<?php

declare(strict_types=1);
namespace CajeerEngine\Analytics;

use CajeerEngine\Integration\IntegrationCenter;
use CajeerEngine\Integration\IntegrationStore;
use CajeerEngine\Integration\Provider\ProviderRegistry;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Privacy\ConsentPolicy;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;

/** Trusted application events, not a public purchase collector. Consent is explicitly delegated to the producer. */
final readonly class ServerEvents
{
    public function __construct(private string $root,private IntegrationCenter $center,private ManagedQueueDriverInterface $queue) {}
    public static function fromRoot(string $root,ManagedQueueDriverInterface $queue): self
    {
        return new self($root,new IntegrationCenter(new IntegrationStore($root),ProviderRegistry::defaults(),new SecretVault((string)(getenv('INTEGRATIONS_KEY')?:getenv('APP_KEY')?:'')),new ConfigRepository($root)),$queue);
    }
    public static function targets(): array
    {
        return array_values(array_filter(array_map('trim',explode(',',(string)getenv('ANALYTICS_SERVER_TARGETS'))),static fn($v)=>preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$v)===1));
    }
    private function version(): string
    {
        $policy=(new ConsentPolicy($this->root))->publicConfig();return $policy['events']['enabled']&&self::targets()!==[]?$policy['version']:'';
    }
    public function grant(string $owner,string $version,int $ttl): array
    {
        if($owner===''||$version===''||$version!==$this->version()||$ttl<1||$ttl>900)throw new \InvalidArgumentException('Server analytics disabled, stale policy or invalid consent lease');
        return $this->transaction(function(array &$state)use($owner,$version,$ttl){
            if(count($state['grants'])>=2000)throw new \RuntimeException('Server consent lease limit reached');
            $id=bin2hex(random_bytes(16));$expires=time()+$ttl;$state['grants'][$id]=['owner'=>$owner,'version'=>$version,'expires'=>$expires,'revoked'=>false,'events'=>[]];return ['grant_id'=>$id,'expires'=>$expires];
        });
    }
    public function revoke(string $owner,string $id): void
    {
        $this->transaction(static function(array &$state)use($owner,$id){if(($state['grants'][$id]['owner']??null)===$owner){$state['grants'][$id]['revoked']=true;foreach($state['grants'][$id]['events'] as &$event)if(!$event['sent'])$event['status']='cancelled';unset($event);}});
    }
    private function active(array $state,string $id,string $owner): bool
    {
        $g=$state['grants'][$id]??null;return $g&&$g['owner']===$owner&&!$g['revoked']&&$g['expires']>time()&&$g['version']===$this->version();
    }
    private function target(string $id,int $revision): bool
    {
        if(!in_array($id,self::targets(),true))return false;
        $overview=$this->center->overview();if(!$overview['enabled'])return false;
        foreach($overview['items'] as $item)if($item['id']===$id&&$item['enabled']&&$item['revision']===$revision)return true;
        return false;
    }
    private static function validate(array $input): void
    {
        $fields=['grant_id','id','name','integration_id','revision','value','currency'];
        if(count($input)!==count($fields)||array_diff($fields,array_keys($input))||!is_string($input['grant_id'])||!preg_match('/^[a-f0-9]{32}$/D',$input['grant_id'])||!is_string($input['id'])||!preg_match('/^[a-f0-9]{32}$/D',$input['id'])||!is_string($input['name'])||!in_array($input['name'],['purchase','refund','generate_lead','form_submit','sign_up','login'],true)||!is_string($input['integration_id'])||!is_int($input['revision'])||$input['revision']<1||(!is_int($input['value'])&&!is_float($input['value']))||!is_finite((float)$input['value'])||$input['value']<0||$input['value']>1e12||!is_string($input['currency'])||!preg_match('/^[A-Z]{3}$/D',$input['currency']))throw new \InvalidArgumentException('Invalid trusted analytics event');
    }
    public function enqueue(string $owner,string $actor,array $input): array
    {
        self::validate($input);if(!$this->target($input['integration_id'],$input['revision']))throw new \InvalidArgumentException('Integration is not an enabled server analytics target at this revision');
        $payload=$this->transaction(function(array &$state)use($owner,$actor,$input){
            $grant=$input['grant_id'];if(!$this->active($state,$grant,$owner))throw new \InvalidArgumentException('Consent lease expired, revoked or stale');
            $key=$input['id'].':'.$input['integration_id'];$events=&$state['grants'][$grant]['events'];
            $payload=$input+['owner'=>$owner,'actor'=>$actor,'_idempotency_key'=>'analytics:'.$key];$hash=\CajeerEngine\Queue\QueueOptions::fingerprint('analytics.deliver',$payload);
            if(isset($events[$key])&&$events[$key]['hash']!==$hash)throw new \InvalidArgumentException('Event id conflict');
            if(!isset($events[$key])&&count($events)>=30)throw new \InvalidArgumentException('Consent lease event limit reached');
            // Commit authorization before queue handoff. Retrying an unacknowledged request uses the same payload and idempotency key.
            $events[$key]??=['hash'=>$hash,'sent'=>false,'status'=>'queued'];return $payload;
        });
        $job=$this->queue->push('analytics.deliver',$payload);return ['id'=>$input['id'],'job_id'=>$job,'queued'=>true];
    }
    public function deliver(array $payload): void
    {
        $input=array_intersect_key($payload,array_flip(['grant_id','id','name','integration_id','revision','value','currency']));self::validate($input);
        $failure=$this->transaction(function(array &$state)use($payload,$input){
            $grant=$input['grant_id'];$key=$input['id'].':'.$input['integration_id'];$owner=$payload['owner']??'';
            if(!isset($state['grants'][$grant]['events'][$key])||($state['grants'][$grant]['owner']??null)!==$owner)return null;
            $record=&$state['grants'][$grant]['events'][$key];
            if(!is_array($record)||$record['sent']||$record['hash']!==\CajeerEngine\Queue\QueueOptions::fingerprint('analytics.deliver',$payload))return null;
            if(!$this->active($state,$grant,$owner)||!$this->target($input['integration_id'],$input['revision'])){$record['status']='cancelled';return null;}
            // Serialize revoke and send. A completed request cannot be recalled; later attempts cannot start after revoke.
            $result=$this->center->run($input['integration_id'],$input['revision'],'analytics.'.$input['name'],['value'=>$input['value'],'currency'=>$input['currency']],(string)$payload['actor'],false,$input['id']);
            if(!$result['ok']){$record['status']='failed';return 'Analytics integration delivery failed (HTTP '.(int)$result['status_code'].')';}
            $record['sent']=true;$record['status']='delivered';return null;
        });
        if($failure!==null)throw new \RuntimeException($failure);
    }
    public function status(string $owner,string $id): array
    {
        return $this->transaction(function(array &$state)use($owner,$id){
            $grant=$state['grants'][$id]??null;if(!$grant||$grant['owner']!==$owner)throw new \InvalidArgumentException('Unknown consent lease');
            $active=$this->active($state,$id,$owner);$items=[];
            foreach($grant['events'] as $key=>$record){[$eventId,$target]=explode(':',$key,2);$items[]=['id'=>$eventId,'integration_id'=>$target,'status'=>$record['sent']?'delivered':($active?$record['status']:'cancelled')];}
            return ['grant_id'=>$id,'active'=>$active,'expires'=>$grant['expires'],'items'=>$items];
        });
    }
    private function transaction(callable $fn): mixed
    {
        $dir=F::path($this->root,'storage/app/privacy');F::dir($dir,0700);$path=F::path($this->root,'storage/app/privacy/server-events.json');$lock=F::path($this->root,'storage/app/privacy/server-events.lock');$h=fopen($lock,'c+b');if(!$h)throw new \RuntimeException('Cannot open server events lock');
        try{F::permissions($lock,0600);if(!flock($h,LOCK_EX))throw new \RuntimeException('Cannot lock server events');$state=is_file($path)?F::read($path):['grants'=>[]];$before=$state;
            if(!is_array($state['grants']??null))throw new \RuntimeException('Invalid server events state');
            $state['grants']=array_filter($state['grants'],static fn($g)=>($g['expires']??0)>time()-3600);$result=$fn($state);if($state!==$before)F::json($path,$state,0600);return $result;
        }finally{fclose($h);}
    }
}
