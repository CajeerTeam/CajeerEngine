<?php

declare(strict_types=1);

namespace CajeerEngine\Integration;

use CajeerEngine\Support\JsonFile;
use CajeerEngine\Support\Uuid;

final readonly class WebhookRepository
{
    public function __construct(private string $rootPath)
    {
    }

    public function rootPath(): string { return $this->rootPath; }

    private function locked(string $name, callable $callback): mixed
    {
        $dir=\CajeerEngine\Update\OperationFiles::path($this->rootPath,'storage/app/webhook-locks');
        \CajeerEngine\Update\OperationFiles::dir($dir,0770);
        $h=fopen($dir.'/'.hash('sha256',$name).'.lock','c+');if(!$h)throw new \RuntimeException('Cannot open webhook lock');
        try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Cannot lock webhooks');return $callback();}finally{fclose($h);}
    }

    /** Record successful delivery before acknowledging queue job. Receiver must deduplicate the event ID. */
    public function deliverOnce(string $webhookId,string $eventId,callable $send): array
    {
        $key=hash('sha256',json_encode([$webhookId,$eventId],JSON_THROW_ON_ERROR));
        return $this->locked('delivery.'.$key,function()use($key,$send){
            $file=new JsonFile(\CajeerEngine\Update\OperationFiles::path($this->rootPath,'storage/app/webhook-receipts/'.$key.'.json'));
            $old=$file->readObject();if(($old['ok']??false)===true)return $old;
            $result=$send();if(($result['ok']??false)!==true)throw new \RuntimeException('Webhook was not delivered');$file->writeObject($result);return $result;
        });
    }

    /** Materialize a stable per-target job before marking the outbox event handed off. */
    public function enqueuePending(\CajeerEngine\Queue\ManagedQueueDriverInterface $queue,int $limit=50): array
    {
        return $this->locked('outbox',function()use($queue,$limit){
            $data=$this->outboxData();$events=0;$deliveries=0;$visited=0;$errors=[];
            foreach($data['events'] as &$item){
                if($visited>=max(1,$limit))break;
                if(($item['status']??'pending')!=='pending'||strtotime($item['available_at']??'now')>time())continue;
                $visited++;
                try {
                    // Persist target selection before enqueue: retries use the original recipient set.
                    if(!isset($item['targets'])){$item['targets']=array_column($this->matching($item['event']),'id');(new JsonFile($this->outboxPath()))->writeObject($data);}
                    foreach($item['targets'] as $id){$queue->push('webhook.deliver',['event'=>$item['event'],'payload'=>$item['payload'],'webhook_id'=>$id,'event_id'=>$item['id'],'_idempotency_key'=>'webhook:'.$item['id'].':'.$id]);$deliveries++;}
                    $item['status']='processed';$item['processed_at']=$this->now();$events++;
                } catch(\Throwable $e) {
                    $item['attempts']=(int)($item['attempts']??0)+1;$item['last_error']=\CajeerEngine\Queue\QueueOptions::error($e->getMessage(),1000);
                    $item['available_at']=gmdate(DATE_ATOM,time()+\CajeerEngine\Queue\QueueOptions::backoff($item['attempts']));$errors[]=$item['id'];
                }
                (new JsonFile($this->outboxPath()))->writeObject($data);
            }
            unset($item);return ['ok'=>$errors===[],'events'=>$events,'deliveries'=>$deliveries,'errors'=>$errors,'error'=>$errors===[]?null:'Outbox handoff failed; events retained for retry'];
        });
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $items = $this->read()['webhooks'] ?? [];
        return is_array($items) ? array_values($items) : [];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $item) {
            if ((string) ($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        return null;
    }

    /** Reject cleartext, loopback, embedded credentials and malformed destinations at registration. */
    public static function validateDestination(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 9 || strlen($value) > 2048 || trim($value) !== $value
            || preg_match('/[\x00-\x20\x7f]/', $value) || str_contains($value, '\\')) {
            throw new \InvalidArgumentException('Invalid webhook destination.');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('Webhook destination must use HTTPS without credentials.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!\CajeerEngine\Security\PublicHttpClient::isPublicIp($host)) throw new \InvalidArgumentException('Non-public webhook destination.');
        } elseif (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $host)) {
            throw new \InvalidArgumentException('Invalid webhook hostname.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function validateEvents(mixed $events): array
    {
        if (!is_array($events) || !array_is_list($events) || $events === [] || count($events) > 32) throw new \InvalidArgumentException('Invalid webhook events.');
        foreach ($events as $event) {
            if (!is_string($event) || ($event !== '*' && preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/D', $event) !== 1)) throw new \InvalidArgumentException('Invalid webhook event.');
        }
        return array_values(array_unique($events));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(array $payload): array
    {
        return $this->locked('registry',fn()=> $this->createLocked($payload));
    }

    private function createLocked(array $payload): array
    {
        $url = self::validateDestination($payload['url'] ?? null);
        $events = self::validateEvents($payload['events'] ?? ['*']);
        $secret = $payload['secret'] ?? 'whsec_' . bin2hex(random_bytes(24));
        if (!is_string($secret) || strlen($secret) < 16 || strlen($secret) > 256 || preg_match('/[\x00-\x1f\x7f]/', $secret)) throw new \InvalidArgumentException('Invalid webhook signing secret.');
        $name = $payload['name'] ?? 'Webhook';
        if (!is_string($name) || trim($name) === '' || strlen($name) > 200 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new \InvalidArgumentException('Invalid webhook name.');
        if (isset($payload['enabled']) && !is_bool($payload['enabled'])) throw new \InvalidArgumentException('Invalid webhook enabled flag.');
        $now = $this->now();
        $item = [
            'id' => Uuid::v4(),
            'name' => trim($name),
            'url' => $url,
            'events' => $events,
            'secret' => $secret,
            'enabled' => $payload['enabled'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $items = $this->all();
        $items[] = $item;
        $this->persist($items);
        return $item;
    }

    /** @param array<string, mixed> $payload */
    public function update(string $id, array $payload): ?array
    {
        return $this->locked('registry',fn()=> $this->updateLocked($id,$payload));
    }

    private function updateLocked(string $id, array $payload): ?array
    {
        $items = $this->all();
        foreach ($items as $i => $item) {
            if ((string) ($item['id'] ?? '') !== $id) {
                continue;
            }
            if (array_key_exists('name', $payload)) {
                $name = $payload['name'];
                if (!is_string($name) || trim($name) === '' || strlen($name) > 200 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new \InvalidArgumentException('Invalid webhook name.');
                $item['name'] = trim($name);
            }
            if (array_key_exists('url', $payload)) {
                $url = self::validateDestination($payload['url']);
                // Never carry a signing credential to another recipient.
                if ($url !== $item['url'] && !array_key_exists('secret', $payload)) {
                    throw new \InvalidArgumentException('Changing a webhook destination requires rotating its signing secret.');
                }
                $item['url'] = $url;
            }
            if (array_key_exists('events', $payload)) {
                $item['events'] = self::validateEvents($payload['events']);
            }
            if (array_key_exists('secret', $payload)) {
                $secret = $payload['secret'];
                if (!is_string($secret) || strlen($secret) < 16 || strlen($secret) > 256 || preg_match('/[\x00-\x1f\x7f]/', $secret)) throw new \InvalidArgumentException('Invalid webhook signing secret.');
                $item['secret'] = $secret;
            }
            if (array_key_exists('enabled', $payload)) {
                if (!is_bool($payload['enabled'])) throw new \InvalidArgumentException('Invalid webhook enabled flag.');
                $item['enabled'] = $payload['enabled'];
            }
            $item['updated_at'] = $this->now();
            $items[$i] = $item;
            $this->persist($items);
            return $item;
        }
        return null;
    }

    public function delete(string $id): bool
    {
        return $this->locked('registry',fn()=> $this->deleteLocked($id));
    }

    private function deleteLocked(string $id): bool
    {
        $items = $this->all();
        $before = count($items);
        $items = array_values(array_filter($items, static fn (array $item): bool => (string) ($item['id'] ?? '') !== $id));
        if (count($items) === $before) {
            return false;
        }
        $this->persist($items);
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function matching(string $event): array
    {
        return array_values(array_filter($this->all(), static function (array $item) use ($event): bool {
            if (!filter_var($item['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
            $events = is_array($item['events'] ?? null) ? $item['events'] : [];
            return in_array('*', $events, true) || in_array($event, $events, true);
        }));
    }

    /** @param array<string, mixed> $delivery */
    public function recordDelivery(array $delivery): void
    {
        $this->locked('delivery-log',fn()=> $this->recordDeliveryLocked($delivery));
    }

    private function recordDeliveryLocked(array $delivery): void
    {
        $data = $this->deliveriesData();
        $items = is_array($data['deliveries'] ?? null) ? $data['deliveries'] : [];
        array_unshift($items, $delivery);
        $items = array_slice($items, 0, 1000);
        (new JsonFile($this->deliveriesPath()))->writeObject(['version' => 1, 'deliveries' => $items]);
    }

    /** @return list<array<string, mixed>> */
    public function deliveries(?string $webhookId = null): array
    {
        $items = $this->deliveriesData()['deliveries'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        if ($webhookId !== null && $webhookId !== '') {
            $items = array_values(array_filter($items, static fn (array $item): bool => (string) ($item['webhook_id'] ?? '') === $webhookId));
        }
        return array_values($items);
    }

    /** @param array<string, mixed> $payload */
    public function pushOutbox(string $event, array $payload): string
    {
        return $this->locked('outbox',fn()=> $this->pushOutboxLocked($event,$payload));
    }

    private function pushOutboxLocked(string $event, array $payload): string
    {
        if($event===''||strlen($event)>200||preg_match('/[\x00-\x1f\x7f]/',$event))throw new \InvalidArgumentException('Invalid event name');
        if(strlen(json_encode($payload,JSON_THROW_ON_ERROR))>900000)throw new \InvalidArgumentException('Event payload too large');
        $data = $this->outboxData();
        $items = is_array($data['events'] ?? null) ? $data['events'] : [];
        $id = Uuid::v4();
        $items[] = [
            'id' => $id,
            'event' => $event,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $this->now(),
            'created_at' => $this->now(),
            'processed_at' => null,
        ];
        (new JsonFile($this->outboxPath()))->writeObject(['version' => 1, 'events' => $items]);
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function outbox(int $limit = 20): array
    {
        $data = $this->outboxData();
        $items = is_array($data['events'] ?? null) ? $data['events'] : [];
        $now = time();
        $pending = array_values(array_filter($items, static function (array $item) use ($now): bool {
            if (($item['status'] ?? 'pending') !== 'pending') {
                return false;
            }
            $available = strtotime((string) ($item['available_at'] ?? 'now')) ?: 0;
            return $available <= $now;
        }));
        return array_slice($pending, 0, max(1, $limit));
    }

    public function markOutboxProcessed(string $id): void
    {
        $this->locked('outbox',fn()=> $this->markOutboxProcessedLocked($id));
    }

    private function markOutboxProcessedLocked(string $id): void
    {
        $data = $this->outboxData();
        $items = is_array($data['events'] ?? null) ? $data['events'] : [];
        foreach ($items as &$item) {
            if ((string) ($item['id'] ?? '') === $id) {
                $item['status'] = 'processed';
                $item['processed_at'] = $this->now();
                break;
            }
        }
        unset($item);
        (new JsonFile($this->outboxPath()))->writeObject(['version' => 1, 'events' => $items]);
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $deliveries = $this->deliveries();
        $success = count(array_filter($deliveries, static fn (array $d): bool => (int) ($d['status_code'] ?? 0) >= 200 && (int) ($d['status_code'] ?? 0) < 300));
        return [
            'webhooks' => count($this->all()),
            'enabled' => count(array_filter($this->all(), static fn (array $w): bool => filter_var($w['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN))),
            'deliveries' => count($deliveries),
            'successful_deliveries' => $success,
            'failed_deliveries' => count($deliveries) - $success,
            'pending_outbox' => count($this->outbox(1000)),
        ];
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return (new JsonFile($this->path()))->readObject(['version' => 1, 'webhooks' => []]);
    }

    /** @param list<array<string, mixed>> $items */
    private function persist(array $items): void
    {
        (new JsonFile($this->path()))->writeObject(['version' => 1, 'updated_at' => $this->now(), 'webhooks' => array_values($items)]);
    }

    /** @return array<string, mixed> */
    private function deliveriesData(): array
    {
        return (new JsonFile($this->deliveriesPath()))->readObject(['version' => 1, 'deliveries' => []]);
    }

    /** @return array<string, mixed> */
    private function outboxData(): array
    {
        return (new JsonFile($this->outboxPath()))->readObject(['version' => 1, 'events' => []]);
    }

    private function path(): string
    {
        return $this->rootPath . '/storage/app/webhooks.json';
    }

    private function deliveriesPath(): string
    {
        return $this->rootPath . '/storage/app/webhook-deliveries.json';
    }

    private function outboxPath(): string
    {
        return $this->rootPath . '/storage/app/outbox.json';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
