<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private ?string $root = null) {}

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $route=(string)$request->attributes->get('matched_route','');
            $public=in_array($route,['public.home','public.page.path','public.search','public.search.trailing'],true);
            $origins=$public?implode(' ',\CajeerEngine\Privacy\ConsentPolicy::origins()):'';
            $sources="'self'".($origins!==''?' '.$origins:'');
            $tracking=['script'=>[],'connect'=>[],'frame'=>[]];
            if($public&&$this->root!==null){
                try {
                    $cfg=(new \CajeerEngine\Privacy\TrackingConfig($this->root))->get();
                    if(in_array(strtolower($request->getHost()),$cfg['hosts'],true))$tracking=\CajeerEngine\Privacy\TrackingConfig::csp($cfg);
                } catch(\Throwable) {} // fail closed on invalid configuration
            }
            $scripts=$sources.' '.implode(' ',$tracking['script']);
            $connect=$sources.' '.implode(' ',$tracking['connect']);
            $frames="'self' ".implode(' ',$tracking['frame']);
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src $scripts; connect-src $connect; frame-src $frames; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'");
        }
        if ($request->isSecure()) { $response->headers->set('Strict-Transport-Security', 'max-age=31536000'); }
        if (str_starts_with(rawurldecode($request->getPathInfo()), '/api/') || str_starts_with($request->getPathInfo(), '/preview/') || str_starts_with($request->getPathInfo(), '/auth/oidc/')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        return $response;
    }
}
