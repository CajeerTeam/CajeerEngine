<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Http;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Exceptions\NotFoundException;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Audit\AuditLogger;
use Cajeer\Modules\Auth\Security\CsrfTokenManager;
use Cajeer\Modules\Auth\SessionGuard;
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
        $id = $this->repo()->create($this->payload($request));
        $this->audit('content.created', $request, $id);
        return Response::redirect('/admin/content');
    }

    public function edit(Request $request): Response
    {
        $item = $this->repo()->find((int) $request->input('id'));
        if (!$item) throw new NotFoundException('Материал не найден.');
        return Response::html($this->view()->render('admin.content.form', [
            'title' => 'Редактировать материал',
            'item' => $item,
            'csrf' => new CsrfTokenManager(),
            'action' => '/admin/content/' . $item->id,
        ]));
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->input('id');
        $this->repo()->update($id, $this->payload($request));
        $this->audit('content.updated', $request, $id);
        return Response::redirect('/admin/content');
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->input('id');
        $this->repo()->delete($id);
        $this->audit('content.deleted', $request, $id);
        return Response::redirect('/admin/content');
    }

    private function repo(): ContentRepository { return new ContentRepository($this->container->get(DatabaseManager::class)); }
    private function view(): ViewRenderer { return $this->container->get(ViewRenderer::class); }

    private function payload(Request $request): array
    {
        $title = trim((string) $request->input('title')) ?: 'Без названия';
        return [
            'type' => (string) $request->input('type', 'post'),
            'status' => (string) $request->input('status', 'draft'),
            'slug' => trim((string) $request->input('slug', '')),
            'title' => $title,
            'excerpt' => trim((string) $request->input('excerpt', '')),
            'body' => (string) $request->input('body', ''),
            'published_at' => trim((string) $request->input('published_at', '')),
            'meta_title' => trim((string) $request->input('meta_title', '')),
            'meta_description' => trim((string) $request->input('meta_description', '')),
            'canonical_url' => trim((string) $request->input('canonical_url', '')),
            'cover_image' => trim((string) $request->input('cover_image', '')),
            'category_id' => (int) $request->input('category_id', 0),
            'sort_order' => (int) $request->input('sort_order', 0),
            'visibility' => (string) $request->input('visibility', 'public'),
        ];
    }

    private function audit(string $event, Request $request, int $id): void
    {
        $db = $this->container->get(DatabaseManager::class);
        $actor = (new SessionGuard($db))->id();
        (new AuditLogger($db))->record($event, $actor, 'content', $id, [], $request);
    }
}
