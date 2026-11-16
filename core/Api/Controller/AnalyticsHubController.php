<?php
declare(strict_types=1);
namespace CajeerEngine\Api\Controller;

use CajeerEngine\Analytics\Reporting\AnalyticsHub;
use CajeerEngine\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalyticsHubController
{
    public function __construct(private AnalyticsHub $hub,private AuditLogger $audit) {}
    private function response(array $data,int $status=200): JsonResponse {return new JsonResponse(['data'=>$data],$status,['Cache-Control'=>'no-store']);}
    private function record(Request $r,string $action,string $id): void {$this->audit->record('analytics.report.'.$action,(string)($r->attributes->get('auth')['user_id']??''),['connection_id'=>$id]);}
    public function index(Request $r): JsonResponse {return $this->response($this->hub->overview());}
    public function create(Request $r): JsonResponse {$v=$this->hub->save(null,$r->attributes->get('json',[]));$this->record($r,'created',$v['id']);return $this->response($v,201);}
    public function update(Request $r,array $p): JsonResponse {$v=$this->hub->save($p['id'],$r->attributes->get('json',[]));$this->record($r,'updated',$p['id']);return $this->response($v);}
    public function delete(Request $r,array $p): JsonResponse {$this->hub->delete($p['id'],$r->attributes->get('json',[])['revision']);$this->record($r,'deleted',$p['id']);return $this->response(['deleted'=>true]);}
    public function sync(Request $r,array $p): JsonResponse {$b=$r->attributes->get('json',[]);$v=$this->hub->enqueue($p['id'],$b['from']??null,$b['to']??null);$this->record($r,'queued',$p['id']);return $this->response($v,202);}
    public function report(Request $r,array $p): JsonResponse {return $this->response($this->hub->report($p['id'],$r->query->get('from'),$r->query->get('to')));}
}
