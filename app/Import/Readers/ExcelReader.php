<?php
declare(strict_types=1);

namespace App\Import\Readers;

use RuntimeException;

/**
 * Выбирает читалку Excel по содержимому файла, а не по расширению:
 * при скачивании по ссылке расширения часто нет вовсе.
 */
final class ExcelReader
{
    private const ZIP_SIGNATURE = "PK\x03\x04";   // .xlsx — это zip-архив
    private const CFB_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"; // .xls — контейнер OLE2

    public static function open(string $path, int $sheetIndex = 1): RowReader
    {
        return match (self::format($path)) {
            'xlsx'  => new XlsxReader($path, $sheetIndex),
            'xls'   => new XlsReader($path, $sheetIndex),
            default => throw new RuntimeException(
                'Файл не похож ни на .xlsx, ни на .xls. Если это CSV — выберите тип источника «CSV».'
            ),
        };
    }

    /** 'xlsx' | 'xls' | '' */
    public static function format(string $path): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 8);

        if (str_starts_with($head, self::ZIP_SIGNATURE)) {
            return 'xlsx';
        }
        if (str_starts_with($head, self::CFB_SIGNATURE)) {
            return 'xls';
        }

        return '';
    }

    public static function isExcel(string $path): bool
    {
        return self::format($path) !== '';
    }
}
