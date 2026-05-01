<?php ob_start(); ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if (!empty($result)): ?>
        <pre><?= htmlspecialchars(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>
    <form method="post" action="/admin/marketplace/install" enctype="multipart/form-data" class="ce-form">
        <?= $csrf->field() ?>
        <label>Package ZIP <input type="file" name="package" accept=".zip"></label>
        <button type="submit">Проверить и установить</button>
    </form>
    <h2>Зарегистрированные расширения</h2>
    <ul>
        <?php foreach ($extensions as $extension): ?>
            <li><?= htmlspecialchars(json_encode($extension, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__, 2) . '/layout.php'; ?>
