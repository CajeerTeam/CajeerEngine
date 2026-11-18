<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Database\Repository\UserSessionRepository;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\HttpFoundation\Request;

final readonly class AuthService
{
    public function __construct(
        private ConfigRepository $config,
        private UserRepository $users,
        private ApiTokenRepository $tokens,
        private AuditLogRepository $audit,
        private PasswordHasher $passwords,
        private TwoFactorTotp $totp,
        private ?UserSessionRepository $sessions = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function login(string $email, string $password, ?string $twoFactorCode, Request $request): array
    {
        $user = $this->users->findByEmailForAuth($email);
        if ($user === null || !$this->passwords->verify($password, (string) $user['password_hash'])) {
            $this->audit->record('auth.login_failed', null, ['email' => mb_strtolower($email), 'reason' => 'invalid_credentials'], $request->getClientIp(), $request->headers->get('User-Agent'));
            throw new \RuntimeException('Неверный email или пароль.');
        }

        if (($user['status'] ?? 'active') !== 'active') {
            $this->audit->record('auth.login_failed', (string) $user['id'], ['email' => $user['email'], 'reason' => 'disabled_user'], $request->getClientIp(), $request->headers->get('User-Agent'));
            throw new \RuntimeException('Пользователь отключён.');
        }

        if ((bool) ($user['two_factor_enabled'] ?? false)) {
            $secret = (string) ($user['two_factor_secret'] ?? '');
            if ($secret === '' || $twoFactorCode === null || !$this->verifyTwoFactorCode((string) $user['id'], $secret, $twoFactorCode)) {
                $this->audit->record('auth.login_failed', (string) $user['id'], ['email' => $user['email'], 'reason' => 'two_factor_failed'], $request->getClientIp(), $request->headers->get('User-Agent'));
                throw new \RuntimeException('Требуется корректный 2FA-код.');
            }
        }

        $scopes = $this->users->permissionsForUser((string) $user['id']);
        $ttlDays = max(1, $this->config->int('security.sessions.ttl_days', 14));
        $token = $this->issueSession((string) $user['id'], $scopes, $ttlDays, 'local', $request);
        $this->users->markLogin((string) $user['id']);
        $this->audit->record('auth.login_success', (string) $user['id'], ['email' => $user['email'], 'token_id' => $token['id']], $request->getClientIp(), $request->headers->get('User-Agent'));

        return [
            'access_token' => $token['plain_token'],
            'token_type' => 'Bearer',
            'expires_at' => $token['expires_at'] ?? null,
            'user' => $this->publicUser($this->users->findById((string) $user['id']) ?? $user),
            'scopes' => $scopes,
        ];
    }

    /** Create a normal local session only for an already-linked and active OIDC identity. */
    public function loginExternal(string $userId, array $verifiedClaims, Request $request): array
    {
        $user = $this->users->findById($userId);
        if ($user === null || ($user['status'] ?? '') !== 'active') throw new \RuntimeException('Account disabled or unavailable.');
        if (($user['two_factor_enabled'] ?? false)) {
            $amr = $verifiedClaims['amr'] ?? [];
            if (!is_array($amr) || !array_intersect(['mfa', 'otp', 'hwk', 'swk'], $amr)) {
                throw new \RuntimeException('External multi-factor authentication evidence required.');
            }
        }
        $scopes = $this->users->permissionsForUser($userId);
        $ttlDays = max(1, $this->config->int('security.sessions.ttl_days', 14));
        $token = $this->issueSession($userId, $scopes, $ttlDays, 'skif4er', $request, $verifiedClaims);
        $this->users->markLogin($userId);
        $this->audit->record('auth.oidc_login_success', $userId, ['provider'=>'skif4er', 'token_id'=>$token['id']], $request->getClientIp(), $request->headers->get('User-Agent'));
        $public = $this->users->findById($userId) ?? $user;
        return ['access_token'=>$token['plain_token'], 'token_type'=>'Bearer', 'expires_at'=>$token['expires_at'], 'user'=>$this->publicUser($public), 'scopes'=>$scopes];
    }

    /** @return array<string, mixed>|null */
    public function authenticateRequest(Request $request): ?array
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) {
            $token = trim($m[1]);
        } elseif ($authorization !== '') { return null; }
        if ($token === '') {
            $token = (string) $request->headers->get('X-API-Token', '');
        }
        if ($token === '') {
            $token = (string) $request->cookies->get('ce_session', '');
            $cookie = true;
        }
        if ($token === '') { return null; }
        $cookie = $cookie ?? false;

        $record = $this->tokens->findValidPlainToken($token);
        if ($record === null) {
            return null;
        }
        $user = isset($record['user_id']) && $record['user_id'] !== null ? $this->users->findById((string) $record['user_id']) : null;
        if ($user === null || ($user['status'] ?? '') !== 'active') { return null; }
        if ($cookie && $record['name'] !== 'admin-session') { return null; }
        if ($record['name'] === 'admin-session' && $this->sessions !== null
            && !$this->sessions->validate($record, $request, max(5, $this->config->int('security.sessions.idle_minutes', 720)))) { return null; }
        $scopes = $record['scopes'];
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            $scopes = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }
        if ($scopes === ['*'] || in_array('*', is_array($scopes) ? $scopes : [], true)) {
            $scopes = ['*'];
        }
        // A token is an upper bound, never a grant beyond current role permissions.
        $scopes = self::intersectScopes(is_array($scopes) ? $scopes : [], $this->users->permissionsForUser((string) $user['id']));
        $this->tokens->touch((string) $record['id']);
        return [
            'token_id' => (string) $record['id'],
            'session' => $record['name'] === 'admin-session',
            'cookie' => $cookie,
            'csrf_token' => $cookie ? self::csrfToken($token) : null,
            'user' => $user !== null ? $this->publicUser($user) : null,
            'user_id' => $record['user_id'] ?? null,
            'scopes' => is_array($scopes) ? array_values($scopes) : [],
        ];
    }

    /** @param list<string> $scopes @param array<string,mixed> $claims */
    private function issueSession(string $userId, array $scopes, int $ttlDays, string $provider, Request $request, array $claims = []): array
    {
        $expiresAt = new \DateTimeImmutable('+' . $ttlDays . ' days', new \DateTimeZone('UTC'));
        $token = $this->tokens->create($userId, 'admin-session', $scopes, $expiresAt);
        if ($this->sessions !== null) {
            try { $this->sessions->register($token['id'], $userId, $provider, $request, $token['expires_at'], $claims); }
            catch (\Throwable $e) { $this->tokens->revoke($token['id']); throw $e; }
        }
        return $token;
    }

    /** Only a current same-origin browser session may enumerate or revoke sessions. */
    public function sessionList(array $identity): array
    {
        $this->requireBrowserSession($identity);
        return $this->requireSessions()->activeForUser((string)$identity['user_id'], (string)$identity['token_id'], max(5,$this->config->int('security.sessions.idle_minutes', 720)));
    }

    public function revokeSession(array $identity, string $sessionId): bool
    {
        $this->requireBrowserSession($identity);
        if (!preg_match('/^[0-9a-fA-F-]{36}$/D', $sessionId)) return false;
        $done = $this->requireSessions()->revoke($sessionId, (string)$identity['user_id']);
        if ($done) $this->audit->record('auth.session_revoked', (string)$identity['user_id'], ['session_id'=>$sessionId]);
        return $done;
    }

    public function revokeAllSessions(array $identity): int
    {
        $this->requireBrowserSession($identity);
        $count = $this->requireSessions()->revokeOthers((string)$identity['user_id'], null);
        $this->audit->record('auth.all_sessions_revoked', (string)$identity['user_id'], ['count'=>$count]);
        return $count;
    }

    public function revokeOtherSessions(array $identity): int
    {
        $this->requireBrowserSession($identity);
        $count = $this->requireSessions()->revokeOthers((string)$identity['user_id'], (string)$identity['token_id']);
        $this->audit->record('auth.other_sessions_revoked', (string)$identity['user_id'], ['count'=>$count]);
        return $count;
    }

    /** Atomically replace a browser cookie credential, preserving external/local provenance. */
    public function rotateSession(array $identity, Request $request): array
    {
        $this->requireBrowserSession($identity);
        $sessions = $this->requireSessions();
        $oldId = (string)$identity['token_id']; $uid = (string)$identity['user_id'];
        $old = $sessions->find($oldId);
        if ($old === null || $old['revoked_at'] !== null) throw new \RuntimeException('Session is not active.');
        $scopes = $this->users->permissionsForUser($uid);
        // A failed transaction leaves the original session untouched.
        $database = $sessions->database();
        $token = $database->transaction(function (\PDO $pdo) use ($sessions, $old, $oldId, $uid, $scopes, $request): array {
            $ttlDays = max(1, $this->config->int('security.sessions.ttl_days', 14));
            $new = $this->issueSession($uid, $scopes, $ttlDays, (string)$old['provider'], $request, ['sid_hash'=>$old['oidc_sid_hash'], 'sub_hash'=>$old['oidc_sub_hash']]);
            if (!$sessions->revoke($oldId, $uid)) throw new \RuntimeException('Session changed during rotation.');
            return $new;
        });
        $this->audit->record('auth.session_rotated', $uid, ['old_token_id'=>$oldId, 'token_id'=>$token['id']]);
        return ['access_token'=>$token['plain_token'], 'expires_at'=>$token['expires_at'], 'csrf_token'=>self::csrfToken($token['plain_token'])];
    }

    private function requireBrowserSession(array $identity): void
    {
        if (($identity['session'] ?? false) !== true || ($identity['cookie'] ?? false) !== true
            || !is_string($identity['user_id'] ?? null) || !is_string($identity['token_id'] ?? null)) {
            throw new \RuntimeException('An authenticated browser session is required.');
        }
    }

    private function requireSessions(): UserSessionRepository
    {
        if ($this->sessions === null) throw new \RuntimeException('Session registry unavailable.');
        return $this->sessions;
    }

    public static function csrfToken(string $token): string
    {
        return hash('sha256', 'cajeer-csrf-v1:' . $token);
    }

    public static function intersectScopes(array $token, array $current): array
    {
        if (in_array('*', $token, true)) { return array_values($current); }
        if (in_array('*', $current, true)) { return array_values($token); }
        return array_values(array_intersect($token, $current));
    }

    /** @param list<string> $required */
    public function hasScopes(?array $identity, array $required): bool
    {
        if ($required === []) {
            return true;
        }
        if ($identity === null) {
            return false;
        }
        $scopes = $identity['scopes'] ?? [];
        if (!is_array($scopes)) {
            return false;
        }
        if (in_array('*', $scopes, true)) {
            return true;
        }
        foreach ($required as $scope) {
            if (!in_array($scope, $scopes, true)) {
                return false;
            }
        }
        return true;
    }

    public function logout(Request $request): bool
    {
        $identity = $request->attributes->get('auth');
        if (!is_array($identity) || !isset($identity['token_id'])) {
            return false;
        }
        if ($this->sessions !== null && ($identity['session'] ?? false) && is_string($identity['user_id'] ?? null)) {
            $this->sessions->revoke((string)$identity['token_id'], (string)$identity['user_id']);
        } else { $this->tokens->revoke((string)$identity['token_id']); }
        $this->audit->record('auth.logout', isset($identity['user_id']) ? (string) $identity['user_id'] : null, ['token_id' => $identity['token_id']], $request->getClientIp(), $request->headers->get('User-Agent'));
        return true;
    }

    public function confirmSensitiveAction(array $identity, string $password, ?string $code): bool
    {
        if (!($identity['session'] ?? false) || empty($identity['user']['email']) || $password === '') { return false; }
        $user = $this->users->findByEmailForAuth((string) $identity['user']['email']);
        if ($user === null || !$this->passwords->verify($password, (string) $user['password_hash'])) { return false; }
        return !filter_var($user['two_factor_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ($code !== null && $this->verifyTwoFactorCode((string) $user['id'], (string) $user['two_factor_secret'], $code));
    }

    /** @return array{secret:string,recovery_codes:list<string>} */
    public function enableTwoFactor(string $userId): array
    {
        $secret = $this->totp->generateSecret();
        $codes = $this->totp->recoveryCodes();
        $this->users->enableTwoFactor($userId, $secret, $this->totp->hashRecoveryCodes($codes));
        $this->tokens->revokeForUser($userId);
        $this->audit->record('auth.2fa_enabled', $userId, []);
        return ['secret' => $secret, 'recovery_codes' => $codes];
    }

    public function disableTwoFactor(string $userId): void
    {
        $this->users->disableTwoFactor($userId);
        $this->tokens->revokeForUser($userId);
        $this->audit->record('auth.2fa_disabled', $userId, []);
    }

    /** @return array<string, mixed> */
    private function publicUser(array $user): array
    {
        unset($user['password_hash'], $user['two_factor_secret'], $user['two_factor_recovery_codes']);
        $user['permissions'] = isset($user['id']) ? $this->users->permissionsForUser((string) $user['id']) : [];
        return $user;
    }

    private function verifyTwoFactorCode(string $userId, string $secret, string $code): bool
    {
        if ($this->totp->verify($secret, $code)) {
            return true;
        }
        return $this->users->consumeRecoveryCode($userId, $code);
    }
}
