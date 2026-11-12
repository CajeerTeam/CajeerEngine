<?php
declare(strict_types=1);
namespace CajeerEngine\Extension\Runtime;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use PDO;

/** Production ledger lives in the same database transaction as migration DML/DDL where supported. */
final class MigrationLedger
{
    private ?PDO $pdo=null;
    private JsonFile $file;
    public function __construct(string $root,ConfigRepository $config,?DatabaseManager $database)
    {
        $this->file=new JsonFile($root.'/storage/app/extensions/migrations.json');
        try { if ($database!==null && $database->tableExists('ce_extension_migrations')) $this->pdo=$database->connection(); }
        catch (\Throwable $e) { if ($config->string('app.env')==='production') throw $e; }
        if ($this->pdo===null && $config->string('app.env')==='production') throw new \RuntimeException('Run core migrations before extension migrations');
    }
    public function applied(string $name): array
    {
        $legacy=$this->file->readObject([])[$name]??[];
        if ($this->pdo===null) return $legacy;
        if ($legacy!==[]) throw new \RuntimeException('Legacy extension migration history requires operator reconciliation before database adoption: '.$name);
        $q=$this->pdo->prepare('SELECT migration_id, checksum FROM ce_extension_migrations WHERE extension_name = :name');$q->execute(['name'=>$name]);return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    public function run(string $name,string $id,string $sha,callable $migration,ExtensionContext $context): void
    {
        if ($this->pdo===null) { $migration($context,null);$state=$this->file->readObject([]);$state[$name][$id]=$sha;$this->file->writeObject($state);return; }
        $this->pdo->beginTransaction();
        try { $migration($context,$this->pdo);$q=$this->pdo->prepare('INSERT INTO ce_extension_migrations (extension_name,migration_id,checksum,applied_at) VALUES (:name,:id,:sha,:at)');$q->execute(['name'=>$name,'id'=>$id,'sha'=>$sha,'at'=>gmdate('Y-m-d H:i:s')]);if($this->pdo->inTransaction())$this->pdo->commit(); }
        catch (\Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e; }
    }
}
