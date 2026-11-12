<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Security/RateLimiter.php';
use CajeerEngine\Security\RateLimiter;
function check(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
if (!function_exists('pcntl_fork')) throw new RuntimeException('pcntl is required for concurrency acceptance');
$root=sys_get_temp_dir().'/ce-rate-'.bin2hex(random_bytes(8)); mkdir($root,0700);
try {
    $pids=[]; $start=hrtime(true);
    for($i=0;$i<8;$i++) {
        $pid=pcntl_fork();
        if($pid<0) throw new RuntimeException('fork failed');
        if($pid===0) {
            try {
                $limiter=new RateLimiter($root); $allowed=0;
                for($j=0;$j<64;$j++) if($limiter->hit('auth','shared-client',37,600)['allowed']) $allowed++;
                if(file_put_contents($root.'/worker-'.$i,(string)$allowed)===false) exit(1);
                exit(0);
            } catch(Throwable) { exit(1); }
        }
        $pids[]=$pid;
    }
    $allowed=0; foreach($pids as $i=>$pid) {
        pcntl_waitpid($pid,$status); if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0) throw new RuntimeException('worker failed');
        $allowed+=(int)file_get_contents($root.'/worker-'.$i);
    }
    check($allowed===37,'512 concurrent requests admit exactly 37');
    $file=$root.'/storage/rate-limits/auth-'.hash('sha256','shared-client').'.json';
    $state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    check($state['hits']===37,'rejected requests saturate counter');
    $limiter=new RateLimiter($root); $denied=$limiter->hit('auth','shared-client',37,600);
    check(!$denied['allowed']&&$denied['remaining']===0&&$denied['retry_after']>0,'retry budget');
    check($limiter->hit('global','shared-client',37,600)['allowed']&&$limiter->hit('auth','other-client',37,600)['allowed'],'bucket and client isolation');
    file_put_contents($file,'{"hits":-1,"reset_at":0}');
    check(!$limiter->hit('auth','shared-client',37,600)['allowed'],'corrupt counter fails closed');
    file_put_contents($file,json_encode(['hits'=>37,'reset_at'=>time()-1]));
    check($limiter->hit('auth','shared-client',37,600)['remaining']===36,'expired window recovers');
    printf("512 operations elapsed %.3f ms\n",(hrtime(true)-$start)/1e6);
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) { if($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($root);
}
