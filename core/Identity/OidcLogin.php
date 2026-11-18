<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Security\AuthService;
use CajeerEngine\Security\AuthenticationAssurance;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Database\Repository\UserSessionRepository;
use Symfony\Component\HttpFoundation\Request;

/** Functional OIDC login orchestration, no email auto-link and no browser exposure of tokens. */
final readonly class OidcLogin
{
    public function __construct(
        private OidcClient $client, private OidcStore $store, private AuthService $auth,
        private UserRepository $users, private RoleRepository $roles, private PasswordHasher $passwords,
        private AuditLogRepository $audit, private bool $autoProvision = false, private ?UserSessionRepository $sessions = null,
        private ?AuthenticationAssurance $assurance = null,
    ) {}

    /** @return array{url:string,browser:string} */
    public function begin(?string $linkUserId = null, ?string $sessionId = null): array
    {
        $tx = $this->client->begin();
        $cookie = $this->store->save($tx['state'], $tx['nonce'], $tx['verifier'], $linkUserId, $sessionId);
        return ['url'=>$tx['url'], 'browser'=>$cookie['browser']];
    }

    /** An already authenticated browser may request a fresh IdP login + MFA. */
    public function beginStepUp(string $userId, string $sessionId): array
    {
        if ($this->assurance === null || $this->sessions === null) throw new \RuntimeException('Step-up unavailable.');
        $session = $this->sessions->find($sessionId);
        if ($session === null || $session['user_id'] !== $userId || $session['revoked_at'] !== null || $session['provider'] !== 'skif4er') {
            throw new \RuntimeException('Current OIDC session required.');
        }
        $tx = $this->client->begin(true);
        $cookie = $this->store->save($tx['state'], $tx['nonce'], $tx['verifier'], $userId, $sessionId, true);
        return ['url'=>$tx['url'], 'browser'=>$cookie['browser']];
    }

    /** @return array<string,mixed> */
    public function finish(string $code, string $state, string $browser, Request $request): array
    {
        $tx = $this->store->consume($state, $browser);
        $claims = $this->client->finish($code, $tx['verifier'], $tx['nonce']);
        $issuer = $this->client->issuer();
        if ($tx['purpose'] === 'stepup') {
            if ($this->assurance === null || $tx['user_id'] === null || $tx['session_id'] === null
                || $this->store->linkedUser($issuer, (string)$claims['sub']) !== $tx['user_id']) {
                throw new \RuntimeException('Step-up identity mismatch.');
            }
            $this->assurance->confirmOidc($tx['session_id'], $tx['user_id'], $claims);
            return ['step_up'=>true];
        }
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
        // Recheck and lock the mapping through session issuance. A concurrent
        // unlink must either revoke this new session or complete before login.
        return $this->store->withLinkedIdentity($issuer, (string)$claims['sub'],
            fn (string $linkedUserId): array => $this->auth->loginExternal($linkedUserId, $claims, $request));
    }

    /** Locally revoke the current OIDC session before returning the pinned Keycloak logout URL. */
    public function globalLogout(Request $request, string $postLogoutUrl): string
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity) || ($identity['cookie'] ?? false) !== true || ($identity['session'] ?? false) !== true
            || !is_string($identity['token_id'] ?? null) || !is_string($identity['user_id'] ?? null) || $this->sessions === null) {
            throw new \RuntimeException('A current browser session is required.');
        }
        $session = $this->sessions->find($identity['token_id']);
        if ($session === null || ($session['provider'] ?? '') !== 'skif4er' || $session['revoked_at'] !== null) {
            throw new \RuntimeException('Global logout requires a SkiF4er ID browser session.');
        }
        // Resolve remote endpoint first. An unavailable provider must not silently produce a false global logout.
        $url = $this->client->endSessionUrl($postLogoutUrl);
        if (!$this->auth->logout($request)) throw new \RuntimeException('Local logout failed.');
        $this->audit->record('identity.oidc_global_logout_started', $identity['user_id'], ['provider'=>'skif4er']);
        return $url;
    }

    /** Signed, public server-to-server notification from Keycloak. */
    public function backchannel(string $logoutToken): array
    {
        if ($this->sessions === null) throw new \RuntimeException('OIDC session registry unavailable.');
        return $this->applyVerifiedBackchannel($this->verifyBackchannel($logoutToken));
    }

    public function verifyBackchannel(string $logoutToken): array
    {
        return $this->client->validateLogoutToken($logoutToken);
    }

    public function applyVerifiedBackchannel(array $claims): array
    {
        if ($this->sessions === null) throw new \RuntimeException('OIDC session registry unavailable.');
        $outcome = $this->sessions->applyBackchannelLogout($claims);
        $this->audit->record('identity.oidc_backchannel_logout', null, [
            'provider'=>'skif4er', 'revoked_count'=>$outcome['revoked_count'],
            'replayed'=>$outcome['already_processed'],
        ]);
        return $outcome;
    }

    public function accountStatus(string $userId): array { return $this->store->statusForUser($this->client->issuer(), $userId); }

    /** Used to clear the browser cookie if self-unlink revoked this SSO session. */
    public function isSsoSession(string $userId, string $tokenId): bool
    {
        if ($this->sessions === null) throw new \RuntimeException('OIDC session registry unavailable.');
        $session = $this->sessions->find($tokenId);
        return is_array($session) && $session['user_id'] === $userId
            && $session['provider'] === 'skif4er' && $session['revoked_at'] === null;
    }

    public function unlink(string $userId): bool
    {
        $removed = $this->store->unlink($this->client->issuer(), $userId);
        if ($removed) $this->audit->record('identity.oidc_self_unlink', $userId, ['provider'=>'skif4er']);
        return $removed;
    }
}
