<?php ob_start(); ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($installed): ?>
        <p class="ce-alert">Проект уже установлен. <a href="/admin">Открыть админку</a>.</p>
    <?php else: ?>
        <h2>Проверки окружения</h2>
        <ul class="ce-checks">
            <?php foreach ($checks as $name => $ok): ?><li><strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong>: <?= $ok ? 'OK' : 'FAIL' ?></li><?php endforeach; ?>
        </ul>
        <form method="post" action="/install" class="ce-form ce-grid two">
            <?= $csrf->field() ?>
            <label>Название <input name="app_name" value="Cajeer Engine"></label>
            <label>URL <input name="app_url" value="http://localhost"></label>
            <label>Драйвер <select name="db_driver"><option value="mysql">MySQL/MariaDB</option><option value="pgsql">PostgreSQL</option></select></label>
            <label>DB host <input name="db_host" value="127.0.0.1"></label>
            <label>DB port <input name="db_port" value="3306"></label>
            <label>DB name <input name="db_name" value="cajeer"></label>
            <label>DB user <input name="db_user" value="cajeer"></label>
            <label>DB password <input name="db_password" type="password"></label>
            <label>Admin email <input name="admin_email" value="admin@example.test"></label>
            <label>Admin username <input name="admin_username" value="admin"></label>
            <label>Admin password <input name="admin_password" type="password" required></label>
            <div><button class="ce-button" type="submit">Установить</button></div>
        </form>
    <?php endif; ?>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
