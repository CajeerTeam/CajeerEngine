<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

final readonly class VersionVerifier
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function verify(): array
    {
        $version = trim((string) @file_get_contents($this->rootPath . '/VERSION'));
        $release = $this->json('release.json');
        $composer = $this->json('composer.json');
        $phpSdk = $this->json('packages/php-sdk/composer.json');
        $tsSdk = $this->json('packages/ts-sdk/package.json');
        $admin = $this->json('admin/package.json');
        $checks = [];

        $checks[] = preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) === 1
            ? CheckResult::pass('version', 'VERSION', 'VERSION содержит корректный номер релиза.')
            : CheckResult::fail('version', 'VERSION', 'VERSION должен содержать версию MAJOR.MINOR.PATCH.', ['actual' => $version]);

        $expected = [
            'release.json' => $release['version'] ?? null,
            'packages/php-sdk/composer.json' => $phpSdk['version'] ?? null,
            'packages/ts-sdk/package.json' => $tsSdk['version'] ?? null,
            'admin/package.json' => $admin['version'] ?? null,
        ];
        $dist = ($this->json('release-manifest.json')['mode'] ?? '') === 'dist';
        foreach ($expected as $file => $actual) {
            if ($dist && $file !== 'release.json' && !is_file($this->rootPath . '/' . $file)) continue;
            $checks[] = $actual === $version
                ? CheckResult::pass('version', $file, $file . ' синхронизирован с VERSION.', ['version' => $actual])
                : CheckResult::fail('version', $file, $file . ' не синхронизирован с VERSION.', ['expected' => $version, 'actual' => $actual]);
        }

        // Do not publish a correctly tagged archive with stale visible UI or download links.
        $manifest = $this->json('public/admin/assets/admin-manifest.json');
        $checks[] = ($manifest['version'] ?? null) === $version && ($manifest['ready'] ?? false) === true
            ? CheckResult::pass('version', 'admin.manifest', 'Published admin assets declare the current version.')
            : CheckResult::fail('version', 'admin.manifest', 'Published admin assets are stale.', ['expected' => $version, 'actual' => $manifest['version'] ?? null]);

        foreach (['source', 'dist'] as $mode) {
            $expectedArtifact = 'storage/releases/cajeerengine-' . $version . '-' . $mode . '.zip';
            $key = $mode . '_artifact';
            $actual = $release['distribution'][$key] ?? null;
            $checks[] = $actual === $expectedArtifact
                ? CheckResult::pass('version', 'release.' . $key, 'Release artifact path matches VERSION.')
                : CheckResult::fail('version', 'release.' . $key, 'Release artifact path does not match VERSION.', ['expected' => $expectedArtifact, 'actual' => $actual]);
        }
        foreach (['admin/static/assets/admin-app.js', 'public/admin/assets/admin-app.js'] as $file) {
            if ($dist && !is_file($this->rootPath . '/' . $file)) {
                continue;
            }
            $content = @file_get_contents($this->rootPath . '/' . $file);
            preg_match("/const ADMIN_VERSION\s*=\s*['\"]([^'\"]+)['\"]/", is_string($content) ? substr($content, 0, 512) : '', $matches);
            $actual = $matches[1] ?? null;
            $checks[] = $actual === $version
                ? CheckResult::pass('version', $file, 'Static admin UI displays VERSION.')
                : CheckResult::fail('version', $file, 'Static admin UI displays a stale version.', ['expected' => $version, 'actual' => $actual]);
        }


        $phpRequirement = $composer['require']['php'] ?? null;
        $checks[] = $phpRequirement === '>=8.4.1 <9.0'
            ? CheckResult::pass('version', 'composer.php_requirement', 'composer.json требует PHP >=8.4.1 <9.0.')
            : CheckResult::fail('version', 'composer.php_requirement', 'composer.json должен требовать PHP >=8.4.1 <9.0.', ['actual' => $phpRequirement]);

        $platformPhp = $composer['config']['platform']['php'] ?? null;
        $checks[] = $platformPhp === '8.4.1'
            ? CheckResult::pass('version', 'composer.platform_php', 'Composer platform PHP синхронизирован с Symfony 8.1 baseline.')
            : CheckResult::fail('version', 'composer.platform_php', 'Composer platform PHP должен быть 8.4.1.', ['actual' => $platformPhp]);

        $checks[] = ($composer['license'] ?? null) === 'Apache-2.0'
            ? CheckResult::pass('version', 'composer.license', 'composer.json использует Apache-2.0.')
            : CheckResult::fail('version', 'composer.license', 'composer.json должен использовать Apache-2.0.', ['actual' => $composer['license'] ?? null]);

        $autoload = $composer['autoload']['psr-4']['CajeerEngine\\'] ?? null;
        $checks[] = $autoload === 'core/'
            ? CheckResult::pass('version', 'composer.autoload', 'PSR-4 autoload указывает на core/.')
            : CheckResult::fail('version', 'composer.autoload', 'PSR-4 autoload должен указывать на core/.', ['actual' => $autoload]);

        return $checks;
    }

    /** @return array<string, mixed> */
    private function json(string $file): array
    {
        $path = $this->rootPath . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }
}
