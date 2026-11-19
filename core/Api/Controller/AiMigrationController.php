<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\{AiMigrationCopilot,AiMigrationRemediation,AiProviderException};
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\{JsonResponse,Request};

/** Migration RBAC is enforced by RouteRegistry; OpenAI access additionally requires a browser session. */
final readonly class AiMigrationController
{
    public function __construct(private AiMigrationCopilot $copilot,private AiMigrationRemediation $remediation,private RateLimiter $limiter,private AuditLogger $audit){}

    private function user(Request $request):string
    {
        $auth=$request->attributes->get('auth');
        if(!is_array($auth)||($auth['cookie']??false)!==true||($auth['session']??false)!==true
            ||!is_string($auth['user_id']??null)||$auth['user_id']==='')
            throw new \DomainException('An active admin browser session is required.');
        return $auth['user_id'];
    }

    public function report(Request $request,array $params):JsonResponse
    {
        $uid=$this->user($request);
        $limit=$this->limiter->hit('ai-migration-report',$uid,5,60);
        if(!$limit['allowed'])return new JsonResponse(['error'=>['code'=>'ai_migration_report_rate_limit','message'=>'Превышен лимит диагностики миграции.']],429,['Cache-Control'=>'no-store','Retry-After'=>(string)$limit['retry_after']]);
        return new JsonResponse(['data'=>$this->copilot->report((string)$params['id'])],200,['Cache-Control'=>'no-store']);
    }

    public function explain(Request $request,array $params):JsonResponse
    {
        $uid=$this->user($request);
        $body=$request->attributes->get('json',[]);
        if(!is_array($body)||$body!==[])throw new \InvalidArgumentException('AI migration explanation does not accept arbitrary input.');
        $limit=$this->limiter->hit('ai-migration-explain',$uid,3,60);
        if(!$limit['allowed'])return new JsonResponse(['error'=>['code'=>'ai_migration_rate_limit','message'=>'Превышен лимит AI-анализа миграции.']],429,
            ['Cache-Control'=>'no-store','Retry-After'=>(string)$limit['retry_after']]);
        try{
            $result=$this->copilot->explain($uid,(string)$params['id']);
            $this->audit->record('ai.migration.explained',$uid,['project_id'=>(string)$params['id'],'model'=>$result['model']]);
            return new JsonResponse(['data'=>$result],200,['Cache-Control'=>'no-store']);
        }catch(AiProviderException $e){return new JsonResponse(['error'=>['code'=>'ai_provider_unavailable','message'=>$e->getMessage()]],$e->httpStatus,['Cache-Control'=>'no-store']);}
    }
    private function limit(string $uid, string $operation, int $rate = 10): ?JsonResponse
    {
        $limit=$this->limiter->hit('ai-migration-'.$operation, $uid, $rate, 60);
        return $limit['allowed'] ? null : new JsonResponse(['error'=>['code'=>'rate_limited','message'=>'Превышен лимит операций миграции.']],429,
            ['Cache-Control'=>'no-store','Retry-After'=>(string)$limit['retry_after']]);
    }

    private function json(Request $request, array $keys): array
    {
        $body=$request->attributes->get('json', []);
        if (!is_array($body) || array_diff(array_keys($body), $keys)) throw new \InvalidArgumentException('Invalid remediation request.');
        return $body;
    }

    public function remediationOptions(Request $request,array $params): JsonResponse
    {
        $uid=$this->user($request);
        if($denied=$this->limit($uid,'options'))return $denied;
        $raw=$request->query->get('offset','0');
        if(!is_string($raw) || !preg_match('/^(0|[1-9][0-9]{0,6})$/D',$raw) || (int)$raw>1000000)throw new \InvalidArgumentException('Invalid remediation offset.');
        return new JsonResponse(['data'=>['options'=>$this->remediation->candidates((string)$params['id'],(int)$raw),
            'pending'=>$this->remediation->pending((string)$params['id'],$uid),
            'history'=>$this->remediation->history((string)$params['id'],$uid)]],200,['Cache-Control'=>'no-store']);
    }

    public function remediationPrepare(Request $request,array $params): JsonResponse
    {
        $uid=$this->user($request);
        if($denied=$this->limit($uid,'prepare',5))return $denied;
        $body=$this->json($request,['kind','source_path']);
        if (!is_string($body['kind']??null) || !is_string($body['source_path']??'')) throw new \InvalidArgumentException('Invalid remediation kind or path.');
        $proposal=$this->remediation->prepare((string)$params['id'],$uid,$body['kind'],$body['source_path']??'');
        return new JsonResponse(['data'=>$proposal],201,['Cache-Control'=>'no-store']);
    }

    public function remediationApply(Request $request,array $params): JsonResponse
    {
        $uid=$this->user($request);
        if($denied=$this->limit($uid,'apply',5))return $denied;
        if($this->json($request,[])!==[])throw new \InvalidArgumentException('Request must be empty.');
        $result=$this->remediation->approve((string)$params['id'],$uid,(string)$params['proposal']);
        return new JsonResponse(['data'=>$result],200,['Cache-Control'=>'no-store']);
    }

    public function remediationReject(Request $request,array $params): JsonResponse
    {
        $uid=$this->user($request);
        if($denied=$this->limit($uid,'reject',5))return $denied;
        if($this->json($request,[])!==[])throw new \InvalidArgumentException('Request must be empty.');
        return new JsonResponse(['data'=>$this->remediation->reject((string)$params['id'],$uid,(string)$params['proposal'])],200,['Cache-Control'=>'no-store']);
    }

    public function remediationRollback(Request $request,array $params): JsonResponse
    {
        $uid=$this->user($request);
        if($denied=$this->limit($uid,'rollback',5))return $denied;
        if($this->json($request,[])!==[])throw new \InvalidArgumentException('Request must be empty.');
        return new JsonResponse(['data'=>$this->remediation->rollback((string)$params['id'],$uid,(string)$params['proposal'])],200,['Cache-Control'=>'no-store']);
    }

}
