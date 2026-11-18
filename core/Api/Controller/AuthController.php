<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Security\AuthService;
use CajeerEngine\Security\AuthenticationAssurance;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthController
{
    public function __construct(private AuthService $auth, private ?AuthenticationAssurance $assurance = null)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        if ($email === '' || $password === '') {
            return $this->error('validation_failed', 'Укажите email и password.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($request->headers->get('X-Cajeer-Browser') === '1' && !$request->isSecure() && !(in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true) && in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true))) {
            return $this->error('https_required', 'Для браузерной сессии требуется HTTPS.', 403);
        }
        try {
            $session = $this->auth->login($email, $password, isset($payload['two_factor_code']) ? (string) $payload['two_factor_code'] : null, $request);
            $browser = $request->headers->get('X-Cajeer-Browser') === '1';
            if (!$browser) { return new JsonResponse(['data' => $session], Response::HTTP_CREATED); }
            $token = $session['access_token'];
            unset($session['access_token']);
            $session['csrf_token'] = AuthService::csrfToken($token);
            $response = new JsonResponse(['data' => $session], 201);
            $response->headers->setCookie(Cookie::create('ce_session', $token, new \DateTimeImmutable($session['expires_at']), '/api/v1', null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT));
            return $response;
        } catch (\RuntimeException $e) {
            return $this->error('login_failed', $e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);
         $response = new JsonResponse(['data' => ['logged_out' => true]]);
        $response->headers->clearCookie('ce_session', '/api/v1', null, $request->isSecure(), true, Cookie::SAMESITE_STRICT);
        return $response;
    }

    public function sessions(Request $request): JsonResponse
    {
        try {
            $items = $this->auth->sessionList((array)$request->attributes->get('auth', []));
            return new JsonResponse(['data'=>$items, 'meta'=>['total'=>count($items)]], 200, ['Cache-Control'=>'no-store']);
        } catch (\RuntimeException) { return $this->error('session_required','Требуется браузерная сессия.',403); }
    }

    /** @param array<string,string> $parameters */
    public function revokeSession(Request $request, array $parameters): JsonResponse
    {
        try {
            $identity = (array)$request->attributes->get('auth', []);
            $id = (string)($parameters['id'] ?? '');
            $ok = $this->auth->revokeSession($identity, $id);
            $response = new JsonResponse(['data'=>['revoked'=>$ok]], $ok ? 200 : 404, ['Cache-Control'=>'no-store']);
            if ($ok && $id === (string)($identity['token_id'] ?? '')) $this->clearSessionCookie($response, $request);
            return $response;
        } catch (\RuntimeException) { return $this->error('session_required','Требуется браузерная сессия.',403); }
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        try {
            $count = $this->auth->revokeOtherSessions((array)$request->attributes->get('auth', []));
            return new JsonResponse(['data'=>['revoked_count'=>$count]],200,['Cache-Control'=>'no-store']);
        } catch (\RuntimeException) { return $this->error('session_required','Требуется браузерная сессия.',403); }
    }

    public function revokeAll(Request $request): JsonResponse
    {
        try {
            $count = $this->auth->revokeAllSessions((array)$request->attributes->get('auth', []));
            $response = new JsonResponse(['data'=>['revoked_count'=>$count]],200,['Cache-Control'=>'no-store']);
            $this->clearSessionCookie($response, $request);
            return $response;
        } catch (\RuntimeException) { return $this->error('session_required','Требуется браузерная сессия.',403); }
    }

    public function rotateSession(Request $request): JsonResponse
    {
        try {
            $rotated = $this->auth->rotateSession((array)$request->attributes->get('auth', []), $request);
            $token = $rotated['access_token']; unset($rotated['access_token']);
            $response = new JsonResponse(['data'=>$rotated],200,['Cache-Control'=>'no-store']);
            $response->headers->setCookie(Cookie::create('ce_session', $token, new \DateTimeImmutable((string)$rotated['expires_at']), '/api/v1', null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT));
            return $response;
        } catch (\RuntimeException) { return $this->error('rotation_failed','Не удалось безопасно обновить сессию.',409); }
    }

    private function clearSessionCookie(JsonResponse $response, Request $request): void
    {
        $response->headers->clearCookie('ce_session','/api/v1',null,$request->isSecure(),true,Cookie::SAMESITE_STRICT);
    }

    public function assuranceStatus(Request $request): JsonResponse
    {
        try { return new JsonResponse(['data'=>$this->requireAssurance()->status((array)$request->attributes->get('auth', []))], 200, ['Cache-Control'=>'no-store']); }
        catch (\Throwable) { return $this->error('session_required','Требуется браузерная сессия.',403); }
    }

    public function assuranceConfirm(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        if (!is_string($payload['password'] ?? null) || strlen($payload['password']) > 1024
            || (isset($payload['two_factor_code']) && (!is_string($payload['two_factor_code']) || strlen($payload['two_factor_code']) > 128))) {
            return $this->error('validation_failed', 'Укажите текущий пароль и корректный TOTP при необходимости.', 422);
        }
        try {
            $result = $this->requireAssurance()->confirmLocal((array)$request->attributes->get('auth', []), $payload['password'], $payload['two_factor_code'] ?? null);
            return new JsonResponse(['data'=>$result], 200, ['Cache-Control'=>'no-store']);
        } catch (\Throwable) { return $this->error('reauthentication_failed','Повторная авторизация не пройдена.',403); }
    }

    private function requireAssurance(): AuthenticationAssurance
    {
        if ($this->assurance === null) throw new \RuntimeException('Assurance unavailable.');
        return $this->assurance;
    }

    public function me(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity)) {
            return $this->error('unauthenticated', 'Требуется авторизация.', Response::HTTP_UNAUTHORIZED);
        }
        return new JsonResponse(['data' => $identity]);
    }

    private function confirmedSensitive(array $identity, array $payload): bool
    {
        if (($identity['cookie'] ?? false) !== true || ($identity['session'] ?? false) !== true) return false;
        try {
            if ($this->assurance !== null && $this->assurance->status($identity)['fresh']) return true;
        } catch (\Throwable) { /* A local password can still reauthenticate on an older session. */ }
        return $this->auth->confirmSensitiveAction($identity, (string)($payload['password'] ?? ''),
            isset($payload['two_factor_code']) ? (string)$payload['two_factor_code'] : null);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity) || empty($identity['user_id'])) {
            return $this->error('unauthenticated', 'Требуется авторизация.', Response::HTTP_UNAUTHORIZED);
        }
        $payload = $this->json($request);
        if (!$this->confirmedSensitive($identity, $payload)) { return $this->error('reauthentication_required', 'Подтвердите локальный пароль / TOTP или выполните свежий SkiF4er ID MFA step-up.', 403); }
        $result = $this->auth->enableTwoFactor((string) $identity['user_id']);
        return new JsonResponse(['data' => $result], Response::HTTP_CREATED);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity) || empty($identity['user_id'])) {
            return $this->error('unauthenticated', 'Требуется авторизация.', Response::HTTP_UNAUTHORIZED);
        }
        $payload = $this->json($request);
        if (!$this->confirmedSensitive($identity, $payload)) { return $this->error('reauthentication_required', 'Подтвердите локальный пароль / TOTP или выполните свежий SkiF4er ID MFA step-up.', 403); }
        $this->auth->disableTwoFactor((string) $identity['user_id']);
        return new JsonResponse(['data' => ['two_factor_enabled' => false]]);
    }

    /** @return array<string, mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        return is_array($parsed) ? $parsed : [];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
