<?php

declare(strict_types=1);

namespace CajeerEngine\StaticExport;

final readonly class StaticExporter
{
    public function __construct(private string $targetPath)
    {
    }

    /** @param iterable<array{path:string,html:string}> $pages */
    public function export(iterable $pages): int
    {
        $count = 0;
        foreach ($pages as $page) {
            $file = rtrim($this->targetPath, '/') . '/' . ltrim($page['path'], '/');
            if (str_ends_with($file, '/')) {
                $file .= 'index.html';
            }
            if (!str_ends_with($file, '.html')) {
                $file = rtrim($file, '/') . '/index.html';
            }
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, $page['html']);
            $count++;
        }

        return $count;
    }
}
