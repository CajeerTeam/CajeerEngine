<?php

declare(strict_types=1);

namespace CajeerEngine\Server;

final readonly class NginxConfigGenerator
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function generate(array $options = []): array
    {
        $domain = $this->sanitizeDomain((string) ($options['domain'] ?? 'example.ru'));
        $preset = strtolower((string) ($options['preset'] ?? 'default'));
        $root = rtrim((string) ($options['root'] ?? $this->rootPath . '/public'), '/');
        $phpSocket = (string) ($options['php_socket'] ?? $this->defaultPhpSocket($preset));
        $clientMaxBodySize = (string) ($options['client_max_body_size'] ?? '64m');
        $enableHttpsComment = (bool) ($options['https_comment'] ?? true);
        $config = $this->render($domain, $root, $phpSocket, $clientMaxBodySize, $enableHttpsComment);
        $suggestedPath = $preset === 'aapanel'
            ? '/www/server/panel/vhost/nginx/' . $domain . '.conf'
            : '/etc/nginx/sites-available/' . $domain . '.conf';

        return [
            'ok' => true,
            'preset' => $preset,
            'domain' => $domain,
            'root' => $root,
            'php_socket' => $phpSocket,
            'suggested_path' => $suggestedPath,
            'config' => $config,
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function write(array $options = []): array
    {
        $generated = $this->generate($options);
        $path = (string) ($options['output'] ?? $generated['suggested_path']);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        if ($path === '') {
            throw new \InvalidArgumentException('Путь для Nginx config пустой.');
        }
        if (!$dryRun) {
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('Не удалось создать директорию: ' . dirname($path));
            }
            file_put_contents($path, (string) $generated['config'], LOCK_EX);
        }
        $generated['written'] = !$dryRun;
        $generated['output'] = $path;
        $generated['dry_run'] = $dryRun;
        return $generated;
    }

    private function render(string $domain, string $root, string $phpSocket, string $clientMaxBodySize, bool $enableHttpsComment): string
    {
        $fastcgiPass = str_starts_with($phpSocket, 'unix:') || str_contains($phpSocket, ':') ? $phpSocket : 'unix:' . $phpSocket;
        $httpsComment = $enableHttpsComment ? "\n    # SSL рекомендуется включить через certbot или aaPanel после проверки HTTP-конфига.\n" : "\n";
        return <<<NGINX
server {
    listen 80;
    server_name {$domain};
    root {$root};
    index index.php index.html;
    charset utf-8;
    client_max_body_size {$clientMaxBodySize};{$httpsComment}
    access_log /var/log/nginx/{$domain}.access.log;
    error_log /var/log/nginx/{$domain}.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /install {
        try_files \$uri \$uri/ /install/index.php?\$query_string;
    }

    location /uploads/ {
        try_files \$uri =404;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass {$fastcgiPass};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$document_root;
        fastcgi_read_timeout 120;
    }

    location ~ /(?:\.env|composer\.(?:json|lock)|package(?:-lock)?\.json|pnpm-lock\.yaml|yarn\.lock|phpunit\.xml|release\.json|VERSION)$ {
        deny all;
    }

    location ~ ^/(?:core|config|migrations|storage|bootstrap|vendor|admin|tests|tools|wiki|packages|plugins|themes)/ {
        deny all;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    private function sanitizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = preg_replace('/^https?:\/\//', '', $domain) ?? $domain;
        $domain = preg_replace('/\/.*$/', '', $domain) ?? $domain;
        if ($domain === '' || !preg_match('/^[a-zA-Z0-9._*-]+$/', $domain)) {
            throw new \InvalidArgumentException('Некорректный домен для Nginx config: ' . $domain);
        }
        return $domain;
    }

    private function defaultPhpSocket(string $preset): string
    {
        if ($preset === 'aapanel') {
            foreach (['/tmp/php-cgi-84.sock', '/tmp/php-cgi-83.sock', '/tmp/php-cgi-82.sock'] as $socket) {
                if (file_exists($socket)) {
                    return 'unix:' . $socket;
                }
            }
            return 'unix:/tmp/php-cgi-84.sock';
        }
        foreach (['/run/php/php8.4-fpm.sock', '/run/php/php8.3-fpm.sock', '/run/php/php-fpm.sock'] as $socket) {
            if (file_exists($socket)) {
                return 'unix:' . $socket;
            }
        }
        return 'unix:/run/php/php8.4-fpm.sock';
    }
}
