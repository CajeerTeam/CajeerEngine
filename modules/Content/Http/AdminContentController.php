<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Http;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Exceptions\NotFoundException;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Auth\Security\CsrfTokenManager;
use Cajeer\Modules\Content\Repository\ContentRepository;
use Cajeer\View\ViewRenderer;

final class AdminContentController
{
    public function __construct(private readonly Container $container) {}

    public function index(Request $request): Response
    {
        $repo = $this->repo();
        return Response::html($this->view()->render('admin.content.index', [
            'title' => 'Контент',
            'items' => array_merge($repo->latest('post', 50), $repo->latest('page', 50)),
            'csrf' => new CsrfTokenManager(),
        ]));
    }

    public function create(Request $request): Response
    {
        return Response::html($this->view()->render('admin.content.form', [
            'title' => 'Создать материал',
            'item' => null,
            'csrf' => new CsrfTokenManager(),
            'action' => '/admin/content',
        ]));
    }

    public function store(Request $request): Response
    {
        $this->repo()->create($this->payload($request));
        return Response::redirect('/admin/content');
    }

    public function edit(Request $request): Response
    {
        $item = $this->repo()->find((int) $request->input('id'));
        if (!$item) {
            throw new NotFoundException('Материал не найден.');
        }
        return Response::html($this->view()->render('admin.content.form', [
            'title' => 'Редактировать материал',
            'item' => $item,
            'csrf' => new CsrfTokenManager(),
            'action' => '/admin/content/' . $item->id,
        ]));
    }

    public function update(Request $request): Response
    {
        $this->repo()->update((int) $request->input('id'), $this->payload($request));
        return Response::redirect('/admin/content');
    }

    public function delete(Request $request): Response
    {
        $this->repo()->delete((int) $request->input('id'));
        return Response::redirect('/admin/content');
    }

    private function repo(): ContentRepository
    {
        return new ContentRepository($this->container->get(DatabaseManager::class));
    }

    private function view(): ViewRenderer
    {
        return $this->container->get(ViewRenderer::class);
    }

    private function payload(Request $request): array
    {
        $title = trim((string) $request->input('title'));
        if ($title === '') {
            $title = 'Без названия';
        }
        return [
            'type' => (string) $request->input('type', 'post'),
            'status' => (string) $request->input('status', 'draft'),
            'slug' => trim((string) $request->input('slug', '')),
            'title' => $title,
            'excerpt' => trim((string) $request->input('excerpt', '')),
            'body' => (string) $request->input('body', ''),
        ];
    }
}
