<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

final class JobHandlerRegistry
{
    private array $handlers=[];
    public function register(string $name,callable $handler): void {if(isset($this->handlers[$name]))throw new \LogicException('Duplicate job handler: '.$name);$this->handlers[$name]=$handler;}
    public function handle(QueuedJob $job,JobContext $context): void { $handler=$this->handlers[$job->name]??throw new \RuntimeException('Unknown job: '.$job->name);$handler($job->payload,$context); }
}
