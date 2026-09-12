<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Cms\NavigationRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Public\PublicPageRenderer;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\StaticExport\StaticExporter;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Template\CajeerTemplateEngine;
use CajeerEngine\Template\TemplateSandbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'static:export', description: 'Собрать статический экспорт опубликованного контента через .cjr-шаблоны.')]
final class StaticExportCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $this->rootPath . '/storage/static-export';
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            $output->writeln('[FAIL] Не удалось создать storage/static-export');
            return self::FAILURE;
        }

        $config = new ConfigRepository($this->rootPath);
        $storage = new StorageManager($this->rootPath, $config);
        $database = new DatabaseManager($config);
        $types = new ContentTypeRepository($this->rootPath, $database);
        $entries = new ContentEntryRepository($this->rootPath, $database);
        $media = new MediaRepository($this->rootPath, $storage);
        $templatesRoot = $this->rootPath . '/templates';
        $templateEngine = new CajeerTemplateEngine(new TemplateSandbox($templatesRoot), $templatesRoot, $this->rootPath . '/storage/cache/templates');
        $renderer = new PublicPageRenderer($this->rootPath, $types, $entries, $templateEngine, $media, new ThemeConfigRepository($this->rootPath, $database), new NavigationRepository($this->rootPath, $database));
        $exporter = new StaticExporter($target);

        $pages = [];
        $manifest = [];
        foreach ($renderer->sitemap() as $item) {
            $page = $renderer->renderByTypeAndId((string) $item['type'], (string) $item['id']);
            if ($page === null) {
                continue;
            }
            $path = trim((string) ($item['path'] ?? $item['slug'] ?? ''), '/');
            $pages[] = ['path' => $path . '/index.html', 'html' => $page['html']];
            $manifest[] = [
                'type' => $item['type'],
                'id' => $item['id'],
                'slug' => $item['slug'],
                'path' => '/' . $path . '/',
                'file' => $path . '/index.html',
                'updated_at' => $item['updated_at'],
            ];
        }

        $pages[] = ['path' => 'index.html', 'html' => $this->indexHtml($manifest)];
        $count = $exporter->export($pages);
        $this->copyUploads($storage->publicUploadsRoot(), $target . '/uploads');

        file_put_contents($target . '/manifest.json', json_encode([
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'version' => trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: '1.1.1',
            'entries' => $manifest,
            'media' => $media->diagnostics(),
            'pages_written' => $count,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        $output->writeln('[OK] Static export: storage/static-export');
        $output->writeln('[OK] Экспортировано опубликованных страниц: ' . count($manifest));
        $output->writeln('[OK] Записано HTML-файлов: ' . $count);
        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $exported */
    private function indexHtml(array $exported): string
    {
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>CajeerEngine export</title></head><body><h1>CajeerEngine static export</h1><ul>';
        foreach ($exported as $item) {
            $file = htmlspecialchars((string) $item['file'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) $item['type'] . '/' . (string) $item['slug'], ENT_QUOTES, 'UTF-8');
            $html .= '<li><a href="' . $file . '">' . $label . '</a></li>';
        }
        return $html . '</ul></body></html>';
    }

    private function copyUploads(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($source, '/')) + 1);
            $destination = rtrim($target, '/') . '/' . $relative;
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0775, true);
            }
            copy($file->getPathname(), $destination);
        }
    }
}
