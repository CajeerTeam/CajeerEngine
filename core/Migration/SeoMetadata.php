<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use PDO;

/** Read-only SEO overlay. Existing editor-entered SEO fields always take precedence. */
final class SeoMetadata
{
    private ?DatabaseManager $db;
    private ?bool $ready=null;
    public function __construct(private string $root,?DatabaseManager $db=null){$this->db=$db;}
    private function db():DatabaseManager{return $this->db??=new DatabaseManager(new ConfigRepository($this->root));}
    private function ready():bool{if($this->ready!==null)return $this->ready;if((string)(getenv('CONTENT_STORAGE')?:'database')==='file')return $this->ready=false;try{return $this->ready=$this->db()->tableExists('ce_migration_seo_meta');}catch(\CajeerEngine\Database\DatabaseUnavailable|\PDOException){return $this->ready=false;}}
    public function get(string $entryId):array
    {
        if(!$this->ready())return [];
        $q=$this->db()->connection()->prepare('SELECT title,description,canonical_url,noindex FROM ce_migration_seo_meta WHERE target_id=:id');$q->execute(['id'=>$entryId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);return $row?:[];
    }
    /** @return array<string,bool> */
    public function noindexIds():array
    {
        if(!$this->ready())return [];
        $result=[];$q=$this->db()->connection()->query('SELECT target_id FROM ce_migration_seo_meta WHERE noindex = 1');
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$result[(string)$id]=true;
        return $result;
    }
}
