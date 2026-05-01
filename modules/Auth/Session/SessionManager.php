<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth\Session;

final class SessionManager
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function start(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $name = (string) ($this->config['name'] ?? 'cajeer_session');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (bool) ($this->config['secure'] ?? false),
            'httponly' => (bool) ($this->config['http_only'] ?? true),
            'samesite' => (string) ($this->config['same_site'] ?? 'Lax'),
        ]);
        session_start();
        $now = time();
        $idle = (int) ($this->config['idle_timeout'] ?? 3600);
        $absolute = (int) ($this->config['absolute_timeout'] ?? 28800);
        if (isset($_SESSION['_started_at']) && $now - (int) $_SESSION['_started_at'] > $absolute) {
            session_regenerate_id(true);
            $_SESSION = [];
        }
        if (isset($_SESSION['_last_seen_at']) && $now - (int) $_SESSION['_last_seen_at'] > $idle) {
            session_regenerate_id(true);
            $_SESSION = [];
        }
        $_SESSION['_started_at'] ??= $now;
        $_SESSION['_last_seen_at'] = $now;
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_last_seen_at'] = time();
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}
