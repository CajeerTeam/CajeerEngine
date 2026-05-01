<?php ob_start(); ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Базовая админ-панель Cajeer Engine.</p>
</section>
<section class="ce-grid two">
    <article>
        <h2>Меню</h2>
        <ul>
            <?php foreach ($menu as $item): ?>
                <li><a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
        </ul>
    </article>
    <article>
        <h2>Расширения</h2>
        <ul>
            <?php foreach ($extensions as $name => $extension): ?>
                <li><strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong> — <?= htmlspecialchars($extension['version'] ?? 'dev', ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </article>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
