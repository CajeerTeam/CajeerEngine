<?php

declare(strict_types=1);

namespace CajeerEngine\Integration\Provider;

use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Security\PublicHttpClient;
use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Security\SignedPayload;

final readonly class HttpProvider implements ProviderInterface
{
    public function __construct(private bool $signed, private OutboundHttpTransport $http = new PublicHttpClient()) {}

    public function id(): string { return $this->signed ? 'signed-webhook' : 'http-json'; }

    public function descriptor(): array
    {
        return ['id'=>$this->id(), 'name'=>$this->signed ? 'Signed webhook' : 'HTTP JSON',
            'secret_label'=>$this->signed ? 'HMAC signing secret' : 'Bearer token (optional)',
            'secret_required'=>$this->signed, 'operations'=>['test','send']];
    }

    public function configuration(array $config): array
    {
        $url = $config['url'] ?? null;
        if (array_diff_key($config, ['url'=>true]) !== [] || !is_string($url) || strlen($url)>2048
            || !filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Invalid integration URL or configuration.');
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) {
            throw new \InvalidArgumentException('Integration URL must use HTTPS on port 443 without credentials, query or fragment.');
        }
        $host = trim($parts['host'], '[]');
        if (filter_var($host,FILTER_VALIDATE_IP) && !PublicHttpClient::isPublicIp($host)) throw new \InvalidArgumentException('Non-public integration destination denied.');
        return ['url'=>$url];
    }

    public function validateSecret(#[\SensitiveParameter] string $secret): void
    {
        if (strlen($secret)>4096 || preg_match('/[\x00-\x20\x7f]/', $secret) || ($this->signed && strlen($secret)<16)) {
            throw new \InvalidArgumentException('Invalid integration credential (signed webhooks require at least 16 characters).');
        }
    }

    public function execute(array $config, #[\SensitiveParameter] string $secret, string $event, array|\stdClass $payload, string $eventId): int
    {
        $this->validateSecret($secret);
        if ($this->signed) {
            $request = (new WebhookDispatcher(new SignedPayload()))->buildSignedRequest($event, (object)$payload, $secret, $eventId);
        } else {
            $request = ['headers'=>['Content-Type'=>'application/json','Idempotency-Key'=>$eventId],
                'body'=>json_encode(['event_id'=>$eventId,'event'=>$event,'payload'=>(object)$payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
            if ($secret !== '') $request['headers']['Authorization'] = 'Bearer '.$secret;
        }
        [$status] = $this->http->request($config['url'], 'POST', $request['headers'], $request['body'], 65536);
        return $status;
    }
}
