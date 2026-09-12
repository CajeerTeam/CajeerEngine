<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Security;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\SecurityHardener;
use PHPUnit\Framework\TestCase;

final class SecurityHardenerTest extends TestCase
{
    public function testHardenerChangesUnsafeEnvValues(): void
    {
        $root = sys_get_temp_dir() . '/ce-security-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0777, true);
        copy(dirname(__DIR__, 2) . '/config/app.php', $root . '/config/app.php');
        copy(dirname(__DIR__, 2) . '/config/security.php', $root . '/config/security.php');
        copy(dirname(__DIR__, 2) . '/.env.example', $root . '/.env.example');
        file_put_contents($root . '/.env', "APP_DEBUG=true\nSECURITY_REQUIRE_AUTH=false\nSECURITY_PROTECT_SYSTEM_ROUTES=false\n");
        $result = (new SecurityHardener($root, new ConfigRepository($root)))->hardenEnv(false);
        self::assertSame(true, $result['ok']);
        $env = file_get_contents($root . '/.env');
        self::assertStringContainsString('SECURITY_REQUIRE_AUTH=true', $env);
        self::assertStringContainsString('SECURITY_PROTECT_SYSTEM_ROUTES=true', $env);
    }
}
