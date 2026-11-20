<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Public\RouteIndex;

/** Content-backed SEO audit. This service never fetches untrusted URLs or changes content. */
final readonly class AiSeoAssistant
{
    public function __construct(
        private ContentEntryRepository $entries,
        private ContentTypeRepository $types,
        private RouteIndex $routes,
    ) {}

    /** @return array<string,mixed> */
    public function audit(string $type, string $id): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/D', $type) ||
            !preg_match('/^[a-f0-9-]{36}$/iD', $id) || $this->types->find($type) === null) {
            throw new \InvalidArgumentException('Invalid SEO content selection.');
        }
        $entry = $this->entries->findById($type, $id);
        if ($entry === null) throw new \DomainException('Content entry not found.');
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $title = trim((string)($data['seo_title'] ?? ''));
        $description = trim((string)($data['meta_description'] ?? ''));
        $route = $this->routes->forEntry($id);
        $path = (string)($route['path'] ?? '');
        $canonical = trim((string)($data['canonical_url'] ?? ''));
        $noindex = ($data['seo_noindex'] ?? false) === true;
        $issues = [];
        $issue = static function (string $code, string $severity, string $message, string $action) use (&$issues): void {
            $issues[] = compact('code', 'severity', 'message', 'action');
        };
        $length = static fn(string $text): int => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : preg_match_all('/./us', $text);
        if ($title === '') $issue('missing_seo_title', 'high', 'Отсутствует SEO Title; используется заголовок записи или тема.', 'Создать уникальный SEO Title на основе страницы.');
        elseif ($length($title) > 65) $issue('long_seo_title', 'medium', 'SEO Title может обрезаться в результатах поиска.', 'Сократить SEO Title без потери смысла.');
        elseif ($length($title) < 20) $issue('short_seo_title', 'low', 'SEO Title недостаточно информативный.', 'Уточнить тему и назначение страницы.');
        if ($description === '') $issue('missing_description', 'high', 'Meta description отсутствует; поисковик может выбирать текст страницы.', 'Создать точное описание страницы.');
        elseif ($length($description) > 160) $issue('long_description', 'medium', 'Meta description длиннее рекомендуемого ориентира.', 'Сократить описание.');
        elseif ($length($description) < 50) $issue('short_description', 'low', 'Meta description очень короткое.', 'Уточнить полезность страницы в описании.');
        // Duplicate detection is bounded to a published sample, not an unbounded full export.
        if ($entry['status'] === 'published' && ($title !== '' || $description !== '')) {
            $peers = $this->entries->page($type, ['status' => 'published', 'locale' => (string)$entry['locale']], 200, 0);
            foreach ($peers['data'] as $peer) {
                if (($peer['id'] ?? '') === $id) continue;
                $other = (array)($peer['data'] ?? []);
                if ($title !== '' && trim((string)($other['seo_title'] ?? '')) === $title) {
                    $issue('duplicate_seo_title', 'medium', 'SEO Title совпадает с другой опубликованной страницей этого типа.', 'Создать уникальный заголовок для каждой страницы.');
                    $title = ''; // report a duplicate of this field at most once
                }
                if ($description !== '' && trim((string)($other['meta_description'] ?? '')) === $description) {
                    $issue('duplicate_description', 'medium', 'Meta description совпадает с другой опубликованной страницей.', 'Уточнить описание под конкретную страницу.');
                    $description = ''; // report a duplicate of this field at most once
                }
                if ($title === '' && $description === '') break;
            }
            // Do not mutate the values returned to the UI while tracking duplicate hits.
            $title = trim((string)($data['seo_title'] ?? ''));
            $description = trim((string)($data['meta_description'] ?? ''));
        }
        if ($entry['status'] === 'published' && $route === null) $issue('missing_route', 'high', 'У опубликованного материала нет публичного маршрута.', 'Проверить маршруты CMS и правила публикации.');
        if ($noindex) $issue('noindex', 'info', 'Страница помечена noindex и не должна участвовать в sitemap.', 'Оставить noindex, если исключение из индекса намеренное.');
        if ($canonical !== '') {
            $expectedPath = $path === '' ? null : parse_url($path, PHP_URL_PATH);
            $actualPath = parse_url($canonical, PHP_URL_PATH);
            if ($expectedPath !== null && $actualPath !== null && $actualPath !== $expectedPath) {
                $issue('canonical_differs', 'info', 'Canonical указывает на другой путь.', 'Проверить намеренность канонизации.');
            }
        }
        $fragments = [];
        $collect = static function (mixed $value, int $depth = 0) use (&$collect, &$fragments): void {
            if ($depth > 8 || count($fragments) > 300) return;
            if (is_string($value) && $value !== '') { $fragments[] = substr($value, 0, 15000); return; }
            if (is_array($value)) foreach ($value as $key => $part) {
                if (in_array((string)$key, ['seo_title','meta_description','canonical_url','og_title','og_description','path','parent_id'], true)) continue;
                $collect($part, $depth + 1);
            }
        };
        $collect($data);
        $html = implode("\n", $fragments);
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = preg_split('/[\s\p{P}]+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = count($words);
        if ($wordCount < 120) $issue('thin_content', 'low', 'В текстовых полях материала мало слов (' . $wordCount . ').', 'Проверить полноту ответа на запрос посетителя.');
        preg_match_all('~<h([1-6])\b[^>]*>~iu', $html, $headingMatches);
        $headingCounts = array_fill_keys(['h1','h2','h3','h4','h5','h6'], 0);
        foreach ($headingMatches[1] as $n) $headingCounts['h' . $n]++;
        if ($headingCounts['h1'] > 1) $issue('multiple_h1', 'medium', 'В сохранённом HTML несколько H1.', 'Проверить структуру заголовков (шаблон может добавлять H1 отдельно).');
        if ($headingCounts['h2'] === 0 && $wordCount >= 300) $issue('no_subheadings', 'low', 'В длинном тексте нет H2.', 'Разбить длинный текст на тематические разделы.');
        preg_match_all('~<img\b[^>]*>~iu', $html, $images);
        $missingAlt = 0;
        foreach ($images[0] as $tag) if (!preg_match('~\balt\s*=\s*(["\'])[^"\']+\1~iu', $tag)) $missingAlt++;
        if ($missingAlt > 0) $issue('missing_image_alt', 'medium', 'Изображений без непустого alt: ' . $missingAlt . '.', 'Добавить точные альтернативные описания для информативных изображений.');
        preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~iu', $html, $links);
        $badLinks = []; $internalCount = 0;
        foreach ($links[2] as $href) {
            if (count($badLinks) >= 10 || !str_starts_with($href, '/') || str_starts_with($href, '//')) continue;
            $urlPath = parse_url($href, PHP_URL_PATH);
            if (!is_string($urlPath) || $urlPath === '' || $urlPath === '/' || !preg_match('~^/[a-zA-Z0-9_./%-]+$~D', $urlPath)) continue;
            $internalCount++;
            try { if ($this->routes->lookup($urlPath) === null) $badLinks[] = $urlPath; } catch (\InvalidArgumentException) { $badLinks[] = $urlPath; }
        }
        if ($badLinks !== []) $issue('broken_internal_links', 'medium', 'Внутренние ссылки без маршрута: ' . implode(', ', array_unique($badLinks)) . '.', 'Проверить ссылки и редиректы.');
        $score = 100;
        foreach ($issues as $item) $score -= match ($item['severity']) {'high'=>20,'medium'=>10,'low'=>5,default=>0};
        return [
            'type' => $type, 'entry_id' => $id, 'revision' => (int)$entry['revision'], 'status' => $entry['status'],
            'title' => $entry['title'], 'locale' => $entry['locale'], 'path' => $path ?: null,
            'seo' => ['title' => $title, 'description' => $description, 'canonical_url' => $canonical, 'noindex' => $noindex],
            'stats' => ['words' => $wordCount, 'headings' => $headingCounts, 'images_without_alt' => $missingAlt, 'internal_links_checked' => $internalCount],
            'score' => max(0, $score), 'issues' => $issues,
            'notes' => 'Проверка использует сохранённые данные CMS и доступные публичные маршруты, а не HTML внешнего сайта. Совпадения метаданных проверяются среди первых 200 опубликованных записей данного типа и языка. Оценка эвристическая и не является показателем ранжирования.',
        ];
    }

    /** @return array<string,mixed> */
    public function batch(string $type, int $limit = 30): array
    {
        if ($limit < 1 || $limit > 50 || !preg_match('/^[a-z][a-z0-9_]{1,99}$/D', $type) || $this->types->find($type) === null)
            throw new \InvalidArgumentException('Invalid SEO batch parameters.');
        $entries = $this->entries->page($type, ['status'=>'published'], $limit, 0);
        $reports = [];
        foreach ($entries['data'] as $entry) $reports[] = $this->audit($type, (string)$entry['id']);
        return ['type' => $type, 'reports' => $reports, 'meta' => ['checked' => count($reports), 'available' => $entries['meta']['total'], 'limit' => $limit]];
    }
}
