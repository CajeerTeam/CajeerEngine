<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

/** Convert the public GitHub Releases REST response into the existing updater catalog. */
final class GitHubReleaseCatalog
{
    /** @return array{releases: list<array<string,mixed>>}|null */
    public static function normalize(array $payload): ?array
    {
        if (!array_is_list($payload)) return null;
        $releases = [];
        foreach ($payload as $release) {
            if (!is_array($release) || ($release['draft'] ?? true) !== false || !is_bool($release['prerelease'] ?? null)) continue;
            $tag = $release['tag_name'] ?? null;
            if (!is_string($tag) || !preg_match('/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?)$/D', $tag, $match)) continue;
            $version = $match[1];
            $prerelease = $release['prerelease'];
            // A prerelease flag or SemVer suffix must never enter the stable channel.
            if ($prerelease !== str_contains($version, '-')) continue;
            $published = $release['published_at'] ?? null;
            if (!is_string($published) || $published === '' || strtotime($published) === false) continue;
            if (!is_array($release['assets'] ?? null)) continue;
            $assets = [];
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset) || ($asset['state'] ?? null) !== 'uploaded' || !is_string($asset['name'] ?? null) || !is_int($asset['size'] ?? null) || $asset['size'] <= 0) continue;
                $name = $asset['name'];
                // Construct canonical links ourselves; never forward arbitrary API-provided URLs.
                if (isset($assets[$name])) continue;
                $assets[$name] = $asset;
            }
            $artifacts = [];
            foreach (['source', 'dist'] as $mode) {
                $name = "cajeerengine-$version-$mode.zip";
                if (!isset($assets[$name], $assets[$name.'.sha256'], $assets[$name.'.sig'])) continue;
                $artifact = [
                    'name' => $name,
                    'download_url' => GitHubEndpoints::assetUrl($tag, $name),
                    'checksum_name' => $name.'.sha256',
                    'checksum_url' => GitHubEndpoints::assetUrl($tag, $name.'.sha256'),
                    'signature_name' => $name.'.sig',
                    'signature_url' => GitHubEndpoints::assetUrl($tag, $name.'.sig'),
                    'size' => $assets[$name]['size'],
                ];
                $digest = $assets[$name]['digest'] ?? null;
                if (is_string($digest) && preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $hash)) $artifact['sha256'] = $hash[1];
                $artifacts[$mode] = $artifact;
            }
            // A Git tag, automatic source archive or incomplete upload is not a deployable release.
            if (!isset($artifacts['dist'])) continue;
            $channel = !$prerelease ? 'stable' : (preg_match('/-rc(?:[.0-9-]|$)/i', $version) ? 'rc' : (preg_match('/-beta(?:[.0-9-]|$)/i', $version) ? 'beta' : 'dev'));
            $releases[] = [
                'version' => $version, 'channel' => $channel, 'stability' => $channel,
                'released_at' => $published, 'source' => GitHubEndpoints::releaseUrl($tag),
                'notes' => is_string($release['body'] ?? null) ? $release['body'] : '',
                'artifacts' => $artifacts,
            ];
        }
        return ['releases' => $releases];
    }
}
