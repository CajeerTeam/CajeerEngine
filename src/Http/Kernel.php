<?php

declare(strict_types=1);

namespace Cajeer\Engine\Http;

use Cajeer\Engine\Support\ProjectPaths;

final class Kernel
{
    private function __construct(
        private readonly ProjectPaths $paths
    ) {
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        return new self(ProjectPaths::fromRoot($projectRoot));
    }

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        header('Content-Type: text/html; charset=utf-8');
        header('X-Powered-By: Cajeer Engine');

        if ($path === '/health') {
            http_response_code(200);
            echo json_encode([
                'status' => 'ok',
                'engine' => 'Cajeer Engine',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cajeer Engine</title></head><body>';
        echo '<main style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:880px;margin:48px auto;padding:0 24px;line-height:1.6">';
        echo '<h1>Cajeer Engine</h1>';
        echo '<p>PHP CMS-платформа для замены DLE и WordPress с PostgreSQL/MySQL и compatibility layers.</p>';
        echo '<ul><li>Core CMS</li><li>Native Extension API</li><li>WordPress Compatibility Layer</li><li>DLE Compatibility Layer</li><li>Migration Toolkit</li><li>Marketplace</li></ul>';
        echo '<p><a href="/health">/health</a></p>';
        echo '</main></body></html>';
    }
}
