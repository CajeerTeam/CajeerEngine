<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

/** @deprecated Use GitHubEndpoints. Retained for installed extensions during migration. */
final class GitFlicEndpoints
{
    public const PROJECT_URL = GitHubEndpoints::PROJECT_URL;
    public const RELEASES_URL = GitHubEndpoints::RELEASES_URL;
    public const REGISTRY_URL = GitHubEndpoints::REGISTRY_URL;
    public const BUNDLED_RELEASES_FILE = GitHubEndpoints::BUNDLED_RELEASES_FILE;

    private function __construct() {}
}
