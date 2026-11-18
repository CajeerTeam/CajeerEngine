<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Database\Repository\UserRepository;

/** One-time authorization state and operator-linked identities, persisted across PHP workers. */
final readonly class OidcStore
{
    public function __construct(private DatabaseManager $database, private SecretVault $vault) {}

    /** @return array{browser:string} */
    public function save(string $state, string $nonce, string $verifier): array
    {
        $browser = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $state);
        $this->database->connection()->prepare('DELETE FROM ce_oidc_transactions WHERE expires_at < :now')->execute(['now'=>time()]);
        $stmt = $this->database->connection()->prepare('INSERT INTO ce_oidc_transactions (state_hash, browser_hash, nonce, verifier_ciphertext, expires_at) VALUES (:state, :browser, :nonce, :verifier, :expiry)');
        $stmt->execute(['state'=>$hash, 'browser'=>hash('sha256', $browser), 'nonce'=>$nonce,
            'verifier'=>$this->vault->seal('oidc:pkce:'.$hash, $verifier), 'expiry'=>time()+300]);
        return compact('browser');
    }

    /** @return array{nonce:string,verifier:string} */
    public function consume(string $state, string $browser): array
    {
        if (strlen($state) > 128 || strlen($browser) > 128 || $state === '' || $browser === '') throw new \RuntimeException('OIDC browser transaction missing.');
        $hash = hash('sha256', $state);
        return $this->database->transaction(function (\PDO $pdo) use ($hash, $browser): array {
            $stmt = $pdo->prepare('SELECT nonce, verifier_ciphertext, expires_at FROM ce_oidc_transactions WHERE state_hash = :state AND browser_hash = :browser');
            $stmt->execute(['state'=>$hash, 'browser'=>hash('sha256', $browser)]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new \RuntimeException('Unknown or consumed OIDC transaction.');
            $stmt = $pdo->prepare('DELETE FROM ce_oidc_transactions WHERE state_hash = :state AND browser_hash = :browser');
            $stmt->execute(['state'=>$hash, 'browser'=>hash('sha256', $browser)]);
            if ($stmt->rowCount() !== 1 || (int) $row['expires_at'] < time()) throw new \RuntimeException('OIDC transaction expired or reused.');
            return ['nonce'=>(string)$row['nonce'], 'verifier'=>$this->vault->open('oidc:pkce:'.$hash, (string)$row['verifier_ciphertext'])];
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
}
