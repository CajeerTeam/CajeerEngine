<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use PDO;

/** Read-only, live-install production acceptance for the complete AI feature suite. */
final readonly class AiGaAcceptance
{
    public function __construct(private string $root, private ConfigRepository $config, private DatabaseManager $db) {}

    /** @return array{ok:bool,version:string,checks:list<array<string,mixed>>} */
    public function run(bool $requireProduction = false, bool $probeProvider = false): array
    {
        $checks = [];
        $record = static function (string $name, bool $ok, string $reason = '') use (&$checks): void {
            // Never output raw PDO/OpenAI errors, environment variables or credentials.
            $checks[] = ['check' => $name, 'status' => $ok ? 'pass' : 'fail', 'detail' => $reason];
        };
        $version = trim((string)@file_get_contents($this->root.'/VERSION'));
        $record('release_version', $version === '1.7.0' && $this->config->string('app.version') === $version, 'Release and runtime versions must match 1.7.0.');
        if ($requireProduction) {
            $record('production_environment', strtolower((string)getenv('APP_ENV')) === 'production', 'Set APP_ENV=production.');
            $record('debug_disabled', !filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN), 'Disable APP_DEBUG.');
            $url = (string)(getenv('APP_URL') ?: $this->config->string('app.url'));
            $record('https_origin', preg_match('~^https://[^/\s?#]+(?:/)?$~iD', $url) === 1, 'Use a single HTTPS APP_URL origin.');
        }
        $record('php_runtime', PHP_VERSION_ID >= 80401, 'PHP 8.4.1 or newer is required.');
        $record('crypto_runtime', extension_loaded('sodium') && extension_loaded('openssl'), 'ext-sodium and OpenSSL are required.');
        $record('streaming_transport', extension_loaded('curl'), 'ext-curl is required for OpenAI requests.');
        $record('admin_assets', (new AdminAssetPublisher($this->root))->manifest()['ready'] === true, 'Published SPA files and manifest must match VERSION.');
        $settings = new AiSettings($this->root);
        $configured = false;
        try {
            $state = $settings->status();
            $configured = $state['configured'] === true;
            $record('openai_configuration', $configured, 'Configure an OpenAI API key for AI Editor / RAG / Support.');
            $record('token_limits', (int)$state['max_output_tokens'] >= 128 && (int)$state['monthly_token_limit'] >= 1000, 'Set safe output and monthly token limits.');
            if ($configured) {
                $key = $settings->key();
                try { $record('credential_decryption', $key !== '', 'OpenAI key must be decryptable by this PHP worker.'); }
                finally { if (function_exists('sodium_memzero')) sodium_memzero($key); }
            }
        } catch (\Throwable) {
            $record('credential_decryption', false, 'OpenAI configuration or encrypted key cannot be read.');
        }
        $tables = [
            'ce_ai_conversations', 'ce_ai_messages', 'ce_ai_usage', 'ce_ai_editor_jobs',
            'ce_ai_editor_proposals', 'ce_ai_translation_proposals', 'ce_ai_translation_glossary',
            'ce_kb_categories', 'ce_kb_documents', 'ce_kb_chunks', 'ce_kb_terms',
            'ce_ai_kb_conversations', 'ce_ai_kb_messages', 'ce_support_tickets', 'ce_support_messages',
        ];
        try {
            $pdo = $this->db->connection();
            $record('database_connectivity', $pdo->query('SELECT 1')->fetchColumn() !== false, 'Database must be available.');
            $missing = [];
            foreach ($tables as $table) {
                try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
                catch (\Throwable) { $missing[] = $table; }
            }
            $record('ai_sql_schema', $missing === [], $missing === [] ? 'All AI migrations are applied.' : 'Missing or inaccessible: '.implode(', ', $missing));
            if ($this->db->driver() === 'sqlite') {
                $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
                $record('foreign_key_integrity', $violations === [], 'SQLite foreign-key integrity must be clean.');
            }
        } catch (\Throwable) {
            $record('database_connectivity', false, 'Database connection or schema cannot be read.');
            $record('ai_sql_schema', false, 'Apply migrations 0031–0036 before enabling AI.');
        }
        if ($probeProvider) {
            // Explicit operator opt-in: this makes one billable outbound OpenAI request.
            if (!$configured || !extension_loaded('curl')) $record('live_provider', false, 'Configure OpenAI and ext-curl first.');
            else {
                $key = '';
                try {
                    $state = $settings->status();
                    $key = $settings->key();
                    $reply = (new OpenAiClient())->complete($key, $state['model'], [
                        ['role' => 'user', 'content' => 'Respond with the word READY only.'],
                    ], 128);
                    $record('live_provider', trim($reply['text']) !== '' && $reply['usage']['input_tokens'] > 0, 'Provider responded with billed token usage.');
                } catch (\Throwable) {
                    $record('live_provider', false, 'OpenAI provider probe failed; check connectivity, credentials and billing.');
                } finally { if ($key !== '' && function_exists('sodium_memzero')) sodium_memzero($key); }
            }
        }
        return ['ok' => array_all($checks, static fn (array $check): bool => $check['status'] === 'pass'), 'version' => $version, 'checks' => $checks];
    }
}
