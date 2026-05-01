<?php ob_start(); $featured = array_values(array_filter($projects ?? [], static fn(array $p): bool => !empty($p['is_featured']))); ?>
<section class="hero section-first">
    <div class="container hero-grid">
        <div>
            <p class="eyebrow">Официальный сайт</p>
            <h1><?= e(site_value($config, 'name', 'Cajeer')) ?></h1>
            <p class="lead"><?= e(site_value($config, 'description', '')) ?></p>
            <div class="hero-actions">
                <a class="button" href="/projects">Проекты</a>
                <a class="button button-secondary" href="/support">Поддержка</a>
            </div>
        </div>
        <article class="card hero-card">
            <h2><?= e(site_value($config, 'tagline', 'Экосистема проектов и брендов')) ?></h2>
            <p><?= e(brand_value($config, 'public_voice', '')) ?></p>
            <p><?= e(brand_value($config, 'remote_model', '')) ?></p>
        </article>
    </div>
</section>
<section class="section">
    <div class="container grid three">
        <article class="card"><h2>Модель</h2><p>Cajeer управляет публичной поверхностью проектов через единый брендовый контур и контролируемую коммуникацию.</p></article>
        <article class="card"><h2>Принципы</h2><ul class="list"><?php foreach (($config['principles'] ?? []) as $principle): ?><li><?= e($principle) ?></li><?php endforeach; ?></ul></article>
        <article class="card"><h2>Поддержка</h2><p>Единые каналы поддержки пользователей публичных проектов Cajeer.</p><p><a class="text-link" href="/support">Открыть поддержку</a></p></article>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="section-heading"><h2>Проекты</h2><a class="text-link" href="/projects">Все проекты</a></div>
        <div class="project-grid">
            <?php foreach ($featured ?: ($projects ?? []) as $project): ?>
                <article class="card project-card"><p class="eyebrow"><?= e(project_status_label($config, $project['status'] ?? null)) ?></p><h3><?= e($project['name'] ?? '') ?></h3><p><?= e($project['short_description'] ?? $project['description'] ?? '') ?></p><a class="button button-small" href="/projects/<?= e($project['slug'] ?? '') ?>">Страница проекта</a></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
