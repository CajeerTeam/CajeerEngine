<?php

declare(strict_types=1);

namespace CajeerEngine\ImportExport;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Support\JsonFile;

final readonly class ImportExportService
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<array<string, mixed>> */
    public function exports(): array
    {
        $dir = $this->exportsDir();
        $items = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $items[] = [
                'file' => basename($file),
                'path' => $file,
                'size' => filesize($file) ?: 0,
                'created_at' => is_array($decoded) ? ($decoded['manifest']['created_at'] ?? date(DATE_ATOM, filemtime($file) ?: time())) : date(DATE_ATOM, filemtime($file) ?: time()),
                'version' => is_array($decoded) ? ($decoded['manifest']['version'] ?? null) : null,
                'format' => is_array($decoded) ? ($decoded['manifest']['format'] ?? null) : null,
                'checksum' => is_array($decoded) ? ($decoded['manifest']['checksum'] ?? null) : null,
                'sections' => is_array($decoded) ? array_keys((array) ($decoded['data'] ?? [])) : [],
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
        return $items;
    }

    /** @param list<string> $sections @return array<string, mixed> */
    public function createExport(array $sections = ['content', 'media', 'users', 'settings', 'themes', 'extensions'], ?string $filename = null): array
    {
        $sections = $sections === [] ? ['content', 'media', 'users', 'settings', 'themes', 'extensions'] : array_values(array_unique($sections));
        $version = trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: '1.1.1';
        $data = [];
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);

        if (in_array('content', $sections, true)) {
            $types = new ContentTypeRepository($this->rootPath, $database);
            $entries = new ContentEntryRepository($this->rootPath, $database);
            $content = ['types' => $types->all(), 'entries' => []];
            foreach ($content['types'] as $type) {
                $handle = (string) ($type['handle'] ?? '');
                if ($handle !== '') {
                    $content['entries'][$handle] = $entries->list($handle, ['status' => 'all']);
                }
            }
            $data['content'] = $content;
        }

        if (in_array('media', $sections, true)) {
            $media = new MediaRepository($this->rootPath, new StorageManager($this->rootPath, $config));
            $items = $media->all();
            $data['media'] = [
                'items' => $items,
                'files' => $this->exportMediaFiles($items),
                'diagnostics' => $media->diagnostics(),
            ];
        }

        if (in_array('users', $sections, true)) {
            $roles = new RoleRepository($database);
            $users = new UserRepository($database, $roles);
            $data['users'] = [
                'roles' => $this->safeArray(fn (): array => $roles->all()),
                'users' => $this->safeArray(fn (): array => $users->all()),
                'policy' => [
                    'password_hashes_exported' => false,
                    'new_users_import_mode' => 'disabled-by-default',
                    'note' => 'Экспорт пользователей не содержит password_hash. Импорт безопасно обновляет существующих пользователей и может создать disabled placeholders только при create_missing_users=true.',
                ],
            ];
        }

        if (in_array('settings', $sections, true)) {
            $data['settings'] = $this->readJsonIfExists($this->rootPath . '/storage/app/settings.json');
        }

        if (in_array('themes', $sections, true)) {
            $data['themes'] = $this->scanManifestDirectory('themes');
            $themeConfig = $this->readJsonIfExists($this->rootPath . '/storage/app/cms/theme.json');
            if ($themeConfig !== []) {
                $data['theme_config'] = $themeConfig;
            }
        }

        if (in_array('extensions', $sections, true)) {
            $data['extensions'] = [
                'registry' => $this->readJsonIfExists($this->rootPath . '/storage/app/extensions/registry.json'),
                'modules' => $this->scanManifestDirectory('modules'),
                'plugins' => $this->scanManifestDirectory('plugins'),
                'themes' => $this->scanManifestDirectory('themes'),
            ];
        }

        $payload = [
            'manifest' => [
                'format' => 'cajeerengine.export.v2',
                'compatible_formats' => ['cajeerengine.export.v1'],
                'version' => $version,
                'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'sections' => array_keys($data),
                'media_files_embedded' => isset($data['media']['files']) && count((array) $data['media']['files']) > 0,
            ],
            'data' => $data,
        ];
        $payload['manifest']['checksum'] = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $filename = $filename ?: 'cajeerengine-export-' . str_replace(['/', ':'], '-', $payload['manifest']['created_at']) . '.json';
        $path = $this->exportsDir() . '/' . $this->safeFilename($filename);
        (new JsonFile($path))->writeObject($payload);
        return ['path' => $path, 'file' => basename($path), 'manifest' => $payload['manifest']];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function diff(string $file, array $options = []): array
    {
        $path = $this->resolveImportPath($file);
        $payload = $this->loadImportPayload($path);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $types = new ContentTypeRepository($this->rootPath, $database);
        $entries = new ContentEntryRepository($this->rootPath, $database);
        $media = new MediaRepository($this->rootPath, new StorageManager($this->rootPath, $config));

        $diff = [
            'ok' => true,
            'file' => basename($path),
            'manifest' => $payload['manifest'],
            'summary' => ['create' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0],
            'sections' => [],
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        if (isset($data['content']) && is_array($data['content'])) {
            $section = ['types' => [], 'entries' => []];
            foreach ((array) ($data['content']['types'] ?? []) as $type) {
                if (!is_array($type)) { continue; }
                $handle = (string) ($type['handle'] ?? '');
                if ($handle === '') { continue; }
                $exists = $types->find($handle) !== null;
                $action = $exists ? 'update' : 'create';
                $section['types'][] = ['handle' => $handle, 'action' => $action];
                $diff['summary'][$action]++;
            }
            foreach ((array) ($data['content']['entries'] ?? []) as $handle => $items) {
                if (!is_array($items)) { continue; }
                foreach ($items as $entry) {
                    if (!is_array($entry)) { continue; }
                    $id = (string) ($entry['id'] ?? '');
                    $slug = (string) ($entry['slug'] ?? '');
                    $existing = $id !== '' ? $entries->find((string) $handle, $id) : null;
                    $existing = $existing ?? ($slug !== '' ? $entries->find((string) $handle, $slug) : null);
                    $action = $existing === null ? 'create' : 'update';
                    $section['entries'][] = ['type' => (string) $handle, 'id' => $id ?: null, 'slug' => $slug ?: null, 'action' => $action];
                    $diff['summary'][$action]++;
                }
            }
            $diff['sections']['content'] = $section;
        }

        if (isset($data['media']) && is_array($data['media'])) {
            $existingChecksums = [];
            foreach ($media->all() as $item) {
                $checksum = (string) ($item['checksum'] ?? '');
                if ($checksum !== '') { $existingChecksums[$checksum] = true; }
            }
            $section = [];
            foreach ((array) ($data['media']['items'] ?? []) as $item) {
                if (!is_array($item)) { continue; }
                $checksum = (string) ($item['checksum'] ?? '');
                $action = $checksum !== '' && isset($existingChecksums[$checksum]) ? 'skip' : 'create';
                $section[] = ['filename' => $item['filename'] ?? null, 'path' => $item['path'] ?? null, 'checksum' => $checksum ?: null, 'action' => $action];
                $diff['summary'][$action]++;
            }
            $diff['sections']['media'] = $section;
        }

        if (isset($data['settings'])) {
            $diff['sections']['settings'] = ['action' => ($options['settings'] ?? 'merge') === 'replace' ? 'replace' : 'merge'];
            $diff['summary']['update']++;
        }
        if (isset($data['extensions']['registry'])) {
            $diff['sections']['extensions.registry'] = ['action' => 'replace'];
            $diff['summary']['update']++;
        }

        return $diff;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function import(string $file, array $options = []): array
    {
        $path = $this->resolveImportPath($file);
        $payload = $this->loadImportPayload($path);
        $dryRun = filter_var($options['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $replaceSettings = ($options['settings'] ?? 'merge') === 'replace';
        $createMissingUsers = filter_var($options['create_missing_users'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $deleteMissingContent = filter_var($options['delete_missing_content'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $result = [
            'ok' => true,
            'dry_run' => $dryRun,
            'file' => basename($path),
            'manifest' => $payload['manifest'],
            'diff' => $this->diff($path, $options),
            'imported' => [],
            'skipped' => [],
            'errors' => [],
        ];

        if ($dryRun) {
            $result['skipped'][] = 'all:dry-run';
            $this->writeImportLog($result);
            return $result;
        }

        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);

        if (isset($data['content']) && is_array($data['content'])) {
            $result['content'] = $this->importContent($data['content'], $database, $deleteMissingContent);
            $result['imported'][] = 'content';
        }

        if (isset($data['media']) && is_array($data['media'])) {
            $result['media'] = $this->importMedia($data['media'], $config);
            $result['imported'][] = 'media';
        }

        if (isset($data['users']) && is_array($data['users'])) {
            $result['users'] = $this->importUsers($data['users'], $database, $createMissingUsers);
            $result['imported'][] = 'users.safe';
        }

        if (isset($data['settings'])) {
            $incoming = is_array($data['settings']) ? $data['settings'] : [];
            $pathSettings = $this->rootPath . '/storage/app/settings.json';
            $existing = $replaceSettings ? [] : $this->readJsonIfExists($pathSettings);
            (new JsonFile($pathSettings))->writeObject(array_replace_recursive($existing, $incoming));
            $result['imported'][] = $replaceSettings ? 'settings.replace' : 'settings.merge';
        }

        if (isset($data['theme_config']) && is_array($data['theme_config'])) {
            (new JsonFile($this->rootPath . '/storage/app/cms/theme.json'))->writeObject($data['theme_config']);
            $result['imported'][] = 'theme_config';
        }

        if (isset($data['extensions']['registry']) && is_array($data['extensions']['registry'])) {
            (new JsonFile($this->rootPath . '/storage/app/extensions/registry.json'))->writeObject($data['extensions']['registry']);
            $result['imported'][] = 'extensions.registry';
        }

        $this->writeImportLog($result);
        return $result;
    }

    /** @param array<string, mixed> $content @return array<string, mixed> */
    private function importContent(array $content, DatabaseManager $database, bool $deleteMissing): array
    {
        $types = new ContentTypeRepository($this->rootPath, $database);
        $entries = new ContentEntryRepository($this->rootPath, $database);
        $result = ['types_created' => 0, 'types_updated' => 0, 'entries_created' => 0, 'entries_updated' => 0, 'entries_deleted' => 0, 'errors' => []];

        foreach ((array) ($content['types'] ?? []) as $type) {
            if (!is_array($type)) { continue; }
            $handle = (string) ($type['handle'] ?? '');
            if ($handle === '') { continue; }
            try {
                if ($types->find($handle) === null) {
                    $types->create($type);
                    $result['types_created']++;
                } else {
                    $types->update($handle, $type);
                    $result['types_updated']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ['section' => 'content.types', 'handle' => $handle, 'error' => $e->getMessage()];
            }
        }

        foreach ((array) ($content['entries'] ?? []) as $handle => $items) {
            if (!is_array($items)) { continue; }
            $seen = [];
            foreach ($items as $entry) {
                if (!is_array($entry)) { continue; }
                $id = (string) ($entry['id'] ?? '');
                $slug = (string) ($entry['slug'] ?? '');
                try {
                    $existing = $id !== '' ? $entries->find((string) $handle, $id) : null;
                    $existing = $existing ?? ($slug !== '' ? $entries->find((string) $handle, $slug) : null);
                    $payload = [
                        'title' => (string) ($entry['title'] ?? 'Без названия'),
                        'slug' => $slug,
                        'status' => (string) ($entry['status'] ?? 'draft'),
                        'locale' => (string) ($entry['locale'] ?? 'ru'),
                        'translation_group' => (string) ($entry['translation_group'] ?? ($id ?: '')),
                        'data' => is_array($entry['data'] ?? null) ? $entry['data'] : [],
                    ];
                    if ($existing === null) {
                        $created = $entries->create((string) $handle, $payload);
                        $seen[] = (string) ($created['id'] ?? '');
                        $result['entries_created']++;
                    } else {
                        $updated = $entries->update((string) $handle, (string) $existing['id'], $payload);
                        $seen[] = (string) ($updated['id'] ?? $existing['id']);
                        $result['entries_updated']++;
                    }
                } catch (\Throwable $e) {
                    $result['errors'][] = ['section' => 'content.entries', 'type' => (string) $handle, 'id' => $id, 'slug' => $slug, 'error' => $e->getMessage()];
                }
            }
            if ($deleteMissing) {
                foreach ($entries->list((string) $handle, ['status' => 'all']) as $existingEntry) {
                    $existingId = (string) ($existingEntry['id'] ?? '');
                    if ($existingId !== '' && !in_array($existingId, $seen, true)) {
                        if ($entries->delete((string) $handle, $existingId)) { $result['entries_deleted']++; }
                    }
                }
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $mediaData @return array<string, mixed> */
    private function importMedia(array $mediaData, ConfigRepository $config): array
    {
        $media = new MediaRepository($this->rootPath, new StorageManager($this->rootPath, $config));
        $existing = [];
        foreach ($media->all() as $item) {
            $checksum = (string) ($item['checksum'] ?? '');
            if ($checksum !== '') { $existing[$checksum] = true; }
        }

        $filesByChecksum = [];
        foreach ((array) ($mediaData['files'] ?? []) as $file) {
            if (is_array($file) && isset($file['checksum'])) {
                $filesByChecksum[(string) $file['checksum']] = $file;
            }
        }

        $result = ['created' => 0, 'skipped_existing' => 0, 'skipped_no_file' => 0, 'errors' => []];
        foreach ((array) ($mediaData['items'] ?? []) as $item) {
            if (!is_array($item)) { continue; }
            $checksum = (string) ($item['checksum'] ?? '');
            if ($checksum !== '' && isset($existing[$checksum])) {
                $result['skipped_existing']++;
                continue;
            }
            $file = $checksum !== '' ? ($filesByChecksum[$checksum] ?? null) : null;
            if (!is_array($file) || (string) ($file['content_base64'] ?? '') === '') {
                $result['skipped_no_file']++;
                continue;
            }
            try {
                $media->createFromBase64([
                    'filename' => (string) ($item['filename'] ?? $file['filename'] ?? 'upload.bin'),
                    'mime_type' => (string) ($item['mime_type'] ?? $file['mime_type'] ?? 'application/octet-stream'),
                    'title' => (string) ($item['title'] ?? ''),
                    'alt' => (string) ($item['alt'] ?? ''),
                    'content_base64' => (string) $file['content_base64'],
                ]);
                $result['created']++;
            } catch (\Throwable $e) {
                $result['errors'][] = ['filename' => $item['filename'] ?? null, 'error' => $e->getMessage()];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $usersData @return array<string, mixed> */
    private function importUsers(array $usersData, DatabaseManager $database, bool $createMissingUsers): array
    {
        $roles = new RoleRepository($database);
        $users = new UserRepository($database, $roles, new PasswordHasher(new ConfigRepository($this->rootPath)));
        $result = ['roles_upserted' => 0, 'users_updated' => 0, 'users_created_disabled' => 0, 'users_skipped_missing_password' => 0, 'errors' => []];

        foreach ((array) ($usersData['roles'] ?? []) as $role) {
            if (!is_array($role)) { continue; }
            try {
                $roles->upsert((string) ($role['handle'] ?? ''), (string) ($role['name'] ?? $role['handle'] ?? ''), array_values(array_map('strval', (array) ($role['permissions'] ?? []))));
                $result['roles_upserted']++;
            } catch (\Throwable $e) {
                $result['errors'][] = ['section' => 'users.roles', 'handle' => $role['handle'] ?? null, 'error' => $e->getMessage()];
            }
        }

        foreach ((array) ($usersData['users'] ?? []) as $user) {
            if (!is_array($user)) { continue; }
            $email = (string) ($user['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
            try {
                $existing = $users->findByEmail($email);
                $payload = [
                    'name' => (string) ($user['name'] ?? $email),
                    'status' => in_array(($user['status'] ?? 'active'), ['active', 'disabled'], true) ? (string) $user['status'] : 'disabled',
                    'roles' => array_values(array_map('strval', (array) ($user['roles'] ?? ['viewer']))),
                ];
                if ($existing !== null) {
                    $users->update((string) $existing['id'], $payload);
                    $result['users_updated']++;
                } elseif ($createMissingUsers) {
                    $password = bin2hex(random_bytes(24));
                    $id = $users->create($email, (string) $payload['name'], password_hash($password, PASSWORD_ARGON2ID) ?: password_hash($password, PASSWORD_DEFAULT), $payload['roles']);
                    $users->update($id, ['status' => 'disabled']);
                    $result['users_created_disabled']++;
                } else {
                    $result['users_skipped_missing_password']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ['section' => 'users.users', 'email' => $email, 'error' => $e->getMessage()];
            }
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function exportMediaFiles(array $items): array
    {
        $files = [];
        foreach ($items as $item) {
            $relative = ltrim((string) ($item['path'] ?? ''), '/');
            if ($relative === '') { continue; }
            $path = $this->rootPath . '/public/uploads/' . $relative;
            if (!is_file($path)) { continue; }
            $contents = file_get_contents($path);
            if ($contents === false) { continue; }
            $files[] = [
                'path' => $relative,
                'filename' => (string) ($item['filename'] ?? basename($relative)),
                'mime_type' => (string) ($item['mime_type'] ?? 'application/octet-stream'),
                'size' => strlen($contents),
                'checksum' => hash('sha256', $contents),
                'content_base64' => base64_encode($contents),
            ];
        }
        return $files;
    }

    /** @return array<string, mixed> */
    private function loadImportPayload(string $path): array
    {
        $payload = (new JsonFile($path))->readObject();
        $format = (string) ($payload['manifest']['format'] ?? '');
        if (!in_array($format, ['cajeerengine.export.v1', 'cajeerengine.export.v2'], true)) {
            throw new \InvalidArgumentException('Файл импорта не является экспортом CajeerEngine v1/v2.');
        }
        return $payload;
    }

    private function exportsDir(): string
    {
        $dir = $this->rootPath . '/storage/app/exports';
        if (!is_dir($dir)) { mkdir($dir, 0775, true); }
        return $dir;
    }

    private function importsDir(): string
    {
        $dir = $this->rootPath . '/storage/app/imports';
        if (!is_dir($dir)) { mkdir($dir, 0775, true); }
        return $dir;
    }

    /** @return array<string, mixed> */
    private function readJsonIfExists(string $path): array
    {
        if (!is_file($path)) { return []; }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string, mixed>> */
    private function scanManifestDirectory(string $relative): array
    {
        $items = [];
        $dir = $this->rootPath . '/' . $relative;
        foreach (glob($dir . '/*/cajeer.extension.json') ?: [] as $manifest) {
            $decoded = json_decode((string) file_get_contents($manifest), true);
            if (is_array($decoded)) {
                $decoded['_path'] = substr($manifest, strlen($this->rootPath) + 1);
                $items[] = $decoded;
            }
        }
        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function safeArray(callable $callback): array
    {
        try {
            $value = $callback();
            return is_array($value) ? array_values($value) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveImportPath(string $file): string
    {
        if (is_file($file)) { return $file; }
        $candidate = $this->importsDir() . '/' . basename($file);
        if (is_file($candidate)) { return $candidate; }
        $candidate = $this->exportsDir() . '/' . basename($file);
        if (is_file($candidate)) { return $candidate; }
        throw new \InvalidArgumentException('Файл импорта не найден: ' . $file);
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $filename) ?: 'export.json';
        return str_ends_with($filename, '.json') ? $filename : $filename . '.json';
    }

    /** @param array<string, mixed> $result */
    private function writeImportLog(array $result): void
    {
        $path = $this->rootPath . '/storage/app/imports/import-log.jsonl';
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0775, true); }
        file_put_contents($path, json_encode(array_merge(['time' => (new \DateTimeImmutable())->format(DATE_ATOM)], $result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
