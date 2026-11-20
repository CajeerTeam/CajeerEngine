<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Security\PasswordHasher;

/** Accounts and source identity receipts participate in the content transaction. */
final readonly class IdentityMigration
{
    public function __construct(private DatabaseManager $db,private UserRepository $users,private RoleRepository $roles){}

    /** No serialized objects or PHP code are evaluated. Source capabilities are informational only. */
    public static function wordpressRoles(string $raw):string
    {
        if(strlen($raw)>16384)return '';
        preg_match_all('/s:\d+:"([a-zA-Z0-9_-]{1,100})";b:1;/', $raw,$m);
        return implode(',',array_slice(array_unique($m[1]),0,30));
    }
    public static function safeRole(RoleRepository $roles,string $handle):array
    {
        $allowed=match($handle){'viewer'=>['content:read','media:read','system:read'],'editor'=>['content:read','content:write','media:read','media:write'],default=>throw new \InvalidArgumentException('Privileged roles cannot be migrated.')};
        $role=$roles->find($handle);
        if(!$role||array_diff($role['permissions'],$allowed))throw new \RuntimeException('Target migration role is missing or has elevated permissions: '.$handle);
        return $role;
    }
    public function import(string $project,array $inventory,array $actions):array
    {
        if(!$this->db->connection()->inTransaction())throw new \LogicException('Identity import requires the content transaction.');
        $source=[];foreach($inventory['users'] as $u)$source[(string)$u['id']]=$u;
        $map=[];$rows=[];
        foreach($actions as $a){
            $sid=(string)$a['source_id'];$id=$a['target_id'];$status='none';
            if($a['action']==='create'){
                $u=$source[$sid]??throw new \RuntimeException('Missing source identity.');
                $role=$a['role']??'viewer';self::safeRole($this->roles,$role);
                $email=strtolower(trim((string)$u['email']));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$this->users->findByEmail($email))throw new \RuntimeException('Source identity email is invalid or already exists.');
                $name=trim(strip_tags((string)($u['name']??$sid)));if($name==='')$name=$sid;
                $name=mb_substr($name,0,190);
                // Unrecoverable random password, never returned or stored in plain text.
                $hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_ARGON2ID);
                $id=$this->users->create($email,$name,$hash,[$role]);
                $this->users->disable($id);
                $status=!empty($u['blocked'])?'blocked':'pending';
                $q=$this->db->connection()->prepare('INSERT INTO ce_migration_identities(project_id,source_id,user_id,role_handle,state,created_at) VALUES(:project,:source,:user,:role,:state,:date)');
                $q->execute(['project'=>$project,'source'=>$sid,'user'=>$id,'role'=>$role,'state'=>$status,'date'=>gmdate(DATE_ATOM)]);
                (new \CajeerEngine\Database\Repository\AuditLogRepository($this->db))->record('migration.identity.created',null,['project_id'=>$project,'user_id'=>$id,'role'=>$role,'state'=>$status]);
            }elseif($a['action']==='link'){
                $target=$this->users->findById((string)$id);if(!$target||$target['status']!=='active')throw new \RuntimeException('Linked user is no longer active.');
            }elseif($a['action']!=='skip')throw new \LogicException('Unresolved user action.');
            if($id!==null&&in_array($a['action'],['link','create'],true))$map[$sid]=$id;
            $rows[]=['source_id'=>$sid,'target_id'=>$id,'action'=>$a['action'],'role'=>$a['role']??null,'activation'=>$status];
        }
        return ['map'=>$map,'users'=>$rows];
    }
    /** Operator-only; ownership must be verified out of band. No legacy hashes or sessions. */
    public function activate(string $id,#[\SensitiveParameter] string $password,PasswordHasher $hasher):void
    {
        $hash=$hasher->hash($password);
        $this->db->transaction(function()use($id,$hash){
            $q=$this->db->connection()->prepare('SELECT state,role_handle FROM ce_migration_identities WHERE user_id=:id');$q->execute(['id'=>$id]);$row=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$row||$row['state']!=='pending')throw new \RuntimeException('Identity is not pending activation.');
            self::safeRole($this->roles,$row['role_handle']);
            $user=$this->users->findById($id);if(!$user||$user['status']!=='disabled'||$user['roles']!==[$row['role_handle']])throw new \RuntimeException('Account or roles changed; review manually.');
            $q=$this->db->connection()->prepare("UPDATE ce_migration_identities SET state='activated',activated_at=:date WHERE user_id=:id AND state='pending'");$q->execute(['date'=>gmdate(DATE_ATOM),'id'=>$id]);if($q->rowCount()!==1)throw new \RuntimeException('Identity already activated.');
            $q=$this->db->connection()->prepare("UPDATE ce_users SET password_hash=:hash,status='active',disabled_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='disabled'");$q->execute(['hash'=>$hash,'id'=>$id]);if($q->rowCount()!==1)throw new \RuntimeException('Account changed during activation.');
            (new \CajeerEngine\Database\Repository\AuditLogRepository($this->db))->record('migration.identity.activated',null,['user_id'=>$id]);
        });
    }
}
