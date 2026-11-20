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
                $statement = self::mysqlAiToolCallForeignKeys($pdo, $statement);
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

    /**
     * MySQL/MariaDB foreign keys require compatible character sets and collations.
     * Historical migration 0031 lets the server choose the charset's default
     * collation for ce_ai_conversations and ce_ai_messages. Migration 0037 has
     * no explicit collation and therefore inherits the database default, which
     * can differ (for example, on MariaDB 10.11). Keep historical migration
     * bytes/checksums intact and match each referenced column at execution.
     */
    private static function mysqlAiToolCallForeignKeys(PDO $pdo, string $sql): string
    {
        if (preg_match('/^CREATE\s+TABLE\s+ce_ai_tool_calls\s*\(/i', $sql) !== 1) {
            return $sql;
        }
        $metadata = $pdo->prepare('SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        foreach (['conversation_id' => 'ce_ai_conversations', 'assistant_message_id' => 'ce_ai_messages'] as $column => $parent) {
            $metadata->execute(['table' => $parent, 'column' => 'id']);
            $definition = $metadata->fetch(PDO::FETCH_ASSOC);
            $type = is_array($definition) ? strtolower((string) ($definition['COLUMN_TYPE'] ?? '')) : '';
            $charset = is_array($definition) ? (string) ($definition['CHARACTER_SET_NAME'] ?? '') : '';
            $collation = is_array($definition) ? (string) ($definition['COLLATION_NAME'] ?? '') : '';
            if (preg_match('/^(?:char|varchar)\(36\)$/D', $type) !== 1
                || preg_match('/^[a-zA-Z0-9_]+$/D', $charset) !== 1
                || preg_match('/^[a-zA-Z0-9_]+$/D', $collation) !== 1) {
                throw new \RuntimeException('Cannot resolve compatible AI tool call foreign key metadata');
            }
            $columnType = strtoupper($type) . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation;
            $sql = preg_replace_callback(
                '/(\b' . $column . '\s+)VARCHAR\s*\(\s*36\s*\)(\s+NOT\s+NULL)/i',
                static fn (array $match): string => $match[1] . $columnType . $match[2],
                $sql,
                1,
                $replacements,
            );
            if ($sql === null || $replacements !== 1) {
                throw new \RuntimeException('Unexpected AI tool call foreign key definition');
            }
        }
        return $sql;
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
