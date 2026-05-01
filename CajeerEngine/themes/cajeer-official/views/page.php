<?php ob_start(); ?>
<section class="section section-first"><div class="container narrow"><h1><?= e($item->title ?? $title ?? '') ?></h1><?php if (!empty($item?->excerpt)): ?><p class="lead"><?= e($item->excerpt) ?></p><?php endif; ?><div class="content-body"><?= $content ?? '' ?></div></div></section>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
