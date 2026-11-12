<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

/** W3C traceparent correlation; JSON spans are local, not an OTLP exporter. */
final readonly class TraceContext
{
    public function __construct(public string $traceId,public string $spanId,public ?string $parentId,public string $flags) {}
    public static function start(?string $header=null): self
    {
        if(is_string($header)&&preg_match('/^00-([a-f0-9]{32})-([a-f0-9]{16})-([a-f0-9]{2})$/D',$header,$m)&&$m[1]!==str_repeat('0',32)&&$m[2]!==str_repeat('0',16))return new self($m[1],bin2hex(random_bytes(8)),$m[2],$m[3]);
        $trace=new self(bin2hex(random_bytes(16)),bin2hex(random_bytes(8)),null,'00');return TraceExporter::sampled($trace)?new self($trace->traceId,$trace->spanId,null,'01'):$trace;
    }
    public function header(): string{return '00-'.$this->traceId.'-'.$this->spanId.'-'.$this->flags;}
    public function fields(): array{return ['trace_id'=>$this->traceId,'span_id'=>$this->spanId,'parent_span_id'=>$this->parentId];}
}
