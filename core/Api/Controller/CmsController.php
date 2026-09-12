<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Cms\CmsBootstrapService;
use CajeerEngine\Cms\NavigationRepository;
use CajeerEngine\Cms\PreviewTokenService;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CmsController
{
    public function __construct(
        private ThemeConfigRepository $theme,
        private NavigationRepository $navigation,
        private RedirectRepository $redirects,
        private PreviewTokenService $previewTokens,
        private CmsBootstrapService $bootstrap,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $menus = $this->navigation->all();
        $redirects = $this->redirects->all();
        return new JsonResponse([
            'data' => [
                'theme' => $this->theme->get(),
                'navigation_menus' => count($menus),
                'redirects' => count($redirects),
                'routes' => [
                    'home' => '/',
                    'sitemap_xml' => '/sitemap.xml',
                    'robots_txt' => '/robots.txt',
                    'preview_token' => '/preview/{token}',
                ],
            ],
        ]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        $result = $this->bootstrap->run(filter_var($payload['demo_home'] ?? false, FILTER_VALIDATE_BOOLEAN));
        return new JsonResponse(['data' => $result]);
    }

    public function theme(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->theme->get()]);
    }

    public function updateTheme(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->theme->save($this->json($request))]);
    }

    public function navigation(Request $request): JsonResponse
    {
        $items = $this->navigation->all();
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    /** @param array<string,mixed> $parameters */
    public function navigationShow(Request $request, array $parameters): JsonResponse
    {
        $menu = $this->navigation->find((string) ($parameters['handle'] ?? ''));
        if ($menu === null) {
            return $this->notFound('navigation_menu_not_found', 'Меню не найдено.');
        }
        return new JsonResponse(['data' => $menu]);
    }

    /** @param array<string,mixed> $parameters */
    public function navigationSave(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $handle = (string) ($parameters['handle'] ?? $payload['handle'] ?? 'primary');
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $menu = $this->navigation->save($handle, (string) ($payload['name'] ?? ucfirst($handle)), $items);
        return new JsonResponse(['data' => $menu]);
    }

    /** @param array<string,mixed> $parameters */
    public function navigationDelete(Request $request, array $parameters): JsonResponse
    {
        if (!$this->navigation->delete((string) ($parameters['handle'] ?? ''))) {
            return $this->notFound('navigation_menu_not_found', 'Меню не найдено.');
        }
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function redirects(Request $request): JsonResponse
    {
        $items = $this->redirects->all();
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    public function redirectStore(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->redirects->save($this->json($request))], Response::HTTP_CREATED);
    }

    /** @param array<string,mixed> $parameters */
    public function redirectUpdate(Request $request, array $parameters): JsonResponse
    {
        $existing = $this->redirects->find((string) ($parameters['id'] ?? ''));
        if ($existing === null) {
            return $this->notFound('redirect_not_found', 'Редирект не найден.');
        }
        return new JsonResponse(['data' => $this->redirects->save($this->json($request) + $existing, (string) $existing['id'])]);
    }

    /** @param array<string,mixed> $parameters */
    public function redirectDelete(Request $request, array $parameters): JsonResponse
    {
        if (!$this->redirects->delete((string) ($parameters['id'] ?? ''))) {
            return $this->notFound('redirect_not_found', 'Редирект не найден.');
        }
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function previewToken(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        $type = (string) ($payload['type'] ?? $payload['content_type'] ?? '');
        $entryId = (string) ($payload['entry_id'] ?? $payload['id'] ?? '');
        $ttl = (int) ($payload['ttl_seconds'] ?? 3600);
        return new JsonResponse(['data' => $this->previewTokens->create($type, $entryId, $ttl)], Response::HTTP_CREATED);
    }

    /** @return array<string,mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        if (is_array($parsed)) {
            return $parsed;
        }
        $body = trim($request->getContent());
        if ($body === '') {
            return [];
        }
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON body должен быть объектом.');
        }
        return $payload;
    }

    private function notFound(string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], Response::HTTP_NOT_FOUND);
    }
}
