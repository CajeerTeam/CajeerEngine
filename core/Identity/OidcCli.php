<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Integration\SecretVault;

final class OidcCli
{
    /** CLI entry point for both vendor-enabled Symfony and vendor-free NativeCli. */
    public static function run(string $root, array $argv): int
    {
        \CajeerEngine\Console\OperationsCli::loadEnv($root);
        try {
            $userId = $argv[2] ?? null; $subject = $argv[3] ?? null;
            if (!is_string($userId) || $userId === '' || !is_string($subject) || $subject === '') {
                throw new \RuntimeException('Usage: identity:link-user USER_UUID VERIFIED_OIDC_SUB --yes');
            }
            $result = self::link($root, $userId, $subject, in_array('--yes', $argv, true));
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, '[FAIL] Identity mapping was not saved: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /** Operator-only mapping; never infer a local account from a matching email. */
    public static function link(string $root, string $userId, string $subject, bool $confirmed): array
    {
        if (!$confirmed) throw new \RuntimeException('Operator approval required: --yes.');
        $config = new ConfigRepository($root);
        $db = new DatabaseManager($config);
        $users = new UserRepository($db, new RoleRepository($db), new PasswordHasher($config));
        $vault = new SecretVault((string)(getenv('SKIF4ER_ID_TRANSACTION_KEY') ?: getenv('INTEGRATIONS_KEY') ?: getenv('APP_KEY') ?: ''));
        $store = new OidcStore($db, $vault);
        $db->transaction(static function () use ($store, $userId, $subject, $users, $db): void {
            $store->link('https://id.skif4er.ru/realms/skif4er', $subject, $userId, $users);
            (new AuditLogRepository($db))->record('identity.oidc_operator_link', $userId, ['provider'=>'skif4er', 'subject_digest'=>hash('sha256', $subject)]);
        });
        return ['ok'=>true, 'user_id'=>$userId, 'provider'=>'skif4er', 'subject_sha256'=>hash('sha256',$subject)];
    }
}
