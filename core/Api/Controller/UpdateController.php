<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateManager;
use CajeerEngine\Update\UpdateResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class UpdateController
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function diagnostics(Request $request, array $parameters): JsonResponse
    {
        $resolver = new UpdateResolver($this->config->get('updates', []), $this->config->rootPath());
        return new JsonResponse(['data' => $resolver->diagnostics($this->config->string('app.version', '1.5.0')) + ['wizard'=>$this->manager()->wizardState(),'staged'=>$this->manager()->staged(),'requests'=>$this->manager()->recentRequests(),'backups'=>array_slice((new \CajeerEngine\Backup\BackupManager($this->config->rootPath(), $this->config))->list()['backups'],0,20),'downloads'=>$this->manager()->downloaded(),'web_upgrade_enabled'=>$this->config->bool('updates.web_upgrade.enabled')]]);
    }

    /** @param array<string, mixed> $parameters */
    public function check(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->manager()->check()]);
    }

    /** @param array<string, mixed> $parameters */
    public function plan(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->manager()->plan($this->json($request))]);
    }

    /** @param array<string, mixed> $parameters */
    public function prepare(Request $request, array $parameters): JsonResponse
    {
        $result = $this->manager()->prepare($this->json($request));
        return new JsonResponse(['data' => $result], 201);
    }

    /** Queue download and signed staging through the one-shot operator, never PHP-FPM. */
    public function download(Request $request, array $parameters): JsonResponse
    {
        return $this->requestOperation($request, 'download');
    }

    /** Queue a verified full backup with explicit administrator approval. */
    public function backup(Request $request, array $parameters): JsonResponse
    {
        return $this->requestOperation($request, 'backup');
    }

    /** @param array<string, mixed> $parameters */
    public function apply(Request $request, array $parameters): JsonResponse
    {
        return $this->requestOperation($request, 'apply');
    }

    /** @param array<string, mixed> $parameters */
    public function rollback(Request $request, array $parameters): JsonResponse
    {
        return $this->requestOperation($request, 'rollback');
    }

    /** @param array<string, mixed> $parameters */
    public function maintenance(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->manager()->maintenanceState()]);
    }

    /** @param array<string, mixed> $parameters */
    public function maintenanceOn(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        return new JsonResponse(['data' => $this->manager()->enableMaintenance((string) ($payload['message'] ?? 'Обслуживание сайта.'))], 201);
    }

    /** @param array<string, mixed> $parameters */
    public function maintenanceOff(Request $request, array $parameters): JsonResponse
    {
        try { return new JsonResponse(['data' => $this->manager()->disableMaintenance()]); } catch (\RuntimeException $e) { return new JsonResponse(['error'=>['code'=>'recovery_required','message'=>$e->getMessage()]],409); }
    }

    private function requestOperation(Request $request, string $action): JsonResponse
    {
        if (!$this->config->bool('updates.web_upgrade.enabled')) return new JsonResponse(['error'=>['code'=>'web_upgrade_disabled','message'=>'Enable UPDATE_WEB_UPGRADE_ENABLED or use CLI.']],403);
        if (!$this->config->bool('updates.operator.enabled')) {
            return new JsonResponse(['error'=>['code'=>'operator_disabled','message'=>'Enable the dedicated UpdateOperator before queueing an update.']],409);
        }
        $identity=$request->attributes->get('auth');
        try { $result=$this->manager()->request($action,$this->json($request),(string)($identity['user_id']??'api-token')); return new JsonResponse(['data'=>$result],202); }
        catch (\RuntimeException $e) { return new JsonResponse(['error'=>['code'=>'update_request_rejected','message'=>$e->getMessage()]],422); }
    }

    private function manager(): UpdateManager
    {
        return new UpdateManager($this->config->rootPath(), $this->config);
    }

    /** @return array<string, mixed> */
    private function json(Request $request): array
    {
        $content = trim($request->getContent());
        if ($content === '') { return []; }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }
}
