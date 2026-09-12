<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

final readonly class DocsVerifier
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function verify(): array
    {
        $required = [
            'README.md',
            'CHANGELOG.md',
            'LICENSE',
            'NOTICE',
            'SECURITY.md',
            'CONTRIBUTING.md',
            'CODE_OF_CONDUCT.md',
            'TRADEMARKS.md',
            'THIRD_PARTY_NOTICES.md',
            'VERSION',
            'api/openapi.yaml',
        ];
        $checks = [];
        foreach ($required as $file) {
            $path = $this->rootPath . '/' . $file;
            if (!is_file($path)) {
                $checks[] = CheckResult::fail('docs', $file, 'Файл отсутствует.');
                continue;
            }
            $size = filesize($path) ?: 0;
            if ($size === 0) {
                $checks[] = CheckResult::fail('docs', $file, 'Файл пустой.');
                continue;
            }
            $checks[] = CheckResult::pass('docs', $file, 'Файл присутствует.', ['bytes' => $size]);
        }

        $readme = $this->read('README.md');
        foreach (['core/', 'admin/', 'api/openapi.yaml', 'bin/cajeer'] as $needle) {
            $checks[] = str_contains($readme, $needle)
                ? CheckResult::pass('docs', 'readme.' . trim($needle, '/'), 'README содержит актуальную ссылку на ' . $needle)
                : CheckResult::warn('docs', 'readme.' . trim($needle, '/'), 'README не содержит явного упоминания ' . $needle);
        }

        $license = $this->read('LICENSE');
        $checks[] = str_contains($license, 'Apache License') && str_contains($license, 'Version 2.0')
            ? CheckResult::pass('docs', 'license.apache_2_0', 'LICENSE содержит Apache License 2.0.')
            : CheckResult::fail('docs', 'license.apache_2_0', 'LICENSE не похож на полный Apache License 2.0.');

        $changelog = $this->read('CHANGELOG.md');
        $checks[] = str_contains($changelog, '1.1.1')
            ? CheckResult::pass('docs', 'changelog.1.1.1', 'CHANGELOG содержит запись 1.1.1.')
            : CheckResult::fail('docs', 'changelog.1.1.1', 'CHANGELOG не содержит запись 1.1.1.');

        return $checks;
    }

    private function read(string $file): string
    {
        $path = $this->rootPath . '/' . $file;
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
