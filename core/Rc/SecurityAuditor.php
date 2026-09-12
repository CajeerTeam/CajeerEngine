<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

final readonly class SecurityAuditor
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function audit(): array
    {
        $checks = [];
        $security = $this->loadConfig('security');
        $requiredScopes = ['content:read', 'content:write', 'system:read', 'users:read', 'users:write', 'extensions:read', 'extensions:write'];
        $scopes = $security['scopes'] ?? [];
        foreach ($requiredScopes as $scope) {
            $checks[] = in_array($scope, $scopes, true)
                ? CheckResult::pass('security', 'scope.' . $scope, 'Scope объявлен: ' . $scope)
                : CheckResult::fail('security', 'scope.' . $scope, 'Scope отсутствует: ' . $scope);
        }

        $checks[] = ($security['protect_content_writes'] ?? null) === true
            ? CheckResult::pass('security', 'content_writes_protected', 'Запись контента защищена.')
            : CheckResult::fail('security', 'content_writes_protected', 'Запись контента должна быть защищена.');

        $rateLimits = $security['rate_limits'] ?? [];
        $checks[] = is_array($rateLimits) && array_key_exists('auth', $rateLimits)
            ? CheckResult::pass('security', 'rate_limit.auth', 'Rate limit для auth настроен.')
            : CheckResult::fail('security', 'rate_limit.auth', 'Rate limit для auth не настроен.');

        $envExample = (string) @file_get_contents($this->rootPath . '/.env.example');
        foreach (['APP_KEY=', 'DB_DRIVER=', 'SECURITY_REQUIRE_AUTH='] as $needle) {
            $checks[] = str_contains($envExample, $needle)
                ? CheckResult::pass('security', 'env.' . trim($needle, '='), '.env.example содержит ' . $needle)
                : CheckResult::fail('security', 'env.' . trim($needle, '='), '.env.example не содержит ' . $needle);
        }

        foreach (['core/Http/Middleware/AuthenticationMiddleware.php', 'core/Http/Middleware/RateLimitMiddleware.php', 'core/Http/Middleware/SecurityHeadersMiddleware.php'] as $file) {
            $checks[] = is_file($this->rootPath . '/' . $file)
                ? CheckResult::pass('security', 'middleware.' . basename($file, '.php'), 'Middleware присутствует: ' . $file)
                : CheckResult::fail('security', 'middleware.' . basename($file, '.php'), 'Middleware отсутствует: ' . $file);
        }

        return $checks;
    }

    /** @return array<string, mixed> */
    private function loadConfig(string $name): array
    {
        $path = $this->rootPath . '/config/' . $name . '.php';
        if (!is_file($path)) {
            return [];
        }
        $value = require $path;
        return is_array($value) ? $value : [];
    }
}
