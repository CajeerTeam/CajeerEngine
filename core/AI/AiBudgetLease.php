<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use PDO;
use CajeerEngine\Database\DatabaseManager;

/** Shared monthly token-budget lease for ChatGPT editor and translation tasks. */
trait AiBudgetLease
{
    /** Locks the shared budget row before occupying the per-user editor generation lease. */
    private function reserve(string $uid, string $period, int $tokens, int $limit, string $job, int $lease, int $now): void
    {
        $this->db->transaction(function (PDO $pdo) use ($uid, $period, $tokens, $limit, $job, $lease, $now): void {
            $insert = $this->db->driver() === 'mysql'
                ? 'INSERT IGNORE INTO ce_ai_usage (user_id, period, used_tokens, reserved_tokens) VALUES (?, ?, 0, 0)'
                : 'INSERT INTO ce_ai_usage (user_id, period, used_tokens, reserved_tokens) VALUES (?, ?, 0, 0) ON CONFLICT (user_id, period) DO NOTHING';
            $pdo->prepare($insert)->execute([$uid, $period]);
            // Serialize all usage updates for this user and month on the parent budget row.
            $lock = 'SELECT used_tokens FROM ce_ai_usage WHERE user_id = ? AND period = ?';
            if ($this->db->driver() !== 'sqlite') $lock .= ' FOR UPDATE';
            $pdo->prepare($lock)->execute([$uid, $period]);
            $previous = $pdo->prepare('SELECT id, period, reserved_tokens, expires_at FROM ce_ai_editor_jobs WHERE user_id = ?');
            $previous->execute([$uid]);
            $pending = $previous->fetch(PDO::FETCH_ASSOC);
            if (is_array($pending)) {
                if ((int)$pending['expires_at'] >= $now) throw new \DomainException('Another AI editorial operation is still running.');
                $pdo->prepare('UPDATE ce_ai_usage SET reserved_tokens = CASE WHEN reserved_tokens >= ? THEN reserved_tokens - ? ELSE 0 END WHERE user_id = ? AND period = ?')
                    ->execute([(int)$pending['reserved_tokens'], (int)$pending['reserved_tokens'], $uid, $pending['period']]);
                $pdo->prepare('DELETE FROM ce_ai_editor_jobs WHERE id = ?')->execute([$pending['id']]);
            }
            $stmt = $pdo->prepare('UPDATE ce_ai_usage SET reserved_tokens = reserved_tokens + ? WHERE user_id = ? AND period = ? AND used_tokens + reserved_tokens + ? <= ?');
            // Bind the budget as an integer. In SQLite an arithmetic expression
            // compared to a text-bound limit can silently bypass the hard cap.
            $stmt->bindValue(1, $tokens, PDO::PARAM_INT);
            $stmt->bindValue(2, $uid, PDO::PARAM_STR);
            $stmt->bindValue(3, $period, PDO::PARAM_STR);
            $stmt->bindValue(4, $tokens, PDO::PARAM_INT);
            $stmt->bindValue(5, $limit, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) throw new \DomainException('Monthly AI token budget reached.');
            $pdo->prepare('INSERT INTO ce_ai_editor_jobs (id, user_id, period, reserved_tokens, expires_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$job, $uid, $period, $tokens, $lease]);
            (new AiCostControl($this->db, $this->settings))->hold($pdo, $job, $uid, $this->aiCostModule(), $this->settings->status(), $tokens, $lease, $now);
        });
    }

    private function settle(PDO $pdo, string $uid, string $period, string $job, int $reserve, int|array $used): void
    {
        $stmt = $pdo->prepare('DELETE FROM ce_ai_editor_jobs WHERE id = ? AND user_id = ?');
        $stmt->execute([$job, $uid]);
        if ($stmt->rowCount() !== 1) throw new \RuntimeException('AI editor generation lease was lost.');
        $total = is_array($used) ? (int)($used['input_tokens'] ?? 0) + (int)($used['output_tokens'] ?? 0) : $used;
        $pdo->prepare('UPDATE ce_ai_usage SET reserved_tokens = CASE WHEN reserved_tokens >= ? THEN reserved_tokens - ? ELSE 0 END, used_tokens = used_tokens + ? WHERE user_id = ? AND period = ?')
            ->execute([$reserve, $reserve, $total, $uid, $period]);
        (new AiCostControl($this->db, $this->settings))->settle($pdo, $job, is_array($used) ? $used : ['input_tokens'=>$used,'output_tokens'=>0]);
    }

    private function aiCostModule(): string
    {
        return match (static::class) {
            AiContentEditor::class => 'editor',
            AiTranslationStudio::class => 'translation',
            AiKnowledgeChat::class => 'knowledge_chat',
            AiSupportCenter::class => 'support',
            AiCmsDiagnostics::class => 'diagnostics',
            AiExternalAnalytics::class => 'analytics',
            AiMigrationCopilot::class => 'migration',
            default => 'ai',
        };
    }

    private function release(string $uid, string $period, string $job, int $reserve, ?array $usage = null): void
    {
        try {
            $this->db->transaction(function (PDO $pdo) use ($uid, $period, $job, $reserve, $usage): void {
                $stmt = $pdo->prepare('DELETE FROM ce_ai_editor_jobs WHERE id = ? AND user_id = ?');
                $stmt->execute([$job, $uid]);
                if ($stmt->rowCount() === 1) {
                    $total = $usage === null ? 0 : max(0, (int)($usage['input_tokens'] ?? 0)) + max(0, (int)($usage['output_tokens'] ?? 0));
                    $pdo->prepare('UPDATE ce_ai_usage SET reserved_tokens = CASE WHEN reserved_tokens >= ? THEN reserved_tokens - ? ELSE 0 END, used_tokens = used_tokens + ? WHERE user_id = ? AND period = ?')
                        ->execute([$reserve, $reserve, $total, $uid, $period]);
                    $cost = new AiCostControl($this->db, $this->settings);
                    if ($usage !== null) $cost->settle($pdo, $job, $usage);
                    else $cost->release($pdo, $job);
                }
            });
        } catch (\Throwable) { /* The lease is reclaimable by the next request after expiry. */ }
    }

}
