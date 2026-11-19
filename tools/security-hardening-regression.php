#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $name) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (str_starts_with($name, $prefix)) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
});

use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Security\SignedPayload;

function check(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException('FAIL ' . $reason);
    echo 'PASS ' . $reason . "\n";
}
function deny(callable $callback, string $reason): void
{
    try { $callback(); } catch (InvalidArgumentException $e) { check(true, $reason); return; }
    throw new RuntimeException('FAIL ' . $reason);
}

$dir = sys_get_temp_dir() . '/ce-sec-182-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    foreach ([
        'http://example.com/receive', 'https://127.0.0.1/receive', 'https://169.254.169.254/latest',
        'https://[::1]/', 'https://example.com:8443/receive', 'https://name:pass@example.com/receive',
        'https://example.com/receive#fragment', 'file:///etc/passwd', 'https://localhost/receive',
    ] as $url) deny(static fn()=>WebhookRepository::validateDestination($url), 'webhook URL rejected: '.$url);
    check(WebhookRepository::validateDestination('https://receiver.example.com/events') === 'https://receiver.example.com/events', 'safe HTTPS destination accepted');

    $webhooks = new WebhookRepository($dir);
    $created = $webhooks->create(['name'=>'Critical receiver','url'=>'https://receiver.example.com/events','events'=>['content.published']]);
    check(strlen($created['secret']) >= 32 && $webhooks->find($created['id'])['secret'] === $created['secret'], 'new webhook has durable signed secret');
    deny(static fn() => $webhooks->create(['url'=>'https://receiver.example.com/events','events'=>["bad\nheader"]]), 'reject header-control webhook event');
    deny(static fn() => $webhooks->create(['url'=>'https://receiver.example.com/events','events'=>['*'], 'secret'=>'weak']), 'reject weak signing secret');
    $oldSecret=$created['secret'];
    deny(static fn() => $webhooks->update($created['id'], ['url'=>'https://new.example.com/events']), 'reject receiver change without secret rotation');
    check($webhooks->find($created['id'])['url']==='https://receiver.example.com/events', 'rejected receiver change does not mutate registry');
    $changed=$webhooks->update($created['id'], ['url'=>'https://new.example.com/events', 'secret'=>str_repeat('b', 48)]);
    check($changed['url']==='https://new.example.com/events' && $changed['secret']!==$oldSecret,'atomic destination and secret rotation');
    $updated=$webhooks->update($created['id'], ['name'=>'Renamed receiver']);
    check($updated['secret']===str_repeat('b',48), 'metadata update keeps signing secret');

    $failed=(new WebhookDispatcher(new SignedPayload(),$webhooks))->dispatchWebhook([
        'id'=>'test-id','name'=>'failure','url'=>'https://127.0.0.1/invalid', 'secret'=>str_repeat('x',48),
    ],'webhook.test',['hello'=>'world']);
    check($failed['ok'] === false && $failed['error']==='delivery_failed' && $failed['response_excerpt']==='', 'no receiver secrets or transport details in failed delivery record');
    check($webhooks->deliveries()[0]['response_excerpt']==='', 'delivery log persists only redacted result');
    $legacy=(new WebhookDispatcher(new SignedPayload(),$webhooks))->dispatchWebhook([
        'id'=>'legacy','name'=>'legacy','url'=>'http://receiver.example.com/secret?token=hidden','secret'=>str_repeat('x',48),
    ], 'webhook.test', []);
    check($legacy['ok']===false && $legacy['status_code']===0 && $legacy['url']==='http://receiver.example.com', 'legacy HTTP delivery blocked and URL credentials omitted from records');
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($dir);
}
