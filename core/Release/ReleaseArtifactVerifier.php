<?php

declare(strict_types=1);
namespace CajeerEngine\Release;

/** Validates the ZIP itself, not the working tree or an untrusted sidecar. */
final class ReleaseArtifactVerifier
{
    public function verify(string $path, string $mode, string $version): array
    {
        $path = realpath($path) ?: throw new \RuntimeException('Artifact does not exist');
        $zip = new \PharData($path);
        $prefix = 'cajeerengine/';
        $manifestName = $prefix . 'release-manifest.json';
        if (!isset($zip[$manifestName]) || $zip[$manifestName]->getSize() > 16 * 1024 * 1024) throw new \RuntimeException('Missing or oversized release manifest');
        $manifest = json_decode($zip[$manifestName]->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? '') !== 'cajeerengine.release-manifest.v5' || ($manifest['project'] ?? '') !== 'cajeerengine' || ($manifest['mode'] ?? '') !== $mode || ($manifest['version'] ?? '') !== $version) throw new \RuntimeException('Invalid release manifest identity');
        $files = $manifest['files'] ?? null;
        $checksums = $manifest['checksums'] ?? null;
        if (!is_array($files) || !array_is_list($files) || !is_array($checksums) || count($files) !== count($checksums) || count($files) !== count(array_unique($files))) throw new \RuntimeException('Invalid release inventory');
        $actual = [];
        // ZipArchive exposes the raw central directory, including duplicate names.
        if (class_exists(\ZipArchive::class)) {
            $raw = new \ZipArchive();
            if ($raw->open($path, \ZipArchive::CHECKCONS) !== true) throw new \RuntimeException('Invalid ZIP');
            try {
                for ($i = 0; $i < $raw->numFiles; $i++) {
                    $name = $raw->getNameIndex($i);
                    if (!is_string($name) || isset($actual[$name])) throw new \RuntimeException('Duplicate ZIP entry');
                    $actual[$name] = true;
                    if ($raw->getExternalAttributesIndex($i, $os, $attr) && $os === 3 && !in_array((($attr >> 16) & 0170000),[0,0100000],true)) throw new \RuntimeException('ZIP nonregular entry');
                }
            } finally { $raw->close(); }
        } else {
            // PharData can hide duplicate names; use the raw ZIP listing when ext-zip is unavailable.
            $process = proc_open(['unzip', '-Z1', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new \RuntimeException('ZIP verification requires ext-zip or unzip');
            $list = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new \RuntimeException('Invalid ZIP: ' . $error);
            foreach (explode("\n", rtrim($list, "\n")) as $name) {
                if (isset($actual[$name])) throw new \RuntimeException('Duplicate ZIP entry');
                $actual[$name] = true;
            }
            $process=proc_open(['unzip','-Z','-l',$path],[1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
            if (!is_resource($process)) throw new \RuntimeException('Cannot inspect ZIP attributes');
            $attributes=stream_get_contents($pipes[1]); fclose($pipes[1]);
            if (proc_close($process)!==0||preg_match('/^[dlbcps][rwxStTs-]{9}\s/m',$attributes)) throw new \RuntimeException('ZIP nonregular entry');
        }
        unset($actual[$manifestName]);
        $total = 0;
        foreach ($files as $file) {
            if (!is_string($file) || !ReleaseFilePolicy::allows($file, $mode) || !isset($actual[$prefix . $file])) throw new \RuntimeException('Forbidden or missing release file: ' . (string) $file);
            $expected = $checksums[$file] ?? null;
            $entry = $zip[$prefix . $file];
            $size = $entry->getSize();
            $total += $size;
            if ($size > 256 * 1024 * 1024 || $total > 2 * 1024 * 1024 * 1024) throw new \RuntimeException('Release exceeds safe extraction size');
            $digest = hash_file('sha256', 'phar://' . $path . '/' . $prefix . $file);
            if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected) || !is_string($digest) || !hash_equals($expected, $digest)) throw new \RuntimeException('Checksum mismatch: ' . $file);
            if (in_array($file, ReleaseFilePolicy::KEEP, true) && $entry->getSize() !== 0) throw new \RuntimeException('Nonempty runtime marker');
            unset($actual[$prefix . $file]);
        }
        if ($actual !== []) throw new \RuntimeException('Unlisted ZIP entries: ' . implode(', ', array_keys($actual)));
        $required = array_merge(['VERSION', 'release.json', 'composer.json', '.env.example', 'bin/cajeer', 'public/index.php', 'public/install/index.php', 'public/upgrade/index.php', 'public/upgrade/upgrade.js', 'tools/update-postcheck.php', 'tools/update-recovery.php', 'core/Backup/BackupManager.php', 'core/Update/UpdateManager.php', 'public/admin/index.html', 'public/admin/assets/admin-app.js', 'public/admin/assets/admin-app.css', 'public/admin/assets/admin-manifest.json'], ReleaseFilePolicy::KEEP);
        if (version_compare($version, '1.3.2', '>=')) $required = array_merge($required, ['resources/consent/consent.js','resources/consent/consent.css']);
        if (version_compare($version, '1.3.3', '>=')) $required = array_merge($required, ['core/Privacy/TrackingConfig.php','core/Api/Controller/TrackingController.php']);
        if (version_compare($version, '1.3.4', '>=')) $required = array_merge($required, ['core/Analytics/EventConfig.php','core/Analytics/EventJournal.php','core/Analytics/ServerEvents.php','core/Api/Controller/AnalyticsEventController.php']);
        if (version_compare($version, '1.3.5', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/AnalyticsHub.php','core/Analytics/Reporting/ApiReports.php','core/Analytics/Reporting/ReportProvider.php','core/Api/Controller/AnalyticsHubController.php','migrations/sqlite/0015_analytics_hub.sql','migrations/pgsql/0015_analytics_hub.sql','migrations/mysql/0015_analytics_hub.sql']);
        if (version_compare($version, '1.3.6', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/SearchReports.php','migrations/sqlite/0016_search_webmaster.sql','migrations/pgsql/0016_search_webmaster.sql','migrations/mysql/0016_search_webmaster.sql']);
        if (version_compare($version, '1.3.7', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/AlternativeReports.php']);
        if (version_compare($version, '1.3.9', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/CloudflareReports.php','resources/consent/consent.js']);
        if (version_compare($version, '1.4.0', '>=')) $required = array_merge($required, ['core/Analytics/Reporting/ReportComparison.php','migrations/sqlite/0017_analytics_sync_runs.sql','migrations/pgsql/0017_analytics_sync_runs.sql','migrations/mysql/0017_analytics_sync_runs.sql']);
        if (version_compare($version, '1.4.1', '>=')) $required = array_merge($required, ['core/Migration/MigrationCenter.php','core/Migration/SourceDiscovery.php','core/Migration/WxrDiscovery.php','core/Api/Controller/MigrationController.php','docs/migration-center.md']);
        if (version_compare($version, '1.4.2', '>=')) $required = array_merge($required, ['core/Migration/PlanningSource.php','core/Migration/MigrationPlanner.php','docs/migration-planning.md']);
        if (version_compare($version, '1.4.3', '>=')) $required = array_merge($required, ['core/Migration/DleImporter.php','core/Migration/DleContent.php','core/Migration/DleCli.php','core/Migration/MigrationFactory.php','docs/dle-migration.md','migrations/sqlite/0018_dle_migration.sql','migrations/pgsql/0018_dle_migration.sql','migrations/mysql/0018_dle_migration.sql']);
        if (version_compare($version, '1.4.4', '>=')) $required = array_merge($required, ['core/Migration/ContentMigrationImporter.php','core/Migration/WordPressContent.php','docs/wordpress-migration.md','migrations/sqlite/0019_wordpress_migration.sql','migrations/pgsql/0019_wordpress_migration.sql','migrations/mysql/0019_wordpress_migration.sql']);
        if (version_compare($version, '1.4.5', '>=')) $required = array_merge($required, ['core/Migration/IdentityMigration.php','docs/identity-migration.md','migrations/sqlite/0020_migration_identities.sql','migrations/pgsql/0020_migration_identities.sql','migrations/mysql/0020_migration_identities.sql']);
        if (version_compare($version, '1.4.6', '>=')) $required = array_merge($required, ['core/Migration/MediaMigration.php','core/Migration/MediaCli.php','core/Migration/WordPressMediaMetadata.php','docs/media-attachments-migration.md','migrations/sqlite/0021_migration_media.sql','migrations/pgsql/0021_migration_media.sql','migrations/mysql/0021_migration_media.sql']);
        if (version_compare($version, '1.4.7', '>=')) $required = array_merge($required, ['core/Migration/SeoMigration.php','core/Migration/SeoCli.php','core/Migration/SeoMetadata.php','docs/seo-url-migration.md','migrations/sqlite/0022_seo_url_migration.sql','migrations/pgsql/0022_seo_url_migration.sql','migrations/mysql/0022_seo_url_migration.sql']);
        if (version_compare($version, '1.4.8', '>=')) $required = array_merge($required, ['core/Migration/MigrationExecution.php','core/Migration/MigrationExecutionCli.php','docs/migration-execution-recovery.md','migrations/sqlite/0023_migration_execution.sql','migrations/pgsql/0023_migration_execution.sql','migrations/mysql/0023_migration_execution.sql']);
        if (version_compare($version, '1.4.9', '>=')) $required = array_merge($required, ['core/Migration/MigrationCutover.php','core/Migration/MigrationCutoverCli.php','docs/migration-validation-cutover.md','migrations/sqlite/0024_migration_validation_cutover.sql','migrations/pgsql/0024_migration_validation_cutover.sql','migrations/mysql/0024_migration_validation_cutover.sql']);
        if (version_compare($version, '1.5.0', '>=')) $required = array_merge($required, ['core/Migration/MigrationIntegrity.php','docs/migration-ga.md','migrations/sqlite/0025_migration_redirect_evidence.sql','migrations/pgsql/0025_migration_redirect_evidence.sql','migrations/mysql/0025_migration_redirect_evidence.sql']);
        if (version_compare($version, '1.5.3', '>=')) $required = array_merge($required, ['core/Database/Repository/UserSessionRepository.php','docs/unified-session-management.md','migrations/sqlite/0028_unified_sessions.sql','migrations/pgsql/0028_unified_sessions.sql','migrations/mysql/0028_unified_sessions.sql']);
        if (version_compare($version, '1.5.4', '>=')) $required = array_merge($required, ['core/Identity/OidcClient.php','core/Identity/OidcLogin.php','docs/skif4er-sso-global-logout.md','migrations/sqlite/0029_oidc_global_logout.sql','migrations/pgsql/0029_oidc_global_logout.sql','migrations/mysql/0029_oidc_global_logout.sql']);
        if (version_compare($version, '1.5.5', '>=')) $required = array_merge($required, ['core/Identity/OidcApiAuthenticator.php','docs/oidc-api-authentication.md']);
        if ($mode === 'source' && version_compare($version, '2.0.0', '>=')) {
            $required = array_merge($required, ['tools/production-release-regression.php', 'docs/production-release-2.0.0.md']);
        }
        if ($mode === 'source' && version_compare($version, '1.9.9', '>=')) {
            $required = array_merge($required, ['tools/ga-qualification-regression.php', 'docs/production-ga-qualification-1.9.9.md']);
        }
        if ($mode === 'source' && version_compare($version, '1.9.8', '>=')) {
            $required = array_merge($required, ['tools/database-recovery-integrity-regression.php', 'tools/e2e-acceptance.sh', 'docs/full-platform-e2e-recovery-1.9.8.md']);
        }
        if ($mode === 'dist') $required = array_merge($required, ['composer.lock'], ReleaseDependencyInventory::AUTOLOAD_FILES);
        elseif (version_compare($version,'1.2.5','>=')) $required = array_merge($required, ['deploy/production.py','deploy/install.php','deploy/verify.php','deploy/host-smoke.sh','deploy/test_production.py','deploy/site.example.json','docs/production-deployment.md']);
        if ($mode === 'source' && version_compare($version, '1.3.0', '>=')) $required = array_merge($required, ['tools/production-acceptance.py','tools/certification-evidence.py','tools/certification-regression.py','tools/rate-limit-concurrency.php','docs/production-certification.md']);
        foreach ($required as $file) if (!in_array($file, $files, true)) throw new \RuntimeException('Required ZIP entry missing: ' . $file);
        foreach (['pgsql', 'mysql', 'sqlite'] as $driver) {
            if (!array_filter($files, static fn ($file) => str_starts_with($file, 'migrations/' . $driver . '/') && str_ends_with($file, '.sql'))) throw new \RuntimeException('Missing migrations: ' . $driver);
        }
        if ($mode === 'dist') {
            $packages = ReleaseDependencyInventory::validate($zip[$prefix . 'composer.lock']->getContent(), $zip[$prefix . 'vendor/composer/installed.json']->getContent());
            foreach ($packages as $package) if (!isset($checksums['vendor/' . $package . '/composer.json'])) throw new \RuntimeException('Missing locked package: ' . $package);
        }
        if (trim($zip[$prefix . 'VERSION']->getContent()) !== $version) throw new \RuntimeException('Archive VERSION mismatch');
        $release = json_decode($zip[$prefix . 'release.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $expectedChannel = str_contains($version, '-rc') ? 'rc'
            : (str_contains($version, '-beta') ? 'beta'
            : (str_contains($version, '-') ? 'dev' : 'stable'));
        if (($release['version'] ?? null) !== $version || ($release['channel'] ?? null) !== $expectedChannel || ($release['stability'] ?? null) !== $expectedChannel) {
            throw new \RuntimeException('Embedded release metadata/version/channel mismatch');
        }
        if (!in_array('api/openapi.yaml', $files, true)) throw new \RuntimeException('Missing API contract in release');
        $openApi = json_decode($zip[$prefix . 'api/openapi.yaml']->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (($openApi['info']['version'] ?? null) !== $version) throw new \RuntimeException('Embedded OpenAPI version mismatch');
        $admin = json_decode($zip[$prefix . 'public/admin/assets/admin-manifest.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (($admin['ready'] ?? false) !== true || ($admin['version'] ?? '') !== $version) throw new \RuntimeException('Invalid admin manifest');
        foreach (['index.html', 'assets/admin-app.js', 'assets/admin-app.css', 'assets/admin-preview.js', 'assets/admin-preview.css'] as $asset) {
            $hash = $checksums['public/admin/' . $asset] ?? null;
            if ($hash === null || ($admin['files'][$asset]['sha256'] ?? null) !== $hash) throw new \RuntimeException('Admin asset mismatch: ' . $asset);
        }
        return $manifest;
    }
}
