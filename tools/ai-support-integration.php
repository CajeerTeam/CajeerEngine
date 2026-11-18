#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Real SQLite Support Center acceptance: visitor secrets, handoff, operator queue, budgeted RAG. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'CajeerEngine\\')) return;
    $file = $root.'/core/'.str_replace('\\', '/', substr($class, 13)).'.php';
    if (is_file($file)) require $file;
});

use CajeerEngine\AI\AiSupportCenter;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\AiKnowledgeBase;
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

$check = static function (bool $test, string $label): void {
    if (!$test) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
};
$reject = static function (callable $fn, string $label) use ($check): void {
    try { $fn(); }
    catch (DomainException|InvalidArgumentException|AiProviderException $e) { $check(true, $label); return; }
    throw new RuntimeException('FAIL '.$label);
};
$check(!AiSupportCenter::enabled() || getenv('SUPPORT_PUBLIC_ENABLED') !== false, 'Public support availability is environment-gated');
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL pdo_sqlite is required for support acceptance\n"); exit(1); }
    echo "SKIP SQL: pdo_sqlite unavailable.\n"; exit(0);
}
$directory = sys_get_temp_dir().'/ce-support-'.bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$env = [];
foreach (['OPENAI_API_KEY','INTEGRATIONS_KEY','SUPPORT_PUBLIC_ENABLED','SUPPORT_AUTO_REPLY_ENABLED','SUPPORT_PUBLIC_AI_MONTHLY_TOKENS','CONTENT_STORAGE'] as $name) $env[$name] = getenv($name);
try {
    putenv('SUPPORT_PUBLIC_ENABLED=1'); putenv('SUPPORT_AUTO_REPLY_ENABLED=0');
    putenv('SUPPORT_PUBLIC_AI_MONTHLY_TOKENS=40000');
    putenv('INTEGRATIONS_KEY=base64:'.base64_encode(random_bytes(32)));
    putenv('OPENAI_API_KEY=');putenv('CONTENT_STORAGE=file');
    $settings = new AiSettings($directory);
    $settings->update(['api_key' => 'sk-test-123456789012345678901234567890', 'model'=>'gpt-4.1-mini', 'max_output_tokens'=>256, 'monthly_token_limit'=>50000]);
    $cfg = new ConfigRepository($root);
    $cfg->set('database.default', 'sqlite');
    $cfg->set('database.connections.sqlite.database', ':memory:');
    $db = new DatabaseManager($cfg);$pdo=$db->connection();
    foreach (['0001_initial_schema','0031_ai_chat','0032_ai_content_editor','0034_ai_knowledge_base','0036_ai_support_center'] as $migration) {
        $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration.'.sql'));
    }
    $kb = new AiKnowledgeBase($db, new ContentEntryRepository($directory, $db), new ContentTypeRepository($directory, $db));
    $public = $kb->saveDocument(['title'=>'Как создать сайт', 'slug'=>'support-public-test',
        'body'=>'Чтобы создать сайт, сначала создайте страницу в CMS и нажмите Сохранить, затем Опубликовать.',
        'locale'=>'ru', 'visibility'=>'public', 'status'=>'published']);
    $private = $kb->saveDocument(['title'=>'Внутренний пароль сервера', 'slug'=>'support-internal-test',
        'body'=>'Секретный механизм восстановления: парольслужбы и приватныйключ',
        'locale'=>'ru', 'visibility'=>'internal', 'status'=>'published']);
    $seen = []; $failing = false;
    $provider = new class($seen, $failing) extends OpenAiClient {
        public function __construct(private array &$seen, private bool &$failing) {}
        public function complete(string $key, string $model, array $input, int $maxOutput, ?callable $onDelta = null): array {
            if ($this->failing) { $this->failing = false; throw new AiProviderException('Fixture timeout', 503); }
            $this->seen[] = $input;
            if (!str_starts_with($key, 'sk-test-')) throw new RuntimeException('Credential missing');
            return ['text'=>'Создайте страницу и опубликуйте её [[S1]].','usage'=>['input_tokens'=>29,'output_tokens'=>17], 'response_id'=>'response-support-test'];
        }
    };
    $service = new AiSupportCenter($db, $kb, $settings, $provider);
    $input = ['name'=>'Customer', 'email'=>'customer@example.org', 'subject'=>'Создание сайта',
        'message'=>'Как создать страницу и опубликовать сайт?', 'locale'=>'ru'];
    $first = $service->create($input);
    $check(strlen($first['access_token'])===43 && $first['status']==='open', 'Guest ticket created with unguessable access token');
    $row = $pdo->query('SELECT * FROM ce_support_tickets')->fetch(PDO::FETCH_ASSOC);
    $check(!str_contains(json_encode($row), $first['access_token']) && $row['access_hash']===hash('sha256', $first['access_token']), 'Only SHA-256 capability hash stored in SQL');
    $reject(fn()=>$service->visitorView($first['ticket_id'], 'wrong'), 'Incorrect capability denies visitor access');
    $view = $service->visitorView($first['ticket_id'], $first['access_token']);
    $check(count($view['messages'])===1 && $view['messages'][0]['role']==='visitor' && !array_key_exists('email', $view), 'Visitor receives scoped sanitized transcript');
    $service->visitorReply($first['ticket_id'], $first['access_token'], 'Продолжение моего вопроса');
    $check(count($service->visitorView($first['ticket_id'], $first['access_token'])['messages'])===2, 'Guest replies persisted in the existing ticket');
    $check($service->tickets('open')['total']===1, 'Operator queue returns actual SQL ticket');
    $reject(fn()=>$service->update($first['ticket_id'],'operator',['expected_revision'=>1,'status'=>'resolved']), 'Old revision cannot overwrite new visitor message');
    $reject(fn()=>$service->update($first['ticket_id'],'operator',['expected_revision'=>2,'assigned_to'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc']), 'Unknown assignee rejected');
    $operator='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $pdo->prepare('INSERT INTO ce_users (id,email,name,password_hash,status) VALUES (?,?,?,?,?)')->execute([$operator,'operator@example.org','Operator','no-login-test','active']);
    $changed = $service->update($first['ticket_id'], $operator, ['expected_revision'=>2,'assigned_to'=>$operator,'priority'=>'high','status'=>'open']);
    $check($changed['assigned_to']===$operator && $changed['priority']==='high' && (int)$changed['revision']===3, 'Active operator assignment and priority update use revision CAS');
    $service->operatorReply($first['ticket_id'], $operator, 'Оператор подготовил ответ.');
    $check($service->visitorView($first['ticket_id'], $first['access_token'])['messages'][2]['content']==='Оператор подготовил ответ.', 'Visitor can read an operator reply');
    $check($service->agentView($first['ticket_id'])['status']==='waiting', 'Operator reply updates status');
    $check($service->requestHuman($first['ticket_id'], $first['access_token'])['needs_human'], 'Visitor can request human handoff');
    $draft = $service->draft($operator,'Как создать сайт?','ru');
    $check(count($draft['sources'])===1 && $draft['sources'][0]['id']===$public['id'] && str_contains($draft['text'],'[1]'), 'Operator receives a real grounded public-only AI draft');
    $check(!str_contains(json_encode($seen),'приватныйключ') && !str_contains(json_encode($seen),'парольслужбы'), 'Private documents excluded from provider context');
    $none = $service->draft($operator,'парольслужбы','ru');
    $check($none['sources']===[] && count($seen)===1, 'Private-only query cannot trigger OpenAI');
    $check((int)$pdo->query('SELECT SUM(reserved_tokens) FROM ce_ai_usage')->fetchColumn()===0, 'AI draft does not leak token reservations');
    $failing = true;
    $reject(fn()=>$service->draft($operator,'Как создать сайт?','ru'), 'Provider failure is propagated for manual agent draft');
    $check((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_editor_jobs')->fetchColumn()===0,'Provider failure releases token lease');
    putenv('SUPPORT_AUTO_REPLY_ENABLED=1');
    $second = $service->create($input);
    $service->autoReply($second['ticket_id']);
    $aiTranscript = $service->visitorView($second['ticket_id'], $second['access_token']);
    $service->autoReply($second['ticket_id']); // Retries must not duplicate messages or spend additional tokens.
    $check(count($service->visitorView($second['ticket_id'], $second['access_token'])['messages']) === 2, 'Repeated AI job is idempotent after first delivery');
    $check(count($aiTranscript['messages'])===2 && $aiTranscript['messages'][1]['role']==='ai' && $aiTranscript['status']==='waiting', 'Automated public response is delivered from live KB evidence');
    $kb->saveDocument(['title'=>$public['title'],'slug'=>$public['slug'], 'body'=>'Весь предыдущий текст устарел и заменён.',
        'locale'=>'ru', 'visibility'=>'public','status'=>'published','expected_revision'=>(int)$public['revision']],$public['id']);
    $redacted = $service->visitorView($second['ticket_id'], $second['access_token']);
    $check($redacted['messages'][1]['sources']===[] && !str_contains($redacted['messages'][1]['content'],'Создайте страницу'), 'Stale AI answers are redacted on every visitor read');
    $pdo->prepare('INSERT INTO ce_support_messages (id,ticket_id,role,content,sources_json,created_at) VALUES (?,?,?,?,?,?)')
        ->execute(['eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',$second['ticket_id'],'ai','Orphaned unsourced AI claim','[]',time()*1000000]);
    $history = $service->visitorView($second['ticket_id'], $second['access_token']);
    $check(!str_contains(json_encode($history, JSON_UNESCAPED_UNICODE), 'Orphaned unsourced AI claim'), 'Legacy AI replies without valid citations remain private');
    $service->update($first['ticket_id'], $operator, ['expected_revision'=>5,'status'=>'closed']);
    $reject(fn()=>$service->visitorReply($first['ticket_id'],$first['access_token'],'Late reply'), 'Closed tickets cannot accept messages');
    $reject(fn()=>$service->requestHuman($first['ticket_id'],$first['access_token']), 'Closed tickets cannot be reopened by unauthorized visitors');
    putenv('SUPPORT_PUBLIC_ENABLED=0');
    $reject(fn()=>$service->create($input), 'Public feature flag blocks new guest support submissions');
    echo "AI Support Center SQL acceptance PASSED\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($directory);
    foreach ($env as $name=>$value) putenv($name.'='.($value===false?'':$value));
}
