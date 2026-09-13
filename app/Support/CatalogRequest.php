<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\ProductRepository;

/**
 * Разбор параметров витрины (одинаковый для HTML-страницы и для API).
 */
final class CatalogRequest
{
    public const SORTS = [
        'default'    => 'Сначала в наличии',
        'price_asc'  => 'Цена: по возрастанию',
        'price_desc' => 'Цена: по убыванию',
        'name_asc'   => 'Название: А-Я',
        'name_desc'  => 'Название: Я-А',
        'newest'     => 'Сначала обновлённые',
    ];

    /**
     * @param array<string,mixed> $query
     * @return array{q:string,suppliers:list<int>,availability:list<string>,sort:string,page:int,per_page:int}
     */
    public static function fromQuery(array $query): array
    {
        $suppliers = $query['supplier'] ?? [];
        if (is_string($suppliers)) {
            $suppliers = $suppliers === '' ? [] : explode(',', $suppliers);
        }
        $suppliers = array_values(array_unique(array_filter(array_map('intval', (array) $suppliers))));

        $availability = $query['availability'] ?? [];
        if (is_string($availability)) {
            $availability = $availability === '' ? [] : explode(',', $availability);
        }
        $availability = array_values(array_intersect(
            array_map('strval', (array) $availability),
            array_keys(ProductRepository::AVAILABILITY)
        ));

        $sort = (string) ($query['sort'] ?? 'default');
        if (!isset(self::SORTS[$sort])) {
            $sort = 'default';
        }

        return [
            'q'            => mb_substr(trim((string) ($query['q'] ?? '')), 0, 120),
            'suppliers'    => $suppliers,
            'availability' => $availability,
            'sort'         => $sort,
            'page'         => max(1, (int) ($query['page'] ?? 1)),
            'per_page'     => (int) Config::get('per_page', 24),
        ];
    }

    /** @param array<string,mixed> $filters */
    public static function isFiltered(array $filters): bool
    {
        return $filters['q'] !== ''
            || $filters['suppliers'] !== []
            || $filters['availability'] !== []
            || $filters['sort'] !== 'default';
    }
}
