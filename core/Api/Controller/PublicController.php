<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Cms\PreviewTokenService;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Public\PublicPageRenderer;
use CajeerEngine\Public\PublicRouteResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class PublicController
{
    public function __construct(
        private PublicPageRenderer $renderer,
        private RedirectRepository $redirects,
        private PreviewTokenService $previewTokens,
    ) {
    }

    /** @param array<string, mixed> $parameters */
    public function sitemap(Request $request, array $parameters): JsonResponse
    {
        $items = $this->renderer->sitemap();
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    /** @param array<string, mixed> $parameters */
    public function sitemapXml(Request $request, array $parameters = []): Response
    {
        $page=$request->query->get('page');
        if($page!==null&&(!is_string($page)||!ctype_digit($page)||strlen($page)>7))return $this->publicError(404);
        try{$xml=$this->renderer->sitemapXml($this->baseUrl($request),$page===null?null:(int)$page);}catch(\InvalidArgumentException){return $this->publicError(404);}
        return new Response($xml,200,['Content-Type'=>'application/xml; charset=UTF-8']);
    }

    /** @param array<string, mixed> $parameters */
    public function robotsTxt(Request $request, array $parameters = []): Response
    {
        return new Response($this->renderer->robotsTxt($this->baseUrl($request)), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @param array<string, mixed> $parameters */
    public function preview(Request $request, array $parameters): Response
    {
        $page = $this->renderer->renderByTypeAndId((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''), true);
        if ($page === null) {
            return $this->notFound();
        }

        if ($request->query->get('format') === 'json') {
            return new JsonResponse([
                'data' => [
                    'path' => $page['path'],
                    'entry' => $page['entry'],
                    'seo' => $page['seo'],
                    'html' => $page['html'],
                ],
            ],200,['Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow','Referrer-Policy'=>'no-referrer']);
        }

        return new Response($page['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-CajeerEngine-Preview' => '1', 'Cache-Control'=>'private, no-store', 'X-Robots-Tag'=>'noindex, nofollow', 'Referrer-Policy'=>'no-referrer']);
    }

    /** @param array<string, mixed> $parameters */
    public function previewToken(Request $request, array $parameters): Response
    {
        $resolved = $this->previewTokens->resolve((string) ($parameters['token'] ?? ''));
        if ($resolved === null) {
            return $this->notFound('preview_token_invalid', 'Preview token недействителен или истёк.');
        }

        $page = $this->renderer->renderPreviewId((string) ($resolved['type'] ?? ''), (string) ($resolved['entry_id'] ?? ''));
        if ($page === null) {
            return $this->notFound();
        }

        if ($request->query->get('format') === 'json') {
            return new JsonResponse(['data' => $page],200,['Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow','Referrer-Policy'=>'no-referrer']);
        }

        return new Response($page['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-CajeerEngine-Preview' => 'token', 'Cache-Control'=>'private, no-store', 'X-Robots-Tag'=>'noindex, nofollow', 'Referrer-Policy'=>'no-referrer']);
    }

    /** @param array<string, mixed> $parameters */
    public function renderHome(Request $request, array $parameters = []): Response
    {
        return $this->renderByPath($request,$parameters);
    }

    public function renderByPath(Request $request, array $parameters): Response
    {
        $result=(new PublicRouteResolver($this->renderer->routeIndex(),$this->redirects))->resolve($request->getPathInfo());
        if(isset($result['target'])){
            $target=$result['target'];
            if(in_array($result['kind'],['normalize','alias'],true)&&($query=$request->getQueryString())!==null&&$query!=='')$target.='?'.$query;
            return new RedirectResponse($target,$result['status']);
        }
        if($result['status']!==200)return $this->publicError($result['status']);
        $page=$this->renderer->renderByPath($result['path']);
        if($page===null)return $this->publicError(404);
        $status=$this->renderer->errorStatus($result['path'])??200;
        $headers=['Content-Type'=>'text/html; charset=UTF-8','X-CajeerEngine-Public-Path'=>$page['path']];
        if($status!==200)$headers+=['X-Robots-Tag'=>'noindex, nofollow','Cache-Control'=>'no-store'];
        return new Response($page['html'],$status,$headers);
    }

    public function renderBySlug(Request $request, array $parameters): Response { return $this->renderByPath($request,$parameters); }

    private function publicError(int $status): Response
    {
        $page=$this->renderer->errorPage($status);
        $message=$status===410?'Страница удалена':'Страница не найдена';
        $html=$page['html']??('<!doctype html><html lang="ru"><meta charset="utf-8"><title>'.$status.' '.$message.'</title><h1>'.$status.' — '.$message.'</h1><p><a href="/">Главная</a></p></html>');
        return new Response($html,$status,['Content-Type'=>'text/html; charset=UTF-8','X-Robots-Tag'=>'noindex, nofollow','Cache-Control'=>'no-store']);
    }

    private function baseUrl(Request $request): string { return $this->renderer->baseUrl(); }

    private function notFound(string $code = 'public_page_not_found', string $message = 'Публичная страница не найдена.'): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], Response::HTTP_NOT_FOUND);
    }
}
