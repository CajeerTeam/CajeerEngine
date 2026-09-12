<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Stable;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Stable\StableReleaseService;
use PHPUnit\Framework\TestCase;

final class StableReleaseServiceTest extends TestCase
{
    public function testSmokeTestReturnsStructuredResult(): void
    {
        $root = dirname(__DIR__, 2);
        $service = new StableReleaseService($root, new ConfigRepository($root));
        $result = $service->smokeTest();
        self::assertArrayHasKey('ok', $result);
        self::assertArrayHasKey('checks', $result);
    }
}
