<?php

declare(strict_types=1);

namespace Cajeer\Modules\Admin\Http;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;
use Cajeer\Extensions\ExtensionRegistry;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\View\ViewRenderer;

final class AdminController
{
    public function __construct(private readonly Container $container) {}

    public function dashboard(Request $request): Response
    {
        $events = $this->container->get(EventDispatcher::class);
        $menu = $events->dispatch('admin.menu', ['items' => []]);
        $extensions = $this->container->get(ExtensionRegistry::class)->all();

        $html = $this->container->get(ViewRenderer::class)->render('admin.dashboard', [
            'title' => 'Админ-панель',
            'menu' => $menu['items'] ?? [],
            'extensions' => $extensions,
        ]);
        return Response::html($html);
    }

    public function compatibility(Request $request): Response
    {
        $html = $this->container->get(ViewRenderer::class)->render('admin.compatibility', [
            'title' => 'Центр совместимости',
        ]);
        return Response::html($html);
    }
}
