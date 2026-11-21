<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** One-shot, single-node service consumer for explicitly approved installation requests.
 *
 * Invoke from a dedicated operator account via systemd timer. No browser request
 * can select a command, filesystem path, or executable. Backups and committed
 * rollbacks also require explicit approval. A killed request is never replayed.
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

    /** Validate the persisted approval at the point of execution, including direct CLI use.
     * No approval may authorize a caller-supplied executable, URL or filesystem path.
     *
     * @param array<string,mixed> $record
     */
    public static function assertApproved(array $record, string $id): void
    {
        if (($record['id'] ?? null) !== $id || ($record['state'] ?? null) !== 'pending' ||
            !preg_match('/^[a-f0-9]{32}$/D', $id) ||
            !is_string($record['actor'] ?? null) || trim($record['actor']) === '' ||
            strlen($record['actor']) > 160 || preg_match('/[\x00-\x1f\x7f]/', $record['actor'])) {
            throw new \RuntimeException('Invalid approved request identity');
        }
        $times = [];
        foreach (['requested_at', 'confirmed_at'] as $field) {
            $value = $record[$field] ?? null;
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                throw new \RuntimeException('Invalid request approval timestamp');
            }
            $times[$field] = strtotime($value);
            if ($times[$field] === false || $times[$field] > time() + 300) {
                throw new \RuntimeException('Request approval timestamp is not valid');
            }
        }
        if ($times['confirmed_at'] < $times['requested_at'] - 300) {
            throw new \RuntimeException('Request approved before creation');
        }
        $valid = match ($record['action'] ?? null) {
            'download' => is_string($record['version'] ?? null) && strlen($record['version']) <= 100 &&
                (bool) preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $record['version']),
            'apply' => is_string($record['stage'] ?? null) &&
                (bool) preg_match('/^[a-f0-9]{32}$/D', $record['stage']) &&
                is_string($record['sha256'] ?? null) &&
                (bool) preg_match('/^[a-f0-9]{64}$/D', $record['sha256']),
            'rollback' => is_string($record['transaction'] ?? null) &&
                (bool) preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D', $record['transaction']),
            'backup' => true,
            default => false,
        };
        if (!$valid) throw new \RuntimeException('Invalid approved update action');
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
        $handle = OperationLock::openLockFile($lockPath, 0600);
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
            if ($record['state'] !== 'pending') continue;
            try { self::assertApproved($record, $id); }
            catch (\Throwable) { return $this->status('recovery_required', $id, false, 'Invalid approved installation request'); }
            $pending[] = ['id' => $id, 'at' => $record['requested_at']];
        }
        if ($pending === []) return $this->status('idle', null, true);
        usort($pending, static fn (array $a, array $b): int => ($a['at'] <=> $b['at']) ?: strcmp($a['id'], $b['id']));
        $id = $pending[0]['id'];
        $this->status('running', $id, true);
        try {
            $result = ($this->execute)($id);
            // A return value is not a commit record. Verify the fsynced request
            // journal before reporting success to administrators.
            $requestPath = OperationFiles::path($this->rootPath, 'storage/app/update-requests/request-' . $id . '.json');
            $after = OperationFiles::read($requestPath);
            if (($after['id'] ?? null) !== $id || !in_array($after['state'] ?? null, ['completed', 'failed'], true) ||
                (($result['ok'] ?? false) === true) !== ($after['state'] === 'completed')) {
                // The executed operation may already have altered files/DB. Never
                // replay an ambiguous request on the next scheduler tick.
                if (($after['id'] ?? null) === $id && ($after['state'] ?? null) === 'pending') {
                    $after['state'] = 'running';
                    $after['started_at'] = gmdate(DATE_ATOM);
                    OperationFiles::json($requestPath, $after, 0640);
                }
                return $this->status('recovery_required', $id, false, 'Executor result and durable request journal disagree');
            }
            if (($result['ok'] ?? false) !== true) {
                return $this->status('failed', $id, false, 'Update operation returned failure; inspect journal');
            }
            return $this->status('completed', $id, true, null, is_string($result['id'] ?? null) ? $result['id'] : null);
        } catch (\Throwable $e) {
            // A throw before the request journal transition could otherwise leave
            // the approval pending for unattended replay. Fail closed even if the
            // executing process dies before persisting its failure state.
            try {
                $requestPath = OperationFiles::path($this->rootPath, 'storage/app/update-requests/request-' . $id . '.json');
                $after = OperationFiles::read($requestPath);
                if (($after['id'] ?? null) !== $id) throw new \RuntimeException('Request journal identity mismatch');
                if (($after['state'] ?? null) === 'pending') {
                    $after['state'] = 'running';
                    $after['started_at'] = gmdate(DATE_ATOM);
                    OperationFiles::json($requestPath, $after, 0640);
                }
                if (($after['state'] ?? null) === 'failed') {
                    return $this->status('failed', $id, false, 'Update failed; inspect protected operation and transaction journals');
                }
            } catch (\Throwable) { /* unverifiable journal: manual intervention */ }
            return $this->status('recovery_required', $id, false, 'Interrupted operation has no verified terminal journal');
        }
    }

    private function status(string $status, ?string $id, bool $ok, ?string $detail = null, ?string $transaction = null): array
    {
        $result = ['ok' => $ok, 'status' => $status, 'processed' => in_array($status, ['completed','failed'], true) ? 1 : 0,
            'request_id' => $id, 'checked_at' => gmdate(DATE_ATOM)];
        if ($detail !== null) $result['detail'] = $detail;
        if ($transaction !== null) $result['transaction_id'] = $transaction;
        OperationFiles::json(OperationFiles::path($this->rootPath, 'storage/app/updates/operator-status.json'), $result, 0644);
        if ($status === 'recovery_required') {
            try { (new UpdateHistory($this->rootPath))->record('update.recovery_required',
                ['request_id'=>$id, 'reason_code'=>'operator_recovery_required'],
                'operator:recovery:'.($id??'active').':'.hash('sha256',$detail??''));
            } catch (\Throwable) { /* blocking journal remains authoritative */ }
        }
        return $result;
    }
}
