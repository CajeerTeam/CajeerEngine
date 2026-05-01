<?php

use Cajeer\Http\Response;

$router->get('/index.php', static fn () => Response::html('<h1>Legacy entry</h1><p>Маршрут подготовлен для DLE/WordPress legacy URL mapping.</p>'));
$router->get('/category/{slug}', static fn ($request) => Response::html('<h1>Категория</h1><p>Legacy category route: ' . htmlspecialchars((string) $request->input('slug'), ENT_QUOTES, 'UTF-8') . '</p>'));
