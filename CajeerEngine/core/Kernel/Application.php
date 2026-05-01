<?php

declare(strict_types=1);

namespace Cajeer\Kernel;

use Cajeer\Config\ConfigRepository;
use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Events\EventDispatcher;
use Cajeer\Extensions\ExtensionRegistry;
use Cajeer\Http\Exceptions\HttpException;
use Cajeer\Http\Exceptions\ValidationException;
use Cajeer\Http\MiddlewarePipeline;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Logging\Logger;
use Cajeer\Routing\Router;
use Cajeer\Support\Path;
use Cajeer\Themes\ThemeAssetManager;
use Cajeer\Themes\ThemeManager;
use Cajeer\Themes\ThemeViewRenderer;
use Cajeer\View\ViewRenderer;

final class Application
{
    private Container $container;
    private Router $router;
    private EventDispatcher $events;
    private ExtensionRegistry $extensions;
    private bool $booted = false;

    private function __construct(private readonly string $basePath)
    {
        $this->container = new Container();
        $this->events = new EventDispatcher();
        $this->router = new Router();
        $this->extensions = new ExtensionRegistry($basePath, $this->events);
    }

    public static function create(string $basePath): self
    {
        $app = new self($basePath);
        $app->bootstrap();
        return $app;
    }

    public function bootstrap(): void
    {
        if ($this->booted) {
            return;
        }

        $paths = new Path($this->basePath);
        $configPath = is_dir($this->basePath . '/configs') ? $this->basePath . '/configs' : $this->basePath . '/config';
        $config = ConfigRepository::load($configPath);
        $logger = new Logger($this->basePath . '/storage/logs/app.log');
        $database = new DatabaseManager($config);
        $themes = new ThemeManager($this->basePath, $database, $config);
        $views = new ViewRenderer($this->basePath . '/resources/views', $themes);

        $this->container->set(Path::class, $paths);
        $this->container->set(ConfigRepository::class, $config);
        $this->container->set(EventDispatcher::class, $this->events);
        $this->container->set(Router::class, $this->router);
        $this->container->set(Logger::class, $logger);
        $this->container->set(ViewRenderer::class, $views);
        $this->container->set(ExtensionRegistry::class, $this->extensions);
        $this->container->set(DatabaseManager::class, $database);
        $this->container->set(ThemeManager::class, $themes);
        $this->container->set(ThemeAssetManager::class, new ThemeAssetManager($themes));
        $this->container->set(ThemeViewRenderer::class, new ThemeViewRenderer($themes));
        $GLOBALS['cajeer_theme_manager'] = $themes;
        $this->container->set(self::class, $this);

        if (function_exists('cajeer_wp_runtime')) {
            cajeer_wp_runtime()->setDatabase($database);
        }

        $this->ensureRuntimeDirectories();
        $this->loadRoutes();
        $this->extensions->discoverAndRegister($this->container);

        $this->events->dispatch('system.boot', ['app' => $this]);
        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        try {
            $this->events->dispatch('request.received', ['request' => $request]);

            $pipeline = new MiddlewarePipeline([
                new \Cajeer\Http\Middleware\TrustProxyMiddleware(),
                new \Cajeer\Http\Middleware\MaintenanceMiddleware($this->basePath),
                new \Cajeer\Http\Middleware\CsrfMiddleware(),
                new \Cajeer\Http\Middleware\AdminAuthMiddleware($this->container, $this->basePath),
                new \Cajeer\Http\Middleware\SecurityHeadersMiddleware(),
            ]);

            return $pipeline->handle($request, function (Request $request): Response {
                $response = $this->router->dispatch($request, $this->container);
                $this->events->dispatch('response.sending', ['request' => $request, 'response' => $response]);
                return $response;
            });
        } catch (ValidationException $e) {
            return $request->wantsJson()
                ? Response::json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422)
                : Response::html('<h1>422</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>', 422);
        } catch (HttpException $e) {
            if ($request->wantsJson()) {
                return Response::json(['message' => $e->getMessage(), 'status' => $e->statusCode()], $e->statusCode());
            }
            return $this->renderError($e->statusCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->container->get(Logger::class)->error($e->getMessage(), ['exception' => $e]);
            $debug = (bool) $this->container->get(ConfigRepository::class)->get('app.debug', false);
            $message = $debug ? $e->getMessage() : 'Внутренняя ошибка сервера';
            return $request->wantsJson()
                ? Response::json(['message' => $message], 500)
                : $this->renderError(500, $message);
        }
    }

    private function renderError(int $status, string $message): Response
    {
        try {
            $view = $status === 404 ? 'errors.404' : 'errors.500';
            $html = $this->container->get(ViewRenderer::class)->render($view, [
                'title' => $status === 404 ? 'Страница не найдена' : 'Ошибка сервера',
                'message' => $message,
                'status' => $status,
                'config' => $this->container->get(ThemeManager::class)->config(),
                'page' => (string) $status,
                'pageMeta' => ['title' => (string) $status, 'description' => $message],
                'canonicalUrl' => '/',
            ]);
            return Response::html($html, $status);
        } catch (\Throwable) {
            return Response::html('<h1>' . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', $status);
        }
    }

    public function container(): Container { return $this->container; }
    public function router(): Router { return $this->router; }
    public function events(): EventDispatcher { return $this->events; }

    private function loadRoutes(): void
    {
        foreach (['web.php', 'admin.php', 'api.php', 'legacy.php'] as $file) {
            $path = $this->basePath . '/routes/' . $file;
            if (is_file($path)) {
                $router = $this->router;
                require $path;
            }
        }
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach (['cache', 'logs', 'compiled_tpl', 'sessions', 'uploads', 'backups', 'framework'] as $dir) {
            $path = $this->basePath . '/storage/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
        if (!is_dir($this->basePath . '/public/uploads')) {
            mkdir($this->basePath . '/public/uploads', 0775, true);
        }
    }
}
