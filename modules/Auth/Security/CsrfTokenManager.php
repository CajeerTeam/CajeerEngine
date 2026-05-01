<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth\Security;

final class CsrfTokenManager
{
    public function __construct()
    {
        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            session_start();
        }
    }

    public function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    public function validate(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && hash_equals((string) $_SESSION['_csrf'], $token);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
