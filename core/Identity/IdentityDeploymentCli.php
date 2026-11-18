<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationLock;

/** Production CLI: no HTTP entrypoint, no Keycloak admin privileges and no secrets in output. */
final class IdentityDeploymentCli
{
    public const COMMANDS = ['identity:acceptance','identity:sessions:status','identity:sessions:migrate'];

    public static function run(string $root, array $argv): int
    {
        $lock = null;
        try {
            $command=$argv[1]??'';
            if (!in_array($command,self::COMMANDS,true)) throw new \InvalidArgumentException('Unknown Identity command.');
            $allowed = match($command) {
                'identity:acceptance'=>['json','require-oidc','probe-idp','probe-site'],
                'identity:sessions:status'=>['json'],
                default=>['json','yes','batch-size','max-batches'],
            };
            $options=[];
            foreach (array_slice($argv,2) as $arg) {
                if (!preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/D',$arg,$parts)
                    || !in_array($parts[1],$allowed,true) || isset($options[$parts[1]])) {
                    throw new \InvalidArgumentException('Unknown or repeated Identity option.');
                }
                if (isset($parts[2]) && in_array($parts[1],['json','yes','require-oidc','probe-idp','probe-site'],true)) {
                    throw new \InvalidArgumentException('Flags must not take values.');
                }
                if (!isset($parts[2]) && in_array($parts[1],['batch-size','max-batches'],true)) {
                    throw new \InvalidArgumentException('Numeric option requires a value.');
                }
                $options[$parts[1]]=$parts[2]??true;
            }
            OperationsCli::loadEnv($root);
            $config = new ConfigRepository($root);
            $database = new DatabaseManager($config);
            $migrate = $command==='identity:sessions:migrate';
            if ($migrate && !isset($options['yes'])) throw new \RuntimeException('Migration changes live browser credentials; require --yes after a verified backup.');
            if ($migrate && OperationLock::maintenance($root)) throw new \RuntimeException('Deployment/maintenance already active. Run session migration only outside these operations.');
            $lock=OperationLock::acquire($root,$migrate);
            $service = new IdentitySessionMigration($database,max(5,$config->int('security.sessions.idle_minutes',720)));
            $result = match($command) {
                'identity:acceptance'=>(new IdentityAcceptance($config,$database))->run(isset($options['require-oidc']),isset($options['probe-idp']),isset($options['probe-site'])),
                'identity:sessions:status'=>['ok'=>true,'sessions'=>$service->status()],
                default=>$service->migrate(self::integer($options,'batch-size',100,1,500),self::integer($options,'max-batches',10,1,200)),
            };
            echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
            return ($result['ok']??false)?0:1;
        } catch (\Throwable $e) {
            fwrite(STDERR,'[FAIL] Identity deployment: '.$e->getMessage().PHP_EOL);
            return 1;
        } finally {
            if ($lock!==null) OperationLock::release($lock);
        }
    }

    private static function integer(array $options,string $key,int $fallback,int $min,int $max): int
    {
        if (!isset($options[$key])) return $fallback;
        $value=$options[$key];
        if (!is_string($value)||!ctype_digit($value)||strlen($value)>6||(int)$value<$min||(int)$value>$max) {
            throw new \InvalidArgumentException('Invalid --'.$key.'; allowed '.$min.'..'.$max);
        }
        return (int)$value;
    }
}
