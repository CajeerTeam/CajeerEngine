<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

/** Stateful parser for a single Responses SSE request, including truncated/corrupt streams. */
final class OpenAiResponseStream
{
    private string $pending = '';
    private string $text = '';
    private bool $completed = false;
    private ?string $error = null;
    private string $responseId = '';
    /** @var array{input_tokens:int,output_tokens:int,cached_input_tokens:int} */
    private array $usage = ['input_tokens'=>0,'output_tokens'=>0,'cached_input_tokens'=>0];
    /** @var list<array<string,mixed>> */
    private array $calls = [];
    private int $frames = 0;

    public function __construct(private readonly ?\Closure $onDelta = null) {}

    public function feed(string $chunk): void
    {
        // A stream may terminate in any position, including the middle of a JSON frame.
        // CRLF is permitted by SSE, but bare CR delimiters are not accepted as JSON.
        $this->pending = str_replace("\r\n", "\n", $this->pending . $chunk);
        while (($pos = strpos($this->pending, "\n\n")) !== false) {
            $frame = substr($this->pending, 0, $pos);
            $this->pending = substr($this->pending, $pos + 2);
            $lines = [];
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data = substr($line, 5);
                    $lines[] = str_starts_with($data, ' ') ? substr($data, 1) : $data;
                }
            }
            if ($lines !== []) $this->event(implode("\n", $lines));
        }
        if (strlen($this->pending) > 262144) throw new AiProviderException('OpenAI event frame exceeds size limit.', 502);
    }

    private function event(string $json): void
    {
        if ($json === '[DONE]') return;
        if (++$this->frames > 4096) throw new AiProviderException('OpenAI sent too many stream events.', 502);
        $item = json_decode($json, true);
        if (!is_array($item) || !is_string($item['type'] ?? null)) {
            throw new AiProviderException('Invalid OpenAI stream event.', 502);
        }
        $type = $item['type'];
        if ($type === 'response.output_text.delta') {
            if ($this->completed || $this->error !== null || !is_string($item['delta'] ?? null)) {
                throw new AiProviderException('Invalid OpenAI output event.', 502);
            }
            $this->appendText($item['delta']);
            if ($this->onDelta !== null && $item['delta'] !== '') ($this->onDelta)($item['delta']);
        } elseif ($type === 'response.completed') {
            if ($this->completed || $this->error !== null) throw new AiProviderException('Duplicate or contradictory OpenAI completion.', 502);
            $response = $item['response'] ?? null;
            if (!is_array($response) || !is_string($response['id'] ?? null) || $response['id'] === '' || strlen($response['id']) > 128
                || (isset($response['status']) && $response['status'] !== 'completed')) {
                throw new AiProviderException('Invalid OpenAI completion payload.', 502);
            }
            $usage = $response['usage'] ?? null;
            if (!is_array($usage) || !is_int($usage['input_tokens'] ?? null) || !is_int($usage['output_tokens'] ?? null)
                || $usage['input_tokens'] < 0 || $usage['output_tokens'] < 0
                || $usage['input_tokens'] + $usage['output_tokens'] === 0) {
                throw new AiProviderException('OpenAI did not supply valid token accounting.', 502);
            }
            $cached = $usage['input_tokens_details']['cached_tokens'] ?? 0;
            if (!is_int($cached) || $cached < 0 || $cached > $usage['input_tokens']) {
                throw new AiProviderException('OpenAI supplied inconsistent cached token usage.', 502);
            }
            $this->usage = [
                'input_tokens'=>$usage['input_tokens'],
                'output_tokens'=>$usage['output_tokens'],
                'cached_input_tokens'=>$cached,
            ];
            $output = $response['output'] ?? [];
            if (!is_array($output) || count($output) > 128) throw new AiProviderException('Invalid OpenAI output items.', 502);
            foreach ($output as $part) {
                if (!is_array($part)) throw new AiProviderException('Invalid OpenAI output item.', 502);
                if (($part['type'] ?? null) === 'function_call') {
                    if (count($this->calls) >= 8) throw new AiProviderException('OpenAI function call budget exceeded.', 502);
                    $this->calls[] = $part;
                } elseif (($part['type'] ?? null) === 'message' && $this->text === '') {
                    $content = $part['content'] ?? [];
                    if (!is_array($content)) throw new AiProviderException('Invalid OpenAI message content.', 502);
                    foreach ($content as $contentPart) {
                        if (is_array($contentPart) && ($contentPart['type'] ?? null) === 'output_text') {
                            if (!is_string($contentPart['text'] ?? null)) throw new AiProviderException('Invalid OpenAI output text.', 502);
                            $this->appendText($contentPart['text']);
                            if ($this->onDelta !== null && $contentPart['text'] !== '') ($this->onDelta)($contentPart['text']);
                        }
                    }
                }
            }
            $this->responseId = $response['id'];
            $this->completed = true;
        } elseif (in_array($type, ['response.failed', 'response.incomplete', 'error'], true)) {
            $this->error = 'OpenAI did not complete the response.';
        }
    }

    private function appendText(string $text): void
    {
        if (strlen($this->text) + strlen($text) > 65536) throw new AiProviderException('AI response exceeds size limit.', 502);
        $this->text .= $text;
    }

    /** @return array{text:string,calls:list<array<string,mixed>>,usage:array{input_tokens:int,output_tokens:int,cached_input_tokens:int},response_id:string} */
    public function result(): array
    {
        if (!$this->completed || $this->error !== null || trim($this->pending) !== '' && trim($this->pending) !== 'data: [DONE]') {
            throw new AiProviderException('OpenAI returned an incomplete response.', 502);
        }
        return ['text'=>$this->text, 'calls'=>$this->calls, 'usage'=>$this->usage, 'response_id'=>$this->responseId];
    }
}
