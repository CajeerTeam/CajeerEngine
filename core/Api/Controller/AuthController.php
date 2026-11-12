<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Security\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthController
{
    public function __construct(private AuthService $auth)
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

    public function me(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity)) {
            return $this->error('unauthenticated', 'Требуется авторизация.', Response::HTTP_UNAUTHORIZED);
        }
        return new JsonResponse(['data' => $identity]);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity) || empty($identity['user_id'])) {
            return $this->error('unauthenticated', 'Требуется авторизация.', Response::HTTP_UNAUTHORIZED);
        }
        $payload = $this->json($request);
        if (!$this->auth->confirmSensitiveAction($identity, (string) ($payload['password'] ?? ''), isset($payload['two_factor_code']) ? (string) $payload['two_factor_code'] : null)) { return $this->error('reauthentication_required', 'Подтвердите пароль и текущий 2FA код.', 403); }
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
        if (!$this->auth->confirmSensitiveAction($identity, (string) ($payload['password'] ?? ''), isset($payload['two_factor_code']) ? (string) $payload['two_factor_code'] : null)) { return $this->error('reauthentication_required', 'Подтвердите пароль и текущий 2FA код.', 403); }
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
