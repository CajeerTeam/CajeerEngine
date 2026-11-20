<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Database\DatabaseManager;
use PDO;

/** Database-owned chat history and serialized per-conversation OpenAI generations. */
final readonly class AiChat
{
    public function __construct(private DatabaseManager $db, private AiSettings $settings, private OpenAiClient $client, private ?AiTools $tools = null) {}

    public function list(string $userId): array
    {
        $query = $this->db->connection()->prepare('SELECT id, title, created_at, updated_at, total_input_tokens, total_output_tokens FROM ce_ai_conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100');
        $query->execute([$userId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function messages(string $userId, string $id): array
    {
        $this->owned($this->db->connection(), $userId, $id);
        $stmt = $this->db->connection()->prepare('SELECT id, role, content, created_at, model, input_tokens, output_tokens FROM ce_ai_messages WHERE conversation_id = ? ORDER BY created_at ASC, id ASC LIMIT 200');
        $stmt->execute([$id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $traces = $this->db->connection()->prepare('SELECT assistant_message_id, tool_name, status, duration_ms FROM ce_ai_tool_calls WHERE conversation_id = ? ORDER BY created_at ASC, id ASC');
        $traces->execute([$id]);
        $byMessage = [];
        foreach ($traces->fetchAll(PDO::FETCH_ASSOC) as $tool) {
            $byMessage[$tool['assistant_message_id']][] = ['name'=>$tool['tool_name'], 'status'=>$tool['status'], 'duration_ms'=>(int)$tool['duration_ms']];
        }
        foreach ($messages as &$item) $item['tools'] = $byMessage[$item['id']] ?? [];
        unset($item);
        return $messages;
    }

    public function rename(string $userId, string $id, string $title): array
    {
        $title = trim($title);
        if ($title === '' || strlen($title) > 160 || !preg_match('//u', $title)) throw new \InvalidArgumentException('Title must be UTF-8 and 1–160 bytes.');
        $stmt = $this->db->connection()->prepare('UPDATE ce_ai_conversations SET title = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$title, time(), $id, $userId]);
        $this->owned($this->db->connection(), $userId, $id);
        return ['id' => $id, 'title' => $title];
    }

    public function delete(string $userId, string $id): void
    {
        $this->db->transaction(function (PDO $pdo) use ($userId, $id): void {
            $item = $this->owned($pdo, $userId, $id, true);
            if ((int)$item['busy_until'] > time()) throw new \DomainException('Conversation is generating a response.');
            $stmt = $pdo->prepare('DELETE FROM ce_ai_messages WHERE conversation_id = ?'); $stmt->execute([$id]);
            $stmt = $pdo->prepare('DELETE FROM ce_ai_conversations WHERE id = ? AND user_id = ?'); $stmt->execute([$id, $userId]);
        });
    }

    /** @return array<string,mixed> */
    public function send(string $userId, ?string $id, string $message, ?callable $onDelta = null, bool $toolsEnabled = false, array $scopes = [], ?callable $onTool = null, bool $actionsEnabled = false, string $billingModule = 'chat'): array
    {
        $message = trim($message);
        if ($message === '' || strlen($message) > 12000 || !preg_match('//u', $message)) throw new \InvalidArgumentException('Message must be valid UTF-8 and 1–12000 bytes.');
        if ($id !== null && !preg_match('/^[a-f0-9-]{32,36}$/D', $id)) throw new \InvalidArgumentException('Invalid conversation identifier.');
        $definitions = ($toolsEnabled && $this->tools !== null) ? $this->tools->definitions($scopes, $actionsEnabled) : [];
        $settings = $this->settings->status();
        if (!$settings['configured']) throw new \DomainException('OpenAI API key is not configured.');
        $key = $this->settings->key();
        $newId = $id === null;
        $id ??= self::uuid();
        $now = time(); $busyUntil = $now + ($definitions === [] ? 180 : 600);
        $month = gmdate('Y-m');
        $costJob = self::uuid();
        // Reserve a deliberately conservative upper bound before the external call.
        try {
            $prepared = $this->db->transaction(function (PDO $pdo) use ($userId, $id, $newId, $message, $settings, $now, $busyUntil, $month, $definitions, $costJob, $billingModule): array {
            // Reclaim reservations abandoned by dead PHP workers after the 180s lease expires.
            $expiredQuery = 'SELECT id, reserved_tokens, reservation_period FROM ce_ai_conversations WHERE user_id = ? AND busy_until > 0 AND busy_until < ? AND reserved_tokens > 0';
            if (in_array($this->db->driver(), ['mysql', 'pgsql'], true)) $expiredQuery .= ' FOR UPDATE';
            $expired = $pdo->prepare($expiredQuery);
            $expired->execute([$userId, $now]);
            foreach ($expired->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $release = $pdo->prepare('UPDATE ce_ai_usage SET reserved_tokens = CASE WHEN reserved_tokens >= ? THEN reserved_tokens - ? ELSE 0 END WHERE user_id = ? AND period = ?');
                $release->execute([(int)$item['reserved_tokens'], (int)$item['reserved_tokens'], $userId, $item['reservation_period']]);
                $clear = $pdo->prepare('UPDATE ce_ai_conversations SET busy_until = 0, reserved_tokens = 0, reservation_period = NULL WHERE id = ? AND user_id = ?');
                $clear->execute([$item['id'], $userId]);
                (new AiCostControl($this->db, $this->settings))->reap($pdo, $now, 30);
            }
            if ($newId) {
                $title = preg_replace('/\s+/u', ' ', $message);
                preg_match('/^.{0,50}/us', $title, $matches);
                $title = $matches[0] ?: 'Новый диалог';
                $stmt = $pdo->prepare('INSERT INTO ce_ai_conversations (id, user_id, title, created_at, updated_at, busy_until, total_input_tokens, total_output_tokens) VALUES (?, ?, ?, ?, ?, 0, 0, 0)');
                $stmt->execute([$id, $userId, $title, $now, $now]);
            }
            $row = $this->owned($pdo, $userId, $id, true);
            $stmt = $pdo->prepare('UPDATE ce_ai_conversations SET busy_until = ? WHERE id = ? AND user_id = ? AND busy_until < ?');
            $stmt->execute([$busyUntil, $id, $userId, $now]);
            if ($stmt->rowCount() !== 1) throw new \DomainException('This conversation already has an active generation.');
            $history = $pdo->prepare('SELECT role, content FROM ce_ai_messages WHERE conversation_id = ? ORDER BY created_at DESC, id DESC LIMIT 20');
            $history->execute([$id]);
            $old = array_reverse($history->fetchAll(PDO::FETCH_ASSOC));
            $input = [];
            foreach ($old as $item) $input[] = ['role' => $item['role'], 'content' => $item['content']];
            $input[] = ['role' => 'user', 'content' => $message];
            $bytes = strlen(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
            if ($bytes > 48000) throw new \DomainException('Conversation context is too large. Start a new conversation.');
            // Tool loops need several provider round trips and can grow with tool outputs.
            // Reserve the worst-case request budget before making a chargeable request.
            $reservation = $definitions === []
                ? $bytes + (int)$settings['max_output_tokens'] + 64
                : 4 * ($bytes + (int)$settings['max_output_tokens'] + 12000);
            $driver = $this->db->driver();
            $insert = $driver === 'mysql'
                ? 'INSERT IGNORE INTO ce_ai_usage (user_id, period, used_tokens, reserved_tokens) VALUES (?, ?, 0, 0)'
                : 'INSERT INTO ce_ai_usage (user_id, period, used_tokens, reserved_tokens) VALUES (?, ?, 0, 0) ON CONFLICT (user_id, period) DO NOTHING';
            $stmt = $pdo->prepare($insert); $stmt->execute([$userId, $month]);
            $usage = $pdo->prepare('UPDATE ce_ai_usage SET reserved_tokens = reserved_tokens + ? WHERE user_id = ? AND period = ? AND used_tokens + reserved_tokens + ? <= ?');
            // SQLite arithmetic expressions have no column affinity: binding the cap as
            // text makes an over-budget numeric sum compare LESS than the text cap.
            // Bind numeric operands explicitly on all supported PDO drivers.
            $usage->bindValue(1, $reservation, PDO::PARAM_INT);
            $usage->bindValue(2, $userId, PDO::PARAM_STR);
            $usage->bindValue(3, $month, PDO::PARAM_STR);
            $usage->bindValue(4, $reservation, PDO::PARAM_INT);
            $usage->bindValue(5, (int)$settings['monthly_token_limit'], PDO::PARAM_INT);
            $usage->execute();
            if ($usage->rowCount() !== 1) throw new \DomainException('Monthly AI token budget reached.');
            $reserve = $pdo->prepare('UPDATE ce_ai_conversations SET reserved_tokens = ?, reservation_period = ? WHERE id = ? AND user_id = ?');
            $reserve->execute([$reservation, $month, $id, $userId]);
            (new AiCostControl($this->db, $this->settings))->hold($pdo, $costJob, $userId, $billingModule === 'chat' && $definitions !== [] ? 'chat_tools' : $billingModule, $settings, $reservation, $busyUntil, $now);
            return ['input' => $input, 'reservation' => $reservation, 'title' => $row['title']];
            });
        } catch (\Throwable $error) {
            // Preflight may fail before curl (budget, revoked session or DB outage).
            // Never keep a decrypted credential in memory after transaction failure.
            if (function_exists('sodium_memzero')) sodium_memzero($key);
            throw $error;
        }
        try {
            $result = $definitions === []
                ? $this->client->complete($key, $settings['model'], $prepared['input'], $settings['max_output_tokens'], $onDelta)
                : $this->client->completeWithTools($key, $settings['model'], $prepared['input'], $settings['max_output_tokens'], $definitions,
                    fn(string $name, array $args): array => $this->tools->execute($name, $args, $scopes, $userId, $actionsEnabled), $onDelta, $onTool);
            $result['tools'] ??= [];
            if (count($result['tools']) > 8) throw new \RuntimeException('Too many tool calls.');
            $created = (int)round(microtime(true) * 1000000);
            $this->db->transaction(function (PDO $pdo) use ($id, $userId, $message, $result, $settings, $prepared, $month, $created, $busyUntil, $costJob): void {
                $this->owned($pdo, $userId, $id, true);
                $insert = $pdo->prepare('INSERT INTO ce_ai_messages (id, conversation_id, role, content, created_at, model, input_tokens, output_tokens) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([self::uuid(), $id, 'user', $message, $created, null, 0, 0]);
                $assistantMessageId = self::uuid();
                $insert->execute([$assistantMessageId, $id, 'assistant', $result['text'], $created + 1, $settings['model'], $result['usage']['input_tokens'], $result['usage']['output_tokens']]);
                if ($result['tools'] !== []) {
                    $trace = $pdo->prepare('INSERT INTO ce_ai_tool_calls (id, conversation_id, assistant_message_id, tool_name, status, duration_ms, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    foreach ($result['tools'] as $index=>$tool) {
                        $trace->execute([self::uuid(), $id, $assistantMessageId, $tool['name'], $tool['status'], (int)$tool['duration_ms'], $created + 2 + $index]);
                    }
                }
                $update = $pdo->prepare('UPDATE ce_ai_conversations SET updated_at = ?, busy_until = 0, reserved_tokens = 0, reservation_period = NULL, total_input_tokens = total_input_tokens + ?, total_output_tokens = total_output_tokens + ? WHERE id = ? AND user_id = ? AND busy_until = ?');
                $update->execute([time(), $result['usage']['input_tokens'], $result['usage']['output_tokens'], $id, $userId, $busyUntil]);
                if ($update->rowCount() !== 1) throw new \RuntimeException('Conversation generation lease expired.');
                $usage = $pdo->prepare('UPDATE ce_ai_usage SET reserved_tokens = reserved_tokens - ?, used_tokens = used_tokens + ? WHERE user_id = ? AND period = ?');
                $usage->execute([$prepared['reservation'], $result['usage']['input_tokens'] + $result['usage']['output_tokens'], $userId, $month]);
                (new AiCostControl($this->db, $this->settings))->settle($pdo, $costJob, $result['usage']);
            });
            return ['conversation_id' => $id, 'title' => $prepared['title'], 'text' => $result['text'], 'usage' => $result['usage'], 'model' => $settings['model'], 'tools' => $result['tools']];
        } catch (\Throwable $error) {
            // If OpenAI completed but CMS persistence failed, usage is still billable.
            $chargeUsage = isset($result) ? $result['usage'] : null;
            try {
                $this->db->transaction(function (PDO $pdo) use ($userId, $id, $busyUntil, $month, $prepared, $newId, $costJob, $chargeUsage): void {
                    $release = $pdo->prepare('UPDATE ce_ai_conversations SET busy_until = 0, reserved_tokens = 0, reservation_period = NULL WHERE id = ? AND user_id = ? AND busy_until = ?');
                    $release->execute([$id, $userId, $busyUntil]);
                    if ($release->rowCount() === 1) {
                        $total = $chargeUsage === null ? 0 : max(0,(int)$chargeUsage['input_tokens'])+max(0,(int)$chargeUsage['output_tokens']);
                        $stmt = $pdo->prepare('UPDATE ce_ai_usage SET reserved_tokens = CASE WHEN reserved_tokens >= ? THEN reserved_tokens - ? ELSE 0 END, used_tokens = used_tokens + ? WHERE user_id = ? AND period = ?');
                        $stmt->execute([$prepared['reservation'], $prepared['reservation'], $total, $userId, $month]);
                        $cost=new AiCostControl($this->db, $this->settings);
                        if ($chargeUsage !== null) $cost->settle($pdo, $costJob, $chargeUsage);
                        else $cost->release($pdo, $costJob);
                        if ($newId) {
                            // A failed first message must not leave an empty conversation.
                            $remove = $pdo->prepare('DELETE FROM ce_ai_conversations WHERE id = ? AND user_id = ? AND busy_until = 0');
                            $remove->execute([$id, $userId]);
                        }
                    }
                });
            } catch (\Throwable) { /* Do not mask the original error; stale leases can be retried. */ }
            throw $error;
        } finally { if (function_exists('sodium_memzero')) sodium_memzero($key); }
    }

    private function owned(PDO $pdo, string $userId, string $id, bool $lock = false): array
    {
        $sql = 'SELECT id, title, busy_until FROM ce_ai_conversations WHERE id = ? AND user_id = ?';
        if ($lock && in_array($this->db->driver(), ['mysql', 'pgsql'], true)) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql); $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \DomainException('Conversation not found.');
        return $row;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
