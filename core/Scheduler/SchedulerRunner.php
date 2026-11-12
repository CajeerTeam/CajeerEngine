<?php

declare(strict_types=1);

namespace CajeerEngine\Scheduler;

use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Search\SearchIndexer;

final readonly class SchedulerRunner
{
    public function __construct(
        private string $rootPath,
        private SearchIndexer $searchIndexer,
        private WebhookRepository $webhooks,
        private WebhookDispatcher $dispatcher,
        private ManagedQueueDriverInterface $queue,
        private ?\CajeerEngine\Extension\Runtime\PlatformServices $platform = null,
        private ?TaskRunStore $runStore = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function tasks(): array
    {
        return array_map(function($task){$name=$task['name'];$task['state']=($this->runStore??new TaskRunStore($this->rootPath))->status(in_array($name,['tmp.cleanup','queue.cleanup_processed','search.index_published','webhooks.dispatch_pending'],true)?$name:'extension.'.$name);return $task;},array_merge([
            ['name' => 'tmp.cleanup', 'description' => 'Удаление старых временных файлов.', 'interval' => 'hourly'],
            ['name' => 'queue.cleanup_processed', 'description' => 'Удаление старых обработанных file-queue задач.', 'interval' => 'daily'],
            ['name' => 'search.index_published', 'description' => 'Проверка и восстановление актуальности поискового каталога.', 'interval' => 'every_minute'],
            ['name' => 'webhooks.dispatch_pending', 'description' => 'Отправка pending-событий Event API.', 'interval' => 'every_minute'],
        ], array_map(static fn($n)=>['name'=>$n,'description'=>'Extension task','interval'=>'every_minute'],$this->platform?->tasks()??[])));
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $store=$this->runStore??new TaskRunStore($this->rootPath);
        $results=[];
        $results['tmp.cleanup']=$store->run('tmp.cleanup',3600,fn()=>['removed'=>$this->cleanupTmp()]);
        $results['queue.cleanup_processed']=$store->run('queue.cleanup_processed',86400,fn()=>['removed'=>$this->queue->cleanupProcessed()]);
        $results['search.index_published']=$store->run('search.index_published',60,fn()=>$this->searchIndexer->reconcile());
        $results['webhooks.dispatch_pending']=$store->run('webhooks.dispatch_pending',60,fn()=>$this->webhooks->enqueuePending($this->queue,50));
        if(\CajeerEngine\Observability\TraceExporter::enabled())$results['observability.export_traces']=$store->run('observability.export_traces',60,fn()=>(new \CajeerEngine\Observability\TraceExporter($this->rootPath))->flush());
        $results['extensions']=$this->platform?->runTasks($this->rootPath,$store)??[];
        $ok=true;foreach($results as $name=>$result){if($name==='extensions'){foreach($result as $r)if(($r['ok']??true)===false)$ok=false;}elseif(($result['ok']??true)===false)$ok=false;}
        $this->writeLog($results);
        return ['ok'=>$ok,'ran_at'=>gmdate(DATE_ATOM),'tasks'=>$results];
    }

    private function cleanupTmp(): int
    {
        $dir = $this->rootPath . '/storage/tmp';
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $threshold = time() - 86400;
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }
            if ($file->getMTime() < $threshold && @unlink($file->getPathname())) {
                $removed++;
            }
        }
        return $removed;
    }

    /** @param array<string, mixed> $results */
    private function writeLog(array $results): void
    {
        $path = $this->rootPath . '/storage/logs/scheduler.jsonl';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $summary=[];foreach($results as $name=>$result){if($name==='extensions')continue;$summary[$name]=['ok'=>$result['ok']??true,'skipped'=>isset($result['skipped'])];}
        // Detailed task state lives in TaskRunStore. Keep the rotating runtime log bounded.
        (new \CajeerEngine\Runtime\RuntimeLogger($this->rootPath))->info('Scheduled task finished',['status'=>in_array(false,array_column($summary,'ok'),true)?'failure':'success']);
    }
}
