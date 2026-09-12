<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Update;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateResolver;
use PHPUnit\Framework\TestCase;

final class UpdateResolverTest extends TestCase
{
    public function testBundledMetadataIsAvailable(): void
    {
        $root = dirname(__DIR__, 2);
        $config = new ConfigRepository($root);
        $diag = (new UpdateResolver($config->get('updates', []), $root))->diagnostics('1.0.4');
        self::assertTrue($diag['metadata_ready']);
        self::assertGreaterThanOrEqual(1, $diag['release_count']);
    }
}
