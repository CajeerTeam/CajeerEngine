<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

use CajeerEngine\Runtime\ConfigRepository;

/** Runs without Symfony so liveness remains meaningful when boot or DB fails. */
final class ProbeEndpoint
{
    public static function response(string $root,string $path,string $method,?string $authorization): array
    {
        $json=['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store'];
        if(!in_array($method,['GET','HEAD'],true))return [405,$json+['Allow'=>'GET, HEAD'],json_encode(['status'=>'method_not_allowed'])];
        if($path==='/health/live')return [200,$json,'{"status":"alive"}'];
        try{
            $config=new ConfigRepository($root);$monitor=new OperationsMonitor($root,$config);
            if($path==='/metrics'){
                $token=$config->string('observability.metrics_token');
                if(strlen($token)<32)return [404,$json,'{"status":"disabled"}'];
                if(!is_string($authorization)||!hash_equals('Bearer '.$token,$authorization))return [401,$json+['WWW-Authenticate'=>'Bearer'],'{"status":"unauthenticated"}'];
                return [200,['Content-Type'=>'text/plain; version=0.0.4; charset=utf-8','Cache-Control'=>'no-store'],$monitor->prometheus()];
            }
            $result=$monitor->readiness();return [$result['ok']?200:503,$json,json_encode(['status'=>$result['status']])];
        }catch(\Throwable){return [503,$json,'{"status":"unavailable"}'];}
    }
}
