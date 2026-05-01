<?php ob_start(); ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Центр совместимости отслеживает DLE-шаблоны, WordPress hooks/functions, legacy URL и план миграции.</p>
</section>
<section class="ce-grid two">
    <article><h2>DLE</h2><ul><li>Сканер .tpl: supported / partially_supported / unsupported / dangerous</li><li>Поддержаны базовые условные блоки: aviable, group, empty</li><li>План импорта: новости, категории, пользователи, комментарии</li></ul></article>
    <article><h2>WordPress</h2><ul><li>Hooks/filters/shortcodes</li><li>Options через cajeer_settings</li><li>Subset функций: escaping, URLs, enqueue stubs</li></ul></article>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
