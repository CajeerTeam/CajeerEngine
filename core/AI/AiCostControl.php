<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Database\DatabaseManager;
use PDO;

/** Atomic USD-micro ledger and hard budgets for *all* chargeable OpenAI operations.
 *
 * Prices are saved in integer US cents per 1M tokens and snapshotted per lease.
 * A lease, its four daily/monthly balances and its settlement live in the same
 * transaction as the existing token lease or persisted CMS result.
 */
final readonly class AiCostControl
{
    public function __construct(private DatabaseManager $db, private AiSettings $settings) {}

    /** @return array{input:int,cached:int,output:int} */
    public static function rates(array $status): array
    {
        $price = $status['model_pricing'] ?? null;
        if (!is_array($price) || !isset($price['input'], $price['cached'], $price['output'])
            || !is_int($price['input']) || $price['input'] <= 0 || !is_int($price['output']) || $price['output'] <= 0
            || !is_int($price['cached']) || $price['cached'] < 0 || $price['cached'] > $price['input']) {
            throw new \DomainException('AI model pricing is missing. Configure verified USD prices before generating.');
        }
        return ['input'=>$price['input'], 'cached'=>$price['cached'], 'output'=>$price['output']];
    }

    /** 1 USD = 1,000,000 micro-USD; prices are US cents per million tokens. */
    public static function charge(int $input, int $cached, int $output, array $rates): int
    {
        if ($input < 0 || $output < 0 || $cached < 0 || $cached > $input) {
            throw new \InvalidArgumentException('Invalid OpenAI token usage.');
        }
        foreach (['input','cached','output'] as $name) {
            if (!isset($rates[$name]) || !is_int($rates[$name]) || $rates[$name] < 0 || $rates[$name] > 100000000) {
                throw new \InvalidArgumentException('Invalid AI model price.');
            }
        }
        // Round up only the sum; never underestimate fractional micro-USD charges.
        return intdiv(($input-$cached)*$rates['input'] + $cached*$rates['cached'] + $output*$rates['output'] + 99, 100);
    }

    public static function estimatedReserve(int $tokenCeiling, array $rates): int
    {
        if ($tokenCeiling < 0 || $tokenCeiling > 200000000) throw new \InvalidArgumentException('Invalid token reservation.');
        return max(1, self::charge(0,0,$tokenCeiling,['input'=>$rates['input'],'cached'=>$rates['cached'],'output'=>max($rates['input'],$rates['output'])]));
    }

    /** The caller must already be in a DB transaction; throws before any provider call. */
    public function hold(PDO $pdo, string $jobId, string $userId, string $module, array $status, int $tokenCeiling, int $expiresAt, ?int $now = null): void
    {
        $now ??= time();
        if (!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D', $jobId) || $userId === '' || strlen($userId) > 80 || !preg_match('/^[a-z][a-z0-9_-]{1,39}$/D', $module)) {
            throw new \InvalidArgumentException('Invalid AI cost lease identity.');
        }
        $rates = self::rates($status);
        $model = (string)($status['model'] ?? '');
        if ($model === '' || strlen($model) > 80) throw new \InvalidArgumentException('Invalid AI model.');
        $amount = self::estimatedReserve($tokenCeiling, $rates);
        $day = gmdate('Y-m-d', $now); $month = gmdate('Y-m', $now);
        $this->reap($pdo, $now, 30);
        foreach (self::windows($userId, $day, $month, $status) as [$scope, $period, $limit]) {
            $insert = $this->db->driver() === 'mysql'
                ? 'INSERT IGNORE INTO ce_ai_cost_balances (scope, period, used_micro_usd, reserved_micro_usd) VALUES (?, ?, 0, 0)'
                : 'INSERT INTO ce_ai_cost_balances (scope, period, used_micro_usd, reserved_micro_usd) VALUES (?, ?, 0, 0) ON CONFLICT (scope, period) DO NOTHING';
            $pdo->prepare($insert)->execute([$scope, $period]);
            $query = 'UPDATE ce_ai_cost_balances SET reserved_micro_usd = reserved_micro_usd + ? WHERE scope = ? AND period = ?';
            if ($limit > 0) $query .= ' AND used_micro_usd + reserved_micro_usd + ? <= ?';
            $values = [$amount, $scope, $period];
            if ($limit > 0) array_push($values, $amount, $limit * 10000);
            $stmt = $pdo->prepare($query); $stmt->execute($values);
            if ($stmt->rowCount() !== 1) throw new \DomainException('AI spending limit reached for ' . ($scope === 'global' ? 'the installation' : 'this user') . ' (' . $period . ').');
        }
        $pdo->prepare('INSERT INTO ce_ai_cost_reservations (id, user_id, module, model, day_period, month_period, reserved_micro_usd, input_rate_cents, cached_rate_cents, output_rate_cents, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$jobId,$userId,$module,$model,$day,$month,$amount,$rates['input'],$rates['cached'],$rates['output'],$expiresAt,$now]);
    }

    /** Caller commits the actual provider response and token usage in the *same* transaction. */
    public function settle(PDO $pdo, string $jobId, array $usage): void
    {
        $row = $this->lease($pdo, $jobId);
        if ($row === null) throw new \RuntimeException('AI cost lease expired before settlement.');
        $input = max(0, (int)($usage['input_tokens'] ?? 0));
        $output = max(0, (int)($usage['output_tokens'] ?? 0));
        $cached = max(0, (int)($usage['cached_input_tokens'] ?? 0));
        $rates = ['input'=>(int)$row['input_rate_cents'],'cached'=>(int)$row['cached_rate_cents'],'output'=>(int)$row['output_rate_cents']];
        $amount = self::charge($input, $cached, $output, $rates);
        $this->finish($pdo, $row, $amount, $input, $cached, $output, 'measured');
    }

    /** Token-budget expiry or known preflight failure: release only this lease. */
    public function release(PDO $pdo, string $jobId): void
    {
        $row = $this->lease($pdo, $jobId);
        if ($row === null) return;
        $this->finish($pdo, $row, 0, 0, 0, 0, 'released');
    }

    /** Crash recovery: reserve is counted pessimistically as unverified spend.
     * This avoids silently resetting a potentially chargeable provider request.
     */
    public function reap(PDO $pdo, int $now, int $max = 50): int
    {
        $query = 'SELECT id FROM ce_ai_cost_reservations WHERE expires_at < ? ORDER BY expires_at ASC LIMIT ' . max(1,min(100,$max));
        if ($this->db->driver() !== 'sqlite') $query .= ' FOR UPDATE';
        $stmt = $pdo->prepare($query); $stmt->execute([$now]); $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $row = $this->lease($pdo,(string)$id);
            if ($row === null) continue;
            $this->finish($pdo,$row,(int)$row['reserved_micro_usd'],0,0,0,'unverified');
            ++$count;
        }
        return $count;
    }

    private function lease(PDO $pdo, string $id): ?array
    {
        $query = 'SELECT * FROM ce_ai_cost_reservations WHERE id = ?';
        if ($this->db->driver() !== 'sqlite') $query .= ' FOR UPDATE';
        $stmt = $pdo->prepare($query); $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function finish(PDO $pdo, array $row, int $charged, int $input, int $cached, int $output, string $state): void
    {
        $old = (int)$row['reserved_micro_usd'];
        foreach (self::windows((string)$row['user_id'],(string)$row['day_period'],(string)$row['month_period'],[]) as [$scope,$period]) {
            $stmt = $pdo->prepare('UPDATE ce_ai_cost_balances SET reserved_micro_usd = reserved_micro_usd - ?, used_micro_usd = used_micro_usd + ? WHERE scope = ? AND period = ? AND reserved_micro_usd >= ?');
            $stmt->execute([$old,$charged,$scope,$period,$old]);
            if ($stmt->rowCount() !== 1) throw new \RuntimeException('AI cost reservation balance mismatch.');
        }
        if ($state !== 'released') {
            $pdo->prepare('INSERT INTO ce_ai_cost_events (id, user_id, module, model, day_period, month_period, input_tokens, cached_input_tokens, output_tokens, cost_micro_usd, input_rate_cents, cached_rate_cents, output_rate_cents, state, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$row['id'],$row['user_id'],$row['module'],$row['model'],$row['day_period'],$row['month_period'],$input,$cached,$output,$charged,$row['input_rate_cents'],$row['cached_rate_cents'],$row['output_rate_cents'],$state,time()]);
        }
        $pdo->prepare('DELETE FROM ce_ai_cost_reservations WHERE id = ?')->execute([$row['id']]);
    }

    /** @return list<array{string,string,int}> */
    private static function windows(string $uid, string $day, string $month, array $settings): array
    {
        // Fixed lock order is important for concurrent requests on PostgreSQL/MySQL.
        return [
            ['global',$month,(int)($settings['global_monthly_limit_cents'] ?? 0)],
            ['global',$day,(int)($settings['global_daily_limit_cents'] ?? 0)],
            ['user:'.$uid,$month,(int)($settings['user_monthly_limit_cents'] ?? 0)],
            ['user:'.$uid,$day,(int)($settings['user_daily_limit_cents'] ?? 0)],
        ];
    }

    /** Read-only admin accounting; no credentials, prompts or messages are returned. */
    public function report(int $days = 30): array
    {
        $days = max(1,min(366,$days));
        $pdo = $this->db->connection();
        $settings = $this->settings->status();
        $start = gmdate('Y-m-d',time()-($days-1)*86400);
        $balance = $pdo->prepare('SELECT scope, period, used_micro_usd, reserved_micro_usd FROM ce_ai_cost_balances WHERE scope = ? AND (period = ? OR period = ?) ORDER BY period');
        $balance->execute(['global',gmdate('Y-m'),gmdate('Y-m-d')]);
        $totals=[];
        foreach ($balance->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ($item['scope'] !== 'global') continue;
            $totals[$item['period']] = ['used_micro_usd'=>(int)$item['used_micro_usd'],'reserved_micro_usd'=>(int)$item['reserved_micro_usd']];
        }
        $group = static function (PDO $pdo,string $column,string $start): array {
            $allowed = ['module','model','user_id'];
            if (!in_array($column,$allowed,true)) throw new \InvalidArgumentException('Invalid cost breakdown.');
            $stmt=$pdo->prepare('SELECT '.$column.' AS label, COUNT(*) AS requests, SUM(input_tokens) AS input_tokens, SUM(cached_input_tokens) AS cached_input_tokens, SUM(output_tokens) AS output_tokens, SUM(cost_micro_usd) AS cost_micro_usd FROM ce_ai_cost_events WHERE day_period >= ? GROUP BY '.$column.' ORDER BY cost_micro_usd DESC LIMIT 50');
            $stmt->execute([$start]);
            return array_map(static fn(array $row): array => ['label'=>(string)$row['label'],'requests'=>(int)$row['requests'],'input_tokens'=>(int)$row['input_tokens'],'cached_input_tokens'=>(int)$row['cached_input_tokens'],'output_tokens'=>(int)$row['output_tokens'],'cost_micro_usd'=>(int)$row['cost_micro_usd']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        };
        $recent = $pdo->prepare('SELECT module, model, input_tokens, cached_input_tokens, output_tokens, cost_micro_usd, state, created_at FROM ce_ai_cost_events WHERE day_period >= ? ORDER BY created_at DESC, id DESC LIMIT 40');
        $recent->execute([$start]);
        $entries=array_map(static fn(array $r): array=>['module'=>$r['module'],'model'=>$r['model'],'input_tokens'=>(int)$r['input_tokens'],'cached_input_tokens'=>(int)$r['cached_input_tokens'],'output_tokens'=>(int)$r['output_tokens'],'cost_micro_usd'=>(int)$r['cost_micro_usd'],'state'=>$r['state'],'created_at'=>(int)$r['created_at']],$recent->fetchAll(PDO::FETCH_ASSOC));
        $alerts=[];
        foreach (['daily'=>['period'=>gmdate('Y-m-d'),'limit'=>(int)$settings['global_daily_limit_cents']], 'monthly'=>['period'=>gmdate('Y-m'),'limit'=>(int)$settings['global_monthly_limit_cents']]] as $name=>$info) {
            $current=$totals[$info['period']]??['used_micro_usd'=>0,'reserved_micro_usd'=>0];
            if ($info['limit'] <= 0) continue;
            $share=($current['used_micro_usd']+$current['reserved_micro_usd'])/($info['limit']*10000);
            if ($share >= .5) $alerts[]=['window'=>$name,'threshold_percent'=>$share>=1?100:($share>=.8?80:50),'utilization_percent'=>round($share*100,2)];
        }
        // User-specific thresholds are evaluated against reserved + settled spend,
        // just like global hard caps; warning values contain no content or credentials.
        $personal=$pdo->prepare('SELECT scope, period, used_micro_usd, reserved_micro_usd FROM ce_ai_cost_balances WHERE scope LIKE ? AND (period = ? OR period = ?) ORDER BY used_micro_usd DESC LIMIT 1000');
        $personal->execute(['user:%',gmdate('Y-m'),gmdate('Y-m-d')]);
        foreach ($personal->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $window = strlen($row['period'])===7 ? 'monthly' : 'daily';
            $cap = (int)($settings[$window==='monthly'?'user_monthly_limit_cents':'user_daily_limit_cents']??0);
            if ($cap <= 0) continue;
            $share=((int)$row['used_micro_usd']+(int)$row['reserved_micro_usd'])/($cap*10000);
            if ($share >= .5) {
                $alerts[]=['window'=>$window,'scope'=>$row['scope'],'threshold_percent'=>$share>=1?100:($share>=.8?80:50),'utilization_percent'=>round($share*100,2)];
                if (count($alerts)>=25) break;
            }
        }
        return ['currency'=>'USD','units'=>'micro_usd','days'=>$days,'settings'=>[
            'global_daily_limit_cents'=>$settings['global_daily_limit_cents'], 'global_monthly_limit_cents'=>$settings['global_monthly_limit_cents'],
            'user_daily_limit_cents'=>$settings['user_daily_limit_cents'], 'user_monthly_limit_cents'=>$settings['user_monthly_limit_cents'],
            'model'=>$settings['model'], 'model_pricing'=>$settings['model_pricing']], 'global_balances'=>$totals,
            'by_module'=>$group($pdo,'module',$start),'by_model'=>$group($pdo,'model',$start), 'by_user'=>$group($pdo,'user_id',$start),
            'recent'=>$entries,'alerts'=>$alerts,
            'notice'=>'Estimates from recorded provider tokens at snapshotted prices, not an OpenAI invoice. Unverified charges are conservative expired reservations; external tools/surcharges are not measured.'];
    }
}
