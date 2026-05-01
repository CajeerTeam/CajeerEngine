<?php

use Cajeer\Modules\Content\Http\ContentController;
use Cajeer\Modules\Content\Http\ThemePageController;
use Cajeer\Modules\Installer\Http\InstallController;
use Cajeer\Modules\Marketplace\Http\MarketplaceController;

$router->get('/install', [InstallController::class, 'index']);
$router->post('/install', [InstallController::class, 'store']);

$router->get('/', [ThemePageController::class, 'home']);
$router->get('/page/{slug}', [ContentController::class, 'page']);
$router->get('/news/{slug}', [ContentController::class, 'news']);

$router->get('/marketplace', [MarketplaceController::class, 'index']);
$router->get('/marketplace/themes', [MarketplaceController::class, 'themes']);
$router->get('/marketplace/plugins', [MarketplaceController::class, 'plugins']);


$router->get('/about', [ThemePageController::class, 'about']);
$router->get('/team', [ThemePageController::class, 'team']);
$router->get('/projects', [ThemePageController::class, 'projects']);
$router->get('/projects/{slug}', [ThemePageController::class, 'project']);
$router->get('/support', [ThemePageController::class, 'support']);
$router->get('/brand', [ThemePageController::class, 'brand']);
$router->get('/sitemap.xml', [ThemePageController::class, 'sitemap']);
$router->get('/robots.txt', [ThemePageController::class, 'robots']);
$router->get('/manifest.webmanifest', [ThemePageController::class, 'manifest']);
$router->get('/{slug}', [ThemePageController::class, 'page']);
