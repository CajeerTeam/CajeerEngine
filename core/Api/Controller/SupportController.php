<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\AiSupportCenter;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Guest bearer capabilities are never exchanged for CMS admin sessions. */
final readonly class SupportController
{
    public function __construct(private AiSupportCenter $support, private RateLimiter $limiter, private AuditLogger $audit, private ManagedQueueDriverInterface $queue) {}

    private static function result(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status, ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }

    private static function body(Request $request): array
    {
        $value = $request->attributes->get('json');
        if (!is_array($value) || ($value !== [] && array_is_list($value))) throw new \InvalidArgumentException('Expected JSON object.');
        return $value;
    }

    private static function admin(Request $request): string
    {
        $auth = $request->attributes->get('auth');
        if (!is_array($auth) || ($auth['cookie'] ?? false) !== true || ($auth['session'] ?? false) !== true || !is_string($auth['user_id'] ?? null)) {
            throw new \DomainException('An active browser admin session is required.');
        }
        return $auth['user_id'];
    }

    private static function capability(Request $request): string
    {
        return (string)$request->headers->get('X-Support-Token', '');
    }

    private function limit(Request $request, string $bucket, int $count, int $seconds, string $subject = ''): void
    {
        // Symfony respects forwarded addresses only for explicitly trusted proxies.
        $ip = (string)($request->getClientIp() ?: 'unknown');
        $hit = $this->limiter->hit('support-'.$bucket, $ip.':'.$subject, $count, $seconds);
        if (!$hit['allowed']) throw new \DomainException('Support rate limit reached.');
    }

    private static function sameOriginJson(Request $request): void
    {
        if (!str_starts_with(strtolower((string)$request->headers->get('Content-Type')), 'application/json')) {
            throw new \InvalidArgumentException('Content-Type application/json is required.');
        }
        $origin = $request->headers->get('Origin');
        if ($origin !== null && $origin !== $request->getSchemeAndHttpHost()) {
            throw new \DomainException('Cross-origin public support requests are not allowed.');
        }
    }

    public function config(Request $request): JsonResponse
    {
        return self::result(['enabled' => AiSupportCenter::enabled(), 'auto_reply' => AiSupportCenter::autoReplyEnabled()]);
    }

    public function create(Request $request): JsonResponse
    {
        self::sameOriginJson($request);
        $this->limit($request, 'new-hour', 3, 3600);
        $this->limit($request, 'new-day', 10, 86400);
        $created = $this->support->create(self::body($request));
        // Queue expensive OpenAI work; even a stopped worker/provider cannot lose the new ticket.
        // Keep the guest response fast so the one-time capability reaches the visitor.
        if (AiSupportCenter::autoReplyEnabled()) {
            try { $this->queue->push('ai.support.reply', ['ticket_id' => $created['ticket_id'], '_idempotency_key' => 'support:auto:'.$created['ticket_id']]); }
            catch (\Throwable) { /* The operator queue remains usable if task enqueueing fails. */ }
        }
        return self::result($created, 201);
    }

    public function visitorShow(Request $request, array $params): JsonResponse
    {
        $this->limit($request, 'read', 100, 60);
        return self::result($this->support->visitorView((string)$params['id'], self::capability($request)));
    }

    public function visitorReply(Request $request, array $params): JsonResponse
    {
        self::sameOriginJson($request);
        $this->limit($request, 'guest-reply', 10, 60, (string)$params['id']);
        $this->limit($request, 'guest-daily', 60, 86400, (string)$params['id']);
        $body = self::body($request);
        if (array_keys($body) !== ['message'] || !is_string($body['message'])) throw new \InvalidArgumentException('Message is required.');
        return self::result($this->support->visitorReply((string)$params['id'], self::capability($request), $body['message']));
    }

    public function handoff(Request $request, array $params): JsonResponse
    {
        self::sameOriginJson($request);
        $this->limit($request, 'handoff', 6, 3600, (string)$params['id']);
        return self::result($this->support->requestHuman((string)$params['id'], self::capability($request)));
    }

    public function tickets(Request $request): JsonResponse
    {
        self::admin($request);
        $limit = filter_var($request->query->get('limit', '30'), FILTER_VALIDATE_INT);
        $offset = filter_var($request->query->get('offset', '0'), FILTER_VALIDATE_INT);
        if ($limit === false || $offset === false) throw new \InvalidArgumentException('Invalid pagination.');
        return self::result($this->support->tickets((string)$request->query->get('status', ''), $limit, $offset));
    }

    public function show(Request $request, array $params): JsonResponse
    {
        self::admin($request);
        return self::result($this->support->agentView((string)$params['id']));
    }

    public function update(Request $request, array $params): JsonResponse
    {
        $actor = self::admin($request);
        $this->limit($request, 'admin-update', 50, 60, $actor);
        $updated = $this->support->update((string)$params['id'], $actor, self::body($request));
        $this->audit->record('support.ticket.updated', $actor, ['ticket_id' => $params['id'], 'status' => $updated['status'], 'priority' => $updated['priority']]);
        return self::result($updated);
    }

    public function reply(Request $request, array $params): JsonResponse
    {
        $actor = self::admin($request);
        $this->limit($request, 'admin-reply', 40, 60, $actor);
        $body = self::body($request);
        if (array_keys($body) !== ['message'] || !is_string($body['message'])) throw new \InvalidArgumentException('Message is required.');
        $updated = $this->support->operatorReply((string)$params['id'], $actor, $body['message']);
        $this->audit->record('support.ticket.replied', $actor, ['ticket_id' => $params['id']]);
        return self::result($updated);
    }

    public function draft(Request $request, array $params): JsonResponse
    {
        $actor = self::admin($request);
        $this->limit($request, 'ai-draft', 6, 60, $actor);
        $body = self::body($request);
        if (array_diff(array_keys($body), ['question']) !== [] || (isset($body['question']) && !is_string($body['question']))) {
            throw new \InvalidArgumentException('Invalid draft request.');
        }
        $ticket = $this->support->agentView((string)$params['id']);
        $question = $body['question'] ?? '';
        if ($question === '') {
            foreach (array_reverse($ticket['messages']) as $message) {
                if ($message['role'] === 'visitor') { $question = $message['content']; break; }
            }
        }
        try { $answer = $this->support->draft($actor, $question, (string)$ticket['locale']); }
        catch (AiProviderException $e) {
            return new JsonResponse(['error' => ['code' => 'support_provider_unavailable', 'message' => $e->getMessage()]], $e->httpStatus, ['Cache-Control' => 'no-store']);
        }
        $this->audit->record('support.ai.draft', $actor, ['ticket_id' => $params['id'], 'sources' => count($answer['sources'])]);
        return self::result($answer);
    }
}
