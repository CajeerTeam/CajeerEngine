<?php

declare(strict_types=1);

namespace Cajeer\Modules\Themes\Http;

use Cajeer\Container\Container;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Audit\AuditLogger;
use Cajeer\Themes\ThemeManager;
use Cajeer\View\ViewRenderer;

final class AdminThemeController
{
    public function __construct(private readonly Container $container) {}

    public function index(Request $request): Response
    {
        $themes = $this->container->get(ThemeManager::class);
        return Response::html($this->container->get(ViewRenderer::class)->render('admin.themes.index', [
            'title' => 'Темы',
            'themes' => $themes->all(),
            'active' => $themes->active()->slug,
        ]));
    }

    public function activate(Request $request): Response
    {
        $slug = (string) $request->input('name');
        $themes = $this->container->get(ThemeManager::class);
        $previous = $themes->active()->slug;
        try {
            $themes->activate($slug);
            try { (new AuditLogger($this->container))->log('theme.activated', ['theme' => $slug, 'previous' => $previous]); } catch (\Throwable) {}
        } catch (\Throwable $e) {
            try { $themes->activate($previous); } catch (\Throwable) {}
            throw $e;
        }
        return Response::redirect('/admin/themes');
    }

    public function preview(Request $request): Response
    {
        $slug = (string) $request->input('name');
        return Response::redirect('/?theme_preview=' . rawurlencode($slug));
    }
}
