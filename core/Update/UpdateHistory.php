<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

/** Durable, append-only operational evidence stored outside application backups.
 * Every record is a separate fsynced, atomically published file. The caller
 * supplies only whitelisted metadata; package paths, secrets and exception
 * bodies are never retained. Consumers may replay webhook hand-off safely.
 */
final readonly class UpdateHistory
{
    private const NOTIFY = ['update.available', 'update.downloaded', 'update.installed', 'update.failed', 'update.rolled_back', 'update.recovery_required', 'update.backup_created'];
    private const EVENTS = ['update.available', 'update.requested', 'update.started', 'update.downloaded', 'update.installed', 'update.failed', 'update.rolled_back', 'update.recovery_required', 'update.backup_created', 'update.check_failed'];
    private const FIELDS = ['actor', 'action', 'request_id', 'transaction_id', 'version', 'previous_version', 'sha256', 'channel', 'backup_id', 'state', 'reason_code'];

    public function __construct(private string $rootPath) {}

    /** @param array<string,mixed> $details */
    public function record(string $event, array $details = [], ?string $key = null): array
    {
        if (!in_array($event, self::EVENTS, true)) throw new \InvalidArgumentException('Unsupported update event');
        if ($key !== null && ($key === '' || strlen($key) > 250)) throw new \InvalidArgumentException('Invalid deduplication key');
        $clean = [];
        foreach (self::FIELDS as $field) {
            $value = $details[$field] ?? null;
            if ($value !== null && is_string($value)) {
                if (strlen($value) > 160 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new \InvalidArgumentException('Unsafe update event field');
                $clean[$field] = $value;
            }
        }
        $id = substr(hash('sha256', $event . ':' . ($key ?? bin2hex(random_bytes(24)))), 0, 40);
        $path = OperationFiles::path($this->rootPath, 'storage/app/updates/history/events/' . $id . '.json');
        $directory = dirname($path);
        OperationFiles::dir($directory, 02770);
        if (is_link($path)) throw new \RuntimeException('Unsafe history record');
        if (is_file($path)) return $this->readEvent($path);
        $eventRecord = ['id' => $id, 'event' => $event, 'at' => self::now(), 'details' => $clean];
        // Atomic no-overwrite publication. A killed process cannot leave a
        // partially written event visible to readers or duplicate an event.
        $temp = $directory . '/.event-' . bin2hex(random_bytes(12));
        OperationFiles::json($temp, $eventRecord, 0660);
        try {
            if (!@link($temp, $path)) {
                if (!is_file($path) || is_link($path)) throw new \RuntimeException('Cannot publish update history');
                return $this->readEvent($path);
            }
            OperationFiles::syncDir($directory);
        } finally {
            @unlink($temp);
        }
        return $eventRecord;
    }

    /** Backfill transitions from existing immutable request journals after a crash. */
    public function reconcile(): array
    {
        $dir = OperationFiles::path($this->rootPath, 'storage/app/update-requests');
        $count = 0;
        if (!is_dir($dir)) return ['ok' => true, 'processed' => 0];
        if (is_link($dir)) throw new \RuntimeException('Unsafe request journal directory');
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot() || !str_starts_with($item->getFilename(), 'request-')) continue;
            if (!$item->isFile() || $item->isLink() || !preg_match('/^request-([a-f0-9]{32})\.json$/D', $item->getFilename(), $m)) {
                throw new \RuntimeException('Invalid request journal entry');
            }
            $r = OperationFiles::read($item->getPathname());
            if (($r['id'] ?? null) !== $m[1]) throw new \RuntimeException('Mismatched request journal');
            $details = ['actor' => $r['actor'] ?? '', 'action' => $r['action'] ?? '', 'request_id' => $m[1], 'version' => $r['version'] ?? null, 'sha256' => $r['sha256'] ?? null];
            $this->record('update.requested', $details, 'request:' . $m[1] . ':pending');
            if (isset($r['started_at'])) $this->record('update.started', $details, 'request:' . $m[1] . ':started');
            if (($r['state'] ?? null) === 'completed') {
                $event = match ($r['action'] ?? '') {
                    'download' => 'update.downloaded', 'backup' => 'update.backup_created',
                    default => null, // A committed apply is recorded by UpdateManager::finish.
                };
                if ($event !== null) $this->record($event, $details, 'request:' . $m[1] . ':completed');
            } elseif (($r['state'] ?? null) === 'failed') {
                $this->record('update.failed', $details + ['reason_code' => 'operation_failed'], 'request:' . $m[1] . ':failed');
            } elseif (($r['state'] ?? null) === 'running') {
                // An active operation is not necessarily interrupted; only a
                // separate operator/recovery marker may declare recovery_required.
            }
            ++$count;
        }
        $reportPath = OperationFiles::path($this->rootPath, 'storage/app/updates/last-update.json');
        if (is_file($reportPath)) {
            $r = OperationFiles::read($reportPath);
            $id = $r['id'] ?? null;
            if (is_string($id) && preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D', $id)) {
                $state = $r['state'] ?? null;
                $event = match ($state) {
                    'committed' => 'update.installed',
                    'rolled_back' => ($r['ok'] ?? false) ? 'update.rolled_back' : 'update.failed',
                    'recovery_required' => 'update.recovery_required',
                    default => null,
                };
                if ($event !== null) $this->record($event,
                    ['transaction_id'=>$id, 'version'=>$r['target_version']??($r['version']??null),
                     'previous_version'=>$r['previous_version']??null,
                     'sha256'=>$r['sha256']??null,
                     'backup_id'=>$r['backup']['id']??null,'state'=>$state],
                    'transaction:'.$id.':'.$state.':'.(($r['ok']??false)?'ok':'failed'));
            }
        }
        return ['ok' => true, 'processed' => $count];
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50, ?string $before = null): array
    {
        $limit = max(1, min(100, $limit));
        if ($before !== null && !preg_match('/^[a-f0-9]{40}$/D', $before)) throw new \InvalidArgumentException('Invalid cursor');
        $dir = OperationFiles::path($this->rootPath, 'storage/app/updates/history/events');
        if (!is_dir($dir)) return [];
        if (is_link($dir)) throw new \RuntimeException('Unsafe history directory');
        $events = [];
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot() || str_starts_with($item->getFilename(), '.')) continue;
            if ($item->isLink() || !$item->isFile() || !preg_match('/^([a-f0-9]{40})\.json$/D', $item->getFilename())) throw new \RuntimeException('Unsafe history record');
            $record = $this->readEvent($item->getPathname());
            if (($record['id'] ?? '') !== substr($item->getFilename(), 0, 40)) throw new \RuntimeException('History record mismatch');
            $events[] = $record;
        }
        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']) ?: strcmp($b['id'], $a['id']));
        if ($before !== null) {
            $index = null;
            foreach ($events as $i => $event) if ($event['id'] === $before) { $index = $i; break; }
            if ($index === null) throw new \InvalidArgumentException('Unknown history cursor');
            $events = array_slice($events, $index + 1);
        }
        return array_slice($events, 0, $limit);
    }

    /** @return array{items:array,unread_count:int} */
    public function notifications(string $actor, int $limit = 30): array
    {
        $readThrough = $this->readThrough($actor);
        $items = [];
        $unread = 0;
        // Count unread from complete journal, not only the visible page.
        $cursor = null;
        do {
            $page = $this->recent(100, $cursor);
            foreach ($page as $record) {
                $cursor = $record['id'];
                if (!in_array($record['event'], self::NOTIFY, true)) continue;
                $record['read'] = strcmp($record['at'], $readThrough) <= 0;
                if (!$record['read']) ++$unread;
                if (count($items) < max(1, min(100, $limit))) $items[] = $record;
            }
        } while (count($page) === 100);
        return ['items' => $items, 'unread_count' => $unread];
    }

    /** Mark notifications read for the authenticated user only, not all admins. */
    public function markRead(string $actor): array
    {
        $path = $this->readPath($actor);
        $time = self::now();
        $previous = $this->readThrough($actor);
        if (strcmp($previous, $time) > 0) $time = $previous;
        OperationFiles::json($path, ['read_through' => $time], 0660);
        return ['ok' => true, 'read_through' => $time];
    }

    private function readThrough(string $actor): string
    {
        $path = $this->readPath($actor);
        if (!is_file($path)) return '';
        $data = OperationFiles::read($path);
        return is_string($data['read_through'] ?? null) ? $data['read_through'] : '';
    }

    private function readPath(string $actor): string
    {
        if ($actor === '' || strlen($actor) > 256 || preg_match('/[\x00-\x1f\x7f]/', $actor)) throw new \InvalidArgumentException('Invalid notification recipient');
        return OperationFiles::path($this->rootPath, 'storage/app/updates/history/read-through/' . hash('sha256', $actor) . '.json');
    }

    private function readEvent(string $path): array
    {
        $record = OperationFiles::read($path);
        if (!is_string($record['id'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $record['id']) ||
            !in_array($record['event'] ?? null, self::EVENTS, true) || !is_string($record['at'] ?? null) ||
            !is_array($record['details'] ?? null)) throw new \RuntimeException('Corrupt update history');
        return $record;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
