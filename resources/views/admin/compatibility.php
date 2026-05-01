<?php ob_start(); ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Здесь должны быть сканеры DLE/WordPress, отчёты совместимости и мастер миграции.</p>
</section>
<section class="ce-grid two">
    <article>
        <h2>DLE</h2>
        <ul>
            <li>Сканирование .tpl</li>
            <li>Проверка тегов</li>
            <li>Импорт новостей</li>
            <li>Legacy URL map</li>
        </ul>
    </article>
    <article>
        <h2>WordPress</h2>
        <ul>
            <li>Сканирование плагинов</li>
            <li>Проверка hooks/filters</li>
            <li>Импорт posts/pages</li>
            <li>Theme adapter</li>
        </ul>
    </article>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
