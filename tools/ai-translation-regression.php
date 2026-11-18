<?php

declare(strict_types=1);
$root = dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) require $file;
    }
});
use CajeerEngine\AI\AiTranslationStudio;
$check = static function(bool $condition, string $label): void { if (!$condition) throw new RuntimeException('FAIL ' . $label); echo 'PASS ' . $label . "\n"; };
$markup = '<p>Hello <a href="https://example.org/p?x=1">friend</a>, {{user.name}} &amp; %s!</p>';
$pieces = AiTranslationStudio::splitProtected($markup);
$check(implode('', array_column($pieces, 'text')) === $markup, 'Protected tokenizer never changes original HTML');
$protected = array_column(array_filter($pieces, static fn(array $piece): bool => $piece['protected']), 'text');
foreach (['<p>', '<a href="https://example.org/p?x=1">', '</a>', '{{user.name}}', '&amp;', '%s', '</p>'] as $literal) {
    $check(in_array($literal, $protected, true), 'Preserve invariant token ' . $literal);
}
$pieces = AiTranslationStudio::splitProtected('Visit https://example.org/file now and write ${CODE}.');
$check(in_array('https://example.org/file', array_column(array_filter($pieces, static fn($x) => $x['protected']), 'text'), true), 'Plain-text hyperlinks cannot be translated');
$check(in_array('${CODE}', array_column(array_filter($pieces, static fn($x) => $x['protected']), 'text'), true), 'Template variables remain invariant');
echo "AI Translation tokenizer acceptance PASSED\n";
