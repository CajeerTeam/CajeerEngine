<?php

declare(strict_types=1);

/** SEO analyzer and CMS metadata integration: executable without vendor or SQLite. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) require $file;
    }
});

use CajeerEngine\AI\AiSeoAssistant;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Content\ContentEntryValidator;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Public\RouteIndex;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;

$assert = static function (bool $pass, string $name): void {
    if (!$pass) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . "\n";
};
$reject = static function (callable $fn, string $name) use ($assert): void {
    try { $fn(); } catch (InvalidArgumentException|DomainException $error) { $assert(true, $name); return; }
    throw new RuntimeException('FAIL: ' . $name);
};
$dir = sys_get_temp_dir().'/ce-seo-'.bin2hex(random_bytes(6)); mkdir($dir, 0700);
$old = getenv('CONTENT_STORAGE'); $oldCms=getenv('CMS_STORAGE'); putenv('CONTENT_STORAGE=file'); putenv('CMS_STORAGE=file');
try {
    $id = '11111111-1111-4111-8111-111111111111';
    $type = ['id'=>'22222222-2222-4222-8222-222222222222','handle'=>'pages','name'=>'Pages',
        'fields'=>[['handle'=>'body','type'=>'richtext'],['handle'=>'summary','type'=>'textarea']]];
    (new JsonFile($dir.'/storage/app/content-types.json'))->writeObject(['content_types'=>[$type]]);
    (new JsonFile($dir.'/storage/app/content/pages.json'))->writeObject(['entries'=>[[
        'id'=>$id,'type'=>'pages','title'=>'Installation guide','slug'=>'install-guide','status'=>'published','locale'=>'en',
        'revision'=>1,'updated_at'=>'2026-11-18T00:00:00+00:00','translation_group'=>$id,
        'data'=>['body'=>'<h1>Install</h1><h1>Guide</h1><p>Documentation <a href="/missing-page">link</a></p><img src="/hero.png">',
            'summary'=>'A quick guide'],
    ]]]);
    $config = new ConfigRepository($dir);
    $db = new DatabaseManager($config);
    $entries = new ContentEntryRepository($dir, $db);
    $types = new ContentTypeRepository($dir, $db);
    $service = new AiSeoAssistant($entries, $types, new RouteIndex($dir, $db));
    $before = $service->audit('pages', $id);
    $codes = array_column($before['issues'], 'code');
    $assert(in_array('missing_seo_title',$codes,true) && in_array('missing_description',$codes,true), 'missing SEO title and description identified');
    $assert(in_array('multiple_h1',$codes,true) && in_array('missing_image_alt',$codes,true), 'headings and missing image alt audited');
    $assert($before['stats']['images_without_alt'] === 1 && $before['revision'] === 1, 'real saved content and revision inspected');
    $validator = new ContentEntryValidator($type);
    $input = $entries->findById('pages',$id);
    $meta = array_replace($input['data'],['seo_title'=>'Installation guide: complete steps',
        'meta_description'=>'Learn to install the software using the official installation guide and verified steps.',
        'canonical_url'=>'/install-guide','seo_noindex'=>false]);
    $normalized=$validator->normalizeForUpdate(['data'=>$meta],$input);
    $assert($normalized['data']['seo_title'] === $meta['seo_title'] && $normalized['data']['meta_description'] === $meta['meta_description'], 'SEO metadata survives CMS normalization without schema fields');
    $updated = $entries->update('pages',$id,['data'=>$meta,'expected_revision'=>$input['revision']],'updated');
    $assert($updated !== null && $updated['revision']===2, 'SEO metadata committed as a real new CMS revision');
    $after = $service->audit('pages',$id);
    $assert(!in_array('missing_seo_title',array_column($after['issues'],'code'),true) &&
        $after['seo']['description']===$meta['meta_description'], 'audit re-reads saved SEO metadata');
    $batch = $service->batch('pages',10);
    $assert($batch['meta']['checked']===1 && $batch['reports'][0]['entry_id']===$id, 'batch audits published CMS documents');
    $duplicate = $entries->create('pages', ['title'=>'Another guide','slug'=>'another-guide','status'=>'published','locale'=>'en',
        'data'=>['body'=>'<h1>Another guide</h1>','seo_title'=>$meta['seo_title'],'meta_description'=>$meta['meta_description']]]);
    $dupReport = $service->audit('pages', $duplicate['id']);
    $assert(in_array('duplicate_seo_title', array_column($dupReport['issues'],'code'), true) &&
        in_array('duplicate_description', array_column($dupReport['issues'],'code'), true), 'duplicate SEO fields detected from actual published peers');
    $reject(fn()=>$service->audit('pages','bad'),'invalid entry refused');
    $reject(fn()=>$service->batch('pages',51),'unbounded batch refused');
    $reject(fn()=>$validator->normalizeForUpdate(['data'=>['seo_title'=>str_repeat('x',300)]],$input),'oversized SEO metadata rejected');
    $reject(fn()=>$validator->normalizeForUpdate(['data'=>['canonical_url'=>'javascript:alert(1)']],$input),'unsafe canonical rejected');
    echo "AI SEO local acceptance PASSED\n";
} finally {
    if(is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($dir);
    }
    putenv('CONTENT_STORAGE='.($old===false?'':$old)); putenv('CMS_STORAGE='.($oldCms===false?'':$oldCms));
}
