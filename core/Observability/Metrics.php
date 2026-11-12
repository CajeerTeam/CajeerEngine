<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

use CajeerEngine\Support\JsonFile;
use CajeerEngine\Update\OperationFiles;

/** Bounded-cardinality, cross-process histograms. SQL observations are batched. */
final class Metrics
{
    private const BUCKETS=[0.005,0.01,0.025,0.05,0.1,0.25,0.5,1,2.5,5,10,30];
    private const KINDS=['http','job','scheduler','database'];
    private static array $pending=[];
    private static array $pendingCount=[];
    private static bool $shutdownRegistered=false;
    private static ?int $pid=null;
    public function __construct(private readonly string $root) {}
    public function observe(string $kind,string $operation,string $outcome,float $seconds): void
    {
        [$key,$row]=$this->sample($kind,$operation,$outcome,$seconds);$this->persist([$key=>$row]);
    }
    /** High frequency SQL events coalesce; a killed process may lose the last batch of telemetry, never business data. */
    public function buffered(string $kind,string $operation,string $outcome,float $seconds): void
    {
        self::checkProcess();
        if(!self::$shutdownRegistered){register_shutdown_function(static function(){self::flushAll();});self::$shutdownRegistered=true;}
        [$key,$row]=$this->sample($kind,$operation,$outcome,$seconds);
        self::$pending[$this->root][$key]=self::combine(self::$pending[$this->root][$key]??null,$row);
        self::$pendingCount[$this->root]=(self::$pendingCount[$this->root]??0)+1;
        if(self::$pendingCount[$this->root]>=128)$this->flush();
    }
    private static function checkProcess(): void
    {
        $pid=getmypid();if(self::$pid!==null&&self::$pid!==$pid){self::$pending=[];self::$pendingCount=[];}self::$pid=$pid;
    }
    public function flush(): void
    {
        self::checkProcess();$rows=self::$pending[$this->root]??[];unset(self::$pending[$this->root],self::$pendingCount[$this->root]);if($rows)$this->persist($rows);
    }
    public static function flushAll(): void{self::checkProcess();foreach(array_keys(self::$pending) as $root)(new self($root))->flush();}
    private function sample(string $kind,string $operation,string $outcome,float $seconds): array
    {
        if(!in_array($kind,self::KINDS,true))throw new \InvalidArgumentException('Unknown metric kind');
        $operation=$this->operation($kind,$operation);$outcome=in_array($outcome,['1xx','2xx','3xx','4xx','5xx','success','failure','lost','skipped'],true)?$outcome:'other';
        $seconds=max(0.0,min(86400.0,is_finite($seconds)?$seconds:0.0));
        return [$kind.'|'.$operation.'|'.$outcome,['count'=>1,'sum'=>$seconds,'buckets'=>array_map(static fn($b)=>(int)($seconds<=$b),self::BUCKETS)]];
    }
    private static function combine(?array $a,array $b): array
    {
        if($a===null)return $b;$a['count']+=$b['count'];$a['sum']+=$b['sum'];foreach($b['buckets'] as $i=>$v)$a['buckets'][$i]+=$v;return $a;
    }
    private function persist(array $rows): void
    {
        if(!is_dir($this->root.'/storage'))return;
        $groups=[];foreach($rows as $key=>$row)$groups[hexdec(substr(hash('sha256',$key),0,1))][$key]=$row;
        foreach($groups as $shard=>$updates)try {
            $dir=$this->root.'/storage/logs/observability'; OperationFiles::dir($dir,0770);
            $path=$dir.'/metrics-'.$shard.'.json'; $lock=fopen($path.'.lock','c+');
            if(!$lock)throw new \RuntimeException('Metrics lock unavailable');
            try {
                if(!flock($lock,LOCK_EX))throw new \RuntimeException('Metrics lock failed');
                $file=new JsonFile($path);$data=$file->readObject();
                foreach($updates as $key=>$row)$data[$key]=self::combine($data[$key]??null,$row);
                $file->writeObject($data);
            }finally{fclose($lock);}
        }catch(\Throwable){error_log('CajeerEngine: metrics write failed');}
    }
    private function operation(string $kind,string $name): string
    {
        if($kind==='http') {
            static $routes=null; $routes??=array_column(\CajeerEngine\Http\RouteRegistry::all(),2);
            return in_array($name,$routes,true)||in_array($name,['unmatched','bootstrap','maintenance','install','upgrade','health','metrics'],true)?$name:'other';
        }
        $known=match($kind){'job'=>['log.message','search.index_all','webhook.dispatch','webhook.deliver','webhooks.dispatch_pending','worker'], 'scheduler'=>['tmp.cleanup','queue.cleanup_processed','search.index_published','webhooks.dispatch_pending','runner','observability.export_traces'], 'database'=>['connect','transaction','query','health'],default=>[]};
        return in_array($name,$known,true)?$name:(str_starts_with($name,'extension.')?'extension':'other');
    }
    public function snapshot(): array
    {
        $this->flush();$result=[];for($i=0;$i<16;$i++){
            $path=$this->root.'/storage/logs/observability/metrics-'.$i.'.json';
            foreach((new JsonFile($path))->readObject() as $key=>$row)$result[$key]=$row;
        }ksort($result);return $result;
    }
    public function httpSummary(): array
    {
        $count=0;$errors=0;$sum=0.0;$buckets=array_fill(0,count(self::BUCKETS),0);
        foreach($this->snapshot() as $key=>$row){if(!str_starts_with($key,'http|'))continue;$count+=$row['count'];$sum+=$row['sum'];if(str_ends_with($key,'|5xx'))$errors+=$row['count'];foreach($row['buckets'] as $i=>$v)$buckets[$i]+=$v;}
        $p95=null;if($count>0)foreach(self::BUCKETS as $i=>$bound)if($buckets[$i]>=ceil($count*0.95)){$p95=$bound*1000;break;}
        return ['requests'=>$count,'errors_5xx'=>$errors,'average_ms'=>$count?round($sum/$count*1000,2):null,'p95_bucket_ms'=>$p95];
    }
    public function prometheus(): string
    {
        $out=[];$rows=$this->snapshot();
        foreach(self::KINDS as $kind){
            $prefix='cajeer_'.$kind.'_duration_seconds';
            $out[]='# HELP '.$prefix.' Observed '.$kind.' operation duration.';$out[]='# TYPE '.$prefix.' histogram';
            foreach($rows as $key=>$row){[$k,$operation,$status]=explode('|',$key);if($k!==$kind)continue;
                $label='operation="'.$operation.'",outcome="'.$status.'"';
                foreach(self::BUCKETS as $i=>$bound)$out[]=$prefix.'_bucket{'.$label.',le="'.$bound.'"} '.$row['buckets'][$i];
                $out[]=$prefix.'_bucket{'.$label.',le="+Inf"} '.$row['count'];
                $out[]=$prefix.'_sum{'.$label.'} '.sprintf('%.9F',$row['sum']);$out[]=$prefix.'_count{'.$label.'} '.$row['count'];
            }
        }
        $out[]='# TYPE cajeer_http_requests_total counter';
        foreach($rows as $key=>$row){[$k,$operation,$status]=explode('|',$key);if($k==='http')$out[]='cajeer_http_requests_total{route="'.$operation.'",status_class="'.$status.'"} '.$row['count'];}
        return implode("\n",$out)."\n";
    }
}
