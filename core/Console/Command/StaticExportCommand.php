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
        $result=(new \CajeerEngine\StaticExport\SiteExport($this->rootPath))->run();
        $output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
