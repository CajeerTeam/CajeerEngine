<?php

declare(strict_types=1);

namespace CajeerEngine\Cms;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Support\Uuid;
use PDO;
use CajeerEngine\Public\PublicPath;
use CajeerEngine\Public\RouteIndex;
use Throwable;

final class RedirectRepository
{
    private JsonFile $file;
    private ?DatabaseManager $database = null;
    private ?bool $databaseReady = null;

    public function __construct(private readonly string $rootPath, ?DatabaseManager $database = null)
    {
        $this->database = $database;
        $this->file = new JsonFile($this->rootPath . '/storage/app/cms/redirects.json');
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        if ($this->useDatabase()) {
            return $this->allDatabase();
        }
        $data = $this->file->readObject(['redirects' => []]);
        $items = is_array($data['redirects'] ?? null) ? $data['redirects'] : [];
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($a['source_path'] ?? ''), (string) ($b['source_path'] ?? '')));
        return array_values($items);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        if(str_starts_with($id,'/'))return $this->byPath($id);
        if($this->useDatabase()){
            $expr=$this->database()->driver()==='pgsql'?'CAST(id AS TEXT)':'CAST(id AS CHAR)';
            $stmt=$this->pdo()->prepare('SELECT * FROM ce_cms_redirects WHERE '.$expr.' = :id');$stmt->execute(['id'=>$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$this->mapRow($row):null;
        }
        foreach($this->all() as $r)if($r['id']===$id)return $r;return null;
    }

    public function byPath(string $path): ?array
    {
        $path=PublicPath::normalize($path);
        if($this->useDatabase()){
            $stmt=$this->pdo()->prepare('SELECT * FROM ce_cms_redirects WHERE source_path = :path');$stmt->execute(['path'=>$path]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$this->mapRow($row):null;
        }
        foreach($this->all() as $r)if($r['source_path']===$path)return $r;return null;
    }

    public function match(string $path): ?array
    {
        $r=$this->byPath($path);if($r===null||!$r['enabled'])return null;
        if((int)$r['status_code']!==410){
            try{$this->assertNoLoop($r);}catch(\InvalidArgumentException){return null;}
        }
        $this->touch($r['id']);return $r;
    }

    private function assertNoLoop(array $r): void
    {
        $base=PublicPath::base((new ConfigRepository($this->rootPath))->string('app.url'));
        $seen=[$r['source_path']=>true];$target=$r['target_url'];
        for($i=0;$i<64;$i++){
            $path=PublicPath::localTarget($target,$base);if($path===null)return;
            if(isset($seen[$path]))throw new \InvalidArgumentException('Redirect cycle: '.$path);
            $seen[$path]=true;$next=$this->byPath($path);
            if($next===null||!$next['enabled']||(int)$next['status_code']===410)return;
            $target=$next['target_url'];
        }
        throw new \InvalidArgumentException('Redirect chain too long');
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function save(array $payload, ?string $id = null): array
    {
        return (new RouteIndex($this->rootPath,$this->database()))->mutate(fn()=>$this->saveRaw($payload,$id),['storage/app/cms/redirects.json']);
    }

    private function saveRaw(array $payload, ?string $id = null): array
    {
        $source = PublicPath::content((string) ($payload['source_path'] ?? $payload['source'] ?? ''));
        $target = trim((string) ($payload['target_url'] ?? $payload['target'] ?? ''));
        if(strlen($source)>700)throw new \InvalidArgumentException('Redirect source is too long');
        if ($source === '/') {
            throw new \InvalidArgumentException('source_path не может быть корнем сайта.');
        }
        $statusCode = (int) ($payload['status_code'] ?? 301);
        if (!in_array($statusCode, [301, 302, 307, 308, 410], true)) throw new \InvalidArgumentException('Invalid redirect status');
        if($statusCode===410)$target='';else PublicPath::target($target);
        $existing=$this->byPath($source);
        $id=$id??($payload['id']??null);
        if($id!==null&&(!is_string($id)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$id)))throw new \InvalidArgumentException('Invalid redirect ID');
        if($id!==null&&($old=$this->find($id))!==null&&$old['source_path']!==$source)throw new \InvalidArgumentException('Redirect source is immutable; delete and create a new rule');
        if($existing!==null)$id=$existing['id'];

        $item = [
            'id' => $id ?: (string) ($payload['id'] ?? Uuid::v4()),
            'source_path' => $source,
            'target_url' => $target,
            'status_code' => $statusCode,
            'enabled' => filter_var($payload['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'hit_count' => (int) ($existing['hit_count'] ?? 0),
            'last_hit_at' => $existing['last_hit_at'] ?? null,
            'created_at' => (string) ($existing['created_at'] ?? $this->now()),
            'updated_at' => $this->now(),
        ];

        if($item['enabled'] && $statusCode!==410)$this->assertNoLoop($item);
        if ($this->useDatabase()) {
            return $this->saveDatabase($item);
        }

        $items = $this->all();
        $found = false;
        foreach ($items as $index => $existing) {
            if (($existing['id'] ?? null) === $item['id'] || ($existing['source_path'] ?? null) === $source) {
                $items[$index] = array_merge($existing, $item, ['storage' => 'file']);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $item['storage'] = 'file';
            $items[] = $item;
        }
        $this->persist($items);
        return $this->find((string) $item['id']) ?? $item;
    }

    public function delete(string $id): bool
    {
        return (new RouteIndex($this->rootPath,$this->database()))->mutate(fn()=>$this->deleteRaw($id),['storage/app/cms/redirects.json']);
    }

    private function deleteRaw(string $id): bool
    {
        if ($this->useDatabase()) {
            $existing=$this->find($id);if($existing===null)return false;
            $stmt = $this->pdo()->prepare('DELETE FROM ce_cms_redirects WHERE id = :id');
            $stmt->execute(['id' => $existing['id']]);
            return $stmt->rowCount() > 0;
        }
        $items = $this->all();
        $before = count($items);
        $path = $this->path($id);
        $items = array_values(array_filter($items, static fn (array $item): bool => ($item['id'] ?? null) !== $id && ($item['source_path'] ?? null) !== $path));
        if (count($items) === $before) {
            return false;
        }
        $this->persist($items);
        return true;
    }

    private function touch(string $id): void
    {
        if ($id === '') {
            return;
        }
        if ($this->useDatabase()) {
            $now = $this->nowForDatabase();
            $stmt = $this->pdo()->prepare('UPDATE ce_cms_redirects SET hit_count = hit_count + 1, last_hit_at = :last_hit_at WHERE id = :id');
            $stmt->execute(['id' => $id, 'last_hit_at' => $now]);
            return;
        }
        (new RouteIndex($this->rootPath,$this->database()))->withWriteLock(function()use($id):void{
        $items = $this->all();
        foreach ($items as $index => $item) {
            if (($item['id'] ?? null) !== $id) {
                continue;
            }
            $items[$index]['hit_count'] = (int) ($item['hit_count'] ?? 0) + 1;
            $items[$index]['last_hit_at'] = $this->now();
            $this->persist($items);
            return;
        }
        });
    }

    /** @return list<array<string,mixed>> */
    private function allDatabase(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM ce_cms_redirects ORDER BY source_path ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return array_map(fn (array $row): array => $this->mapRow($row), $rows);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function saveDatabase(array $item): array
    {
        $driver = $this->database()->driver();
        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO ce_cms_redirects (id, source_path, target_url, status_code, enabled, hit_count, last_hit_at, created_at, updated_at) VALUES (:id, :source_path, :target_url, :status_code, :enabled, :hit_count, :last_hit_at, :created_at, :updated_at) ON CONFLICT (source_path) DO UPDATE SET target_url = EXCLUDED.target_url, status_code = EXCLUDED.status_code, enabled = EXCLUDED.enabled, updated_at = EXCLUDED.updated_at';
        } elseif ($driver === 'mysql') {
            $sql = 'INSERT INTO ce_cms_redirects (id, source_path, target_url, status_code, enabled, hit_count, last_hit_at, created_at, updated_at) VALUES (:id, :source_path, :target_url, :status_code, :enabled, :hit_count, :last_hit_at, :created_at, :updated_at) ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), status_code = VALUES(status_code), enabled = VALUES(enabled), updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO ce_cms_redirects (id, source_path, target_url, status_code, enabled, hit_count, last_hit_at, created_at, updated_at) VALUES (:id, :source_path, :target_url, :status_code, :enabled, :hit_count, :last_hit_at, :created_at, :updated_at) ON CONFLICT(source_path) DO UPDATE SET target_url = excluded.target_url, status_code = excluded.status_code, enabled = excluded.enabled, updated_at = excluded.updated_at';
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'id' => $item['id'],
            'source_path' => $item['source_path'],
            'target_url' => $item['target_url'],
            'status_code' => $item['status_code'],
            'enabled' => $this->boolParam((bool) $item['enabled']),
            'hit_count' => $item['hit_count'],
            'last_hit_at' => $item['last_hit_at'],
            'created_at' => $this->nowForDatabase(),
            'updated_at' => $this->nowForDatabase(),
        ]);
        return $this->byPath((string) $item['source_path']) ?? $item;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'source_path' => (string) $row['source_path'],
            'target_url' => (string) $row['target_url'],
            'status_code' => (int) $row['status_code'],
            'enabled' => in_array($row['enabled'],[true,1,'1','t','true'],true),
            'hit_count' => (int) $row['hit_count'],
            'last_hit_at' => $row['last_hit_at'] !== null ? (string) $row['last_hit_at'] : null,
            'storage' => 'database',
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function persist(array $items): void
    {
        $this->file->writeObject([
            'version' => 1,
            'updated_at' => $this->now(),
            'redirects' => array_values($items),
        ]);
    }

    private function useDatabase(): bool
    {
        if ($this->databaseReady !== null) {
            return $this->databaseReady;
        }
        $mode = strtolower(trim((string) (getenv('CMS_STORAGE') ?: 'auto')));
        if ($mode === 'file') {
            return $this->databaseReady = false;
        }
        try {
            $this->databaseReady = $this->database()->tableExists('ce_cms_redirects');
        } catch (Throwable $e) {
            if ($mode === 'database') {
                throw new \RuntimeException('CMS_STORAGE=database, но CMS redirects table недоступна: ' . $e->getMessage(), 0, $e);
            }
            $this->databaseReady = false;
        }
        if ($mode === 'database' && $this->databaseReady !== true) {
            throw new \RuntimeException('CMS_STORAGE=database, но таблица ce_cms_redirects не найдена. Выполните миграции.');
        }
        return $this->databaseReady;
    }

    private function database(): DatabaseManager
    {
        if (!$this->database instanceof DatabaseManager) {
            $this->database = new DatabaseManager(new ConfigRepository($this->rootPath));
        }
        return $this->database;
    }

    private function pdo(): PDO
    {
        return $this->database()->connection();
    }

    private function path(string $path): string
    {
        $path = '/' . trim($path, " \t\n\r\0\x0B/");
        return $path === '/' ? '/' : rtrim(preg_replace('#/+#', '/', $path) ?: $path, '/');
    }

    private function boolParam(bool $value): int|bool
    {
        return $value ? 1 : 0;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    private function nowForDatabase(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
