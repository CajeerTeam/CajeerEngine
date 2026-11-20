<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\IntegrationStore;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Update\OperationFiles as Files;
use CajeerEngine\Update\OperationLock;
use PDO;

/**
 * Idempotent in-place migration of durable encrypted credentials to current keys.
 * Every store has its own transaction/atomic commit; rerunning after a crash is safe
 * as long as previous keys remain available. Never prints or logs plaintext.
 */
final readonly class KeyRotation
{
    public function __construct(
        private string $root,
        private SecretVault $integrations,
        private SecretVault $oidc,
        private ?DatabaseManager $database = null,
    ) {}

    /** Run offline only (the global exclusive operation lease prevents concurrent writers). */
    public function run(bool $apply): array
    {
        if (OperationLock::maintenance($this->root)) throw new \RuntimeException('Finish pending recovery or maintenance before rekeying.');
        $lease = OperationLock::acquire($this->root, true);
        try {
            $db = $this->database ?? new DatabaseManager(new \CajeerEngine\Runtime\ConfigRepository($this->root));
            // Fail before changing files if the database cannot be reached.
            $db->connection();
            $result = ['ok'=>true, 'applied'=>$apply, 'active_key_ids'=>[
                'integrations'=>$this->integrations->activeKeyId(),
                'oidc'=>$this->oidc->activeKeyId(),
            ], 'stores'=>[]];
            $result['stores']['ai_settings'] = $this->aiSettings($apply);
            $result['stores']['integrations'] = $this->integrationRegistry($apply);
            $result['stores']['migration'] = $this->migrationProjects($apply);
            $result['stores']['server_events'] = $this->serverEvents($apply);
            $result['stores']['analytics'] = $this->databaseTable($db, 'analytics_connections', 'id', 'credential',
                fn(array $row): string=>'analytics:report:v1:'.$row['id'].':'.$row['provider'].':'.$row['resource_id'], $this->integrations, $apply,
                ['provider','resource_id']);
            $result['stores']['oidc_transactions'] = $this->databaseTable($db, 'ce_oidc_transactions', 'state_hash', 'verifier_ciphertext',
                fn(array $row): string=>'oidc:pkce:'.$row['state_hash'], $this->oidc, $apply);
            $result['changed'] = array_sum(array_column($result['stores'], 'changed'));
            $result['deferred'] = array_sum(array_column($result['stores'], 'deferred'));
            return $result;
        } finally { OperationLock::release($lease); }
    }

    private function aiSettings(bool $apply): array
    {
        $path = Files::path($this->root, 'storage/app/ai/settings.json');
        if (!(new \SplFileInfo($path))->isFile()) return ['changed'=>0];
        $lock = Files::path($this->root, 'storage/app/ai/settings.lock');
        $handle = fopen($lock, 'c+');
        if ($handle === false) throw new \RuntimeException('AI credential rotation lock unavailable.');
        try {
            Files::permissions($lock, 0600);
            if (!flock($handle, $apply ? LOCK_EX : LOCK_SH)) throw new \RuntimeException('AI credential rotation lock failed.');
            if (!(new \SplFileInfo($path))->isFile()) return ['changed'=>0];
            $state = Files::read($path);
            if (!isset($state['key_ciphertext'])) return ['changed'=>0];
            if (!is_string($state['key_ciphertext'])) throw new \RuntimeException('Invalid encrypted AI API credential.');
            $new = $this->integrations->rewrap('cajeerengine:ai:openai:key', $state['key_ciphertext']);
            if ($new === $state['key_ciphertext']) return ['changed'=>0];
            if ($apply) {
                $state['key_ciphertext'] = $new;
                Files::json($path, $state, 0600);
            }
            return ['changed'=>1];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function integrationRegistry(bool $apply): array
    {
        $store = new IntegrationStore($this->root);
        $changed = 0;
        $store->transaction(function (array &$items) use ($apply, &$changed): void {
            foreach ($items as &$item) {
                if (!isset($item['credential'])) continue;
                if (!is_string($item['credential'])) throw new \RuntimeException('Invalid integration ciphertext');
                $new = $this->integrations->rewrap('integration:v1:'.$item['id'].':'.$item['provider'], $item['credential']);
                if ($new !== $item['credential']) {
                    $changed++;
                    if ($apply) $item['credential'] = $new;
                }
            }
            unset($item);
        }, $apply);
        return ['changed'=>$changed];
    }

    private function migrationProjects(bool $apply): array
    {
        $dir = Files::path($this->root, 'storage/app/migration');
        if (!is_dir($dir)) return ['changed'=>0];
        $changed = 0;
        foreach (glob($dir.'/*.json') ?: [] as $path) {
            if (is_link($path) || !preg_match('/^[a-f0-9]{32}\.json$/D', basename($path))) throw new \RuntimeException('Unsafe migration project path');
            $id = basename($path, '.json');
            $project = Files::read($path);
            if (($project['id'] ?? null) !== $id) throw new \RuntimeException('Migration project identifier mismatch');
            $dirty = false;
            if (isset($project['credential'])) {
                if (!is_string($project['credential']) || !is_string($project['source'] ?? null)) throw new \RuntimeException('Invalid migration credential');
                $new = $this->integrations->rewrap('migration:v1:'.$id.':'.$project['source'], $project['credential']);
                if ($new !== $project['credential']) { $changed++; $dirty = true; if ($apply) $project['credential'] = $new; }
            }
            if (isset($project['wxr_ref'])) {
                $ref = $project['wxr_ref'];
                if (!is_string($ref) || !preg_match('/^[a-f0-9]{32}$/D', $ref)) throw new \RuntimeException('Invalid WXR reference');
                $file = Files::path($this->root, 'storage/app/migration/'.$id.'-'.$ref.'.wxr.enc');
                if (!is_file($file)) throw new \RuntimeException('Encrypted WXR file missing');
                $raw = file_get_contents($file);
                if ($raw === false) throw new \RuntimeException('Cannot read encrypted WXR');
                $new = $this->integrations->rewrap('migration:wxr:v1:'.$id, $raw);
                if ($new !== $raw) { $changed++; if ($apply) Files::write($file, $new, 0600); }
            }
            if (isset($project['wxr'])) {
                $new = $this->integrations->rewrap('migration:wxr:v1:'.$id, (string)$project['wxr']);
                if ($new !== $project['wxr']) { $changed++; $dirty = true; if ($apply) $project['wxr'] = $new; }
            }
            if ($dirty && $apply) Files::json($path, $project, 0600);
        }
        return ['changed'=>$changed];
    }

    private function serverEvents(bool $apply): array
    {
        $path = Files::path($this->root, 'storage/app/privacy/server-events.json');
        if (!(new \SplFileInfo($path))->isFile()) return ['changed'=>0];
        $state = Files::read($path);
        if (!is_array($state['grants'] ?? null)) throw new \RuntimeException('Invalid server analytics state');
        $changed = 0;
        $deferred = 0;
        foreach ($state['grants'] as $grant => &$item) {
            // The queued job contains the *original* ciphertext and its fingerprint.
            // Rewrapping an active grant here would make a legitimate idempotent
            // replay conflict with the stored fingerprint. Defer until inactive.
            if (!($item['revoked'] ?? false) && (int)($item['expires'] ?? 0) > time()) {
                foreach ($item['events'] ?? [] as $event => $record) {
                    if (!isset($record['ad_data'])) continue;
                    if (!is_string($record['ad_data'])) throw new \RuntimeException('Invalid advertising ciphertext');
                    $plaintext = $this->integrations->open('analytics-ad:v1:'.$grant.':'.$event, $record['ad_data']);
                    sodium_memzero($plaintext); // still authenticate records that cannot be rewrapped yet
                    if ($this->integrations->needsRotation($record['ad_data'])) $deferred++;
                }
                continue;
            }
            foreach ($item['events'] ?? [] as $event => &$record) {
                if (!isset($record['ad_data'])) continue;
                if (!is_string($record['ad_data'])) throw new \RuntimeException('Invalid advertising ciphertext');
                $new = $this->integrations->rewrap('analytics-ad:v1:'.$grant.':'.$event, $record['ad_data']);
                if ($new !== $record['ad_data']) { $changed++; if ($apply) $record['ad_data'] = $new; }
            }
            unset($record);
        }
        unset($item);
        if ($apply && $changed > 0) Files::json($path, $state, 0600);
        return ['changed'=>$changed, 'deferred'=>$deferred];
    }

    /** @param callable(array):string $context @param list<string> $moreColumns */
    private function databaseTable(DatabaseManager $db, string $table, string $id, string $column, callable $context, SecretVault $vault, bool $apply, array $moreColumns = []): array
    {
        // Identifiers come only from hardcoded call sites.
        if (!$db->tableExists($table)) return ['changed'=>0, 'present'=>false];
        $changed = 0;
        $fields = implode(',', array_merge([$id, $column], $moreColumns));
        $db->transaction(function (PDO $pdo) use ($table, $id, $column, $context, $vault, $apply, $fields, &$changed): void {
            $statement = $pdo->query('SELECT '.$fields.' FROM '.$table);
            $update = $pdo->prepare('UPDATE '.$table.' SET '.$column.' = :next WHERE '.$id.' = :id AND '.$column.' = :old');
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($row[$column] === null) continue;
                if (!is_string($row[$column])) throw new \RuntimeException('Invalid database ciphertext');
                $new = $vault->rewrap($context($row), $row[$column]);
                if ($new !== $row[$column]) {
                    $changed++;
                    if ($apply) {
                        $update->execute(['next'=>$new, 'id'=>$row[$id], 'old'=>$row[$column]]);
                        if ($update->rowCount() !== 1) throw new \RuntimeException('Concurrent encryption record change');
                    }
                }
            }
        });
        return ['changed'=>$changed, 'present'=>true];
    }
}
