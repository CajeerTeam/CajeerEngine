<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$theme = $argv[1] ?? 'cajeer-official';
$base = $root . '/themes/' . preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $theme);
$required = ['extension.json', 'views/layout.php', 'views/home.php', 'assets/css/site.css'];
foreach ($required as $file) {
    if (!is_file($base . '/' . $file)) {
        fwrite(STDERR, "Missing theme file: {$file}\n");
        exit(1);
    }
}
$manifest = json_decode((string) file_get_contents($base . '/extension.json'), true);
if (!is_array($manifest) || ($manifest['type'] ?? null) !== 'theme') {
    fwrite(STDERR, "Invalid theme extension.json\n");
    exit(1);
}
$forbidden = ['index.php', 'nginx.conf', 'content/site.php'];
foreach ($forbidden as $file) {
    if (is_file($base . '/' . $file)) {
        fwrite(STDERR, "Forbidden runtime file in theme: {$file}\n");
        exit(1);
    }
}
echo "Theme check {$theme}: ok\n";
