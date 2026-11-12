<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Stable\StableReleaseService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class StableController
{
    public function __construct(private StableReleaseService $stable, private \CajeerEngine\Runtime\ConfigRepository $config)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function status(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->stable->status()]);
    }

    /** @param array<string, mixed> $parameters */
    public function smokeTest(Request $request, array $parameters): JsonResponse
    {
        $result = $this->stable->smokeTest();
        return new JsonResponse(['data' => $result], $result['ok'] === true ? 200 : 422);
    }

    /** @param array<string, mixed> $parameters */
    public function lock(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->stable->writeReleaseLock()], 201);
    }

    /** @param array<string, mixed> $parameters */
    public function backup(Request $request, array $parameters): JsonResponse
    {
        $identity=$request->attributes->get('auth');
        try { $result=(new \CajeerEngine\Update\UpdateManager($this->config->rootPath(),$this->config))->request('backup',$this->json($request),(string)($identity['user_id']??'api-token')); return new JsonResponse(['data'=>$result],202); }
        catch (\RuntimeException $e) { return new JsonResponse(['error'=>['code'=>'backup_request_rejected','message'=>$e->getMessage()]],422); }
    }

    /** @param array<string, mixed> $parameters */
    public function supportBundle(Request $request, array $parameters): JsonResponse
    {
        $result = $this->stable->supportBundle($this->json($request));
        return new JsonResponse(['data' => $result], $result['ok'] === true ? 201 : 422);
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
        return is_array($payload) ? $payload : [];
    }
}
