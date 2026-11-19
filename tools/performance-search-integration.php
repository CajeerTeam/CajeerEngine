<?php
/** Real PHP PDO SQL search correctness, pagination and concurrent MVCC snapshot acceptance. */
declare(strict_types=1);
require dirname(__DIR__) . '/core/Console/NativeCli.php';
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Search\CmsSearchEngine;
use CajeerEngine\Search\SearchProjection;

function assertPerf(bool $valid, string $reason): void { if (!$valid) throw new RuntimeException($reason); }
function removePerfTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "pdo_sqlite required: cannot assert real PHP PDO search integration\n");
    exit(2);
}
$source = dirname(__DIR__);
$site = sys_get_temp_dir() . '/ce-search-load-' . bin2hex(random_bytes(8));
mkdir($site . '/config', 0700, true);
foreach (glob($source . '/config/*.php') ?: [] as $file) copy($file, $site . '/config/' . basename($file));
$old = [];
foreach (['APP_ENV' => 'testing', 'DB_DRIVER' => 'sqlite', 'DB_SQLITE_WAL' => 'true', 'CONTENT_STORAGE' => 'database', 'CMS_STORAGE' => 'database', 'SQLITE_DATABASE' => $site . '/search.sqlite'] as $key => $value) {
    $old[$key] = getenv($key);
    putenv($key . '=' . $value);
}
try {
    $config = new ConfigRepository($site);
    $db = new DatabaseManager($config);
    $pdo = $db->connection();
    (new MigrationRunner($pdo, $source . '/migrations'))->run('sqlite');
    $pdo->exec('UPDATE ce_public_route_state SET generation=1, search_version=' . SearchProjection::VERSION . ' WHERE id=1');
    $insert = $pdo->prepare('INSERT INTO ce_cms_search (document_id,entry_id,content_type,locale,title,body,title_norm,body_norm,payload,digest) VALUES (?,?,?,?,?,?,?,?,?,?)');
    for ($i = 0; $i < 60; $i++) {
        $type = $i % 3 === 0 ? 'news' : 'pages';
        $locale = $i % 2 === 0 ? 'ru' : 'en';
        $doc = ['id' => $type . ':' . $i, 'source_id' => (string) $i, 'index' => 'content', 'type' => $type,
                'title' => 'atlas ' . $i, 'body' => 'test', 'excerpt' => 'test', 'url' => '/doc-' . $i,
                'locale' => $locale, 'status' => 'published', 'revision' => 1, 'updated_at' => '2026-01-01'];
        $payload = json_encode($doc, JSON_THROW_ON_ERROR);
        $insert->execute([$doc['id'], (string) $i, $type, $locale, $doc['title'], $doc['body'], strtolower($doc['title']), 'test', $payload, hash('sha256', $payload)]);
    }
    $engine = new CmsSearchEngine($site, $db);
    $page = $engine->page('atlas', 'content', 7, 3);
    assertPerf($page['meta']['total'] === 60 && count($page['data']) === 7 && $page['meta']['offset'] === 3, 'Unfiltered search result/count mismatch');
    assertPerf($page['meta']['facets']['type'] === ['news' => 20, 'pages' => 40], 'Facet counts differ from results');
    $filtered = $engine->page('atlas', 'content', 10, 0, 'news', 'ru');
    assertPerf($filtered['meta']['total'] === 10 && count($filtered['data']) === 10, 'Type/locale filter failed');
    assertPerf($filtered['meta']['facets']['locale'] === ['ru' => 10], 'Filtered facet incorrect');
    assertPerf($engine->page('notfound')['meta']['total'] === 0, 'Empty result count');

    // Ensure writer commit can progress during an open read transaction while
    // the reader sees the same search generation in every statement.
    $writer = (new DatabaseManager($config))->connection();
    $db->readSnapshot(function (PDO $reader) use ($writer, $insert): void {
        $before = (int) $reader->query('SELECT COUNT(*) FROM ce_cms_search')->fetchColumn();
        $writer->exec("INSERT INTO ce_cms_search (document_id,entry_id,content_type,locale,title,body,title_norm,body_norm,payload,digest) VALUES ('snapshot:1','new','pages','ru','snapshot','body','snapshot','body','{}','x')");
        $after = (int) $reader->query('SELECT COUNT(*) FROM ce_cms_search')->fetchColumn();
        assertPerf($before === 60 && $after === 60, 'Read snapshot changed during committed write');
    });
    assertPerf((int) $pdo->query('SELECT COUNT(*) FROM ce_cms_search')->fetchColumn() === 61, 'Committed writer not visible after snapshot');
    echo "PASS: real PHP PDO SQLite CMS search, 60 documents, filtered facets, pagination and concurrent MVCC read\n";
} finally {
    foreach ($old as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    removePerfTree($site);
}
