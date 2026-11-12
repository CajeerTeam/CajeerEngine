<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

/** Process-local context, restored in finally across nested operations. */
final class TraceScope
{
    private static ?TraceContext $current=null;
    public static function enter(TraceContext $trace): ?TraceContext{$previous=self::$current;self::$current=$trace;return $previous;}
    public static function leave(?TraceContext $previous): void{self::$current=$previous;}
    public static function child(): TraceContext{return TraceContext::start(self::$current?->header());}
}
