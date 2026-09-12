<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Rc\ReleaseCandidateAuditor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class ReleaseCandidateController
{
    public function __construct(private ReleaseCandidateAuditor $auditor)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function readiness(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->auditor->summaryOnly()]);
    }

    /** @param array<string, mixed> $parameters */
    public function checks(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->auditor->run()]);
    }

    /** @param array<string, mixed> $parameters */
    public function run(Request $request, array $parameters): JsonResponse
    {
        $result = $this->auditor->run();
        $status = $result['ready'] === true ? 200 : 422;
        return new JsonResponse(['data' => $result], $status);
    }
}
