<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Cms\PreviewTokenService;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Public\PublicPageRenderer;
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
        return new Response($this->renderer->sitemapXml($this->baseUrl($request)), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
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
            ]);
        }

        return new Response($page['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-CajeerEngine-Preview' => '1']);
    }

    /** @param array<string, mixed> $parameters */
    public function previewToken(Request $request, array $parameters): Response
    {
        $resolved = $this->previewTokens->resolve((string) ($parameters['token'] ?? ''));
        if ($resolved === null) {
            return $this->notFound('preview_token_invalid', 'Preview token недействителен или истёк.');
        }

        $page = $this->renderer->renderByTypeAndId((string) ($resolved['type'] ?? ''), (string) ($resolved['entry_id'] ?? ''), true);
        if ($page === null) {
            return $this->notFound();
        }

        if ($request->query->get('format') === 'json') {
            return new JsonResponse(['data' => $page + ['preview_token' => $resolved]]);
        }

        return new Response($page['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-CajeerEngine-Preview' => 'token']);
    }

    /** @param array<string, mixed> $parameters */
    public function renderHome(Request $request, array $parameters = []): Response
    {
        $page = $this->renderer->renderHome(false);
        if ($page === null) {
            return new Response($this->defaultHomeHtml(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return new Response($page['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-CajeerEngine-Public-Path' => $page['path'],
        ]);
    }

    /** @param array<string, mixed> $parameters */
    public function renderByPath(Request $request, array $parameters): Response
    {
        $path = (string) ($parameters['path'] ?? $parameters['slug'] ?? '');
        if ($path === '' || $this->isReservedPath($path)) {
            return $this->notFound();
        }

        $redirect = $this->redirects->match('/' . $path);
        if ($redirect !== null) {
            return new RedirectResponse((string) $redirect['target_url'], (int) $redirect['status_code']);
        }

        $page = $this->renderer->renderByPath($path, false);
        if ($page === null) {
            return $this->notFound();
        }

        return new Response($page['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-CajeerEngine-Public-Path' => $page['path'],
        ]);
    }

    /** @param array<string, mixed> $parameters */
    public function renderBySlug(Request $request, array $parameters): Response
    {
        return $this->renderByPath($request, $parameters);
    }

    private function defaultHomeHtml(): string
    {
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CajeerEngine</title><style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f7f9fc;color:#172033;margin:0}.wrap{max-width:860px;margin:10vh auto;padding:28px}a{color:#1f5eff}.card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:26px;box-shadow:0 18px 48px rgba(15,23,42,.08)}</style></head><body><main class="wrap"><section class="card"><h1>CajeerEngine установлен</h1><p>Публичная главная страница пока не опубликована. Запустите <code>php bin/cajeer cms:bootstrap --demo-home</code> или создайте страницу в админке.</p><p><a href="/admin/">Открыть Admin UI</a></p></section></main></body></html>';
    }

    private function isReservedPath(string $path): bool
    {
        $first = explode('/', trim($path, '/'))[0] ?? '';
        return in_array($first, ['api', 'admin', 'uploads', 'install', 'upgrade'], true);
    }

    private function baseUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

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
