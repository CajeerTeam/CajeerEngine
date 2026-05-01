<?php

declare(strict_types=1);

namespace Cajeer\Modules\Marketplace\Http;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Extensions\ExtensionRegistry;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Audit\AuditLogger;
use Cajeer\Modules\Auth\Security\CsrfTokenManager;
use Cajeer\Modules\Auth\SessionGuard;
use Cajeer\Modules\Marketplace\PackageInstaller;
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
            'result' => null,
        ]));
    }

    public function install(Request $request): Response
    {
        $file = $request->files['package'] ?? null;
        $result = ['error' => 'Файл пакета не загружен.'];
        $db = $this->container->get(DatabaseManager::class);
        if (is_array($file) && is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $result = (new PackageInstaller(dirname(__DIR__, 3), $db))->install((string) $file['tmp_name']);
            $actor = (new SessionGuard($db))->id();
            (new AuditLogger($db))->record('marketplace.package.installed', $actor, 'package', $result['manifest']['name'] ?? null, $result, $request);
        }
        return Response::html($this->container->get(ViewRenderer::class)->render('admin.marketplace.index', [
            'title' => 'Marketplace',
            'extensions' => $this->container->get(ExtensionRegistry::class)->all(),
            'csrf' => new CsrfTokenManager(),
            'result' => $result,
        ]));
    }
}
