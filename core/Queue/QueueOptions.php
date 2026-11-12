<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

use CajeerEngine\Support\Uuid;

/** Shared validation and stable enqueue identity. Keys are scoped to a queue. */
final class QueueOptions
{
    public static function queue(?string $queue): string
    {
        $queue = $queue ?: 'default';
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $queue)) throw new \InvalidArgumentException('Invalid queue name');
        return $queue;
    }
    public static function id(string $queue, string $name, array $payload): string
    {
        if ($name === '' || strlen($name)>200 || preg_match('/[\x00-\x1f\x7f]/',$name)) throw new \InvalidArgumentException('Invalid job name');
        if(strlen(json_encode($payload,JSON_THROW_ON_ERROR))>1048576)throw new \InvalidArgumentException('Job payload exceeds 1 MiB');
        if (!isset($payload['_idempotency_key'])) return Uuid::v4();
        $key=$payload['_idempotency_key'];
        if (!is_string($key)||$key===''||strlen($key)>256) throw new \InvalidArgumentException('Invalid idempotency key');
        $h=hash('sha256', "cajeer-queue\0$queue\0$key");
        return substr($h,0,8).'-'.substr($h,8,4).'-5'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
    }
    public static function validateId(string $id): void { if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$id)) throw new \InvalidArgumentException('Invalid job ID'); }
    public static function available(array $p): int { if (!isset($p['_available_at'])) return time(); $t=is_string($p['_available_at'])?strtotime($p['_available_at']):false; if($t===false)throw new \InvalidArgumentException('Invalid available_at'); return $t; }
    public static function maxAttempts(array $p): int { return max(1,min(100,(int)($p['_max_attempts']??3))); }
    public static function lease(int $seconds): int { return max(1,min(86400,$seconds)); }
    public static function backoff(int $attempt): int { $base=max(1,min(3600,(int)(getenv('QUEUE_RETRY_BACKOFF')?:60)));return min(3600,$base*(2**min(7,max(0,$attempt-1)))); }
    public static function fingerprint(string $name,array $payload): string
    {
        $canonical=function(mixed $v)use(&$canonical): mixed {if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);return array_map($canonical,$v);};
        return hash('sha256',json_encode([$name,$canonical($payload)],JSON_THROW_ON_ERROR));
    }
    public static function error(string $message,int $max=2000): string {return json_decode(json_encode(substr($message,0,$max),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),true,512,JSON_THROW_ON_ERROR);}
    public static function token(): string { return bin2hex(random_bytes(24)); }
}
