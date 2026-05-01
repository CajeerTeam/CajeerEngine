<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth;

use Cajeer\Database\DatabaseManager;
use Cajeer\Modules\Auth\Security\PasswordHasher;
use Throwable;

final class SessionGuard
{
    public function __construct(private readonly DatabaseManager $database, private readonly PasswordHasher $hasher = new PasswordHasher())
    {
        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            session_start();
        }
    }

    public function check(): bool
    {
        return isset($_SESSION['admin_user_id']);
    }

    public function id(): ?int
    {
        return isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    }

    public function user(): ?array
    {
        $id = $this->id();
        if (!$id) {
            return null;
        }
        try {
            $pdo = $this->database->connection();
            $stmt = $pdo->prepare('SELECT id, email, username, display_name, status FROM cajeer_users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function attempt(string $login, string $password): bool
    {
        try {
            $pdo = $this->database->connection();
            $stmt = $pdo->prepare('SELECT * FROM cajeer_users WHERE (email = :login OR username = :login) AND status = :status LIMIT 1');
            $stmt->execute(['login' => $login, 'status' => 'active']);
            $user = $stmt->fetch();
            if (!$user || !$this->hasher->verify($password, (string) $user['password_hash'])) {
                return false;
            }
            $_SESSION['admin_user_id'] = (int) $user['id'];
            $_SESSION['admin_user'] = [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
            ];
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function logout(): void
    {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_user']);
    }
}
