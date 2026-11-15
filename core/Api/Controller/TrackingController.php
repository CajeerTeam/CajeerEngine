<?php

declare(strict_types=1);
namespace CajeerEngine\Api\Controller;

use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Privacy\TrackingConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class TrackingController
{
    public function __construct(private TrackingConfig $config,private AuditLogger $audit) {}
    public function index(Request $request): JsonResponse { return $this->response($this->config->get()); }
    public function update(Request $request): JsonResponse
    {
        $value=$this->config->update($request->attributes->get('json',[]));
        $this->audit->record('tracking.config_updated',(string)($request->attributes->get('auth')['user_id']??''),['revision'=>$value['revision'],'enabled'=>$value['enabled']]);
        return $this->response($value);
    }
    private function response(array $data): JsonResponse { return new JsonResponse(['data'=>$data],200,['Cache-Control'=>'no-store']); }
}
