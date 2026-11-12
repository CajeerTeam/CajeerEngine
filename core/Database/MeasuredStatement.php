<?php
declare(strict_types=1);
namespace CajeerEngine\Database;

use CajeerEngine\Observability\Metrics;
use PDOStatement;

final class MeasuredStatement extends PDOStatement
{
    protected function __construct(private readonly Metrics $metrics) {}
    public function execute(?array $params=null): bool
    {
        $start=hrtime(true);$ok=false;try{$ok=parent::execute($params);return $ok;}
        finally{$this->metrics->buffered('database','query',$ok?'success':'failure',(hrtime(true)-$start)/1e9);}
    }
}
