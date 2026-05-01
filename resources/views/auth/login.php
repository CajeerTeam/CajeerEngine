<?php ob_start(); ?>
<section class="ce-card ce-narrow">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($error): ?><p class="ce-alert danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post" action="/admin/login" class="ce-form">
        <?= $csrf->field() ?>
        <label>Логин или email <input name="login" required autocomplete="username"></label>
        <label>Пароль <input type="password" name="password" required autocomplete="current-password"></label>
        <button class="ce-button" type="submit">Войти</button>
    </form>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
