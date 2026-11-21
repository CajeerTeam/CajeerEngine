<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Integration\WebhookRepository;

/** Retryable bridge: persisted history -> existing signed webhook outbox.
 * No HTTP delivery happens in the release monitor or privileged operator.
 */
final readonly class UpdateNotificationPublisher
{
    public function __construct(private string $rootPath, private ?WebhookRepository $webhooks = null) {}

    public function dispatch(int $limit = 50): array
    {
        $history = new UpdateHistory($this->rootPath);
        $history->reconcile();
        $repo = $this->webhooks ?? new WebhookRepository($this->rootPath);
        $processed = 0;
        $cursor = null;
        $limit = max(1, min($limit, 250));
        do {
            $page = $history->recent(100, $cursor);
            foreach ($page as $entry) {
                $cursor = $entry['id'];
                if (!in_array($entry['event'], ['update.available','update.downloaded','update.installed','update.failed','update.rolled_back','update.recovery_required','update.backup_created'], true)) continue;
                $path = OperationFiles::path($this->rootPath, 'storage/app/updates/history/webhook-receipts/' . $entry['id'] . '.json');
                if (is_link($path)) throw new \RuntimeException('Unsafe webhook receipt');
                if (is_file($path)) continue;
                // pushOutboxOnce uses a deterministic ID under the repository's
                // lock, so a crash between outbox append and receipt is safe.
                $id = $repo->pushOutboxOnce($entry['event'], [
                    'history_id' => $entry['id'],
                    'at' => $entry['at'],
                    'details' => $entry['details'],
                ], $entry['id']);
                OperationFiles::json($path, ['event_id' => $id, 'queued_at' => gmdate(DATE_ATOM)], 0660);
                ++$processed;
                if ($processed >= $limit) return ['ok' => true, 'queued' => $processed, 'has_more' => true];
            }
        } while (count($page) === 100);
        return ['ok' => true, 'queued' => $processed, 'has_more' => false];
    }
}
