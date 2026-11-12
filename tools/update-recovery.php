<?php
// Copied before replacement; authenticate the recovery snapshot before executing any of its code.
declare(strict_types=1);
try {
    $metadata=json_decode((string)file_get_contents(__DIR__.'/recovery.json'),true,512,JSON_THROW_ON_ERROR);
    $root=realpath($metadata['root']); $backup=realpath($metadata['backup']);
    if ($root===false||$backup===false||!str_starts_with($backup,$root.'/storage/app/backups/production/')||!str_starts_with(__DIR__,$root.'/storage/app/updates/transactions/')) throw new RuntimeException('Invalid recovery metadata');
    $keyFile=getenv('BACKUP_KEY_FILE');
    if (!$keyFile||!str_starts_with($keyFile,'/')||!is_file($keyFile)||is_link($keyFile)||(fileperms($keyFile)&0077)!==0||str_starts_with(realpath($keyFile)?:$keyFile,$root.'/')) throw new RuntimeException('Export BACKUP_KEY_FILE with the original private key before emergency recovery');
    $key=base64_decode(trim((string)file_get_contents($keyFile)),true);
    if (!is_string($key)||strlen($key)!==32) throw new RuntimeException('Invalid backup key');
    foreach (['manifest.json','manifest.mac'] as $f) if (!is_file($backup.'/'.$f)||is_link($backup.'/'.$f)) throw new RuntimeException('Backup manifest missing');
    $raw=(string)file_get_contents($backup.'/manifest.json');
    $mac=hash_hmac('sha256',$raw,$key); sodium_memzero($key);
    if (!hash_equals($mac,trim((string)file_get_contents($backup.'/manifest.mac')))) throw new RuntimeException('Recovery manifest authentication failed');
    $manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (($manifest['format']??null)!=='cajeerengine.backup.v2'||($manifest['id']??null)!==basename($backup)||is_file($backup.'/incomplete.json')) throw new RuntimeException('Invalid recovery snapshot');
    $snapshot=$backup.'/files';
    foreach ($manifest['files'] as $relative=>$info) {
        if (!is_string($relative)||$relative===''||str_starts_with($relative,'/')||preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|[\\\\:\x00-\x1f\x7f]|//~',$relative)) throw new RuntimeException('Unsafe recovery path');
        $file=$snapshot.'/'.$relative;
        if (!is_file($file)||realpath($file)!==$file||!hash_equals($info['sha256'],hash_file('sha256',$file))) throw new RuntimeException('Recovery snapshot checksum mismatch: '.$relative);
    }
    // Only files authenticated by the original backup may be loaded, not additional PHP code.
    spl_autoload_register(static function(string $class)use($snapshot,$manifest):void {
        $prefix='CajeerEngine\\'; if (!str_starts_with($class,$prefix)) return;
        $file='core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (!isset($manifest['files'][$file])) throw new RuntimeException('Unlisted recovery class');
        require $snapshot.'/'.$file;
    });
    foreach (glob($snapshot.'/config/*.php')?:[] as $file) if (!isset($manifest['files']['config/'.basename($file)])) throw new RuntimeException('Unlisted recovery configuration');
    chdir($root);
    CajeerEngine\Console\OperationsCli::loadEnv($root);
    $config=new CajeerEngine\Runtime\ConfigRepository($snapshot);
    $result=(new CajeerEngine\Update\UpdateManager($root,$config))->rollback(null,false);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit(($result['ok']??false)?0:1);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
