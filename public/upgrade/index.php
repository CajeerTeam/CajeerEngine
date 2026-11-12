<?php

declare(strict_types=1);
$root=dirname(__DIR__,2);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
if (!is_file($root.'/storage/app/installed.lock')) { http_response_code(403); echo 'CMS is not installed.'; exit; }
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CajeerEngine — обновление</title><link rel="stylesheet" href="/admin/assets/admin-app.css"><script src="/upgrade/upgrade.js" defer></script></head>
<body><main class="card"><h1>Безопасное обновление CajeerEngine</h1><p>Войдите как администратор через <a href="/admin/">Admin UI</a>. Подготовка подписанного dist выполняется оператором на сервере:</p><pre>php bin/cajeer update:stage --package=/path/release-dist.zip</pre><p>Проверьте версию и SHA256, подтвердите запрос. Оператор запускает выбранный запрос отдельным CLI-процессом. Веб-процесс не изменяет PHP-код.</p><p><button id="refresh" type="button">Обновить статус</button></p><pre id="status" aria-live="polite">Загрузка...</pre><button id="apply" type="button" disabled>Запросить обновление</button> <button id="rollback" type="button" disabled>Запросить rollback</button><p id="message" aria-live="polite"></p><p>При прерванном обновлении: <code>php bin/cajeer update:status</code> и <code>php bin/cajeer update:rollback</code>. Не отключайте maintenance до восстановления.</p></main></body></html>
