<?php

use Cajeer\Modules\Admin\Http\AdminController;
use Cajeer\Modules\Auth\Http\AuthController;
use Cajeer\Modules\Content\Http\AdminContentController;
use Cajeer\Modules\Marketplace\Http\AdminMarketplaceController;
use Cajeer\Modules\Themes\Http\AdminThemeController;

$router->get('/admin/login', [AuthController::class, 'login']);
$router->post('/admin/login', [AuthController::class, 'authenticate']);
$router->get('/admin/logout', [AuthController::class, 'logout']);

$router->get('/admin', [AdminController::class, 'dashboard']);
$router->get('/admin/health', [AdminController::class, 'health']);
$router->get('/admin/compatibility', [AdminController::class, 'compatibility']);
$router->get('/admin/marketplace', [AdminMarketplaceController::class, 'index']);
$router->post('/admin/marketplace/install', [AdminMarketplaceController::class, 'install']);

$router->get('/admin/content', [AdminContentController::class, 'index']);
$router->get('/admin/content/create', [AdminContentController::class, 'create']);
$router->post('/admin/content', [AdminContentController::class, 'store']);
$router->get('/admin/content/{id}/edit', [AdminContentController::class, 'edit']);
$router->post('/admin/content/{id}', [AdminContentController::class, 'update']);
$router->post('/admin/content/{id}/delete', [AdminContentController::class, 'delete']);

$router->get('/admin/themes', [AdminThemeController::class, 'index']);
$router->get('/admin/themes/{name}/preview', [AdminThemeController::class, 'preview']);
$router->post('/admin/themes/{name}/activate', [AdminThemeController::class, 'activate']);
