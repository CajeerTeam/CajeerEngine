<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\UserSessionRepository;
use PDO;

/** Operator actions on CajeerEngine's local OIDC mappings, never on Keycloak accounts. */
final readonly class IdentityCenter
{
    public const ISSUER = 'https://id.skif4er.ru/realms/skif4er';

    public function __construct(
        private DatabaseManager $database,
        private UserSessionRepository $sessions,
        private AuditLogRepository $audit,
    ) {}

    /** @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int} */
    public function users(string $search = '', string $linked = 'all', int $limit = 30, int $offset = 0): array
    {
        $search = trim($search);
        if (strlen($search) > 120 || !in_array($linked, ['all', 'yes', 'no'], true)
            || $limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw new \InvalidArgumentException('Некорректные параметры поиска Identity.');
        }
        $where = [];
        $args = ['issuer' => self::ISSUER];
        if ($search !== '') {
            $where[] = "(LOWER(u.email) LIKE :email ESCAPE '!' OR LOWER(u.name) LIKE :name ESCAPE '!' OR LOWER(u.id) LIKE :uid ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            $args += ['email' => $like, 'name' => $like, 'uid' => $like];
        }
        if ($linked === 'yes') $where[] = 'i.user_id IS NOT NULL';
        if ($linked === 'no') $where[] = 'i.user_id IS NULL';
        $from = ' FROM ce_users u LEFT JOIN ce_oidc_identities i ON i.user_id = u.id AND i.issuer = :issuer';
        $filter = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        return $this->database->readSnapshot(function (PDO $pdo) use ($from, $filter, $args, $limit, $offset): array {
            $count = $pdo->prepare('SELECT COUNT(*)' . $from . $filter);
            $count->execute($args);
            $total = (int) $count->fetchColumn();
            $page = $pdo->prepare('SELECT u.id, u.email, u.name, u.status, u.two_factor_enabled, u.last_login_at, '
                . 'i.subject, i.provisioned, i.created_at AS linked_at' . $from . $filter
                . ' ORDER BY u.email ASC, u.id ASC LIMIT :limit OFFSET :offset');
            foreach ($args as $key => $value) $page->bindValue(':' . $key, $value, PDO::PARAM_STR);
            $page->bindValue(':limit', $limit, PDO::PARAM_INT);
            $page->bindValue(':offset', $offset, PDO::PARAM_INT);
            $page->execute();
            $items = array_map(static fn (array $row): array => [
                'id' => (string) $row['id'], 'email' => (string) $row['email'],
                'name' => (string) $row['name'], 'status' => (string) $row['status'],
                'two_factor_enabled' => (bool) $row['two_factor_enabled'],
                'last_login_at' => $row['last_login_at'], 'linked' => $row['subject'] !== null,
                'subject' => $row['subject'] === null ? null : (string) $row['subject'],
                'provisioned' => (bool) ($row['provisioned'] ?? false),
                'linked_at' => $row['linked_at'],
            ], $page->fetchAll(PDO::FETCH_ASSOC));
            return compact('items', 'total', 'limit', 'offset');
        });
    }

    /** Link only an externally verified, exact Keycloak subject; never infer from email. */
    public function link(string $actor, string $target, string $subject): void
    {
        $this->checkTarget($target);
        if ($actor === $target) throw new \DomainException('Для собственного аккаунта используйте подтверждённую OIDC-привязку.');
        if ($subject === '' || strlen($subject) > 255 || preg_match('/[\x00-\x1f\x7f]/', $subject)) {
            throw new \InvalidArgumentException('Нужен точный проверенный subject из SkiF4er ID.');
        }
        try { $this->database->transaction(function (PDO $pdo) use ($actor, $target, $subject): void {
            $this->activeUser($pdo, $target);
            $own = $pdo->prepare('SELECT subject FROM ce_oidc_identities WHERE issuer = :issuer AND user_id = :uid');
            $own->execute(['issuer' => self::ISSUER, 'uid' => $target]);
            if ($own->fetchColumn() !== false) throw new \DomainException('У пользователя уже есть привязка SkiF4er ID.');
            $existing = $pdo->prepare('SELECT user_id FROM ce_oidc_identities WHERE issuer = :issuer AND subject = :subject');
            $existing->execute(['issuer' => self::ISSUER, 'subject' => $subject]);
            if ($existing->fetchColumn() !== false) throw new \DomainException('Этот subject уже используется другим пользователем.');
            $insert = $pdo->prepare('INSERT INTO ce_oidc_identities (issuer, subject, user_id, provisioned) VALUES (:issuer, :subject, :uid, :provisioned)');
            $insert->execute(['issuer' => self::ISSUER, 'subject' => $subject, 'uid' => $target, 'provisioned' => 0]);
            $this->audit->record('identity.admin_link', $actor, ['target_user_id' => $target, 'subject_digest' => hash('sha256', $subject), 'provider' => 'skif4er']);
        }); } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505', '19'], true)) {
                throw new \DomainException('Эта учётная запись или subject уже привязаны.', 0, $e);
            }
            throw $e;
        }
    }

    /** Remove mapping and revoke its browser SSO sessions as a single SQL transaction. */
    public function unlink(string $actor, string $target): int
    {
        $this->checkTarget($target);
        if ($actor === $target) throw new \DomainException('Для собственной учётной записи используйте самостоятельную отвязку.');
        return $this->database->transaction(function (PDO $pdo) use ($actor, $target): int {
            $this->existingUser($pdo, $target);
            $find = $pdo->prepare('SELECT provisioned, subject FROM ce_oidc_identities WHERE issuer = :issuer AND user_id = :uid');
            $find->execute(['issuer' => self::ISSUER, 'uid' => $target]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new \DomainException('Привязка SkiF4er ID отсутствует.');
            if ((bool) $row['provisioned']) throw new \DomainException('Нельзя отвязать OIDC-only аккаунт без проверенного локального входа.');
            $delete = $pdo->prepare('DELETE FROM ce_oidc_identities WHERE issuer = :issuer AND user_id = :uid');
            $delete->execute(['issuer' => self::ISSUER, 'uid' => $target]);
            $count = $this->revokeSsoSessions($pdo, $target);
            $this->audit->record('identity.admin_unlink', $actor, ['target_user_id' => $target,
                'subject_digest' => hash('sha256', (string) $row['subject']), 'revoked_sessions' => $count]);
            return $count;
        });
    }

    /** @return list<array<string,mixed>> */
    public function sessions(string $target): array
    {
        $this->checkTarget($target);
        $this->existingUser($this->database->connection(), $target);
        return $this->sessions->activeForUser($target, null, 720, 'skif4er');
    }

    public function revokeSession(string $actor, string $target, string $tokenId): bool
    {
        $this->checkTarget($target);
        if ($actor === $target) throw new \DomainException('Для собственной сессии используйте настройки сессий.');
        if (!preg_match('/^[a-fA-F0-9-]{36}$/D', $tokenId)) throw new \InvalidArgumentException('Некорректный идентификатор сессии.');
        return $this->database->transaction(function (PDO $pdo) use ($actor, $target, $tokenId): bool {
            $this->existingUser($pdo, $target);
            $session = $this->sessions->find($tokenId);
            if ($session === null || $session['user_id'] !== $target || $session['provider'] !== 'skif4er' || $session['revoked_at'] !== null) {
                throw new \DomainException('Активная сессия SkiF4er ID не найдена.');
            }
            $revoked = $this->sessions->revoke($tokenId, $target);
            if (!$revoked) throw new \DomainException('Сессия уже завершена.');
            $this->audit->record('identity.admin_session_revoke', $actor, ['target_user_id' => $target, 'session_id' => $tokenId]);
            return true;
        });
    }

    public function revokeAllSessions(string $actor, string $target): int
    {
        $this->checkTarget($target);
        if ($actor === $target) throw new \DomainException('Для собственных сессий используйте настройки сессий.');
        return $this->database->transaction(function (PDO $pdo) use ($actor, $target): int {
            $this->existingUser($pdo, $target);
            $count = $this->revokeSsoSessions($pdo, $target);
            $this->audit->record('identity.admin_sessions_revoke_all', $actor, ['target_user_id' => $target, 'revoked_sessions' => $count]);
            return $count;
        });
    }

    private function revokeSsoSessions(PDO $pdo, string $target): int
    {
        // Only registered OIDC admin-session tokens, never machine/API tokens or local logins.
        $tokens = $pdo->prepare("UPDATE ce_api_tokens SET revoked_at = CURRENT_TIMESTAMP
            WHERE user_id = :owner AND name = 'admin-session' AND revoked_at IS NULL AND id IN
            (SELECT token_id FROM ce_user_sessions WHERE user_id = :owner2 AND provider = 'skif4er' AND revoked_at IS NULL)");
        $tokens->execute(['owner' => $target, 'owner2' => $target]);
        $sessions = $pdo->prepare("UPDATE ce_user_sessions SET revoked_at = CURRENT_TIMESTAMP
            WHERE user_id = :owner AND provider = 'skif4er' AND revoked_at IS NULL");
        $sessions->execute(['owner' => $target]);
        return $sessions->rowCount();
    }

    private function activeUser(PDO $pdo, string $target): void
    {
        if ($this->existingUser($pdo, $target) !== 'active') throw new \DomainException('Пользователь отключён.');
    }

    private function existingUser(PDO $pdo, string $target): string
    {
        $stmt = $pdo->prepare('SELECT status FROM ce_users WHERE id = :id');
        $stmt->execute(['id' => $target]);
        $status = $stmt->fetchColumn();
        if ($status === false) throw new \DomainException('Пользователь не найден.');
        return (string) $status;
    }

    private function checkTarget(string $target): void
    {
        if (!preg_match('/^[a-fA-F0-9-]{36}$/D', $target)) throw new \InvalidArgumentException('Некорректный идентификатор пользователя.');
    }
}
