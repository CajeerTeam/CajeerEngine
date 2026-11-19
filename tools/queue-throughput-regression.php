<?php
/** Query-count acceptance for the PostgreSQL queue hot path, no external DB. */
declare(strict_types=1);
require dirname(__DIR__) . '/core/Console/NativeCli.php';
use CajeerEngine\Queue\PostgresQueueDriver;

final class PerfQueuePdo extends PDO
{
    public int $recoveryQueries = 0;
    public int $reserveQueries = 0;
    public bool $emptyReady = false;
    public bool $failRecovery = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PerfQueueStatement($this, $query);
    }
}
final class PerfQueueStatement extends PDOStatement
{
    public function __construct(private PerfQueuePdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        if (str_contains($this->sql, 'WITH expired AS')) {
            $this->pdo->recoveryQueries++;
            if ($this->pdo->failRecovery) throw new RuntimeException('Simulated recovery outage');
        } elseif (str_contains($this->sql, 'WITH candidate AS')) $this->pdo->reserveQueries++;
        return true;
    }
    public function rowCount(): int {return 0;}
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->pdo->emptyReady) return false;
        return ['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'log.message', 'payload' => '{}',
            'queue' => 'default', 'attempts' => 1, 'max_attempts' => 3, 'available_at' => '2026-01-01 00:00:00',
            'reserved_at' => '2026-01-01 00:00:01', 'reservation_token' => 'mock-token', 'lease_until' => '2030-01-01 00:00:00'];
    }
}
$pdo = new PerfQueuePdo();
$queue = new PostgresQueueDriver($pdo);
for ($i = 0; $i < 96; $i++) {
    $job = $queue->pop();
    if (!$job || $job->name !== 'log.message') throw new RuntimeException('Failed to reserve a job');
}
if ($pdo->reserveQueries !== 96 || $pdo->recoveryQueries < 2 || $pdo->recoveryQueries > 5) {
    throw new RuntimeException('Hot path recovery query count regression: ' . json_encode([$pdo->reserveQueries,$pdo->recoveryQueries]));
}
$pdo->emptyReady = true;
$recoveryBefore = $pdo->recoveryQueries;
if ($queue->pop() !== null || $pdo->recoveryQueries <= $recoveryBefore) throw new RuntimeException('Empty queue must force recovery');
$pdo->failRecovery = true;
try { $queue->pop(); throw new RuntimeException('Expected recovery failure'); } catch (RuntimeException $e) {
    if ($e->getMessage() !== 'Simulated recovery outage') throw $e;
}
$pdo->failRecovery = false;
if ($queue->pop() !== null) throw new RuntimeException('Recovered empty queue must return null');
echo json_encode(['ok' => true, 'reserved' => 96, 'recoveries_during_busy_work' => $recoveryBefore,
    'idle_recovery' => true, 'recovery_outage_not_suppressed' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
