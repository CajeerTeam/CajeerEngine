<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'Cajeer Engine', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="ce-header">
    <a class="ce-logo" href="/">Cajeer Engine</a>
    <nav>
        <a href="/news/demo">Новости</a>
        <a href="/marketplace">Marketplace</a>
        <a href="/admin">Админка</a>
        <a href="/api/health">Health</a>
    </nav>
</header>
<main class="ce-main">
    <?= $content ?? '' ?>
</main>
<footer class="ce-footer">
    <span>PHP CMS · DLE compatibility · WordPress compatibility</span>
</footer>
</body>
</html>
