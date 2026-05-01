<?php

declare(strict_types=1);

namespace Cajeer\System;

final class MetricsCollector
{
    public function collect(string $basePath): string
    {
        $lines = [];
        $lines[] = '# HELP cajeer_storage_writable Storage directory writable flag';
        $lines[] = '# TYPE cajeer_storage_writable gauge';
        $lines[] = 'cajeer_storage_writable ' . (is_writable($basePath . '/storage') ? '1' : '0');
        $lines[] = '# HELP cajeer_cache_writable Cache directory writable flag';
        $lines[] = '# TYPE cajeer_cache_writable gauge';
        $lines[] = 'cajeer_cache_writable ' . (is_writable($basePath . '/storage/cache') ? '1' : '0');
        $lines[] = '# HELP cajeer_installed Installed lock flag';
        $lines[] = '# TYPE cajeer_installed gauge';
        $lines[] = 'cajeer_installed ' . (is_file($basePath . '/storage/installed.lock') ? '1' : '0');
        $lines[] = '# HELP cajeer_disk_free_bytes Free disk space';
        $lines[] = '# TYPE cajeer_disk_free_bytes gauge';
        $lines[] = 'cajeer_disk_free_bytes ' . (string) (disk_free_space($basePath) ?: 0);
        return implode("\n", $lines) . "\n";
    }
}
