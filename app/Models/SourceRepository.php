<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Db;

final class SourceRepository
{
    public const TYPES = [
        'excel' => 'Excel (.xlsx)',
        'csv'   => 'CSV / TXT',
        'yml'   => 'YML-фид (XML)',
    ];

    public const FETCH_METHODS = [
        'upload' => 'Загрузка файла вручную',
        'url'    => 'Ссылка (скачивание по расписанию)',
    ];

    /** Поля маппинга и их обязательность. */
    public const FIELDS = [
        'name'  => ['label' => 'Название товара', 'required' => true],
        'sku'   => ['label' => 'Артикул / SKU',   'required' => true],
        'price' => ['label' => 'Цена',            'required' => true],
        'stock' => ['label' => 'Остаток/наличие', 'required' => true],
        'image' => ['label' => 'Фото (URL)',      'required' => false],
        'currency' => ['label' => 'Валюта (столбец)', 'required' => false],
    ];

    public const DEFAULT_IN_STOCK_VALUES = 'да,есть,в наличии,+,true,1,in stock,instock,available,много';

    /** @return array<string,string> Маппинг по умолчанию для YML-фида. */
    public static function defaultYmlMapping(): array
    {
        return [
            'name'     => 'name',
            'sku'      => 'vendorCode',
            'price'    => 'price',
            'stock'    => 'count',
            'image'    => 'picture',
            'currency' => 'currencyId',
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function list(array $filters = []): array
    {
        $sql = 'SELECT * FROM sources WHERE 1';
        $params = [];

        if (!empty($filters['only_active'])) {
            $sql .= ' AND is_active = 1';
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND name LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        $sql .= ' ORDER BY name';

        return array_map([self::class, 'hydrate'], Db::all($sql, $params));
    }

    /** Поставщики, у которых есть хотя бы один товар — для фильтра на витрине. */
    public static function listForCatalog(): array
    {
        return Db::all(
            'SELECT s.id, s.name, COUNT(p.id) AS products_count
               FROM sources s
               JOIN products p ON p.source_id = s.id
              WHERE s.is_active = 1
              GROUP BY s.id, s.name
              HAVING products_count > 0
              ORDER BY s.name'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Db::first('SELECT * FROM sources WHERE id = ?', [$id]);
        return $row === null ? null : self::hydrate($row);
    }

    /** Источники, которым пора выполнить автоматический импорт. */
    public static function dueForImport(): array
    {
        $rows = Db::all(
            "SELECT * FROM sources
              WHERE is_active = 1
                AND auto_import = 1
                AND fetch_method = 'url'
                AND source_url IS NOT NULL AND source_url <> ''
                AND (last_run_at IS NULL
                     OR last_run_at <= DATE_SUB(NOW(), INTERVAL import_interval_minutes MINUTE))
              ORDER BY last_run_at IS NOT NULL, last_run_at"
        );

        return array_map([self::class, 'hydrate'], $rows);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $data['mapping'] = json_encode($data['mapping'] ?? [], JSON_UNESCAPED_UNICODE);
        $columns = array_keys($data);
        $sql = 'INSERT INTO sources (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')';
        Db::run($sql, $data);

        return Db::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (isset($data['mapping']) && is_array($data['mapping'])) {
            $data['mapping'] = json_encode($data['mapping'], JSON_UNESCAPED_UNICODE);
        }
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = :' . $column;
        }
        $data['id'] = $id;
        Db::run('UPDATE sources SET ' . implode(', ', $sets) . ' WHERE id = :id', $data);
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::run('UPDATE sources SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    /** Полное удаление источника вместе с товарами. */
    public static function delete(int $id): void
    {
        $source = self::find($id);
        if ($source !== null && !empty($source['file_path'])) {
            $file = APP_STORAGE . '/uploads/' . $source['file_path'];
            if (is_file($file)) {
                @unlink($file);
            }
        }
        Db::run('DELETE FROM sources WHERE id = ?', [$id]);
    }

    public static function refreshProductsCount(int $id): int
    {
        $count = (int) Db::scalar('SELECT COUNT(*) FROM products WHERE source_id = ?', [$id]);
        Db::run('UPDATE sources SET products_count = ? WHERE id = ?', [$count, $id]);

        return $count;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function hydrate(array $row): array
    {
        $mapping = [];
        if (!empty($row['mapping'])) {
            $decoded = json_decode((string) $row['mapping'], true);
            if (is_array($decoded)) {
                $mapping = $decoded;
            }
        }
        $row['mapping_array'] = $mapping;

        return $row;
    }

    /** Человекочитаемый статус для списка источников. */
    public static function statusLabel(array $source): array
    {
        if ((int) $source['is_active'] === 0) {
            return ['disabled', 'Отключен'];
        }

        return match ($source['last_status']) {
            'ok'      => ['ok', 'Активен'],
            'error'   => ['error', 'Ошибка'],
            'running' => ['running', 'Импорт идёт'],
            default   => ['never', 'Активен (без импорта)'],
        };
    }
}
