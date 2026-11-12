<?php
declare(strict_types=1);
namespace CajeerEngine\Extension\Runtime;

/** Process-local registrations owned by an extension. No arbitrary core route replacement. */
final class PlatformServices
{
    private array $actions=[]; private array $jobs=[]; private array $pages=[]; private array $tasks=[]; private array $services=[];
    private function key(ExtensionContext $c,string $name,string $permission): string
    {
        $c->requirePermission($permission);
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$name)) throw new \InvalidArgumentException('Invalid registration name');
        return $c->slug().'.'.$name;
    }
    private function put(array &$map,string $key,mixed $value): void { if (isset($map[$key])) throw new \RuntimeException('Duplicate registration: '.$key); $map[$key]=$value; }
    public function service(ExtensionContext $c,string $name,callable $factory): void { $key=$this->key($c,$name,'services.register'); $this->put($this->services,$key,$factory); $c->container->set('extension.'.$key,$factory); }
    public function action(ExtensionContext $c,string $name,callable $handler,bool $write=false): void { $this->put($this->actions,$this->key($c,$name,'routes.register'),['handler'=>$handler,'write'=>$write]); }
    public function page(ExtensionContext $c,string $name,string $title,string $action): void { $key=$this->key($c,$name,'admin.register'); $this->put($this->pages,$key,['slug'=>$c->slug(),'name'=>$name,'title'=>$title,'action'=>$action]); }
    public function job(ExtensionContext $c,string $name,callable $handler): void { $this->put($this->jobs,'extension.'.$this->key($c,$name,'queue.register'),$handler); }
    public function task(ExtensionContext $c,string $name,callable $handler): void { $this->put($this->tasks,$this->key($c,$name,'scheduler.register'),$handler); }
    public function execute(string $slug,string $name,array $payload,bool $write): array { $a=$this->actions[$slug.'.'.$name]??throw new \OutOfBoundsException('Extension action not found'); if ($a['write']!==$write) throw new \OutOfBoundsException('Extension action method mismatch'); $result=($a['handler'])($payload); if (!is_array($result)) throw new \RuntimeException('Extension action must return an array'); return $result; }
    public function handleJob(string $name,array $payload,?\CajeerEngine\Queue\JobContext $context=null): void { $handler=$this->jobs[$name]??throw new \RuntimeException('Extension job unavailable: '.$name); $handler($payload,$context); }
    public function pages(): array { return array_values($this->pages); }
    public function tasks(): array { return array_keys($this->tasks); }
    public function runTasks(string $root,?\CajeerEngine\Scheduler\TaskRunStore $store=null): array {
        $store??=new \CajeerEngine\Scheduler\TaskRunStore($root); $results=[];
        foreach($this->tasks as $name=>$handler)$results[$name]=$store->run('extension.'.$name,60,$handler);
        return $results;
    }
}
