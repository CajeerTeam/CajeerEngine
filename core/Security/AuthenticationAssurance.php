<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;

/** Short-lived, session-bound evidence of a genuine recent reauthentication. */
final readonly class AuthenticationAssurance
{
    public function __construct(
        private DatabaseManager $db,
        private UserRepository $users,
        private PasswordHasher $passwords,
        private TwoFactorTotp $totp,
        private AuditLogRepository $audit,
    ) {}

    public static function required(Request $request): bool
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) return false;
        // Use the route name established by Symfony's matcher, not raw path text.
        // A raw-path regex can be bypassed with percent-encoded path segments.
        $route = (string)$request->attributes->get('matched_route', '');
        if (str_starts_with($route, 'api.v1.identity.admin.') || str_starts_with($route, 'api.v1.users.') || str_starts_with($route, 'api.v1.roles.')
            || str_starts_with($route, 'api.v1.api_tokens.')) return true;
        // Keep runtime service-to-service delivery/test under existing RBAC, while guarding
        // actual integration creation, configuration, secrets and deletion.
        return in_array($route, ['api.v1.integrations.store','api.v1.integrations.update',
            'api.v1.integrations.delete','api.v1.integrations.secret','api.v1.ai.settings'], true);
    }

    public function ttl(): int
    {
        $raw = getenv('AUTH_ASSURANCE_TTL_SECONDS');
        return max(60, min(900, is_string($raw) && ctype_digit($raw) ? (int)$raw : 300));
    }

    /** Never trust browser-supplied user IDs, assurance timestamps or identity claims. */
    public function status(array $identity): array
    {
        $this->browser($identity);
        $stmt = $this->db->connection()->prepare(
            "SELECT s.provider, s.assured_at, s.assurance_method, s.assurance_mfa, s.revoked_at
               FROM ce_user_sessions s JOIN ce_api_tokens t ON t.id = s.token_id AND t.user_id = s.user_id
              WHERE s.token_id = :id AND s.user_id = :uid AND t.name = 'admin-session'
                AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP)"
        );
        $stmt->execute(['id'=>$identity['token_id'], 'uid'=>$identity['user_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $valid = is_array($row) && $row['revoked_at'] === null;
        $at = $valid && $row['assured_at'] !== null ? (int)$row['assured_at'] : null;
        $fresh = $at !== null && $at <= time() + 30 && $at + $this->ttl() >= time();
        // A high-risk OIDC operation requires verifiable IdP MFA, not just a fresh password prompt.
        if ($fresh && $row['assurance_method'] === 'oidc') $fresh = (int)$row['assurance_mfa'] === 1;
        return ['required'=>true, 'fresh'=>$fresh, 'method'=>$fresh ? (string)$row['assurance_method'] : null,
            'expires_at'=>$fresh ? gmdate('Y-m-d\TH:i:s\Z', $at + $this->ttl()) : null,
            'provider'=>$valid ? (string)$row['provider'] : null];
    }

    /** Local reauthentication always verifies password, plus a live TOTP when enabled. */
    public function confirmLocal(array $identity, string $password, ?string $otp): array
    {
        $this->browser($identity);
        if ($password === '' || strlen($password) > 1024) throw new \RuntimeException('Password required.');
        $public = $this->users->findById((string)$identity['user_id']);
        if ($public === null || ($public['status'] ?? '') !== 'active') throw new \RuntimeException('Inactive user.');
        $user = $this->users->findByEmailForAuth((string)$public['email']);
        if ($user === null || !hash_equals((string)$user['id'], (string)$identity['user_id'])
            || !$this->passwords->verify($password, (string)$user['password_hash'])) {
            throw new \RuntimeException('Local reauthentication rejected.');
        }
        $mfa = (bool)($user['two_factor_enabled'] ?? false);
        if ($mfa && (!is_string($otp) || !preg_match('/^[0-9]{6}$/D', $otp)
            || !$this->totp->verify((string)$user['two_factor_secret'], $otp))) {
            throw new \RuntimeException('TOTP verification required.');
        }
        $this->db->transaction(function () use ($identity, $mfa): void {
            $this->stamp((string)$identity['token_id'], (string)$identity['user_id'], 'local', $mfa);
            $this->audit->record('auth.assurance_local', (string)$identity['user_id'], ['mfa'=>$mfa]);
        });
        return $this->status($identity);
    }

    /** Called ONLY after RS256 validation, one-time state consumption and issuer+subject mapping. */
    public function confirmOidc(string $tokenId, string $userId, array $claims): void
    {
        $authTime = $claims['auth_time'] ?? null;
        if (!is_int($authTime) || $authTime > time()+30 || $authTime < time()-180) {
            throw new \RuntimeException('OIDC reauthentication was not recent.');
        }
        $amr = $claims['amr'] ?? null;
        $mfa = is_array($amr) && array_is_list($amr) && count($amr) <= 16
            && count(array_filter($amr, 'is_string')) === count($amr)
            && count(array_intersect($amr, ['mfa','otp','hwk','swk'])) > 0;
        if (!$mfa) throw new \RuntimeException('Verified OIDC MFA evidence required.');
        $this->db->transaction(function () use ($tokenId, $userId): void {
            $this->stamp($tokenId, $userId, 'oidc', true, 'skif4er');
            $this->audit->record('auth.assurance_oidc', $userId, ['mfa'=>true]);
        });
    }

    private function stamp(string $tokenId, string $userId, string $method, bool $mfa, ?string $provider = null): void
    {
        $sql = "UPDATE ce_user_sessions SET assured_at = :at, assurance_method = :method, assurance_mfa = :mfa
                 WHERE token_id = :id AND user_id = :uid AND revoked_at IS NULL
                   AND EXISTS (SELECT 1 FROM ce_api_tokens t WHERE t.id = ce_user_sessions.token_id AND t.user_id = :owner
                     AND t.name = 'admin-session' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP))";
        $args = ['at'=>time(), 'method'=>$method, 'mfa'=>$mfa ? 1 : 0, 'id'=>$tokenId, 'uid'=>$userId, 'owner'=>$userId];
        if ($provider !== null) { $sql .= ' AND provider = :provider'; $args['provider']=$provider; }
        $stmt = $this->db->connection()->prepare($sql);
        $stmt->execute($args);
        if ($stmt->rowCount() !== 1) throw new \RuntimeException('Reauthentication session was revoked or rotated.');
    }

    private function browser(array $identity): void
    {
        if (($identity['cookie'] ?? false) !== true || ($identity['session'] ?? false) !== true
            || !is_string($identity['token_id'] ?? null) || !is_string($identity['user_id'] ?? null)) {
            throw new \RuntimeException('Authenticated browser session required.');
        }
    }
}
