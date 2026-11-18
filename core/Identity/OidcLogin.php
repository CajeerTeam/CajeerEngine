<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Security\AuthService;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Security\PasswordHasher;
use Symfony\Component\HttpFoundation\Request;

/** Functional OIDC login orchestration, no email auto-link and no browser exposure of tokens. */
final readonly class OidcLogin
{
    public function __construct(
        private OidcClient $client, private OidcStore $store, private AuthService $auth,
        private UserRepository $users, private RoleRepository $roles, private PasswordHasher $passwords,
        private AuditLogRepository $audit, private bool $autoProvision = false,
    ) {}

    /** @return array{url:string,browser:string} */
    public function begin(?string $linkUserId = null, ?string $sessionId = null): array
    {
        $tx = $this->client->begin();
        $cookie = $this->store->save($tx['state'], $tx['nonce'], $tx['verifier'], $linkUserId, $sessionId);
        return ['url'=>$tx['url'], 'browser'=>$cookie['browser']];
    }

    /** @return array<string,mixed> */
    public function finish(string $code, string $state, string $browser, Request $request): array
    {
        $tx = $this->store->consume($state, $browser);
        $claims = $this->client->finish($code, $tx['verifier'], $tx['nonce']);
        $issuer = $this->client->issuer();
        if ($tx['purpose'] === 'link') {
            if ($tx['user_id'] === null || $tx['session_id'] === null) throw new \RuntimeException('Invalid linking transaction.');
            $this->store->completeLink($issuer, $claims['sub'], $tx['user_id'], $tx['session_id'], $this->users);
            $this->audit->record('identity.oidc_self_link', $tx['user_id'], ['provider'=>'skif4er', 'subject_digest'=>hash('sha256', $claims['sub'])]);
            return ['linked'=>true];
        }
        if ($tx['purpose'] !== 'login') throw new \RuntimeException('Unknown OIDC transaction type.');
        $userId = $this->store->linkedUser($issuer, $claims['sub']);
        if ($userId === null) {
            if (!$this->autoProvision) throw new \RuntimeException('This SkiF4er ID is not linked to a local account.');
            $userId = $this->store->provision($issuer, $claims, $this->users, $this->roles, $this->passwords);
            $this->audit->record('identity.oidc_provisioned', $userId, ['provider'=>'skif4er', 'subject_digest'=>hash('sha256', $claims['sub'])]);
        }
        return $this->auth->loginExternal($userId, $claims, $request);
    }

    public function accountStatus(string $userId): array { return $this->store->statusForUser($this->client->issuer(), $userId); }

    public function unlink(string $userId): bool
    {
        $removed = $this->store->unlink($this->client->issuer(), $userId);
        if ($removed) $this->audit->record('identity.oidc_self_unlink', $userId, ['provider'=>'skif4er']);
        return $removed;
    }
}
