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
        $lines[] = '# HELP cajeer_disk_free_bytes Free disk space';
        $lines[] = '# TYPE cajeer_disk_free_bytes gauge';
        $lines[] = 'cajeer_disk_free_bytes ' . (string) (disk_free_space($basePath) ?: 0);
        return implode("\n", $lines) . "\n";
    }
}
