<?php

declare(strict_types=1);
$root = dirname(__DIR__);
$spec = json_decode(file_get_contents($root . '/api/openapi.yaml'), true, 512, JSON_THROW_ON_ERROR);
$operations = [];
foreach ($spec['paths'] as $path => $methods) {
    foreach ($methods as $method => $op) {
        if (!in_array($method, ['get','post','put','patch','delete'], true)) continue;
        $operations[$op['operationId']] = ['method' => strtoupper($method), 'path' => $path, 'parameters' => array_values(array_map(static fn ($p) => ['name'=>$p['name'], 'in'=>$p['in'], 'required'=>$p['required']??false], $op['parameters']??[])), 'bodyRequired' => $op['requestBody']['required']??false];
    }
}
ksort($operations);
$json = json_encode($operations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$files = [
    'packages/ts-sdk/src/operations.ts' => "// Generated from api/openapi.yaml. Do not edit.\nexport const operations = $json as const\nexport type OperationId = keyof typeof operations\nexport type OperationArguments = { path?: Record<string, string | number>; query?: Record<string, string | number | boolean>; body?: unknown }\n",
    'packages/php-sdk/src/Operations.php' => "<?php\n\ndeclare(strict_types=1);\nnamespace CajeerEngine\\ExtensionSdk;\n\n/** Generated from api/openapi.yaml. */\nfinal class Operations {\n    public const ALL = " . var_export($operations, true) . ";\n}\n",
];
foreach ($files as $path => $content) {
    if (in_array('--check', $argv, true)) {
        if (!is_file($root.'/'.$path) || file_get_contents($root.'/'.$path) !== $content) { fwrite(STDERR,"SDK out of date: $path\n"); exit(1); }
    } else file_put_contents($root.'/'.$path, $content);
}
echo count($operations)." SDK operations synchronized\n";
