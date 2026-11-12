<?php

declare(strict_types=1);
namespace CajeerEngine\Rc;

use CajeerEngine\Api\ApiContract;
use CajeerEngine\Http\RouteRegistry;

final readonly class OpenApiLinter
{
    public function __construct(private string $rootPath) {}
    /** @return list<CheckResult> */
    public function lint(): array
    {
        try { $document = (new ApiContract($this->rootPath))->document(); }
        catch (\Throwable $e) { return [CheckResult::fail('api','openapi.parse',$e->getMessage())]; }
        $errors = [];
        if (($document['openapi'] ?? '') !== '3.1.1' || ($document['servers'][0]['url'] ?? '') !== '/api/v1') $errors[] = 'Invalid OpenAPI version or server base';
        if (($document['info']['version'] ?? '') !== trim((string) file_get_contents($this->rootPath . '/VERSION'))) $errors[] = 'Version mismatch';
        $expected = [];
        foreach (RouteRegistry::api() as [$method,$path,$name,$controller,$action,$scopes]) {
            $key = strtolower($method).' '.substr($path,7);
            if (isset($expected[$key])) $errors[] = 'Duplicate runtime route: '.$key;
            $expected[$key] = [$name,$scopes];
        }
        $actual = []; $ids = [];
        foreach ($document['paths'] ?? [] as $path => $item) {
            if (!str_starts_with($path,'/') || str_starts_with($path,'/api/')) $errors[] = 'Invalid relative path: '.$path;
            foreach ($item as $method => $op) {
                if (!in_array($method,['get','post','put','patch','delete','head','options','trace'],true)) continue;
                $key = $method.' '.$path; $actual[$key] = true;
                if (!isset($expected[$key])) { $errors[] = 'Undeclared runtime operation: '.$key; continue; }
                [$name,$scopes] = $expected[$key];
                if (($op['x-cajeer-route'] ?? '') !== $name || !array_key_exists('x-cajeer-required-scopes',$op) || $op['x-cajeer-required-scopes'] !== $scopes) $errors[] = 'Route / scope mismatch: '.$key;
                $security = $scopes === null ? [] : [['BearerAuth'=>[]],['ApiTokenAuth'=>[]],['BrowserSession'=>[]]+($method !== 'get' ? ['CsrfToken'=>[]] : [])];
                if (($op['security'] ?? null) !== $security) $errors[] = 'Security mismatch: '.$key;
                $id = $op['operationId'] ?? '';
                if (!is_string($id) || $id === '' || isset($ids[$id])) $errors[] = 'Missing/duplicate operationId: '.$key;
                $ids[$id] = true;
                preg_match_all('/\{([^}]+)\}/',$path,$matches);
                $params = array_merge($item['parameters'] ?? [], $op['parameters'] ?? []);
                $found = [];
                foreach ($params as $parameter) if (($parameter['in'] ?? '') === 'path' && ($parameter['required'] ?? false) === true && isset($parameter['schema'])) $found[] = $parameter['name'];
                sort($found); $required = $matches[1]; sort($required);
                if ($found !== $required) $errors[] = 'Invalid path parameters: '.$key;
                if (!isset($op['responses']['500'],$op['responses']['429'])) $errors[] = 'Missing error responses: '.$key;
                if ($scopes !== null && !isset($op['responses']['401'],$op['responses']['403'])) $errors[] = 'Missing auth responses: '.$key;
                if (!($op['deprecated'] ?? false) && !array_filter(array_keys($op['responses'] ?? []),static fn($s)=>str_starts_with((string)$s,'2'))) $errors[] = 'Missing success response: '.$key;
                foreach ($op['responses'] ?? [] as $status=>$response) {
                    if ((string)$status !== '204' && !isset($response['content']['application/json']['schema'])) $errors[] = 'Missing response schema: '.$key.' '.$status;
                }
                if (isset($op['requestBody']) && !isset($op['requestBody']['content']['application/json']['schema'])) $errors[] = 'Missing body schema: '.$key;
            }
        }
        foreach (array_diff_key($expected,$actual) as $key=>$_) $errors[] = 'Missing OpenAPI operation: '.$key;
        $walk = function (array $node) use (&$walk,&$errors,$document): void {
            if (isset($node['$ref'])) {
                $ref = $node['$ref'];
                if (!is_string($ref) || !str_starts_with($ref,'#/components/schemas/') || !isset($document['components']['schemas'][substr($ref,21)])) $errors[] = 'Unresolved reference: '.(string)$ref;
            }
            foreach ($node as $child) if (is_array($child)) $walk($child);
        };
        $walk($document);
        return [$errors === [] ? CheckResult::pass('api','openapi.strict','All core routes, permissions, operation IDs and schema references agree.', ['operations'=>count($actual),'runtime'=>count($expected)]) : CheckResult::fail('api','openapi.strict','OpenAPI contract mismatch.', ['errors'=>array_values(array_unique($errors)),'operations'=>count($actual),'runtime'=>count($expected)])];
    }
}
