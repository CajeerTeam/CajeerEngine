<?php

declare(strict_types=1);

namespace CajeerEngine\Public;

use CajeerEngine\Cms\NavigationRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Template\CajeerTemplateEngine;

final readonly class PublicPageRenderer
{
    public function __construct(
        private string $rootPath,
        private ContentTypeRepository $types,
        private ContentEntryRepository $entries,
        private CajeerTemplateEngine $templates,
        private MediaRepository $media,
        private ?ThemeConfigRepository $theme = null,
        private ?NavigationRepository $navigation = null,
    ) {
    }

    /** @return array{type:array<string,mixed>,entry:array<string,mixed>,html:string,path:string,seo:array<string,mixed>}|null */
    public function renderByTypeAndId(string $typeHandle, string $idOrSlug, bool $preview = false): ?array
    {
        $type = $this->types->find($typeHandle);
        if ($type === null) {
            return null;
        }

        $entry = $this->entries->find($typeHandle, $idOrSlug);
        if ($entry === null) {
            return null;
        }

        if (!$preview && ($entry['status'] ?? null) !== 'published') {
            return null;
        }

        return $this->renderEntry($type, $entry, $preview);
    }

    /** @return array{type:array<string,mixed>,entry:array<string,mixed>,html:string,path:string,seo:array<string,mixed>}|null */
    public function renderBySlug(string $slug, bool $preview = false): ?array
    {
        return $this->renderByPath($slug, $preview);
    }

    /** @return array{type:array<string,mixed>,entry:array<string,mixed>,html:string,path:string,seo:array<string,mixed>}|null */
    public function renderByPath(string $path, bool $preview = false): ?array
    {
        $path = $this->normalizePath($path);
        $slugCandidate = trim($path, '/');

        foreach ($this->types->all() as $type) {
            $handle = (string) ($type['handle'] ?? '');
            if ($handle === '') {
                continue;
            }

            $direct = $slugCandidate !== '' ? $this->entries->find($handle, $slugCandidate) : null;
            if ($direct !== null && ($preview || ($direct['status'] ?? null) === 'published') && $this->normalizePath($this->entryPath($direct)) === $path) {
                return $this->renderEntry($type, $direct, $preview);
            }

            $entries = $this->entries->list($handle, ['status' => $preview ? 'all' : 'published', 'published_only' => !$preview]);
            foreach ($entries as $entry) {
                if (!$preview && ($entry['status'] ?? null) !== 'published') {
                    continue;
                }
                if ($this->normalizePath($this->entryPath($entry)) === $path) {
                    return $this->renderEntry($type, $entry, $preview);
                }
            }
        }

        return null;
    }

    /** @return array{type:array<string,mixed>,entry:array<string,mixed>,html:string,path:string,seo:array<string,mixed>}|null */
    public function renderHome(bool $preview = false): ?array
    {
        $theme = $this->themeConfig();
        $homeSlug = trim((string) ($theme['home_slug'] ?? 'home'), '/');
        foreach (array_values(array_unique([$homeSlug, '', 'home', 'index', 'main'])) as $path) {
            $page = $this->renderByPath($path === '' ? '/' : $path, $preview);
            if ($page !== null) {
                return $page;
            }
        }

        foreach ($this->types->all() as $type) {
            $handle = (string) ($type['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            $entries = $this->entries->list($handle, ['published_only' => !$preview, 'status' => $preview ? 'all' : 'published']);
            foreach ($entries as $entry) {
                if (!$preview && ($entry['status'] ?? null) !== 'published') {
                    continue;
                }
                return $this->renderEntry($type, $entry, $preview);
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function sitemap(): array
    {
        $items = [];
        foreach ($this->types->all() as $type) {
            $handle = (string) ($type['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            foreach ($this->entries->list($handle, ['published_only' => true]) as $entry) {
                $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
                if (filter_var($data['seo_noindex'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $path = $this->entryPath($entry);
                $items[] = [
                    'type' => $handle,
                    'id' => (string) ($entry['id'] ?? ''),
                    'title' => (string) ($entry['title'] ?? ''),
                    'slug' => (string) ($entry['slug'] ?? ''),
                    'path' => $path,
                    'locale' => (string) ($entry['locale'] ?? 'ru'),
                    'updated_at' => (string) ($entry['updated_at'] ?? ''),
                    'priority' => $path === '/' ? '1.0' : '0.7',
                    'changefreq' => $path === '/' ? 'daily' : 'weekly',
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return $items;
    }

    public function sitemapXml(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($this->sitemap() as $item) {
            $loc = $baseUrl . $this->normalizePath((string) ($item['path'] ?? '/'));
            $lastmod = $this->dateOnly((string) ($item['updated_at'] ?? ''));
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
            if ($lastmod !== '') {
                $xml .= '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</lastmod>\n";
            }
            $xml .= '    <changefreq>' . htmlspecialchars((string) ($item['changefreq'] ?? 'weekly'), ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</changefreq>\n";
            $xml .= '    <priority>' . htmlspecialchars((string) ($item['priority'] ?? '0.7'), ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</priority>\n";
            $xml .= "  </url>\n";
        }
        return $xml . "</urlset>\n";
    }

    public function robotsTxt(string $baseUrl): string
    {
        $theme = $this->themeConfig();
        $index = filter_var($theme['robots_index'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $lines = [
            'User-agent: *',
            $index ? 'Allow: /' : 'Disallow: /',
        ];
        if (filter_var($theme['sitemap_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $lines[] = 'Sitemap: ' . rtrim($baseUrl, '/') . '/sitemap.xml';
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param array<string,mixed> $type @param array<string,mixed> $entry @return array{type:array<string,mixed>,entry:array<string,mixed>,html:string,path:string,seo:array<string,mixed>} */
    private function renderEntry(array $type, array $entry, bool $preview): array
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $template = $this->templateFor($type, $entry);
        $theme = $this->themeConfig();
        $navigation = $this->navigation();
        $path = $this->entryPath($entry);
        $seo = $this->seo($entry, $theme, $path);
        $content = (string) ($data['body'] ?? $data['content'] ?? '');
        $title = (string) ($entry['title'] ?? ($data['title'] ?? $data['heading'] ?? ''));
        $context = [
            'title' => $title,
            'heading' => (string) ($data['heading'] ?? $title),
            'summary' => (string) ($data['summary'] ?? $data['excerpt'] ?? ''),
            'content' => $content,
            'data' => $data,
            'entry' => $entry,
            'type' => $type,
            'locale' => (string) ($entry['locale'] ?? ($theme['locale'] ?? 'ru')),
            'preview' => $preview,
            'media' => $this->media->all(),
            'published_at' => (string) ($entry['published_at'] ?? ''),
            'updated_at' => (string) ($entry['updated_at'] ?? ''),
            'theme' => $theme,
            'navigation' => $navigation,
            'seo' => $seo,
            'canonical_url' => (string) ($seo['canonical_url'] ?? ''),
            'path' => $path,
        ];

        $html = $this->templates->render($template, $context);
        return [
            'type' => $type,
            'entry' => $entry,
            'html' => $html,
            'path' => $path,
            'seo' => $seo,
        ];
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $theme @return array<string,mixed> */
    private function seo(array $entry, array $theme, string $path): array
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $themeSeo = is_array($theme['seo'] ?? null) ? $theme['seo'] : [];
        $title = trim((string) ($data['seo_title'] ?? $data['meta_title'] ?? $entry['title'] ?? $themeSeo['default_title'] ?? $theme['site_name'] ?? 'CajeerEngine'));
        $suffix = trim((string) ($themeSeo['title_suffix'] ?? ''));
        if ($suffix !== '' && !str_ends_with($title, $suffix)) {
            $title .= ' ' . $suffix;
        }
        $description = trim((string) ($data['meta_description'] ?? $data['summary'] ?? $themeSeo['default_description'] ?? $theme['site_description'] ?? ''));
        return [
            'title' => $title,
            'description' => $description,
            'canonical_url' => trim((string) ($data['canonical_url'] ?? $path)),
            'og_title' => trim((string) ($data['og_title'] ?? $title)),
            'og_description' => trim((string) ($data['og_description'] ?? $description)),
            'og_image' => trim((string) ($data['og_image'] ?? $themeSeo['og_image'] ?? '')),
            'robots' => filter_var($data['seo_noindex'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'noindex,nofollow' : 'index,follow',
        ];
    }

    /** @param array<string,mixed> $entry */
    private function entryPath(array $entry): string
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $path = trim((string) ($data['path'] ?? $data['_path'] ?? ''));
        if ($path !== '') {
            return $this->normalizePath($path);
        }
        return $this->normalizePath((string) ($entry['slug'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function themeConfig(): array
    {
        return ($this->theme ?? new ThemeConfigRepository($this->rootPath))->get();
    }

    /** @return array<string,mixed> */
    private function navigation(): array
    {
        $repo = $this->navigation ?? new NavigationRepository($this->rootPath);
        $theme = $this->themeConfig();
        $handle = (string) ($theme['primary_menu'] ?? 'primary');
        return [
            'primary' => $repo->find($handle) ?? $repo->primary(),
            'menus' => $repo->all(),
        ];
    }

    /** @param array<string,mixed> $type @param array<string,mixed> $entry */
    private function templateFor(array $type, array $entry): string
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $candidates = [
            (string) ($data['_template'] ?? ''),
            'types.' . (string) ($type['handle'] ?? '') . '.show',
            'default.page',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            try {
                return $this->templates->resolve($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->rootPath . '/templates/default/page.cjr';
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, " \t\n\r\0\x0B/");
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function dateOnly(string $date): string
    {
        if ($date === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($date))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }
}
