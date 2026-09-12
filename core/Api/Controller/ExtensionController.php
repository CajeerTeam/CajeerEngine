<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Extension\Runtime\ExtensionRuntime;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ExtensionController
{
    public function __construct(private ExtensionRegistry $extensions, private ?ExtensionRuntime $runtime = null)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function index(Request $request, array $parameters): JsonResponse
    {
        $items = $this->extensions->all();
        $type = (string) $request->query->get('type', '');
        $enabled = (string) $request->query->get('enabled', '');
        $installed = (string) $request->query->get('installed', '');

        $items = array_values(array_filter($items, static function (array $item) use ($type, $enabled, $installed): bool {
            if ($type !== '' && ($item['type'] ?? '') !== $type) {
                return false;
            }
            if ($enabled !== '' && ((bool) ($item['enabled'] ?? false)) !== filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
            if ($installed !== '' && ((bool) ($item['installed'] ?? false)) !== filter_var($installed, FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
            return true;
        }));

        return new JsonResponse(['data' => $items, 'meta' => $this->extensions->diagnostics()]);
    }

    /** @param array<string, mixed> $parameters */
    public function diagnostics(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->extensions->diagnostics()]);
    }

    /** @param array<string, mixed> $parameters */
    public function runtime(Request $request, array $parameters): JsonResponse
    {
        if ($this->runtime === null) {
            return new JsonResponse(['data' => ['ok' => false, 'booted' => false, 'message' => 'ExtensionRuntime не зарегистрирован.']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $boot = !in_array((string) $request->query->get('boot', '0'), ['0', 'false', 'no'], true);
        return new JsonResponse(['data' => $boot ? $this->runtime->boot(true) : $this->runtime->diagnostics()]);
    }

    /** @param array<string, mixed> $parameters */
    public function permissions(Request $request, array $parameters): JsonResponse
    {
        $enabledOnly = !in_array((string) $request->query->get('enabled', '1'), ['0', 'false', 'no'], true);
        return new JsonResponse(['data' => $this->extensions->permissions($enabledOnly)]);
    }

    /** @param array<string, mixed> $parameters */
    public function events(Request $request, array $parameters): JsonResponse
    {
        $enabledOnly = !in_array((string) $request->query->get('enabled', '1'), ['0', 'false', 'no'], true);
        return new JsonResponse(['data' => $this->extensions->eventSubscriptions($enabledOnly)]);
    }

    /** @param array<string, mixed> $parameters */
    public function validate(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $target = (string) ($payload['target'] ?? $request->query->get('target', ''));
        if ($target === '') {
            return $this->error('extension_target_required', 'Укажите target: путь к manifest, директория расширения или имя расширения.', Response::HTTP_BAD_REQUEST);
        }
        $result = $this->extensions->validateManifest($target);
        return new JsonResponse(['data' => $result], $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @param array<string, mixed> $parameters */
    public function install(Request $request, array $parameters): JsonResponse
    {
        try {
            $payload = $this->json($request);
            $target = (string) ($payload['target'] ?? $payload['name'] ?? '');
            if ($target === '') {
                return $this->error('extension_target_required', 'Укажите target или name.', Response::HTTP_BAD_REQUEST);
            }
            $item = $this->runtime !== null
                ? $this->runtime->install($target, !empty($payload['enable']), !array_key_exists('lifecycle', $payload) || (bool) $payload['lifecycle'], !empty($payload['publish_assets']), !array_key_exists('migrations', $payload) || (bool) $payload['migrations'])
                : $this->extensions->install($target, !empty($payload['enable']));
            return new JsonResponse(['data' => $item], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return $this->error('extension_install_failed', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, mixed> $parameters */
    public function enable(Request $request, array $parameters): JsonResponse
    {
        try {
            $name = $this->nameFromRequest($request);
            return new JsonResponse(['data' => $this->extensions->enable($name)]);
        } catch (\Throwable $e) {
            return $this->error('extension_enable_failed', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, mixed> $parameters */
    public function disable(Request $request, array $parameters): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $this->extensions->disable($this->nameFromRequest($request))]);
        } catch (\Throwable $e) {
            return $this->error('extension_disable_failed', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, mixed> $parameters */
    public function uninstall(Request $request, array $parameters): JsonResponse
    {
        try {
            $payload = $this->json($request);
            $name = $this->nameFromRequest($request);
            $data = $this->runtime !== null
                ? $this->runtime->uninstall($name, !array_key_exists('lifecycle', $payload) || (bool) $payload['lifecycle'], !empty($payload['remove_assets']))
                : ['deleted' => $this->extensions->uninstall($name)];
            return new JsonResponse(['data' => $data]);
        } catch (\Throwable $e) {
            return $this->error('extension_uninstall_failed', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, mixed> $parameters */
    public function config(Request $request, array $parameters): JsonResponse
    {
        try {
            $payload = $this->json($request);
            $name = (string) ($payload['name'] ?? '');
            $config = $payload['config'] ?? null;
            if ($name === '' || !is_array($config)) {
                return $this->error('extension_config_invalid', 'Укажите name и config-объект.', Response::HTTP_BAD_REQUEST);
            }
            return new JsonResponse(['data' => $this->extensions->updateConfig($name, $config)]);
        } catch (\Throwable $e) {
            return $this->error('extension_config_failed', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, mixed> $parameters */
    public function dispatchEvent(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $event = (string) ($payload['event'] ?? '');
        if ($event === '') {
            return $this->error('extension_event_required', 'Укажите event.', Response::HTTP_BAD_REQUEST);
        }
        $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        return new JsonResponse(['data' => $this->runtime?->dispatch($event, $body) ?? $this->extensions->dispatchEvent($event, $body)]);
    }

    /** @param array<string, mixed> $parameters */
    public function publishAssets(Request $request, array $parameters): JsonResponse
    {
        if ($this->runtime === null) {
            return $this->error('extension_runtime_missing', 'ExtensionRuntime не зарегистрирован.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $payload = $this->json($request);
        return new JsonResponse(['data' => $this->runtime->publishAssets((string) ($payload['name'] ?? '') ?: null, !empty($payload['dry_run']))]);
    }

    /** @param array<string, mixed> $parameters */
    public function migrate(Request $request, array $parameters): JsonResponse
    {
        if ($this->runtime === null) {
            return $this->error('extension_runtime_missing', 'ExtensionRuntime не зарегистрирован.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $payload = $this->json($request);
        return new JsonResponse(['data' => $this->runtime->migrate((string) ($payload['name'] ?? '') ?: null, !empty($payload['dry_run']))]);
    }

    private function nameFromRequest(Request $request): string
    {
        $payload = $this->json($request);
        $name = (string) ($payload['name'] ?? $payload['extension'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Укажите name расширения.');
        }
        return $name;
    }

    /** @return array<string, mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        if (is_array($parsed)) {
            return $parsed;
        }
        $body = trim($request->getContent());
        if ($body === '') {
            return [];
        }
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON body должен быть объектом.');
        }
        return $payload;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
