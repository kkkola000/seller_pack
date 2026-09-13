<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Db;

final class ImportRunRepository
{
    public static function start(int $sourceId, string $trigger): int
    {
        Db::run(
            'INSERT INTO import_runs (source_id, trigger_type, status, started_at) VALUES (?, ?, ?, NOW())',
            [$sourceId, $trigger, 'running']
        );

        return Db::lastInsertId();
    }

    /** @param array<string,mixed> $stats */
    public static function finish(int $runId, string $status, array $stats = [], ?string $message = null): void
    {
        Db::run(
            'UPDATE import_runs
                SET status = ?, finished_at = NOW(), rows_read = ?, rows_imported = ?,
                    rows_skipped = ?, rows_deleted = ?, message = ?
              WHERE id = ?',
            [
                $status,
                (int) ($stats['rows_read'] ?? 0),
                (int) ($stats['rows_imported'] ?? 0),
                (int) ($stats['rows_skipped'] ?? 0),
                (int) ($stats['rows_deleted'] ?? 0),
                $message !== null ? mb_substr($message, 0, 4000) : null,
                $runId,
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recent(?int $sourceId = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT r.*, s.name AS source_name
                  FROM import_runs r
                  JOIN sources s ON s.id = r.source_id';
        $params = [];
        if ($sourceId !== null) {
            $sql .= ' WHERE r.source_id = ?';
            $params[] = $sourceId;
        }
        $sql .= ' ORDER BY r.id DESC LIMIT ' . $limit;

        return Db::all($sql, $params);
    }
}
