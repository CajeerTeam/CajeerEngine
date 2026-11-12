<?php
// Run from the trusted operator checkout, never from the candidate archive.
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    if (str_starts_with($class,'CajeerEngine\\')) {
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if (is_file($file)) require $file;
    }
});
try {
    if (!in_array($argc,[5,6],true) || ($argc===6 && $argv[5]!=='--installed') || !preg_match('/^\d+\.\d+\.\d+$/D',$argv[4])) throw new RuntimeException('Usage: verify.php ZIP PUBLIC_KEY SHA256 VERSION');
    $signature=\CajeerEngine\Update\ReleaseSignature::verify($argv[1],$argv[1].'.sig',$argv[2],$argv[3]);
    $manifest=(new \CajeerEngine\Release\ReleaseArtifactVerifier())->verify($argv[1],'dist',$argv[4]);
    if ($argc===6) {
        $installed=getcwd();
        foreach ($manifest['checksums'] as $path=>$digest) {
            if (in_array($path,\CajeerEngine\Release\ReleaseFilePolicy::KEEP,true)) continue;
            $file=\CajeerEngine\Update\OperationFiles::path($installed,$path);
            if (!hash_equals($digest,\CajeerEngine\Update\OperationFiles::hash($file))) throw new RuntimeException('Installed release differs: '.$path);
        }
    }
    echo json_encode(['version'=>$manifest['version'],'sha256'=>$signature['sha256']],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR,"Artifact verification failed: ".$e->getMessage()."\n"); exit(1); }
