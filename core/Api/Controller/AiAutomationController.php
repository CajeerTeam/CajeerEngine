<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\AI\AiBackgroundAutomation;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Security\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** An authenticated administrator owns every schedule and its billable results. */
final readonly class AiAutomationController
{
    public function __construct(private AiBackgroundAutomation $automations, private RateLimiter $limiter, private AuditLogger $audit) {}

    private function user(Request $request): string
    {
        $auth=$request->attributes->get('auth');
        if (!is_array($auth) || ($auth['cookie']??false)!==true || ($auth['session']??false)!==true || !is_string($auth['user_id']??null) || $auth['user_id']==='') {
            throw new \DomainException('An active admin browser session is required.');
        }
        return $auth['user_id'];
    }

    private function body(Request $request): array
    {
        $body=$request->attributes->get('json');
        if (!is_array($body)) throw new \InvalidArgumentException('JSON object required.');
        return $body;
    }

    private function respond(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data'=>$data],$status,['Cache-Control'=>'no-store']);
    }

    private function limit(string $uid): ?JsonResponse
    {
        $hit=$this->limiter->hit('ai-automation-write',$uid,10,60);
        return $hit['allowed']?null:new JsonResponse(['error'=>['code'=>'rate_limited','message'=>'Too many AI automation changes.']],429,
            ['Cache-Control'=>'no-store','Retry-After'=>(string)$hit['retry_after']]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->automations->list($this->user($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $uid=$this->user($request);
        if ($limited=$this->limit($uid)) return $limited;
        $created=$this->automations->create($uid,$this->body($request));
        $this->audit->record('ai.automation.created',$uid,['id'=>$created['id'],'interval_minutes'=>$created['interval_minutes']]);
        return $this->respond($created,201);
    }

    public function show(Request $request,array $params): JsonResponse
    {
        return $this->respond($this->automations->get($this->user($request),(string)$params['id']));
    }

    public function update(Request $request,array $params): JsonResponse
    {
        $uid=$this->user($request);
        if ($limited=$this->limit($uid)) return $limited;
        $updated=$this->automations->update($uid,(string)$params['id'],$this->body($request));
        $this->audit->record('ai.automation.updated',$uid,['id'=>$updated['id'],'enabled'=>$updated['enabled']]);
        return $this->respond($updated);
    }

    public function delete(Request $request,array $params): JsonResponse
    {
        $uid=$this->user($request);
        if ($limited=$this->limit($uid)) return $limited;
        $this->automations->delete($uid,(string)$params['id']);
        $this->audit->record('ai.automation.deleted',$uid,['id'=>(string)$params['id']]);
        return $this->respond(['deleted'=>true]);
    }

    public function runs(Request $request,array $params): JsonResponse
    {
        return $this->respond($this->automations->runs($this->user($request),(string)$params['id']));
    }

    public function run(Request $request,array $params): JsonResponse
    {
        $uid=$this->user($request);
        if ($limited=$this->limit($uid)) return $limited;
        if ($this->body($request)!==[]) throw new \InvalidArgumentException('Run now does not accept a payload.');
        $run=$this->automations->runNow($uid,(string)$params['id']);
        $this->audit->record('ai.automation.queued',$uid,['id'=>(string)$params['id'],'run_id'=>$run['id']]);
        return $this->respond($run,202);
    }
}
