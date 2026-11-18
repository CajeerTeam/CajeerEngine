<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/core/Analytics/Reporting/ReportComparison.php';
require_once dirname(__DIR__).'/core/Analytics/Reporting/AnalyticsHub.php';
require_once dirname(__DIR__).'/core/AI/AiBudgetLease.php';
require_once dirname(__DIR__).'/core/AI/AiExternalAnalytics.php';
require_once dirname(__DIR__).'/core/Http/RouteRegistry.php';

use CajeerEngine\AI\AiExternalAnalytics;
use CajeerEngine\Analytics\Reporting\ReportComparison;

function check(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
$scopes=array_column(\CajeerEngine\Http\RouteRegistry::all(),5,2);
check($scopes['api.v1.ai.analytics.insights']===['analytics:read'] && $scopes['api.v1.ai.analytics.explain']===['analytics:read'],'Analytics insight routes enforce read RBAC');
$to=gmdate('Y-m-d',time()-86400);$from=gmdate('Y-m-d',time()-20*86400);
$days=[];$cursor=new DateTimeImmutable($from,new DateTimeZone('UTC'));
for ($i=0;$i<20;$i++) {
    $date=$cursor->modify('+'.$i.' days')->format('Y-m-d');
    $days[]=['label'=>$date,'values'=>[$i<13?100.:50.,$i<13?180.:100.]];
}
$report=[
    'provider'=>'ga4','from'=>$from,'to'=>$to,'fetched_at'=>gmdate(DATE_ATOM),'timezone'=>'Europe/Warsaw',
    'metrics'=>['sessions','screenPageViews'],'labels'=>['Sessions','Views'],
    'sections'=>[
        'overview'=>['rows'=>[['label'=>'Total','values'=>[1200.,2500.]]], 'quality'=>[],'truncated'=>false],
        'daily'=>['rows'=>$days,'quality'=>[],'truncated'=>false],
        'channels'=>['rows'=>[['label'=>'Organic Search','values'=>[150.,200.]]], 'quality'=>[]],
        'pages'=>['rows'=>[['label'=>'/article?auth_secret=should-not-appear','values'=>[30.,40.]]], 'quality'=>[]],
    ],
];
$comparison=['from'=>'previous','to'=>'previous','status'=>'ready','sampled'=>false,
    'metrics'=>[['metric'=>'sessions','label'=>'Sessions','current'=>1200.,'previous'=>2000.],
        ['metric'=>'screenPageViews','label'=>'Views','current'=>2500.,'previous'=>2200.]]];
$x=AiExternalAnalytics::analyze($report,$comparison);
check($x['available'] && $x['provider']==='ga4','Snapshot not analyzed');
check(count($x['changes'])===2 && $x['changes'][0]['percent']===-40.0,'Equal period delta wrong');
check(count($x['observations'])>=1 && $x['observations'][0]['code']==='period_decline','Decline not detected');
check(count($x['daily_signals'])===1 && $x['daily_signals'][0]['percent']===-50.0,'Week-over-week anomaly wrong');
check($x['segments']['pages']['items'][0]['label']==='/article','Page query should be stripped');
check(!str_contains(json_encode($x,JSON_THROW_ON_ERROR),'should-not-appear'),'Sensitive URL query leaked');
$partial=$report;$partial['sections']['overview']['quality']['sampled']=true;
$y=AiExternalAnalytics::analyze($partial,$comparison);
check($y['changes']===[] && $y['quality']['complete']===false,'Sampled overview should suppress exact comparisons');
$short=$report;$short['sections']['daily']['rows']=array_slice($days,7);
check(AiExternalAnalytics::analyze($short,$comparison)['daily_signals']===[],'Missing dates yielded false anomaly');
$sampledPrevious=$comparison;$sampledPrevious['sampled']=true;
$approx=AiExternalAnalytics::analyze($report,$sampledPrevious);
check($approx['observations']===[] && $approx['changes'][0]['sampled'],'Sampled comparison cannot trigger confident anomaly');
$missingDays=$report;$missingDays['sections']['overview']['quality']['available_days']=9;
check(AiExternalAnalytics::analyze($missingDays,$comparison)['changes']===[],'Missing provider coverage suppresses comparison');
$missing=AiExternalAnalytics::analyze($report,['status'=>'missing']);
check($missing['changes']===[] && $missing['warnings']!==[],'Missing previous report did not block comparisons');
try{$bad=$report;$bad['provider']='unknown';AiExternalAnalytics::analyze($bad,$comparison);throw new RuntimeException('Unknown provider not rejected');}
catch(InvalidArgumentException){}
check(ReportComparison::previous($from,$to)!==null,'Existing period comparison broken');
echo "AI External Analytics: 13 real deterministic regression checks passed\n";
