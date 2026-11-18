<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Identity\OidcLogin;
use CajeerEngine\Security\AuthService;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Browser redirects only; ID/access/refresh tokens are never emitted into a response. */
final readonly class OidcController
{
    public function __construct(private ConfigRepository $config, private ?OidcLogin $login, private ?AuthService $auth = null) {}

    public function status(Request $request): JsonResponse
    {
        return new JsonResponse(['data'=>['enabled'=>$this->login !== null, 'provider'=>'SkiF4er ID', 'global_logout'=>$this->login !== null]], 200, ['Cache-Control'=>'no-store']);
    }

    public function start(Request $request): Response
    {
        if ($this->login === null) return new JsonResponse(['error'=>['code'=>'oidc_disabled','message'=>'SkiF4er ID не настроен.']], 503);
        if (!$request->isSecure()) return new JsonResponse(['error'=>['code'=>'https_required','message'=>'OIDC требует HTTPS.']], 403);
        try {
            $login = $this->login->begin();
            $response = new RedirectResponse($login['url'], 302);
            $response->headers->setCookie(Cookie::create('ce_oidc_tx', $login['browser'], new \DateTimeImmutable('+5 minutes'), '/auth/oidc', null, true, true, false, Cookie::SAMESITE_LAX));
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            return $response;
        } catch (\Throwable) {
            return new JsonResponse(['error'=>['code'=>'oidc_unavailable','message'=>'Не удалось начать авторизацию SkiF4er ID.']], 503);
        }
    }

    public function callback(Request $request): Response
    {
        if ($this->login === null) return new RedirectResponse('/admin/#/login', 303);
        $response = new RedirectResponse('/admin/#/login', 303);
        $response->headers->clearCookie('ce_oidc_tx', '/auth/oidc', null, true, true, Cookie::SAMESITE_LAX);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        if (!$request->isSecure() || $request->query->has('error')) return $response;
        $code = $request->query->get('code'); $state = $request->query->get('state');
        $browser = $request->cookies->get('ce_oidc_tx');
        if (!is_string($code) || !is_string($state) || !is_string($browser)) return $response;
        try {
            $session = $this->login->finish($code, $state, $browser, $request);
            $response = new RedirectResponse((($session['linked'] ?? false) === true || ($session['step_up'] ?? false) === true) ? '/admin/#/identity' : '/admin/', 303);
            if (($session['linked'] ?? false) !== true && ($session['step_up'] ?? false) !== true) {
                $response->headers->setCookie(Cookie::create('ce_session', $session['access_token'], new \DateTimeImmutable((string)$session['expires_at']), '/api/v1', null, true, true, false, Cookie::SAMESITE_STRICT));
            }
            $response->headers->clearCookie('ce_oidc_tx', '/auth/oidc', null, true, true, Cookie::SAMESITE_LAX);
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        } catch (\Throwable) { /* Fail closed and keep existing session unchanged. */ }
        return $response;
    }

    /** Authenticated, CSRF-protected browser POST; no logout on a GET or from a Bearer token. */
    public function globalLogout(Request $request): Response
    {
        if ($this->login === null) return new JsonResponse(['error'=>['code'=>'oidc_disabled','message'=>'SkiF4er ID отключён.']], 503);
        if (!$request->isSecure()) return new JsonResponse(['error'=>['code'=>'https_required','message'=>'Требуется HTTPS.']], 403);
        try {
            $base = rtrim($this->config->string('app.url'), '/');
            $url = $this->login->globalLogout($request, $base.'/auth/oidc/logout/complete');
            $response = new JsonResponse(['data'=>['authorization_url'=>$url, 'local_session_revoked'=>true]], 200, ['Cache-Control'=>'no-store']);
            $response->headers->clearCookie('ce_session', '/api/v1', null, true, true, Cookie::SAMESITE_STRICT);
            return $response;
        } catch (\Throwable) {
            return new JsonResponse(['error'=>['code'=>'global_logout_failed','message'=>'Глобальный выход не выполнен. Проверьте подключение и активную сессию SkiF4er ID.']], 409, ['Cache-Control'=>'no-store']);
        }
    }

    /** Keycloak sends application/x-www-form-urlencoded logout_token, not a browser cookie. */
    public function backchannel(Request $request): JsonResponse
    {
        $headers = ['Cache-Control'=>'no-store'];
        if ($this->login === null || !$request->isSecure()) return new JsonResponse(['error'=>'unavailable'], 403, $headers);
        $type = strtolower((string)$request->headers->get('Content-Type',''));
        if (!str_starts_with($type, 'application/x-www-form-urlencoded') || strlen($request->getContent()) > 20000) {
            return new JsonResponse(['error'=>'invalid_request'], 400, $headers);
        }
        $token = $request->request->get('logout_token');
        if (!is_string($token) || $token === '' || strlen($token) > 16384) return new JsonResponse(['error'=>'invalid_request'], 400, $headers);
        try { $claims = $this->login->verifyBackchannel($token); }
        catch (\Throwable) { return new JsonResponse(['error'=>'invalid_logout_token'], 400, $headers); }
        try {
            $this->login->applyVerifiedBackchannel($claims);
            return new JsonResponse([], 200, $headers);
        } catch (\Throwable) {
            // A database fault must not ACK receipt; Keycloak may retry delivery.
            return new JsonResponse(['error'=>'temporarily_unavailable'], 503, $headers);
        }
    }

    /** This callback has no side effects. It is safe even if the provider returns an error. */
    public function logoutComplete(Request $request): RedirectResponse
    {
        $response = new RedirectResponse('/admin/#/login', 303);
        $response->headers->set('Cache-Control','no-store');
        $response->headers->set('Referrer-Policy','no-referrer');
        return $response;
    }

    /** Never accepts bearer credentials; cookie + CSRF are enforced by auth middleware. */
    public function stepUpStart(Request $request): JsonResponse
    {
        $identity = $this->currentUser($request);
        if ($identity === null || $this->login === null || !$request->isSecure()) {
            return $this->linkError('step_up_unavailable', 'Требуется HTTPS и действующая OIDC-сессия.', 403);
        }
        try {
            $tx = $this->login->beginStepUp((string)$identity['user_id'], (string)$identity['token_id']);
            $response = new JsonResponse(['data'=>['authorization_url'=>$tx['url']]], 200, ['Cache-Control'=>'no-store']);
            $response->headers->setCookie(Cookie::create('ce_oidc_tx', $tx['browser'], new \DateTimeImmutable('+5 minutes'), '/auth/oidc', null, true, true, false, Cookie::SAMESITE_LAX));
            return $response;
        } catch (\Throwable) { return $this->linkError('step_up_unavailable', 'Повторная авторизация SkiF4er ID недоступна.', 409); }
    }

    public function account(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($user === null) return new JsonResponse(['error'=>['code'=>'session_required','message'=>'Требуется защищённая браузерная сессия.']], 403);
        if ($this->login === null) return new JsonResponse(['data'=>['enabled'=>false, 'linked'=>false]], 200, ['Cache-Control'=>'no-store']);
        return new JsonResponse(['data'=>['enabled'=>true] + $this->login->accountStatus((string)$user['user_id'])], 200, ['Cache-Control'=>'no-store']);
    }

    /** Recent local password + 2FA confirmation and CSRF are required before contacting the IdP. */
    public function linkStart(Request $request): Response
    {
        $identity = $this->currentUser($request);
        if ($identity === null || $this->login === null || $this->auth === null || !$request->isSecure()) {
            return $this->linkError('link_unavailable', 'Привязка требует HTTPS, активную сессию и настроенный SkiF4er ID.', 403);
        }
        $payload = $request->attributes->get('json');
        if (!is_array($payload) || !is_string($payload['password'] ?? null) || strlen($payload['password']) > 1024) return $this->linkError('validation_failed', 'Введите текущий пароль.', 422);
        $code = $payload['two_factor_code'] ?? null;
        if (!is_null($code) && (!is_string($code) || strlen($code) > 128)) return $this->linkError('validation_failed', 'Некорректный 2FA код.', 422);
        if (!$this->auth->confirmSensitiveAction($identity, $payload['password'], $code)) return $this->linkError('reauthentication_required', 'Неверный пароль или код 2FA.', 403);
        try {
            if ($this->login->accountStatus((string)$identity['user_id'])['linked']) return $this->linkError('already_linked', 'SkiF4er ID уже привязан.', 409);
            $tx = $this->login->begin((string)$identity['user_id'], (string)$identity['token_id']);
            $response = new JsonResponse(['data'=>['authorization_url'=>$tx['url']]], 200, ['Cache-Control'=>'no-store']);
            $response->headers->setCookie(Cookie::create('ce_oidc_tx', $tx['browser'], new \DateTimeImmutable('+5 minutes'), '/auth/oidc', null, true, true, false, Cookie::SAMESITE_LAX));
            return $response;
        } catch (\Throwable) { return $this->linkError('link_failed', 'Не удалось начать безопасную привязку.', 409); }
    }

    public function unlink(Request $request): JsonResponse
    {
        $identity = $this->currentUser($request);
        if ($identity === null || $this->login === null || $this->auth === null || !$request->isSecure()) {
            return $this->linkError('unlink_unavailable', 'Для отвязки необходима защищённая браузерная сессия.', 403);
        }
        $payload = $request->attributes->get('json');
        if (!is_array($payload) || !is_string($payload['password'] ?? null) || strlen($payload['password']) > 1024) return $this->linkError('validation_failed', 'Введите текущий пароль.', 422);
        $code = $payload['two_factor_code'] ?? null;
        if (!is_null($code) && (!is_string($code) || strlen($code) > 128)) return $this->linkError('validation_failed', 'Некорректный 2FA код.', 422);
        if (!$this->auth->confirmSensitiveAction($identity, $payload['password'], $code)) return $this->linkError('reauthentication_required', 'Неверный пароль или код 2FA.', 403);
        try {
            return new JsonResponse(['data'=>['unlinked'=>$this->login->unlink((string)$identity['user_id'])]], 200, ['Cache-Control'=>'no-store']);
        } catch (\RuntimeException $e) {
            return $this->linkError('unlink_denied', $e->getMessage(), 409);
        }
    }

    private function currentUser(Request $request): ?array
    {
        $identity = $request->attributes->get('auth');
        return is_array($identity) && ($identity['session'] ?? false) === true && ($identity['cookie'] ?? false) === true
            && is_string($identity['user_id'] ?? null) && is_string($identity['token_id'] ?? null) ? $identity : null;
    }

    private function linkError(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error'=>['code'=>$code, 'message'=>$message]], $status, ['Cache-Control'=>'no-store']);
    }

}
