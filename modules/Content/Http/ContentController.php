<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Http;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Events\EventDispatcher;
use Cajeer\Http\Exceptions\NotFoundException;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Content\Repository\ContentRepository;
use Cajeer\Modules\Content\Service\ContentRenderer;
use Cajeer\View\ViewRenderer;

final class ContentController
{
    public function __construct(private readonly Container $container) {}

    public function home(Request $request): Response
    {
        $repo = new ContentRepository($this->container->get(DatabaseManager::class));
        $html = $this->container->get(ViewRenderer::class)->render('home', [
            'title' => 'Cajeer Engine',
            'description' => 'PHP CMS с compatibility layer для DLE и WordPress.',
            'items' => $repo->latest('post', 10),
        ]);
        return Response::html($html);
    }

    public function page(Request $request): Response
    {
        return $this->show('page', (string) $request->input('slug'));
    }

    public function news(Request $request): Response
    {
        return $this->show('post', (string) $request->input('slug'));
    }

    private function show(string $type, string $slug): Response
    {
        $repo = new ContentRepository($this->container->get(DatabaseManager::class));
        $item = $repo->findPublishedBySlug($type, $slug);
        if (!$item) throw new NotFoundException('Материал не найден.');
        $content = (new ContentRenderer($this->container->get(EventDispatcher::class)))->render($item);
        $html = $this->container->get(ViewRenderer::class)->render('page', [
            'title' => $item->metaTitle ?: $item->title,
            'content' => $content,
            'item' => $item,
        ]);
        return Response::html($html);
    }
}
