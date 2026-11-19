<?php
/** Integration acceptance of unmodified 0041 -> 0042 -> 0043 migrations on real PgSQL/MySQL services. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('AI_AUTOMATION_GA_FIXTURE') !== '1') {
    fwrite(STDERR,"Refusing to create test databases without AI_AUTOMATION_GA_FIXTURE=1\n");
    exit(2);
}

$root=dirname(__DIR__);
function uuid(): string {
    $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40); $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    $hex=bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s',substr($hex,0,8),substr($hex,8,4),substr($hex,12,4),substr($hex,16,4),substr($hex,20));
}
function check(bool $valid,string $why): void { if (!$valid) throw new RuntimeException($why); }
function apply(PDO $pdo,string $driver,string $root): void {
    foreach (['0041_ai_background_automation','0042_ai_automation_delivery_acceptance','0043_ai_automation_ga'] as $migration) {
        $sql=file_get_contents("$root/migrations/$driver/$migration.sql");
        if ($sql===false) throw new RuntimeException("Missing $migration");
        foreach (explode(';',$sql) as $statement) {
            $statement=trim($statement);
            if ($statement!=='' && !preg_match('/^--[^\n]*$/s',$statement)) $pdo->exec($statement);
        }
    }
}
function smoke(PDO $pdo,string $driver,string $root): void {
    $pdo->exec($driver==='pgsql' ? 'CREATE TABLE ce_users (id UUID PRIMARY KEY)' : 'CREATE TABLE ce_users (id CHAR(36) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    apply($pdo,$driver,$root);
    $user=uuid(); $automation=uuid(); $run=uuid();
    $pdo->prepare('INSERT INTO ce_users (id) VALUES (?)')->execute([$user]);
    $pdo->prepare('INSERT INTO ce_ai_automations (id,user_id,title,prompt,interval_minutes,next_run_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$automation,$user,'Test automation','Private sample',60,100,1,1]);
    $insert=$pdo->prepare("INSERT INTO ce_ai_automation_runs (id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at) VALUES (?,?,?,?,?,'queued',?)");
    $insert->execute([$run,$automation,$user,'Private sample',100,1]);
    try {
        $insert->execute([uuid(),$automation,$user,'Private sample',100,1]);
        throw new RuntimeException('Duplicate recurring slot was accepted');
    } catch (PDOException $e) {
        check(in_array((string)$e->getCode(),['23000','23505'],true),'Unexpected constraint error: '.$e->getMessage());
    }
    $claim=$pdo->prepare("UPDATE ce_ai_automation_runs SET status='running',started_at=? WHERE id=? AND status='queued'");
    $claim->execute([100,$run]); check($claim->rowCount()===1,'First worker claim failed');
    $claim->execute([101,$run]); check($claim->rowCount()===0,'Duplicate worker claim succeeded');
    $pdo->prepare("UPDATE ce_ai_automation_runs SET status='succeeded',finished_at=? WHERE id=? AND status='running'")->execute([102,$run]);
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE ce_ai_automations SET enabled=0,deleted_at=?,prompt='[deleted]' WHERE id=?")->execute([103,$automation]);
    $pdo->prepare("UPDATE ce_ai_automation_runs SET prompt_snapshot='[deleted]' WHERE automation_id=?")->execute([$automation]);
    $pdo->commit();
    $check=$pdo->prepare('SELECT prompt_snapshot FROM ce_ai_automation_runs WHERE id=?'); $check->execute([$run]);
    check($check->fetchColumn()==='[deleted]','Soft-deleted prompt persisted');
    $indexes=$driver==='pgsql' ? $pdo->query("SELECT indexname FROM pg_indexes WHERE tablename='ce_ai_automation_runs'")->fetchAll(PDO::FETCH_COLUMN) : $pdo->query('SHOW INDEX FROM ce_ai_automation_runs')->fetchAll(PDO::FETCH_ASSOC);
    $names=$driver==='pgsql' ? $indexes : array_column($indexes,'Key_name');
    check(in_array('idx_ai_automation_runs_retention',$names,true),'Retention index missing');
    check(in_array('idx_ai_automation_runs_pending_owner',$names,true),'Owner status index missing');
    echo "OK: $driver migrations 0041-0043, uniqueness, at-most-once claim, deletion redaction, indexes\n";
}
$targets=[
    'pgsql'=>['dsn'=>'pgsql:host=127.0.0.1;port=5432;dbname=cajeer_smoke','user'=>'cajeer','password'=>'release-smoke'],
    'mysql'=>['dsn'=>'mysql:host=127.0.0.1;port=3306;charset=utf8mb4','user'=>'root','password'=>'migration-fixture'],
];
foreach ($targets as $driver=>$target) {
    if (!in_array($driver,PDO::getAvailableDrivers(),true)) throw new RuntimeException("Missing pdo_$driver");
    $db=new PDO($target['dsn'],$target['user'],$target['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $schema='ce_ai_ga_'.bin2hex(random_bytes(6));
    // Always work in an isolated disposable schema/database. No persistent CMS data is touched.
    if ($driver==='pgsql') {
        $db->exec('CREATE SCHEMA '.$schema);
        try {
            $db->exec('SET search_path TO '.$schema);
            smoke($db,$driver,$root);
        } finally {
            $db->exec('SET search_path TO public');
            $db->exec('DROP SCHEMA '.$schema.' CASCADE');
        }
    } else {
        $db->exec('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $db->exec('USE '.$schema);
            smoke($db,$driver,$root);
        } finally {
            $db->exec('DROP DATABASE '.$schema);
        }
    }
}
