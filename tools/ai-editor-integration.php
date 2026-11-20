<?php

declare(strict_types=1);

/** AI Content Editor acceptance: real PDO SQL, real file CMS, fake deterministic provider. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) require $file;
    }
});
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL PDO SQLite is required for editor integration\n"); exit(1); }
    echo "SKIP PDO SQLite unavailable\n"; exit(0);
}
use CajeerEngine\AI\AiContentEditor;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

$assert = static function (bool $v, string $label): void { if (!$v) throw new RuntimeException('FAIL ' . $label); echo 'PASS ' . $label . "\n"; };
$reject = static function (callable $fn, string $label) use ($assert): void {
    try { $fn(); } catch (Throwable) { $assert(true, $label); return; }
    throw new RuntimeException('FAIL ' . $label);
};
$dir = sys_get_temp_dir() . '/ce-ai-editor-' . bin2hex(random_bytes(7)); mkdir($dir, 0700);
$oldKey = getenv('INTEGRATIONS_KEY'); $oldOpenAi = getenv('OPENAI_API_KEY'); $oldStorage=getenv('CONTENT_STORAGE');
try {
    putenv('INTEGRATIONS_KEY=base64:' . base64_encode(random_bytes(32)));putenv('OPENAI_API_KEY=');putenv('CONTENT_STORAGE=file');
    $settings = new AiSettings($dir);
    $settings->update(['api_key'=>'sk-test-123456789012345678901234567890', 'model'=>'gpt-4.1-mini', 'max_output_tokens'=>256, 'monthly_token_limit'=>100000]);
    $config = new ConfigRepository($root); $config->set('database.default', 'sqlite'); $config->set('database.connections.sqlite.database', ':memory:');
    $db = new DatabaseManager($config);
    $db->connection()->exec(file_get_contents($root . '/migrations/sqlite/0031_ai_chat.sql'));
    $db->connection()->exec((string)file_get_contents($root.'/migrations/sqlite/0040_ai_cost_control.sql'));
    $db->connection()->exec(file_get_contents($root . '/migrations/sqlite/0032_ai_content_editor.sql'));
    $types = new ContentTypeRepository($dir, $db); $entries = new ContentEntryRepository($dir, $db);
    $types->create(['handle'=>'pages','name'=>'Pages','fields'=>[
        ['handle'=>'body','label'=>'Body','type'=>'richtext'],
        ['handle'=>'lead','label'=>'Lead','type'=>'textarea'],
        ['handle'=>'blocks','label'=>'Blocks','type'=>'blocks']],
        'blocks'=>[['handle'=>'paragraph','name'=>'Paragraph','fields'=>[['handle'=>'heading','label'=>'Heading','type'=>'text']]]]]);
    $entry = $entries->create('pages',['title'=>'Old title','slug'=>'test-page','locale'=>'ru','status'=>'draft',
        'data'=>['body'=>'<p>Original content</p>','lead'=>'Old lead','blocks'=>[['handle'=>'paragraph','data'=>['heading'=>'Old block']]]]]);
    $testText = 'Changed output';$failNext = false;$calls = 0;
    $client = new class($testText, $failNext, $calls) extends OpenAiClient {
        public function __construct(private string &$text, private bool &$fail, private int &$calls) {}
        public function complete(string $key, string $model, array $input, int $maxOutput, ?callable $onDelta = null): array
        {
            $this->calls++;
            if ($this->fail) { $this->fail=false; throw new AiProviderException('Fixture provider unavailable.',503); }
            if (!str_starts_with($key, 'sk-test-') || !str_contains(json_encode($input), 'Saved text')) throw new RuntimeException('Invalid provider context');
            return ['text'=>$this->text, 'usage'=>['input_tokens'=>50, 'output_tokens'=>20], 'response_id'=>'resp-test'];
        }
    };
    $editor = new AiContentEditor($db, $settings, $client, $entries, $types);
    $uid='11111111-1111-4111-8111-111111111111'; $other='22222222-2222-4222-8222-222222222222';
    $proposal=$editor->propose($uid,['type'=>'pages','entry_id'=>$entry['id'],'field'=>'lead','mode'=>'rewrite','instructions'=>'Simple']);
    $assert($proposal['original']==='Old lead' && $proposal['proposed']==='Changed output', 'real provider invocation returns editorial comparison');
    $assert($entries->findById('pages',$entry['id'])['data']['lead']==='Old lead', 'generation does not mutate live content');
    $reject(fn()=>$editor->apply($other,$proposal['id']), 'cross-user apply denied');
    $out=$editor->apply($uid,$proposal['id']);
    $assert($out['entry']['data']['lead']==='Changed output' && $out['entry']['revision']===$entry['revision']+1, 'accept changes real CMS revision');
    $reject(fn()=>$editor->apply($uid,$proposal['id']), 'proposal cannot be applied twice');
    $assert($entries->findById('pages',$entry['id'])['status']==='draft', 'editor leaves publication state unchanged');
    $testText = 'Changed block';
    $block=$editor->propose($uid,['type'=>'pages','entry_id'=>$entry['id'],'field'=>'blocks','block_index'=>0,'block_field'=>'heading','mode'=>'rewrite']);
    $out=$editor->apply($uid,$block['id']);
    $assert($out['entry']['data']['blocks'][0]['data']['heading']==='Changed block', 'saved block text updated through validated schema');
    $testText='Safe <script>alert(1)</script>';
    $rich=$editor->propose($uid,['type'=>'pages','entry_id'=>$entry['id'],'field'=>'body','mode'=>'rewrite']);
    $assert(!str_contains($rich['proposed'],'<script>') && str_contains($rich['proposed'],'&lt;script&gt;'), 'model richtext HTML escaped');
    // The exact string '0' is a valid selected fragment (it must not be silently discarded).
    $oldZero=$entries->findById('pages',$entry['id']);
    $entries->update('pages',$entry['id'],['data'=>array_replace($oldZero['data'],['lead'=>'Item 0 here']),'expected_revision'=>$oldZero['revision']]);
    $testText='one';
    $zero=$editor->propose($uid,['type'=>'pages','entry_id'=>$entry['id'],'field'=>'lead','mode'=>'rewrite','selection'=>'0']);
    $zeroResult=$editor->apply($uid,$zero['id']);
    $assert($zeroResult['entry']['data']['lead']==='Item one here','numeric-zero text selection changes only the selected substring');
    $testText='Edited';
    $stale=$editor->propose($uid,['type'=>'pages','entry_id'=>$entry['id'],'field'=>'lead','mode'=>'rewrite']);
    $fresh=$entries->findById('pages',$entry['id']);
    $entries->update('pages',$entry['id'],['data'=>array_replace($fresh['data'],['lead'=>'Manual edit']),'expected_revision'=>$fresh['revision']]);
    $reject(fn()=>$editor->apply($uid,$stale['id']), 'stale proposal cannot overwrite concurrent edit');
    $testText='Public update';
    $published=$entries->publish('pages',$entry['id']);
    $pub=$editor->propose($uid,['type'=>'pages','entry_id'=>$entry['id'],'field'=>'lead','mode'=>'rewrite']);
    $reject(fn()=>$editor->apply($uid,$pub['id']), 'published material requires explicit approval');
    $accepted=$editor->apply($uid,$pub['id'], true);
    $assert($accepted['entry']['status']==='published', 'publication confirmation preserves published status');
    $draft=$editor->propose($uid,['type'=>'pages','entry_id'=>null,'field'=>'lead','mode'=>'generate','instructions'=>'A new page']);
    $assert($draft['expected_revision']===null && $draft['entry_id']===null, 'new draft generation available without mutating CMS');
    $reject(fn()=>$editor->apply($uid,$draft['id']), 'draft is not silently saved by server');
    $reject(fn()=>$editor->propose($uid,['type'=>'pages','field'=>'bad','mode'=>'generate','instructions'=>'X']), 'non-schema field denied');
    $reject(fn()=>$editor->propose($uid,['type'=>'pages','entry_id'=>$entry['id'],'field'=>'blocks','block_index'=>20,'block_field'=>'heading','mode'=>'rewrite']), 'nonexistent block denied');
    $failNext=true;
    $reject(fn()=>$editor->propose($uid,['type'=>'pages','entry_id'=>$entry['id'],'field'=>'lead','mode'=>'rewrite']), 'provider network failure surfaced');
    $assert((int)$db->connection()->query('SELECT COUNT(*) FROM ce_ai_editor_jobs')->fetchColumn()===0, 'all editor leases released');
    $row=$db->connection()->query('SELECT used_tokens, reserved_tokens FROM ce_ai_usage')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row['used_tokens']>0 && (int)$row['reserved_tokens']===0, 'actual token usage charged and reservations released');
    $assert($calls>=6, 'real generation path invoked rather than manifest-only feature');
    // A long, valid request must hit the shared monthly cap before contacting
    // OpenAI, even on SQLite (where text-bound limits can bypass numeric CAS).
    $settings->update(['monthly_token_limit'=>1000]);
    $callsBeforeLimit = $calls;
    $blockedByTokens = false;
    try {
        $editor->propose($uid, ['type'=>'pages', 'entry_id'=>$entry['id'], 'field'=>'lead',
            'mode'=>'rewrite', 'instructions'=>str_repeat('x', 1800)]);
    } catch (DomainException $error) {
        $blockedByTokens = $error->getMessage() === 'Monthly AI token budget reached.';
    }
    $assert($blockedByTokens && $calls === $callsBeforeLimit,
        'editor monthly budget rejects oversized request before provider call');
    $assert((int)$db->connection()->query('SELECT SUM(reserved_tokens) FROM ce_ai_usage')->fetchColumn() === 0
        && (int)$db->connection()->query('SELECT COUNT(*) FROM ce_ai_editor_jobs')->fetchColumn() === 0,
        'blocked editor request leaves no budget or worker lease');
    echo "AI Content Editor real PHP/PDO acceptance PASSED\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        if ($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());
    } rmdir($dir);
    foreach (['INTEGRATIONS_KEY'=>$oldKey,'OPENAI_API_KEY'=>$oldOpenAi,'CONTENT_STORAGE'=>$oldStorage] as $key=>$value) putenv($key.'='.($value===false?'':$value));
}
