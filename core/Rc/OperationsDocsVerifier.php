<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

/**
 * Checks the executable operational contract, not just the existence of markdown.
 * Offline by design: safe for PR CI, read-only on deployed installations.
 */
final readonly class OperationsDocsVerifier
{
    public const RUNBOOKS = [
        'docs/operations-handbook-1.8.8.md',
        'docs/operations-deployment-1.8.8.md',
        'docs/operations-monitoring-1.8.8.md',
        'docs/operations-recovery-1.8.8.md',
        'docs/operations-security-1.8.8.md',
        'docs/operations-incidents-1.8.8.md',
        'docs/operations-acceptance-1.8.8.md',
    ];

    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function verify(): array
    {
        $checks = [];
        $version = trim((string) @file_get_contents($this->rootPath . '/VERSION'));
        $env = $this->envKeys();
        $commands = $this->commandNames();
        if ($version === '') {
            return [CheckResult::fail('operations-docs', 'version', 'Missing VERSION.')];
        }
        if ($commands === []) {
            return [CheckResult::fail('operations-docs', 'cli.registry', 'Unable to discover CLI commands.')];
        }
        foreach (self::RUNBOOKS as $relative) {
            $path = $this->rootPath . '/' . $relative;
            if (!is_file($path) || !is_readable($path)) {
                $checks[] = CheckResult::fail('operations-docs', $relative, 'Required runbook is missing.');
                continue;
            }
            $content = file_get_contents($path);
            if (!is_string($content) || strlen(trim($content)) < 1000 || !str_contains($content, '# CajeerEngine ' . $version)) {
                $checks[] = CheckResult::fail('operations-docs', $relative, 'Runbook must contain substantive current-version procedures.');
                continue;
            }
            $checks[] = CheckResult::pass('operations-docs', $relative, 'Current-version runbook exists and is substantive.');
            // Links are resolved relative to the file, never over the network.
            if (preg_match_all('/\]\(([^)]+)\)/', $content, $matches) !== false) {
                foreach ($matches[1] as $link) {
                    $link = trim($link);
                    $destination = preg_split('/\s+"/', $link, 2)[0];
                    $destination = rawurldecode(explode('#', $destination, 2)[0]);
                    if ($destination === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/)~i', $destination)) {
                        continue;
                    }
                    $candidate = realpath(dirname($path) . '/' . $destination);
                    $root = realpath($this->rootPath);
                    if ($candidate === false || $root === false || !str_starts_with($candidate, $root . '/')) {
                        $checks[] = CheckResult::fail('operations-docs', $relative . ':link:' . $destination, 'Local link is missing or leaves repository.');
                    }
                }
            }
            // Validate examples in shell fences against actual CLI command registrations.
            if (preg_match_all('/```(?:bash|sh)\s*\n(.*?)```/s', $content, $fences) !== false) {
                foreach ($fences[1] as $fence) {
                    if (preg_match_all('/^\s*(?:\$\s*)?php\s+bin\/cajeer\s+([a-z][a-z0-9:_-]*)\b/m', $fence, $calls) === false) {
                        continue;
                    }
                    foreach ($calls[1] as $command) {
                        if (!isset($commands[$command])) {
                            $checks[] = CheckResult::fail('operations-docs', $relative . ':command:' . $command, 'Unregistered CLI command in shell runbook.');
                        }
                    }
                }
            }
            // Every dotenv snippet must use a real configuration variable.
            if (preg_match_all('/```(?:dotenv|env)\s*\n(.*?)```/s', $content, $blocks) !== false) {
                foreach ($blocks[1] as $block) {
                    if (preg_match_all('/^\s*([A-Z][A-Z0-9_]*)\s*=/m', $block, $keys) === false) {
                        continue;
                    }
                    foreach ($keys[1] as $key) {
                        if (!isset($env[$key])) {
                            $checks[] = CheckResult::fail('operations-docs', $relative . ':env:' . $key, 'Unknown .env.example key in configuration example.');
                        }
                    }
                }
            }
        }
        return $checks;
    }

    /** @return array<string, true> */
    private function envKeys(): array
    {
        $env = (string) @file_get_contents($this->rootPath . '/.env.example');
        preg_match_all('/^\s*([A-Z][A-Z0-9_]*)\s*=/m', $env, $matches);
        return array_fill_keys($matches[1], true);
    }

    /** @return array<string, true> */
    private function commandNames(): array
    {
        $names = [];
        foreach (glob($this->rootPath . '/core/Console/Command/*.php') ?: [] as $path) {
            $code = (string) file_get_contents($path);
            if (preg_match_all('/#\[AsCommand\(name:\s*[\'\"]([^\'\"]+)[\'\"]/', $code, $matches) !== false) {
                foreach ($matches[1] as $name) $names[$name] = true;
            }
        }
        $ops = (string) @file_get_contents($this->rootPath . '/core/Console/OperationsCli.php');
        if (preg_match('/const COMMANDS\s*=\s*\[([^]]+)\]/s', $ops, $matched) === 1) {
            preg_match_all('/[\'\"]([a-z][a-z0-9:_-]+)[\'\"]/', $matched[1], $matches);
            foreach ($matches[1] as $name) $names[$name] = true;
        }
        $queue = (string) @file_get_contents($this->rootPath . '/core/Console/QueueCli.php');
        if (preg_match('/const COMMANDS\s*=\s*\[([^]]+)\]/s', $queue, $matched) === 1) {
            preg_match_all('/[\'\"]([a-z][a-z0-9:_-]+)[\'\"]/', $matched[1], $matches);
            foreach ($matches[1] as $name) $names[$name] = true;
        }
        $front = (string) @file_get_contents($this->rootPath . '/bin/cajeer');
        if (preg_match('/\[\s*[\'\"]ops:check[\'\"].*?\]/s', $front, $matched) === 1) {
            preg_match_all('/[\'\"]([a-z][a-z0-9:_-]+)[\'\"]/', $matched[0], $matches);
            foreach ($matches[1] as $name) $names[$name] = true;
        }
        return $names;
    }
}
