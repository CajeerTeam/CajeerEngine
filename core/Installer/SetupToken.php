<?php

declare(strict_types=1);
namespace CajeerEngine\Installer;

final class SetupToken
{
    public static function issue(string $root): string
    {
        if (is_file($root . '/storage/app/installed.lock')) { throw new \RuntimeException('Already installed'); }
        $dir = $root . '/storage/app';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) { throw new \RuntimeException('Cannot create setup directory'); }
        $token = bin2hex(random_bytes(32));
        $tmp = tempnam($dir, '.setup-');
        if ($tmp === false) { throw new \RuntimeException('Cannot create setup token'); }
        try {
            chmod($tmp, 0600);
            if (file_put_contents($tmp, json_encode(['hash' => hash('sha256', $token), 'expires' => time() + 1800], JSON_THROW_ON_ERROR)) === false || !rename($tmp, self::path($root))) { throw new \RuntimeException('Cannot save setup token'); }
        } finally { if (is_file($tmp)) { unlink($tmp); } }
        return $token;
    }

    public static function verify(string $root, string $token): bool
    {
        $path = self::path($root);
        if (is_file($root . '/storage/app/installed.lock') || !is_file($path) || !preg_match('/^[a-f0-9]{64}$/D', $token)) { return false; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) && ($data['expires'] ?? 0) >= time() && is_string($data['hash'] ?? null) && hash_equals($data['hash'], hash('sha256', $token));
    }

    public static function consume(string $root): void { if (is_file(self::path($root))) { unlink(self::path($root)); } }
    private static function path(string $root): string { return $root . '/storage/app/setup-token.json'; }
}
