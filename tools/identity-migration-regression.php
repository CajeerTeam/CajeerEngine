<?php
declare(strict_types=1);
use CajeerEngine\Migration\{IdentityMigration,MigrationPlanner,PlanningSource,DleImporter};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function ck(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
function reject(callable $f,string $type,string $m):void{try{$f();}catch(Throwable $e){if(!$e instanceof $type)throw $e;ck(true,$m);return;}throw new RuntimeException('Expected rejection: '.$m);}
$type=['handle'=>'articles','fields'=>[['handle'=>'body','type'=>'text','required'=>true]]];
$record=PlanningSource::record('post',['id'=>'1','title'=>'Imported','slug'=>'imported','body'=>'Body','author'=>'7','status'=>'publish']);
$inventory=PlanningSource::inventory([$record],[['id'=>'7','email'=>'writer@example.com','name'=>'Writer','role'=>'administrator'],['id'=>'8','email'=>'blocked@example.com','name'=>'Blocked','blocked'=>true]]);
$map=['types'=>['post'=>['target'=>'articles','fields'=>['data:body'=>['from'=>'body']]]],'statuses'=>['publish'=>'published'],'users'=>['7'=>'create','8'=>'create'],'user_roles'=>['7'=>'editor'],'locale'=>'ru','conflict'=>'fail'];
$p=MigrationPlanner::build($inventory,$map,['articles'=>$type],[],[],fn($e)=>null);
ck($p['approvable']&&$p['users'][0]['role']==='editor'&&$p['users'][1]['activation']==='blocked','explicit editor role, blocked identity and default viewer');
ck(!str_contains(json_encode($p),'writer@example.com'),'plan contains no source email');
$bad=$map;$bad['user_roles']['7']='admin';reject(fn()=>MigrationPlanner::mapping($bad),InvalidArgumentException::class,'admin role cannot be mapped');
$bad=$map;$bad['users']['7']='skip';reject(fn()=>MigrationPlanner::mapping($bad),InvalidArgumentException::class,'role cannot change linked or skipped account');
$dupe=MigrationPlanner::build($inventory,$map,['articles'=>$type],[],[],fn($e)=>['id'=>'existing']);ck(!$dupe['approvable']&&$dupe['user_counts']['conflict']===2,'email collisions block creation');
ck(IdentityMigration::wordpressRoles('a:2:{s:13:"administrator";b:1;s:6:"editor";b:0;}')==='administrator','source capabilities read without execution');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)||!class_exists(DOMDocument::class)||!function_exists('mb_substr')){if(in_array('--require-db',$argv,true))throw new RuntimeException('PDO SQLite, DOM and mbstring required');echo "SKIP PHP SQL execution: extensions unavailable\n";exit(0);}
$work=sys_get_temp_dir().'/ce-identity-'.bin2hex(random_bytes(8));mkdir($work,0700);mkdir($work.'/config');foreach(glob($root.'/config/*.php') as $f)copy($f,$work.'/config/'.basename($f));
putenv('CONTENT_STORAGE=database');putenv('CMS_STORAGE=database');putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$work.'/test.sqlite');
try{
 $config=new ConfigRepository($work);$db=new DatabaseManager($config);$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');$roles=new RoleRepository($db);$roles->seedDefaults();$users=new UserRepository($db,$roles);$types=new ContentTypeRepository($work,$db);$entries=new ContentEntryRepository($work,$db);
 $types->create(['handle'=>'articles','name'=>'Articles','fields'=>[['handle'=>'body','label'=>'Body','type'=>'text','required'=>true]]]);$planner=new MigrationPlanner($types,$entries,$users,$db);$imp=new DleImporter($work,$db,$types,$entries,$planner);$id=str_repeat('a',32);
 $plan=$planner->plan($inventory,$map);$project=['id'=>$id,'source'=>'dle-db','status'=>'approved','mapping'=>$map,'plan'=>$plan+['approved_at'=>gmdate(DATE_ATOM)]];
 $result=$imp->execute($project,$inventory);$a=$result['users'][0]['target_id'];$b=$result['users'][1]['target_id'];
 ck($users->findById($a)['status']==='disabled'&&$users->findById($a)['roles']===['editor']&&$users->findById($b)['roles']===['viewer'],'actual imported accounts disabled with explicit roles');
 ck($pdo->query("SELECT author_id FROM ce_content_entries WHERE slug='imported'")->fetchColumn()===$a,'actual migrated author linked');
 ck($imp->execute($project,$inventory)===$result&&$users->count()===2,'repeat import creates no users or records');
 $identity=new IdentityMigration($db,$users,$roles);$hasher=new PasswordHasher($config);$pw='Strong-Identity-2026';
 reject(fn()=>$identity->activate($b,$pw,$hasher),RuntimeException::class,'blocked source account cannot activate');
 $identity->activate($a,$pw,$hasher);$auth=$users->findByEmailForAuth('writer@example.com');ck($auth['status']==='active'&&$hasher->verify($pw,$auth['password_hash']),'new password authenticates after one-time activation');
 reject(fn()=>$identity->activate($a,$pw,$hasher),RuntimeException::class,'repeat activation rejected');
 $roles->upsert('viewer','Unsafe',['*']);reject(fn()=>IdentityMigration::safeRole($roles,'viewer'),RuntimeException::class,'customized privileged viewer rejected');$roles->seedDefaults();
 $wpRecord=$record;$wpRecord['id']='2';$wpRecord['author']='20';$wpRecord['fields']['slug']='wp-imported';$wpRecord['fields']['title']='WP imported';
 $wpInventory=PlanningSource::inventory([$wpRecord],[['id'=>'20','email'=>'wpwriter@example.com','name'=>'WP Writer','role'=>'administrator']]);$wpMap=$map;$wpMap['users']=['20'=>'create'];$wpMap['user_roles']=[];$wpPlan=$planner->plan($wpInventory,$wpMap);$wpProject=['id'=>str_repeat('c',32),'source'=>'wordpress-wxr','status'=>'approved','mapping'=>$wpMap,'plan'=>$wpPlan+['approved_at'=>gmdate(DATE_ATOM)]];
 $wpResult=$imp->execute($wpProject,$wpInventory);$wpUser=$wpResult['users'][0]['target_id'];ck($users->findById($wpUser)['roles']===['viewer']&&$pdo->query("SELECT author_id FROM ce_content_entries WHERE slug='wp-imported'")->fetchColumn()===$wpUser,'WordPress user and author created; source administrator remains viewer');
 $staleRecord=$record;$staleRecord['id']='3';$staleRecord['author']='21';$staleRecord['fields']['slug']='stale-role';$staleInventory=PlanningSource::inventory([$staleRecord],[['id'=>'21','email'=>'stale@example.com']]);$staleMap=$map;$staleMap['users']=['21'=>'create'];$staleMap['user_roles']=[];$stalePlan=$planner->plan($staleInventory,$staleMap);$staleProject=['id'=>str_repeat('d',32),'source'=>'dle-db','status'=>'approved','mapping'=>$staleMap,'plan'=>$stalePlan+['approved_at'=>gmdate(DATE_ATOM)]];$roles->upsert('viewer','Changed role',['content:read']);reject(fn()=>$imp->execute($staleProject,$staleInventory),\CajeerEngine\Content\RevisionConflict::class,'changed role invalidates approved plan');$roles->seedDefaults();ck($users->findByEmail('stale@example.com')===null,'stale role plan creates no identity');
 $before=$users->count();$new=['users'=>[['id'=>'9','email'=>'rollback@example.com','name'=>'Rollback']]];$actions=[['source_id'=>'9','target_id'=>null,'action'=>'create','role'=>'viewer']];
 reject(fn()=>$db->transaction(function()use($identity,$new,$actions){$identity->import(str_repeat('b',32),$new,$actions);throw new RuntimeException('forced rollback');}),RuntimeException::class,'transaction failure');
 ck($users->count()===$before&&$users->findByEmail('rollback@example.com')===null&&(int)$pdo->query("SELECT COUNT(*) FROM ce_migration_identities WHERE source_id='9'")->fetchColumn()===0,'account and identity rolled back together');
}finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($work);}
