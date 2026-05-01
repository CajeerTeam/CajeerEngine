<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Cajeer\Engine\Http\Kernel;

Kernel::fromProjectRoot(dirname(__DIR__))->handle();
