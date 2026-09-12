<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$lock = $root . '/storage/app/installed.lock';

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use CajeerEngine\Installer\InstallerService;

function ce_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ce_bool(mixed $value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function ce_status_class(bool $ok): string
{
    return $ok ? 'ok' : 'fail';
}

function ce_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (is_file($lock)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>CajeerEngine installed</title><body style="font-family:system-ui;margin:40px"><h1>Installer отключён</h1><p>CajeerEngine уже установлен. Найден <code>storage/app/installed.lock</code>.</p></body>';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['ce_install_csrf'])) {
    $_SESSION['ce_install_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['ce_install_csrf'];
$service = new InstallerService($root);
$messages = [];
$error = null;
$result = null;

$selectedDriver = (string) ($_POST['db_driver'] ?? $_GET['db'] ?? 'sqlite');
$requirements = $service->requirements($selectedDriver);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('CSRF token устарел. Обновите страницу и повторите установку.');
        }

        $driver = (string) ($_POST['db_driver'] ?? 'sqlite');
        $options = [
            'source' => 'web-installer',
            'preset' => $driver === 'sqlite' ? 'sqlite' : ((ce_bool($_POST['production'] ?? false) && $driver === 'pgsql') ? 'production' : $driver),
            'db' => $driver,
            'domain' => trim((string) ($_POST['domain'] ?? '')),
            'db_host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
            'db_port' => trim((string) ($_POST['db_port'] ?? '')),
            'db_name' => trim((string) ($_POST['db_name'] ?? '')),
            'db_user' => trim((string) ($_POST['db_user'] ?? '')),
            'db_password' => (string) ($_POST['db_password'] ?? ''),
            'admin_name' => trim((string) ($_POST['admin_name'] ?? 'Administrator')),
            'admin_email' => trim((string) ($_POST['admin_email'] ?? '')),
            'admin_password' => (string) ($_POST['admin_password'] ?? ''),
            'production' => ce_bool($_POST['production'] ?? false),
            'create_env' => true,
            'run_migrations' => true,
            'create_admin' => true,
            'write_lock' => true,
        ];
        if ($options['db_port'] === '') {
            unset($options['db_port']);
        }
        $result = $service->install($options);
        unset($_SESSION['ce_install_csrf']);
        $messages[] = 'Установка завершена. Installer заблокирован через storage/app/installed.lock.';
        if (isset($_POST['json'])) {
            ce_json_response($result);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (isset($_POST['json'])) {
            ce_json_response(['ok' => false, 'error' => $error], 422);
        }
    }

    $requirements = $service->requirements($selectedDriver);
}

$defaults = [
    'domain' => $_POST['domain'] ?? ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080'),
    'db_driver' => $_POST['db_driver'] ?? 'sqlite',
    'db_host' => $_POST['db_host'] ?? '127.0.0.1',
    'db_port' => $_POST['db_port'] ?? '5432',
    'db_name' => $_POST['db_name'] ?? 'storage/database/cajeer.sqlite',
    'db_user' => $_POST['db_user'] ?? 'cajeerengine',
    'admin_name' => $_POST['admin_name'] ?? 'Administrator',
    'admin_email' => $_POST['admin_email'] ?? '',
    'production' => isset($_POST['production']),
];

http_response_code($error === null ? 200 : 422);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CajeerEngine Install UX 1.1.1</title>
    <style>
        :root{color-scheme:light;--bg:#f6f7fb;--card:#fff;--border:#d8dee9;--text:#111827;--muted:#64748b;--ok:#147a35;--fail:#b42318;--warn:#a15c00;--primary:#1f5eff}
        body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,sans-serif}
        main{max-width:1120px;margin:32px auto;padding:0 16px 48px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:20px;margin:18px 0;box-shadow:0 8px 32px rgba(15,23,42,.04)}
        h1{font-size:32px;margin:0 0 8px}h2{font-size:20px;margin:0 0 14px}p{color:var(--muted)}label{display:block;font-weight:650;margin:12px 0 6px}input,select{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:12px;padding:12px 13px;font:inherit;background:#fff}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.actions{display:flex;gap:12px;align-items:center;margin-top:18px}.btn{border:0;background:var(--primary);color:white;border-radius:12px;padding:13px 18px;font-weight:750;cursor:pointer}.muted{color:var(--muted);font-size:14px}.ok{color:var(--ok)}.fail{color:var(--fail)}.warn{color:var(--warn)}.pill{display:inline-flex;border-radius:999px;padding:4px 9px;font-weight:700;background:#eef2ff;color:#1e3a8a}.check{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid #edf0f5}.check:last-child{border-bottom:0}.error{border-color:#fda29b;background:#fff5f4;color:#7a271a}.success{border-color:#86efac;background:#f0fdf4;color:#14532d}code,pre{background:#111827;color:#e5e7eb;border-radius:12px;padding:12px;display:block;overflow:auto}.step{display:flex;gap:10px;align-items:flex-start}.num{width:28px;height:28px;border-radius:50%;background:#e0e7ff;color:#1e3a8a;display:inline-flex;align-items:center;justify-content:center;font-weight:800;flex:0 0 auto}@media(max-width:850px){.grid,.row{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main>
    <h1>CajeerEngine Install UX <span class="pill">1.1.1</span></h1>
    <p>Web Installer создаёт `.env`, проверяет окружение, подключается к БД, запускает миграции, создаёт первого администратора, генерирует секреты и блокирует `/install` после установки.</p>

    <?php if ($error !== null): ?><section class="card error"><h2>Ошибка установки</h2><p><?= ce_h($error) ?></p></section><?php endif; ?>
    <?php if ($messages !== []): ?><section class="card success"><h2>Готово</h2><?php foreach ($messages as $message): ?><p><?= ce_h($message) ?></p><?php endforeach; ?><p><a href="/admin/">Перейти в админку</a></p></section><?php endif; ?>

    <div class="grid">
        <section class="card">
            <h2>1. Проверка окружения</h2>
            <?php foreach (($requirements['checks'] ?? []) as $key => $check): $ok = (bool) ($check['ok'] ?? false); ?>
                <div class="check">
                    <span><?= ce_h($check['label'] ?? $key) ?></span>
                    <strong class="<?= ce_status_class($ok) ?>"><?= $ok ? 'OK' : 'FAIL' ?></strong>
                </div>
            <?php endforeach; ?>
            <p class="muted">Composer autoload и Admin assets нужны для полноценного dist-архива. Сам installer работает без Symfony Console.</p>
        </section>

        <section class="card">
            <h2>Этапы установки</h2>
            <?php foreach (['Выбор БД и режима', 'Генерация .env и секретов', 'Проверка подключения к БД', 'Запуск миграций', 'Создание первого администратора', 'Финальная проверка', 'Создание installed.lock'] as $i => $step): ?>
                <p class="step"><span class="num"><?= $i + 1 ?></span><span><?= ce_h($step) ?></span></p>
            <?php endforeach; ?>
        </section>
    </div>

    <form class="card" method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= ce_h($csrf) ?>">
        <h2>2. Параметры установки</h2>
        <label for="domain">Домен / URL</label>
        <input id="domain" name="domain" value="<?= ce_h($defaults['domain']) ?>" placeholder="site.ru" required>

        <div class="row">
            <div>
                <label for="db_driver">Тип БД</label>
                <select id="db_driver" name="db_driver">
                    <?php foreach (['sqlite' => 'SQLite demo/local', 'pgsql' => 'PostgreSQL production', 'mysql' => 'MySQL/MariaDB optional'] as $value => $label): ?>
                        <option value="<?= ce_h($value) ?>" <?= $defaults['db_driver'] === $value ? 'selected' : '' ?>><?= ce_h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><input type="checkbox" name="production" value="1" <?= $defaults['production'] ? 'checked' : '' ?> style="width:auto"> Production hardening</label>
                <p class="muted">Отключает debug и включает строгие security defaults.</p>
            </div>
        </div>

        <div class="row">
            <div><label for="db_host">DB host</label><input id="db_host" name="db_host" value="<?= ce_h($defaults['db_host']) ?>"></div>
            <div><label for="db_port">DB port</label><input id="db_port" name="db_port" value="<?= ce_h($defaults['db_port']) ?>"></div>
        </div>
        <label for="db_name">DB name / SQLite path</label>
        <input id="db_name" name="db_name" value="<?= ce_h($defaults['db_name']) ?>" required>
        <div class="row">
            <div><label for="db_user">DB user</label><input id="db_user" name="db_user" value="<?= ce_h($defaults['db_user']) ?>"></div>
            <div><label for="db_password">DB password</label><input id="db_password" name="db_password" type="password" value=""></div>
        </div>

        <h2>3. Первый администратор</h2>
        <div class="row">
            <div><label for="admin_name">Имя</label><input id="admin_name" name="admin_name" value="<?= ce_h($defaults['admin_name']) ?>" required></div>
            <div><label for="admin_email">Email</label><input id="admin_email" name="admin_email" type="email" value="<?= ce_h($defaults['admin_email']) ?>" required></div>
        </div>
        <label for="admin_password">Пароль</label>
        <input id="admin_password" name="admin_password" type="password" minlength="12" required>
        <p class="muted">Минимум 12 символов, строчные и заглавные буквы, цифры.</p>
        <div class="actions"><button class="btn" type="submit">Установить</button><span class="muted">После успешной установки `/install` будет запрещён.</span></div>
    </form>

    <?php if (is_array($result)): ?>
        <section class="card"><h2>Post-install report</h2><pre><?= ce_h(json_encode($result['final'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></section>
    <?php endif; ?>
</main>
<script>
const driver = document.getElementById('db_driver');
const dbName = document.getElementById('db_name');
const dbPort = document.getElementById('db_port');
driver.addEventListener('change', () => {
  if (driver.value === 'sqlite') { dbName.value = 'storage/database/cajeer.sqlite'; dbPort.value = ''; }
  if (driver.value === 'pgsql') { if (dbName.value.includes('.sqlite')) dbName.value = 'cajeerengine'; dbPort.value = '5432'; }
  if (driver.value === 'mysql') { if (dbName.value.includes('.sqlite')) dbName.value = 'cajeerengine'; dbPort.value = '3306'; }
});
</script>
</body>
</html>
