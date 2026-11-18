<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Identity\OidcLogin;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Browser redirects only; ID/access/refresh tokens are never emitted into a response. */
final readonly class OidcController
{
    public function __construct(private ConfigRepository $config, private ?OidcLogin $login) {}

    public function status(Request $request): JsonResponse
    {
        return new JsonResponse(['data'=>['enabled'=>$this->login !== null, 'provider'=>'SkiF4er ID']], 200, ['Cache-Control'=>'no-store']);
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
            $response = new RedirectResponse('/admin/', 303);
            $response->headers->setCookie(Cookie::create('ce_session', $session['access_token'], new \DateTimeImmutable((string)$session['expires_at']), '/api/v1', null, true, true, false, Cookie::SAMESITE_STRICT));
            $response->headers->clearCookie('ce_oidc_tx', '/auth/oidc', null, true, true, Cookie::SAMESITE_LAX);
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        } catch (\Throwable) { /* Fail closed and keep existing session unchanged. */ }
        return $response;
    }
}
