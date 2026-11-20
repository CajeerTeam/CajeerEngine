<?php

declare(strict_types=1);
namespace CajeerEngine\Public;

/** Content-addressed validation, one bounded entry per public path; preview never uses this cache. */
final readonly class RenderCache
{
    private const MAX_HTML_BYTES = 2 * 1024 * 1024;
    public function __construct(private string $root) {}

    public function render(string $path, array $context, callable $render): string
    {
        $ttl = max(0, min(3600, (int) (getenv('PUBLIC_RENDER_CACHE_TTL') ?: 0)));
        if ($ttl === 0) return $render();
        $dir = $this->root . '/storage/cache/public';
        $fingerprints = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/templates', \FilesystemIterator::SKIP_DOTS)) as $template) {
            if ($template->isFile() && !$template->isLink() && $template->getExtension() === 'cjr') {
                // A changed template invalidates even a still-fresh HTML response.
                $fingerprints[$template->getPathname()] = hash_file('sha256', $template->getPathname());
            }
        }
        ksort($fingerprints);
        $key = hash('sha256', json_encode([$context, $fingerprints, hash_file('sha256', __FILE__), hash_file('sha256', __DIR__.'/PublicPageRenderer.php'), hash_file('sha256', __DIR__.'/BlockRenderer.php'), PHP_VERSION], JSON_THROW_ON_ERROR));
        $file = $dir . '/' . hash('sha256', $path) . '.json';
        $cached = self::read($file, $key);
        if ($cached !== null) return $cached;
        // HTML above the cache size cap is intentionally never cached. A
        // short-lived hint avoids serializing every such render behind a lock.
        if (self::oversized($file, $key)) return $render();

        // Per-path advisory lock, held only for cache misses. A second FPM worker
        // rechecks the cache after the first finishes instead of rendering the
        // same expensive page. No global lock: unrelated URLs render concurrently.
        $lock = null;
        try {
            \CajeerEngine\Update\OperationFiles::dir($dir);
            $lockFile = substr($file, 0, -5) . '.lock';
            if (!is_link($lockFile)) $lock = @fopen($lockFile, 'c+b');
        } catch (\Throwable) {
            // Cache I/O is best-effort; fall back to rendering.
        }
        if (!is_resource($lock)) return $render();
        try {
            if (!flock($lock, LOCK_EX)) return $render();
            $cached = self::read($file, $key);
            if ($cached !== null) return $cached;
            if (self::oversized($file, $key)) {
                // A peer discovered an uncacheable oversized render while we
                // waited. Drop the advisory lock BEFORE rendering this request.
                flock($lock, LOCK_UN);
                fclose($lock);
                $lock = null;
                return $render();
            }
            // Do not catch render() exceptions: an application error must not be
            // hidden or cause the callback to run a second time.
            return self::renderAndStore($file, $key, $ttl, $render);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function read(string $file, string $key): ?string
    {
        if (is_link($file) || !is_file($file)) return null;
        // Cache entries cannot legitimately exceed the maximum rendered HTML
        // plus their JSON envelope. Avoid loading an arbitrary oversized file.
        $size = @filesize($file);
        if ($size === false || $size > self::MAX_HTML_BYTES * 3) return null;
        $raw = @file_get_contents($file);
        if (!is_string($raw)) return null;
        $record = json_decode($raw, true);
        if (!is_array($record) || ($record['key'] ?? null) !== $key || ($record['expires'] ?? 0) <= time()) return null;
        $html = $record['html'] ?? null;
        return is_string($html) && strlen($html) <= self::MAX_HTML_BYTES ? $html : null;
    }

    /** Hint only. Never serve HTML from an oversize marker. */
    private static function oversized(string $file, string $key): bool
    {
        if (is_link($file) || !is_file($file)) return false;
        clearstatcache(true, $file);
        $size = @filesize($file);
        if (!is_int($size) || $size < 2 || $size > 512) return false;
        $raw = @file_get_contents($file);
        if (!is_string($raw)) return false;
        $record = json_decode($raw, true);
        return is_array($record) && ($record['key'] ?? null) === $key
            && ($record['oversize'] ?? false) === true && ($record['expires'] ?? 0) > time();
    }

    private static function renderAndStore(string $file, string $key, int $ttl, callable $render): string
    {
        $html = $render();
        try {
            if (strlen($html) <= self::MAX_HTML_BYTES) {
                \CajeerEngine\Update\OperationFiles::write($file, json_encode(['key' => $key, 'expires' => time() + $ttl, 'html' => $html], JSON_THROW_ON_ERROR), 0640);
            } else {
                // The hint is small, short-lived and content-keyed. It never
                // serves stale HTML; subsequent requests render independently.
                \CajeerEngine\Update\OperationFiles::write($file, json_encode(['key' => $key, 'expires' => time() + min(60, $ttl), 'oversize' => true], JSON_THROW_ON_ERROR), 0640);
            }
        } catch (\Throwable) {
            // Cache failure cannot take the site offline.
        }
        return $html;
    }
}
