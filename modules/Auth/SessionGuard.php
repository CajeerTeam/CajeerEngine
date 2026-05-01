<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth;

use Cajeer\Config\ConfigRepository;
use Cajeer\Database\DatabaseManager;
use Cajeer\Modules\Auth\Security\PasswordHasher;
use Cajeer\Modules\Auth\Session\SessionManager;
use Throwable;

final class SessionGuard
{
    private SessionManager $sessions;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly PasswordHasher $hasher = new PasswordHasher(),
        ?ConfigRepository $config = null
    ) {
        $this->sessions = new SessionManager(is_array($config?->get('security.session')) ? $config->get('security.session') : []);
        $this->sessions->start();
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
            $stmt = $this->database->connection()->prepare('SELECT id, email, username, display_name, status FROM cajeer_users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function attempt(string $login, string $password): bool
    {
        try {
            $stmt = $this->database->connection()->prepare('SELECT * FROM cajeer_users WHERE (email = :login OR username = :login) AND status = :status LIMIT 1');
            $stmt->execute(['login' => $login, 'status' => 'active']);
            $user = $stmt->fetch();
            if (!$user || !$this->hasher->verify($password, (string) $user['password_hash'])) {
                return false;
            }
            $this->sessions->regenerate();
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
        $this->sessions->destroy();
    }
}
