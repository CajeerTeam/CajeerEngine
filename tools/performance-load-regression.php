<?php
/** Concurrent, real-process public render cache load and correctness acceptance. */
declare(strict_types=1);
require dirname(__DIR__) . '/core/Console/NativeCli.php';
use CajeerEngine\Public\RenderCache;

function ensure(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function deleteTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $node) {
        $node->isDir() && !$node->isLink() ? rmdir($node->getPathname()) : unlink($node->getPathname());
    }
    rmdir($path);
}
function increment(string $counter): int
{
    $fp = fopen($counter, 'c+b');
    if ($fp === false) throw new RuntimeException('Counter unavailable');
    try {
        ensure(flock($fp, LOCK_EX), 'Counter lock');
        $count = (int) stream_get_contents($fp) + 1;
        rewind($fp); ftruncate($fp, 0); fwrite($fp, (string) $count); fflush($fp);
        flock($fp, LOCK_UN);
        return $count;
    } finally { fclose($fp); }
}
$root = sys_get_temp_dir() . '/ce-load-' . bin2hex(random_bytes(8));
mkdir($root . '/templates', 0700, true);
file_put_contents($root . '/templates/home.cjr', 'first template');
$counter = $root . '/render-counter.txt';
$cache = new RenderCache($root);
putenv('PUBLIC_RENDER_CACHE_TTL=60');
$render = static function () use ($counter): string {
    $count = increment($counter);
    usleep(80000); // Slow render exposes herd behaviour reliably.
    return '<h1>Rendered ' . $count . '</h1>';
};
try {
    ensure(function_exists('pcntl_fork'), 'pcntl is required for multiprocess acceptance');
    $children = 12;
    $pids = [];
    $start = hrtime(true);
    for ($i = 0; $i < $children; $i++) {
        $pid = pcntl_fork();
        ensure($pid !== -1, 'Cannot fork worker');
        if ($pid === 0) {
            try {
                $html = $cache->render('/home', ['locale' => 'en'], $render);
                exit($html === '<h1>Rendered 1</h1>' ? 0 : 2);
            } catch (Throwable $e) { fwrite(STDERR, get_class($e) . PHP_EOL); exit(3); }
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        ensure(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Concurrent cache worker returned invalid HTML');
    }
    $elapsed = (hrtime(true) - $start) / 1e9;
    ensure(trim(file_get_contents($counter)) === '1', 'Cache miss was rendered more than once');
    ensure($cache->render('/home', ['locale' => 'en'], $render) === '<h1>Rendered 1</h1>', 'Cache did not serve warm response');
    file_put_contents($root . '/templates/home.cjr', 'changed template');
    ensure($cache->render('/home', ['locale' => 'en'], $render) === '<h1>Rendered 2</h1>', 'Template edit did not invalidate cache');
    ensure($cache->render('/home', ['locale' => 'ru'], $render) === '<h1>Rendered 3</h1>', 'Context edit did not invalidate cache');
    putenv('PUBLIC_RENDER_CACHE_TTL=1');
    ensure($cache->render('/short-lived', [], $render) === '<h1>Rendered 4</h1>', 'Initial TTL cache result');
    sleep(2);
    ensure($cache->render('/short-lived', [], $render) === '<h1>Rendered 5</h1>', 'Expired cache was served');
    $errors = 0;
    try { $cache->render('/error', [], static function () use (&$errors): never { $errors++; throw new LogicException('render failed'); }); }
    catch (LogicException $e) { ensure($e->getMessage() === 'render failed', 'Render exception changed'); }
    ensure($errors === 1, 'Failing render executed more than once');
    ensure($cache->render('/error', [], $render) === '<h1>Rendered 6</h1>', 'A failed render poisoned the cache');
    putenv('PUBLIC_RENDER_CACHE_TTL=0');
    $cache->render('/home', ['locale' => 'en'], $render);
    ensure(trim(file_get_contents($counter)) === '7', 'TTL zero did not disable cache');
    $result = ['ok' => true, 'workers' => $children, 'render_calls_on_initial_miss' => 1,
        'wall_seconds_for_initial_concurrent_miss' => round($elapsed, 3),
        'throughput_requests_per_second' => round($children / max($elapsed, 0.000001), 3),
        'template_invalidation' => true, 'context_invalidation' => true,
        'expiration' => true, 'exception_propagation' => true, 'disabled_cache' => true];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    putenv('PUBLIC_RENDER_CACHE_TTL');
    deleteTree($root);
}
