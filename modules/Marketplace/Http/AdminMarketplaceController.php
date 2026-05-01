<?php

declare(strict_types=1);

namespace Cajeer\Modules\Marketplace\Http;

use Cajeer\Container\Container;
use Cajeer\Extensions\ExtensionRegistry;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Auth\Security\CsrfTokenManager;
use Cajeer\View\ViewRenderer;

final class AdminMarketplaceController
{
    public function __construct(private readonly Container $container) {}

    public function index(Request $request): Response
    {
        return Response::html($this->container->get(ViewRenderer::class)->render('admin.marketplace.index', [
            'title' => 'Marketplace',
            'extensions' => $this->container->get(ExtensionRegistry::class)->all(),
            'csrf' => new CsrfTokenManager(),
        ]));
    }
}
