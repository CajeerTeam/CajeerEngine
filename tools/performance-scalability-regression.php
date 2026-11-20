#!/usr/bin/env php
<?php
/** Offline protocol regression for high-cardinality AI retrieval and batched job metrics. */
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';

use CajeerEngine\AI\AiKnowledgeBase;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Observability\Metrics;

final class ScalabilityPdo extends PDO
{
    public array $queries = [];
    public array $bindings = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return new ScalabilityStatement($this);
    }
}
final class ScalabilityStatement extends PDOStatement
{
    private int $index = 0;
    public function __construct(private ScalabilityPdo $pdo) {}
    public function execute(?array $params = null): bool
    {
        $this->index = 0;
        $this->pdo->bindings[] = $params;
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $base = ['term'=>'alpha','document_id'=>'11111111-1111-4111-8111-111111111111', 'chunk_no'=>0,
            'frequency'=>3,'title'=>'Verified notes','locale'=>'ru','visibility'=>'public','revision'=>1,
            'source_kind'=>'manual','source_type'=>null,'source_id'=>null,'source_revision'=>null,'content'=>'Safe and current'];
        if ($this->index++ === 0) return $base;
        if ($this->index === 2) return array_replace($base, ['term'=>'beta', 'frequency'=>2]);
        return false;
    }
    public function closeCursor(): bool { return true; }
}
function scalabilityAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$pdo = new ScalabilityPdo();
$db = (new ReflectionClass(DatabaseManager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(DatabaseManager::class, 'pdo'))->setValue($db, $pdo);
$entries = (new ReflectionClass(ContentEntryRepository::class))->newInstanceWithoutConstructor();
$types = (new ReflectionClass(ContentTypeRepository::class))->newInstanceWithoutConstructor();
$kb = new AiKnowledgeBase($db, $entries, $types);
$result = $kb->retrieve('alpha beta', 'public', 'ru', 6);
scalabilityAssert(count($pdo->queries) === 1 && count($pdo->bindings) === 1, 'RAG must use a single SQL round trip');
scalabilityAssert($pdo->bindings[0] === ['alpha','published','public','ru','beta','published','public','ru'], 'Bound per-term filters and query terms');
scalabilityAssert(substr_count($pdo->queries[0], 'LIMIT 350') === 2 && str_contains($pdo->queries[0], 'UNION ALL'), 'Each term must have its own bound');
scalabilityAssert(count($result) === 1 && $result[0]['score'] === 13 && $result[0]['content'] === 'Safe and current', 'RAG ranking and data preserved');
$actualSql = $pdo->queries[0];
$actualBindings = $pdo->bindings[0];
$fourteen = implode(' ', array_map(static fn (int $n): string => 'term'.str_pad((string)$n, 2, '0', STR_PAD_LEFT), range(1, 14)));
$kb->retrieve($fourteen, 'all', '', 6);
scalabilityAssert(count($pdo->queries) === 2 && count($pdo->bindings[1]) === 28,
    'Fourteen distinct RAG terms still issue one prepared query');
scalabilityAssert(substr_count($pdo->queries[1], 'LIMIT 350') === 14,
    'Fourteen per-term caps are retained without an unbounded global scan');

$root = sys_get_temp_dir().'/ce-perf-scalability-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/logs/observability', 0700, true);
try {
    $metrics = new Metrics($root);
    for ($i=0; $i<500; ++$i) $metrics->buffered('job','log.message','success',0.01);
    $metrics->flush();
    $metrics->flush(); // An extra shutdown/explicit flush must not double count.
    $files = glob($root.'/storage/logs/observability/metrics-*.json');
    scalabilityAssert(count($files) === 1, 'One histogram shard should aggregate the batch');
    $data = json_decode((string)file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
    scalabilityAssert(($data['job|log.message|success']['count'] ?? 0) === 500, 'All 500 job events accounted once');
    scalabilityAssert(abs(($data['job|log.message|success']['sum'] ?? 0) - 5.0) < 0.0001, 'Histogram duration sum preserved');
} finally {
    foreach (glob($root.'/storage/logs/observability/*') ?: [] as $f) unlink($f);
    rmdir($root.'/storage/logs/observability'); rmdir($root.'/storage/logs'); rmdir($root.'/storage'); rmdir($root);
}
$output = ['ok'=>true,'retrieval_sql_round_trips_per_call'=>1,'retrieval_terms_tested'=>[2,14],'buffered_job_observations'=>500];
if (in_array('--sql-json', $argv, true)) $output += ['sql'=>$actualSql,'params'=>$actualBindings];
echo json_encode($output,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
