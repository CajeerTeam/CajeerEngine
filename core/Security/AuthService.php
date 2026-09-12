<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\UserRepository;
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
        $token = $this->tokens->create((string) $user['id'], 'admin-session', $scopes, new \DateTimeImmutable('+' . $ttlDays . ' days'));
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

    /** @return array<string, mixed>|null */
    public function authenticateRequest(Request $request): ?array
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) {
            $token = trim($m[1]);
        }
        if ($token === '') {
            $token = (string) $request->headers->get('X-API-Token', '');
        }
        if ($token === '') {
            return null;
        }

        $record = $this->tokens->findValidPlainToken($token);
        if ($record === null) {
            return null;
        }
        $user = isset($record['user_id']) && $record['user_id'] !== null ? $this->users->findById((string) $record['user_id']) : null;
        $scopes = $record['scopes'];
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            $scopes = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }
        if ($scopes === ['*'] || in_array('*', is_array($scopes) ? $scopes : [], true)) {
            $scopes = ['*'];
        }
        $this->tokens->touch((string) $record['id']);
        return [
            'token_id' => (string) $record['id'],
            'user' => $user !== null ? $this->publicUser($user) : null,
            'user_id' => $record['user_id'] ?? null,
            'scopes' => is_array($scopes) ? array_values($scopes) : [],
        ];
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
        $this->tokens->revoke((string) $identity['token_id']);
        $this->audit->record('auth.logout', isset($identity['user_id']) ? (string) $identity['user_id'] : null, ['token_id' => $identity['token_id']], $request->getClientIp(), $request->headers->get('User-Agent'));
        return true;
    }

    /** @return array{secret:string,recovery_codes:list<string>} */
    public function enableTwoFactor(string $userId): array
    {
        $secret = $this->totp->generateSecret();
        $codes = $this->totp->recoveryCodes();
        $this->users->enableTwoFactor($userId, $secret, $this->totp->hashRecoveryCodes($codes));
        $this->audit->record('auth.2fa_enabled', $userId, []);
        return ['secret' => $secret, 'recovery_codes' => $codes];
    }

    public function disableTwoFactor(string $userId): void
    {
        $this->users->disableTwoFactor($userId);
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
