<?php

declare(strict_types=1);
namespace CajeerEngine\Database;

use PDO;

/** Serialize schema changes on the database server, or beside the SQLite database. */
final class MigrationLock
{
    public static function run(PDO $pdo, callable $work): mixed
    {
        if($pdo->inTransaction())throw new \LogicException('Migrations require a dedicated connection outside a transaction');
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $deadline=hrtime(true)+30_000_000_000;
        $file=null;$key=null;
        if($driver==='pgsql'){
            do {
                $locked=$pdo->query("SELECT CASE WHEN pg_try_advisory_lock(hashtext(current_database()), 1128352325) THEN 1 ELSE 0 END")->fetchColumn();
                if((int)$locked===1)break;
                usleep(100000);
            }while(hrtime(true)<$deadline);
            if((int)$locked!==1)throw new \RuntimeException('Database migration lock timeout');
        }elseif($driver==='mysql'){
            $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            $key='ce-migrate-'.substr(hash('sha256',$db),0,48);
            $s=$pdo->prepare('SELECT GET_LOCK(:key, 30)');$s->execute(['key'=>$key]);
            if((int)$s->fetchColumn()!==1)throw new \RuntimeException('Database migration lock timeout');
        }elseif($driver==='sqlite'){
            $rows=$pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $row)if($row['name']==='main'&&$row['file']!==''){
                $path=$row['file'].'.migration.lock';
                if(is_link($path))throw new \RuntimeException('Unsafe migration lock path');
                $file=fopen($path,'c+b');if($file===false)throw new \RuntimeException('Cannot open migration lock');
                do{if(flock($file,LOCK_EX|LOCK_NB))break;usleep(100000);}while(hrtime(true)<$deadline);
                if(!flock($file,LOCK_EX|LOCK_NB)){fclose($file);throw new \RuntimeException('Database migration lock timeout');}
                break;
            }
        }else throw new \RuntimeException('Unsupported migration driver');
        try{return $work();}finally{
            if($driver==='pgsql')$pdo->query('SELECT pg_advisory_unlock(hashtext(current_database()), 1128352325)')->fetchColumn();
            elseif($driver==='mysql'){$s=$pdo->prepare('SELECT RELEASE_LOCK(:key)');$s->execute(['key'=>$key]);$s->fetchColumn();}
            elseif(is_resource($file)){flock($file,LOCK_UN);fclose($file);}
        }
    }
}
