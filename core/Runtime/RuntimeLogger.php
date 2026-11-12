<?php

declare(strict_types=1);

namespace CajeerEngine\Runtime;

final readonly class RuntimeLogger
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function event(RuntimeEvent $event, array $context = []): void
    {
        $this->write('event', $event->name, array_merge($event->payload, $context));
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $path = $this->rootPath . '/storage/logs/runtime.jsonl';
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $entry = [
            'level' => $level,
            'message' => self::safeMessage($message),
            'context' => self::safeContext($context),
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        try {
            $line=json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR).PHP_EOL;
            $lock=fopen($path.'.lock','c+');if(!$lock)return;
            try{
                if(!flock($lock,LOCK_EX))return;
                clearstatcache(true,$path);
                if(is_file($path)&&filesize($path)+strlen($line)>max(65536,(int)(getenv('OBSERVABILITY_LOG_MAX_BYTES')?:10485760))){
                    for($i=4;$i>=1;$i--)if(is_file($path.'.'.$i))rename($path.'.'.$i,$path.'.'.($i+1));
                    rename($path,$path.'.1');
                }
                file_put_contents($path,$line,FILE_APPEND|LOCK_EX);
            }finally{fclose($lock);}
        }catch(\Throwable){error_log('CajeerEngine: runtime log write failed');}
    }
    /** Strict allowlist: never retain arbitrary payloads, SQL, URLs, cookies or exception messages. */
    private static function safeContext(array $context): array
    {
        $out=[];
        foreach(['request_id','trace_id','span_id','parent_span_id','job_id','event_id','method','route','status','duration_ms','version','env','routes','middleware','exception','level','name','actor_id','code'] as $key){
            $v=$context[$key]??null;
            if(is_int($v)||is_float($v)||is_bool($v))$out[$key]=$v;
            elseif(is_string($v)&&strlen($v)<=128&&preg_match('~^[a-zA-Z0-9_.:/\\\\-]+$~D',$v))$out[$key]=$v;
        }
        return $out;
    }
    private static function safeMessage(string $message): string
    {
        // Existing call sites use constant event/message names. Suppress free-form strings that could include credentials.
        return in_array($message,['HTTP request handled','Search catalog unavailable','Database storage unavailable','Unhandled HTTP exception','Background job finished','Scheduled task finished','kernel.booted','kernel.request_handled'],true)?$message:'Runtime event';
    }
}
