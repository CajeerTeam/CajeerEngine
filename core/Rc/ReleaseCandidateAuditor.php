<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

final readonly class ReleaseCandidateAuditor
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $checks = array_merge(
            (new VersionVerifier($this->rootPath))->verify(),
            (new DocsVerifier($this->rootPath))->verify(),
            (new OpenApiLinter($this->rootPath))->lint(),
            (new MigrationVerifier($this->rootPath))->verify(),
            (new SecurityAuditor($this->rootPath))->audit(),
            (new TemplateSandboxAuditor($this->rootPath))->audit(),
            (new ReleaseVerifier($this->rootPath))->verify(),
            $this->syntaxChecks(),
        );

        $serialized = array_map(static fn (CheckResult $check): array => $check->toArray(), $checks);
        $summary = $this->summary($serialized);
        return [
            'ready' => $summary['fail'] === 0,
            'summary' => $summary,
            'checks' => $serialized,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function summaryOnly(): array
    {
        $result = $this->run();
        unset($result['checks']);
        return $result;
    }

    /** @return list<CheckResult> */
    private function syntaxChecks(): array
    {
        $checks = [];
        foreach ($this->phpFiles() as $file) {
            $relative = str_replace($this->rootPath . '/', '', $file);
            $tokens = @token_get_all((string) file_get_contents($file));
            if (!is_array($tokens) || count($tokens) === 0) {
                $checks[] = CheckResult::fail('syntax', $relative, 'PHP-файл не удалось токенизировать.', ['file' => $relative]);
            } else {
                $checks[] = CheckResult::pass('syntax', $relative, 'PHP-файл токенизируется.', ['file' => $relative]);
            }
        }
        return $checks;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $dirs = ['core', 'public', 'bin', 'installer', 'tools'];
        $files = [];
        foreach ($dirs as $dir) {
            $path = $this->rootPath . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $name = $file->getFilename();
                    if ($file->getExtension() === 'php' || $name === 'cajeer') {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /** @param list<array{status:string,group:string,name:string,message:string,context:array<string,mixed>}> $checks @return array<string, mixed> */
    private function summary(array $checks): array
    {
        $summary = ['total' => count($checks), 'pass' => 0, 'warn' => 0, 'fail' => 0, 'groups' => []];
        foreach ($checks as $check) {
            $status = $check['status'];
            $group = $check['group'];
            if (!isset($summary[$status])) {
                $summary[$status] = 0;
            }
            $summary[$status]++;
            if (!isset($summary['groups'][$group])) {
                $summary['groups'][$group] = ['total' => 0, 'pass' => 0, 'warn' => 0, 'fail' => 0];
            }
            $summary['groups'][$group]['total']++;
            if (isset($summary['groups'][$group][$status])) {
                $summary['groups'][$group][$status]++;
            }
        }
        return $summary;
    }
}
