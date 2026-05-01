<?php

declare(strict_types=1);

namespace Cajeer\Database;

use PDO;

final class TransactionManager
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
