<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Integration\IntegrationCenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class IntegrationController
{
    public function __construct(private IntegrationCenter $center, private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse { return $this->response($this->center->overview()); }

    public function store(Request $request): JsonResponse
    {
        $item=$this->center->create($this->body($request),$this->actor($request));
        $this->record($request,'created',$item['id']);
        return $this->response($item,201);
    }

    public function update(Request $request, array $parameters): JsonResponse
    {
        $item=$this->center->update($parameters['id'],$this->body($request),$this->actor($request));
        $this->record($request,'updated',$item['id']); return $this->response($item);
    }

    public function secret(Request $request, array $parameters): JsonResponse
    {
        $body=$this->body($request);
        $item=$this->center->rotate($parameters['id'],$body['revision'],$body['secret'],$this->actor($request));
        $this->record($request,'credential_changed',$item['id']); return $this->response($item);
    }

    public function delete(Request $request, array $parameters): JsonResponse
    {
        $this->center->delete($parameters['id'],$this->body($request)['revision']);
        $this->record($request,'deleted',$parameters['id']); return new JsonResponse(null,204);
    }

    public function test(Request $request, array $parameters): JsonResponse
    {
        $result=$this->center->run($parameters['id'],$this->body($request)['revision'],'integration.test',['message'=>'CajeerEngine integration test'],$this->actor($request),true);
        $this->record($request,'tested',$parameters['id'],$result); return $this->response($result);
    }

    public function send(Request $request, array $parameters): JsonResponse
    {
        $body=$this->body($request);
        // Preserve object/array distinctions in nested JSON (including empty objects).
        $payload=json_decode($request->getContent(),false,64,JSON_THROW_ON_ERROR)->payload;
        $result=$this->center->run($parameters['id'],$body['revision'],$body['event'],$payload,$this->actor($request));
        $this->record($request,'executed',$parameters['id'],$result); return $this->response($result);
    }

    private function body(Request $request): array { return $request->attributes->get('json',[]); }
    private function actor(Request $request): string { return (string)($request->attributes->get('auth')['user_id'] ?? ''); }
    private function response(array $data, int $status=200): JsonResponse
    {
        $response=new JsonResponse(['data'=>$data],$status); $response->headers->set('Cache-Control','no-store'); return $response;
    }
    private function record(Request $request,string $action,string $id,array $result=[]): void
    {
        $context=['integration_id'=>$id];
        if ($result!==[]) $context += ['event_id'=>$result['event_id'],'ok'=>$result['ok'],'status_code'=>$result['status_code']];
        $this->audit->record('integration.'.$action,$this->actor($request),$context);
    }
}
