<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\AiExternalAnalytics;
use CajeerEngine\AI\AiSeoContentInsights;
use CajeerEngine\AI\AiContentEditor;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Analytics-only RBAC at the router; paid explanation requires a browser session and CSRF. */
final readonly class AiAnalyticsController
{
    public function __construct(private AiExternalAnalytics $analytics, private RateLimiter $limiter, private AuditLogger $audit, private AiSeoContentInsights $seoInsights, private AiContentEditor $editor) {}

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

    /** Evidence-backed prioritization: read-only, no AI request or token charge. */
    public function seoInsights(Request $request): JsonResponse
    {
        $id = $this->connectionId($request->query->get('connection_id'));
        [$from,$to] = $this->dates($request->query->get('from'),$request->query->get('to'));
        $limit = filter_var($request->query->get('limit','25'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 40) throw new \InvalidArgumentException('Invalid SEO insights limit.');
        return $this->response($this->seoInsights->report($id,$from,$to,$limit));
    }

    /** Generate a proposal only from live server-side evidence; approval remains in AI Editor. */
    public function seoProposal(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity) || ($identity['cookie']??false)!==true || ($identity['session']??false)!==true
            || !is_string($identity['user_id']??null) || $identity['user_id']==='')
            throw new \DomainException('An active admin browser session is required.');
        $uid = $identity['user_id'];
        $body = $request->attributes->get('json', []);
        if (!is_array($body) || array_diff(array_keys($body),['connection_id','from','to','type','entry_id','field','instructions'])
            || !is_string($body['type']??null) || !is_string($body['entry_id']??null)
            || !in_array($body['field']??null,['seo_title','meta_description'],true)
            || !is_string($body['instructions']??'') || strlen($body['instructions']??'')>500)
            throw new \InvalidArgumentException('Invalid SEO insights proposal.');
        $id = $this->connectionId($body['connection_id']??null);
        [$from,$to] = $this->dates($body['from']??null,$body['to']??null);
        $report = $this->seoInsights->report($id,$from,$to,40);
        $item = null;
        foreach ($report['items'] as $row) {
            if ($row['type']===$body['type'] && $row['entry_id']===$body['entry_id']) { $item=$row; break; }
        }
        if ($item===null) throw new \DomainException('Published page not present in current SEO insights. Re-run the analysis.');
        $allowed = false;
        foreach ($item['recommendations'] as $recommendation) {
            if ($recommendation['field']===$body['field']) { $allowed=true; break; }
        }
        if (!$allowed) throw new \DomainException('The selected field has no verified SEO recommendation.');
        $hit = $this->limiter->hit('ai-seo-insights-user',$uid,4,60);
        if (!$hit['allowed']) return new JsonResponse(['error'=>['code'=>'ai_seo_insights_rate_limit','message'=>'Лимит: 4 предложения в минуту.']],429,
            ['Retry-After'=>(string)$hit['retry_after'],'Cache-Control'=>'no-store']);
        $basis = 'SEO-проверка записи: '.implode(', ',array_slice(array_map(
            static fn(array $v): string => $v['code'], $item['issues']),0,8)).'. '
            .'Метрика '.$item['metric'].': '.$item['current'].'. ';
        if ($item['trend']!=='unavailable') {
            $basis .= 'Прошлый равный период: '.$item['previous'].', изменение '.$item['change_percent'].'%. ';
        } else $basis .= 'Надёжное сравнение периодов недоступно; не утверждай падение трафика. ';
        $basis .= 'Не придумывай данные, позиции, причины снижения или гарантии SEO. '
            .'Исправь только выбранное метаполе, сохраняя смысл страницы. '
            .trim($body['instructions']??'');
        try {
            $proposal = $this->editor->propose($uid,[
                'type'=>$item['type'],'entry_id'=>$item['entry_id'],'field'=>$body['field'],
                'mode'=>$body['field']==='seo_title'?'seo_title':'seo_description',
                'instructions'=>$basis,
            ]);
            if (($proposal['expected_revision']??null)!==$item['revision'])
                throw new \CajeerEngine\Content\RevisionConflict('Page changed during proposal generation; refresh SEO insights.');
            $this->audit->record('ai.seo.insights.proposed',$uid,['proposal_id'=>$proposal['id'],
                'connection_id'=>$id,'entry_id'=>$item['entry_id'],'field'=>$body['field']]);
            return $this->response(['proposal'=>$proposal,'evidence'=>[
                'path'=>$item['path'],'metric'=>$item['metric'],'current'=>$item['current'],
                'previous'=>$item['previous'],'change_percent'=>$item['change_percent'],
                'comparison_status'=>$report['comparison_status'],'source_fetched_at'=>$report['source_fetched_at']]]);
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
