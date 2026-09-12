<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Kernel;

use CajeerEngine\Kernel\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApplicationTest extends TestCase
{
    public function testHealthEndpointReturnsVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $app = Application::boot($root);
        $response = $app->handle(Request::create('/api/v1/health'));

        self::assertContains($response->getStatusCode(), [200, 503]);
        $expectedVersion = trim((string) file_get_contents($root . '/VERSION'));
        self::assertStringContainsString($expectedVersion, (string) $response->getContent());
    }

    public function testMissingRouteReturns404(): void
    {
        $root = dirname(__DIR__, 2);
        $app = Application::boot($root);
        $response = $app->handle(Request::create('/api/v1/missing'));

        self::assertSame(404, $response->getStatusCode());
    }
}
