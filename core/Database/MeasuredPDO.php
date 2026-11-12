<?php
declare(strict_types=1);
namespace CajeerEngine\Database;

use CajeerEngine\Observability\Metrics;
use PDO;
use PDOStatement;

/** Measures execution without recording SQL text, parameters or connection credentials. */
final class MeasuredPDO extends PDO
{
    public function __construct(string $dsn,?string $username,?string $password,array $options,private readonly Metrics $metrics)
    {
        parent::__construct($dsn,$username,$password,$options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[MeasuredStatement::class,[$metrics]]);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        $start=hrtime(true);$ok=false;
        try{$result=$fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);$ok=$result!==false;return $result;}
        finally{$this->metrics->buffered('database','query',$ok?'success':'failure',(hrtime(true)-$start)/1e9);}
    }
    public function exec(string $statement): int|false
    {
        $start=hrtime(true);$ok=false;try{$result=parent::exec($statement);$ok=$result!==false;return $result;}
        finally{$this->metrics->buffered('database','query',$ok?'success':'failure',(hrtime(true)-$start)/1e9);}
    }
}
