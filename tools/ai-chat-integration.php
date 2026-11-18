<?php

declare(strict_types=1);

/** Real PDO SQLite integration: credential vault, ownership, history, token budgets and rollback. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) require $file;
    }
});
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR, "FAIL PDO SQLite required\n"); exit(1); }
    echo "SKIP PDO SQLite unavailable\n";
    exit(0);
}
use CajeerEngine\AI\AiChat;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

$workspace = sys_get_temp_dir() . '/cajeer-ai-' . bin2hex(random_bytes(8));
mkdir($workspace, 0700);
$assert = static function (bool $test, string $message): void {
    if (!$test) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . "\n";
};
$reject = static function (callable $fn, string $message) use ($assert): void {
    try { $fn(); } catch (\Throwable) { $assert(true, $message); return; }
    throw new RuntimeException('FAIL ' . $message);
};
try {
    putenv('INTEGRATIONS_KEY=base64:' . base64_encode(random_bytes(32)));
    putenv('OPENAI_API_KEY=');
    $settings = new AiSettings($workspace);
    $assert(!$settings->status()['configured'], 'unconfigured until key exists');
    $status = $settings->update(['api_key'=>'sk-test-not-a-production-key', 'model'=>'gpt-4.1-mini', 'max_output_tokens'=>256, 'monthly_token_limit'=>10000]);
    $assert($status['configured'] && $status['credential_source'] === 'vault', 'encrypted key configured');
    $assert($settings->key() === 'sk-test-not-a-production-key', 'authenticated decryption returns key');
    $content = (string)file_get_contents($workspace . '/storage/app/ai/settings.json');
    $assert(!str_contains($content, 'sk-test-not-a-production-key') && str_contains($content, 'v2:'), 'API key not persisted in plaintext');
    $assert((fileperms($workspace . '/storage/app/ai/settings.json') & 0777) === 0600, 'vault file permissions 0600');
    $reject(fn() => $settings->update(['model'=>'bad\nmodel']), 'reject invalid model');
    $reject(fn() => $settings->update(['max_output_tokens'=>50000]), 'reject unbounded output tokens');
    $config = new ConfigRepository($root);
    $config->set('database.default', 'sqlite');
    $config->set('database.connections.sqlite.database', ':memory:');
    $db = new DatabaseManager($config);
    $db->connection()->exec((string)file_get_contents($root . '/migrations/sqlite/0031_ai_chat.sql'));
    $failNext = false;
    $client = new class($failNext) extends OpenAiClient {
        public function __construct(private bool &$failNext) {}
        public function complete(string $key, string $model, array $input, int $maxOutput, ?callable $onDelta = null): array
        {
            if ($this->failNext) { $this->failNext = false; throw new \CajeerEngine\AI\AiProviderException('Simulated upstream failure', 503); }
            if ($key === '' || $model === '' || $maxOutput < 128) throw new RuntimeException('Invalid provider arguments.');
            if ($onDelta !== null) { $onDelta('Ответ'); $onDelta(' AI'); }
            return ['text'=>'Ответ AI', 'usage'=>['input_tokens'=>120, 'output_tokens'=>20], 'response_id'=>'resp-fixture'];
        }
    };
    $chat = new AiChat($db, $settings, $client);
    $one = '11111111-1111-4111-8111-111111111111';
    $other = '22222222-2222-4222-8222-222222222222';
    $pieces=[];
    $response = $chat->send($one, null, 'Привет', static function (string $delta) use (&$pieces): void { $pieces[] = $delta; });
    $id = $response['conversation_id'];
    $assert($response['text'] === 'Ответ AI' && implode('', $pieces) === 'Ответ AI', 'streamed generation committed');
    $assert(count($chat->messages($one, $id)) === 2 && count($chat->list($one)) === 1, 'user-scoped durable history');
    $assert(count($chat->list($other)) === 0, 'different user cannot list conversations');
    $reject(fn() => $chat->messages($other, $id), 'different user cannot read messages');
    $reject(fn() => $chat->rename($other, $id, 'Stolen'), 'different user cannot rename');
    $reject(fn() => $chat->delete($other, $id), 'different user cannot delete');
    $next = $chat->send($one, $id, 'Второй запрос');
    $assert($next['conversation_id'] === $id && count($chat->messages($one, $id)) === 4, 'multi-turn context and persistence');
    $failNext = true;
    $reject(fn() => $chat->send($one, $id, 'Ошибка сети'), 'upstream failure propagated');
    $assert(count($chat->messages($one, $id)) === 4, 'upstream failure writes no conversation messages');
    $assert($db->connection()->query('SELECT reserved_tokens FROM ce_ai_usage')->fetchColumn() == 0, 'failed request releases budget reservation');
    $failNext = true;
    $reject(fn() => $chat->send($one, null, 'Сбой первого сообщения'), 'failed initial generation propagated');
    $assert(count($chat->list($one)) === 1, 'failed first generation does not leave an empty conversation');
    $chat->rename($one, $id, 'Новое название');
    $assert($chat->list($one)[0]['title'] === 'Новое название', 'rename persisted');
    $settings->update(['monthly_token_limit'=>1000]);
    $reject(fn() => $chat->send($one, $id, str_repeat('x', 2000)), 'monthly token budget enforced before network');
    $chat->delete($one, $id);
    $assert(count($chat->list($one)) === 0 && (int)$db->connection()->query('SELECT count(*) FROM ce_ai_messages')->fetchColumn() === 0, 'delete cascades messages');
    $settings->update(['remove_key'=>true]);
    $assert(!$settings->status()['configured'], 'credential deletion effective');
    echo "AI Chat PDO integration PASSED\n";
} finally {
    if (is_dir($workspace)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($workspace);
    }
}
