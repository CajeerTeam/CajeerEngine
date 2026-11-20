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
        $record('release_version', version_compare($version, '1.7.0', '>=') && $this->config->string('app.version') === $version, 'Release and runtime versions must match.');
        if ($requireProduction) {
            $record('production_environment', strtolower((string)getenv('APP_ENV')) === 'production', 'Set APP_ENV=production.');
            $record('debug_disabled', !filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN), 'Disable APP_DEBUG.');
            $url = (string)(getenv('APP_URL') ?: $this->config->string('app.url'));
            $record('https_origin', preg_match('~^https://[^/\s?#]+(?:/)?$~iD', $url) === 1, 'Use a single HTTPS APP_URL origin.');
        }
        $record('php_runtime', version_compare(PHP_VERSION, '8.4.1', '>='), 'PHP 8.4.1 or newer is required.');
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
            $record('model_pricing', $state['model_pricing'] !== null, 'Configure verified model input, cached and output prices before running AI.');
            $record('cost_limits', (int)$state['global_monthly_limit_cents'] > 0 && (int)$state['user_monthly_limit_cents'] > 0, 'Set global and per-user hard spending caps.');
            if ($configured) {
                $key = $settings->key();
                try { $record('credential_decryption', $key !== '', 'OpenAI key must be decryptable by this PHP worker.'); }
                finally { if (function_exists('sodium_memzero')) sodium_memzero($key); }
            }
        } catch (\Throwable) {
            $record('credential_decryption', false, 'OpenAI configuration or encrypted key cannot be read.');
        }
        $tables = [
            'ce_ai_conversations', 'ce_ai_messages', 'ce_ai_tool_calls', 'ce_ai_actions', 'ce_ai_usage', 'ce_ai_editor_jobs',
            'ce_ai_cost_balances', 'ce_ai_cost_reservations', 'ce_ai_cost_events',
            'ce_ai_editor_proposals', 'ce_ai_translation_proposals', 'ce_ai_translation_glossary',
            'ce_kb_categories', 'ce_kb_documents', 'ce_kb_chunks', 'ce_kb_terms',
            'ce_ai_kb_conversations', 'ce_ai_kb_messages', 'ce_support_tickets', 'ce_support_messages',
            'ce_ai_automations', 'ce_ai_automation_runs',
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
            $record('ai_sql_schema', false, 'Apply all AI migrations through 0043 before enabling the assistant.');
        }
        if ($probeProvider) {
            // Explicit operator opt-in: this makes one billable outbound OpenAI request.
            if (!$configured || !extension_loaded('curl')) $record('live_provider', false, 'Configure OpenAI and ext-curl first.');
            else {
                $key = '';
                $cost = new AiCostControl($this->db, $settings);
                $raw = bin2hex(random_bytes(16));
                $job = substr($raw,0,8).'-'.substr($raw,8,4).'-'.substr($raw,12,4).'-'.substr($raw,16,4).'-'.substr($raw,20);
                $reserved = false; $reply = null;
                try {
                    $state = $settings->status();
                    $this->db->transaction(static function (PDO $pdo) use ($cost, $job, $state): void {
                        $cost->hold($pdo, $job, 'system:acceptance', 'acceptance', $state, 2048, time()+180);
                    });
                    $reserved = true;
                    $key = $settings->key();
                    $reply = (new OpenAiClient())->complete($key, $state['model'], [
                        ['role' => 'user', 'content' => 'Respond with the word READY only.'],
                    ], 128);
                    $this->db->transaction(static fn(PDO $pdo) => $cost->settle($pdo, $job, $reply['usage']));
                    $reserved = false;
                    $record('live_provider', trim($reply['text']) !== '' && $reply['usage']['input_tokens'] > 0, 'Provider responded with accounted token usage.');
                } catch (\Throwable) {
                    $record('live_provider', false, 'OpenAI provider probe failed; check connectivity, spending cap, credentials and billing.');
                } finally {
                    if ($reserved) {
                        try { $this->db->transaction(static function(PDO $pdo) use ($cost, $job, $reply): void {
                            if (is_array($reply['usage'] ?? null)) $cost->settle($pdo, $job, $reply['usage']);
                            else $cost->release($pdo, $job);
                        }); } catch (\Throwable) { /* Expired provider reservations are reconciled conservatively. */ }
                    }
                    if ($key !== '' && function_exists('sodium_memzero')) sodium_memzero($key);
                }
            }
        }
        return ['ok' => array_all($checks, static fn (array $check): bool => $check['status'] === 'pass'), 'version' => $version, 'checks' => $checks];
    }
}
