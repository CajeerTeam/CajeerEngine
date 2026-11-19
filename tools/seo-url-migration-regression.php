<?php
declare(strict_types=1);
use CajeerEngine\Migration\{SeoMigration,SeoMetadata};
use CajeerEngine\Public\PublicPath;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root){$prefix='CajeerEngine\\';if(str_starts_with($class,$prefix)){$path=$root.'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require $path;}});
function checkSeo(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rejectSeo(callable $op,string $label):void{try{$op();}catch(InvalidArgumentException){checkSeo(true,$label);return;}throw new RuntimeException('Must reject: '.$label);}
$tmp=sys_get_temp_dir().'/ce-seo-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
try{
    checkSeo(SeoMigration::pattern('/%year%/%monthnum%/%postname%/')==='/%year%/%monthnum%/%postname%/','WordPress date permalink template parsed');
    checkSeo(SeoMigration::pattern('/news/%id%-%slug%.html')==='/news/%id%-%slug%.html','DLE news template parsed');
    rejectSeo(fn()=>SeoMigration::pattern('https://evil.example/%slug%'),'external permalink template rejected');
    rejectSeo(fn()=>SeoMigration::pattern('/news/%unknown%'),'unsupported template token rejected');
    rejectSeo(fn()=>SeoMigration::pattern('/news/../%slug%'),'template traversal rejected');
    $wp=['id'=>'55','type'=>'post','origin'=>'wordpress','fields'=>['title'=>'Test Article','slug'=>'тест-статья','date'=>'2025-06-04 11:00:00','meta:wp_date_gmt'=>'2025-06-04 11:00:00','meta:_yoast_wpseo_title'=>'%%title%% — Website','meta:_yoast_wpseo_metadesc'=>'A description','meta:_yoast_wpseo_meta-robots-noindex'=>'1']];
    $old=SeoMigration::expand('/%year%/%monthnum%/%postname%/',$wp);checkSeo($old==='/2025/06/'.rawurlencode('тест-статья'),'WordPress date and encoded UTF-8 permalink');
    $meta=SeoMigration::metadata($wp,'/articles/test','https://new.example.com');
    foreach (['noindex', ['index','noindex'], 'a:1:{i:0;s:7:"noindex";}'] as $robots) {
        $rank=$wp;unset($rank['fields']['meta:_yoast_wpseo_meta-robots-noindex']);
        $rank['fields']['meta:rank_math_robots']=$robots;
        checkSeo(SeoMigration::metadata($rank,'/articles/test','https://new.example.com')['noindex']===true,'Rank Math noindex remains protected across REST and serialized DB metadata');
    }
    $rank=$wp;unset($rank['fields']['meta:_yoast_wpseo_meta-robots-noindex']);
    $rank['fields']['meta:rank_math_robots']='a:1:{i:0;s:5:"index";}';
    checkSeo(SeoMigration::metadata($rank,'/articles/test','https://new.example.com')['noindex']===false,'Rank Math index directive does not force noindex');
    checkSeo($meta['title']==='Test Article — Website'&&$meta['description']==='A description'&&$meta['noindex']===true&&$meta['canonical']==='https://new.example.com/articles/test','SEO plugin title/description/noindex/canonical mapped');
    $dle=['id'=>'123','type'=>'post','origin'=>'dle','fields'=>['slug'=>'hello','date'=>'2025-06-04 11:00:00','title'=>'Hello','meta:dle_seo_title'=>'DLE Title','meta:dle_seo_description'=>'DLE Description']];
    checkSeo(SeoMigration::expand('/news/%id%-%slug%.html',$dle)==='/news/123-hello.html','DLE legacy URL expansion');
    $dleSeo=SeoMigration::metadata($dle,'/hello','https://new.example.com');
    checkSeo($dleSeo['title']==='DLE Title'&&$dleSeo['description']==='DLE Description','DLE SEO fields converted');
    checkSeo(SeoMigration::legacy('/news/123-hello.html','https://old.example.com')['key']==='/news/123-hello.html','verified old path');
    checkSeo(SeoMigration::legacy('https://old.example.com/news/123-hello.html','https://old.example.com')['key']==='/news/123-hello.html','same-origin URL accepted');
    checkSeo(SeoMigration::legacy('/?p=123','https://old.example.com')['key']==='p:123','WordPress plain query permalink');
    checkSeo(SeoMigration::legacy('/?page_id=51','https://old.example.com')['key']==='page_id:51','WordPress page query permalink');
    rejectSeo(fn()=>SeoMigration::legacy('https://attacker.example.com/evil','https://old.example.com'),'foreign URL rejected');
    rejectSeo(fn()=>SeoMigration::legacy('/?redirect=https://attacker.example.com','https://old.example.com'),'unapproved query parameter rejected');
    rejectSeo(fn()=>SeoMigration::legacy('/api/v1/anything','https://old.example.com'),'reserved path rejected');
    rejectSeo(fn()=>SeoMigration::legacy('/news/%2fsecret','https://old.example.com'),'encoded path separator rejected');
    rejectSeo(fn()=>SeoMigration::legacy('/','https://old.example.com'),'root redirect rejected');
    $map=['post:123'=>['/news/123-hello.html','/?p=123'],'page:51'=>'/about.html'];file_put_contents($tmp.'/urls.json',json_encode($map,JSON_THROW_ON_ERROR));
    $loaded=SeoMigration::parseMap($tmp.'/urls.json');checkSeo(count($loaded['post:123'])===2&&$loaded['page:51']===['/about.html'],'real JSON URL map parses source records');
    file_put_contents($tmp.'/empty.json','{}');checkSeo(SeoMigration::parseMap($tmp.'/empty.json')===[],'empty map is accepted');
    file_put_contents($tmp.'/invalid.json','{"bad/name":"/test"}');rejectSeo(fn()=>SeoMigration::parseMap($tmp.'/invalid.json'),'invalid source mapping key rejected');
    file_put_contents($tmp.'/huge.json',str_repeat('x',1048577));rejectSeo(fn()=>SeoMigration::parseMap($tmp.'/huge.json'),'oversized URL map rejected');
    symlink($tmp.'/urls.json',$tmp.'/link.json');rejectSeo(fn()=>SeoMigration::parseMap($tmp.'/link.json'),'symlinked URL map rejected');
    $unsetWp=['id'=>'7','origin'=>'wordpress','fields'=>['slug'=>'hello','date'=>'','meta:wp_date_gmt'=>'']];
    rejectSeo(fn()=>SeoMigration::expand('/%year%/%monthnum%/%slug%/',$unsetWp),'missing date does not create false legacy URL');
    $unknown=['origin'=>'wordpress','fields'=>['title'=>'Entry','meta:_yoast_wpseo_title'=>'%%unknown_token%%']];
    checkSeo(SeoMigration::metadata($unknown,'','https://new.example.com')['title']==='','unknown plugin placeholder is not rendered literally');
    echo "SEO URL regression complete.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)unlink($file);rmdir($tmp);}
