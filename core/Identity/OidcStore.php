<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserSessionRepository;
use CajeerEngine\Security\PasswordHasher;

/** One-time authorization state and operator-linked identities, persisted across PHP workers. */
final readonly class OidcStore
{
    public function __construct(private DatabaseManager $database, private SecretVault $vault) {}

    /** @return array{browser:string} */
    public function save(string $state, string $nonce, string $verifier, ?string $userId = null, ?string $sessionId = null, bool $stepUp = false): array
    {
        if ($stepUp && ($userId === null || $sessionId === null)) throw new \RuntimeException('Step-up requires a bound session.');
        $browser = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $state);
        $this->database->connection()->prepare('DELETE FROM ce_oidc_transactions WHERE expires_at < :now')->execute(['now'=>time()]);
        $stmt = $this->database->connection()->prepare('INSERT INTO ce_oidc_transactions (state_hash, browser_hash, nonce, verifier_ciphertext, expires_at, purpose, user_id, session_id) VALUES (:state, :browser, :nonce, :verifier, :expiry, :purpose, :user_id, :session_id)');
        $stmt->execute(['state'=>$hash, 'browser'=>hash('sha256', $browser), 'nonce'=>$nonce,
            'verifier'=>$this->vault->seal('oidc:pkce:'.$hash, $verifier), 'expiry'=>time()+300, 'purpose'=>$stepUp ? 'stepup' : ($userId === null ? 'login' : 'link'), 'user_id'=>$userId, 'session_id'=>$sessionId]);
        return compact('browser');
    }

    /** @return array{nonce:string,verifier:string,purpose:string,user_id:?string,session_id:?string} */
    public function consume(string $state, string $browser): array
    {
        if (strlen($state) > 128 || strlen($browser) > 128 || $state === '' || $browser === '') throw new \RuntimeException('OIDC browser transaction missing.');
        $hash = hash('sha256', $state);
        return $this->database->transaction(function (\PDO $pdo) use ($hash, $browser): array {
            $stmt = $pdo->prepare('SELECT nonce, verifier_ciphertext, expires_at, purpose, user_id, session_id FROM ce_oidc_transactions WHERE state_hash = :state AND browser_hash = :browser');
            $stmt->execute(['state'=>$hash, 'browser'=>hash('sha256', $browser)]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new \RuntimeException('Unknown or consumed OIDC transaction.');
            $stmt = $pdo->prepare('DELETE FROM ce_oidc_transactions WHERE state_hash = :state AND browser_hash = :browser');
            $stmt->execute(['state'=>$hash, 'browser'=>hash('sha256', $browser)]);
            if ($stmt->rowCount() !== 1 || (int) $row['expires_at'] < time()) throw new \RuntimeException('OIDC transaction expired or reused.');
            return ['nonce'=>(string)$row['nonce'], 'verifier'=>$this->vault->open('oidc:pkce:'.$hash, (string)$row['verifier_ciphertext']), 'purpose'=>(string)$row['purpose'], 'user_id'=>$row['user_id'] !== null ? (string)$row['user_id'] : null, 'session_id'=>$row['session_id'] !== null ? (string)$row['session_id'] : null];
        });
    }

    public function linkedUser(string $issuer, string $subject): ?string
    {
        $stmt = $this->database->connection()->prepare('SELECT user_id FROM ce_oidc_identities WHERE issuer = :issuer AND subject = :subject');
        $stmt->execute(['issuer'=>$issuer, 'subject'=>$subject]);
        $id = $stmt->fetchColumn();
        return is_string($id) ? $id : null;
    }

    /** Operator-only prelink. Does not link by email; rejects an identity attached to another user. */
    public function link(string $issuer, string $subject, string $userId, UserRepository $users): void
    {
        if ($issuer !== 'https://id.skif4er.ru/realms/skif4er' || strlen($subject) < 1 || strlen($subject) > 255 || preg_match('/[\x00-\x1f\x7f]/', $subject) || !preg_match('/^[a-fA-F0-9-]{36}$/D', $userId)) {
            throw new \RuntimeException('Invalid identity mapping.');
        }
        $user = $users->findById($userId);
        if ($user === null || ($user['status'] ?? null) !== 'active') throw new \RuntimeException('Target CajeerEngine user must be active.');
        $this->database->transaction(function (\PDO $pdo) use ($issuer, $subject, $userId): void {
            $existing = $pdo->prepare('SELECT user_id FROM ce_oidc_identities WHERE issuer = :issuer AND subject = :subject');
            $existing->execute(['issuer'=>$issuer, 'subject'=>$subject]);
            $mapped = $existing->fetchColumn();
            if ($mapped !== false) {
                if ($mapped !== $userId) throw new \RuntimeException('External identity already linked to another user.');
                return;
            }
            $stmt = $pdo->prepare('INSERT INTO ce_oidc_identities (issuer, subject, user_id) VALUES (:issuer, :subject, :user_id)');
            $stmt->execute(['issuer'=>$issuer, 'subject'=>$subject, 'user_id'=>$userId]);
        });
    }

    /** The session that authorized account-linking must still be active when OIDC returns. */
    public function completeLink(string $issuer, string $subject, string $userId, string $sessionId, UserRepository $users): void
    {
        $this->database->transaction(function (\PDO $pdo) use ($issuer, $subject, $userId, $sessionId, $users): void {
            // An old token can remain in ce_api_tokens while the browser session
            // was independently revoked. The registry must also be live.
            $token = $pdo->prepare("SELECT t.id FROM ce_api_tokens t
                JOIN ce_user_sessions s ON s.token_id = t.id AND s.user_id = t.user_id
                WHERE t.id = :id AND t.user_id = :user AND t.name = 'admin-session'
                  AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP)
                  AND s.revoked_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
                  AND s.last_seen_at >= :cutoff");
            $idle = max(5, (int)(getenv('SESSION_IDLE_MINUTES') ?: 720));
            $token->execute(['id'=>$sessionId, 'user'=>$userId, 'cutoff'=>gmdate('Y-m-d H:i:s', time() - $idle * 60)]);
            if ($token->fetchColumn() === false) throw new \RuntimeException('Local linking session expired or revoked.');
            $this->link($issuer, $subject, $userId, $users);
        });
    }

    /** @return array{linked:bool,provisioned:bool,created_at:?string} */
    public function statusForUser(string $issuer, string $userId): array
    {
        $stmt = $this->database->connection()->prepare('SELECT provisioned, created_at FROM ce_oidc_identities WHERE issuer = :issuer AND user_id = :user');
        $stmt->execute(['issuer'=>$issuer, 'user'=>$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? ['linked'=>false, 'provisioned'=>false, 'created_at'=>null] :
            ['linked'=>true, 'provisioned'=>(bool)$row['provisioned'], 'created_at'=>(string)$row['created_at']];
    }

    /** Never allow an OIDC-only account to unlink its last functional login method. */
    public function unlink(string $issuer, string $userId): bool
    {
        return $this->database->transaction(function (\PDO $pdo) use ($issuer, $userId): bool {
            $stmt = $pdo->prepare('SELECT provisioned FROM ce_oidc_identities WHERE issuer = :issuer AND user_id = :user');
            $stmt->execute(['issuer'=>$issuer, 'user'=>$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) return false;
            if ((bool)$row['provisioned']) throw new \RuntimeException('Set up a verified local login before unlinking a provisioned account.');
            $delete = $pdo->prepare('DELETE FROM ce_oidc_identities WHERE issuer = :issuer AND user_id = :user');
            $delete->execute(['issuer'=>$issuer, 'user'=>$userId]);
            if ($delete->rowCount() !== 1) throw new \RuntimeException('Identity changed during unlink.');
            // Removal without revocation leaves a live SkiF4er cookie that no
            // longer has a corresponding identity. Never allow that state.
            (new UserSessionRepository($this->database))->revokeSsoSessionsForUser($userId, $pdo);
            return true;
        });
    }

    /**
     * Issue a local SSO session while holding the exact issuer/subject mapping.
     * PostgreSQL/MySQL row locks serialize with administrator and self-unlink;
     * SQLite holds its transactional reader lock until session registration.
     * The callback MUST re-check the mapping after a separate provisioning step.
     *
     * @template T @param callable(string):T $issue @return T
     */
    public function withLinkedIdentity(string $issuer, string $subject, callable $issue): mixed
    {
        return $this->database->transaction(function (\PDO $pdo) use ($issuer, $subject, $issue): mixed {
            $locking = $this->database->driver() !== 'sqlite' ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare('SELECT user_id FROM ce_oidc_identities WHERE issuer = :issuer AND subject = :subject'.$locking);
            $stmt->execute(['issuer' => $issuer, 'subject' => $subject]);
            $userId = $stmt->fetchColumn();
            if (!is_string($userId) || $userId === '') throw new \RuntimeException('OIDC identity was unlinked during login.');
            return $issue($userId);
        });
    }

    /** Atomically provision only new, verified identities. Never merge accounts by email. */
    public function provision(string $issuer, array $claims, UserRepository $users, RoleRepository $roles, PasswordHasher $passwords): string
    {
        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (!is_string($subject) || $subject === '' || strlen($subject) > 255 || preg_match('/[\x00-\x1f\x7f]/', $subject)
            || ($claims['email_verified'] ?? false) !== true || !is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A verified OIDC email and valid subject are required for account creation.');
        }
        $email = strtolower(trim($email));
        $name = trim((string)($claims['name'] ?? $claims['preferred_username'] ?? ''));
        if ($name === '' || strlen($name) > 150 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            $name = explode('@', $email)[0];
        }
        return $this->database->transaction(function (\PDO $pdo) use ($issuer, $subject, $email, $name, $users, $roles, $passwords): string {
            $existing = $this->linkedUser($issuer, $subject);
            if ($existing !== null) return $existing; // Another callback may have provisioned concurrently.
            if ($users->findByEmail($email) !== null) throw new \RuntimeException('An account with this email exists. Sign in locally and link it instead.');
            $role = $roles->find('viewer');
            $allowed = ['content:read', 'media:read', 'system:read'];
            if ($role === null || array_diff($role['permissions'], $allowed) !== []) {
                throw new \RuntimeException('Viewer role grants unsafe permissions for OIDC provisioning.');
            }
            // The random password is deliberately never disclosed; local access requires a separate reset workflow.
            $userId = $users->create($email, $name, $passwords->hash('A9a' . bin2hex(random_bytes(48))), ['viewer']);
            $insert = $pdo->prepare('INSERT INTO ce_oidc_identities (issuer, subject, user_id, provisioned) VALUES (:issuer, :subject, :user, :provisioned)');
            $insert->execute(['issuer'=>$issuer, 'subject'=>$subject, 'user'=>$userId, 'provisioned'=>1]);
            return $userId;
        });
    }

}
