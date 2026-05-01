<?php

declare(strict_types=1);

use Cajeer\Kernel\Application;
use Cajeer\Http\Request;

require_once dirname(__DIR__) . '/core/bootstrap.php';

$app = Application::create(dirname(__DIR__));
$response = $app->handle(Request::fromGlobals());
$response->send();
