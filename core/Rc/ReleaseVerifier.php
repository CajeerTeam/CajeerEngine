<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

use CajeerEngine\Release\ReleaseBuilder;

final readonly class ReleaseVerifier
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function verify(): array
    {
        $checks = [];
        $builder = new ReleaseBuilder($this->rootPath);
        try {
            $sourcePlan = $builder->build(true, 'source');
            $distPlan = $builder->plan('dist');
            $files = $sourcePlan['files'] ?? [];
            $checks[] = is_array($files) && count($files) > 30
                ? CheckResult::pass('release', 'source.files', 'Source release plan содержит файлы.', ['count' => count($files)])
                : CheckResult::fail('release', 'source.files', 'Source release plan содержит недостаточно файлов.', ['count' => is_array($files) ? count($files) : 0]);

            foreach (['.git/', 'vendor/', 'node_modules/', 'storage/logs/', 'storage/cache/', 'storage/tmp/', 'storage/app/backups/', 'storage/app/updates/downloads/', '.env'] as $forbidden) {
                $found = false;
                if (is_array($files)) {
                    foreach ($files as $file) {
                        $file = (string) $file;
                        if (str_ends_with($file, '/.gitkeep')) { continue; }
                        if ($forbidden === '.env' ? ($file === '.env') : ($file === rtrim($forbidden, '/') || str_starts_with($file, $forbidden))) {
                            $found = true;
                            break;
                        }
                    }
                }
                $checks[] = $found
                    ? CheckResult::fail('release', 'source.exclude.' . trim($forbidden, '/.'), 'Source release содержит запрещённый путь: ' . $forbidden)
                    : CheckResult::pass('release', 'source.exclude.' . trim($forbidden, '/.'), 'Запрещённый путь исключён из source: ' . $forbidden);
            }

            foreach (['public/index.php', 'public/install/index.php', 'public/upgrade/index.php', 'upgrade.php', 'bin/cajeer', 'api/openapi.yaml', 'composer.json', 'VERSION', 'release.json', 'resources/updates/releases.json', 'core/Update/UpdateManager.php'] as $required) {
                $checks[] = is_array($files) && in_array($required, $files, true)
                    ? CheckResult::pass('release', 'source.include.' . $required, 'Source release включает ' . $required)
                    : CheckResult::fail('release', 'source.include.' . $required, 'Source release не включает ' . $required);
            }

            $distReq = is_array($distPlan['dist_requirements'] ?? null) ? $distPlan['dist_requirements'] : [];
            $checks[] = ($distPlan['mode'] ?? null) === 'dist'
                ? CheckResult::pass('release', 'dist.mode', 'Dist release mode реализован.', ['requirements' => $distReq])
                : CheckResult::fail('release', 'dist.mode', 'Dist release mode отсутствует.');

            foreach (['vendor_autoload', 'composer_lock', 'admin_prebuilt_assets', 'env_example', 'cli'] as $requirement) {
                $ok = (bool) ($distReq[$requirement] ?? false);
                $checks[] = $ok
                    ? CheckResult::pass('release', 'dist.requirement.' . $requirement, 'Dist requirement выполнен: ' . $requirement)
                    : CheckResult::warn('release', 'dist.requirement.' . $requirement, 'Dist requirement не выполнен в текущем source sandbox: ' . $requirement);
            }

            $checks[] = str_contains((string) ($sourcePlan['target'] ?? ''), '-source.zip')
                ? CheckResult::pass('release', 'artifact.source_name', 'Source artifact naming корректный.')
                : CheckResult::fail('release', 'artifact.source_name', 'Source artifact naming некорректный.');
            $checks[] = str_contains((string) ($distPlan['target'] ?? ''), '-dist.zip')
                ? CheckResult::pass('release', 'artifact.dist_name', 'Dist artifact naming корректный.')
                : CheckResult::fail('release', 'artifact.dist_name', 'Dist artifact naming некорректный.');
            $checks[] = str_ends_with((string) ($sourcePlan['checksum_target'] ?? ''), '.zip.sha256')
                ? CheckResult::pass('release', 'artifact.sha256', 'Checksum artifact path формируется.')
                : CheckResult::fail('release', 'artifact.sha256', 'Checksum artifact path не формируется.');
        } catch (\Throwable $e) {
            $checks[] = CheckResult::fail('release', 'plan.exception', 'ReleaseBuilder завершился ошибкой.', ['error' => $e->getMessage()]);
        }

        foreach ([
            'tools/build-release.php' => 'Release build script присутствует.',
            'public/upgrade/index.php' => 'Upgrade Wizard присутствует.',
            'core/Update/UpdateManager.php' => 'UpdateManager присутствует.',
            'core/Console/Command/UpdateCommand.php' => 'CLI update command присутствует.',
            'core/Console/Command/ReleaseArtifactsCommand.php' => 'CLI release artifacts command присутствует.',
        ] as $file => $message) {
            $checks[] = is_file($this->rootPath . '/' . $file)
                ? CheckResult::pass('release', 'file.' . str_replace('/', '.', $file), $message)
                : CheckResult::fail('release', 'file.' . str_replace('/', '.', $file), $message . ' Файл отсутствует.');
        }

        return $checks;
    }
}
