<?php
declare(strict_types=1);
namespace CajeerExample;
use CajeerEngine\Extension\Contracts\AbstractExtensionProvider;
use CajeerEngine\Extension\Runtime\ExtensionContext;
use CajeerEngine\Runtime\ServiceContainer;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Update\OperationFiles as F;

/** Working opt-in list manager; no outbound mail is sent by this example. */
final class NewsletterProvider extends AbstractExtensionProvider
{
    public function install(ExtensionContext $c): void {
        $c->requirePermission('content.write'); $types=$c->container->get(ContentTypeRepository::class);
        if (!$types->find('newsletter')) $types->create(['handle'=>'newsletter','name'=>'Newsletter','fields'=>[['handle'=>'subject','label'=>'Subject','type'=>'text']]]);
    }
    public function register(ServiceContainer $container,ExtensionContext $c): void {
        $p=$c->registrations(); $path=$c->paths()['storage'].'/subscribers.json';
        $p->service($c,'count',static fn()=> is_file($path)?count(F::read($path)):0);
        $p->action($c,'overview',fn()=>['title'=>'Newsletter subscribers','data'=>['count'=>$container->get('extension.'.$c->slug().'.count')],'form'=>['action'=>'subscribe','fields'=>[['name'=>'email','label'=>'Opt-in email','type'=>'email','required'=>true]],'submit'=>'Add subscriber']]);
        $p->action($c,'subscribe',function(array $input) use($path,$container,$c): array {
            $email=strtolower(trim((string)($input['email']??''))); if (!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254) throw new \InvalidArgumentException('Invalid email');
            $h=fopen($path.'.lock','c+'); if (!$h) throw new \RuntimeException('Subscriber lock unavailable');
            try { if (!flock($h,LOCK_EX)) throw new \RuntimeException('Subscriber lock failed'); $list=is_file($path)?F::read($path):[]; $list[hash('sha256',$email)]=['email'=>$email,'created_at'=>gmdate(DATE_ATOM)]; F::json($path,$list,0640); } finally { fclose($h); }
            $job=$container->get(ManagedQueueDriverInterface::class)->push('extension.'.$c->slug().'.snapshot'); return ['count'=>count($list),'job'=>$job];
        },true);
        $snapshot=static function()use($path,$c):void { F::json($c->paths()['storage'].'/summary.json',['count'=>is_file($path)?count(F::read($path)):0,'at'=>gmdate(DATE_ATOM)],0640); };
        $p->job($c,'snapshot',$snapshot); $p->task($c,'refresh',$snapshot); $p->page($c,'subscribers','Newsletter','overview');
    }
    public function upgrade(ExtensionContext $c,string $previous): void { $c->log('newsletter.upgraded',['from'=>$previous]); }
}
