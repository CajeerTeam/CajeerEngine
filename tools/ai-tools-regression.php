<?php

declare(strict_types=1);

/** Offline behavioral acceptance for Responses function_call loops and their security boundary. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $path = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($path)) require $path;
    }
});
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\AI\AiTools;

function check171(bool $v, string $message): void { if (!$v) throw new RuntimeException($message); echo "PASS $message\n"; }
function reject171(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable) { echo "PASS $message\n"; return; }
    throw new RuntimeException('FAIL '.$message);
}
$tools = (new ReflectionClass(AiTools::class))->newInstanceWithoutConstructor();
$actionProposals = (new ReflectionClass(\CajeerEngine\AI\AiActions::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(AiTools::class,'actions'))->setValue($tools,$actionProposals);
$public = $tools->catalog(['content:read']);
check171(count($public) === 5, 'content-read scope exposes content, SEO, knowledge and support status without write access');
check171(count($tools->catalog(['system:read'])) === 1, 'diagnostics permission scoped separately');
check171(count($tools->catalog(['analytics:read'])) === 1, 'analytics permission scoped separately');
check171(count($tools->catalog([])) === 0, 'no scopes means no model tools');
check171(count($tools->definitions(['*'],true)) === count($tools->definitions(['*'])) + 3, 'action tools are opt-in and carry mandatory strict JSON schemas');
check171(count(array_filter($tools->catalog(['content:read'],true),static fn($tool)=>!empty($tool['requires_approval'])))===0, 'read-only RBAC excludes action proposals');
reject171(fn()=>$tools->execute('cms_action_update_seo',[],['*'],'someone'), 'action proposal execution requires explicit request opt-in');
reject171(fn()=>$tools->execute('cms_diagnostics_run',['window_minutes'=>60],['content:read'],'someone'), 'forbidden tool cannot execute');
reject171(fn()=>$tools->execute('cms_shell',['command'=>'whoami'],['*'],'someone'), 'unregistered functions fail closed');
foreach ($tools->definitions(['*']) as $declaration) {
    check171($declaration['strict'] === true && $declaration['parameters']['additionalProperties'] === false &&
        $declaration['parameters']['required'] === array_keys($declaration['parameters']['properties']),
        $declaration['name'].' strictly validated model schema');
}
$provider = new class extends OpenAiClient {
    public array $requests = [];
    public array $stages = [];
    protected function request(string $key, string $model, array $input, int $maxOutput, array $tools, ?callable $onDelta = null, bool $forceText = false): array
    {
        $this->requests[] = ['input'=>$input,'tools'=>$tools,'force_text'=>$forceText];
        $result = array_shift($this->stages);
        if ($result === null) throw new RuntimeException('Unplanned model request');
        return $result;
    }
};
$call = ['type'=>'function_call', 'name'=>'cms_content_search', 'call_id'=>'call_fixture_01',
    'arguments'=>json_encode(['type'=>'pages','query'=>'hello','status'=>'published','limit'=>2],JSON_THROW_ON_ERROR)];
$provider->stages = [
    ['text'=>'','calls'=>[$call],'usage'=>['input_tokens'=>100,'output_tokens'=>25],'response_id'=>'resp_tool'],
    ['text'=>'Найден материал','calls'=>[],'usage'=>['input_tokens'=>150,'output_tokens'=>30],'response_id'=>'resp_final'],
];
$messages=[];$trace=[];
$result=$provider->completeWithTools('sk-example','gpt-4.1-mini',[['role'=>'user','content'=>'Найди страницу']],256,
    $tools->definitions(['content:read']),
    function(string $name,array $args)use(&$messages):array { $messages[]=[$name,$args];return ['items'=>[['id'=>'entry-1','title'=>'Sample page']]]; },
    function(string $delta) use (&$trace):void {$trace[]=$delta;});
check171($result['text']==='Найден материал' && implode('',$trace)===$result['text'],'final generated answer streamed');
check171($result['usage']===['input_tokens'=>250,'output_tokens'=>55,'cached_input_tokens'=>0],'usage accumulated across model round trips');
check171(count($result['tools'])===1 && $result['tools'][0]['status']==='ok' && count($messages)===1,'one real tool callback executed');
$second=$provider->requests[1]['input'];
check171($second[count($second)-2]['type']==='function_call' && $second[count($second)-1]['type']==='function_call_output',
    'model receives matching function_call + function_call_output items');
check171($second[count($second)-1]['call_id']==='call_fixture_01' && str_contains($second[count($second)-1]['output'],'Sample page'),
    'model receives actual tool output with correlated call ID');
check171($provider->requests[0]['tools'][0]['type']==='function','real model tool definitions transmitted');
$provider->stages = [['text'=>'','calls'=>[array_merge($call,['name'=>'unregistered_shell'])],'usage'=>['input_tokens'=>1,'output_tokens'=>1],'response_id'=>'bad']];
reject171(fn()=>$provider->completeWithTools('key','model',[['role'=>'user','content'=>'x']],256,$tools->definitions(['content:read']),fn()=>[]), 'unregistered provider call never dispatched');
$provider->stages = [['text'=>'','calls'=>[$call],'usage'=>['input_tokens'=>1,'output_tokens'=>1],'response_id'=>'bad']];
reject171(fn()=>$provider->completeWithTools('key','model',[['role'=>'user','content'=>'x']],256,$tools->definitions(['content:read']),
    fn()=>['secret'=>'sk-'.str_repeat('A',30)]), 'tool result containing credential blocked before next provider request');
$provider->stages = [
    ['text'=>'','calls'=>[$call],'usage'=>['input_tokens'=>2,'output_tokens'=>2],'response_id'=>'tool'],
    ['text'=>'Ресурс недоступен','calls'=>[],'usage'=>['input_tokens'=>2,'output_tokens'=>2],'response_id'=>'final'],
];
$error=$provider->completeWithTools('key','model',[['role'=>'user','content'=>'x']],256,$tools->definitions(['content:read']),
    fn()=>throw new DomainException('Private filepath and SQL query'),null);
check171($error['tools'][0]['status']==='error' && !str_contains(json_encode($provider->requests[count($provider->requests)-1]),'Private filepath'),
    'business lookup errors redacted before sending back to model');
echo "AI Tools function calling regression PASSED\n";
