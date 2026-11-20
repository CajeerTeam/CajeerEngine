#!/usr/bin/env php
<?php

declare(strict_types=1);

// 2.0 GA regression: the real release builder must not publish a source-only
// index, and the actual diagnostic service must never return raw secrets.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'CajeerEngine\\')) return;
    $name = $root . '/core/' . str_replace('\\', '/', substr($class, strlen('CajeerEngine\\'))) . '.php';
    if (is_file($name)) require $name;
});
use CajeerEngine\Release\ReleaseBuilder;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Stable\StableReleaseService;

function assertGa(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS ' . $label . "\n";
}

function removeGa(string $dir): void
{
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($dir);
}

$work = sys_get_temp_dir() . '/cajeer-2-ga-' . bin2hex(random_bytes(8));
mkdir($work, 0700);
try {
    $builder = new ReleaseBuilder($root);
    assertGa(trim((string) file_get_contents($root . '/VERSION')) === '2.0.0', 'GA product version is 2.0.0');
    $plan = $builder->plan('source');
    assertGa($plan['release_requirements']['ready'] && in_array('tools/production-release-regression.php', $plan['files'], true), 'real source build requires GA regression');
    $support = new StableReleaseService($work, new ConfigRepository($work));
    file_put_contents($work . '/.env', "PUBLIC_EMAIL=private.person@example.org\nDB_HOST=db.private\nPASSWORD2=supersensitive123\n# URL=https://private\n");
    $redact = new ReflectionMethod($support, 'redactedEnv');
    $env = $redact->invoke($support);
    assertGa(str_contains($env, 'PUBLIC_EMAIL=***redacted***') && str_contains($env, 'DB_HOST=***redacted***')
        && !str_contains($env, 'private.person') && !str_contains($env, 'db.private') && !str_contains($env, 'supersensitive123'), 'env values and comments are completely redacted');

    mkdir($work . '/storage/logs', 0700, true);
    $secret = 'raw-unredactable-secret-' . bin2hex(random_bytes(6));
    file_put_contents($work . '/storage/logs/runtime.jsonl', json_encode(['level'=>'error','message'=>$secret,'token'=>$secret]) . "\n"
        . json_encode(['level'=>'warning','email'=>'operator@example.com']) . "\n"
        . "not-json\n"
        . json_encode(['level'=>['bad'=>'value'], 'message'=>$secret]) . "\n");
    $summaryMethod = new ReflectionMethod($support, 'safeLogSummary');
    $summary = $summaryMethod->invoke($support, $work . '/storage/logs/runtime.jsonl');
    $summaryText = json_encode($summary, JSON_THROW_ON_ERROR);
    assertGa($summary['entries'] === 3 && $summary['invalid'] === 1 && $summary['levels']['error'] === 1
        && $summary['levels']['warning'] === 1 && $summary['levels']['other'] === 1
        && !str_contains($summaryText, $secret) && !str_contains($summaryText, '@'), 'log summaries contain counts, not raw payloads');
    symlink($work . '/storage/logs/runtime.jsonl', $work . '/storage/logs/audit.jsonl');
    assertGa($summaryMethod->invoke($support, $work . '/storage/logs/audit.jsonl')['entries'] === 0, 'support bundle ignores symlinked logs');

    if (!class_exists(ZipArchive::class)) {
        if (in_array('--require-zip', $argv, true)) throw new RuntimeException('GA acceptance requires ext-zip');
        echo "SKIP physical support ZIP (ext-zip unavailable locally)\n";
    } else {
        // Use the installed project for the full service; no secrets are read
        // from the test fixture outside this self-contained regression.
        $projectService = new StableReleaseService($root, new ConfigRepository($root));
        $bundle = $projectService->supportBundle(['include_logs'=>true]);
        assertGa(($bundle['ok'] ?? false) && ($bundle['log_format'] ?? '') === 'aggregate-counts-only', 'production support bundle generated');
        $path = $bundle['target'];
        $zip = new ZipArchive();
        assertGa($zip->open($path) === true, 'generated support ZIP opens');
        try {
            assertGa($zip->locateName('logs/runtime.jsonl') === false && $zip->locateName('logs/audit.jsonl') === false
                && $zip->locateName('logs/runtime-summary.json') !== false, 'raw audit and runtime events excluded');
            assertGa($zip->locateName('env-redacted.txt') !== false, 'safe environment inventory included');
        } finally { $zip->close(); @unlink($path); }
        if (PHP_OS_FAMILY !== 'Windows') {
            assertGa(($bundle['size'] ?? 0) > 0, 'private support archive was created and closed');
        }
    }

    // Build an isolated source release without vendor, regardless of whether
    // the CI runner itself has Composer dependencies installed.
    $snapshot = $builder->build(false, 'source', $work . '/snapshot');
    $sourceTree = $work . '/source-tree';
    (new PharData($snapshot['target']))->extractTo($sourceTree);
    $isolated = new ReleaseBuilder($sourceTree . '/cajeerengine');
    $result = $isolated->buildAll(false, $work . '/releases');
    assertGa(($result['ok'] ?? true) === false && ($result['dist'] ?? null) === null && $result['artifact_index'] === null,
        'failed dist does not result in a complete artifact index');
    assertGa(!is_file($work . '/releases/cajeerengine-2.0.0-artifacts.json'), 'incomplete GA artifact index absent on disk');
    assertGa($isolated->verifyRelease('source', false, $result['source']['target'])['ok'], 'partial source archive itself remains verifiable');
    $existing = $isolated->build(false, 'source', $work . '/releases');
    assertGa($existing['sha256'] === $result['source']['sha256'], 'identical rebuild retains immutable release bytes');
    file_put_contents($sourceTree . '/cajeerengine/README.md', "\nchanged after publication\n", FILE_APPEND);
    $refused = false;
    try { $isolated->build(false, 'source', $work . '/releases'); }
    catch (RuntimeException $e) { $refused = str_contains($e->getMessage(), 'Refusing to overwrite different release bytes'); }
    assertGa($refused, 'same-version artifact mutation is refused by production builder');
    assertGa(hash_file('sha256', $result['source']['target']) === $existing['sha256'], 'previously published archive remains intact');

} finally {
    removeGa($work);
}
