<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Security\PublicHttpClient;
use CajeerEngine\Support\JsonFile;

/**
 * HTTP-conditional GitHub discovery. The local cache is a display/discovery aid,
 * NEVER a trusted artifact or an authorization to install an update.
 */
final readonly class GitHubReleaseMonitor
{
    /** @param null|\Closure(string,array<string,string>):array{0:int,1:string,2:array<string,string>} $transport */
    public function __construct(private ?string $rootPath, private ?\Closure $transport = null, private int $timeoutSeconds = 5)
    {
    }

    /** @return array<string,mixed> */
    public function fetch(): array
    {
        $cache = $this->readCache();
        $now = time();
        $until = (int) ($cache['rate_limited_until'] ?? 0);
        if ($until > $now) {
            return $this->fallback($cache, 'rate_limited', $until);
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'CajeerEngine-Release-Monitor',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ($cache !== null && isset($cache['etag']) && self::validEtag($cache['etag'])) {
            $headers['If-None-Match'] = $cache['etag'];
        }
        try {
            [$status, $body, $responseHeaders] = $this->transport !== null
                ? ($this->transport)(GitHubEndpoints::RELEASES_URL, $headers)
                : (new PublicHttpClient($this->timeoutSeconds))->requestWithHeaders(GitHubEndpoints::RELEASES_URL, 'GET', $headers, '', 4 * 1024 * 1024);

            if ($status === 304) {
                if ($cache === null) {
                    throw new \RuntimeException('GitHub returned 304 without usable cache');
                }
                $cache['checked_at'] = gmdate(DATE_ATOM, $now);
                $cache['rate_limited_until'] = 0;
                $this->writeCache($cache);
                return $this->fromCache($cache, 'not_modified');
            }
            if ($status === 200) {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                // Even an empty list is a valid response; never silently display an older release.
                $catalog = is_array($decoded) ? GitHubReleaseCatalog::normalize($decoded) : null;
                if ($catalog === null) throw new \RuntimeException('Invalid GitHub release list');
                $cache = [
                    'endpoint' => GitHubEndpoints::RELEASES_URL,
                    'releases' => $catalog['releases'],
                    'etag' => self::validEtag($responseHeaders['etag'] ?? null) ? $responseHeaders['etag'] : null,
                    'fetched_at' => gmdate(DATE_ATOM, $now),
                    'checked_at' => gmdate(DATE_ATOM, $now),
                    'rate_limited_until' => 0,
                ];
                $this->writeCache($cache);
                return $this->fromCache($cache, 'fresh');
            }
            if ($status === 403 || $status === 429) {
                $until = $this->rateLimitUntil($responseHeaders, $now);
                if ($until > $now && $cache !== null) {
                    $cache['rate_limited_until'] = $until;
                    $this->writeCache($cache);
                }
                return $this->fallback($cache, 'rate_limited', $until > $now ? $until : null);
            }
            return $this->fallback($cache, 'http_error');
        } catch (\Throwable) {
            // Never leak DNS, URL query strings or HTTP exception details to admin users.
            return $this->fallback($cache, 'network_error');
        }
    }

    /** @return array<string,mixed>|null */
    public function snapshot(): ?array
    {
        $cache = $this->readCache();
        return $cache === null ? null : $this->fromCache($cache, 'cached');
    }

    /** @param array<string,mixed>|null $cache @return array<string,mixed> */
    private function fallback(?array $cache, string $reason, ?int $retryAt = null): array
    {
        return [
            'releases' => $cache['releases'] ?? [],
            '_source' => $cache === null ? 'empty' : GitHubEndpoints::RELEASES_URL,
            '_status' => $cache === null ? 'unavailable' : 'stale',
            '_failure' => $reason,
            '_retry_at' => $retryAt !== null ? gmdate(DATE_ATOM, $retryAt) : null,
            '_fetched_at' => $cache['fetched_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $cache @return array<string,mixed> */
    private function fromCache(array $cache, string $status): array
    {
        return [
            'releases' => $cache['releases'],
            '_source' => GitHubEndpoints::RELEASES_URL,
            '_status' => $status,
            '_fetched_at' => $cache['fetched_at'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if ($path === null) return null;
        try {
            $cache = (new JsonFile($path))->readObject();
            if (($cache['endpoint'] ?? null) !== GitHubEndpoints::RELEASES_URL ||
                !is_array($cache['releases'] ?? null) || !array_is_list($cache['releases']) ||
                !is_string($cache['fetched_at'] ?? null) || strtotime($cache['fetched_at']) === false ||
                count($cache['releases']) > 100) return null;
            // Validate persisted data with the same conditions as network normalization.
            foreach ($cache['releases'] as $release) {
                if (!is_array($release) || !is_string($release['version'] ?? null) ||
                    !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $release['version']) ||
                    !is_array($release['artifacts']['dist'] ?? null)) return null;
            }
            if (array_key_exists('etag', $cache) && !self::validEtag($cache['etag'])) unset($cache['etag']);
            $cache['rate_limited_until'] = max(0, (int) ($cache['rate_limited_until'] ?? 0));
            return $cache;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $cache */
    private function writeCache(array $cache): void
    {
        $path = $this->cachePath();
        if ($path !== null) (new JsonFile($path))->writeObject($cache);
    }

    private function cachePath(): ?string
    {
        return $this->rootPath === null ? null : OperationFiles::path($this->rootPath, 'storage/app/updates/github-release-cache.json');
    }

    private static function validEtag(mixed $etag): bool
    {
        return is_string($etag) && strlen($etag) <= 160 && (bool) preg_match('/^(?:W\/)?"[!#-~]{1,140}"$/D', $etag);
    }

    /** @param array<string,string> $headers */
    private function rateLimitUntil(array $headers, int $now): int
    {
        $until = $now;
        $after = $headers['retry-after'] ?? null;
        if (is_string($after) && ctype_digit($after)) $until = $now + min((int) $after, 86400);
        $reset = $headers['x-ratelimit-reset'] ?? null;
        $remaining = $headers['x-ratelimit-remaining'] ?? null;
        if ($remaining === '0' && is_string($reset) && ctype_digit($reset)) {
            $until = max($until, min((int) $reset, $now + 86400));
        }
        return $until;
    }
}
