<?php

declare(strict_types=1);

namespace CajeerEngine\Integration;

use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Integration\Provider\ProviderRegistry;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\Uuid;

final readonly class IntegrationCenter
{
    public function __construct(private IntegrationStore $store, private ProviderRegistry $providers, private SecretVault $vault, private ConfigRepository $config) {}

    public function overview(): array
    {
        return ['providers'=>$this->providers->descriptors(), 'enabled'=>$this->config->bool('integrations.enabled',true),
            'items'=>$this->store->transaction(fn (array $items): array => array_values(array_map($this->publicItem(...), $items)))];
    }

    private function publicItem(array $item): array
    {
        $item['secret_configured'] = isset($item['credential']);
        unset($item['credential']);
        return $item;
    }

    private function lookup(array $items, string $id): array
    {
        return $items[$id] ?? throw new \RuntimeException('Integration не найден.');
    }

    private function revision(array $item, mixed $revision): void
    {
        if (!is_int($revision) || $revision !== $item['revision']) throw new RevisionConflict('Integration revision conflict; reload before retrying.');
    }

    private function name(mixed $name): string
    {
        if (!is_string($name) || trim($name)==='' || strlen($name)>200 || preg_match('/[\x00-\x1f\x7f]/',$name)) throw new \InvalidArgumentException('Invalid integration name.');
        return trim($name);
    }

    private function context(array $item): string { return 'integration:v1:'.$item['id'].':'.$item['provider']; }

    private function policy(array $config): void
    {
        $hosts = $this->config->get('integrations.allowed_hosts',[]);
        $host = strtolower((string)parse_url($config['url'] ?? '',PHP_URL_HOST));
        if ($hosts !== [] && !in_array($host,array_map('strtolower',$hosts),true)) throw new \InvalidArgumentException('Integration host is not allowed.');
    }

    public function create(array $input, string $actor): array
    {
        $provider = $this->providers->get((string)($input['provider'] ?? ''));
        $config = $provider->configuration($input['config'] ?? []);
        $this->policy($config);
        $secret = $input['secret'] ?? '';
        if (!is_string($secret)) throw new \InvalidArgumentException('Invalid credential.');
        $provider->validateSecret($secret);
        $item = ['id'=>Uuid::v4(),'provider'=>$provider->id(),'name'=>$this->name($input['name'] ?? null),'config'=>$config,
            'enabled'=>$input['enabled'] ?? false,'revision'=>1,'created_at'=>gmdate(DATE_ATOM),'updated_at'=>gmdate(DATE_ATOM),'updated_by'=>$actor,'last_run'=>null];
        if (!is_bool($item['enabled'])) throw new \InvalidArgumentException('Invalid enabled flag.');
        if ($secret !== '') $item['credential'] = $this->vault->seal($this->context($item),$secret);
        return $this->store->transaction(function (array &$items) use ($item): array {
            if (count($items)>=$this->config->int('integrations.max_connections',200)) throw new \InvalidArgumentException('Integration limit reached.');
            $items[$item['id']] = $item;
            return $this->publicItem($item);
        },true);
    }

    public function update(string $id, array $input, string $actor): array
    {
        return $this->store->transaction(function (array &$items) use ($id,$input,$actor): array {
            $item = $this->lookup($items,$id);
            $this->revision($item,$input['revision'] ?? null);
            if (array_key_exists('name',$input)) $item['name']=$this->name($input['name']);
            if (array_key_exists('enabled',$input)) {
                if (!is_bool($input['enabled'])) throw new \InvalidArgumentException('Invalid enabled flag.');
                $item['enabled']=$input['enabled'];
            }
            // A destination change must never carry an existing credential to a new receiver.
            if (array_key_exists('config',$input)) {
                $config=$this->providers->get($item['provider'])->configuration($input['config']);
                $this->policy($config);
                if ($config !== $item['config']) {
                    if (isset($item['credential'])) throw new \InvalidArgumentException('Create a new integration to change a credential-bound destination.');
                    $item['config']=$config;
                }
            }
            $item['revision']++; $item['updated_at']=gmdate(DATE_ATOM); $item['updated_by']=$actor; $item['last_run']=null;
            $items[$id]=$item;
            return $this->publicItem($item);
        },true);
    }

    public function rotate(string $id, int $revision, #[\SensitiveParameter] string $secret, string $actor): array
    {
        return $this->store->transaction(function (array &$items) use ($id,$revision,$secret,$actor): array {
            $item=$this->lookup($items,$id); $this->revision($item,$revision);
            $this->providers->get($item['provider'])->validateSecret($secret);
            if ($secret==='') unset($item['credential']); else $item['credential']=$this->vault->seal($this->context($item),$secret);
            $item['revision']++; $item['updated_at']=gmdate(DATE_ATOM); $item['updated_by']=$actor; $item['last_run']=null;
            $items[$id]=$item; return $this->publicItem($item);
        },true);
    }

    public function delete(string $id, int $revision): void
    {
        $this->store->transaction(function (array &$items) use ($id,$revision): void {
            $this->revision($this->lookup($items,$id),$revision); unset($items[$id]);
        },true);
    }

    public function run(string $id, int $revision, string $event, array|\stdClass $payload, string $actor, bool $test=false, ?string $eventId=null): array
    {
        if (!$this->config->bool('integrations.enabled',true)) throw new \RuntimeException('Integration execution disabled by configuration.');
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,199}$/D',$event) || strlen(json_encode($payload,JSON_THROW_ON_ERROR))>$this->config->int('integrations.max_payload_bytes',65536)) throw new \InvalidArgumentException('Invalid integration event or payload size.');
        $item=$this->store->transaction(fn (array $items): array => $this->lookup($items,$id));
        $this->revision($item,$revision);
        if (!$test && !$item['enabled']) throw new \InvalidArgumentException('Integration is disabled.');
        $this->policy($item['config']);
        $provider=$this->providers->get($item['provider']);
        $secret=isset($item['credential']) ? $this->vault->open($this->context($item),$item['credential']) : '';
        if($eventId!==null&&!preg_match('/^[a-f0-9]{32}$/D',$eventId))throw new \InvalidArgumentException('Invalid event id');
        $eventId??=Uuid::v4(); $start=microtime(true); $status=0;
        try { $status=$provider->execute($item['config'],$secret,$event,$payload,$eventId); }
        catch (\Throwable) { /* Never expose provider errors, remote bodies or credentials. */ }
        finally { if ($secret!=='') sodium_memzero($secret); }
        $result=['event_id'=>$eventId,'event'=>$event,'test'=>$test,'ok'=>$status>=200 && $status<300,'status_code'=>$status,
            'error'=>$status===0?'transport_failed':($status>=200 && $status<300?null:'remote_rejected'), 'duration_ms'=>round((microtime(true)-$start)*1000,2), 'created_at'=>gmdate(DATE_ATOM),'actor_id'=>$actor];
        $this->store->transaction(function (array &$items) use ($id,$item,$result): void {
            if (($items[$id]['revision'] ?? null)===$item['revision']) $items[$id]['last_run']=$result;
        },true);
        return $result;
    }
}
