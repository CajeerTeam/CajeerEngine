<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\AiChat;
use CajeerEngine\AI\AiContentEditor;
use CajeerEngine\AI\AiSeoAssistant;
use CajeerEngine\AI\AiTranslationStudio;
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
    public function __construct(private AiChat $chat, private AiSettings $settings, private AuditLogger $audit, private RateLimiter $limiter, private AiContentEditor $editor, private AiSeoAssistant $seo, private AiTranslationStudio $translations, private \CajeerEngine\Queue\ManagedQueueDriverInterface $queue) {}

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

    public function editorPropose(Request $request): JsonResponse
    {
        $uid = $this->user($request);
        $hit = $this->limiter->hit('ai-editor-user', $uid, 6, 60);
        if (!$hit['allowed']) return new JsonResponse(['error' => ['code' => 'ai_editor_rate_limit', 'message' => 'Лимит AI Editor: 6 запросов в минуту.']], 429, ['Retry-After' => (string)$hit['retry_after'], 'Cache-Control' => 'no-store']);
        try {
            $result = $this->editor->propose($uid, $this->body($request));
            $this->audit->record('ai.editor.proposed', $uid, ['proposal_id' => $result['id'], 'type' => $result['type'], 'field' => $result['field'], 'mode' => $result['mode']]);
            return $this->ok($result);
        } catch (AiProviderException $e) {
            return new JsonResponse(['error' => ['code' => 'ai_provider_unavailable', 'message' => $e->getMessage()]], $e->httpStatus, ['Cache-Control' => 'no-store']);
        }
    }

    public function editorApply(Request $request, array $params): JsonResponse
    {
        $uid = $this->user($request);
        $body = $this->body($request);
        if (array_diff(array_keys($body), ['confirm_published']) !== [] || (isset($body['confirm_published']) && !is_bool($body['confirm_published']))) {
            throw new \InvalidArgumentException('Invalid editor approval.');
        }
        $result = $this->editor->apply($uid, (string)($params['id'] ?? ''), $body['confirm_published'] ?? false);
        $this->audit->record('ai.editor.applied', $uid, ['proposal_id' => $params['id'], 'type' => $result['type'], 'entry_id' => $result['entry']['id'], 'revision' => $result['entry']['revision']]);
        return $this->ok($result);
    }

    public function seoAudit(Request $request): JsonResponse
    {
        $this->user($request);
        return $this->ok($this->seo->audit((string)$request->query->get('type', ''), (string)$request->query->get('entry_id', '')));
    }

    public function seoBatch(Request $request): JsonResponse
    {
        $this->user($request);
        $limit = filter_var($request->query->get('limit', '30'), FILTER_VALIDATE_INT);
        if ($limit === false) throw new \InvalidArgumentException('Invalid SEO batch limit.');
        return $this->ok($this->seo->batch((string)$request->query->get('type', ''), $limit));
    }

    public function seoPropose(Request $request): JsonResponse
    {
        $uid = $this->user($request);
        $data = $this->body($request);
        if (array_diff(array_keys($data), ['type', 'entry_id', 'field', 'instructions']) !== [] ||
            !is_string($data['type'] ?? null) || !is_string($data['entry_id'] ?? null) ||
            !in_array($data['field'] ?? null, ['seo_title', 'meta_description'], true) ||
            !is_string($data['instructions'] ?? null) || strlen($data['instructions']) > 1500) {
            throw new \InvalidArgumentException('Invalid AI SEO request.');
        }
        // The audit confirms the target exists before any tokens can be charged.
        $this->seo->audit($data['type'], $data['entry_id']);
        $hit = $this->limiter->hit('ai-seo-user', $uid, 6, 60);
        if (!$hit['allowed']) return new JsonResponse(['error' => ['code' => 'ai_seo_rate_limit', 'message' => 'SEO generation rate limit reached.']], 429,
            ['Retry-After' => (string)$hit['retry_after'], 'Cache-Control' => 'no-store']);
        try {
            $result = $this->editor->propose($uid, [
                'type' => $data['type'], 'entry_id' => $data['entry_id'], 'field' => $data['field'],
                'mode' => $data['field'] === 'seo_title' ? 'seo_title' : 'seo_description',
                'instructions' => $data['instructions'],
            ]);
            $this->audit->record('ai.seo.proposed', $uid, ['proposal_id' => $result['id'], 'entry_id' => $data['entry_id'], 'field' => $data['field']]);
            return $this->ok($result);
        } catch (AiProviderException $e) {
            return new JsonResponse(['error' => ['code' => 'ai_provider_unavailable', 'message' => $e->getMessage()]], $e->httpStatus, ['Cache-Control'=>'no-store']);
        }
    }

    public function translationOverview(Request $request): JsonResponse
    {
        $this->user($request);
        return $this->ok($this->translations->overview((string)$request->query->get('type', ''), (string)$request->query->get('entry_id', '')));
    }

    public function translationGlossary(Request $request): JsonResponse
    {
        $this->user($request);
        return $this->ok($this->translations->glossary((string)$request->query->get('type', ''), (string)$request->query->get('source_locale', ''), (string)$request->query->get('target_locale', '')));
    }

    public function translationSaveTerm(Request $request): JsonResponse
    {
        $uid = $this->user($request); $body = $this->body($request);
        if (array_diff(array_keys($body), ['type', 'source_locale', 'target_locale', 'term', 'translation']) !== [])
            throw new \InvalidArgumentException('Invalid glossary request.');
        foreach (['type', 'source_locale', 'target_locale', 'term', 'translation'] as $key)
            if (!is_string($body[$key] ?? null)) throw new \InvalidArgumentException('Invalid glossary field.');
        $result = $this->translations->saveTerm($body['type'], $body['source_locale'], $body['target_locale'], $body['term'], $body['translation']);
        $this->audit->record('ai.translation.glossary_saved', $uid, ['type' => $body['type'], 'source_locale' => $body['source_locale'], 'target_locale' => $body['target_locale']]);
        return $this->ok($result);
    }

    public function translationDeleteTerm(Request $request, array $params): JsonResponse
    {
        $uid = $this->user($request);
        $result = $this->translations->deleteTerm((string)$params['id']);
        $this->audit->record('ai.translation.glossary_deleted', $uid, ['id' => $params['id'], 'deleted' => $result]);
        return $this->ok(['deleted' => $result]);
    }

    public function translationPropose(Request $request): JsonResponse
    {
        $uid = $this->user($request);
        $hit = $this->limiter->hit('ai-translation-user', $uid, 4, 60);
        if (!$hit['allowed']) return new JsonResponse(['error' => ['code' => 'ai_translation_rate_limit', 'message' => 'Лимит: 4 перевода в минуту.']], 429,
            ['Retry-After' => (string)$hit['retry_after'], 'Cache-Control' => 'no-store']);
        try {
            $result = $this->translations->propose($uid, $this->body($request));
            $this->audit->record('ai.translation.proposed', $uid, ['proposal_id' => $result['id'], 'type' => $result['type'], 'source_id' => $result['source_entry_id'], 'target_locale' => $result['target_locale']]);
            return $this->ok($result);
        } catch (AiProviderException $e) {
            return new JsonResponse(['error' => ['code' => 'ai_provider_unavailable', 'message' => $e->getMessage()]], $e->httpStatus, ['Cache-Control' => 'no-store']);
        }
    }

    public function translationApply(Request $request, array $params): JsonResponse
    {
        $uid = $this->user($request); $body = $this->body($request);
        if (array_diff(array_keys($body), ['confirm_published']) !== [] || (isset($body['confirm_published']) && !is_bool($body['confirm_published'])))
            throw new \InvalidArgumentException('Invalid approval.');
        $result = $this->translations->apply($uid, (string)$params['id'], $body['confirm_published'] ?? false);
        $this->audit->record('ai.translation.applied', $uid, ['proposal_id' => $params['id'], 'entry_id' => $result['entry']['id'], 'revision' => $result['entry']['revision'], 'created' => $result['created']]);
        return $this->ok($result);
    }

    public function translationBatchCreate(Request $request): JsonResponse
    {
        $uid = $this->user($request);
        $hit = $this->limiter->hit('ai-translation-batch-user', $uid, 2, 60);
        if (!$hit['allowed']) return new JsonResponse(['error' => ['code' => 'ai_translation_batch_rate_limit', 'message' => 'Лимит: 2 пакетных перевода в минуту.']], 429,
            ['Retry-After' => (string)$hit['retry_after'], 'Cache-Control' => 'no-store']);
        $result = $this->translations->enqueueBatch($uid, $this->body($request), $this->queue);
        $this->audit->record('ai.translation.batch_queued', $uid, ['batch_id' => $result['id'], 'total' => $result['total']]);
        return $this->ok($result);
    }

    public function translationBatchShow(Request $request, array $params): JsonResponse
    {
        return $this->ok($this->translations->batchStatus($this->user($request), (string)$params['id']));
    }

    public function translationProposalShow(Request $request, array $params): JsonResponse
    {
        return $this->ok($this->translations->proposal($this->user($request), (string)$params['id']));
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
