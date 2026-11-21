<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** Automatic read-only discovery run by the already deployed CMS scheduler. */
final readonly class AutomaticReleaseDiscovery
{
    public function __construct(private ConfigRepository $config, private ?GitHubReleaseMonitor $monitor = null)
    {
    }

    public function enabled(): bool
    {
        return $this->config->bool('updates.core.auto_check_enabled', true);
    }

    public function intervalSeconds(): int
    {
        return max(3600, min(604800, $this->config->int('updates.core.auto_check_interval_seconds', 21600)));
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        if (!$this->enabled()) return ['ok' => true, 'skipped' => 'disabled'];
        $result = (new UpdateResolver($this->config->get('updates', []), $this->config->rootPath(), $this->monitor))
            ->check($this->config->string('app.version', '0.0.0'));
        // An offline bundled catalog is useful for diagnostics, but it is NOT a
        // successful GitHub poll; retry on the next slot and expose degraded state.
        return [
            'ok' => $result['github_reachable'] === true,
            'current_version' => $result['current_version'],
            'channel' => $result['channel'],
            'update_available' => $result['update_available'],
            'latest_version' => $result['latest']['version'] ?? null,
            'metadata_status' => $result['metadata_status'],
            'metadata_fetched_at' => $result['metadata_fetched_at'],
        ] + ($result['github_reachable'] ? [] : ['error' => 'GitHub release metadata unavailable']);
    }
}
