<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use Symfony\Component\Console\Command\Command;

abstract class BaseCommand extends Command
{
    public function __construct(protected readonly string $rootPath)
    {
        parent::__construct();
    }
}
