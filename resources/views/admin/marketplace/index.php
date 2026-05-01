<?php ob_start(); ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Установщик пакетов подготовлен под signed zip + cajeer.json. Ниже — локально найденные расширения и опасные capabilities.</p>
    <table class="ce-table"><thead><tr><th>Пакет</th><th>Тип</th><th>Версия</th><th>Риски</th></tr></thead><tbody>
    <?php foreach ($extensions as $name => $extension): ?><tr><td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($extension['type'] ?? '', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($extension['version'] ?? 'dev', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(implode(', ', $extension['dangerous_permissions'] ?? []), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
    </tbody></table>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__, 2) . '/layout.php'; ?>
