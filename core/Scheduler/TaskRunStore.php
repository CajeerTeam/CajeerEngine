<?php
declare(strict_types=1);
namespace CajeerEngine\Scheduler;

use CajeerEngine\Support\JsonFile;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use PDO;

/** One start per UTC slot; file lock on one host, PostgreSQL session lock across hosts. */
final readonly class TaskRunStore
{
    public function __construct(private string $root,private ?PDO $pdo=null) {}
    public static function fromConfig(string $root,ConfigRepository $config,DatabaseManager $db): self
    {
        $driver=$config->string('scheduler.driver','file');
        if($driver==='file')return new self($root);
        if($driver!=='pgsql'||$db->driver()!=='pgsql')throw new \RuntimeException('Scheduler requires file or PostgreSQL storage');
        // Dedicated connection: a handler transaction cannot accidentally release the scheduler lease.
        return new self($root,$db->connection('pgsql'));
    }
    public function run(string $name,int $interval,callable $handler,?int $now=null): array
    {
        if($interval<60)throw new \InvalidArgumentException('Invalid task interval');
        return $this->locked($name,function()use($name,$interval,$handler,$now){
            $now??=$this->pdo?(int)$this->pdo->query('SELECT floor(extract(epoch FROM clock_timestamp()))')->fetchColumn():time();$slot=intdiv($now,$interval);
            $state=$this->status($name);
            if(isset($state['slot'])&&(int)$state['slot']>=$slot)return ['skipped'=>'already run','status'=>$state['status']??'unknown'];
            $history=$state['history']??[];
            if(isset($state['slot'])){$prev=$state;unset($prev['history']);if($prev['status']==='running')$prev['status']='interrupted';$history[]=$prev;}
            $state=['name'=>$name,'slot'=>$slot,'interval_seconds'=>$interval,'started_at'=>gmdate(DATE_ATOM,$now),'status'=>'running','last_success_at'=>$state['last_success_at']??null,'history'=>array_slice($history,-50)];$this->write($name,$state);
            try {$result=$handler();if(is_array($result)&&($result['ok']??true)===false)throw new \RuntimeException((string)($result['error']??'Scheduled task returned failure'));$state['status']='success';$state['last_success_at']=gmdate(DATE_ATOM);$result=is_array($result)?$result:[];}
            catch(\Throwable $e){$state['status']='failed';$state['error']=\CajeerEngine\Queue\QueueOptions::error($e->getMessage(),1000);$result=['ok'=>false,'error'=>$state['error']];}
            $state['finished_at']=gmdate(DATE_ATOM);$this->write($name,$state);return $result+['ok'=>$state['status']==='success'];
        });
    }
    private function locked(string $name,callable $fn): array
    {
        if($this->pdo){
            $key="hashtextextended(current_schema() || ':cajeer.scheduler:' || CAST(:name AS text),0)";
            $s=$this->pdo->prepare("SELECT pg_try_advisory_lock($key)");$s->execute(['name'=>$name]);$locked=$s->fetchColumn();$s->closeCursor();
            if(!in_array($locked,[true,1,'1','t','true'],true))return ['skipped'=>'busy'];
            try{return $fn();}finally{$s=$this->pdo->prepare("SELECT pg_advisory_unlock($key)");$s->execute(['name'=>$name]);$s->closeCursor();}
        }
        $dir=F::path($this->root,'storage/app/scheduler');F::dir($dir,0770);$key=hash('sha256',$name);
        $h=fopen(F::path($this->root,'storage/app/scheduler/'.$key.'.lock'),'c+');if(!$h)throw new \RuntimeException('Cannot open schedule lock');
        try{if(!flock($h,LOCK_EX|LOCK_NB))return ['skipped'=>'busy'];return $fn();}finally{fclose($h);}
    }
    public function status(string $name): array
    {
        if($this->pdo){$s=$this->pdo->prepare('SELECT state FROM ce_scheduler_runs WHERE name=:name');$s->execute(['name'=>$name]);$raw=$s->fetchColumn();return is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):['name'=>$name,'status'=>'never'];}
        return (new JsonFile(F::path($this->root,'storage/app/scheduler/'.hash('sha256',$name).'.json')))->readObject(['name'=>$name,'status'=>'never']);
    }
    private function write(string $name,array $state): void
    {
        if($this->pdo){$s=$this->pdo->prepare('INSERT INTO ce_scheduler_runs(name,state,updated_at) VALUES (:name,CAST(:state AS jsonb),clock_timestamp()) ON CONFLICT(name) DO UPDATE SET state=EXCLUDED.state,updated_at=EXCLUDED.updated_at');$s->execute(['name'=>$name,'state'=>json_encode($state,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)]);return;}
        (new JsonFile(F::path($this->root,'storage/app/scheduler/'.hash('sha256',$name).'.json')))->writeObject($state);
    }
}
