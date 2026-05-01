<?php

use Cajeer\Modules\Content\Http\ContentController;
use Cajeer\Modules\Marketplace\Http\MarketplaceController;

$router->get('/', [ContentController::class, 'home']);
$router->get('/page/{slug}', [ContentController::class, 'page']);
$router->get('/news/{slug}', [ContentController::class, 'news']);

$router->get('/marketplace', [MarketplaceController::class, 'index']);
$router->get('/marketplace/themes', [MarketplaceController::class, 'themes']);
$router->get('/marketplace/plugins', [MarketplaceController::class, 'plugins']);
