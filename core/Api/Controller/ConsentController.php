<?php

declare(strict_types=1);
namespace CajeerEngine\Api\Controller;

use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Privacy\ConsentPolicy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class ConsentController
{
    public function __construct(private ConsentPolicy $policy,private AuditLogger $audit) {}
    public function index(Request $request): JsonResponse { return $this->response($this->policy->get()); }
    public function publicConfig(Request $request): JsonResponse { return $this->response($this->policy->publicConfig()); }
    public function update(Request $request): JsonResponse
    {
        $policy=$this->policy->update($request->attributes->get('json',[]));
        $this->audit->record('privacy.policy_updated',(string)($request->attributes->get('auth')['user_id']??''),['revision'=>$policy['revision']]);
        return $this->response($policy);
    }
    private function response(array $data): JsonResponse { return new JsonResponse(['data'=>$data],200,['Cache-Control'=>'no-store']); }
}
