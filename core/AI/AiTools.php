<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Analytics\Reporting\AnalyticsHub;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;

/** Read-only, scope-gated execution boundary for model-selected CMS tools. */
final readonly class AiTools
{
    private const MAX_OUTPUT = 9500;

    public function __construct(
        private ContentTypeRepository $types,
        private ContentEntryRepository $entries,
        private AiSeoAssistant $seo,
        private AiCmsDiagnostics $diagnostics,
        private AiKnowledgeBase $knowledge,
        private AnalyticsHub $analytics,
        private AuditLogger $audit,
    ) {}

    private const SPECS = [
        'cms_content_search' => [
            'scope' => 'content:read', 'description' => 'Search a CMS content type. Returns bounded records without private metadata. Type handle required.',
            'parameters' => [
                'type' => ['type'=>'string','description'=>'CMS content type handle'],
                'query' => ['type'=>'string','description'=>'Search in title, slug and body; empty to list newest'],
                'status' => ['type'=>'string','enum'=>['draft','published','archived','all']],
                'limit' => ['type'=>'integer','description'=>'1 to 10'],
            ],
        ],
        'cms_content_get' => [
            'scope'=>'content:read','description'=>'Read a single CMS content entry by type handle and UUID.',
            'parameters'=>['type'=>['type'=>'string'], 'id'=>['type'=>'string']],
        ],
        'cms_seo_audit' => [
            'scope'=>'content:read','description'=>'Perform the real read-only SEO audit of a CMS entry.',
            'parameters'=>['type'=>['type'=>'string'], 'id'=>['type'=>'string']],
        ],
        'cms_diagnostics_run' => [
            'scope'=>'system:read','description'=>'Read an allowlisted CMS health diagnostics report, never raw logs or secrets.',
            'parameters'=>['window_minutes'=>['type'=>'integer','description'=>'15 to 1440 minutes']],
        ],
        'cms_knowledge_search' => [
            'scope'=>'content:read','description'=>'Search published indexed knowledge base documents. Visibility can include internal administrator docs.',
            'parameters'=>['query'=>['type'=>'string'],'visibility'=>['type'=>'string','enum'=>['all','public','internal']],'locale'=>['type'=>'string','description'=>'Language code or empty string'],'limit'=>['type'=>'integer','description'=>'1 to 8']],
        ],
        'cms_analytics_report' => [
            'scope'=>'analytics:read','description'=>'Read previously synced analytics report, no live sync or write operations.',
            'parameters'=>['connection_id'=>['type'=>'string'],'from'=>['type'=>'string','description'=>'YYYY-MM-DD or empty for latest'],'to'=>['type'=>'string','description'=>'YYYY-MM-DD or empty for latest']],
        ],
    ];

    /** @return list<array<string,mixed>> */
    public function definitions(array $scopes): array
    {
        $allowed = [];
        foreach (self::SPECS as $name=>$spec) {
            if (!self::allows($scopes, $spec['scope'])) continue;
            $properties = $spec['parameters'];
            $allowed[] = [
                'type'=>'function', 'name'=>$name, 'description'=>$spec['description'], 'strict'=>true,
                'parameters'=>['type'=>'object', 'properties'=>$properties, 'required'=>array_keys($properties), 'additionalProperties'=>false],
            ];
        }
        return $allowed;
    }

    /** @return list<array{name:string,description:string,scope:string}> */
    public function catalog(array $scopes): array
    {
        $list = [];
        foreach (self::SPECS as $name=>$spec) {
            if (self::allows($scopes,$spec['scope'])) $list[] = ['name'=>$name, 'description'=>$spec['description'], 'scope'=>$spec['scope']];
        }
        return $list;
    }

    private static function allows(array $scopes, string $scope): bool
    {
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    private static function string(array $args, string $key, int $max, bool $empty = false): string
    {
        if (!is_string($args[$key] ?? null) || strlen($args[$key]) > $max || !preg_match('//u', $args[$key]) || (!$empty && trim($args[$key]) === ''))
            throw new \InvalidArgumentException('Invalid tool argument: ' . $key);
        return trim($args[$key]);
    }

    private static function integer(array $args, string $key, int $min, int $max): int
    {
        if (!is_int($args[$key] ?? null) || $args[$key] < $min || $args[$key] > $max)
            throw new \InvalidArgumentException('Invalid tool argument: ' . $key);
        return $args[$key];
    }

    private static function type(array $args): string
    {
        $handle = self::string($args, 'type', 100);
        if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/D', $handle)) throw new \InvalidArgumentException('Invalid CMS content type.');
        return $handle;
    }

    private static function id(array $args): string
    {
        $id = self::string($args, 'id', 36);
        if (!preg_match('/^[a-f0-9-]{36}$/iD', $id)) throw new \InvalidArgumentException('Invalid entry identifier.');
        return $id;
    }

    /** Validate model arguments before invoking any service; never trust a model's claimed permissions. */
    public function execute(string $name, array $arguments, array $scopes, string $userId): array
    {
        $spec = self::SPECS[$name] ?? null;
        if ($spec === null || !self::allows($scopes, $spec['scope'])) throw new \DomainException('AI tool not permitted.');
        if (array_diff(array_keys($arguments), array_keys($spec['parameters'])) !== [] || array_diff(array_keys($spec['parameters']), array_keys($arguments)) !== [])
            throw new \InvalidArgumentException('Invalid tool argument names.');
        $start = microtime(true);
        $status = 'failed';
        try {
            $result = match ($name) {
                'cms_content_search' => $this->searchContent($arguments),
                'cms_content_get' => $this->getContent($arguments),
                'cms_seo_audit' => $this->seo->audit(self::type($arguments), self::id($arguments)),
                'cms_diagnostics_run' => $this->diagnosticReport($arguments),
                'cms_knowledge_search' => $this->searchKnowledge($arguments),
                'cms_analytics_report' => $this->analyticsReport($arguments),
                default => throw new \DomainException('Unknown AI tool.'),
            };
            // No raw credentials or unbounded database values may reach OpenAI.
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (strlen($json) > self::MAX_OUTPUT) throw new \DomainException('Tool result too large; narrow the query.');
            AiOutboundSafety::assertNoCredentials([['role'=>'tool','content'=>$json]]);
            $status = 'ok';
            return $result;
        } finally {
            $this->audit->record('ai.tool.executed', $userId, ['tool'=>$name,'status'=>$status,'duration_ms'=>min(120000,(int)round((microtime(true)-$start)*1000))]);
        }
    }

    private function searchContent(array $args): array
    {
        $type = self::type($args);
        if ($this->types->find($type) === null) throw new \DomainException('Content type not found.');
        $query = self::string($args, 'query', 160, true);
        $status = self::string($args, 'status', 12);
        if (!in_array($status, ['draft','published','archived','all'], true)) throw new \InvalidArgumentException('Invalid status.');
        $limit = self::integer($args,'limit',1,10);
        // Bounded page: do not leak complete content blobs in search results.
        $page = $this->entries->page($type, ['status'=>$status, 'q'=>$query], $limit, 0);
        return ['type'=>$type,'total'=>$page['meta']['total'], 'items'=>array_map(static fn(array $item): array => [
            'id'=>$item['id'], 'title'=>self::prefix((string)$item['title'],160), 'slug'=>$item['slug'],
            'status'=>$item['status'], 'locale'=>$item['locale'], 'revision'=>(int)$item['revision']
        ], $page['data'])];
    }

    private function getContent(array $args): array
    {
        $type = self::type($args);
        if ($this->types->find($type) === null) throw new \DomainException('Content type not found.');
        $id = self::id($args);
        $entry = $this->entries->findById($type,$id);
        if ($entry === null) throw new \DomainException('Content not found.');
        $data = $entry['data'] ?? [];
        if (!is_array($data)) $data = [];
        $fields = [];
        foreach ($data as $key=>$value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,49}$/iD', $key)) continue;
            if (preg_match('/(?:secret|token|password|key|credential|auth)/i', $key)) continue;
            if (is_string($value)) $fields[$key] = self::prefix($value,1800);
            elseif (is_bool($value) || is_numeric($value)) $fields[$key] = $value;
            if (count($fields) >= 16) break;
        }
        return ['type'=>$type,'id'=>$id,'title'=>self::prefix((string)$entry['title'],180),'slug'=>$entry['slug'],
            'status'=>$entry['status'],'locale'=>$entry['locale'],'revision'=>(int)$entry['revision'],'fields'=>$fields];
    }

    private function searchKnowledge(array $args): array
    {
        $query = self::string($args,'query',200);
        $visibility = self::string($args,'visibility',10);
        $locale = self::string($args,'locale',16,true);
        $limit = self::integer($args,'limit',1,8);
        if (!in_array($visibility,['all','public','internal'],true)) throw new \InvalidArgumentException('Invalid visibility.');
        return ['items'=>$this->knowledge->search($query,$visibility,$locale,$limit)];
    }

    private static function prefix(string $s, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $length, 'UTF-8') : substr($s, 0, $length);
    }

    private function diagnosticReport(array $args): array
    {
        $report = $this->diagnostics->snapshot(self::integer($args,'window_minutes',15,1440));
        return ['status'=>$report['status']??'unknown','generated_at'=>$report['generated_at']??'',
            'window_minutes'=>$report['window_minutes']??0,'summary'=>$report['summary']??[],
            'readiness'=>$report['readiness']??[],'issues'=>array_slice($report['issues']??[],0,12)];
    }

    private function analyticsReport(array $args): array
    {
        $id = self::string($args,'connection_id',32);
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \InvalidArgumentException('Invalid analytics connection ID.');
        $from = self::string($args,'from',10,true);
        $to = self::string($args,'to',10,true);
        if (($from === '') !== ($to === '')) throw new \InvalidArgumentException('Both report dates required.');
        $report = $this->analytics->report($id,$from === '' ? null : $from,$to === '' ? null : $to);
        // Only bounded, normalized stored metrics—not provider configuration, credentials, or sync errors.
        $snapshot = $report['report'] ?? null;
        if ($snapshot === null) return ['provider'=>$report['connection']['provider'],'available'=>false];
        $sections = [];
        foreach (['overview','daily','channels','pages'] as $name) {
            $part = $snapshot['sections'][$name] ?? null;
            if (!is_array($part)) continue;
            $rows = [];
            foreach (array_slice($part['rows'] ?? [],0,6) as $row) {
                $rows[] = ['name'=>self::prefix((string)($row['label'] ?? ''),100),'values'=>array_slice($row['values'] ?? [],0,8)];
            }
            $sections[$name] = ['rows'=>$rows,'truncated'=>!empty($part['truncated']),'quality'=>$part['quality']??[]];
        }
        return ['provider'=>$report['connection']['provider'], 'available'=>true,
            'from'=>$snapshot['from']??null, 'to'=>$snapshot['to']??null,
            'metrics'=>array_slice($snapshot['metrics']??[],0,8), 'labels'=>array_slice($snapshot['labels']??[],0,8),
            'sections'=>$sections, 'comparison'=>$report['comparison']['metrics']??[]];
    }
}
