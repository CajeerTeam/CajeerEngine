<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Http;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Exceptions\NotFoundException;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Content\Repository\ContentRepository;
use Cajeer\View\ViewRenderer;

final class ContentController
{
    public function __construct(private readonly Container $container) {}

    public function home(Request $request): Response
    {
        $items = [];
        if ($this->container->has(DatabaseManager::class)) {
            $items = (new ContentRepository($this->container->get(DatabaseManager::class)))->latest('post', 6);
        }
        $html = $this->container->get(ViewRenderer::class)->render('home', [
            'title' => 'Cajeer Engine',
            'description' => 'PHP CMS с compatibility layer для DLE и WordPress.',
            'items' => $items,
        ]);
        return Response::html($html);
    }

    public function page(Request $request): Response
    {
        $slug = (string) $request->input('slug', 'page');
        $item = (new ContentRepository($this->container->get(DatabaseManager::class)))->findPublishedBySlug('page', $slug);
        if (!$item) {
            throw new NotFoundException('Страница не найдена.');
        }
        return Response::html($this->container->get(ViewRenderer::class)->render('page', ['title' => $item->title, 'content' => $item->body ?? '', 'item' => $item]));
    }

    public function news(Request $request): Response
    {
        $slug = (string) $request->input('slug', 'news');
        $item = (new ContentRepository($this->container->get(DatabaseManager::class)))->findPublishedBySlug('post', $slug);
        if (!$item) {
            throw new NotFoundException('Новость не найдена.');
        }
        return Response::html($this->container->get(ViewRenderer::class)->render('page', ['title' => $item->title, 'content' => $item->body ?? '', 'item' => $item]));
    }
}
