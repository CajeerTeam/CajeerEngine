<?php

use Cajeer\Modules\Content\Http\ContentController;
use Cajeer\Modules\Installer\Http\InstallController;
use Cajeer\Modules\Marketplace\Http\MarketplaceController;

$router->get('/install', [InstallController::class, 'index']);
$router->post('/install', [InstallController::class, 'store']);

$router->get('/', [ContentController::class, 'home']);
$router->get('/page/{slug}', [ContentController::class, 'page']);
$router->get('/news/{slug}', [ContentController::class, 'news']);

$router->get('/marketplace', [MarketplaceController::class, 'index']);
$router->get('/marketplace/themes', [MarketplaceController::class, 'themes']);
$router->get('/marketplace/plugins', [MarketplaceController::class, 'plugins']);
