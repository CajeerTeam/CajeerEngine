<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\AiExternalAnalytics;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Analytics-only RBAC at the router; paid explanation requires a browser session and CSRF. */
final readonly class AiAnalyticsController
{
    public function __construct(private AiExternalAnalytics $analytics, private RateLimiter $limiter, private AuditLogger $audit) {}

    public function insights(Request $request): JsonResponse
    {
        $id=$this->connectionId($request->query->get('connection_id'));
        [$from,$to]=$this->dates($request->query->get('from'),$request->query->get('to'));
        return $this->response($this->analytics->insights($id,$from,$to));
    }

    public function explain(Request $request): JsonResponse
    {
        $identity=$request->attributes->get('auth');
        if (!is_array($identity)||($identity['cookie']??false)!==true||($identity['session']??false)!==true||
            !is_string($identity['user_id']??null)||$identity['user_id']==='')
            throw new \DomainException('An active admin browser session is required.');
        $uid=$identity['user_id'];
        $body=$request->attributes->get('json',[]);
        if (!is_array($body)||array_diff(array_keys($body),['connection_id','from','to']))
            throw new \InvalidArgumentException('Invalid analytics explanation request.');
        $id=$this->connectionId($body['connection_id']??null);
        [$from,$to]=$this->dates($body['from']??null,$body['to']??null);
        $hit=$this->limiter->hit('ai-analytics-user',$uid,3,60);
        if (!$hit['allowed']) return new JsonResponse(['error'=>['code'=>'ai_analytics_rate_limit','message'=>'Лимит: 3 AI-анализа в минуту.']],429,
            ['Cache-Control'=>'no-store','Retry-After'=>(string)$hit['retry_after']]);
        try {
            $result=$this->analytics->explain($uid,$id,$from,$to);
            $this->audit->record('ai.analytics.explained',$uid,['connection_id'=>$id,'provider'=>$result['insights']['provider'],
                'from'=>$result['insights']['from'],'to'=>$result['insights']['to']]);
            return $this->response($result);
        } catch (AiProviderException $e) {
            return new JsonResponse(['error'=>['code'=>'ai_provider_unavailable','message'=>$e->getMessage()]],$e->httpStatus,['Cache-Control'=>'no-store']);
        }
    }

    private static function connectionId(mixed $id): string
    {
        if (!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \InvalidArgumentException('Invalid analytics connection ID.');
        return $id;
    }

    private static function dates(mixed $from,mixed $to): array
    {
        if ($from === '' && $to === '') return [null,null];
        if ($from === null && $to === null) return [null,null];
        if (!is_string($from)||!is_string($to)) throw new \InvalidArgumentException('Both analytics dates required.');
        return \CajeerEngine\Analytics\Reporting\AnalyticsHub::period($from,$to);
    }

    private function response(array $data): JsonResponse
    {
        return new JsonResponse(['data'=>$data],200,['Cache-Control'=>'no-store']);
    }
}
