<?php

declare(strict_types=1);

namespace CajeerEngine\Migration;

/** Independent checks of content receipts and revision provenance before site activation. */
final class MigrationIntegrity
{
    private const IMPORT_REASONS = ['migration_import', 'migration_media', 'migration_seo'];

    /**
     * Detect missing/altered receipts and claims to the same target UUID.
     * @return list<array{source:string,code:string}>
     */
    public static function receiptProblems(array $approved, array $receipts): array
    {
        $expected = [];
        $problems = [];
        foreach ($approved as $row) {
            $key = (string)($row['source_type'] ?? '') . ':' . (string)($row['source_id'] ?? '');
            if (isset($expected[$key])) {
                $problems[] = ['source' => $key, 'code' => 'duplicate_approved_source'];
            }
            $expected[$key] = $row;
        }
        $seen = [];
        $targets = [];
        foreach ($receipts as $row) {
            $key = (string)($row['source_type'] ?? '') . ':' . (string)($row['source_id'] ?? '');
            if (isset($seen[$key])) {
                $problems[] = ['source' => $key, 'code' => 'duplicate_import_receipt'];
                continue;
            }
            $seen[$key] = true;
            $planned = $expected[$key] ?? null;
            if ($planned === null) {
                $problems[] = ['source' => $key, 'code' => 'unapproved_import_receipt'];
                continue;
            }
            if (($planned['action'] ?? null) !== ($row['action'] ?? null)
                || ($planned['target_type'] ?? null) !== ($row['target_type'] ?? null)
                || (in_array(($row['action'] ?? ''), ['create', 'update'], true)
                    && (!is_string($row['target_id'] ?? null)
                        || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $row['target_id'])
                        || (isset($planned['slug']) && $planned['slug'] !== ($row['slug'] ?? null))
                        || (($row['action'] ?? '') === 'update' && ($planned['target_id'] ?? null) !== ($row['target_id'] ?? null))))) {
                $problems[] = ['source' => $key, 'code' => 'import_receipt_disagrees_with_plan'];
            }
            if (in_array(($row['action'] ?? ''), ['create', 'update'], true)) {
                $id = (string)($row['target_id'] ?? '');
                if (isset($targets[$id])) {
                    $problems[] = ['source' => $key, 'code' => 'duplicate_import_target'];
                }
                $targets[$id] = true;
            }
        }
        foreach ($expected as $key => $_row) {
            if (!isset($seen[$key])) {
                $problems[] = ['source' => $key, 'code' => 'missing_import_receipt'];
            }
        }
        return $problems;
    }

    /**
     * A revision is trusted only if the complete chain since a committed migration
     * receipt comes from our labelled migration writers. For pre-1.5.0 media
     * rewrites, the exact stored data digest can justify one legacy revision.
     * Unlabelled SEO/editor changes MUST be manually resolved, never auto-approved.
     *
     * @return null|string reason for refusing the target revision
     */
    public static function revisionProblem(array $entry, array $revisions, int $committedRevision, ?array $media = null): ?string
    {
        $current = (int)($entry['revision'] ?? 0);
        if ($committedRevision < 1 || $current < $committedRevision) {
            return 'invalid_target_revision';
        }
        $history = [];
        foreach ($revisions as $revision) {
            if (!is_array($revision)) {
                continue;
            }
            $number = (int)($revision['revision'] ?? 0);
            if ($number > 0 && isset($history[$number])) {
                return 'duplicate_revision_history';
            }
            if ($number > 0) {
                $history[$number] = $revision;
            }
        }
        $latest = $history[$current] ?? null;
        if ($latest === null) {
            return 'target_revision_history_missing';
        }
        foreach (['title', 'slug', 'status', 'locale'] as $field) {
            if (($entry[$field] ?? null) !== ($latest[$field] ?? null)) {
                return 'target_snapshot_mismatch';
            }
        }
        if (self::dataHash($entry['data'] ?? []) !== self::dataHash($latest['data'] ?? [])) {
            return 'target_snapshot_mismatch';
        }
        for ($revision = $committedRevision + 1; $revision <= $current; $revision++) {
            $snapshot = $history[$revision] ?? null;
            if ($snapshot === null) {
                return 'target_revision_history_missing';
            }
            if (in_array(($snapshot['reason'] ?? null), self::IMPORT_REASONS, true)) {
                continue;
            }
            if ($revision === $committedRevision + 1 && $revision === $current
                && ($snapshot['reason'] ?? '') === 'updated'
                && ($media['status'] ?? '') === 'done'
                && (int)($media['original_revision'] ?? -1) === $committedRevision
                && is_string($media['updated_sha'] ?? null)
                && hash_equals($media['updated_sha'], self::dataHash($entry['data'] ?? []))) {
                continue;
            }
            return 'unverified_editorial_revision';
        }
        return null;
    }

    private static function dataHash(mixed $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
