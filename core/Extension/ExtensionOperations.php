<?php
declare(strict_types=1);
namespace CajeerEngine\Extension;
use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Runtime\ConfigRepository;
final class ExtensionOperations
{
    public static function run(string $root,array $argv): int {
        try {
            OperationsCli::loadEnv($root); $config=new ConfigRepository($root); $package=$argv[2]??'';
            if ($argv[1]!=='extension:package:recover' && $package==='') throw new \RuntimeException('Package path required');
            $result=match($argv[1]) {
                'extension:package:enable','extension:enable'=>(new PackageManager($root,$config))->activate($package),
                'extension:package:uninstall'=>in_array('--purge-lifecycle',$argv,true)&&!in_array('--yes',$argv,true)?throw new \RuntimeException('Destructive lifecycle requires --yes'):(new PackageManager($root,$config))->uninstall($package,in_array('--purge-lifecycle',$argv,true)),
                'extension:package:recover'=>(new PackageManager($root,$config))->recover(),
                'extension:sign'=>PackageTrust::sign(realpath($package)?:throw new \RuntimeException('Package directory not found'),self::key($argv)),
                'extension:package:install','extension:package:upgrade'=>(new PackageManager($root,$config))->deploy($package,in_array('--enable',$argv,true),$argv[1]==='extension:package:upgrade'),
                default=>throw new \RuntimeException('Unknown extension operation'),
            }; echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL; return 0;
        } catch (\Throwable $e) { fwrite(STDERR,'[FAIL] '.$e->getMessage().PHP_EOL); return 1; }
    }
    private static function key(array $argv): string { foreach($argv as $arg) if(str_starts_with($arg,'--secret-key=')) return substr($arg,13); throw new \RuntimeException('--secret-key required'); }
}
