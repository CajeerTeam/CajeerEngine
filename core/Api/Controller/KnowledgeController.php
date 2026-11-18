<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\AiKnowledgeBase;
use CajeerEngine\AI\AiKnowledgeChat;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Authenticated browser-only editorial knowledge management, never a public retrieval route. */
final readonly class KnowledgeController
{
    public function __construct(private AiKnowledgeBase $kb, private AuditLogger $audit, private RateLimiter $limiter, private AiKnowledgeChat $chat) {}

    private static function user(Request $request): string
    {
        $auth = $request->attributes->get('auth');
        if (!is_array($auth) || ($auth['cookie'] ?? false) !== true || ($auth['session'] ?? false) !== true
            || !is_string($auth['user_id'] ?? null) || $auth['user_id'] === '') {
            throw new \DomainException('An active admin browser session is required.');
        }
        return $auth['user_id'];
    }

    private static function data(Request $request): array
    {
        $json = $request->attributes->get('json');
        if (!is_array($json) || array_is_list($json) && $json !== []) throw new \InvalidArgumentException('JSON object is required.');
        return $json;
    }

    private static function ok(mixed $data): JsonResponse
    {
        return new JsonResponse(['data' => $data], 200, ['Cache-Control' => 'no-store']);
    }

    private static function integer(mixed $raw, int $default, int $max): int
    {
        if ($raw === null) return $default;
        $result = filter_var($raw, FILTER_VALIDATE_INT);
        if ($result === false || $result < 0 || $result > $max) throw new \InvalidArgumentException('Invalid pagination parameter.');
        return $result;
    }

    private function limited(string $user, string $action, int $count = 20): void
    {
        $hit = $this->limiter->hit('knowledge-'.$action, $user, $count, 60);
        if (!$hit['allowed']) throw new \DomainException('Knowledge operation rate limit reached.');
    }

    public function categories(Request $request): JsonResponse
    {
        self::user($request); return self::ok($this->kb->categories());
    }

    public function createCategory(Request $request): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'write');
        $item = $this->kb->saveCategory(self::data($request));
        $this->audit->record('knowledge.category.created', $user, ['id' => $item['id']]);
        return self::ok($item);
    }

    public function updateCategory(Request $request, array $params): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'write');
        $item = $this->kb->saveCategory(self::data($request), (string)$params['id']);
        $this->audit->record('knowledge.category.updated', $user, ['id' => $item['id']]);
        return self::ok($item);
    }

    public function deleteCategory(Request $request, array $params): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'write');
        $this->kb->deleteCategory((string)$params['id']);
        $this->audit->record('knowledge.category.deleted', $user, ['id' => $params['id']]);
        return self::ok(['deleted' => true]);
    }

    public function documents(Request $request): JsonResponse
    {
        self::user($request);
        $limit = self::integer($request->query->get('limit'), 30, 100);
        if ($limit < 1) throw new \InvalidArgumentException('Limit must be positive.');
        $offset = self::integer($request->query->get('offset'), 0, 100000);
        return self::ok($this->kb->documents($limit, $offset, (string)$request->query->get('category_id', ''), (string)$request->query->get('q', '')));
    }

    public function show(Request $request, array $params): JsonResponse
    {
        self::user($request); return self::ok($this->kb->document((string)$params['id']));
    }

    public function create(Request $request): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'write');
        $item = $this->kb->saveDocument(self::data($request));
        $this->audit->record('knowledge.document.created', $user, ['id' => $item['id'], 'visibility' => $item['visibility']]);
        return self::ok($item);
    }

    public function update(Request $request, array $params): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'write');
        $item = $this->kb->saveDocument(self::data($request), (string)$params['id']);
        $this->audit->record('knowledge.document.updated', $user, ['id' => $item['id'], 'revision' => $item['revision']]);
        return self::ok($item);
    }

    public function delete(Request $request, array $params): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'write');
        $body = self::data($request);
        if (array_keys($body) !== ['expected_revision'] || !is_int($body['expected_revision']))
            throw new \InvalidArgumentException('Expected revision required.');
        $this->kb->deleteDocument((string)$params['id'], $body['expected_revision']);
        $this->audit->record('knowledge.document.deleted', $user, ['id' => $params['id']]);
        return self::ok(['deleted' => true]);
    }

    public function import(Request $request): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'import', 5);
        $item = $this->kb->importFile(self::data($request));
        $this->audit->record('knowledge.document.imported', $user, ['id' => $item['id'], 'visibility' => $item['visibility']]);
        return self::ok($item);
    }

    public function syncCms(Request $request): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'import', 5);
        $body = self::data($request);
        if (array_diff(array_keys($body), ['type','entry_id']) !== [] || !is_string($body['type'] ?? null) || !is_string($body['entry_id'] ?? null))
            throw new \InvalidArgumentException('CMS content type and entry ID required.');
        $item = $this->kb->syncCms($body['type'], $body['entry_id']);
        $this->audit->record('knowledge.cms.synced', $user, ['id' => $item['id'], 'source_id' => $body['entry_id']]);
        return self::ok($item);
    }

    public function search(Request $request): JsonResponse
    {
        self::user($request);
        $limit = self::integer($request->query->get('limit'), 20, 50);
        if ($limit < 1) throw new \InvalidArgumentException('Invalid limit.');
        return self::ok($this->kb->search((string)$request->query->get('q', ''), (string)$request->query->get('visibility', 'all'),
            (string)$request->query->get('locale', ''), $limit));
    }

    /** Owner-scoped RAG conversations, separate from general ChatGPT history. */
    public function chatConversations(Request $request): JsonResponse
    {
        return self::ok($this->chat->conversations(self::user($request)));
    }

    public function chatShow(Request $request, array $params): JsonResponse
    {
        return self::ok($this->chat->messages(self::user($request), (string)$params['id']));
    }

    public function chatDelete(Request $request, array $params): JsonResponse
    {
        $user = self::user($request);
        $this->chat->remove($user, (string)$params['id']);
        $this->audit->record('knowledge.chat.deleted', $user, ['conversation_id' => $params['id']]);
        return self::ok(['deleted' => true]);
    }

    public function chatFeedback(Request $request, array $params): JsonResponse
    {
        $user = self::user($request);
        $body = self::data($request);
        if (array_keys($body) !== ['rating'] || !is_int($body['rating']))
            throw new \InvalidArgumentException('Rating must be an integer.');
        $value = $this->chat->feedback($user, (string)$params['id'], $body['rating']);
        $this->audit->record('knowledge.chat.feedback', $user, ['message_id' => $params['id'], 'rating' => $body['rating']]);
        return self::ok($value);
    }

    public function chatAsk(Request $request): JsonResponse
    {
        $user = self::user($request);
        $body = self::data($request);
        if (array_diff(array_keys($body), ['conversation_id', 'question', 'visibility', 'locale']) !== []
            || !is_string($body['question'] ?? null)
            || (isset($body['conversation_id']) && !is_string($body['conversation_id']))
            || (isset($body['visibility']) && !is_string($body['visibility']))
            || (isset($body['locale']) && !is_string($body['locale'])))
            throw new \InvalidArgumentException('Invalid knowledge question.');
        $hit = $this->limiter->hit('knowledge-rag-generation', $user, 6, 60);
        if (!$hit['allowed']) return new JsonResponse(['error' => ['code' => 'knowledge_chat_rate_limited', 'message' => 'Knowledge Chat: не более шести вопросов в минуту.']],
            429, ['Retry-After' => (string)$hit['retry_after'], 'Cache-Control' => 'no-store']);
        try {
            $result = $this->chat->ask($user, $body['conversation_id'] ?? null, $body['question'],
                $body['visibility'] ?? 'all', $body['locale'] ?? '');
            $this->audit->record('knowledge.chat.answered', $user, [
                'conversation_id' => $result['conversation_id'], 'sources' => count($result['sources']),
                'usage' => $result['usage'],
            ]);
            return self::ok($result);
        } catch (AiProviderException $e) {
            return new JsonResponse(['error' => ['code' => 'knowledge_chat_provider_unavailable', 'message' => $e->getMessage()]],
                $e->httpStatus, ['Cache-Control' => 'no-store']);
        }
    }

    public function reindex(Request $request): JsonResponse
    {
        $user = self::user($request); $this->limited($user, 'reindex', 60);
        $body = self::data($request);
        if (array_diff(array_keys($body), ['limit','offset']) !== []) throw new \InvalidArgumentException('Unknown reindex parameter.');
        $limit = self::integer($body['limit'] ?? null, 20, 100);
        $offset = self::integer($body['offset'] ?? null, 0, 100000);
        $item = $this->kb->reindex($limit, $offset);
        $this->audit->record('knowledge.index.rebuilt', $user, ['processed' => $item['processed'], 'offset' => $offset]);
        return self::ok($item);
    }
}
