<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

use CajeerEngine\Template\TemplateCompiler;

final readonly class TemplateSandboxAuditor
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function audit(): array
    {
        $checks = [];
        $dir = $this->rootPath . '/templates';
        if (!is_dir($dir)) {
            return [CheckResult::fail('templates', 'templates.dir', 'Директория templates отсутствует.')];
        }
        $files = $this->files($dir, 'cjr');
        $checks[] = count($files) > 0
            ? CheckResult::pass('templates', 'templates.present', 'Найдены .cjr шаблоны.', ['count' => count($files)])
            : CheckResult::fail('templates', 'templates.present', 'Не найдены .cjr шаблоны.');

        $dangerous = ['<?php', '<?=', '<? ', '<?', 'shell_exec', 'passthru', 'system(', 'exec(', 'proc_open', 'popen', 'eval(', 'assert(', 'include', 'require', '`'];
        $compiler = new TemplateCompiler();
        foreach ($files as $file) {
            $relative = str_replace($this->rootPath . '/', '', $file);
            $content = (string) file_get_contents($file);
            $found = [];
            foreach ($dangerous as $needle) {
                if (stripos($content, $needle) !== false) {
                    $found[] = $needle;
                }
            }
            if ($found !== []) {
                $checks[] = CheckResult::fail('templates', 'sandbox.' . $relative, 'Шаблон содержит запрещённые конструкции.', ['file' => $relative, 'found' => $found]);
                continue;
            }
            try {
                $compiled = $compiler->compileDocument($content);
                $checks[] = is_string($compiled['template'] ?? null)
                    ? CheckResult::pass('templates', 'sandbox.' . $relative, 'Шаблон проходит безопасную компиляцию.', ['file' => $relative])
                    : CheckResult::fail('templates', 'sandbox.' . $relative, 'Шаблон не вернул compiled template.', ['file' => $relative]);
            } catch (\Throwable $e) {
                $checks[] = CheckResult::fail('templates', 'sandbox.' . $relative, 'Шаблон не прошёл безопасную компиляцию.', ['file' => $relative, 'error' => $e->getMessage()]);
            }
        }

        foreach (['core/Template/CajeerTemplateEngine.php', 'core/Template/TemplateCompiler.php', 'core/Template/TemplateExpressionEvaluator.php', 'core/Template/TemplateSandbox.php'] as $file) {
            $checks[] = is_file($this->rootPath . '/' . $file)
                ? CheckResult::pass('templates', 'engine.' . basename($file, '.php'), 'Файл шаблонизатора присутствует.')
                : CheckResult::fail('templates', 'engine.' . basename($file, '.php'), 'Файл шаблонизатора отсутствует.');
        }

        return $checks;
    }

    /** @return list<string> */
    private function files(string $dir, string $extension): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }
}
