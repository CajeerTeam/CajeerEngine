<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$lock = $root . '/storage/app/installed.lock';

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require $file; }
});

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateManager;

function cu_h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function cu_bool_env(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') { return $default; }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}
function cu_load_env(string $root): void
{
    $path = $root . '/.env';
    if (!is_file($path)) { return; }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) { continue; }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '' || getenv($key) !== false) { continue; }
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
function cu_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

cu_load_env($root);

if (!is_file($lock)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>CajeerEngine upgrade</title><body style="font-family:system-ui;margin:40px"><h1>Upgrade недоступен</h1><p>Проект ещё не установлен. Сначала завершите <code>/install</code>.</p></body>';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['ce_upgrade_csrf'])) { $_SESSION['ce_upgrade_csrf'] = bin2hex(random_bytes(32)); }
$csrf = (string) $_SESSION['ce_upgrade_csrf'];
$webUpgradeEnabled = cu_bool_env('UPDATE_WEB_UPGRADE_ENABLED', false);
$upgradeSecret = trim((string) (getenv('UPDATE_WEB_UPGRADE_SECRET') ?: getenv('UPGRADE_SECRET') ?: ''));
$upgradeUnlocked = $webUpgradeEnabled && $upgradeSecret !== '' && !empty($_SESSION['ce_upgrade_unlocked']);
$manager = new UpdateManager($root, new ConfigRepository($root));
$state = $manager->wizardState();
$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('CSRF token устарел. Обновите страницу и повторите действие.');
        }

        if (($_POST['action'] ?? '') === 'unlock') {
            if (!$webUpgradeEnabled) {
                throw new RuntimeException('Web Upgrade отключён. Установите UPDATE_WEB_UPGRADE_ENABLED=true в .env и используйте CLI для первичной настройки.');
            }
            if ($upgradeSecret === '') {
                throw new RuntimeException('UPDATE_WEB_UPGRADE_SECRET не задан. Web Upgrade не может быть разблокирован без отдельного секрета.');
            }
            if (!hash_equals($upgradeSecret, (string) ($_POST['upgrade_secret'] ?? ''))) {
                throw new RuntimeException('Неверный upgrade secret.');
            }
            $_SESSION['ce_upgrade_unlocked'] = true;
            $upgradeUnlocked = true;
            $result = ['ok' => true, 'unlocked' => true, 'message' => 'Web Upgrade разблокирован для текущей сессии.'];
        } elseif (($_POST['action'] ?? '') === 'lock') {
            unset($_SESSION['ce_upgrade_unlocked']);
            $upgradeUnlocked = false;
            $result = ['ok' => true, 'unlocked' => false, 'message' => 'Web Upgrade заблокирован.'];
        } elseif (($_POST['action'] ?? '') === 'rollback') {
            if (!$upgradeUnlocked) {
                throw new RuntimeException('Web Upgrade заблокирован. Разблокируйте его перед rollback.');
            }
            $result = $manager->rollback(trim((string) ($_POST['rollback_backup'] ?? '')) ?: null);
            $state = $manager->wizardState();
        } else {
            if (!$upgradeUnlocked) {
                throw new RuntimeException('Web Upgrade заблокирован. Разблокируйте его через UPDATE_WEB_UPGRADE_SECRET или используйте CLI: php bin/cajeer update --package=/path/to/dist.zip --apply.');
            }
            $package = trim((string) ($_POST['package'] ?? ''));
            if (isset($_FILES['package_upload']) && is_array($_FILES['package_upload']) && (int) ($_FILES['package_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) $_FILES['package_upload']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Не удалось загрузить ZIP artifact. PHP upload error: ' . (string) $_FILES['package_upload']['error']);
                }
                $uploadDir = $root . '/storage/app/updates/uploads';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('Не удалось создать upload directory: ' . $uploadDir);
                }
                $name = basename((string) ($_FILES['package_upload']['name'] ?? 'update.zip'));
                $name = preg_replace('/[^a-zA-Z0-9._-]/', '-', $name) ?: 'update.zip';
                if (!str_ends_with(strtolower($name), '.zip')) {
                    throw new RuntimeException('Web Upgrade принимает только .zip artifacts.');
                }
                $target = $uploadDir . '/' . date('Ymd-His') . '-' . $name;
                if (!move_uploaded_file((string) $_FILES['package_upload']['tmp_name'], $target)) {
                    throw new RuntimeException('Не удалось сохранить загруженный ZIP artifact.');
                }
                $package = $target;
            }
            $options = [
                'package' => $package,
                'sha256' => trim((string) ($_POST['sha256'] ?? '')),
                'dry_run' => ($_POST['action'] ?? '') === 'dry-run',
                'no_backup' => isset($_POST['no_backup']),
                'force' => isset($_POST['force']),
                'no_maintenance' => isset($_POST['no_maintenance']),
                'no_migrations' => isset($_POST['no_migrations']),
                'no_doctor' => isset($_POST['no_doctor']),
            ];
            if (($_POST['action'] ?? '') === 'prepare') {
                $result = $manager->prepare($options);
            } else {
                $result = $manager->apply($options);
            }
            $state = $manager->wizardState();
        }
        if (isset($_POST['json'])) { cu_json($result); }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (isset($_POST['json'])) { cu_json(['ok' => false, 'error' => $error], 422); }
    }
}

http_response_code($error === null ? 200 : 422);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>CajeerEngine Upgrade UX 1.1.1</title>
<style>
:root{color-scheme:light;--bg:#f6f7fb;--card:#fff;--border:#d8dee9;--text:#111827;--muted:#64748b;--ok:#147a35;--fail:#b42318;--warn:#a15c00;--primary:#1f5eff}body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:1100px;margin:32px auto;padding:0 16px 48px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:20px;margin:18px 0;box-shadow:0 8px 32px rgba(15,23,42,.04)}h1{font-size:32px;margin:0 0 8px}h2{font-size:20px;margin:0 0 14px}p{color:var(--muted)}label{display:block;font-weight:650;margin:12px 0 6px}input{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:12px;padding:12px 13px;font:inherit;background:#fff}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.btn{border:0;background:var(--primary);color:white;border-radius:12px;padding:13px 18px;font-weight:750;cursor:pointer}.btn.secondary{background:#334155}.btn.warn{background:#a15c00}.muted{color:var(--muted);font-size:14px}.ok{color:var(--ok)}.fail{color:var(--fail)}.warn{color:var(--warn)}.pill{display:inline-flex;border-radius:999px;padding:4px 9px;font-weight:700;background:#eef2ff;color:#1e3a8a}.error{border-color:#fda29b;background:#fff5f4;color:#7a271a}.success{border-color:#86efac;background:#f0fdf4;color:#14532d}.locked{border-color:#fde68a;background:#fffbeb;color:#713f12}code,pre{background:#111827;color:#e5e7eb;border-radius:12px;padding:12px;display:block;overflow:auto}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}@media(max-width:850px){.grid,.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
<h1>CajeerEngine Upgrade UX <span class="pill">1.1.1</span></h1>
<p>Upgrade Wizard проверяет release ZIP artifact, SHA256/manifest, делает backup перед обновлением и не перезаписывает `.env`, uploads, SQLite database и runtime-директории.</p>
<?php if ($error !== null): ?><section class="card error"><h2>Ошибка</h2><p><?= cu_h($error) ?></p></section><?php endif; ?>
<?php if (is_array($result)): ?><section class="card success"><h2>Результат</h2><pre><?= cu_h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></section><?php endif; ?>
<?php if (!$upgradeUnlocked): ?>
<section class="card locked"><h2>Web Upgrade заблокирован</h2><p>В 1.1.1 применение обновлений через браузер требует явного включения и отдельного секрета. Это защищает production-проект от несанкционированной загрузки ZIP artifact.</p><pre>UPDATE_WEB_UPGRADE_ENABLED=true
UPDATE_WEB_UPGRADE_SECRET=replace-with-long-random-secret</pre><form method="post"><input type="hidden" name="_csrf" value="<?= cu_h($csrf) ?>"><label for="upgrade_secret">Upgrade secret</label><input id="upgrade_secret" name="upgrade_secret" type="password" autocomplete="off"><div class="actions"><button class="btn" name="action" value="unlock" type="submit">Разблокировать текущую сессию</button></div></form><p class="muted">CLI остаётся основным способом production-обновления: <code>php bin/cajeer update --package=/path/to/cajeerengine-1.1.1-dist.zip --apply</code></p></section>
<?php else: ?>
<section class="card success"><h2>Web Upgrade разблокирован</h2><p>Разблокировка действует только для текущей PHP session.</p><form method="post"><input type="hidden" name="_csrf" value="<?= cu_h($csrf) ?>"><button class="btn secondary" name="action" value="lock" type="submit">Заблокировать</button></form></section>
<?php endif; ?>
<div class="grid">
<section class="card">
<h2>Текущее состояние</h2>
<p><strong>Версия:</strong> <?= cu_h($state['current_version'] ?? '') ?></p>
<p><strong>ZipArchive:</strong> <span class="<?= ($state['zip_extension'] ?? false) ? 'ok' : 'fail' ?>"><?= ($state['zip_extension'] ?? false) ? 'OK' : 'FAIL' ?></span></p>
<p><strong>Storage writable:</strong> <span class="<?= ($state['storage_writable'] ?? false) ? 'ok' : 'fail' ?>"><?= ($state['storage_writable'] ?? false) ? 'OK' : 'FAIL' ?></span></p>
<p><strong>Web Upgrade:</strong> <span class="<?= $upgradeUnlocked ? 'ok' : 'warn' ?>"><?= $upgradeUnlocked ? 'UNLOCKED' : 'LOCKED' ?></span></p>
<p><strong>Maintenance:</strong> <span class="<?= !empty($state['maintenance']['enabled']) ? 'warn' : 'ok' ?>"><?= !empty($state['maintenance']['enabled']) ? 'ON' : 'OFF' ?></span></p>
<p><strong>Update available:</strong> <?= !empty($state['check']['update_available']) ? 'yes' : 'no' ?></p>
</section>
<section class="card">
<h2>Последний отчёт</h2>
<?php if (is_array($state['last_report'] ?? null)): ?><pre><?= cu_h(json_encode($state['last_report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php else: ?><p>Отчётов update пока нет.</p><?php endif; ?>
</section>
</div>
<form class="card" method="post" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= cu_h($csrf) ?>">
<h2>Release ZIP artifact</h2>
<label for="package_upload">Загрузить ZIP artifact</label>
<input id="package_upload" name="package_upload" type="file" accept=".zip,application/zip" <?= $upgradeUnlocked ? '' : 'disabled' ?>>
<label for="package">Или локальный путь / URL</label>
<input id="package" name="package" placeholder="storage/app/updates/downloads/cajeerengine-1.1.1-dist.zip" <?= $upgradeUnlocked ? '' : 'disabled' ?>>
<label for="sha256">SHA256, если известен</label>
<input id="sha256" name="sha256" placeholder="abcdef..." <?= $upgradeUnlocked ? '' : 'disabled' ?>>
<div class="row">
<label><input type="checkbox" name="no_backup" value="1" style="width:auto" <?= $upgradeUnlocked ? '' : 'disabled' ?>> Не создавать backup</label>
<label><input type="checkbox" name="force" value="1" style="width:auto" <?= $upgradeUnlocked ? '' : 'disabled' ?>> Force apply при неполной проверке</label>
<label><input type="checkbox" name="no_maintenance" value="1" style="width:auto" <?= $upgradeUnlocked ? '' : 'disabled' ?>> Не включать maintenance mode</label>
<label><input type="checkbox" name="no_migrations" value="1" style="width:auto" <?= $upgradeUnlocked ? '' : 'disabled' ?>> Не запускать migrations</label>
<label><input type="checkbox" name="no_doctor" value="1" style="width:auto" <?= $upgradeUnlocked ? '' : 'disabled' ?>> Не запускать doctor</label>
</div>
<div class="actions">
<button class="btn secondary" name="action" value="prepare" type="submit" <?= $upgradeUnlocked ? '' : 'disabled' ?>>Сохранить план</button>
<button class="btn warn" name="action" value="dry-run" type="submit" <?= $upgradeUnlocked ? '' : 'disabled' ?>>Dry-run apply</button>
<button class="btn" name="action" value="apply" type="submit" <?= $upgradeUnlocked ? '' : 'disabled' ?>>Применить обновление</button>
</div>
<p class="muted">Безопасный режим по умолчанию: перед apply создаётся backup runtime-данных в `storage/app/backups`.</p>
</form>

<form class="card" method="post">
<input type="hidden" name="_csrf" value="<?= cu_h($csrf) ?>">
<h2>Rollback snapshot</h2>
<label for="rollback_backup">Путь к rollback ZIP</label>
<input id="rollback_backup" name="rollback_backup" placeholder="storage/app/updates/rollback/rollback-YYYYmmdd-HHMMSS-1.1.1.zip" <?= $upgradeUnlocked ? '' : 'disabled' ?>>
<div class="actions"><button class="btn warn" name="action" value="rollback" type="submit" <?= $upgradeUnlocked ? '' : 'disabled' ?>>Выполнить rollback</button></div>
<p class="muted">Если путь не указан, будет использован snapshot из последнего update report.</p>
</form>
</main>
</body>
</html>
