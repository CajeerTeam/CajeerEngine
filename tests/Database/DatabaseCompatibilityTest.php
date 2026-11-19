<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Database;

use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Database\MigrationSqlExecutor;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseCompatibilityTest extends TestCase
{
    public function testAllPublishedSQLiteMigrationsAreAppliedAndIdempotent(): void
    {
        $this->requireSQLite();
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $runner = new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations');
        $expected = count(glob(dirname(__DIR__, 2) . '/migrations/sqlite/*.sql') ?: []);
        self::assertSame($expected, $runner->run('sqlite')['applied']);
        self::assertSame(0, $runner->run('sqlite')['applied']);
        $status = $runner->status('sqlite');
        self::assertCount($expected, $status['applied']);
        self::assertSame([], $status['failed']);
        self::assertSame([], $status['pending']);
        self::assertSame([], $status['integrity_errors']);
    }

    public function testFailedMigrationRollsBackAndCanBeRepaired(): void
    {
        $this->requireSQLite();
        $dir = sys_get_temp_dir() . '/ce-db-compat-' . bin2hex(random_bytes(8));
        mkdir($dir . '/sqlite', 0700, true);
        try {
            file_put_contents($dir . '/sqlite/0001_initial.sql', 'CREATE TABLE working (id INTEGER PRIMARY KEY);');
            file_put_contents($dir . '/sqlite/0002_new.sql', 'CREATE TABLE working (id INTEGER PRIMARY KEY);');
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $runner = new MigrationRunner($pdo, $dir);
            try {
                $runner->run('sqlite');
                self::fail('Conflicting migration must fail');
            } catch (\PDOException $e) {
                self::assertNotSame('', $e->getMessage());
            }
            self::assertCount(1, $runner->status('sqlite')['failed']);
            file_put_contents($dir . '/sqlite/0002_new.sql', 'CREATE TABLE recovered (id INTEGER PRIMARY KEY);');
            self::assertSame(1, $runner->run('sqlite')['applied']);
            self::assertSame([], $runner->status('sqlite')['failed']);
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='recovered'")->fetchColumn());
        } finally {
            @unlink($dir . '/sqlite/0001_initial.sql');
            @unlink($dir . '/sqlite/0002_new.sql');
            @rmdir($dir . '/sqlite');
            @rmdir($dir);
        }
    }

    public function testChecksumsMissingFilesAndDialectMismatchAreDetected(): void
    {
        $this->requireSQLite();
        $dir = sys_get_temp_dir() . '/ce-db-compat-' . bin2hex(random_bytes(8));
        mkdir($dir . '/sqlite', 0700, true);
        try {
            $file = $dir . '/sqlite/0001_initial.sql';
            file_put_contents($file, 'CREATE TABLE original (id INTEGER PRIMARY KEY);');
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $runner = new MigrationRunner($pdo, $dir);
            $runner->run('sqlite');
            try {
                $runner->run('mysql');
                self::fail('Mismatch must fail before SQL execution');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('does not match', $e->getMessage());
            }
            file_put_contents($file, 'CREATE TABLE changed (id INTEGER PRIMARY KEY);');
            self::assertSame('checksum_mismatch', $runner->status('sqlite')['integrity_errors'][0]['reason']);
            try {
                $runner->run('sqlite');
                self::fail('Changed applied SQL must block execution');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('checksum mismatch', $e->getMessage());
            }
            unlink($file);
            self::assertSame('file_missing', $runner->status('sqlite')['integrity_errors'][0]['reason']);
        } finally {
            @unlink($dir . '/sqlite/0001_initial.sql');
            @rmdir($dir . '/sqlite');
            @rmdir($dir);
        }
    }

    public function testSqlParserRespectsQuotesAndComments(): void
    {
        $sql = "-- a ; comment\nINSERT INTO x VALUES ('a; b', 'it''s; quoted'); /* ; */ SELECT `a;b` FROM x;";
        $parts = MigrationSqlExecutor::statements($sql);
        self::assertCount(2, $parts);
        self::assertStringContainsString("'a; b'", $parts[0]);
        self::assertStringStartsWith('SELECT', $parts[1]);
    }

    private function requireSQLite(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for migration integration tests');
        }
    }
}
