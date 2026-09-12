<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

final class GitFlicEndpoints
{
    public const PROJECT_URL = 'https://gitflic.ru/project/cajeerteam/cajeerengine';
    public const RELEASES_URL = self::PROJECT_URL . '/releases';
    public const REGISTRY_URL = self::PROJECT_URL . '/packages';
    public const BUNDLED_RELEASES_FILE = 'resources/updates/releases.json';

    private function __construct()
    {
    }
}
