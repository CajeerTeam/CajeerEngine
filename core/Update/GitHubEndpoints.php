<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

final class GitHubEndpoints
{
    public const REPOSITORY = 'cajeerteam/CajeerEngine';
    public const PROJECT_URL = 'https://github.com/' . self::REPOSITORY;
    public const RELEASES_URL = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases?per_page=100';
    public const REGISTRY_URL = self::PROJECT_URL . '/releases';
    public const BUNDLED_RELEASES_FILE = 'resources/updates/releases.json';

    public static function releaseUrl(string $tag): string
    {
        return self::PROJECT_URL . '/releases/tag/' . rawurlencode($tag);
    }

    public static function assetUrl(string $tag, string $name): string
    {
        return self::PROJECT_URL . '/releases/download/' . rawurlencode($tag) . '/' . rawurlencode($name);
    }

    private function __construct() {}
}
