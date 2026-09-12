<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Release;

use CajeerEngine\Release\ReleaseBuilder;
use PHPUnit\Framework\TestCase;

final class ReleaseBuilderTest extends TestCase
{
    public function testDistPlanReportsRequirements(): void
    {
        $plan = (new ReleaseBuilder(dirname(__DIR__, 2)))->plan(true);
        self::assertSame('dist', $plan['mode']);
        self::assertArrayHasKey('dist_requirements', $plan);
        self::assertArrayHasKey('admin_prebuilt_assets', $plan['dist_requirements']);
    }
}
