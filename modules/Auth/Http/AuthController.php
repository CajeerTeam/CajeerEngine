<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth\Http;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Auth\Security\CsrfTokenManager;
use Cajeer\Modules\Auth\SessionGuard;
use Cajeer\View\ViewRenderer;

final class AuthController
{
    public function __construct(private readonly Container $container) {}

    public function login(Request $request): Response
    {
        $html = $this->container->get(ViewRenderer::class)->render('auth.login', [
            'title' => 'Вход в админку',
            'csrf' => new CsrfTokenManager(),
            'error' => null,
        ]);
        return Response::html($html);
    }

    public function authenticate(Request $request): Response
    {
        $guard = new SessionGuard($this->container->get(DatabaseManager::class));
        if ($guard->attempt(trim((string) $request->input('login')), (string) $request->input('password'))) {
            return Response::redirect('/admin');
        }

        $html = $this->container->get(ViewRenderer::class)->render('auth.login', [
            'title' => 'Вход в админку',
            'csrf' => new CsrfTokenManager(),
            'error' => 'Неверный логин или пароль.',
        ]);
        return Response::html($html, 422);
    }

    public function logout(Request $request): Response
    {
        (new SessionGuard($this->container->get(DatabaseManager::class)))->logout();
        return Response::redirect('/admin/login');
    }
}
