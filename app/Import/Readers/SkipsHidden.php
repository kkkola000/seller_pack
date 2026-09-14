<?php
declare(strict_types=1);

namespace App\Import\Readers;

/**
 * Читалка умеет пропускать строки и столбцы, скрытые в книге Excel,
 * и рассказывать, сколько пропустила.
 */
interface SkipsHidden
{
    public function hiddenRowsSkipped(): int;

    public function hiddenColumnsSkipped(): int;
}
