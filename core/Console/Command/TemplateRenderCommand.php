<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Template\CajeerTemplateEngine;
use CajeerEngine\Template\TemplateSandbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'template:render', description: 'Рендерит .cjr-шаблон через Cajeer Template Engine.')]
final class TemplateRenderCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('template:render')
            ->setDescription('Рендерит .cjr-шаблон через Cajeer Template Engine.')
            ->addArgument('template', InputArgument::REQUIRED, 'Имя шаблона, например default.page')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Заголовок', 'CajeerEngine')
            ->addOption('content', null, InputOption::VALUE_REQUIRED, 'HTML-контент', '<p>Template render OK</p>');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $templatesRoot = $this->rootPath . '/templates';
        $engine = new CajeerTemplateEngine(new TemplateSandbox($templatesRoot), $templatesRoot, $this->rootPath . '/storage/cache/templates');
        $html = $engine->renderName((string) $input->getArgument('template'), [
            'title' => (string) $input->getOption('title'),
            'content' => (string) $input->getOption('content'),
            'summary' => '',
        ]);
        $output->write($html);

        return self::SUCCESS;
    }
}
