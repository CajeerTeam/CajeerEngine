<?php

declare(strict_types=1);

namespace Cajeer\Modules\Marketplace;

use RuntimeException;
use ZipArchive;

final class PackageInstaller
{
    public function __construct(private readonly string $basePath) {}

    public function inspect(string $zipFile): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Для установки пакетов требуется расширение zip.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Не удалось открыть пакет.');
        }
        $manifestRaw = $zip->getFromName('cajeer.json') ?: $zip->getFromName('extension.json');
        if (!$manifestRaw) {
            $zip->close();
            throw new RuntimeException('В пакете нет cajeer.json или extension.json.');
        }
        $manifest = json_decode($manifestRaw, true);
        $zip->close();
        if (!is_array($manifest)) {
            throw new RuntimeException('Manifest пакета некорректен.');
        }
        return ['manifest' => $manifest, 'errors' => (new PackageManifestValidator())->validate($manifest)];
    }
}
