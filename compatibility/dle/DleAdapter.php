<?php

declare(strict_types=1);

namespace Cajeer\Compatibility\Dle;

use Cajeer\Compatibility\Dle\Template\DleTemplateParser;

final class DleAdapter
{
    public function __construct(private readonly DleTemplateParser $parser = new DleTemplateParser()) {}

    public function renderTemplateFile(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException("DLE-шаблон не найден: {$file}");
        }
        return $this->parser->renderString(file_get_contents($file) ?: '', $data);
    }

    public function scanTemplate(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException("DLE-шаблон не найден: {$file}");
        }
        $template = file_get_contents($file) ?: '';
        return [
            'tags' => $this->parser->detectTags($template),
            'report' => $this->parser->compatibilityReport($template),
        ];
    }
}
