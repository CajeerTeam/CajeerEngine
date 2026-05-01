<?php

use Cajeer\Modules\Admin\Http\AdminController;

$router->get('/admin', [AdminController::class, 'dashboard']);
$router->get('/admin/compatibility', [AdminController::class, 'compatibility']);
$router->get('/admin/marketplace', static fn () => Cajeer\Http\Response::html('<h1>Admin Marketplace</h1><p>Заготовка административной установки пакетов.</p>'));
$router->get('/admin/content', static fn () => Cajeer\Http\Response::html('<h1>Контент</h1><p>Заготовка CRUD контента.</p>'));
