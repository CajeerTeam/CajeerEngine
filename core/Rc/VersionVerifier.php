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

        $checks[] = $version === '1.1.1'
            ? CheckResult::pass('version', 'VERSION', 'VERSION содержит 1.1.1.')
            : CheckResult::fail('version', 'VERSION', 'VERSION должен быть 1.1.1.', ['actual' => $version]);

        $expected = [
            'release.json' => $release['version'] ?? null,
            'packages/php-sdk/composer.json' => $phpSdk['version'] ?? null,
            'packages/ts-sdk/package.json' => $tsSdk['version'] ?? null,
            'admin/package.json' => $admin['version'] ?? null,
        ];
        foreach ($expected as $file => $actual) {
            $checks[] = $actual === $version
                ? CheckResult::pass('version', $file, $file . ' синхронизирован с VERSION.', ['version' => $actual])
                : CheckResult::fail('version', $file, $file . ' не синхронизирован с VERSION.', ['expected' => $version, 'actual' => $actual]);
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
