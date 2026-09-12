<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

final readonly class MigrationVerifier
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function verify(): array
    {
        $checks = [];
        foreach (['pgsql', 'mysql', 'sqlite'] as $driver) {
            $dir = $this->rootPath . '/migrations/' . $driver;
            if (!is_dir($dir)) {
                $checks[] = CheckResult::fail('migrations', $driver . '.dir', 'Директория миграций отсутствует.', ['path' => $dir]);
                continue;
            }
            $files = glob($dir . '/*.sql') ?: [];
            sort($files, SORT_STRING);
            $checks[] = count($files) >= 5
                ? CheckResult::pass('migrations', $driver . '.count', 'Найдены миграции ' . $driver . '.', ['count' => count($files)])
                : CheckResult::fail('migrations', $driver . '.count', 'Недостаточно миграций ' . $driver . '.', ['count' => count($files)]);

            $previous = 0;
            $names = [];
            foreach ($files as $file) {
                $base = basename($file);
                $names[] = $base;
                if (!preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', $base, $m)) {
                    $checks[] = CheckResult::fail('migrations', $driver . '.' . $base, 'Имя миграции не соответствует формату 0001_name.sql.');
                    continue;
                }
                $num = (int) $m[1];
                if ($num <= $previous) {
                    $checks[] = CheckResult::fail('migrations', $driver . '.' . $base, 'Нарушен порядок номеров миграций.');
                }
                $previous = $num;
                $content = trim((string) file_get_contents($file));
                if ($content === '') {
                    $checks[] = CheckResult::fail('migrations', $driver . '.' . $base, 'Миграция пустая.');
                } elseif (!str_contains(strtoupper($content), 'CREATE') && !str_contains(strtoupper($content), 'ALTER')) {
                    $checks[] = CheckResult::warn('migrations', $driver . '.' . $base, 'Миграция не содержит CREATE/ALTER.');
                } else {
                    $checks[] = CheckResult::pass('migrations', $driver . '.' . $base, 'Миграция выглядит применимой.', ['sha256' => hash_file('sha256', $file)]);
                }
            }
            $checks[] = count($names) === count(array_unique($names))
                ? CheckResult::pass('migrations', $driver . '.unique', 'Имена миграций уникальны.')
                : CheckResult::fail('migrations', $driver . '.unique', 'Есть дубли имён миграций.');
        }
        return $checks;
    }
}
