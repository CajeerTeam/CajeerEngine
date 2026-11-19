<?php
/** Standalone transport acceptance using ext-amqp protocol-shaped fakes (no broker required). */
declare(strict_types=1);

const AMQP_EX_TYPE_DIRECT = 'direct';
const AMQP_DURABLE = 2;
const AMQP_NOPARAM = 0;
const AMQP_REQUEUE = 1;

final class FakeBroker
{
    /** @var array<string, list<FakeEnvelope>> */
    public static array $queues = [];
    public static bool $rejectPublish = false;
    public static int $tag = 0;
}
class AMQPConnection
{
    public function __construct(array $config) {}
    public function connect(): bool { return true; }
    public function isConnected(): bool { return true; }
}
class AMQPChannel
{
    public function __construct(AMQPConnection $connection) {}
}
class AMQPExchange
{
    public function __construct(AMQPChannel $channel) {}
    public function setName(string $name): void {}
    public function setType(string $type): void {}
    public function setFlags(int $flags): void {}
    public function declareExchange(): void {}
    public function publish(string $message, string $queue, int $flags, array $attributes): bool
    {
        if (FakeBroker::$rejectPublish) return false;
        FakeBroker::$queues[$queue][] = new FakeEnvelope($message, ++FakeBroker::$tag);
        return true;
    }
}
class FakeEnvelope
{
    public function __construct(private string $body, private int $tag) {}
    public function getBody(): string { return $this->body; }
    public function getDeliveryTag(): int { return $this->tag; }
}
class AMQPQueue
{
    private string $name = '';
    private array $reserved = [];
    public function __construct(AMQPChannel $channel) {}
    public function setName(string $name): void { $this->name = $name; }
    public function setFlags(int $flags): void {}
    public function declareQueue(): int { return count(FakeBroker::$queues[$this->name] ?? []); }
    public function bind(string $exchange, string $key): void {}
    public function get(int $flags): FakeEnvelope|false
    {
        $envelope = array_shift(FakeBroker::$queues[$this->name]);
        if ($envelope === null) return false;
        $this->reserved[$envelope->getDeliveryTag()] = $envelope;
        return $envelope;
    }
    public function ack(int $deliveryTag): void { unset($this->reserved[$deliveryTag]); }
    public function reject(int $deliveryTag, int $flags): void
    {
        $envelope = $this->reserved[$deliveryTag] ?? null;
        unset($this->reserved[$deliveryTag]);
        if ($envelope !== null && $flags === AMQP_REQUEUE) FakeBroker::$queues[$this->name][] = $envelope;
    }
}

$root = dirname(__DIR__);
require $root.'/core/Support/Uuid.php';
require $root.'/core/Queue/QueueOptions.php';
require $root.'/core/Queue/QueueDriverInterface.php';
require $root.'/core/Queue/ManagedQueueDriverInterface.php';
require $root.'/core/Queue/QueuedJob.php';
require $root.'/core/Queue/RabbitMqQueueDriver.php';

use CajeerEngine\Queue\QueueOptions;
use CajeerEngine\Queue\RabbitMqQueueDriver;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$driver = new RabbitMqQueueDriver(['dsn'=>'amqp://test:test@127.0.0.1:5672/%2f','queue_prefix'=>'test-ce']);
$run='6cd68025-7787-4c5f-a10a-bc1f110c2c2d';
$payload=['run_id'=>$run,'_idempotency_key'=>'ai.automation.'.$run,'_max_attempts'=>1];
$expected=QueueOptions::id('default','ai.automation.execute',$payload);
$id=$driver->push('ai.automation.execute',$payload);
expect($id===$expected,'RabbitMQ must share DB/Redis/File deterministic IDs');
$job=$driver->pop();
expect($job!==null && $job->id===$expected && $job->queue==='default','RabbitMQ must preserve logical queue identity');
expect($job->maxAttempts===1 && $job->attempts===1,'Paid AI jobs must have exactly one attempt');
$driver->fail($job,'Redacted error');
expect($driver->pop()===null,'Failed one-shot AI job must not be requeued');
// Re-offers may produce duplicate messages, but their IDs must remain stable.
expect($driver->push('ai.automation.execute',$payload)===$expected,'Duplicate offer must use the same job ID');
$next=$driver->pop();
expect($next?->id===$expected,'Re-offers may only carry the original DB idempotency identity');
$driver->complete($next);
// A broker publication rejection must not be reported as a successful enqueue.
FakeBroker::$rejectPublish = true;
try {
    $driver->push('ai.automation.execute',['run_id'=>$run,'_idempotency_key'=>'new-test-key','_max_attempts'=>1]);
    throw new RuntimeException('Unconfirmed broker publication was accepted');
} catch (RuntimeException $e) {
    expect(str_contains($e->getMessage(),'enqueue failed'),'Wrong broker publication error');
}

echo "OK: RabbitMQ AI deterministic queue identity, one-shot rejection, retry identity, publish failure\n";
