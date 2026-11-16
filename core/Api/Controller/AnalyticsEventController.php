<?php

declare(strict_types=1);
namespace CajeerEngine\Api\Controller;

use CajeerEngine\Analytics\EventConfig;
use CajeerEngine\Analytics\EventJournal;
use CajeerEngine\Analytics\ServerEvents;
use CajeerEngine\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalyticsEventController
{
    public function __construct(private EventConfig $config,private EventJournal $journal,private AuditLogger $audit,private ?ServerEvents $server=null) {}
    private function response(array $data): JsonResponse {return new JsonResponse(['data'=>$data],200,['Cache-Control'=>'no-store']);}
    public function index(Request $r): JsonResponse {return $this->response($this->config->get());}
    public function update(Request $r): JsonResponse
    {
        $data=$this->config->update($r->attributes->get('json',[]));$this->audit->record('tracking.events_updated',(string)($r->attributes->get('auth')['user_id']??''),['revision'=>$data['revision']]);return $this->response($data);
    }
    public function journal(Request $r): JsonResponse {return $this->response(['items'=>$this->journal->recent()]);}
    public function clear(Request $r): JsonResponse {$this->journal->clear();$this->audit->record('tracking.events_cleared',(string)($r->attributes->get('auth')['user_id']??''),[]);return $this->response(['items'=>[]]);}
    private function producer(Request $r): array
    {
        $auth=$r->attributes->get('auth',[]);if(($auth['session']??true)||($auth['cookie']??true)||empty($auth['token_id'])||$this->server===null)throw new \InvalidArgumentException('A dedicated server API token is required');return $auth;
    }
    public function grant(Request $r): JsonResponse
    {
        $auth=$this->producer($r);$body=$r->attributes->get('json',[]);return $this->response($this->server->grant($auth['token_id'],(string)$body['version'],(int)$body['ttl_seconds'],(bool)($body['marketing']??false)));
    }
    public function revoke(Request $r,array $params): JsonResponse
    {
        $auth=$this->producer($r);$this->server->revoke($auth['token_id'],(string)$params['id']);return $this->response(['revoked'=>true]);
    }
    public function serverStatus(Request $r,array $params): JsonResponse
    {
        $auth=$this->producer($r);return $this->response($this->server->status($auth['token_id'],(string)$params['id']));
    }
    public function enqueue(Request $r): JsonResponse
    {
        $auth=$this->producer($r);return $this->response($this->server->enqueue($auth['token_id'],(string)$auth['user_id'],$r->attributes->get('json',[])));
    }
    public function collect(Request $r): JsonResponse
    {
        // Browser-only diagnostic input; no auth cookies or arbitrary user payloads needed.
        if($r->headers->get('Origin')!==$r->getSchemeAndHttpHost()||($r->headers->has('Sec-Fetch-Site')&&$r->headers->get('Sec-Fetch-Site')!=='same-origin')||strlen($r->getContent())>2048)throw new \InvalidArgumentException('Invalid event origin or size');
        return $this->response($this->journal->collect($r->attributes->get('json',[]),$r->getHost()));
    }
}
