<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

use CajeerEngine\Support\JsonFile;
use CajeerEngine\Update\OperationFiles;

/** Bounded local spool and OTLP/HTTP JSON export. No request payloads or SQL text. */
final readonly class TraceExporter
{
    public function __construct(private string $root) {}
    public static function enabled(): bool{return trim((string)getenv('OBSERVABILITY_OTLP_ENDPOINT'))!=='';}
    public static function sampled(TraceContext $trace): bool
    {
        if(!self::enabled())return false;
        if($trace->parentId!==null)return (hexdec($trace->flags)&1)===1;
        $rate=getenv('OBSERVABILITY_TRACE_SAMPLE_RATE');$rate=$rate===false?0.1:max(0.0,min(1.0,(float)$rate));
        return hexdec(substr($trace->traceId,0,8))/4294967296<$rate;
    }
    /** Store one finished span, using trusted operation names only. */
    public function record(TraceContext $trace,string $kind,string $operation,float $start,float $duration,bool $ok): void
    {
        if(!self::sampled($trace))return;
        try{
            if(!in_array($kind,['http','job','scheduler','database'],true))return;
            $name=$kind==='http'&&in_array($operation,array_column(\CajeerEngine\Http\RouteRegistry::all(),2),true)?$operation:match($kind){'http'=>'http.request','job'=>'queue.job','scheduler'=>'scheduler.task','database'=>'database.operation'};
            $dir=$this->directory();$lock=fopen($dir.'/spool.lock','c+');if(!$lock)return;
            try{if(!flock($lock,LOCK_EX|LOCK_NB))return;$files=glob($dir.'/span-*.json')?:[];if(count($files)>=1000){error_log('CajeerEngine: trace spool full');return;}
                $span=['traceId'=>$trace->traceId,'spanId'=>$trace->spanId,'name'=>$name,'kind'=>$kind==='http'?2:1,'startTimeUnixNano'=>sprintf('%.0F',$start*1e9),'endTimeUnixNano'=>sprintf('%.0F',($start+max(0.0,$duration))*1e9),'status'=>['code'=>$ok?1:2],'attributes'=>[['key'=>'cajeer.kind','value'=>['stringValue'=>$kind]]]];
                if($trace->parentId!==null)$span['parentSpanId']=$trace->parentId;
                (new JsonFile($dir.'/span-'.$trace->spanId.'.json'))->writeObject($span);
            }finally{fclose($lock);}
        }catch(\Throwable){error_log('CajeerEngine: trace spool write failed');}
    }
    private function directory(): string{$dir=$this->root.'/storage/logs/observability/traces';OperationFiles::dir($dir,0770);return $dir;}
    /** Send at most 100 spans; retain them on failure, including OTLP partial success. */
    public function flush(): array
    {
        if(!self::enabled())return ['ok'=>true,'sent'=>0,'disabled'=>true];
        $endpoint=(string)getenv('OBSERVABILITY_OTLP_ENDPOINT');$p=parse_url($endpoint);
        if(!is_array($p)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||preg_match('/[\x00-\x20\x7f]/',$endpoint))throw new \RuntimeException('Invalid OTLP endpoint');
        if(($p['scheme']??'')!=='https'&&!(($p['scheme']??'')==='http'&&in_array($p['host'],['127.0.0.1','localhost','[::1]'],true)))throw new \RuntimeException('OTLP endpoint requires HTTPS or loopback HTTP');
        $dir=$this->directory();$lock=fopen($dir.'/export.lock','c+');if(!$lock)throw new \RuntimeException('Cannot lock trace export');
        try{
            if(!flock($lock,LOCK_EX|LOCK_NB))return ['ok'=>true,'sent'=>0,'busy'=>true];
            $files=array_slice(glob($dir.'/span-*.json')?:[],0,100);if(!$files)return ['ok'=>true,'sent'=>0];$spans=[];foreach($files as $file)$spans[]=(new JsonFile($file))->readObject();
            $version=trim((string)@file_get_contents($this->root.'/VERSION'));
            $body=json_encode(['resourceSpans'=>[['resource'=>['attributes'=>[['key'=>'service.name','value'=>['stringValue'=>'cajeerengine']],['key'=>'service.version','value'=>['stringValue'=>$version]]]],'scopeSpans'=>[['scope'=>['name'=>'cajeerengine.runtime'],'spans'=>$spans]]]]],JSON_THROW_ON_ERROR);
            $headers="Content-Type: application/json\r\nAccept: application/json\r\n";$token=(string)getenv('OBSERVABILITY_OTLP_TOKEN');if($token!==''){if(preg_match('/[\x00-\x20\x7f]/',$token))throw new \RuntimeException('Invalid OTLP token');$headers.='Authorization: Bearer '.$token."\r\n";}
            $context=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>$body,'timeout'=>5,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            $stream=@fopen($endpoint,'rb',false,$context);if(!$stream)return ['ok'=>false,'sent'=>0,'error'=>'collector_unavailable'];
            try{$meta=stream_get_meta_data($stream);$response=stream_get_contents($stream,65537);$status=(int)substr($meta['wrapper_data'][0]??'',9,3);}finally{fclose($stream);}
            if($status<200||$status>=300||$response===false||strlen($response)>65536)return ['ok'=>false,'sent'=>0,'error'=>'collector_rejected'];
            try{$reply=$response===''?[]:json_decode($response,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){return ['ok'=>false,'sent'=>0,'error'=>'collector_invalid_response'];}
            if((int)($reply['partialSuccess']['rejectedSpans']??0)>0)return ['ok'=>false,'sent'=>0,'error'=>'collector_partial_success'];
            foreach($files as $file)OperationFiles::remove($file);
            return ['ok'=>true,'sent'=>count($files)];
        }finally{fclose($lock);}
    }
}
