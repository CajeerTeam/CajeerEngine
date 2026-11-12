<?php

declare(strict_types=1);
namespace CajeerEngine\Release;

/** Only shipped application files and explicit empty runtime markers are distributable. */
final class ReleaseFilePolicy
{
    public const KEEP = [
        'storage/.gitkeep', 'storage/app/.gitkeep', 'storage/app/backups/.gitkeep',
        'storage/app/extensions/.gitkeep', 'storage/app/media/.gitkeep', 'storage/app/reports/.gitkeep',
        'storage/app/support/.gitkeep', 'storage/app/updates/.gitkeep', 'storage/cache/.gitkeep',
        'storage/database/.gitkeep', 'storage/logs/.gitkeep', 'storage/tmp/.gitkeep',
        'bootstrap/cache/.gitkeep', 'public/uploads/.gitkeep', 'public/extensions/.gitkeep',
        'modules/.gitkeep', 'plugins/.gitkeep', 'themes/.gitkeep',
    ];
    private const ROOT = ['.env.example', 'VERSION', 'composer.json', 'composer.lock', 'release.json', 'README.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE', 'SECURITY.md', 'THIRD_PARTY_NOTICES.md', 'TRADEMARKS.md', 'install.php', 'upgrade.php', 'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md'];
    private const PUBLIC = ['public/index.php', 'public/install/index.php', 'public/upgrade/index.php', 'public/upgrade/upgrade.js', 'public/admin/index.html', 'public/admin/assets/admin-app.js', 'public/admin/assets/admin-app.css', 'public/admin/assets/admin-preview.js', 'public/admin/assets/admin-preview.css', 'public/admin/assets/admin-manifest.json'];

    public static function traverses(string $path, string $mode): bool
    {
        foreach (array_merge(self::KEEP, self::PUBLIC) as $file) if (str_starts_with($file, $path . '/')) return true;
        return self::allows($path . '/release-probe.php', $mode) || in_array($path, ['tools', 'bin'], true) || ($mode === 'source' && $path === 'deploy');
    }

    public static function allows(string $path, string $mode): bool
    {
        if ($path === '' || preg_match('~(?:^|/)(?:\.{1,2}|\.git|\.env(?:\..*)?|node_modules|\.nuxt|\.output|\.cache|\.idea|\.vscode)(?:/|$)~i', $path) && $path !== '.env.example') return false;
        if (str_contains($path, '\\') || str_contains($path, ':') || str_contains($path, '//') || str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path)) return false;
        if (in_array($path, self::KEEP, true) || in_array($path, self::ROOT, true) || in_array($path, self::PUBLIC, true)) return true;
        if (preg_match('/\.(?:log|tmp|sqlite3?|db|pem|key|zip|bak)$/i', $path)) return false;
        if (str_starts_with($path, 'tools/')) return in_array($path, ['tools/build-release.php', 'tools/ci-check.php', 'tools/release-pipeline.sh', 'tools/release-smoke.sh', 'tools/release-regression.php', 'tools/security-regression.php', 'tools/generate-api-sdk.php', 'tools/update-postcheck.php', 'tools/update-recovery.php', 'tools/update-regression.php', 'tools/update-dist-smoke.sh', 'tools/backup-db-regression.php', 'tools/import-regression.php', 'tools/extension-regression.php','tools/routing-regression.php','tools/media-regression.php','tools/rendering-regression.php','tools/search-regression.php','tools/performance-regression.php','tools/database-benchmark.py','tools/admin-editor-browser.py','tools/queue-regression.php','tools/observability-regression.php','tools/disaster-recovery-regression.php','tools/disaster-recovery-dist-smoke.sh'], true) && ($mode === 'source' || in_array($path, ['tools/build-release.php', 'tools/ci-check.php', 'tools/update-postcheck.php', 'tools/update-recovery.php'], true));
        if (preg_match('~^(core|config|migrations|templates|installer|api|resources|docs)/~', $path)) return true;
        if ($path === 'bin/cajeer') return true;
        if ($mode === 'dist') return str_starts_with($path, 'vendor/');
        if (str_starts_with($path, 'deploy/')) return in_array($path, ['deploy/production.py', 'deploy/install.php', 'deploy/verify.php', 'deploy/test_production.py', 'deploy/host-smoke.sh', 'deploy/site.example.json'], true);
        return in_array($path, ['.gitignore', 'phpunit.xml.dist', 'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md'], true)
            || preg_match('~^(admin|tests|packages|examples|\.github)/~', $path) === 1;
    }
}
