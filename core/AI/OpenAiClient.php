<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Security\PublicHttpClient;

/** Pinned, TLS-authenticated SSE transport for OpenAI Responses API (no browser credential). */
class OpenAiClient
{
    /** @return array{text:string,usage:array{input_tokens:int,output_tokens:int},response_id:string} */
    public function complete(#[\SensitiveParameter] string $key, string $model, array $input, int $maxOutput, ?callable $onDelta = null): array
    {
        if (!function_exists('curl_init')) throw new AiProviderException('PHP ext-curl is required for OpenAI requests.', 503);
        $url = 'https://api.openai.com/v1/responses';
        try {
            [$host, $port, $ip] = PublicHttpClient::target($url);
        } catch (\Throwable) {
            throw new AiProviderException('OpenAI DNS or network destination unavailable.', 503);
        }
        $body = json_encode([
            'model' => $model, 'input' => $input, 'stream' => true, 'store' => false,
            'max_output_tokens' => $maxOutput,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $ch = curl_init($url);
        if ($ch === false) throw new AiProviderException('Cannot initialize OpenAI transport.', 503);
        $status = 0; $pending = ''; $errorBody = ''; $seenBytes = 0;
        $output = ''; $completed = false; $responseId = '';
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $providerError = null;
        $onEvent = static function (string $eventData) use (&$output, &$completed, &$responseId, &$usage, &$providerError, $onDelta): void {
            $event = json_decode($eventData, true);
            if (!is_array($event)) return;
            $type = $event['type'] ?? '';
            if ($type === 'response.output_text.delta' && is_string($event['delta'] ?? null)) {
                $delta = $event['delta'];
                if (strlen($output) + strlen($delta) > 65536) throw new AiProviderException('AI response exceeds size limit.', 502);
                $output .= $delta;
                if ($onDelta !== null) $onDelta($delta);
            } elseif ($type === 'response.completed') {
                $completed = true;
                $response = $event['response'] ?? [];
                $responseId = (string)($response['id'] ?? '');
                $usage = [
                    'input_tokens' => max(0, (int)($response['usage']['input_tokens'] ?? 0)),
                    'output_tokens' => max(0, (int)($response['usage']['output_tokens'] ?? 0)),
                ];
            } elseif ($type === 'response.failed' || $type === 'error') {
                $providerError = 'OpenAI did not complete the response.';
            }
        };
        $consume = static function (string $chunk) use (&$pending, $onEvent): void {
            $pending .= str_replace("\r", '', $chunk);
            while (($pos = strpos($pending, "\n\n")) !== false) {
                $frame = substr($pending, 0, $pos);
                $pending = substr($pending, $pos + 2);
                $lines = [];
                foreach (explode("\n", $frame) as $line) {
                    if (str_starts_with($line, 'data:')) $lines[] = ltrim(substr($line, 5));
                }
                if ($lines !== []) $onEvent(implode("\n", $lines));
            }
            if (strlen($pending) > 262144) throw new AiProviderException('Invalid OpenAI event stream.', 502);
        };
        $failure = null;
        try {
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: text/event-stream'],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 90,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$status, &$seenBytes, &$errorBody, $consume, &$failure): int {
                    $length = strlen($chunk);
                    $seenBytes += $length;
                    if ($seenBytes > 1048576) { $failure = new AiProviderException('OpenAI stream too large.', 502); return 0; }
                    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    if ($status < 200 || $status >= 300) { $errorBody .= substr($chunk, 0, max(0, 4096 - strlen($errorBody))); return $length; }
                    try { $consume($chunk); } catch (\Throwable $e) { $failure = $e; return 0; }
                    return $length;
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($failure !== null) throw $failure;
            if ($ok === false) throw new AiProviderException('OpenAI network timeout or delivery failure.', 503);
            if ($status === 429) throw new AiProviderException('OpenAI rate or quota limit reached.', 429);
            if ($status === 401 || $status === 403) throw new AiProviderException('OpenAI rejected the configured API key.', 502);
            if ($status < 200 || $status >= 300) throw new AiProviderException('OpenAI temporarily unavailable (HTTP ' . $status . ').', 502);
            if (!$completed || $providerError !== null || trim($output) === '' || ($usage['input_tokens'] + $usage['output_tokens']) <= 0) throw new AiProviderException('OpenAI returned an incomplete response.', 502);
            return ['text' => $output, 'usage' => $usage, 'response_id' => $responseId];
        } finally { curl_close($ch); }
    }
}
