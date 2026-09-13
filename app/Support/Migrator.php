<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Создание таблиц и применение миграций.
 *
 * На чистой базе выполняется только db/schema.sql — он всегда содержит
 * актуальную структуру, а все миграции сразу помечаются применёнными.
 * На уже работающей установке выполняются только недостающие миграции.
 */
final class Migrator
{
    /**
     * @return array{fresh:bool,statements:int,applied:list<string>,skipped:int}
     */
    public static function migrate(): array
    {
        $fresh = !Db::tableExists('products');

        $statements = self::runFile(APP_ROOT . '/db/schema.sql');

        $files = self::migrationFiles();
        $applied = [];
        $skipped = 0;

        foreach ($files as $version => $file) {
            if (self::isApplied($version)) {
                $skipped++;
                continue;
            }
            if ($fresh) {
                // Структура из schema.sql уже актуальна — миграцию только отмечаем
                self::markApplied($version);
                $skipped++;
                continue;
            }

            try {
                self::runFile($file);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Миграция ' . $version . ' не применилась: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
            self::markApplied($version);
            $applied[] = $version;
        }

        return ['fresh' => $fresh, 'statements' => $statements, 'applied' => $applied, 'skipped' => $skipped];
    }

    /** @return list<string> Версии миграций, которые ещё не применены. */
    public static function pending(): array
    {
        if (!Db::tableExists('schema_migrations')) {
            return array_keys(self::migrationFiles());
        }

        $pending = [];
        foreach (array_keys(self::migrationFiles()) as $version) {
            if (!self::isApplied($version)) {
                $pending[] = $version;
            }
        }

        return $pending;
    }

    /** @return array<string,string> версия => путь к файлу, по возрастанию версии */
    private static function migrationFiles(): array
    {
        $dir = APP_ROOT . '/db/migrations';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $result = [];
        foreach ($files as $file) {
            $result[basename($file, '.sql')] = $file;
        }

        return $result;
    }

    private static function runFile(string $file): int
    {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        $count = 0;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            Db::pdo()->exec($statement);
            $count++;
        }

        return $count;
    }

    private static function isApplied(string $version): bool
    {
        if (!Db::tableExists('schema_migrations')) {
            return false;
        }

        return Db::first('SELECT version FROM schema_migrations WHERE version = ?', [$version]) !== null;
    }

    private static function markApplied(string $version): void
    {
        Db::run('INSERT IGNORE INTO schema_migrations (version) VALUES (?)', [$version]);
    }
}
