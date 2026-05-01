<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Http;

use Cajeer\Container\Container;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\View\ViewRenderer;

final class ContentController
{
    public function __construct(private readonly Container $container) {}

    public function home(Request $request): Response
    {
        $html = $this->container->get(ViewRenderer::class)->render('home', [
            'title' => 'Cajeer Engine',
            'description' => 'PHP CMS с compatibility layer для DLE и WordPress.',
        ]);
        return Response::html($html);
    }

    public function page(Request $request): Response
    {
        $slug = (string) $request->input('slug', 'page');
        $html = $this->container->get(ViewRenderer::class)->render('page', [
            'title' => 'Страница: ' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'),
            'content' => 'Это заглушка страницы. Здесь будет Content Repository.',
        ]);
        return Response::html($html);
    }

    public function news(Request $request): Response
    {
        $slug = (string) $request->input('slug', 'news');
        $html = $this->container->get(ViewRenderer::class)->render('page', [
            'title' => 'Новость: ' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'),
            'content' => 'Это заглушка новости. Маршрут готов под DLE-style URL.',
        ]);
        return Response::html($html);
    }
}
