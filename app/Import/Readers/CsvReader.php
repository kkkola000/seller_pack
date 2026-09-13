<?php
declare(strict_types=1);

namespace App\Import\Readers;

use Generator;
use RuntimeException;

/**
 * Чтение CSV/TXT с автоопределением кодировки (UTF-8 / Windows-1251)
 * и разделителя (; , tab |).
 */
final class CsvReader implements RowReader
{
    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = 'auto',
        private readonly string $encoding = 'auto',
    ) {
    }

    public function rows(): Generator
    {
        if (!is_readable($this->path)) {
            throw new RuntimeException('Файл недоступен для чтения: ' . basename($this->path));
        }

        $encoding  = $this->encoding === 'auto' ? $this->detectEncoding() : strtoupper($this->encoding);
        $delimiter = $this->delimiter === 'auto' ? $this->detectDelimiter($encoding) : $this->unescapeDelimiter($this->delimiter);

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть файл: ' . basename($this->path));
        }

        if ($encoding !== 'UTF-8') {
            $filter = @stream_filter_append($handle, 'convert.iconv.' . $encoding . '/UTF-8//TRANSLIT', STREAM_FILTER_READ);
            if ($filter === false) {
                fclose($handle);
                throw new RuntimeException('Не поддерживается кодировка файла: ' . $encoding);
            }
        }

        $first = true;
        try {
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue; // пустая строка
                }
                $row = array_map(static fn ($value): string => (string) $value, $row);
                if ($first) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
                    $first = false;
                }
                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    public function preview(int $limit = 10): array
    {
        $rows = [];
        foreach ($this->rows() as $row) {
            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    private function detectEncoding(): string
    {
        $sample = (string) file_get_contents($this->path, false, null, 0, 65536);
        if ($sample === '') {
            return 'UTF-8';
        }
        if (str_starts_with($sample, "\xEF\xBB\xBF") || mb_check_encoding($sample, 'UTF-8')) {
            return 'UTF-8';
        }

        return 'CP1251';
    }

    private function detectDelimiter(string $encoding): string
    {
        $sample = (string) file_get_contents($this->path, false, null, 0, 65536);
        if ($encoding !== 'UTF-8') {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $sample);
            $sample = $converted === false ? $sample : $converted;
        }

        $line = '';
        foreach (preg_split('/\r\n|\r|\n/', $sample) ?: [] as $candidate) {
            if (trim($candidate) !== '') {
                $line = $candidate;
                break;
            }
        }

        $best = ';';
        $bestCount = 0;
        foreach ([';', "\t", ',', '|'] as $delimiter) {
            $count = substr_count($line, $delimiter);
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function unescapeDelimiter(string $delimiter): string
    {
        return match ($delimiter) {
            'tab', '\t' => "\t",
            'semicolon' => ';',
            'comma'     => ',',
            'pipe'      => '|',
            ''          => ';',
            default     => substr($delimiter, 0, 1),
        };
    }
}
