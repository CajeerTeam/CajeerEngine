<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Search\SearchIndexer;

final class QueueWorker
{
    private JobHandlerRegistry $handlers;
    private bool $stopping = false;

    public function __construct(
        private readonly ManagedQueueDriverInterface $queue,
        private readonly SearchIndexer $searchIndexer,
        private readonly WebhookRepository $webhooks,
        private readonly WebhookDispatcher $dispatcher,
        private readonly ?\CajeerEngine\Extension\Runtime\PlatformServices $platform = null,
        private readonly ?\CajeerEngine\Analytics\ServerEvents $analytics = null,
        private readonly ?\CajeerEngine\Analytics\Reporting\AnalyticsHub $analyticsHub = null,
        private readonly ?\CajeerEngine\AI\AiTranslationStudio $translationStudio = null,
        private readonly ?\CajeerEngine\AI\AiSupportCenter $supportCenter = null,
        private readonly ?\CajeerEngine\AI\AiBackgroundAutomation $aiAutomations = null,
    ) {
        $this->handlers = new JobHandlerRegistry();
        $this->handlers->register('ai.automation.execute', function (array $payload, JobContext $context): void {
            if ($this->aiAutomations === null || !is_string($payload['run_id'] ?? null)) throw new \RuntimeException('AI Background Automation unavailable');
            $context->heartbeat();
            $this->aiAutomations->execute($payload['run_id'], $context);
            $context->heartbeat();
        });
        $this->handlers->register('ai.support.reply', function ($payload, JobContext $ctx): void {
            if ($this->supportCenter === null || !is_string($payload['ticket_id'] ?? null)) throw new \RuntimeException('AI Support job unavailable.');
            $ctx->heartbeat();
            $this->supportCenter->autoReply($payload['ticket_id']);
            $ctx->heartbeat();
        });
        $this->handlers->register('ai.translation.propose', function ($payload, JobContext $ctx): void {
            if ($this->translationStudio === null || !is_string($payload['item_id'] ?? null)) throw new \RuntimeException('AI Translation Studio job unavailable');
            $ctx->heartbeat();
            $this->translationStudio->runBatchItem($payload['item_id'], $ctx->job->attempts, $ctx->job->maxAttempts);
            $ctx->heartbeat();
        });
        $this->handlers->register('analytics.report_sync', function ($p): void {
            if ($this->analyticsHub === null) throw new \RuntimeException('Analytics reporting unavailable');
            $this->analyticsHub->sync($p);
        });
        $this->handlers->register('analytics.deliver', fn($p, $c) => ($this->analytics ?? \CajeerEngine\Analytics\ServerEvents::fromRoot($this->webhooks->rootPath(), $this->queue))->deliver($p));
        $this->handlers->register('log.message', fn($p, $c) => $this->logMessage($p, $c));
        $this->handlers->register('search.index_all', fn() => $this->searchIndexer->indexAll());
        $this->handlers->register('webhooks.dispatch_pending', function (): void {
            if (!$this->webhooks->enqueuePending($this->queue, 50)['ok']) throw new \RuntimeException('Outbox handoff failed; events retained for retry');
        });
        $this->handlers->register('webhook.dispatch', fn($p, $c) => $this->dispatchWebhookJob($p, $c));
        $this->handlers->register('webhook.deliver', fn($p, $c) => $this->deliver($p, $c));
    }

    public function registry(): JobHandlerRegistry { return $this->handlers; }
    public function stop(): void { $this->stopping = true; }

    /** A queue outage terminates this finite batch; the supervisor may start a new one. */
    public function work(?string $queue = null, int $limit = 10): array
    {
        $queue = QueueOptions::queue($queue);
        $root = $this->webhooks->rootPath();
        $metrics = new \CajeerEngine\Observability\Metrics($root);
        \CajeerEngine\Observability\OperationsMonitor::marker($root, 'worker', 'running');
        $processed = 0;
        $failed = 0;
        $lost = 0;
        $errors = [];
        $backendUnavailable = false;
        $signals = PHP_SAPI === 'cli' && function_exists('pcntl_signal') && function_exists('pcntl_alarm');
        if ($signals) {
            $async = pcntl_async_signals(true);
            $old = [];
            foreach ([SIGTERM, SIGINT, SIGALRM] as $signal) $old[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal(SIGTERM, fn() => $this->stop());
            pcntl_signal(SIGINT, fn() => $this->stop());
            pcntl_signal(SIGALRM, static function (): void { throw new \RuntimeException('Job execution timeout'); });
        }
        try {
            for ($i = 0; $i < max(1, min(1000, $limit)) && !$this->stopping; $i++) {
                try {
                    $job = $this->queue->pop($queue);
                } catch (\Throwable) {
                    // A pop failure does not own a job. Do not guess about reservations or retry blindly.
                    $backendUnavailable = true;
                    $errors[] = ['error' => 'Queue backend unavailable during reservation'];
                    break;
                }
                if ($job === null) break;
                $context = new JobContext($job, $this->queue);
                $started = hrtime(true);
                $wall = microtime(true);
                $trace = \CajeerEngine\Observability\TraceScope::child();
                $previousTrace = \CajeerEngine\Observability\TraceScope::enter($trace);
                $outcome = 'failure';
                $handlerError = null;
                try {
                    try {
                        $context->heartbeat();
                        if ($signals) {
                            $timeout = max(1, (int) (getenv('QUEUE_JOB_TIMEOUT_SECONDS') ?: 120));
                            $lease = $job->leaseUntil === null ? $timeout : max(1, $job->leaseUntil - time() - 1);
                            pcntl_alarm(min($timeout, $lease));
                        }
                        if (str_starts_with($job->name, 'extension.')) {
                            if ($this->platform === null) throw new \RuntimeException('Extension runtime unavailable');
                            $this->platform->handleJob($job->name, $job->payload, $context);
                        } else {
                            $this->handlers->handle($job, $context);
                        }
                        $context->heartbeat();
                    } catch (\Throwable $e) {
                        $handlerError = $e;
                    } finally {
                        if ($signals) pcntl_alarm(0);
                    }

                    if ($handlerError !== null) {
                        $failed++;
                        // Do not return arbitrary handler/transport errors to API clients.
                        $errors[] = ['id' => $job->id, 'name' => $job->name, 'error' => 'Job execution failed'];
                        try {
                            $this->queue->fail($job, 'Job execution failed: ' . (new \ReflectionClass($handlerError))->getShortName(), QueueOptions::backoff($job->attempts));
                        } catch (\Throwable) {
                            $lost++;
                            $backendUnavailable = true;
                            $outcome = 'lost';
                            $errors[] = ['id' => $job->id, 'error' => 'Cannot persist job failure'];
                        }
                    } else {
                        try {
                            $this->queue->complete($job);
                            $processed++;
                            $outcome = 'success';
                        } catch (\Throwable) {
                            // Acknowledgement may have committed before the connection failed.
                            // Never call fail() after successful handler execution: doing so
                            // would deliberately requeue potentially irreversible side effects.
                            $lost++;
                            $backendUnavailable = true;
                            $outcome = 'lost';
                            $errors[] = ['id' => $job->id, 'error' => 'Job acknowledgement unconfirmed'];
                        }
                    }
                } finally {
                    \CajeerEngine\Observability\TraceScope::leave($previousTrace);
                    $duration = (hrtime(true) - $started) / 1e9;
                    $metrics->observe('job', $job->name, $outcome, $duration);
                    (new \CajeerEngine\Observability\TraceExporter($root))->record($trace, 'job', $job->name, $wall, $duration, $outcome === 'success');
                    (new \CajeerEngine\Runtime\RuntimeLogger($root))->info('Background job finished', $trace->fields() + ['job_id' => $job->id, 'status' => $outcome, 'duration_ms' => round($duration * 1000, 3)]);
                    \CajeerEngine\Observability\OperationsMonitor::marker($root, 'worker', 'running');
                }
                if ($backendUnavailable) break;
            }
        } finally {
            if ($signals) {
                pcntl_alarm(0);
                foreach ($old as $signal => $handler) pcntl_signal($signal, $handler);
                pcntl_async_signals($async);
            }
        }
        $diagnostics = [];
        try {
            $diagnostics = $this->queue->diagnostics();
        } catch (\Throwable) {
            $backendUnavailable = true;
            $errors[] = ['error' => 'Queue diagnostics unavailable'];
        }
        \CajeerEngine\Observability\OperationsMonitor::marker($root, 'worker', $failed || $lost || $backendUnavailable ? 'failure' : 'success');
        return [
            'ok' => $failed === 0 && $lost === 0 && !$backendUnavailable,
            'processed' => $processed,
            'failed' => $failed,
            'lost_reservations' => $lost,
            'backend_unavailable' => $backendUnavailable,
            'stopping' => $this->stopping,
            'errors' => $errors,
            'diagnostics' => $diagnostics,
        ];
    }

    private function logMessage(array $payload, JobContext $context): void
    {
        $path = $this->webhooks->rootPath() . '/storage/logs/queue.log';
        \CajeerEngine\Update\OperationFiles::dir(dirname($path), 0770);
        $h = fopen($path, 'c+');
        if (!$h) throw new \RuntimeException('Cannot open job log');
        try {
            if (!flock($h, LOCK_EX)) throw new \RuntimeException('Cannot lock job log');
            $marker = '[' . $context->job->id . ']';
            while (($line = fgets($h)) !== false) if (str_contains($line, ' ' . $marker . ' ') && str_ends_with($line, "\n")) return;
            $line = '[' . gmdate(DATE_ATOM) . '] ' . $marker . ' ' . str_replace(["\r", "\n"], ' ', (string) ($payload['message'] ?? 'empty')) . PHP_EOL;
            if (fseek($h, 0, SEEK_END) !== 0 || fwrite($h, $line) !== strlen($line) || !fflush($h) || !fsync($h)) throw new \RuntimeException('Cannot persist job log');
        } finally { fclose($h); }
    }

    private function dispatchWebhookJob(array $p, JobContext $c): void
    {
        $event = (string) ($p['event'] ?? 'manual.test');
        $body = is_array($p['payload'] ?? null) ? $p['payload'] : [];
        foreach ($this->webhooks->matching($event) as $w) {
            $c->heartbeat();
            $this->queue->push('webhook.deliver', ['event' => $event, 'payload' => $body, 'webhook_id' => $w['id'], 'event_id' => $c->job->id, '_idempotency_key' => 'webhook:' . $c->job->id . ':' . $w['id']], $c->job->queue);
        }
    }

    private function deliver(array $p, JobContext $c): void
    {
        $id = (string) ($p['webhook_id'] ?? '');
        $event = (string) ($p['event'] ?? '');
        $eventId = (string) ($p['event_id'] ?? $c->job->id);
        $w = $this->webhooks->find($id);
        if (!$w || !($w['enabled'] ?? false)) return;
        $this->webhooks->deliverOnce($id, $eventId, function () use ($w, $event, $p, $eventId, $c) {
            $c->heartbeat();
            $r = $this->dispatcher->dispatchWebhook($w, $event, is_array($p['payload'] ?? null) ? $p['payload'] : [], $eventId);
            if (!$r['ok']) throw new \RuntimeException('Webhook delivery failed (HTTP ' . (int) $r['status_code'] . ')');
            return $r;
        });
    }
}
