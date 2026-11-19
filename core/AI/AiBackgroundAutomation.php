<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Queue\JobContext;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
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
        self::allowed($body, ['title', 'prompt', 'interval_minutes', 'enabled']);
        [$title, $prompt, $minutes, $enabled] = self::validate($body);
        $id = Uuid::v4(); $now = time();
        $this->db->transaction(function (PDO $pdo) use ($userId, $title, $prompt, $minutes, $enabled, $id, $now): void {
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
            $pdo->prepare('UPDATE ce_ai_automations SET deleted_at = ?, enabled = 0, updated_at = ?, revision = revision + 1 WHERE id = ? AND user_id = ?')->execute([$now,$now,$id,$userId]);
            $pdo->prepare("UPDATE ce_ai_automation_runs SET status = 'canceled', finished_at = ? WHERE automation_id = ? AND status = 'queued'")->execute([$now,$id]);
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
        $id=Uuid::v4();
        $this->db->transaction(function (PDO $pdo) use ($userId,$automationId,$id): void {
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
        $this->enqueue($id);
        return ['id'=>$id,'status'=>'queued'];
    }

    /** Called under the existing per-slot scheduler lock. Concurrent hosts also use CAS. */
    public function dispatch(int $batch = 50): array
    {
        $now=time(); $claimed=0;
        // A lost PHP worker must never trigger an automatic *second* billable request.
        $this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status = 'failed', finished_at = ?, error = 'Execution interrupted; no automatic replay' WHERE status = 'running' AND started_at < ?")
            ->execute([$now,$now-1800]);
        $stmt=$this->db->connection()->prepare('SELECT a.id FROM ce_ai_automations a INNER JOIN ce_users u ON u.id = a.user_id AND u.status = ? WHERE a.enabled = 1 AND a.deleted_at IS NULL AND a.next_run_at <= ? ORDER BY a.next_run_at ASC LIMIT '.max(1,min(100,$batch)));
        $stmt->execute(['active',$now]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $created = $this->db->transaction(function (PDO $pdo) use ($id,$now): bool {
                $row=$pdo->prepare('SELECT id, user_id, prompt, next_run_at, interval_minutes, enabled FROM ce_ai_automations WHERE id = ? AND deleted_at IS NULL');
                $row->execute([$id]); $item=$row->fetch(PDO::FETCH_ASSOC);
                if (!is_array($item) || !(int)$item['enabled'] || (int)$item['next_run_at']>$now) return false;
                if (!$this->canRun((string)$item['user_id'])) {
                    $pdo->prepare('UPDATE ce_ai_automations SET enabled = 0, revision = revision + 1, updated_at = ? WHERE id = ?')->execute([$now,$id]);
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
                $count->execute([$item['user_id'],$now-86400]);
                if ((int)$count->fetchColumn() >= 100) return false;
                $pdo->prepare('INSERT INTO ce_ai_automation_runs (id, automation_id, user_id, prompt_snapshot, scheduled_for, status, queued_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([Uuid::v4(),$id,$item['user_id'],$item['prompt'],$slot,'queued',$now]);
                return true;
            });
            if ($created) ++$claimed;
        }
        // A DB commit followed by queue transport failure leaves a queued row;
        // re-offer the same stable job key on every scheduler tick (no duplicates).
        $pending=$this->db->connection()->query("SELECT id FROM ce_ai_automation_runs WHERE status = 'queued' ORDER BY queued_at ASC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
        $enqueued=0; $errors=0;
        foreach ($pending as $runId) {
            try { $this->enqueue((string)$runId); ++$enqueued; }
            catch (\Throwable) { ++$errors; }
        }
        return ['ok'=>$errors===0,'created'=>$claimed,'offered'=>$enqueued,'enqueue_errors'=>$errors];
    }

    public function execute(string $runId, JobContext $context): void
    {
        $item=$this->db->transaction(function(PDO $pdo)use($runId): ?array {
            $stmt=$pdo->prepare('SELECT r.id, r.automation_id, r.user_id, r.prompt_snapshot, r.status, a.enabled, a.deleted_at, u.status AS user_status FROM ce_ai_automation_runs r JOIN ce_ai_automations a ON a.id = r.automation_id JOIN ce_users u ON u.id = r.user_id WHERE r.id = ?');
            $stmt->execute([$runId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['status']!=='queued') return null;
            // Queued executions are canceled when owner is deactivated or automation deleted.
            if ($row['deleted_at']!==null || $row['user_status']!=='active' || !$this->canRun((string)$row['user_id'])) {
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
            // No write tools or implicit privileges: automation is an ordinary owner-funded
            // AI conversation, charged through the existing token and dollar hard caps.
            $result=$this->chat->send((string)$item['user_id'], null, (string)$item['prompt_snapshot'], null, false, [], null, false, 'background_automation');
            $context->heartbeat();
            $conversation=(string)($result['conversation_id']??$result['id']??'');
            if ($conversation==='') throw new \RuntimeException('AI run did not return a conversation.');
            $this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status = 'succeeded', conversation_id = ?, finished_at = ?, error = NULL WHERE id = ? AND status = 'running'")
                ->execute([$conversation,time(),$runId]);
        } catch (\Throwable $e) {
            $this->db->connection()->prepare("UPDATE ce_ai_automation_runs SET status = 'failed', finished_at = ?, error = ? WHERE id = ? AND status = 'running'")
                ->execute([time(),self::safeError($e),$runId]);
            // One provider attempt maximum: no unsafe retry after an unknown outcome.
            throw $e;
        }
    }

    private function canRun(string $userId): bool
    {
        $permissions=(new RoleRepository($this->db))->permissionsForUser($userId);
        return in_array('*',$permissions,true) || (in_array('content:read',$permissions,true) && in_array('content:write',$permissions,true));
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
