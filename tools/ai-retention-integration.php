#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Transactional retention acceptance against actual PHP PDO SQLite. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'CajeerEngine\\')) return;
    $path = $root.'/core/'.str_replace('\\', '/', substr($class, strlen('CajeerEngine\\'))).'.php';
    if (is_file($path)) require_once $path;
});
use CajeerEngine\AI\AiDataRetention;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

$check = static function (bool $yes, string $label): void {
    if (!$yes) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
};
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL pdo_sqlite is required\n"); exit(1); }
    echo "SKIP: pdo_sqlite not installed\n";
    exit(0);
}
$config = new ConfigRepository($root);
$config->set('database.default', 'sqlite');
$config->set('database.connections.sqlite.database', ':memory:');
$db = new DatabaseManager($config);
$pdo = $db->connection();
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (['0031_ai_chat', '0032_ai_content_editor', '0033_ai_translation_studio', '0035_ai_knowledge_chat', '0036_ai_support_center', '0038_ai_actions'] as $migration) {
    $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration.'.sql'));
}
$now = 1800000000;
$old = $now - 100 * 86400;
$fresh = $now - 86400;
$uuid = static fn (int $number): string => sprintf('00000000-0000-4000-8000-%012d', $number);
foreach ([[$uuid(1),$old,0,0], [$uuid(2),$fresh,0,0], [$uuid(3),$old,$now+300,99]] as [$id,$ts,$busy,$reserved]) {
    $pdo->prepare('INSERT INTO ce_ai_conversations (id,user_id,title,created_at,updated_at,busy_until,reserved_tokens,total_input_tokens,total_output_tokens) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$uuid(20),'Chat',$ts,$ts,$busy,$reserved,0,0]);
    $pdo->prepare('INSERT INTO ce_ai_messages (id,conversation_id,role,content,created_at) VALUES (?,?,?,?,?)')
        ->execute([$uuid((int)substr($id,-1)+10),$id,'user','Sensitive chat '.$id,$ts]);
}
foreach ([[$uuid(4),$old],[$uuid(5),$fresh]] as [$id,$ts]) {
    $pdo->prepare('INSERT INTO ce_ai_kb_conversations (id,user_id,title,visibility,locale,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id,$uuid(20),'RAG','public','ru',$ts,$ts]);
    $pdo->prepare('INSERT INTO ce_ai_kb_messages (id,conversation_id,role,content,sources_json,created_at,input_tokens,output_tokens) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$uuid((int)substr($id,-1)+30),$id,'user','Secret RAG','[]',$ts,0,0]);
}
foreach ([[$uuid(6),'closed',$old],[$uuid(7),'open',$old],[$uuid(8),'closed',$fresh]] as [$id,$status,$ts]) {
    $pdo->prepare('INSERT INTO ce_support_tickets (id,access_hash,name,email,subject,locale,status,priority,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,str_repeat('a',64),'Client','client@example.org','Support','ru',$status,'normal',1,$ts,$ts]);
    $pdo->prepare('INSERT INTO ce_support_messages (id,ticket_id,role,content,sources_json,created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$uuid((int)substr($id,-1)+40),$id,'visitor','Sensitive support','[]',$ts]);
}
$pdo->prepare('INSERT INTO ce_ai_editor_proposals (id,user_id,type_handle,target_field,field_type,mode,original_hash,proposed_text,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
    ->execute([$uuid(60),$uuid(20),'page','title','text','rewrite',str_repeat('a',64),'Expired proposal',$old,$old]);
$pdo->prepare('INSERT INTO ce_ai_translation_proposals (id,user_id,type_handle,source_entry_id,source_revision,source_hash,target_locale,translated_title,translated_data,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$uuid(61),$uuid(20),'page',$uuid(62),1,str_repeat('b',64),'en','Expired translation','{}',$old,$old]);
$pdo->prepare('INSERT INTO ce_ai_usage (user_id,period,used_tokens,reserved_tokens) VALUES (?,?,?,?)')
    ->execute([$uuid(20),'2026-11',152,99]);
// Active RAG generation protects aged conversation; retention can reclaim it after lease expiry.
$pdo->prepare('INSERT INTO ce_ai_editor_jobs (id,user_id,period,reserved_tokens,expires_at) VALUES (?,?,?,?,?)')
    ->execute([$uuid(65),$uuid(20),'2026-11',100,$now+180]);
$service = new AiDataRetention($db);
$guarded = $service->run(90, 100, false, $now);
$check($guarded['deleted']['knowledge_chat'] === 0, 'Active RAG generation prevents retention of conversation history');
$pdo->prepare('DELETE FROM ce_ai_editor_jobs WHERE id=?')->execute([$uuid(65)]);
try { $service->run(1, 10, true, $now); throw new RuntimeException('Invalid retention age accepted.'); }
catch (InvalidArgumentException) { $check(true, 'Retention below seven days rejected'); }
$preview = $service->run(90, 100, false, $now);
$check($preview['deleted']['closed_support'] === 1 && $preview['deleted']['chat'] === 1 && $preview['deleted']['editor_proposals'] === 1, 'Dry run identifies only eligible data');
$check((int)$pdo->query('SELECT COUNT(*) FROM ce_support_tickets')->fetchColumn() === 3, 'Dry run has no side effects');
// Failure must roll back the transcript removal together with the parent.
$pdo->exec("CREATE TRIGGER deny_retention BEFORE DELETE ON ce_ai_messages BEGIN SELECT RAISE(ABORT, 'simulated DB error'); END");
try { $service->run(90, 100, true, $now); throw new RuntimeException('DB error did not prevent retention'); }
catch (PDOException) { $check(true, 'Database failure aborts transaction'); }
$check((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_conversations')->fetchColumn() === 3
    && (int)$pdo->query('SELECT COUNT(*) FROM ce_ai_messages')->fetchColumn() === 3, 'Parent and transcript retained on rollback');
$pdo->exec('DROP TRIGGER deny_retention');
$removed = $service->run(90, 100, true, $now);
$check($removed['deleted'] === ['chat'=>1,'knowledge_chat'=>1,'closed_support'=>1,'editor_proposals'=>1,'translation_proposals'=>1,'action_proposals'=>0], 'All eligible records were deleted');
$check((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_messages')->fetchColumn() === 2
    && (int)$pdo->query('SELECT COUNT(*) FROM ce_ai_kb_messages')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM ce_support_messages')->fetchColumn() === 2, 'Child transcripts cascade or delete atomically');
$check((int)$pdo->query('SELECT COUNT(*) FROM ce_support_tickets WHERE status = "open"')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM ce_ai_conversations WHERE busy_until > 0')->fetchColumn() === 1, 'Live support and busy conversations preserved');
$check((int)$pdo->query('SELECT SUM(used_tokens) FROM ce_ai_usage')->fetchColumn() === 152, 'Historical token usage remains for accounting');
$again = $service->run(90, 100, true, $now);
$check(array_sum($again['deleted']) === 0, 'Repeated cleanup is idempotent');
echo "AI retention integration PASSED\n";
