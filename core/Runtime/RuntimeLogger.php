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
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        // Never follow a user-replaced log or lock symlink. Logs contain
        // request correlation metadata and must be owner-private.
        if (is_link($directory) || is_link($path) || is_link($path.'.lock')) return;

        $entry = [
            'level' => $level,
            'message' => self::safeMessage($message),
            'context' => self::safeContext($context),
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        try {
            $line=json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR).PHP_EOL;
            $previousMask = umask(0077);
            try { $lock = @fopen($path.'.lock', 'c+'); }
            finally { umask($previousMask); }
            if (!$lock) return;
            try {
                if (!flock($lock, LOCK_EX)) return;
                if (!self::openedRegularFile($lock, $path.'.lock') || !@chmod($path.'.lock', 0600)) return;
                clearstatcache(true, $path);
                if (is_link($path)) return;
                if (is_file($path) && filesize($path) + strlen($line) > max(65536,(int)(getenv('OBSERVABILITY_LOG_MAX_BYTES') ?: 10485760))) {
                    for ($i=4; $i>=1; $i--) if (is_file($path.'.'.$i) && !is_link($path.'.'.$i)) rename($path.'.'.$i, $path.'.'.($i+1));
                    if (is_file($path) && !is_link($path)) {
                        rename($path, $path.'.1');
                        @chmod($path.'.1',0600);
                    }
                }
                $previousMask = umask(0077);
                try { $handle = @fopen($path, 'ab'); }
                finally { umask($previousMask); }
                if (!$handle) return;
                try {
                    // Opening alone does not establish trust: another process may
                    // have swapped the name for a symlink before fopen(). Verify
                    // the opened inode before changing permissions or writing.
                    if (!self::openedRegularFile($handle, $path) || !@chmod($path,0600)) return;
                    if (fwrite($handle,$line) !== strlen($line)) return;
                    fflush($handle);
                } finally { fclose($handle); }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }catch(\Throwable){error_log('CajeerEngine: runtime log write failed');}
    }
    /** An open descriptor must still point to the named regular file, never a symlink. */
    private static function openedRegularFile($handle, string $path): bool
    {
        clearstatcache(true, $path);
        $fd = fstat($handle);
        $named = @lstat($path);
        return is_array($fd) && is_array($named)
            && (($named['mode'] & 0170000) === 0100000)
            && $fd['dev'] === $named['dev'] && $fd['ino'] === $named['ino'];
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
