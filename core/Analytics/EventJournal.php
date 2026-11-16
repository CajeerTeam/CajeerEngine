<?php

declare(strict_types=1);
namespace CajeerEngine\Analytics;

use CajeerEngine\Privacy\ConsentPolicy;
use CajeerEngine\Update\OperationFiles as F;

/** Optional first-party diagnostics, not a visitor database. Bounded and idempotent. */
final readonly class EventJournal
{
    public const LIMIT=2000;
    public const TTL=604800;
    public function __construct(private string $root) {}
    public function collect(array $input,string $host): array
    {
        $policy=(new ConsentPolicy($this->root))->publicConfig();$config=$policy['events'];$tracking=$policy['tracking'];
        if(!$config['collect_diagnostics']||!$tracking['enabled']||!in_array(strtolower($host),$tracking['hosts'],true))throw new \InvalidArgumentException('Event diagnostics disabled for this host');
        if(array_diff(array_keys($input),['id','name','version','outcomes'])||count($input)!==4||!is_string($input['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$input['id'])||($input['version']??null)!==$policy['version']||!EventConfig::name($input['name']??null)||!is_array($input['outcomes']??null))throw new \InvalidArgumentException('Invalid event diagnostic');
        $rule=EventConfig::rule($config,$input['name']);if(!$rule||!in_array($input['name'],array_column($config['rules'],'name'),true))throw new \InvalidArgumentException('Event disabled');
        $outcomes=$input['outcomes'];if(!$outcomes||count($outcomes)>10)throw new \InvalidArgumentException('Invalid outcomes');
        foreach($outcomes as $provider=>$status){if((!in_array($provider,EventConfig::PROVIDERS,true)||$rule[$provider]===''||$tracking[$provider.'_id']===''||($provider==='metrika'&&in_array($rule[$provider],array_map(static fn($n)=>\CajeerEngine\Privacy\TrackingConfig::yandexGoal($tracking,$n),\CajeerEngine\Privacy\TrackingConfig::AD_EVENTS),true)))&&!\CajeerEngine\Privacy\TrackingConfig::adRoute($tracking,$provider,$input['name'])||!in_array($status,['queued','submitted','failed'],true))throw new \InvalidArgumentException('Invalid provider outcome');}
        return $this->transaction(function(array &$rows)use($input,$outcomes){
            foreach($rows as $row)if($row['id']===$input['id']){if($row['name']!==$input['name']||$row['outcomes']!=$outcomes)throw new \InvalidArgumentException('Event id conflict');return ['accepted'=>true,'duplicate'=>true];}
            $rows[]=['id'=>$input['id'],'name'=>$input['name'],'outcomes'=>$outcomes,'at'=>time()];
            $rows=array_slice($rows,-self::LIMIT);return ['accepted'=>true,'duplicate'=>false];
        });
    }
    public function recent(): array
    {
        return $this->transaction(static fn(array &$rows)=>array_reverse(array_slice($rows,-100)));
    }
    public function clear(): void {$this->transaction(static function(array &$rows){$rows=[];});}
    private function transaction(callable $fn): mixed
    {
        $dir=F::path($this->root,'storage/app/privacy');F::dir($dir,0700);$lock=F::path($this->root,'storage/app/privacy/event-journal.lock');$h=fopen($lock,'c+b');if(!$h)throw new \RuntimeException('Cannot open event journal');
        try{F::permissions($lock,0600);if(!flock($h,LOCK_EX))throw new \RuntimeException('Cannot lock event journal');$path=F::path($this->root,'storage/app/privacy/event-journal.json');$state=is_file($path)?F::read($path):['rows'=>[]];
            if(!is_array($state['rows']??null))throw new \RuntimeException('Invalid event journal');
            $rows=array_values(array_filter($state['rows'],static fn($row)=>($row['at']??0)>time()-self::TTL));$before=$state['rows'];$result=$fn($rows);
            if($before!==$rows)F::json($path,['rows'=>$rows],0600);return $result;
        }finally{fclose($h);}
    }
}
