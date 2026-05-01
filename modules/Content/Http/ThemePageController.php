<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Http;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Content\Repository\ContentRepository;
use Cajeer\Themes\ThemeManager;
use Cajeer\View\ViewRenderer;
use Throwable;

final class ThemePageController
{
    public function __construct(private readonly Container $container) {}

    public function home(Request $request): Response { return $this->render('home', 'home', $request, ['projects' => $this->loadProjects()]); }
    public function about(Request $request): Response { return $this->render('about', 'about', $request); }
    public function team(Request $request): Response { return $this->render('team', 'team', $request); }
    public function support(Request $request): Response { return $this->render('support', 'support', $request); }
    public function brand(Request $request): Response { return $this->render('brand', 'brand', $request); }
    public function projects(Request $request): Response { return $this->render('projects', 'projects', $request, ['projects' => $this->loadProjects()]); }

    public function project(Request $request): Response
    {
        $slug = (string) $request->input('slug');
        $project = $this->projectBySlug($slug);
        if (!$project) {
            return $this->notFound($request);
        }
        return $this->render('project', $slug, $request, ['currentProject' => $project], $project);
    }

    public function page(Request $request): Response
    {
        $slug = trim((string) $request->input('slug'), '/');
        if ($slug === '') {
            return $this->home($request);
        }
        if (in_array($slug, ['about', 'team', 'projects', 'support', 'brand'], true)) {
            return $this->render($slug, $slug, $request, $slug === 'projects' ? ['projects' => $this->loadProjects()] : []);
        }
        try {
            $repo = new ContentRepository($this->container->get(DatabaseManager::class));
            $item = $repo->findPublishedBySlug('page', $slug);
            if ($item) {
                return Response::html($this->container->get(ViewRenderer::class)->render('page', [
                    'title' => $item->metaTitle ?: $item->title,
                    'item' => $item,
                    'content' => (string) $item->body,
                    'config' => $this->themes()->config($this->preview($request)),
                    'page' => $slug,
                    'pageMeta' => ['title' => $item->metaTitle ?: $item->title, 'description' => $item->metaDescription ?: (string) $item->excerpt],
                    'canonicalUrl' => $this->canonical('/' . $slug),
                    'theme_preview' => $this->preview($request),
                ]));
            }
        } catch (Throwable) {}
        return $this->notFound($request);
    }

    public function sitemap(Request $request): Response
    {
        $config = $this->themes()->config($this->preview($request));
        $urls = [];
        foreach (($config['meta'] ?? []) as $page => $meta) {
            if ($page === '404') continue;
            $path = $page === 'home' ? '/' : '/' . $page;
            $urls[] = ['loc' => $this->canonical($path), 'lastmod' => $meta['updated_at'] ?? null];
        }
        foreach ($this->loadProjects() as $project) {
            $urls[] = ['loc' => $this->canonical('/projects/' . $project['slug']), 'lastmod' => $project['updated_at'] ?? null];
        }
        try {
            foreach ((new ContentRepository($this->container->get(DatabaseManager::class)))->published('page', 500) as $item) {
                $urls[] = ['loc' => $this->canonical('/' . $item->slug), 'lastmod' => $item->updatedAt];
            }
        } catch (Throwable) {}
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars((string) $url['loc'], ENT_XML1) . '</loc>';
            if (!empty($url['lastmod'])) $xml .= '<lastmod>' . htmlspecialchars(substr((string) $url['lastmod'], 0, 10), ENT_XML1) . '</lastmod>';
            $xml .= "</url>\n";
        }
        $xml .= '</urlset>';
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(Request $request): Response
    {
        return new Response("User-agent: *\nAllow: /\nSitemap: " . $this->canonical('/sitemap.xml') . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function manifest(Request $request): Response
    {
        $config = $this->themes()->config($this->preview($request));
        $data = [
            'name' => (string) ($config['site']['name'] ?? 'Cajeer'),
            'short_name' => (string) ($config['site']['short_name'] ?? $config['site']['name'] ?? 'Cajeer'),
            'lang' => (string) ($config['site']['language'] ?? 'ru'),
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => (string) ($config['site']['background_color'] ?? '#0b0f19'),
            'theme_color' => (string) ($config['site']['theme_color'] ?? '#0b0f19'),
            'icons' => [
                ['src' => theme_asset('img/favicon-16.png'), 'sizes' => '16x16', 'type' => 'image/png'],
                ['src' => theme_asset('img/favicon-32.png'), 'sizes' => '32x32', 'type' => 'image/png'],
                ['src' => theme_asset('img/apple-touch-icon.png'), 'sizes' => '180x180', 'type' => 'image/png'],
                ['src' => theme_asset('img/android-chrome-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => theme_asset('img/android-chrome-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ];
        return new Response(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', 200, ['Content-Type' => 'application/manifest+json; charset=UTF-8']);
    }

    private function render(string $view, string $page, Request $request, array $extra = [], ?array $project = null): Response
    {
        $preview = $this->preview($request);
        $themes = $this->themes();
        $config = $themes->config($preview);
        $meta = $themes->pageMeta($page, $project, $preview);
        $html = $this->container->get(ViewRenderer::class)->render($view, $extra + [
            'title' => $meta['title'] ?? ($config['site']['name'] ?? 'Cajeer'),
            'config' => $config,
            'page' => $page,
            'pageMeta' => $meta,
            'canonicalUrl' => $this->canonical($page === 'home' ? '/' : '/'.trim($page, '/')),
            'theme_preview' => $preview,
        ]);
        return Response::html($html);
    }

    private function notFound(Request $request): Response
    {
        $preview = $this->preview($request);
        $config = $this->themes()->config($preview);
        $html = $this->container->get(ViewRenderer::class)->render('errors.404', [
            'title' => 'Страница не найдена',
            'config' => $config,
            'page' => '404',
            'pageMeta' => $config['meta']['404'] ?? ['title' => '404', 'description' => 'Страница не найдена'],
            'canonicalUrl' => $this->canonical($request->path()),
            'theme_preview' => $preview,
        ]);
        return Response::html($html, 404);
    }

    private function themes(): ThemeManager { return $this->container->get(ThemeManager::class); }
    private function preview(Request $request): ?string { return $request->input('theme_preview') ? (string) $request->input('theme_preview') : null; }
    private function canonical(string $path): string
    {
        $config = $this->themes()->config();
        $domain = rtrim((string) ($config['site']['domain'] ?? ''), '/');
        return $domain !== '' ? $domain . ($path === '/' ? '/' : '/' . trim($path, '/')) : ($path === '/' ? '/' : '/' . trim($path, '/'));
    }

    private function loadProjects(): array
    {
        try {
            $items = (new ContentRepository($this->container->get(DatabaseManager::class)))->published('project', 100);
            if ($items !== []) {
                return array_map(static fn ($item): array => [
                    'name' => $item->title,
                    'slug' => $item->slug,
                    'short_description' => $item->excerpt ?: $item->metaDescription,
                    'description' => $item->body ?: $item->excerpt,
                    'public_note' => $item->metaDescription,
                    'status' => $item->status,
                    'order' => $item->sortOrder,
                    'is_featured' => true,
                    'external_url' => $item->canonicalUrl,
                    'meta_title' => $item->metaTitle,
                    'meta_description' => $item->metaDescription,
                    'updated_at' => $item->updatedAt ?: $item->publishedAt,
                ], $items);
            }
        } catch (Throwable) {}
        $projects = array_values($this->themes()->config()['projects'] ?? []);
        usort($projects, static fn (array $a, array $b): int => ($a['order'] ?? 9999) <=> ($b['order'] ?? 9999));
        return $projects;
    }

    private function projectBySlug(string $slug): ?array
    {
        foreach ($this->loadProjects() as $project) {
            if (($project['slug'] ?? '') === $slug) return $project;
        }
        return null;
    }
}
