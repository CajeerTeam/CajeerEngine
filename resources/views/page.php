<?php ob_start(); ?>
<article class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></p>
</article>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
