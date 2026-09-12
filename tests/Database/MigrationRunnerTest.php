<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Database;

use CajeerEngine\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testFailedMigrationCanBeRepairedAndRetried(): void
    {
        $dir = sys_get_temp_dir() . '/ce-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir . '/sqlite', 0777, true);
        file_put_contents($dir . '/sqlite/0001_init.sql', 'CREATE TABLE example (id INT PRIMARY KEY);');
        $pdo = new PDO('sqlite::memory:');
        $runner = new MigrationRunner($pdo, $dir);
        $result = $runner->run('sqlite');
        self::assertSame(1, $result['applied']);
        $status = $runner->status('sqlite');
        self::assertSame(0, count($status['failed']));
    }
}
