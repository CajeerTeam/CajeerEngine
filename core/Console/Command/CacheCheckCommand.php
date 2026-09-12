<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:check', description: 'Проверить файловый cache runtime и доступность Redis extension.')]
final class CacheCheckCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->rootPath . '/storage/cache';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $output->writeln('[FAIL] Не удалось создать storage/cache');
            return self::FAILURE;
        }

        $probe = $dir . '/cache-check-' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($probe, 'ok');
        $ok = is_file($probe) && trim((string) file_get_contents($probe)) === 'ok';
        @unlink($probe);

        $output->writeln(($ok ? '[OK]' : '[FAIL]') . ' file cache read/write');
        $output->writeln((extension_loaded('redis') ? '[OK]' : '[WARN]') . ' redis extension ' . (extension_loaded('redis') ? 'loaded' : 'not loaded'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
