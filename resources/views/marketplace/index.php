<?php ob_start(); ?>
<section class="ce-card">
    <p class="ce-kicker">Public Marketplace</p>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Публичная витрина расширений. Тип: <strong><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
</section>
<section class="ce-grid">
    <article>
        <h2>Темы</h2>
        <p>Нативные, DLE-compatible и WordPress-compatible темы.</p>
    </article>
    <article>
        <h2>Плагины</h2>
        <p>Нативные Cajeer плагины и совместимые legacy-пакеты.</p>
    </article>
    <article>
        <h2>Модули</h2>
        <p>Расширение системной функциональности движка.</p>
    </article>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
