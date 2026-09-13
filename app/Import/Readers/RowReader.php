<?php
declare(strict_types=1);

namespace App\Import\Readers;

use Generator;

/**
 * Табличный источник (Excel/CSV): отдаёт строки как списки значений,
 * индексация столбцов с нуля.
 */
interface RowReader
{
    /** @return Generator<int, list<string>> */
    public function rows(): Generator;

    /**
     * Первые N строк — для предпросмотра и подсказок по маппингу.
     *
     * @return list<list<string>>
     */
    public function preview(int $limit = 10): array;
}
