<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Rc\ReleaseCandidateAuditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rc:doctor', description: 'Проверить готовность CajeerEngine к release candidate.')] 
final class RcDoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести полный JSON-отчёт.');
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Возвращать ошибку при warning.');
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Сохранить JSON-отчёт в файл.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ReleaseCandidateAuditor($this->rootPath))->run();
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $target = $input->getOption('output');
        if (is_string($target) && $target !== '') {
            $path = str_starts_with($target, '/') ? $target : $this->rootPath . '/' . $target;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $json . PHP_EOL, LOCK_EX);
            $output->writeln('[OK] RC report saved: ' . $path);
        }
        if ((bool) $input->getOption('json')) {
            $output->writeln($json);
        } else {
            $summary = $result['summary'];
            $output->writeln('RC readiness: ' . ($result['ready'] ? 'ready' : 'not ready'));
            $output->writeln('Checks: ' . $summary['total'] . ' total, ' . $summary['pass'] . ' pass, ' . $summary['warn'] . ' warn, ' . $summary['fail'] . ' fail');
            foreach ($summary['groups'] as $group => $data) {
                $output->writeln(sprintf('- %s: %d pass / %d warn / %d fail', $group, $data['pass'], $data['warn'], $data['fail']));
            }
        }

        if (($result['summary']['fail'] ?? 0) > 0) {
            return self::FAILURE;
        }
        if ((bool) $input->getOption('strict') && ($result['summary']['warn'] ?? 0) > 0) {
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
