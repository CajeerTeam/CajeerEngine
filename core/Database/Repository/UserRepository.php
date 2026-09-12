<?php

declare(strict_types=1);

namespace CajeerEngine\Database\Repository;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Support\Uuid;

final readonly class UserRepository
{
    public function __construct(private DatabaseManager $database, private RoleRepository $roles, private ?PasswordHasher $passwordHasher = null)
    {
    }

    public function count(): int
    {
        return (int) $this->database->connection()->query('SELECT COUNT(*) FROM ce_users')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->database->connection()->query('SELECT id, email, name, status, two_factor_enabled, last_login_at, disabled_at, created_at, updated_at FROM ce_users ORDER BY created_at DESC')->fetchAll();
        return array_map(fn (array $row): array => $this->normalize($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT id, email, name, status, two_factor_enabled, last_login_at, disabled_at, created_at, updated_at FROM ce_users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->withRoles($this->normalize($row)) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT id, email, name, status, two_factor_enabled, last_login_at, disabled_at, created_at, updated_at FROM ce_users WHERE email = :email');
        $stmt->execute(['email' => mb_strtolower($email)]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->withRoles($this->normalize($row)) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByEmailForAuth(string $email): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT * FROM ce_users WHERE email = :email');
        $stmt->execute(['email' => mb_strtolower($email)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['two_factor_recovery_codes'] = $this->decodeList((string) ($row['two_factor_recovery_codes'] ?? '[]'));
        return $row;
    }

    /** @param list<string> $roles */
    public function create(string $email, string $name, string $passwordHash, array $roles = ['viewer']): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Некорректный email пользователя.');
        }
        $email = mb_strtolower($email);
        if ($this->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('Пользователь с таким email уже существует.');
        }
        $userId = Uuid::v4();
        $this->database->transaction(function () use ($userId, $email, $name, $passwordHash, $roles): void {
            $driver = $this->database->driver();
            $recovery = $driver === 'pgsql' ? "'[]'::jsonb" : ($driver === 'sqlite' ? "'[]'" : "JSON_ARRAY()");
            $sql = "INSERT INTO ce_users (id, email, name, password_hash, status, two_factor_enabled, two_factor_recovery_codes, created_at, updated_at) VALUES (:id, :email, :name, :password_hash, 'active', FALSE, {$recovery}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
            $stmt = $this->database->connection()->prepare($sql);
            $stmt->execute(['id' => $userId, 'email' => $email, 'name' => $name, 'password_hash' => $passwordHash]);
            $this->syncRoles($userId, $roles);
        });
        return $userId;
    }

    public function createAdmin(string $email, string $name, string $password): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Некорректный email администратора.');
        }
        $passwordHash = $this->passwordHasher !== null
            ? $this->passwordHasher->hash($password)
            : $this->fallbackPasswordHash($password);
        $email = mb_strtolower($email);
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            $this->syncRoles((string) $existing['id'], ['admin']);
            return (string) $existing['id'];
        }
        return $this->create($email, $name, $passwordHash, ['admin']);
    }

    /** @param array<string, mixed> $payload */
    public function update(string $id, array $payload): ?array
    {
        $user = $this->findById($id);
        if ($user === null) {
            return null;
        }
        $name = isset($payload['name']) ? trim((string) $payload['name']) : (string) $user['name'];
        $status = isset($payload['status']) ? (string) $payload['status'] : (string) ($user['status'] ?? 'active');
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new \InvalidArgumentException('Некорректный status пользователя.');
        }
        $stmt = $this->database->connection()->prepare('UPDATE ce_users SET name = :name, status = :status, disabled_at = CASE WHEN :status_check = :disabled THEN CURRENT_TIMESTAMP ELSE NULL END, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['id' => $id, 'name' => $name, 'status' => $status, 'status_check' => $status, 'disabled' => 'disabled']);
        if (isset($payload['roles']) && is_array($payload['roles'])) {
            $this->syncRoles($id, array_values(array_map('strval', $payload['roles'])));
        }
        return $this->findById($id);
    }

    public function disable(string $id): bool
    {
        $stmt = $this->database->connection()->prepare("UPDATE ce_users SET status = 'disabled', disabled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function markLogin(string $id): void
    {
        $stmt = $this->database->connection()->prepare('UPDATE ce_users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function permissionsForUser(string $userId): array
    {
        return $this->roles->permissionsForUser($userId);
    }

    /** @param list<string> $roleHandles */
    public function syncRoles(string $userId, array $roleHandles): void
    {
        $this->database->transaction(function () use ($userId, $roleHandles): void {
            $delete = $this->database->connection()->prepare('DELETE FROM ce_user_roles WHERE user_id = :user_id');
            $delete->execute(['user_id' => $userId]);
            $insert = $this->database->connection()->prepare('INSERT INTO ce_user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
            foreach (array_values(array_unique($roleHandles)) as $handle) {
                $roleId = $this->roles->findIdByHandle((string) $handle);
                if ($roleId === null) {
                    throw new \InvalidArgumentException('Роль не найдена: ' . $handle);
                }
                $insert->execute(['user_id' => $userId, 'role_id' => $roleId]);
            }
        });
    }

    /** @param list<string> $hashedRecoveryCodes */
    public function enableTwoFactor(string $userId, string $secret, array $hashedRecoveryCodes): void
    {
        $json = json_encode($hashedRecoveryCodes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $sql = $this->database->driver() === 'pgsql'
            ? 'UPDATE ce_users SET two_factor_enabled = TRUE, two_factor_secret = :secret, two_factor_recovery_codes = CAST(:codes AS jsonb), updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            : 'UPDATE ce_users SET two_factor_enabled = TRUE, two_factor_secret = :secret, two_factor_recovery_codes = :codes, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->database->connection()->prepare($sql);
        $stmt->execute(['id' => $userId, 'secret' => $secret, 'codes' => $json]);
    }

    public function disableTwoFactor(string $userId): void
    {
        $driver = $this->database->driver();
        $sql = $driver === 'pgsql'
            ? "UPDATE ce_users SET two_factor_enabled = FALSE, two_factor_secret = NULL, two_factor_recovery_codes = '[]'::jsonb, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            : ($driver === 'sqlite'
                ? "UPDATE ce_users SET two_factor_enabled = FALSE, two_factor_secret = NULL, two_factor_recovery_codes = '[]', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
                : "UPDATE ce_users SET two_factor_enabled = FALSE, two_factor_secret = NULL, two_factor_recovery_codes = JSON_ARRAY(), updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $this->database->connection()->prepare($sql)->execute(['id' => $userId]);
    }

    public function consumeRecoveryCode(string $userId, string $code): bool
    {
        $record = $this->findByIdWithRecoveryCodes($userId);
        if ($record === null) {
            return false;
        }
        $codes = $record['two_factor_recovery_codes'];
        if (!is_array($codes)) {
            return false;
        }
        foreach ($codes as $index => $hash) {
            if (is_string($hash) && password_verify($code, $hash)) {
                unset($codes[$index]);
                $json = json_encode(array_values($codes), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $sql = $this->database->driver() === 'pgsql'
                    ? 'UPDATE ce_users SET two_factor_recovery_codes = CAST(:codes AS jsonb), updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                    : 'UPDATE ce_users SET two_factor_recovery_codes = :codes, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
                $this->database->connection()->prepare($sql)->execute(['id' => $userId, 'codes' => $json]);
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    private function findByIdWithRecoveryCodes(string $id): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT id, two_factor_recovery_codes FROM ce_users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['two_factor_recovery_codes'] = $this->decodeList((string) ($row['two_factor_recovery_codes'] ?? '[]'));
        return $row;
    }

    /** @return array<string, mixed> */
    private function normalize(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'status' => (string) ($row['status'] ?? 'active'),
            'two_factor_enabled' => (bool) ($row['two_factor_enabled'] ?? false),
            'last_login_at' => isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            'disabled_at' => isset($row['disabled_at']) ? (string) $row['disabled_at'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function withRoles(array $user): array
    {
        $stmt = $this->database->connection()->prepare('SELECT r.handle FROM ce_roles r INNER JOIN ce_user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :id ORDER BY r.handle');
        $stmt->execute(['id' => $user['id']]);
        $user['roles'] = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $user['permissions'] = $this->roles->permissionsForUser((string) $user['id']);
        return $user;
    }

    private function fallbackPasswordHash(string $password): string
    {
        if (mb_strlen($password) < 12) {
            throw new \InvalidArgumentException('Пароль администратора должен быть не короче 12 символов.');
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($hash === false) {
            throw new \RuntimeException('Не удалось создать хэш пароля администратора.');
        }
        return $hash;
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
