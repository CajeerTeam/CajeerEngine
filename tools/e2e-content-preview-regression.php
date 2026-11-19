#!/usr/bin/env php
<?php
/** Integration: actual CMS content -> public publication/search -> preview -> static export. */
declare(strict_types=1);

use CajeerEngine\Cms\{CmsBootstrapService, PreviewTokenService, ThemeConfigRepository, NavigationRepository};
use CajeerEngine\Content\{ContentEntryRepository, ContentTypeRepository, RevisionConflict};
use CajeerEngine\Database\{DatabaseManager, MigrationRunner};
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Public\{PublicPageRenderer, RouteIndex};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Search\CmsSearchEngine;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Template\{CajeerTemplateEngine, TemplateSandbox};
use CajeerEngine\Update\OperationFiles as F;

$source = dirname(__DIR__);
require $source . '/core/Console/NativeCli.php';
function e2eAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL ' . $message);
    echo "PASS $message\n";
}
function e2eDelete(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($directory);
}
$database = in_array('--database', $argv, true);
if ($database && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('E2E --database explicitly requires PDO SQLite (must not silently skip)');
}
$root = sys_get_temp_dir() . '/ce-e2e-content-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$envNames = ['APP_ENV', 'APP_URL', 'CONTENT_STORAGE', 'CMS_STORAGE', 'MEDIA_STORAGE', 'DB_DRIVER', 'STORAGE_DRIVER', 'SEARCH_DRIVER', 'PUBLIC_RENDER_CACHE_TTL', 'MEDIA_REQUIRE_IMAGE_PROCESSOR'];
$previous = [];
foreach ($envNames as $name) $previous[$name] = getenv($name);
try {
    foreach (['APP_ENV=testing', 'APP_URL=https://e2e.example', 'CONTENT_STORAGE=' . ($database ? 'database' : 'file'), 'CMS_STORAGE=' . ($database ? 'database' : 'file'), 'MEDIA_STORAGE=' . ($database ? 'database' : 'file'), 'DB_DRIVER=sqlite', 'STORAGE_DRIVER=local', 'SEARCH_DRIVER=file', 'PUBLIC_RENDER_CACHE_TTL=60', 'MEDIA_REQUIRE_IMAGE_PROCESSOR=false'] as $variable) putenv($variable);
    foreach (['config', 'templates', 'resources/consent'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) F::copy($file->getPathname(), $root . '/' . substr($file->getPathname(), strlen($source) + 1), 0644);
        }
    }
    if ($database) {
        F::dir($root . '/storage/database');
        $dbConfig = require $root . '/config/database.php';
        $dbConfig['connections']['sqlite']['database'] = $root . '/storage/database/e2e.sqlite';
        F::write($root . '/config/database.php', '<?php return ' . var_export($dbConfig, true) . ';');
    }
    $config = new ConfigRepository($root);
    $db = new DatabaseManager($config);
    if ($database) (new MigrationRunner($db->connection(), $source . '/migrations'))->run('sqlite');
    (new CmsBootstrapService($root))->run();
    $types = new ContentTypeRepository($root, $db);
    $entries = new ContentEntryRepository($root, $db);
    $routes = new RouteIndex($root, $db);
    $search = new CmsSearchEngine($root, $db, 'file');
    $tokens = new PreviewTokenService($root, $db);
    $media = new MediaRepository($root, new StorageManager($root, $config), $db);
    $renderer = new PublicPageRenderer($root, $types, $entries, new CajeerTemplateEngine(new TemplateSandbox($root . '/templates'), $root . '/templates'), $media, new ThemeConfigRepository($root, $db), new NavigationRepository($root, $db), $routes);

    $home = $entries->create('pages', ['title' => 'E2E Home', 'slug' => 'home', 'status' => 'published', 'data' => ['path' => '/', 'body' => '<p>E2E Home</p>']]);
    $draft = $entries->create('pages', ['title' => 'E2E Needle Draft', 'slug' => 'needledraft', 'status' => 'draft', 'data' => ['path' => '/e2e-draft', 'body' => '<p>NeedleDraft E2E content</p>']]);
    e2eAssert($renderer->renderByPath('/e2e-draft') === null && $search->page('NeedleDraft')['meta']['total'] === 0, 'draft hidden from public routing and search');
    $preview = $tokens->create('pages', $draft['id']);
    $resolved = $tokens->resolve($preview['token']);
    e2eAssert(($resolved['entry_id'] ?? '') === $draft['id'] && ($resolved['token_hash'] ?? null) === null && str_contains((string) ($renderer->renderPreviewId('pages', $resolved['entry_id'])['html'] ?? ''), 'NeedleDraft'), 'authenticated preview token resolves non-public draft without leaking token hash');
    e2eAssert($tokens->resolve('invalid-token') === null, 'unknown token never resolves');
    $published = $entries->publish('pages', $draft['id']);
    e2eAssert($routes->lookup('/e2e-draft')['id'] === $draft['id'] && str_contains((string) ($renderer->renderByPath('/e2e-draft')['html'] ?? ''), 'NeedleDraft'), 'publish immediately activates public route and renderer');
    e2eAssert($search->page('NeedleDraft')['meta']['total'] === 1, 'publish atomically activates public search projection');
    $updated = $entries->update('pages', $draft['id'], ['title' => 'E2E NeedleUpdated', 'data' => $published['data'], 'expected_revision' => $published['revision']]);
    e2eAssert($search->page('NeedleUpdated')['meta']['total'] === 1, 'edit immediately refreshes public search');
    try {
        $entries->update('pages', $draft['id'], ['title' => 'Stale', 'expected_revision' => $published['revision']]);
        throw new RuntimeException('Stale edit was accepted');
    } catch (RevisionConflict) {
        e2eAssert($entries->findById('pages', $draft['id'])['title'] === 'E2E NeedleUpdated', 'optimistic concurrency prevents lost edits');
    }
    $export = (new CajeerEngine\StaticExport\SiteExport($root))->run();
    e2eAssert(is_file($export['path'] . '/e2e-draft/index.html') && str_contains((string) file_get_contents($export['path'] . '/e2e-draft/index.html'), 'NeedleUpdated'), 'static export includes currently published revision');
    $entries->unpublish('pages', $draft['id']);
    e2eAssert($renderer->renderByPath('/e2e-draft') === null && $search->page('NeedleUpdated')['meta']['total'] === 0, 'unpublish revokes public renderer and search visibility');
    $export2 = (new CajeerEngine\StaticExport\SiteExport($root))->run();
    e2eAssert(!is_file($export2['path'] . '/e2e-draft/index.html'), 'second export removes unpublished content');

    if ($database) {
        $db->connection()->prepare('UPDATE ce_cms_preview_tokens SET expires_at = :expired, used_at = NULL WHERE token_hash = :hash')
            ->execute(['expired' => '2000-01-01 00:00:00', 'hash' => hash('sha256', $preview['token'])]);
        e2eAssert($tokens->resolve($preview['token']) === null, 'expired SQL token cannot resolve');
        $used = $db->connection()->prepare('SELECT used_at FROM ce_cms_preview_tokens WHERE token_hash = :hash');
        $used->execute(['hash' => hash('sha256', $preview['token'])]);
        e2eAssert($used->fetchColumn() === null, 'expired SQL token never records a new usage');
    }

    if (!$database && function_exists('pcntl_fork')) {
        $total = 16; $children = [];
        for ($i = 0; $i < $total; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) throw new RuntimeException('Cannot fork preview test');
            if ($pid === 0) {
                try {
                    $token = (new PreviewTokenService($root))->create('pages', $draft['id']);
                    file_put_contents($root . '/preview-' . $i, $token['token']);
                    exit(0);
                } catch (Throwable $e) { fwrite(STDERR, $e . PHP_EOL); exit(1); }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            e2eAssert(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'concurrent preview issuance worker exits successfully');
        }
        $issued = [];
        foreach (range(0, $total - 1) as $i) $issued[] = file_get_contents($root . '/preview-' . $i);
        e2eAssert(count(array_unique($issued)) === $total, 'concurrent tokens have unique cryptographic values');
        foreach ($issued as $token) e2eAssert($tokens->resolve($token) !== null, 'concurrently issued token persists and resolves');
        e2eAssert($tokens->resolve($preview['token']) !== null, 'older preview token remains valid after concurrent writes');
        $stored = json_decode((string) file_get_contents($root . '/storage/app/cms/preview-tokens.json'), true, 512, JSON_THROW_ON_ERROR);
        e2eAssert(count($stored['tokens']) === $total + 1, 'no preview tokens lost during concurrent issuance');
        $stored['tokens'][0]['expires_at'] = '2000-01-01T00:00:00+00:00';
        $stored['tokens'][0]['used_at'] = null;
        file_put_contents($root . '/storage/app/cms/preview-tokens.json', json_encode($stored, JSON_THROW_ON_ERROR));
        e2eAssert($tokens->resolve($preview['token']) === null, 'expired file token cannot resolve');
        $after = json_decode((string) file_get_contents($root . '/storage/app/cms/preview-tokens.json'), true, 512, JSON_THROW_ON_ERROR);
        e2eAssert($after['tokens'][0]['used_at'] === null, 'expired file token never records a new usage');
        $newToken = $tokens->create('pages', $draft['id']);
        $pruned = json_decode((string) file_get_contents($root . '/storage/app/cms/preview-tokens.json'), true, 512, JSON_THROW_ON_ERROR);
        e2eAssert(count($pruned['tokens']) === $total + 1 && $tokens->resolve($newToken['token']) !== null,
            'token issuance prunes expired records while preserving active records');
    }
    echo 'E2E content, preview, search and export (' . ($database ? 'SQLite' : 'file') . ") complete\n";
} finally {
    foreach ($previous as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    e2eDelete($root);
}
