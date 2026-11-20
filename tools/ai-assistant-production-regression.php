#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Real OpenAI SSE parser, function-dispatch, and outbound DLP regressions. No API key needed. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'CajeerEngine\\')) return;
    $file = $root.'/core/'.str_replace('\\', '/', substr($class, strlen('CajeerEngine\\'))).'.php';
    if (is_file($file)) require $file;
});

use CajeerEngine\AI\AiOutboundSafety;
use CajeerEngine\AI\OpenAiResponseStream;
use CajeerEngine\AI\OpenAiClient;

$passed = 0;
$ok = static function (bool $valid, string $label) use (&$passed): void {
    if (!$valid) throw new RuntimeException('FAIL '.$label);
    ++$passed; echo "PASS $label\n";
};
$reject = static function (callable $callback, string $label) use ($ok): void {
    try { $callback(); }
    catch (\CajeerEngine\AI\AiProviderException|InvalidArgumentException $e) {
        $ok(!str_contains($e->getMessage(), 'superprivate'), $label); return;
    }
    throw new RuntimeException('FAIL '.$label);
};
$completed = static fn(array $output, array $usage = ['input_tokens'=>12,'output_tokens'=>4], string $status = 'completed'): string =>
    'data: '.json_encode(['type'=>'response.completed','response'=>[
        'id'=>'resp_verified_1','status'=>$status,'usage'=>$usage,'output'=>$output,
    ]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n\n";
$message = ['type'=>'message','content'=>[['type'=>'output_text','text'=>'Готово']]];
$events = [];
$stream = new OpenAiResponseStream(static function (string $chunk) use (&$events): void { $events[] = $chunk; });
$frame = 'data: '.json_encode(['type'=>'response.output_text.delta','delta'=>'Готово'],JSON_THROW_ON_ERROR)."\r\n\r\n".$completed([$message]);
// Split CRLF and JSON tokens at arbitrary byte boundaries to exercise the actual parser.
foreach (str_split($frame, 3) as $chunk) $stream->feed($chunk);
$value=$stream->result();
$ok($value['text']==='Готово' && $events===['Готово'] && $value['response_id']==='resp_verified_1', 'streamed delta/final text, no duplicate answer, fragmented CRLF');
$ok($value['usage']===['input_tokens'=>12,'output_tokens'=>4,'cached_input_tokens'=>0], 'validated provider usage is available to cost ledger');
$fallback = [];
$stream = new OpenAiResponseStream(static function (string $text) use (&$fallback): void { $fallback[]=$text; });
$stream->feed($completed([$message]));
$ok($stream->result()['text']==='Готово' && $fallback===['Готово'], 'final-only provider output is streamed to the browser');
$tool=['type'=>'function_call','name'=>'cms_content_search','call_id'=>'call_1234','arguments'=>'{}'];
$stream = new OpenAiResponseStream(); $stream->feed($completed([$tool]));
$ok(count($stream->result()['calls'])===1 && $stream->result()['calls'][0]['call_id']==='call_1234', 'tool calls extracted from final response');
$reject(static function () use ($completed, $message): void {
    $stream=new OpenAiResponseStream(); $stream->feed(substr($completed([$message]),0,-9)); $stream->result();
}, 'truncated SSE response is not billed as complete');
$reject(static function () use ($completed, $message): void {
    $stream=new OpenAiResponseStream(); $stream->feed($completed([$message],['input_tokens'=>-2,'output_tokens'=>4]));
}, 'negative provider token usage rejected');
$reject(static function () use ($completed, $message): void {
    $stream=new OpenAiResponseStream(); $stream->feed($completed([$message],['input_tokens'=>5,'output_tokens'=>3,'input_tokens_details'=>['cached_tokens'=>6]]));
}, 'cached tokens exceeding input rejected');
$reject(static function () use ($completed, $message): void {
    $stream=new OpenAiResponseStream();
    $stream->feed('data: '.json_encode(['type'=>'response.completed','response'=>['id'=>'resp1','status'=>'completed','usage'=>'not an array','output'=>[$message]]],JSON_THROW_ON_ERROR)."\n\n");
}, 'malformed provider usage rejected');
$reject(static function () use ($completed, $message): void {
    $stream=new OpenAiResponseStream(); $stream->feed($completed([$message],['input_tokens'=>1,'output_tokens'=>1],'incomplete'));
}, 'incomplete completion status rejected');
$reject(static function () use ($completed, $message): void {
    $stream=new OpenAiResponseStream(); $stream->feed($completed([$message]).$completed([$message]));
}, 'duplicate completion rejected');
$reject(static function () use ($completed, $message): void {
    $stream=new OpenAiResponseStream(); $stream->feed($completed([$message]).'data: {"type":"response.failed"}'."\n\n"); $stream->result();
}, 'failed provider stream after completion rejected');
$reject(static function () use ($completed): void {
    $stream=new OpenAiResponseStream(); $stream->feed($completed([['type'=>'message','content'=>[['type'=>'output_text','text'=>str_repeat('x',65537)]]]]));
}, 'large final-only output rejected before persistence');
$reject(static function (): void {
    $stream=new OpenAiResponseStream(); $stream->feed('data: superprivate_invalid_json'."\n\n");
}, 'malformed event rejected without leaking contents');
$reject(static function (): void {
    AiOutboundSafety::assertNoCredentials([['role'=>'tool','content'=>'{"client_secret":"abcd1234","metadata":{"ok":true}}']]);
}, 'short JSON-encoded client secret blocked');
$reject(static function (): void {
    AiOutboundSafety::assertNoCredentials([['role'=>'tool','content'=>json_encode(['nested'=>['aws_secret_access_key'=>'tiny']],JSON_THROW_ON_ERROR)]]);
}, 'nested credentials are blocked before provider transport');
AiOutboundSafety::assertNoCredentials([['role'=>'user','content'=>'How do I reset a password?'],['role'=>'tool','content'=>'{"status":"ok","token_count":500}']]);
$ok(true,'ordinary prose and nonsensitive JSON remain usable');
$fake=new class extends OpenAiClient {
    public array $stages=[];
    public array $requests=[];
    protected function request(string $key, string $model, array $input, int $maxOutput, array $tools, ?callable $onDelta = null, bool $forceText = false): array
    {
        $this->requests[]=$input;
        return array_shift($this->stages) ?? throw new RuntimeException('Unexpected provider invocation');
    }
};
$definitions=[['type'=>'function','name'=>'cms_content_search']];
$first=['text'=>'','calls'=>[$tool, $tool],'usage'=>['input_tokens'=>1,'output_tokens'=>1],'response_id'=>'resp1'];
$fake->stages=[$first]; $executed=0;
$reject(static function () use ($fake,$definitions,&$executed): void {
    $fake->completeWithTools('test','model',[['role'=>'user','content'=>'x']],256,$definitions,
        static function () use (&$executed): array { ++$executed; return []; });
}, 'duplicate call identifiers rejected');
$ok($executed===0 && count($fake->requests)===1, 'invalid tool batch has zero side effects');
$reject(static function () use ($fake): void {
    $fake->completeWithTools('test','model',[],256,[['type'=>'function','name'=>'cms_content_search'],['type'=>'function','name'=>'cms_content_search']],static fn():array=>[]);
}, 'duplicate tool schemas rejected before contacting provider');
$empty = ['text'=>'','calls'=>[['type'=>'function_call','name'=>'cms_content_search','call_id'=>'call_empty_1','arguments'=>'{}']],
    'usage'=>['input_tokens'=>2,'output_tokens'=>1],'response_id'=>'r1'];
$fake->stages=[$empty,['text'=>'Done','calls'=>[], 'usage'=>['input_tokens'=>2,'output_tokens'=>1],'response_id'=>'r2']];
$result=$fake->completeWithTools('test','model',[['role'=>'user','content'=>'x']],256,$definitions, static fn(string $name,array $args):array=>['empty'=>$args===[]]);
$ok($result['text']==='Done' && count($result['tools'])===1, 'empty JSON object tool arguments are valid');
echo "AI Assistant Production: $passed checks PASSED\n";
