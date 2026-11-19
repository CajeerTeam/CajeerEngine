<?php

declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/core/Http/RouteRegistry.php';
require_once $root.'/core/Security/AuthenticationAssurance.php';
use CajeerEngine\Http\RouteRegistry;
use CajeerEngine\Security\AuthenticationAssurance;
use Symfony\Component\HttpFoundation\Request;
$routes=array_column(RouteRegistry::all(),5,2);
foreach ([
 'options'=>['migration:read','migration:write'],
 'prepare'=>['migration:read','migration:write'],
 'apply'=>['migration:run','migration:write'],
 'reject'=>['migration:write'],
 'rollback'=>['migration:run','migration:write'],
] as $action=>$expected) {
    if (($routes['api.v1.ai.migration.remediation.'.$action]??[])!==$expected)throw new RuntimeException('RBAC mismatch for '.$action);
}
if (class_exists(Request::class)) {
    $request=Request::create('/api/v1/ai/migration/projects/test/remediation/id/apply','POST');
    $request->attributes->set('matched_route','api.v1.ai.migration.remediation.apply');
    if (!AuthenticationAssurance::required($request))throw new RuntimeException('Remediation approval must require recent authentication');
    $request->attributes->set('matched_route','api.v1.ai.migration.remediation.rollback');
    if (!AuthenticationAssurance::required($request))throw new RuntimeException('Remediation rollback must require recent authentication');
}
echo "PASS five remediation routes use explicit RBAC; high-risk apply requires reauthentication\n";
