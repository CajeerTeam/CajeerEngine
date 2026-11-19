<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

/** Parse every shipped PHP entrypoint in an isolated PHP CLI process. */
final readonly class PhpSyntaxVerifier
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function verify(): array
    {
        if (!function_exists('proc_open')) {
            return [CheckResult::fail('syntax', 'runtime.proc_open', 'PHP CLI syntax checks require proc_open; the release must fail closed.')];
        }
        $checks = [];
        foreach ($this->files() as $path) {
            $relative = substr($path, strlen(rtrim($this->rootPath, '/\\')) + 1);
            // Array-form proc_open bypasses shell interpolation and handles spaces in paths.
            $process = @proc_open(
                [PHP_BINARY, '-l', $path],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->rootPath,
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                $checks[] = CheckResult::fail('syntax', $relative, 'Unable to start PHP CLI lint.');
                continue;
            }
            fclose($pipes[0]);
            $output = trim((string) stream_get_contents($pipes[1]));
            $error = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $checks[] = $exitCode === 0
                ? CheckResult::pass('syntax', $relative, 'PHP -l passed.')
                : CheckResult::fail('syntax', $relative, 'PHP -l failed.', ['exit_code' => $exitCode, 'diagnostic' => substr($error !== '' ? $error : $output, 0, 1200)]);
        }
        return $checks;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];
        foreach (['core', 'public', 'bin', 'installer', 'tools', 'tests', 'deploy', 'packages'] as $directory) {
            $path = $this->rootPath . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry instanceof \SplFileInfo && $entry->isFile() && !$entry->isLink() && ($entry->getExtension() === 'php' || $entry->getFilename() === 'cajeer')) {
                    $files[] = $entry->getPathname();
                }
            }
        }
        foreach (glob($this->rootPath . '/*.php') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                $files[] = $path;
            }
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);
        return $files;
    }
}
