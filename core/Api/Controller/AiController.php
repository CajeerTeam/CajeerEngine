<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\AiChat;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin-only chat endpoints. Authorization/CSRF/step-up also run in the core middleware. */
final readonly class AiController
{
    public function __construct(private AiChat $chat, private AiSettings $settings, private AuditLogger $audit, private RateLimiter $limiter) {}

    private function user(Request $request): string
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity) || ($identity['cookie'] ?? false) !== true || ($identity['session'] ?? false) !== true
            || !is_string($identity['user_id'] ?? null) || $identity['user_id'] === '') {
            throw new \DomainException('An active admin browser session is required.');
        }
        return $identity['user_id'];
    }

    private function body(Request $request): array
    {
        $body = $request->attributes->get('json');
        return is_array($body) ? $body : [];
    }

    private function ok(mixed $value): JsonResponse
    {
        return new JsonResponse(['data' => $value], 200, ['Cache-Control' => 'no-store']);
    }

    public function status(Request $request): JsonResponse
    {
        $this->user($request);
        return $this->ok($this->settings->status());
    }

    public function configure(Request $request): JsonResponse
    {
        $uid = $this->user($request);
        $data = $this->settings->update($this->body($request));
        $this->audit->record('ai.settings.updated', $uid, ['model' => $data['model'], 'configured' => $data['configured']]);
        return $this->ok($data);
    }

    public function index(Request $request): JsonResponse { return $this->ok($this->chat->list($this->user($request))); }

    public function show(Request $request, array $params): JsonResponse
    {
        return $this->ok($this->chat->messages($this->user($request), (string)$params['id']));
    }

    public function rename(Request $request, array $params): JsonResponse
    {
        $body = $this->body($request);
        if (!is_string($body['title'] ?? null)) throw new \InvalidArgumentException('Title required.');
        return $this->ok($this->chat->rename($this->user($request), (string)$params['id'], $body['title']));
    }

    public function delete(Request $request, array $params): JsonResponse
    {
        $this->chat->delete($this->user($request), (string)$params['id']);
        return $this->ok(['deleted' => true]);
    }

    private function throttle(string $uid): ?JsonResponse
    {
        $hit = $this->limiter->hit('ai-user', $uid, 12, 60);
        if ($hit['allowed']) return null;
        return new JsonResponse(['error' => ['code' => 'ai_rate_limit', 'message' => 'Лимит AI-запросов: 12 в минуту.']], 429,
            ['Cache-Control' => 'no-store', 'Retry-After' => (string)$hit['retry_after']]);
    }

    private function validated(Request $request): array
    {
        $body = $this->body($request);
        $id = $body['conversation_id'] ?? null;
        $message = $body['message'] ?? null;
        if (($id !== null && !is_string($id)) || !is_string($message)) throw new \InvalidArgumentException('Message or conversation ID invalid.');
        return [$id, $message];
    }

    public function send(Request $request): JsonResponse
    {
        $uid = $this->user($request);
        [$id, $message] = $this->validated($request);
        if (($denied = $this->throttle($uid)) !== null) return $denied;
        try {
            $result = $this->chat->send($uid, $id, $message);
            $this->audit->record('ai.chat.completed', $uid, ['conversation_id' => $result['conversation_id'], 'model' => $result['model'], 'usage' => $result['usage']]);
            return $this->ok($result);
        } catch (AiProviderException $e) {
            return new JsonResponse(['error' => ['code' => 'ai_provider_unavailable', 'message' => $e->getMessage()]], $e->httpStatus, ['Cache-Control' => 'no-store']);
        }
    }

    public function stream(Request $request): Response
    {
        $uid = $this->user($request);
        [$id, $message] = $this->validated($request);
        if (($denied = $this->throttle($uid)) !== null) return $denied;
        return new StreamedResponse(function () use ($uid, $id, $message): void {
            try {
                $result = $this->chat->send($uid, $id, $message, fn (string $delta) => $this->event('delta', ['text' => $delta]));
                $this->audit->record('ai.chat.completed', $uid, ['conversation_id' => $result['conversation_id'], 'model' => $result['model'], 'usage' => $result['usage']]);
                $this->event('done', $result);
            } catch (AiProviderException $e) {
                $this->event('error', ['message' => $e->getMessage()]);
            } catch (\InvalidArgumentException|\DomainException $e) {
                $this->event('error', ['message' => $e->getMessage()]);
            } catch (\Throwable) {
                $this->event('error', ['message' => 'AI operation could not be completed.']);
            }
        }, 200, ['Content-Type' => 'text/event-stream; charset=utf-8', 'Cache-Control' => 'no-store, no-transform', 'X-Accel-Buffering' => 'no', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function event(string $name, array $data): void
    {
        echo 'event: ' . $name . "\n" . 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        if (function_exists('ob_flush') && ob_get_level() > 0) @ob_flush();
        flush();
    }
}
