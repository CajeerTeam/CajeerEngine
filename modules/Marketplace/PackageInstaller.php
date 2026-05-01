<?php

declare(strict_types=1);

namespace Cajeer\Modules\Marketplace;

use Cajeer\Database\DatabaseManager;
use RuntimeException;
use ZipArchive;

final class PackageInstaller
{
    private const FORBIDDEN_EXTENSIONS = ['php5', 'phtml', 'phar', 'cgi', 'pl', 'sh'];

    public function __construct(private readonly string $basePath, private readonly ?DatabaseManager $database = null) {}

    public function inspect(string $zipFile): array
    {
        $zip = $this->open($zipFile);
        $manifest = $this->manifest($zip);
        $files = $this->validateFiles($zip);
        $zip->close();
        return ['manifest' => $manifest, 'files' => $files, 'errors' => (new PackageManifestValidator())->validate($manifest)];
    }

    public function install(string $zipFile): array
    {
        $zip = $this->open($zipFile);
        $manifest = $this->manifest($zip);
        $errors = (new PackageManifestValidator())->validate($manifest);
        if ($errors !== []) throw new RuntimeException('Manifest пакета некорректен: ' . implode('; ', $errors));
        $this->validateFiles($zip);

        $name = (string) ($manifest['name'] ?? 'package');
        $type = (string) ($manifest['type'] ?? 'plugin');
        $version = (string) ($manifest['version'] ?? '0.0.0');
        $target = $this->targetPath($type, $name);
        $tmp = $this->basePath . '/storage/framework/package-' . bin2hex(random_bytes(6));
        if (!is_dir($tmp)) mkdir($tmp, 0775, true);

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = (string) ($stat['name'] ?? '');
                if ($entry === '' || str_ends_with($entry, '/')) continue;
                $dest = $tmp . '/' . $entry;
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0775, true);
                file_put_contents($dest, $zip->getFromIndex($i));
            }
            $zip->close();
            if (is_dir($target)) $this->rrmdir($target);
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
            rename($tmp, $target);
            $this->register($name, $type, $version, $manifest);
            return ['installed' => true, 'target' => $target, 'manifest' => $manifest];
        } catch (\Throwable $e) {
            if (is_dir($tmp)) $this->rrmdir($tmp);
            throw $e;
        }
    }

    private function open(string $zipFile): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Для установки пакетов требуется расширение zip.');
        if (!is_file($zipFile) || filesize($zipFile) > 50 * 1024 * 1024) throw new RuntimeException('Пакет не найден или слишком большой.');
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) throw new RuntimeException('Не удалось открыть пакет.');
        return $zip;
    }

    private function manifest(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('cajeer.json') ?: $zip->getFromName('extension.json');
        if (!$raw) throw new RuntimeException('В пакете нет cajeer.json или extension.json.');
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) throw new RuntimeException('Manifest пакета некорректен.');
        return $manifest;
    }

    private function validateFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) (($zip->statIndex($i)['name'] ?? ''));
            if ($entry === '') continue;
            if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_contains($entry, '://')) throw new RuntimeException('Path traversal в пакете: ' . $entry);
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) throw new RuntimeException('Запрещённое расширение файла в пакете: ' . $entry);
            $files[] = $entry;
        }
        return $files;
    }

    private function targetPath(string $type, string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $name) ?: 'package';
        return match ($type) {
            'theme' => $this->basePath . '/themes/' . $safe,
            'module' => $this->basePath . '/modules/' . $safe,
            default => $this->basePath . '/plugins/' . $safe,
        };
    }

    private function register(string $name, string $type, string $version, array $manifest): void
    {
        if (!$this->database) return;
        $pdo = $this->database->connection();
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $stmt = $pdo->prepare('INSERT INTO cajeer_extensions (package_name, type, version, status, manifest_json) VALUES (:name, :type, :version, :status, :manifest) ON CONFLICT (package_name) DO UPDATE SET version = EXCLUDED.version, manifest_json = EXCLUDED.manifest_json, updated_at = CURRENT_TIMESTAMP');
        } else {
            $stmt = $pdo->prepare('INSERT INTO cajeer_extensions (package_name, type, version, status, manifest_json) VALUES (:name, :type, :version, :status, :manifest) ON DUPLICATE KEY UPDATE version = VALUES(version), manifest_json = VALUES(manifest_json), updated_at = CURRENT_TIMESTAMP');
        }
        $stmt->execute(['name' => $name, 'type' => $type, 'version' => $version, 'status' => 'enabled', 'manifest' => $json]);
    }

    private function rrmdir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
