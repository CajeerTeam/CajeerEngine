<?php ob_start(); ?>
<article class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if (!empty($item?->metaDescription)): ?><p class="ce-muted"><?= htmlspecialchars($item->metaDescription, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <div class="ce-content"><?= $content ?? '' ?></div>
</article>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
