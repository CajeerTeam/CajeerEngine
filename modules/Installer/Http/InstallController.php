<?php

declare(strict_types=1);

namespace Cajeer\Modules\Installer\Http;

use Cajeer\Config\ConfigRepository;
use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Database\MigrationRunner;
use Cajeer\Http\Exceptions\ValidationException;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Audit\AuditLogger;
use Cajeer\Modules\Auth\Rbac\PermissionRepository;
use Cajeer\Modules\Auth\Security\CsrfTokenManager;
use Cajeer\Modules\Auth\Security\PasswordHasher;
use Cajeer\View\ViewRenderer;
use PDO;
use Throwable;

final class InstallController
{
    public function __construct(private readonly Container $container) {}

    public function index(Request $request): Response
    {
        $basePath = dirname(__DIR__, 3);
        return Response::html($this->container->get(ViewRenderer::class)->render('install.index', [
            'title' => 'Установка Cajeer Engine',
            'installed' => is_file($basePath . '/storage/installed.lock'),
            'checks' => $this->checks($basePath),
            'csrf' => new CsrfTokenManager(),
        ]));
    }

    public function store(Request $request): Response
    {
        $basePath = dirname(__DIR__, 3);
        if (is_file($basePath . '/storage/installed.lock')) {
            return Response::redirect('/admin');
        }

        $driver = (string) $request->input('db_driver', 'mysql');
        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new ValidationException('Неподдерживаемый драйвер БД.', ['db_driver' => 'mysql или pgsql']);
        }
        $this->validateAdminPassword((string) $request->input('admin_password', ''));

        $env = $this->buildEnv($request, $driver);
        $tmpEnv = $basePath . '/.env.installing';
        file_put_contents($tmpEnv, $env);

        $oldEnv = $basePath . '/.env';
        $backup = null;
        if (is_file($oldEnv)) {
            $backup = $basePath . '/.env.backup.' . date('YmdHis');
            copy($oldEnv, $backup);
        }
        rename($tmpEnv, $oldEnv);

        try {
            $config = ConfigRepository::load($basePath . '/configs');
            $db = new DatabaseManager($config);
            $pdo = $db->connection($driver);
            (new MigrationRunner($pdo, $basePath . '/migrations'))->run($driver);
            $adminId = $this->createAdmin($pdo, $request);
            (new PermissionRepository($db))->ensureDefaults($adminId);
            (new AuditLogger($db))->record('installer.completed', $adminId, 'system', 'installer', ['driver' => $driver], $request);
            file_put_contents($basePath . '/storage/installed.lock', date(DATE_ATOM));
        } catch (Throwable $e) {
            @unlink($basePath . '/storage/installed.lock');
            if ($backup && is_file($backup)) {
                copy($backup, $oldEnv);
            }
            throw $e;
        }

        return Response::redirect('/admin/login');
    }

    private function createAdmin(PDO $pdo, Request $request): int
    {
        $email = trim((string) $request->input('admin_email', ''));
        $username = trim((string) $request->input('admin_username', 'admin'));
        $password = (string) $request->input('admin_password', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Некорректный email администратора.', ['admin_email' => 'email required']);
        }
        $stmt = $pdo->prepare('INSERT INTO cajeer_users (email, username, password_hash, display_name, status) VALUES (:email, :username, :password_hash, :display_name, :status)');
        $stmt->execute(['email' => $email, 'username' => $username, 'password_hash' => (new PasswordHasher())->make($password), 'display_name' => $username, 'status' => 'active']);
        return (int) $pdo->lastInsertId();
    }

    private function validateAdminPassword(string $password): void
    {
        $weak = ['admin', 'password', '123456', 'admin123456', 'qwerty', 'change-me'];
        if (strlen($password) < 12 || in_array(strtolower($password), $weak, true)) {
            throw new ValidationException('Пароль администратора слишком слабый.', ['admin_password' => 'Минимум 12 символов, без словарных значений.']);
        }
    }

    private function buildEnv(Request $request, string $driver): string
    {
        $lines = [
            'APP_NAME="' . addslashes((string) $request->input('app_name', 'Cajeer Engine')) . '"',
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=' . (string) $request->input('app_url', 'http://localhost'),
            'APP_KEY=' . bin2hex(random_bytes(32)),
            'DB_DEFAULT=' . $driver,
            'MYSQL_HOST=' . (string) $request->input('db_host', '127.0.0.1'),
            'MYSQL_PORT=' . (string) $request->input('db_port', $driver === 'mysql' ? '3306' : '5432'),
            'MYSQL_DATABASE=' . (string) $request->input('db_name', 'cajeer'),
            'MYSQL_USERNAME=' . (string) $request->input('db_user', 'cajeer'),
            'MYSQL_PASSWORD=' . (string) $request->input('db_password', ''),
            'MYSQL_CHARSET=utf8mb4',
            'PGSQL_HOST=' . (string) $request->input('db_host', '127.0.0.1'),
            'PGSQL_PORT=' . (string) $request->input('db_port', $driver === 'pgsql' ? '5432' : '3306'),
            'PGSQL_DATABASE=' . (string) $request->input('db_name', 'cajeer'),
            'PGSQL_USERNAME=' . (string) $request->input('db_user', 'cajeer'),
            'PGSQL_PASSWORD=' . (string) $request->input('db_password', ''),
            'PGSQL_CHARSET=utf8',
            'COMPAT_DLE_ENABLED=true',
            'COMPAT_WORDPRESS_ENABLED=true',
            'MARKETPLACE_ENABLED=true',
        ];
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function checks(string $basePath): array
    {
        return [
            'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'PDO' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'storage writable' => is_writable($basePath . '/storage'),
            'public/uploads writable' => is_writable($basePath . '/public/uploads'),
            'migrations present' => is_dir($basePath . '/migrations/mysql') && is_dir($basePath . '/migrations/pgsql'),
        ];
    }
}
