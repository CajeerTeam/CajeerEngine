<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Database\DatabaseManager;

/** API resource server: signed, audience-restricted, linked SkiF4er ID access tokens. */
final readonly class OidcApiAuthenticator
{
    /** @param list<string> $allowedClients @param list<string> $maxPermissions */
    public function __construct(
        private OidcClient $oidc,
        private DatabaseManager $database,
        private string $audience,
        private array $allowedClients,
        private string $requiredScope,
        private array $maxPermissions,
        private bool $introspection = true,
    ) {
        if (!preg_match('/^[A-Za-z0-9._:-]{3,128}$/D', $audience)
            || !preg_match('/^[A-Za-z0-9._:-]{3,128}$/D', $requiredScope)
            || $allowedClients === [] || $maxPermissions === []
            || count($allowedClients) > 32 || count($maxPermissions) > 128) {
            throw new \InvalidArgumentException('OIDC API configuration incomplete.');
        }
        foreach ($allowedClients as $client) {
            if (!preg_match('/^[A-Za-z0-9._:-]{3,128}$/D', $client)) throw new \InvalidArgumentException('Invalid OAuth client allowlist.');
        }
        foreach ($maxPermissions as $permission) {
            if (!is_string($permission) || !preg_match('/^[a-z][a-z0-9_.:-]{2,128}$/D', $permission)) throw new \InvalidArgumentException('Invalid OIDC API permission ceiling.');
        }
    }

    /** @return array{user_id:string,permissions:list<string>}|null */
    public function authenticate(string $token): ?array
    {
        try {
            $claims = $this->oidc->validateAccessToken($token, $this->audience, $this->allowedClients, $this->requiredScope, $this->introspection);
            // Only a pre-linked identity can access the API. Never resolve by email.
            $stmt = $this->database->connection()->prepare('SELECT user_id FROM ce_oidc_identities WHERE issuer = :issuer AND subject = :subject');
            $stmt->execute(['issuer'=>$this->oidc->issuer(), 'subject'=>$claims['sub']]);
            $userId = $stmt->fetchColumn();
            if (!is_string($userId) || !preg_match('/^[0-9a-fA-F-]{36}$/D', $userId)) return null;
            return ['user_id'=>$userId, 'permissions'=>$this->maxPermissions];
        } catch (\Throwable) {
            // Network problems, signature failures, storage faults: all fail closed.
            // Never leak the bearer token or IdP response to application logs.
            return null;
        }
    }
}
