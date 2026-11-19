<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Release;

use CajeerEngine\Api\ApiContract;
use CajeerEngine\Rc\OpenApiLinter;
use CajeerEngine\Rc\PhpSyntaxVerifier;
use CajeerEngine\Rc\VersionVerifier;
use PHPUnit\Framework\TestCase;

final class BaselineAcceptanceTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testAllReleaseVersionsAreSynchronized(): void
    {
        $results = (new VersionVerifier($this->root()))->verify();
        foreach ($results as $result) {
            self::assertSame('pass', $result->status, $result->name . ': ' . $result->message);
        }
    }

    public function testVersionVerifierRejectsStalePublishedManifest(): void
    {
        $root = sys_get_temp_dir() . '/ce-version-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/public/admin/assets', 0700, true));
        try {
            file_put_contents($root . '/VERSION', "1.8.1\n");
            file_put_contents($root . '/public/admin/assets/admin-manifest.json', json_encode(['version' => '1.8.0', 'ready' => true], JSON_THROW_ON_ERROR));
            $results = (new VersionVerifier($root))->verify();
            $byName = [];
            foreach ($results as $check) {
                $byName[$check->name] = $check->status;
            }
            self::assertSame('fail', $byName['admin.manifest']);
        } finally {
            @unlink($root . '/public/admin/assets/admin-manifest.json');
            @unlink($root . '/VERSION');
            @rmdir($root . '/public/admin/assets');
            @rmdir($root . '/public/admin');
            @rmdir($root . '/public');
            @rmdir($root);
        }
    }

    public function testCandidateVersionValidationAcceptsSemVerAndRejectsMalformedPreRelease(): void
    {
        $root = sys_get_temp_dir() . '/ce-semver-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));
        try {
            foreach (['1.9.0-rc.1' => 'pass', '1.9.0-rc..1' => 'fail',
                      '01.9.0' => 'fail', '1.9.0-rc.01' => 'fail',
                      '1.8.9' => 'pass'] as $version => $expected) {
                file_put_contents($root . '/VERSION', $version . PHP_EOL);
                $checks = (new VersionVerifier($root))->verify();
                self::assertSame($expected, $checks[0]->status, $version);
            }
        } finally {
            @unlink($root . '/VERSION');
            @rmdir($root);
        }
    }

    public function testReleaseStabilityMustAgreeWithVersionAndChannel(): void
    {
        $root = sys_get_temp_dir() . '/ce-stability-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700));
        try {
            file_put_contents($root . '/VERSION', "1.9.1\n");
            file_put_contents($root . '/release.json', json_encode(['version'=>'1.9.1','channel'=>'stable','stability'=>'rc'], JSON_THROW_ON_ERROR));
            $results = (new VersionVerifier($root))->verify();
            $byName = [];
            foreach ($results as $check) $byName[$check->name] = $check->status;
            self::assertSame('fail', $byName['release.stability']);
        } finally {
            @unlink($root . '/VERSION'); @unlink($root . '/release.json'); @rmdir($root);
        }
    }

    public function testAutomationRoutesMatchActualRuntimeAndExposeRequestSchemas(): void
    {
        $checks = (new OpenApiLinter($this->root()))->lint();
        self::assertSame('pass', $checks[0]->status, json_encode($checks[0]->context));
        $document = (new ApiContract($this->root()))->document();
        foreach (['/ai/automations' => ['get','post'], '/ai/automations/{id}' => ['get','patch','delete'], '/ai/automations/{id}/run' => ['post'], '/ai/automations/{id}/runs' => ['get']] as $path => $methods) {
            foreach ($methods as $method) {
                $operation = $document['paths'][$path][$method] ?? null;
                self::assertIsArray($operation, $method . ' ' . $path);
                self::assertSame([['BrowserSession' => []] + ($method === 'get' ? [] : ['CsrfToken' => []])], $operation['security']);
                self::assertArrayHasKey('responses', $operation);
            }
        }
        self::assertSame(['title', 'prompt', 'interval_minutes'], $document['paths']['/ai/automations']['post']['requestBody']['content']['application/json']['schema']['required']);
        self::assertSame(['revision'], $document['paths']['/ai/automations/{id}']['patch']['requestBody']['content']['application/json']['schema']['required']);
    }

    public function testPhpSyntaxVerifierRunsRealParserAndDetectsInvalidSyntax(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable; release auditor fails closed in this environment');
        }
        $root = sys_get_temp_dir() . '/ce-baseline-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/core', 0700, true));
        try {
            file_put_contents($root . '/core/Good.php', "<?php\ndeclare(strict_types=1);\nfunction good(): int { return 1; }\n");
            file_put_contents($root . '/core/Broken.php', "<?php\nfunction broken( {\n");
            $checks = (new PhpSyntaxVerifier($root))->verify();
            self::assertCount(2, $checks);
            $statuses = [];
            foreach ($checks as $result) {
                $statuses[$result->name] = $result->status;
            }
            self::assertSame('pass', $statuses['core/Good.php']);
            self::assertSame('fail', $statuses['core/Broken.php']);
        } finally {
            @unlink($root . '/core/Good.php');
            @unlink($root . '/core/Broken.php');
            @rmdir($root . '/core');
            @rmdir($root);
        }
    }
}
