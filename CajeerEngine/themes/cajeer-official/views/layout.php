<?php
$siteName = site_value($config, 'name', 'Cajeer');
$description = $pageMeta['description'] ?? site_value($config, 'description', '');
$themeColor = site_value($config, 'theme_color', '#0b0f19');
$logo = theme_asset((string) site_value($config, 'logo', 'img/logo.png'));
$social = theme_asset((string) site_value($config, 'social_preview', 'img/social-preview.png'));
?><!DOCTYPE html>
<html lang="<?= e(site_value($config, 'language', 'ru')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageMeta['title'] ?? $title ?? $siteName) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<?php if (($page ?? '') === '404'): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="canonical" href="<?= e($canonicalUrl ?? '/') ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:locale" content="ru_RU">
<meta property="og:title" content="<?= e($pageMeta['title'] ?? $siteName) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonicalUrl ?? '/') ?>">
<meta property="og:image" content="<?= e(str_starts_with($social, 'http') ? $social : site_domain($config) . $social) ?>">
<meta property="og:image:width" content="<?= e((string) site_value($config, 'social_image_width', 1200)) ?>">
<meta property="og:image:height" content="<?= e((string) site_value($config, 'social_image_height', 630)) ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= e(theme_asset('img/favicon-16.png')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(theme_asset('img/favicon-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(theme_asset('img/apple-touch-icon.png')) ?>">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="stylesheet" href="<?= e(theme_asset('css/site.css')) ?>">
<?= $head ?? '' ?>
<?php if (function_exists('wp_head')) { wp_head(); } ?>
</head>
<body class="page-<?= e((string) ($page ?? 'default')) ?>">
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="/" aria-label="<?= e($siteName) ?>">
            <img src="<?= e($logo) ?>" alt="<?= e($siteName) ?>" class="brand-mark">
            <span class="brand-text"><?= e($siteName) ?></span>
        </a>
        <nav class="site-nav" aria-label="Основная навигация">
            <?php foreach (($config['navigation'] ?? []) as $url => $label): ?>
                <a href="<?= e($url) ?>"<?= ($url === '/' ? ($page ?? '') === 'home' : trim($url, '/') === ($page ?? '')) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<main class="site-main">
    <?= $content ?? '' ?>
</main>
<footer class="site-footer">
    <div class="container footer-inner">
        <span><?= e(site_value($config, 'copyright', '© Cajeer')) ?></span>
        <span><?= e(brand_value($config, 'public_voice', '')) ?></span>
    </div>
</footer>
<?= $footer_scripts ?? '' ?>
<script src="<?= e(theme_asset('js/site.js')) ?>" defer></script>
<?php if (function_exists('wp_footer')) { wp_footer(); } ?>
</body>
</html>
