<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Identity\IdentityCenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Cookie-only, CSRF-protected, RBAC-scoped Identity Center operations. */
final readonly class IdentityAdminController
{
    public function __construct(private IdentityCenter $center) {}

    public function users(Request $request): JsonResponse
    {
        if (($denied = $this->browser($request)) !== null) return $denied;
        try {
            $params = $request->query->all();
            foreach (['q', 'linked', 'limit', 'offset'] as $key) {
                if (isset($params[$key]) && !is_string($params[$key])) return $this->error('validation_failed', 'Некорректные параметры.', 422);
            }
            $limit = $params['limit'] ?? '30';
            $offset = $params['offset'] ?? '0';
            if (!ctype_digit($limit) || !ctype_digit($offset)) return $this->error('validation_failed', 'Некорректная пагинация.', 422);
            $data = $this->center->users($params['q'] ?? '', $params['linked'] ?? 'all', (int) $limit, (int) $offset);
            return $this->ok($data);
        } catch (\InvalidArgumentException $e) { return $this->error('validation_failed', $e->getMessage(), 422); }
    }

    public function sessions(Request $request, array $parameters): JsonResponse
    {
        if (($denied = $this->browser($request)) !== null) return $denied;
        try { return $this->ok($this->center->sessions((string) ($parameters['id'] ?? ''))); }
        catch (\InvalidArgumentException $e) { return $this->error('validation_failed', $e->getMessage(), 422); }
        catch (\DomainException $e) { return $this->error('user_not_found', $e->getMessage(), 404); }
    }

    public function link(Request $request, array $parameters): JsonResponse
    {
        if (($denied = $this->browser($request)) !== null) return $denied;
        $id = (string) ($parameters['id'] ?? '');
        $data = $this->body($request);
        $subject = $data['subject'] ?? null;
        if (!is_string($subject) || $subject === '' || !is_string($data['confirm_subject'] ?? null)
            || !hash_equals($subject, $data['confirm_subject'])) {
            return $this->error('confirmation_required', 'Подтвердите точный subject учётной записи.', 422);
        }
        try {
            $this->center->link($this->actor($request), $id, $subject);
            return $this->ok(['linked' => true]);
        } catch (\InvalidArgumentException $e) { return $this->error('validation_failed', $e->getMessage(), 422); }
        catch (\DomainException $e) { return $this->error('identity_conflict', $e->getMessage(), 409); }
    }

    public function unlink(Request $request, array $parameters): JsonResponse
    {
        if (($denied = $this->browser($request)) !== null) return $denied;
        $id = (string) ($parameters['id'] ?? '');
        if (($this->body($request)['confirm_user_id'] ?? null) !== $id) return $this->error('confirmation_required', 'Подтвердите UUID пользователя.', 422);
        try { return $this->ok(['unlinked' => true, 'revoked_sessions' => $this->center->unlink($this->actor($request), $id)]); }
        catch (\InvalidArgumentException $e) { return $this->error('validation_failed', $e->getMessage(), 422); }
        catch (\DomainException $e) { return $this->error('identity_conflict', $e->getMessage(), 409); }
    }

    public function revokeSession(Request $request, array $parameters): JsonResponse
    {
        if (($denied = $this->browser($request)) !== null) return $denied;
        try { return $this->ok(['revoked' => $this->center->revokeSession($this->actor($request),
            (string) ($parameters['id'] ?? ''), (string) ($parameters['session'] ?? ''))]); }
        catch (\InvalidArgumentException $e) { return $this->error('validation_failed', $e->getMessage(), 422); }
        catch (\DomainException $e) { return $this->error('session_not_found', $e->getMessage(), 409); }
    }

    public function revokeAll(Request $request, array $parameters): JsonResponse
    {
        if (($denied = $this->browser($request)) !== null) return $denied;
        $id = (string) ($parameters['id'] ?? '');
        if (($this->body($request)['confirm_user_id'] ?? null) !== $id) return $this->error('confirmation_required', 'Подтвердите UUID пользователя.', 422);
        try { return $this->ok(['revoked_sessions' => $this->center->revokeAllSessions($this->actor($request), $id)]); }
        catch (\InvalidArgumentException $e) { return $this->error('validation_failed', $e->getMessage(), 422); }
        catch (\DomainException $e) { return $this->error('session_not_found', $e->getMessage(), 409); }
    }

    private function browser(Request $request): ?JsonResponse
    {
        $auth = $request->attributes->get('auth');
        if (!$request->isSecure() || !is_array($auth) || ($auth['cookie'] ?? false) !== true
            || ($auth['session'] ?? false) !== true || !is_string($auth['user_id'] ?? null)) {
            return $this->error('browser_session_required', 'Требуется активная защищённая HTTPS-сессия админки.', 403);
        }
        return null;
    }

    private function actor(Request $request): string { return (string) $request->attributes->get('auth')['user_id']; }
    private function body(Request $request): array
    {
        $data = $request->attributes->get('json');
        return is_array($data) ? $data : [];
    }
    private function ok(mixed $data): JsonResponse { return new JsonResponse(['data' => $data], 200, ['Cache-Control' => 'no-store']); }
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => compact('code', 'message')], $status, ['Cache-Control' => 'no-store']);
    }
}
