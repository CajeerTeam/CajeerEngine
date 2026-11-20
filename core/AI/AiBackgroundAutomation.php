<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Queue\JobContext;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Queue\QueueOptions;
use CajeerEngine\Support\Uuid;
use PDO;

/** Durable, owner-isolated AI automation. The scheduler never calls the provider directly. */
final readonly class AiBackgroundAutomation
{
    public function __construct(
        private DatabaseManager $db,
        private ManagedQueueDriverInterface $queue,
        private AiChat $chat,
    ) {}

    /** Operator kill switch: disable new and not-yet-started AI calls without disabling the CMS. */
    private static function enabledGlobally(): bool
    {
        $flag=getenv('AI_AUTOMATION_ENABLED');
        return $flag===false || !in_array(strtolower(trim($flag)), ['0', 'false', 'off', 'no'], true);
    }

    public function list(string $userId): array
    {
        $stmt = $this->db->connection()->prepare('SELECT id, title, prompt, interval_minutes, enabled, revision, next_run_at, created_at, updated_at FROM ce_ai_automations WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 25');
        $stmt->execute([$userId]);
        return array_map(self::normalise(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(string $userId, string $id): array
    {
        $stmt = $this->db->connection()->prepare('SELECT id, title, prompt, interval_minutes, enabled, revision, next_run_at, created_at, updated_at FROM ce_ai_automations WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \DomainException('AI automation not found.');
        return self::normalise($row);
    }

    public function create(string $userId, array $body): array
    {
        if (!self::enabledGlobally()) throw new \DomainException('AI automation is disabled by the operator.');
        self::allowed($body, ['title', 'prompt', 'interval_minutes', 'enabled']);
        [$title, $prompt, $minutes, $enabled] = self::validate($body);
        $id = Uuid::v4(); $now = time();
        $this->db->transaction(function (PDO $pdo) use ($userId, $title, $prompt, $minutes, $enabled, $id, $now): void {
            if (!$this->lockActiveOwner($pdo, $userId)) throw new \DomainException('Account is no longer active.');
            if (!$this->canRun($userId)) throw new \DomainException('Automation privileges have been revoked.');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM ce_ai_automations WHERE user_id = ? AND deleted_at IS NULL');
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() >= 20) throw new \DomainException('Maximum 20 AI automations per administrator.');
            $pdo->prepare('INSERT INTO ce_ai_automations (id, user_id, title, prompt, interval_minutes, enabled, revision, next_run_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)')
                ->execute([$id, $userId, $title, $prompt, $minutes, (int)$enabled, $now + $minutes * 60, $now, $now]);
        });
        return $this->get($userId, $id);
    }

    public function update(string $userId, string $id, array $body): array
    {
        self::allowed($body, ['title','prompt','interval_minutes','enabled','revision']);
        if (!isset($body['revision']) || !is_int($body['revision'])) throw new \InvalidArgumentException('Current automation revision is required.');
        return $this->db->transaction(function (PDO $pdo) use ($userId,$id,$body): array {
            if (!$this->canRun($userId)) throw new \DomainException('Automation privileges have been revoked.');
            $row = $this->lockOwned($pdo, $userId, $id);
            if ((int)$row['revision'] !== $body['revision']) throw new \DomainException('Automation was updated elsewhere. Refresh before editing.');
            $current = self::normalise($row);
            [$title,$prompt,$minutes,$enabled] = self::validate(array_merge($current, $body));
            $now = time();
            // Preserve the slot when only the prompt/title changes. Resuming or changing
            // the frequency starts a fresh interval, without replaying missed slots.
            $next = $minutes !== $current['interval_minutes'] || ($enabled && !$current['enabled'])
                ? $now + $minutes * 60 : (int)$current['next_run_at'];
            $stmt = $pdo->prepare('UPDATE ce_ai_automations SET title = ?, prompt = ?, interval_minutes = ?, enabled = ?, revision = revision + 1, next_run_at = ?, updated_at = ? WHERE id = ? AND user_id = ? AND revision = ? AND deleted_at IS NULL');
            $stmt->execute([$title,$prompt,$minutes,(int)$enabled,$next,$now,$id,$userId,$body['revision']]);
            if ($stmt->rowCount() !== 1) throw new \DomainException('Automation update conflict.');
            if (!$enabled) $pdo->prepare("UPDATE ce_ai_automation_runs SET status = 'canceled', finished_at = ? WHERE automation_id = ? AND status = 'queued'")->execute([$now,$id]);
            return $this->get($userId, $id);
        });
    }

    public function delete(string $userId, string $id): void
    {
        $this->db->transaction(function (PDO $pdo) use ($userId,$id): void {
            $this->lockOwned($pdo, $userId, $id);
            $now=time();
            $pdo->prepare("UPDATE ce_ai_automations SET deleted_at = ?, enabled = 0, prompt = '[deleted]', updated_at = ?, revision = revision + 1 WHERE id = ? AND user_id = ?")->execute([$now,$now,$id,$userId]);
            $pdo->prepare("UPDATE ce_ai_automation_runs SET status = 'canceled', finished_at = ? WHERE automation_id = ? AND status = 'queued'")->execute([$now,$id]);
            // Deleting a schedule must not leave live prompt copies in soft-deleted rows.
            // Running jobs already hold their own in-memory snapshot and cannot be recalled.
            $pdo->prepare("UPDATE ce_ai_automation_runs SET prompt_snapshot = '[deleted]' WHERE automation_id = ?")->execute([$id]);
        });
    }

    public function runs(string $userId, string $automationId): array
    {
        $this->get($userId, $automationId);
        $stmt=$this->db->connection()->prepare('SELECT id, scheduled_for, status, conversation_id, queued_at, started_at, finished_at, error FROM ce_ai_automation_runs WHERE automation_id = ? AND user_id = ? ORDER BY queued_at DESC, id DESC LIMIT 100');
        $stmt->execute([$automationId,$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Queue a one-off execution even if recurring scheduling is paused. */
    public function runNow(string $userId, string $automationId): array
    {
        if (!self::enabledGlobally()) throw new \DomainException('AI automation is disabled by the operator.');
        $id=Uuid::v4();
        $this->db->transaction(function (PDO $pdo) use ($userId,$automationId,$id): void {
            if (!$this->lockActiveOwner($pdo, $userId)) throw new \DomainException('Account is no longer active.');
            $row=$this->lockOwned($pdo,$userId,$automationId);
            if (!$this->canRun($userId)) throw new \DomainException('Automation privileges have been revoked.');
            $check=$pdo->prepare("SELECT COUNT(*) FROM ce_ai_automation_runs WHERE automation_id = ? AND status IN ('queued','running')");
            $check->execute([$automationId]);
            if ((int)$check->fetchColumn() > 0) throw new \DomainException('This automation already has a pending execution.');
            $start=time()-86400;
            $limit=$pdo->prepare('SELECT COUNT(*) FROM ce_ai_automation_runs WHERE user_id = ? AND queued_at >= ?');
            $limit->execute([$userId,$start]);
            if ((int)$limit->fetchColumn() >= 100) throw new \DomainException('Daily AI automation execution limit reached.');
            $pdo->prepare('INSERT INTO ce_ai_automation_runs (id, automation_id, user_id, prompt_snapshot, scheduled_for, status, queued_at) VALUES (?, ?, ?, ?, NULL, ?, ?)')
                ->execute([$id,$automationId,$userId,$row['prompt'],'queued',time()]);
        });
        // A committed run must remain recoverable if Redis, PostgreSQL or the file
        // queue becomes unavailable between the insert and the transport push.
        // The scheduler re-offers it with exactly the same idempotency key.
        $enqueued = false;
        try { $this->enqueue($id); $enqueued = true; }
        catch (\Throwable) { /* The scheduler will safely re-offer the committed run. */ }
        if ($enqueued) {
            try { $this->markOffered($id); }
            catch (\Throwable) { /* Transport succeeded; the re-offer is idempotent. */ }
        }
        return ['id'=>$id,'status'=>'queued','enqueued'=>$enqueued];
    }

    /** Schedule a bounded batch, reconcile dead transports, fairly re-offer unclaimed runs. */
    public function dispatch(int $batch = 50): array
    {
        $now=time(); $claimed=0;
        // Neither an interrupted paid request nor a run stranded in a dead queue
        // is replayed. A late delivery sees a terminal status and does nothing.
        $interrupted=$this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status = 'failed', finished_at = ?, error = 'Execution interrupted; provider may have charged' WHERE status = 'running' AND started_at < ?");
        $interrupted->execute([$now,$now-1800]);
        $expired=$this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status = 'failed', finished_at = ?, error = 'Queue delivery deadline exceeded; no automatic replay' WHERE status = 'queued' AND queued_at < ?");
        $expired->execute([$now,$now-86400]);
        $deadScanError = 0; $failedJobs = 0;
        try { $failedJobs = $this->reconcileFailedJobs(); }
        catch (\Throwable) { $deadScanError = 1; /* DB-backed runs still proceed; monitor degraded transport. */ }
        // Do not filter inactive users before loading the row: deactivated owners
        // must have recurring schedules disabled instead of silently accumulating slots.
        $stmt=$this->db->connection()->prepare('SELECT id, user_id FROM ce_ai_automations WHERE enabled = 1 AND deleted_at IS NULL AND next_run_at <= ? ORDER BY next_run_at ASC LIMIT '.max(1,min(100,$batch)));
        $stmt->execute([$now]);
        foreach (self::enabledGlobally() ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $candidate) {
            $id=(string)$candidate['id']; $owner=(string)$candidate['user_id'];
            $created = $this->db->transaction(function (PDO $pdo) use ($id,$owner,$now): bool {
                // All manual and scheduled capacity decisions serialize on the owner
                // row. This is essential on multi-worker PostgreSQL/MySQL deployments.
                $active=$this->lockActiveOwner($pdo, $owner);
                $row=$pdo->prepare('SELECT id, user_id, prompt, next_run_at, interval_minutes, enabled FROM ce_ai_automations WHERE id = ? AND deleted_at IS NULL');
                $row->execute([$id]); $item=$row->fetch(PDO::FETCH_ASSOC);
                if (!is_array($item) || (string)$item['user_id']!==$owner || !(int)$item['enabled'] || (int)$item['next_run_at']>$now) return false;
                if (!$active || !$this->canRun($owner)) {
                    $pdo->prepare('UPDATE ce_ai_automations SET enabled = 0, revision = revision + 1, updated_at = ? WHERE id = ? AND enabled = 1')->execute([$now,$id]);
                    $pdo->prepare("UPDATE ce_ai_automation_runs SET status = 'canceled', finished_at = ? WHERE automation_id = ? AND status = 'queued'")->execute([$now,$id]);
                    return false;
                }
                $slot=(int)$item['next_run_at']; $minutes=(int)$item['interval_minutes'];
                $next=max($now,$slot)+$minutes*60;
                $claim=$pdo->prepare('UPDATE ce_ai_automations SET next_run_at = ?, updated_at = ? WHERE id = ? AND enabled = 1 AND deleted_at IS NULL AND next_run_at = ?');
                $claim->execute([$next,$now,$id,$slot]);
                if ($claim->rowCount()!==1) return false;
                $busy=$pdo->prepare("SELECT COUNT(*) FROM ce_ai_automation_runs WHERE automation_id = ? AND status IN ('queued','running')");
                $busy->execute([$id]);
                if ((int)$busy->fetchColumn() > 0) return false;
                $count=$pdo->prepare('SELECT COUNT(*) FROM ce_ai_automation_runs WHERE user_id = ? AND queued_at >= ?');
                $count->execute([$owner,$now-86400]);
                if ((int)$count->fetchColumn() >= 100) return false;
                $pdo->prepare('INSERT INTO ce_ai_automation_runs (id, automation_id, user_id, prompt_snapshot, scheduled_for, status, queued_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([Uuid::v4(),$id,$owner,$item['prompt'],$slot,'queued',$now]);
                return true;
            });
            if ($created) ++$claimed;
        }
        // Round-robin-ish fairness even if the oldest 100 jobs never leave 'queued'.
        // last_offer_at is updated on success AND failure, so later jobs get a turn.
        $pending=$this->db->connection()->query("SELECT id FROM ce_ai_automation_runs WHERE status = 'queued' ORDER BY COALESCE(last_offer_at,0) ASC, queued_at ASC, id ASC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
        $enqueued=0; $errors=0;
        foreach (self::enabledGlobally() ? $pending : [] as $runId) {
            try { $this->enqueue((string)$runId); ++$enqueued; }
            catch (\Throwable) { ++$errors; }
            finally { $this->markOffered((string)$runId); }
        }
        // Past completed prompts are no longer needed: retain run metadata without
        // leaving a long-lived copy of sensitive user content in execution history.
        // Retention work is bounded so a long-lived installation never locks all
        // historical rows in one scheduler tick. Keep this portable across all DBs.
        $select=$this->db->connection()->prepare("SELECT id FROM ce_ai_automation_runs WHERE status IN ('succeeded','failed','canceled') AND finished_at < ? AND prompt_snapshot NOT IN ('[expired]','[deleted]') ORDER BY finished_at, id LIMIT 250");
        $select->execute([$now-30*86400]);
        $ids=$select->fetchAll(PDO::FETCH_COLUMN);
        $redacted=0;
        if ($ids !== []) {
            $placeholders=implode(',', array_fill(0,count($ids),'?'));
            $prune=$this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET prompt_snapshot = '[expired]' WHERE id IN ($placeholders) AND status IN ('succeeded','failed','canceled') AND prompt_snapshot NOT IN ('[expired]','[deleted]')");
            $prune->execute($ids); $redacted=$prune->rowCount();
        }
        $counts=$this->db->connection()->query("SELECT status, COUNT(*) AS total, MIN(queued_at) AS oldest FROM ce_ai_automation_runs WHERE status IN ('queued','running') GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        $backlog=['queued'=>0,'running'=>0,'oldest_queued_age_seconds'=>0];
        foreach ($counts as $row) {
            $status=(string)$row['status']; $backlog[$status]=(int)$row['total'];
            if ($status==='queued') $backlog['oldest_queued_age_seconds']=max(0,$now-(int)$row['oldest']);
        }
        return ['ok'=>$errors===0 && $deadScanError===0,'enabled'=>self::enabledGlobally(),'created'=>$claimed,'offered'=>$enqueued,'enqueue_errors'=>$errors,
            'dead_queue_jobs'=>$failedJobs,'dead_queue_scan_errors'=>$deadScanError,'interrupted'=>$interrupted->rowCount(),'expired_queued'=>$expired->rowCount(),'redacted'=>$redacted,'backlog'=>$backlog];
    }

    /** Reconcile a queue item that has exhausted its sole permitted delivery. */
    private function reconcileFailedJobs(): int
    {
        $count=0;
        foreach ($this->queue->failed() as $job) {
            if (($job['name']??null)!=='ai.automation.execute') continue;
            $payload=$job['payload']??null;
            if (is_string($payload)) $payload=json_decode($payload,true);
            if (!is_array($payload) || !is_string($payload['run_id']??null)) continue;
            $runId=$payload['run_id'];
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$runId)) continue;
            // Do not trust job payload alone: its id must be the deterministic key
            // for this precise run (prevents another queue client spoofing failures).
            if (($job['id']??'')!==QueueOptions::id('default','ai.automation.execute',[
                'run_id'=>$runId,'_idempotency_key'=>'ai.automation.'.$runId,'_max_attempts'=>1,
            ])) continue;
            $stmt=$this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status = 'failed', finished_at = ?, error = 'Queue delivery exhausted; no automatic replay' WHERE id = ? AND status = 'queued'");
            $stmt->execute([time(),$runId]); $count+=$stmt->rowCount();
        }
        return $count;
    }

    private function markOffered(string $runId): void
    {
        $this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET last_offer_at = ? WHERE id = ? AND status = 'queued'")->execute([time(),$runId]);
    }

    public function execute(string $runId, JobContext $context): void
    {
        // A forged/reused transport message must never start a billable operation.
        $expected = QueueOptions::id('default','ai.automation.execute',[
            'run_id'=>$runId,'_idempotency_key'=>'ai.automation.'.$runId,'_max_attempts'=>1,
        ]);
        if ($context->job->id !== $expected || $context->job->queue !== 'default'
            || $context->job->name !== 'ai.automation.execute'
            || ($context->job->payload['run_id'] ?? null) !== $runId
            || $context->job->maxAttempts !== 1) {
            throw new \DomainException('Invalid AI automation queue delivery.');
        }
        $item=$this->db->transaction(function(PDO $pdo)use($runId): ?array {
            $stmt=$pdo->prepare('SELECT r.id, r.automation_id, r.user_id, r.prompt_snapshot, r.status, r.scheduled_for, r.queued_at, a.user_id AS automation_owner, a.enabled, a.deleted_at, u.status AS user_status FROM ce_ai_automation_runs r JOIN ce_ai_automations a ON a.id = r.automation_id JOIN ce_users u ON u.id = r.user_id WHERE r.id = ?');
            $stmt->execute([$runId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['status']!=='queued') return null;
            // A worker may come back before the scheduler after a multi-day outage.
            // Enforce the delivery deadline here too, before any provider call.
            if ((int)$row['queued_at'] < time()-86400) {
                $pdo->prepare("UPDATE ce_ai_automation_runs SET status = 'failed', finished_at = ?, error = 'Queue delivery deadline exceeded; no automatic replay' WHERE id = ? AND status = 'queued'")->execute([time(),$runId]);
                return null;
            }
            // Queued executions are canceled when owner is deactivated or automation deleted.
            if (!self::enabledGlobally() || $row['deleted_at']!==null || $row['automation_owner']!==$row['user_id'] || $row['user_status']!=='active' || ($row['scheduled_for']!==null && !(int)$row['enabled']) || !$this->canRun((string)$row['user_id'])) {
                $pdo->prepare("UPDATE ce_ai_automation_runs SET status = 'canceled', finished_at = ? WHERE id = ? AND status = 'queued'")->execute([time(),$runId]);
                return null;
            }
            $claim=$pdo->prepare("UPDATE ce_ai_automation_runs SET status = 'running', started_at = ? WHERE id = ? AND status = 'queued'");
            $claim->execute([time(),$runId]);
            return $claim->rowCount()===1?$row:null;
        });
        if ($item===null) return;
        try {
            $context->heartbeat();
            // Permissions may have been revoked since the atomic queue claim. Check
            // once more immediately before entering the billable AI transaction.
            if (!self::enabledGlobally() || !$this->canRun((string)$item['user_id'])
                || !$this->stillAllowed((string)$item['automation_id'], (string)$item['user_id'])) {
                $this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status='canceled', finished_at=? WHERE id=? AND status='running'")
                    ->execute([time(),$runId]);
                return;
            }
            // No write tools or implicit privileges: automation is an ordinary owner-funded
            // AI conversation, charged through the existing token and dollar hard caps.
            $result=$this->chat->send((string)$item['user_id'], null, (string)$item['prompt_snapshot'], null, false, [], null, false, 'background_automation');
            $conversation=(string)($result['conversation_id']??$result['id']??'');
            if ($conversation==='') throw new \RuntimeException('AI run did not return a conversation.');
            // Record the committed conversation before checking the queue lease again:
            // a lost ACK must not turn a successful paid generation into an orphan.
            $saved=$this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status = 'succeeded', conversation_id = ?, finished_at = ?, error = NULL WHERE id = ? AND (status = 'running' OR (status = 'failed' AND error = 'Execution interrupted; provider may have charged'))");
            $saved->execute([$conversation,time(),$runId]);
            if ($saved->rowCount()!==1) throw new \RuntimeException('AI result cannot be linked to its run.');
        } catch (\Throwable $e) {
            $this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status = 'failed', finished_at = ?, error = ? WHERE id = ? AND status = 'running'")
                ->execute([time(),self::safeError($e),$runId]);
            // Never put a provider exception (which can include sensitive input)
            // into queue failed-job storage or worker API responses.
            throw new \RuntimeException('AI automation execution failed; see protected run status.');
        }
    }

    /** Recheck deletion/account state immediately before beginning the paid request. */
    private function stillAllowed(string $automationId, string $userId): bool
    {
        $stmt=$this->db->connection()->prepare('SELECT a.deleted_at, a.user_id, u.status FROM ce_ai_automations a JOIN ce_users u ON u.id = a.user_id WHERE a.id = ?');
        $stmt->execute([$automationId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && $row['deleted_at']===null && $row['user_id']===$userId && $row['status']==='active';
    }

    private function canRun(string $userId): bool
    {
        $active=$this->db->connection()->prepare('SELECT status FROM ce_users WHERE id = ?');
        $active->execute([$userId]);
        if ($active->fetchColumn() !== 'active') return false;
        $permissions=(new RoleRepository($this->db))->permissionsForUser($userId);
        return in_array('*',$permissions,true) || (in_array('content:read',$permissions,true) && in_array('content:write',$permissions,true));
    }

    /** Lock the owner across quota/capacity checks on server DBs. Caller is in a transaction. */
    private function lockActiveOwner(PDO $pdo, string $userId): bool
    {
        $sql='SELECT status FROM ce_users WHERE id = ?';
        if (in_array($this->db->driver(),['mysql','pgsql'],true)) $sql.=' FOR UPDATE';
        $stmt=$pdo->prepare($sql); $stmt->execute([$userId]);
        return $stmt->fetchColumn()==='active';
    }

    private function enqueue(string $id): void
    {
        $this->queue->push('ai.automation.execute', ['run_id'=>$id,'_idempotency_key'=>'ai.automation.'.$id,'_max_attempts'=>1]);
    }

    private static function safeError(\Throwable $error): string
    {
        return match (true) {
            $error instanceof AiProviderException => 'AI provider unavailable or generation failed',
            $error instanceof \DomainException => 'AI settings, owner permissions or spending limit prevent this run',
            default => 'Automation failed; inspect protected application logs',
        };
    }

    private function lockOwned(PDO $pdo,string $userId,string $id): array
    {
        $sql='SELECT * FROM ce_ai_automations WHERE id = ? AND user_id = ? AND deleted_at IS NULL';
        if (in_array($this->db->driver(),['mysql','pgsql'],true)) $sql.=' FOR UPDATE';
        $stmt=$pdo->prepare($sql); $stmt->execute([$id,$userId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \DomainException('AI automation not found.');
        return $row;
    }

    private static function normalise(array $row): array
    {
        foreach (['interval_minutes','revision','next_run_at','created_at','updated_at'] as $name) $row[$name]=(int)$row[$name];
        $row['enabled']=(bool)$row['enabled'];
        return $row;
    }

    private static function allowed(array $body,array $fields): void
    {
        if (array_diff(array_keys($body),$fields)) throw new \InvalidArgumentException('Unknown automation field.');
    }

    private static function validate(array $body): array
    {
        $title=$body['title']??null; $prompt=$body['prompt']??null; $minutes=$body['interval_minutes']??null; $enabled=$body['enabled']??true;
        if (!is_string($title) || trim($title)==='' || strlen($title)>160 || !preg_match('//u',$title)) throw new \InvalidArgumentException('Title must be UTF-8 and 1–160 bytes.');
        if (!is_string($prompt) || trim($prompt)==='' || strlen($prompt)>12000 || !preg_match('//u',$prompt)) throw new \InvalidArgumentException('Prompt must be UTF-8 and 1–12000 bytes.');
        if (!is_int($minutes) || $minutes<60 || $minutes>10080) throw new \InvalidArgumentException('Interval must be 60–10080 minutes.');
        if (!is_bool($enabled)) throw new \InvalidArgumentException('enabled must be boolean.');
        return [trim($title),trim($prompt),$minutes,$enabled];
    }
}
