<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/core/AI/AiSeoContentInsights.php';
require_once dirname(__DIR__).'/core/Http/RouteRegistry.php';

use CajeerEngine\AI\AiSeoContentInsights as Service;
use CajeerEngine\Http\RouteRegistry;

$check = static function(bool $value,string $label):void {if(!$value)throw new RuntimeException('FAIL '.$label); echo 'PASS '.$label."\n";};
$origin='https://site.example';
foreach (['/news','https://site.example/news','https://site.example/news?source=search'] as $input)
    $check(Service::normalizedPath($input,$origin)==='/news','valid URL maps to exact CMS path');
foreach (['https://other.example/news','//other.example/news','https://site.example:444/news',
    'https://user:pass@site.example/news','/foo/../news','/%2fadmin','/foo//bar',
    'javascript:alert(1)','/foo%2e%2e/bar','/path\\secret', '/news'.chr(10)] as $input)
    $check(Service::normalizedPath($input,$origin)===null,'unsafe or unrelated URL rejected');
$decline=Service::trend(50.,100.,true);
$check($decline['status']==='decline'&&$decline['percent']===-50.0,'real decline calculated');
$check(Service::trend(20.,100.,false)['status']==='unavailable','incomplete periods cannot imply decline');
$check(Service::trend(1.,2.,true)['percent']===null,'low-volume pages cannot imply a trend');
$check(Service::trend(122.,100.,true)['status']==='growth','growth classified');
$check(Service::trend(89.,100.,true)['status']==='stable','small change classified stable');
$routes=[];foreach (RouteRegistry::all() as $r)$routes[$r[2]]=$r;
$check(($routes['api.v1.ai.seo.insights'][5]??null)===['analytics:read','content:read'],'read needs both source and content rights');
$check(($routes['api.v1.ai.seo.insights.propose'][5]??null)===['analytics:read','content:read','content:write'],'paid proposal needs both sources and write rights');
echo "SEO Content Insights deterministic checks passed\n";
