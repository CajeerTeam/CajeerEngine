<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\{AiMigrationCopilot,AiProviderException};
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\{JsonResponse,Request};

/** Migration RBAC is enforced by RouteRegistry; OpenAI access additionally requires a browser session. */
final readonly class AiMigrationController
{
    public function __construct(private AiMigrationCopilot $copilot,private RateLimiter $limiter,private AuditLogger $audit){}

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
}
