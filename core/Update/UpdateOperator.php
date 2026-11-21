<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** One-shot, single-node service consumer for explicitly approved installation requests.
 *
 * Invoke from a dedicated operator account via systemd timer. No browser request
 * can select a command, filesystem path, or executable. Rollbacks and backups remain
 * manual in 2.0.3. A killed running request is never replayed automatically.
 */
final class UpdateOperator
{
    /** @var \Closure(string):array */
    private readonly \Closure $execute;

    public function __construct(
        private readonly string $rootPath,
        private readonly ConfigRepository $config,
        ?\Closure $execute = null,
    ) {
        $this->execute = $execute ?? fn (string $id): array => (new UpdateManager($this->rootPath, $this->config))->runRequest($id);
    }

    /** One request per invocation: process exits before application PHP files are replaced again. */
    public function runOnce(): array
    {
        if (!$this->config->bool('updates.operator.enabled') || !$this->config->bool('updates.web_upgrade.enabled')) {
            return ['ok' => true, 'status' => 'disabled', 'processed' => 0];
        }
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
            throw new \RuntimeException('Update operator requires non-root CLI execution');
        }

        $lockPath = OperationFiles::path($this->rootPath, 'storage/app/updates/operator.lock');
        OperationFiles::dir(dirname($lockPath), 0770);
        if (!is_file($lockPath)) {
            $created = @fopen($lockPath, 'x+');
            if (is_resource($created)) { chmod($lockPath, 0600); fclose($created); }
        }
        if (is_link($lockPath) || !is_file($lockPath)) throw new \RuntimeException('Invalid operator lock file');
        $handle = fopen($lockPath, 'r+');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot access operator lease');
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return ['ok' => true, 'status' => 'busy', 'processed' => 0];
        }
        try {
            return $this->consume();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function consume(): array
    {
        // Never consume a later request while the preceding update, restore or
        // extension deployment has unresolved journal state.
        foreach (['storage/app/updates/active.json', 'storage/app/backup-restore.json', 'storage/app/extensions/deployment.json'] as $marker) {
            if (file_exists(OperationFiles::path($this->rootPath, $marker))) {
                return $this->status('recovery_required', null, false, 'Active recovery marker: ' . $marker);
            }
        }
        $dir = OperationFiles::path($this->rootPath, 'storage/app/update-requests');
        if (!is_dir($dir)) return $this->status('idle', null, true);
        if (is_link($dir)) throw new \RuntimeException('Symlink request directory');

        $pending = [];
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) continue;
            $name = $entry->getFilename();
            if (!str_starts_with($name, 'request-')) continue;
            // Do not silently ignore a malformed or symlinked request file.
            if (!preg_match('/^request-([a-f0-9]{32})\.json$/D', $name) || $entry->isLink() || !$entry->isFile()) {
                return $this->status('recovery_required', null, false, 'Invalid request file');
            }
            $id = substr($name, 8, 32);
            try { $record = OperationFiles::read($entry->getPathname()); }
            catch (\Throwable) { return $this->status('recovery_required', $id, false, 'Unreadable request journal'); }
            if (($record['id'] ?? null) !== $id || !in_array($record['state'] ?? null, ['pending', 'running', 'completed', 'failed'], true)) {
                return $this->status('recovery_required', $id, false, 'Invalid request journal');
            }
            if ($record['state'] === 'running') {
                return $this->status('recovery_required', $id, false, 'Unfinished operator request: manual reconciliation required');
            }
            if ($record['state'] !== 'pending' || !in_array($record['action'] ?? null, ['apply', 'download'], true)) continue;
            $validPayload = $record['action'] === 'download'
                ? is_string($record['version'] ?? null) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $record['version'])
                : preg_match('/^[a-f0-9]{32}$/D', (string) ($record['stage'] ?? '')) &&
                    preg_match('/^[a-f0-9]{64}$/D', (string) ($record['sha256'] ?? ''));
            if (!$validPayload || !is_string($record['actor'] ?? null) || trim($record['actor']) === '' ||
                !is_string($record['requested_at'] ?? null) || strtotime($record['requested_at']) === false ||
                !is_string($record['confirmed_at'] ?? null) || strtotime($record['confirmed_at']) === false) {
                return $this->status('recovery_required', $id, false, 'Invalid approved installation request');
            }
            $pending[] = ['id' => $id, 'at' => $record['requested_at']];
        }
        if ($pending === []) return $this->status('idle', null, true);
        usort($pending, static fn (array $a, array $b): int => ($a['at'] <=> $b['at']) ?: strcmp($a['id'], $b['id']));
        $id = $pending[0]['id'];
        $this->status('running', $id, true);
        try {
            $result = ($this->execute)($id);
            if (($result['ok'] ?? false) !== true) {
                return $this->status('failed', $id, false, 'Update operation returned failure; inspect journal');
            }
            return $this->status('completed', $id, true, null, $result['id'] ?? null);
        } catch (\Throwable $e) {
            // UpdateManager::runRequest persists failed state before throwing.
            // Only pre-existing pending requests can run; never make this one pending again.
            return $this->status('failed', $id, false, 'Update failed; inspect protected operation and transaction journals');
        }
    }

    private function status(string $status, ?string $id, bool $ok, ?string $detail = null, ?string $transaction = null): array
    {
        $result = ['ok' => $ok, 'status' => $status, 'processed' => in_array($status, ['completed','failed'], true) ? 1 : 0,
            'request_id' => $id, 'checked_at' => gmdate(DATE_ATOM)];
        if ($detail !== null) $result['detail'] = $detail;
        if ($transaction !== null) $result['transaction_id'] = $transaction;
        OperationFiles::json(OperationFiles::path($this->rootPath, 'storage/app/updates/operator-status.json'), $result, 0644);
        return $result;
    }
}
