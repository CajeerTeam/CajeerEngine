<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Security\AuthService;
use Symfony\Component\HttpFoundation\Request;

/** Functional OIDC login orchestration, no email auto-link and no browser exposure of tokens. */
final readonly class OidcLogin
{
    public function __construct(private OidcClient $client, private OidcStore $store, private AuthService $auth) {}

    /** @return array{url:string,browser:string} */
    public function begin(): array
    {
        $tx = $this->client->begin();
        $cookie = $this->store->save($tx['state'], $tx['nonce'], $tx['verifier']);
        return ['url'=>$tx['url'], 'browser'=>$cookie['browser']];
    }

    /** @return array<string,mixed> */
    public function finish(string $code, string $state, string $browser, Request $request): array
    {
        $tx = $this->store->consume($state, $browser);
        $claims = $this->client->finish($code, $tx['verifier'], $tx['nonce']);
        $userId = $this->store->linkedUser($this->client->issuer(), $claims['sub']);
        if ($userId === null) throw new \RuntimeException('This SkiF4er ID is not linked to a local account.');
        return $this->auth->loginExternal($userId, $claims, $request);
    }
}
