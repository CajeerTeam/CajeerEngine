#!/usr/bin/env php
<?php
/** 1.9.2 migration acceptance: approved revision CAS and bounded, lossless source reads. */
declare(strict_types=1);

use CajeerEngine\Migration\{MigrationPlanner,PlanningSource};

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='CajeerEngine\\';
    if(str_starts_with($class,$prefix)){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require $file;
    }
});
function test192(bool $condition,string $description):void{
    if(!$condition)throw new RuntimeException('FAILED: '.$description);
    echo 'PASS '.$description.PHP_EOL;
}
function rejects192(callable $run,string $contains):void{
    try{$run();}catch(RuntimeException $e){
        test192(str_contains($e->getMessage(),$contains),'bounded read rejects '.$contains);
        return;
    }
    throw new RuntimeException('Incomplete WordPress source inventory was accepted: '.$contains);
}

$entry=['id'=>'11111111-1111-1111-1111-111111111111','slug'=>'fixture','locale'=>'ru','revision'=>7,'title'=>'Editor content','data'=>['body'=>'editor']];
$type=['handle'=>'articles','fields'=>[['handle'=>'body','type'=>'text','required'=>false]]];
$record=PlanningSource::record('post',['id'=>'1','title'=>'Imported content','slug'=>'fixture','body'=>'import']);
$inventory=PlanningSource::inventory([$record],[]);
$mapping=['types'=>['post'=>['target'=>'articles','fields'=>['data:body'=>['from'=>'body']]]],'statuses'=>['draft'=>'draft'],'conflict'=>'update'];
$plan=MigrationPlanner::build($inventory,$mapping,['articles'=>$type],['articles'=>[$entry]],[],static fn()=>null);
test192($plan['approvable']&&$plan['records'][0]['action']==='update'&&$plan['records'][0]['expected_revision']===7,'approved update carries target revision 7');
$new=$entry;$new['revision']=8;
$stale=MigrationPlanner::build($inventory,$mapping,['articles'=>$type],['articles'=>[$new]],[],static fn()=>null);
test192($stale['records'][0]['expected_revision']===8&&$stale['sha256']!==$plan['sha256'],'editor revision changes the approved plan digest');

/** Simulates PDO's actual streaming cursor without a running MySQL daemon. */
final class MigrationFixtureStatement192 extends PDOStatement
{
    private int $cursor=0;
    public function __construct(private array $rows=[],private int $generated=0,private string $kind=''){}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed
    {
        if($this->kind!==''){
            if(++$this->cursor>$this->generated)return false;
            return $this->kind==='metadata'
                ? ['post_id'=>'1','meta_key'=>'rating','meta_value'=>'5','value_bytes'=>1]
                : ['object_id'=>'1','taxonomy'=>'category','slug'=>'news'];
        }
        return $this->rows[$this->cursor++]??false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array
    {
        $rows=[];while(($row=$this->fetch())!==false)$rows[]=$row;
        return $rows;
    }
    public function fetchColumn(int $column=0):mixed
    {
        $row=$this->fetch();return is_array($row)?array_values($row)[$column]??false:$row;
    }
}
final class MigrationFixturePdo192 extends PDO
{
    public function __construct(private string $case){}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false
    {
        if(str_contains($query,'SELECT COALESCE(SUM(n),0)'))return new MigrationFixtureStatement192([[0]]);
        if(str_contains($query,'SUM(OCTET_LENGTH(meta_value)+OCTET_LENGTH(meta_key))'))return new MigrationFixtureStatement192([[0]]);
        if(str_contains($query,'FROM `wp_postmeta`'))return new MigrationFixtureStatement192([], $this->case==='metadata'?100001:1, 'metadata');
        if(str_contains($query,'FROM `wp_term_relationships`'))return new MigrationFixtureStatement192([], $this->case==='taxonomy'?10001:1, 'taxonomy');
        if(str_contains($query,'FROM `wp_posts`'))return new MigrationFixtureStatement192([[
            'id'=>'1','type'=>'post','status'=>'draft','author'=>'0','title'=>'Fixture','body'=>'','excerpt'=>'',
            'slug'=>'fixture','date'=>'2024-01-01 12:00:00','date_gmt'=>'2024-01-01 12:00:00',
            'password'=>'','parent'=>'0','categories'=>'','tags'=>'',
        ]]);
        throw new RuntimeException('Unexpected source SQL in fixture: '.substr($query,0,100));
    }
}
$report=['details'=>['prefix'=>'wp_','columns'=>[
    'wp_posts'=>['ID','post_type','post_status','post_title','post_content','post_name','post_password'],
    'wp_postmeta'=>['post_id','meta_id','meta_key','meta_value'],
    'wp_term_relationships'=>[], 'wp_term_taxonomy'=>[], 'wp_terms'=>[],
]]];
$reader=new PlanningSource();
rejects192(fn()=>$reader->database('wordpress-db',new MigrationFixturePdo192('metadata'),$report),'WordPress metadata exceeds planning limit');
rejects192(fn()=>$reader->database('wordpress-db',new MigrationFixturePdo192('taxonomy'),$report),'Taxonomy relationship limit exceeded');
$normal=$reader->database('wordpress-db',new MigrationFixturePdo192('normal'),$report);
test192(count($normal['records'])===1&&$normal['records'][0]['fields']['meta:rating']==='5','valid WordPress metadata and taxonomy are retained');
echo "PASS Migration Center Production 1.9.2 acceptance\n";
