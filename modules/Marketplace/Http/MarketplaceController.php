<?php

declare(strict_types=1);

namespace Cajeer\Modules\Marketplace\Http;

use Cajeer\Container\Container;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\View\ViewRenderer;

final class MarketplaceController
{
    public function __construct(private readonly Container $container) {}

    public function index(Request $request): Response
    {
        return Response::html($this->container->get(ViewRenderer::class)->render('marketplace.index', [
            'title' => 'Marketplace',
            'type' => 'all',
        ]));
    }

    public function themes(Request $request): Response
    {
        return Response::html($this->container->get(ViewRenderer::class)->render('marketplace.index', [
            'title' => 'Темы',
            'type' => 'themes',
        ]));
    }

    public function plugins(Request $request): Response
    {
        return Response::html($this->container->get(ViewRenderer::class)->render('marketplace.index', [
            'title' => 'Плагины',
            'type' => 'plugins',
        ]));
    }
}
