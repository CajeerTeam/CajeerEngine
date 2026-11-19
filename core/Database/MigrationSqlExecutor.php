<?php

declare(strict_types=1);

namespace CajeerEngine\Database;

use PDO;

/** Executes the published SQL migrations without relying on PDO multi-statement mode.
 *
 * MySQL 8.4 does not accept ADD COLUMN IF NOT EXISTS (MariaDB does). Historic
 * migrations 0005/0007 use it and must retain their original SHA-256 digests.
 * Inspecting information_schema lets both servers apply the same immutable SQL.
 */
final class MigrationSqlExecutor
{
    public static function execute(PDO $pdo, string $driver, string $sql): void
    {
        foreach (self::statements($sql) as $statement) {
            if ($driver === 'mysql') {
                $statement = self::mysqlConditionalColumn($pdo, $statement);
                if ($statement === null) {
                    continue;
                }
            }
            $pdo->exec($statement);
        }
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $result = [];
        $buffer = '';
        $state = 'normal';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($state === 'line') {
                if ($ch === "\n" || $ch === "\r") {
                    $state = 'normal';
                    $buffer .= ' ';
                }
                continue;
            }
            if ($state === 'block') {
                if ($ch === '*' && $next === '/') {
                    $state = 'normal';
                    $i++;
                    $buffer .= ' ';
                }
                continue;
            }
            if ($state !== 'normal') {
                $buffer .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                } elseif ($ch === $state) {
                    if ($next === $state) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $state = 'normal';
                    }
                }
                continue;
            }
            if ($ch === '-' && $next === '-' && ($i + 2 === $length || ctype_space($sql[$i + 2]))) {
                $state = 'line';
                $i++;
                continue;
            }
            if ($ch === '#') {
                $state = 'line';
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $state = 'block';
                $i++;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $state = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                if (trim($buffer) !== '') {
                    $result[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if ($state !== 'normal' && $state !== 'line') {
            throw new \RuntimeException('Unterminated SQL string/comment in migration');
        }
        if (trim($buffer) !== '') {
            $result[] = trim($buffer);
        }
        return $result;
    }

    private static function mysqlConditionalColumn(PDO $pdo, string $sql): ?string
    {
        if (!preg_match('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i', $sql)) {
            return $sql;
        }
        if (!preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+(.+)$/is', $sql, $matches)) {
            throw new \RuntimeException('Unsupported conditional MySQL migration statement');
        }
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $check->execute(['table' => $matches[1], 'column' => $matches[2]]);
        if ((int) $check->fetchColumn() > 0) {
            return null;
        }
        return sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $matches[1], $matches[2], $matches[3]);
    }
}
