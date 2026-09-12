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
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Template\CajeerTemplateEngine;
use CajeerEngine\Template\TemplateSandbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cms:sitemap', description: 'Показать sitemap CMS или XML sitemap для публичного сайта.')]
final class CmsSitemapCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('xml', null, InputOption::VALUE_NONE, 'Вывести XML sitemap.');
        $this->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Базовый URL сайта.', 'http://localhost');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $renderer = $this->renderer();
        if ((bool) $input->getOption('xml')) {
            $output->writeln($renderer->sitemapXml((string) $input->getOption('base-url')));
            return self::SUCCESS;
        }

        $items = $renderer->sitemap();
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $items, 'meta' => ['total' => count($items)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $output->writeln((string) $item['path'] . '  ' . (string) $item['title']);
        }
        $output->writeln('[OK] Всего URL: ' . count($items));
        return self::SUCCESS;
    }

    private function renderer(): PublicPageRenderer
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $storage = new StorageManager($this->rootPath, $config);
        $templatesRoot = $this->rootPath . '/templates';
        return new PublicPageRenderer(
            $this->rootPath,
            new ContentTypeRepository($this->rootPath, $database),
            new ContentEntryRepository($this->rootPath, $database),
            new CajeerTemplateEngine(new TemplateSandbox($templatesRoot), $templatesRoot, $this->rootPath . '/storage/cache/templates'),
            new MediaRepository($this->rootPath, $storage),
            new ThemeConfigRepository($this->rootPath, $database),
            new NavigationRepository($this->rootPath, $database),
        );
    }
}
