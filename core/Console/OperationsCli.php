<?php

declare(strict_types=1);
namespace CajeerEngine\Console;

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Update\ReleaseSignature;
use CajeerEngine\Update\UpdateManager;

/** Operational CLI remains available without Composer; never execute through HTTP. */
final class OperationsCli
{
    public const COMMANDS=['backup:create','stable:backup','backup:list','backup:verify','backup:restore','backup:keygen','release:keygen','release:sign','update:verify-signature','update:apply','update:rollback','update:stage','update:requests','update:run','update:status','maintenance:on','maintenance:off','maintenance:status','import:execute','import:requests'];
    public static function handles(array $argv): bool { return in_array($argv[1]??'',self::COMMANDS,true)||(($argv[1]??'')==='update'&&in_array('--apply',$argv,true)); }
    public static function run(string $root,array $argv): int
    {
        try {
            $c=$argv[1]??''; $o=self::options($argv);
            $required=static fn($name)=>isset($o[$name])&&is_string($o[$name])&&$o[$name]!==''?$o[$name]:throw new \RuntimeException('Provide --'.$name.'=...');
            if ($c==='release:keygen') $result=ReleaseSignature::generate(self::externalKey($root,$required('secret-key')),self::externalKey($root,$required('public-key')));
            elseif ($c==='release:sign') {
                $package=realpath($required('package'))?:throw new \RuntimeException('Package not found');
                $zip=new \PharData($package); $manifest=json_decode($zip['cajeerengine/release-manifest.json']->getContent(),true,512,JSON_THROW_ON_ERROR);
                if (!in_array($manifest['mode']??null,['source','dist'],true)) throw new \RuntimeException('Unknown artifact mode');
                (new \CajeerEngine\Release\ReleaseArtifactVerifier())->verify($package,$manifest['mode'],$manifest['version']);
                $result=ReleaseSignature::sign($package,self::externalKey($root,$required('secret-key')));
            }
            elseif ($c==='backup:keygen') {
                $path=self::externalKey($root,$required('key')); if (!str_starts_with($path,'/')||file_exists($path)||is_link($path)) throw new \RuntimeException('Provide a new absolute key path');
                OperationFiles::write($path,base64_encode(random_bytes(32))."\n"); $result=['ok'=>true,'key_file'=>$path];
            } else {
                self::loadEnv($root); $config=new ConfigRepository($root); $m=new UpdateManager($root,$config);
                $result=match($c) {
                    'import:execute'=>(new \CajeerEngine\ImportExport\ImportExportService($root))->executeRequest($required('id')),
                    'import:requests'=>['ok'=>true,'requests'=>(new \CajeerEngine\ImportExport\ImportExportService($root))->requests()],
                    'backup:create','stable:backup'=> (new BackupManager($root,$config))->create(),
                    'backup:list'=>(new BackupManager($root,$config))->list(),
                    'backup:verify'=>(new BackupManager($root,$config))->verify($required('id')),
                    'backup:restore'=>isset($o['yes'])?(new BackupManager($root,$config))->restore($required('id')):throw new \RuntimeException('Restore replaces database and files; pass --yes'),
                    'update:verify-signature'=>$m->verifyPackage($required('package'),$o['sha256']??null,$o['signature']??null),
                    'update:apply','update'=>$m->apply(array_combine(array_map(static fn($k)=>str_replace('-','_',$k),array_keys($o)),array_values($o))),
                    'update:rollback'=>$m->rollback($o['backup']??null,isset($o['yes'])),
                    'update:stage'=>$m->publish($required('package'),$o['signature']??null),
                    'update:requests'=>['ok'=>true,'requests'=>$m->pending()],
                    'update:run'=>$m->runRequest($required('id')),
                    'update:status'=>$m->wizardState(),
                    'maintenance:status'=>$m->maintenanceState()+['ok'=>true],
                    'maintenance:on','maintenance:off'=>self::maintenance($root,$m,$c,$o),
                    default=>throw new \RuntimeException('Unknown operation'),
                };
            }
            echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
            return ($result['ok']??false)?0:1;
        } catch (\Throwable $e) { fwrite(STDERR,'[FAIL] '.$e->getMessage()."\n"); return 1; }
    }
    private static function externalKey(string $root,string $path): string
    {
        if (!str_starts_with($path,'/')||is_link($path)||preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~',$path)) throw new \RuntimeException('Use an absolute external key path');
        $dir=realpath(dirname($path));
        if ($dir===false) throw new \RuntimeException('Create the protected key directory first');
        $p=$dir.'/'.basename($path); $base=realpath($root)?:$root;
        if (str_starts_with($p,$base.'/')) throw new \RuntimeException('Keep operational keys outside the project');
        return $p;
    }
    private static function maintenance(string $root,UpdateManager $m,string $c,array $o): array
    {
        $lock=OperationLock::acquire($root,true);
        try { return $c==='maintenance:on'?$m->enableMaintenance($o['message']??'Maintenance'):$m->disableMaintenance(); }
        finally { OperationLock::release($lock); }
    }
    private static function options(array $argv): array
    {
        $result=[]; for($i=2;$i<count($argv);$i++) {
            $arg=$argv[$i]; if (!str_starts_with($arg,'--')) throw new \RuntimeException('Use --option=value');
            $parts=explode('=',substr($arg,2),2); $k=$parts[0];
            if (isset($result[$k])) throw new \RuntimeException('Duplicate option');
            $result[$k]=$parts[1]??true;
        }
        return $result;
    }
    public static function loadEnv(string $root): void
    {
        $path=$root.'/.env'; if (!is_file($path)) return;
        // Operations are available without vendor; support the installer's quoted dotenv format.
        if (class_exists(\Symfony\Component\Dotenv\Dotenv::class)) { (new \Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv($path); return; }
        foreach (file($path,FILE_IGNORE_NEW_LINES)?:[] as $line) {
            if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/D',$line,$m)||getenv($m[1])!==false) continue;
            $v=trim($m[2]);
            if ($v!==''&&($v[0]==='"'||$v[0]==="'")) { $q=$v[0]; if (substr($v,-1)!==$q) throw new \RuntimeException('Complex dotenv requires Composer'); $v=substr($v,1,-1); if ($q==='"') $v=strtr($v,['\\"'=>'"','\\\\'=>'\\','\\n'=>"\n",'\\r'=>"\r"]); }
            else $v=preg_replace('/\s+#.*$/','',$v);
            putenv($m[1].'='.$v); $_ENV[$m[1]]=$v; $_SERVER[$m[1]]=$v;
        }
    }
}
