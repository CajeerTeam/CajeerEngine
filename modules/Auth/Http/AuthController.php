<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth\Http;

use Cajeer\Config\ConfigRepository;
use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Audit\AuditLogger;
use Cajeer\Modules\Auth\Security\CsrfTokenManager;
use Cajeer\Modules\Auth\SessionGuard;
use Cajeer\View\ViewRenderer;

final class AuthController
{
    public function __construct(private readonly Container $container) {}

    public function login(Request $request): Response
    {
        return Response::html($this->container->get(ViewRenderer::class)->render('auth.login', [
            'title' => 'Вход в админку',
            'csrf' => new CsrfTokenManager(),
            'error' => null,
        ]));
    }

    public function authenticate(Request $request): Response
    {
        $db = $this->container->get(DatabaseManager::class);
        $guard = new SessionGuard($db, config: $this->container->get(ConfigRepository::class));
        $login = trim((string) $request->input('login'));
        if ($guard->attempt($login, (string) $request->input('password'))) {
            (new AuditLogger($db))->record('auth.login.success', $guard->id(), 'user', $guard->id(), ['login' => $login], $request);
            return Response::redirect('/admin');
        }

        (new AuditLogger($db))->record('auth.login.failed', null, 'user', null, ['login' => $login], $request);
        return Response::html($this->container->get(ViewRenderer::class)->render('auth.login', [
            'title' => 'Вход в админку',
            'csrf' => new CsrfTokenManager(),
            'error' => 'Неверный логин или пароль.',
        ]), 422);
    }

    public function logout(Request $request): Response
    {
        $db = $this->container->get(DatabaseManager::class);
        $guard = new SessionGuard($db, config: $this->container->get(ConfigRepository::class));
        $id = $guard->id();
        (new AuditLogger($db))->record('auth.logout', $id, 'user', $id, [], $request);
        $guard->logout();
        return Response::redirect('/admin/login');
    }
}
