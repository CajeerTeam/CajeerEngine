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
        $admin = json_decode($zip[$prefix . 'public/admin/assets/admin-manifest.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (($admin['ready'] ?? false) !== true || ($admin['version'] ?? '') !== $version) throw new \RuntimeException('Invalid admin manifest');
        foreach (['index.html', 'assets/admin-app.js', 'assets/admin-app.css', 'assets/admin-preview.js', 'assets/admin-preview.css'] as $asset) {
            $hash = $checksums['public/admin/' . $asset] ?? null;
            if ($hash === null || ($admin['files'][$asset]['sha256'] ?? null) !== $hash) throw new \RuntimeException('Admin asset mismatch: ' . $asset);
        }
        return $manifest;
    }
}
