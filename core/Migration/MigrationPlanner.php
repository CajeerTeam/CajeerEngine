<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Content\{ContentEntryValidator,ContentEntryRepository,ContentTypeRepository};
use CajeerEngine\Database\Repository\UserRepository;

/** Plans only: no target mutator is called. Approval binds exact source and target fingerprints. */
final class MigrationPlanner
{
    public function __construct(private ContentTypeRepository $types,private ContentEntryRepository $entries,private UserRepository $users,private ?\CajeerEngine\Database\DatabaseManager $database=null){}
    public function catalog():array
    {
        return ['types'=>array_map(fn($t)=>['handle'=>$t['handle'],'fields'=>array_map(fn($f)=>['handle'=>$f['handle'],'type'=>$f['type'],'required'=>$f['required']??false],$t['fields']??[])],$this->types->all())];
    }
    public function plan(array $inventory,array $mapping):array
    {
        if($this->database!==null&&$this->entries->storage()==='database')return $this->database->readSnapshot(fn()=>$this->planSnapshot($inventory,$mapping));
        return $this->planSnapshot($inventory,$mapping);
    }
    private function planSnapshot(array $inventory,array $mapping):array
    {
        $types=[];$entries=[];foreach($this->types->all() as $t)$types[$t['handle']]=$t;
        foreach(array_unique(array_filter(array_column($mapping['types'],'target'))) as $handle){if(!isset($types[$handle]))throw new \InvalidArgumentException('Unknown target content type: '.$handle);$rows=[];for($offset=0;;$offset+=200){$page=$this->entries->page($handle,[],200,$offset);if($page['meta']['total']>10000)throw new \RuntimeException('Target content type exceeds planning limit (10000).');foreach($page['data'] as $row)$rows[]=['id'=>$row['id'],'slug'=>$row['slug'],'locale'=>$row['locale'],'fingerprint'=>self::hash($row),'revision'=>(int)$row['revision']];if(count($page['data'])<200||count($rows)>=$page['meta']['total'])break;}if($this->database!==null&&$this->entries->storage()==='database'){$q=$this->database->connection()->prepare('SELECT e.id,e.slug,e.locale,e.deleted_at FROM ce_content_entries e JOIN ce_content_types t ON t.id=e.content_type_id WHERE t.handle=:handle AND e.deleted_at IS NOT NULL LIMIT 10001');$q->execute(['handle'=>$handle]);while($row=$q->fetch(\PDO::FETCH_ASSOC)){if(count($rows)>=10000)throw new \RuntimeException('Target content type exceeds planning limit (10000).');$rows[]=$row+['fingerprint'=>self::hash($row),'revision'=>(int)$row['revision']];}}$entries[$handle]=$rows;}
        $userIds=[];foreach($mapping['users'] as $id=>$action)if(!in_array($action,['skip','create'],true)){$u=$this->users->findById($action);if($u===null||$u['status']!=='active')throw new \InvalidArgumentException('Mapped target user is unavailable.');$userIds[$action]=['id'=>$u['id'],'status'=>$u['status'],'updated_at'=>$u['updated_at']??null];}
        $plan=self::build($inventory,$mapping,$types,$entries,$userIds,fn($email)=>$this->users->findByEmail($email));
        $roleState=[];foreach($plan['users'] as $a)if($a['action']==='create'){$role=$a['role'];if(!isset($roleState[$role])){$repo=new \CajeerEngine\Database\Repository\RoleRepository($this->database??throw new \RuntimeException('Database required for identity planning.'));$roleState[$role]=IdentityMigration::safeRole($repo,$role);}}
        if($roleState){ksort($roleState);$plan['target_sha256']=self::hash([$plan['target_sha256'],$roleState]);unset($plan['sha256']);$plan['sha256']=self::hash($plan);}
        return $plan;
    }
    public static function mapping(array $m):array
    {
        $types=$m['types']??null;$statuses=$m['statuses']??null;$users=$m['users']??[];$userRoles=$m['user_roles']??[];$locale=$m['locale']??'ru';$conflict=$m['conflict']??'fail';
        if(!is_array($types)||!$types||count($types)>100||!is_array($statuses)||count($statuses)>100||!is_array($users)||count($users)>5000||!is_string($locale)||!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$locale)||!in_array($conflict,['fail','skip','update','create'],true))throw new \InvalidArgumentException('Invalid mapping profile.');
        $out=[];foreach($types as $source=>$rule){if(!is_string($source)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$source)||!is_array($rule))throw new \InvalidArgumentException('Invalid source type.');$target=$rule['target']??null;$fields=$rule['fields']??[];if(!is_string($target)||($target!==''&&!preg_match('/^[a-z][a-z0-9_]{1,99}$/D',$target))||!is_array($fields)||count($fields)>100)throw new \InvalidArgumentException('Invalid target type or field mapping.');$clean=[];foreach($fields as $dest=>$spec){if(!is_string($dest)||!preg_match('/^(?:title|slug|data:[a-z][a-z0-9_]{1,99})$/D',$dest)||!is_array($spec))throw new \InvalidArgumentException('Invalid target field.');$from=$spec['from']??null;$transform=$spec['transform']??'copy';if(!is_string($from)||strlen($from)>205||!preg_match('/^(?:title|body|excerpt|slug|date|categories|tags|meta:.+)$/uD',$from)||!in_array($transform,['copy','text','number','boolean','date','datetime','json'],true))throw new \InvalidArgumentException('Invalid source field or transform.');$clean[$dest]=compact('from','transform');}ksort($clean);$out[$source]=['target'=>$target,'fields'=>$clean];}
        foreach($statuses as $s=>$v){if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',(string)$s)||!in_array($v,['draft','published','archived','skip'],true))throw new \InvalidArgumentException('Invalid status mapping.');if($v==='published'&&!in_array((string)$s,['publish','published'],true))throw new \InvalidArgumentException('Non-public source statuses cannot be published automatically.');}
        foreach($users as $id=>$v){if(strlen((string)$id)>190||!is_string($v)||(!in_array($v,['skip','create'],true)&&!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$v)))throw new \InvalidArgumentException('Invalid user mapping.');}
        if(!is_array($userRoles)||count($userRoles)>5000)throw new \InvalidArgumentException('Invalid role mapping.');
        foreach($userRoles as $id=>$role)if(($users[$id]??null)!=='create'||!in_array($role,['viewer','editor'],true))throw new \InvalidArgumentException('Only new accounts may receive viewer or editor roles.');
        ksort($out);ksort($statuses);ksort($users);ksort($userRoles);return ['types'=>$out,'statuses'=>$statuses,'users'=>$users,'user_roles'=>$userRoles,'locale'=>$locale,'conflict'=>$conflict];
    }
    private static function hash(mixed $value):string{return hash('sha256',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    private static function value(mixed $v,string $transform):mixed
    {
        if($v===null)return null;
        return match($transform){'copy'=>$v,'text'=>is_scalar($v)?trim(html_entity_decode(strip_tags((string)$v),ENT_QUOTES|ENT_HTML5,'UTF-8')):throw new \InvalidArgumentException('Text conversion requires scalar.'),'number'=>is_numeric($v)&&is_finite((float)$v)?(str_contains((string)$v,'.')||stripos((string)$v,'e')!==false?(float)$v:(int)$v):throw new \InvalidArgumentException('Invalid numeric value.'),'boolean'=>filter_var($v,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)??throw new \InvalidArgumentException('Invalid boolean value.'),'date'=>self::date($v,false),'datetime'=>self::date($v,true),'json'=>is_string($v)?json_decode($v,true,32,JSON_THROW_ON_ERROR):$v,default=>throw new \InvalidArgumentException('Unknown transform.')};
    }
    private static function date(mixed $v,bool $time):string
    {
        if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?)?$/D',$v)||!checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)))throw new \InvalidArgumentException('Invalid source date.');
        $d=new \DateTimeImmutable($v,new \DateTimeZone('UTC'));return $d->format($time?DATE_ATOM:'Y-m-d');
    }
    /** The same normalization is used by planning and the transactional importer. */
    public static function payload(array $r,array $rule,array $type,string $locale,string $status):array
    {
        $defs=array_column($type['fields']??[],null,'handle');$payload=['title'=>$r['fields']['title'],'slug'=>$r['fields']['slug']?:$r['fields']['title'],'locale'=>$locale,'status'=>$status,'data'=>[]];
                    foreach($rule['fields'] as $dest=>$spec){$v=self::value($r['fields'][$spec['from']]??null,$spec['transform']);if(str_starts_with($dest,'data:')){$field=substr($dest,5);if(!isset($defs[$field]))throw new \InvalidArgumentException('Unknown target field: '.$field);if(in_array($defs[$field]['type'],['media','relation','blocks'],true)&&$v!==null&&$v!=='')throw new \InvalidArgumentException('Media, relations and blocks require a later migration stage.');$payload['data'][$field]=$v;}else{if(!is_scalar($v)&&$v!==null)throw new \InvalidArgumentException('Title/slug require scalar.');$payload[$dest]=(string)$v;}}
                    if(($r['origin']??null)==='dle'){
            foreach($defs as $h=>$definition)if(($definition['type']??null)==='richtext'&&is_string($payload['data'][$h]??null))$payload['data'][$h]=DleContent::html($payload['data'][$h]);
        }
        if(($r['origin']??null)==='wordpress'){
            foreach($defs as $h=>$definition)if(($definition['type']??null)==='richtext'&&is_string($payload['data'][$h]??null))$payload['data'][$h]=WordPressContent::html($payload['data'][$h]);
        }
        return (new ContentEntryValidator($type))->normalizeForCreate($payload);
    }
    public static function fingerprint(mixed $value):string {return self::hash($value);}
    public static function build(array $inventory,array $mapping,array $types,array $entries,array $targetUsers,callable $findEmail):array
    {
        $mapping=self::mapping($mapping);$actions=[];$userActions=[];$counts=['create'=>0,'update'=>0,'skip'=>0,'conflict'=>0,'invalid'=>0];$userCounts=['link'=>0,'create'=>0,'skip'=>0,'unmapped'=>0,'conflict'=>0];$reserved=[];$targetIndex=[];$targetState=[];
        foreach($entries as $type=>$rows){foreach($rows as $r){$key=$type.'|'.$r['locale'].'|'.$r['slug'];$targetIndex[$key][]=$r;$targetState[$r['id']]=$r['fingerprint']??self::hash([$r['id'],$r['slug'],$r['locale'],$r['status']??null,$r['updated_at']??null,$r['revision']??null,$r['data']??null,$r['title']??null]);}}ksort($targetState);
        $sourceUsers=[];$plannedEmails=[];foreach($inventory['users'] as $u)$sourceUsers[$u['id']]=$u;foreach($inventory['records'] as $r)if($r['author']!==''&&$r['author']!=='0')$sourceUsers[$r['author']]??=['id'=>$r['author'],'email'=>''];ksort($sourceUsers);
        foreach($sourceUsers as $id=>$u){$chosen=$mapping['users'][$id]??null;$email=filter_var($u['email'],FILTER_VALIDATE_EMAIL)?strtolower($u['email']):null;$match=$email?$findEmail($email):null;$a=['source_id'=>(string)$id,'action'=>'unmapped','target_id'=>null,'reason'=>'No explicit author mapping.'];if($chosen==='skip')$a=['source_id'=>(string)$id,'action'=>'skip','target_id'=>null,'reason'=>'Explicitly excluded.'];elseif($chosen==='create'){$a=['source_id'=>(string)$id,'action'=>($email&&!$match?'create':'conflict'),'target_id'=>null,'reason'=>$email&&!$match?'Create disabled account; verified operator activation required.':'Valid unique email required for new account.'];}elseif($chosen!==null){if(!isset($targetUsers[$chosen]))throw new \InvalidArgumentException('Target user not found.');$a=['source_id'=>(string)$id,'action'=>'link','target_id'=>$chosen,'reason'=>'Explicit existing account.'];}elseif($match){$a=['source_id'=>(string)$id,'action'=>'conflict','target_id'=>$match['id'],'reason'=>'Email matches an existing account; explicit confirmation required.'];}if($a['action']==='create'){if(isset($plannedEmails[$email])){$a['action']='conflict';$a['reason']='Duplicate source email in planned accounts.';}else $plannedEmails[$email]=true;}$a['source_role']=(string)($u['role']??'');if($a['action']==='create'){$a['role']=$mapping['user_roles'][$id]??'viewer';$a['activation']=!empty($u['blocked'])?'blocked':'pending';}$userCounts[$a['action']]++;$userActions[]=$a;}
        foreach($inventory['records'] as $r){$rule=$mapping['types'][$r['type']]??null;$a=['source_id'=>$r['id'],'source_type'=>$r['type'],'source_status'=>$r['status'],'action'=>'invalid','target_type'=>$rule['target']??null,'target_id'=>null,'slug'=>null,'author_id'=>null,'source_author'=>$r['author'],'payload_sha256'=>null,'expected_revision'=>null,'reason'=>null];$status=$mapping['statuses'][$r['status']]??'draft';
            try{
                if($rule===null)throw new \InvalidArgumentException('Source type is not mapped.');
                if($rule['target']===''||$status==='skip'){$a['action']='skip';$a['reason']='Excluded by mapping.';}
                else{$handle=$rule['target'];$type=$types[$handle]??throw new \InvalidArgumentException('Target type does not exist.');$normalized=self::payload($r,$rule,$type,$mapping['locale'],$status);$slug=$normalized['slug'];$key=$handle.'|'.$mapping['locale'].'|'.$slug;$matches=$targetIndex[$key]??[];$a['slug']=$slug;$a['action']='create';
                    if(count($matches)>1||isset($reserved[$key])){$a['action']='conflict';$a['reason']='Ambiguous target or duplicate planned slug.';}
                    elseif($matches){$existing=$matches[0];$a['target_id']=$existing['id'];switch($mapping['conflict']){case 'skip':$a['action']='skip';$a['reason']='Existing target slug.';break;case 'update':$a['action']=!empty($existing['deleted_at'])?'conflict':'update';if($a['action']==='update')$a['expected_revision']=isset($existing['revision'])?(int)$existing['revision']:null;$a['reason']=!empty($existing['deleted_at'])?'Deleted target reserves slug; choose create or skip.':'Explicit update policy.';break;case 'create':$base=$slug;$i=2;do{$slug=$base.'-'.$i++;$key=$handle.'|'.$mapping['locale'].'|'.$slug;}while(isset($targetIndex[$key])||isset($reserved[$key]));$normalized['slug']=$a['slug']=$slug;$a['target_id']=null;break;default:$a['action']='conflict';$a['reason']='Existing target slug.';}}
                    if(in_array($a['action'],['create','update'],true)){$reserved[$key]=true;$chosen=$mapping['users'][$r['author']]??null;if($chosen!==null&&!in_array($chosen,['skip','create'],true))$a['author_id']=$chosen;$a['payload_sha256']=self::hash($normalized);}
                }
            }catch(\Throwable $e){$a['action']='invalid';$a['reason']=$e instanceof \InvalidArgumentException?$e->getMessage():'Field conversion failed.';}
            $counts[$a['action']]++;$actions[]=$a;
        }
        $schema=[];foreach($mapping['types'] as $rule)if($rule['target']!==''&&isset($types[$rule['target']]))$schema[$rule['target']]=$types[$rule['target']];ksort($schema);ksort($targetUsers);
        $plan=['source_sha256'=>self::hash($inventory),'target_sha256'=>self::hash([$schema,$targetState,$targetUsers,$userActions]),'mapping_sha256'=>self::hash($mapping),'counts'=>$counts,'user_counts'=>$userCounts,'records'=>$actions,'users'=>$userActions,'warnings'=>$inventory['warnings'],'target_modified'=>false,'approvable'=>$counts['conflict']===0&&$counts['invalid']===0&&$userCounts['conflict']===0&&$userCounts['unmapped']===0];
        $plan['sha256']=self::hash($plan);return $plan;
    }
}
