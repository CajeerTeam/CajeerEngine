<?php ob_start(); ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <table class="ce-table"><thead><tr><th>Проверка</th><th>Статус</th><th>Значение</th></tr></thead><tbody>
    <?php foreach ($checks as $check): ?><tr><td><?= htmlspecialchars($check['name'], ENT_QUOTES, 'UTF-8') ?></td><td><?= $check['ok'] ? 'OK' : 'FAIL' ?></td><td><?= htmlspecialchars((string) $check['value'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
    </tbody></table>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
