<?php ob_start(); ?>
<section class="ce-hero">
    <div>
        <p class="ce-kicker">PHP · Nginx · PostgreSQL · MySQL</p>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="ce-actions">
            <a class="ce-button" href="/admin">Открыть админку</a>
            <a class="ce-button secondary" href="/marketplace">Marketplace</a>
        </div>
    </div>
</section>
<section class="ce-grid">
    <article>
        <h2>Cajeer Core</h2>
        <p>Собственное ядро: роутинг, события, DBAL, расширения, темы, модули.</p>
    </article>
    <article>
        <h2>DLE Layer</h2>
        <p>Адаптер для .tpl, тегов, legacy URL и будущего импорта данных.</p>
    </article>
    <article>
        <h2>WordPress Layer</h2>
        <p>Subset API для hooks, filters, shortcodes и совместимых плагинов.</p>
    </article>
</section>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
