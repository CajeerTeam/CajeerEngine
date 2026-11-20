<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Security;

use CajeerEngine\Api\Controller\ApiTokenController;
use CajeerEngine\Api\Controller\WebhookController;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Http\Middleware\ErrorHandlerMiddleware;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\RuntimeLogger;
use CajeerEngine\Security\SignedPayload;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SecurityHardeningAcceptanceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ce-sec-182-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iter as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testWebhookSecretIsOneTimeAndReceiverChangeRotatesCredentials(): void
    {
        $repository = new WebhookRepository($this->root);
        $controller = new WebhookController($repository, new WebhookDispatcher(new SignedPayload(), $repository));
        $request = Request::create('/api/v1/webhooks', 'POST');
        $request->attributes->set('json', ['url'=>'https://receiver.example.com/events', 'events'=>['content.published']]);
        $response = $controller->store($request, []);
        $created = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertArrayHasKey('secret', $created);
        // Symfony adds `private` to cache-control for sensitive responses.
        // Require both directives without depending on their serialization order.
        $cacheControl = $response->headers->get('Cache-Control', '');
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $cacheControl);
        self::assertTrue($response->headers->hasCacheControlDirective('private'), $cacheControl);
        $id = $created['id'];
        $list = json_decode($controller->index(Request::create('/api/v1/webhooks'), [])->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('secret', $list['data'][0]);
        $update = Request::create('/api/v1/webhooks/'.$id, 'PATCH');
        $update->attributes->set('json', ['name'=>'New name']);
        $result = json_decode($controller->update($update, ['id'=>$id])->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertArrayNotHasKey('secret', $result);
        self::assertSame($created['secret'], $repository->find($id)['secret']);
        try {
            $repository->update($id, ['url'=>'https://new.example.com/receive']);
            self::fail('Destination changed without rotating signing credentials.');
        } catch (\InvalidArgumentException) { }
        $repository->update($id, ['url'=>'https://new.example.com/receive','secret'=>str_repeat('x',40)]);
        self::assertSame(str_repeat('x',40), $repository->find($id)['secret']);
        self::assertNotSame($created['secret'], $repository->find($id)['secret']);
    }

    public function testWebhookDestinationDoesNotPermitSsrAndCredentials(): void
    {
        foreach (['http://example.com/','https://127.0.0.1/','https://169.254.169.254/','https://localhost/','https://user:pw@example.com/', 'https://example.com:8080/'] as $url) {
            try { WebhookRepository::validateDestination($url); self::fail('Allowed '.$url); }
            catch (\InvalidArgumentException) { }
        }
    }

    public function testRuntimeExceptionDoesNotExposeInternalDetailsInProduction(): void
    {
        $config = new ConfigRepository(dirname(__DIR__,2));
        $config->set('app.env', 'production');
        $middleware = new ErrorHandlerMiddleware($config, new RuntimeLogger($this->root));
        $response = $middleware->process(Request::create('/api/v1/system'), static function (): never {
            throw new \RuntimeException('Unable to reach postgres://admin:secret@host:5432/db');
        });
        self::assertSame(400, $response->getStatusCode());
        self::assertStringNotContainsString('secret', $response->getContent());
        self::assertStringNotContainsString('postgres', $response->getContent());
    }

    public function testTokenRevocationCannotCrossOwnersOrRevokeBrowserSessions(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required');
        $oldDb = getenv('DB_DRIVER'); $oldPath = getenv('SQLITE_DATABASE');
        putenv('DB_DRIVER=sqlite'); putenv('SQLITE_DATABASE='.$this->root.'/tokens.sqlite');
        try {
            $db = new DatabaseManager(new ConfigRepository(dirname(__DIR__,2)));
            $pdo = $db->connection();
            $pdo->exec('CREATE TABLE ce_api_tokens (id TEXT PRIMARY KEY,user_id TEXT,name TEXT,token_hash TEXT,token_prefix TEXT,scopes TEXT,expires_at TEXT,created_at TEXT,last_used_at TEXT,revoked_at TEXT)');
            $tokens = new ApiTokenRepository($db);
            $owner = 'owner'; $other = 'other';
            $own = $tokens->create($owner, 'api', ['content:read']);
            $foreign = $tokens->create($other, 'api', ['content:read']);
            $session = $tokens->create($owner, 'admin-session', ['*']);
            $controller = new ApiTokenController($tokens);
            $request = Request::create('/api/v1/api-tokens/'.$foreign['id'], 'DELETE');
            $request->attributes->set('auth', ['user_id'=>$owner, 'scopes'=>['*']]);
            $result = json_decode($controller->revoke($request, ['id'=>$foreign['id']])->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($result['data']['revoked']);
            self::assertNull($tokens->find($foreign['id'])['revoked_at']);
            self::assertFalse($tokens->revokeOwned($session['id'], $owner));
            self::assertTrue($tokens->revokeOwned($own['id'], $owner));
            self::assertFalse($tokens->revokeOwned($own['id'], $owner));
            self::assertNotNull($tokens->find($own['id'])['revoked_at']);
            $bad = Request::create('/api/v1/api-tokens', 'POST');
            $bad->attributes->set('auth',['user_id'=>$owner,'scopes'=>['*']]);
            $bad->attributes->set('json',['scopes'=>[['*']]]);
            self::assertSame(422,$controller->store($bad)->getStatusCode());
            $bad->attributes->set('json',['scopes'=>['content:read'], 'expires_at'=>'2000-01-01T00:00:00Z']);
            self::assertSame(422,$controller->store($bad)->getStatusCode());
        } finally {
            $oldDb === false ? putenv('DB_DRIVER') : putenv('DB_DRIVER='.$oldDb);
            $oldPath === false ? putenv('SQLITE_DATABASE') : putenv('SQLITE_DATABASE='.$oldPath);
        }
    }
}
