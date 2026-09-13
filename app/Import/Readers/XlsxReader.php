<?php
declare(strict_types=1);

namespace App\Import\Readers;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Потоковое чтение .xlsx средствами ZipArchive + XMLReader,
 * без внешних библиотек (важно для хостинга без Composer).
 */
final class XlsxReader implements RowReader
{
    private const MAX_GAP_FILL = 5000;

    /** @var list<string> */
    private array $tempFiles = [];

    public function __construct(
        private readonly string $path,
        private readonly int $sheetIndex = 1,
    ) {
    }

    public function rows(): Generator
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Для чтения Excel нужен модуль PHP «zip». Включите его в настройках PHP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('Не удалось открыть файл Excel. Поддерживается формат .xlsx (Excel 2007+).');
        }

        try {
            $sheetPath   = $this->resolveSheetPath($zip);
            $sharedStr   = $this->readSharedStrings($zip);
            $sheetFile   = $this->extract($zip, $sheetPath);

            $reader = new XMLReader();
            if (!$reader->open('file://' . $sheetFile)) {
                throw new RuntimeException('Не удалось прочитать лист книги Excel.');
            }

            try {
                $expectedRow = 1;
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
                        continue;
                    }

                    $rowNumber = (int) ($reader->getAttribute('r') ?: $expectedRow);
                    $gap = $rowNumber - $expectedRow;
                    if ($gap > 0 && $gap <= self::MAX_GAP_FILL) {
                        for ($i = 0; $i < $gap; $i++) {
                            yield [];
                        }
                    }
                    $expectedRow = $rowNumber + 1;

                    $xml = $reader->readOuterXml();
                    if ($xml === '') {
                        continue;
                    }
                    yield $this->parseRow($xml, $sharedStr);
                }
            } finally {
                $reader->close();
            }
        } finally {
            $zip->close();
            $this->cleanup();
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

    /** Названия листов книги — для выбора в админке. */
    public function sheetNames(): array
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) {
            return [];
        }

        $names = [];
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook !== false && preg_match_all('/<sheet[^>]*name="([^"]*)"/i', $workbook, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $names[] = html_entity_decode($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $zip->close();

        return $names;
    }

    /**
     * @param list<string> $sharedStrings
     * @return list<string>
     */
    private function parseRow(string $xml, array $sharedStrings): array
    {
        $cells = [];
        $maxIndex = -1;

        $reader = new XMLReader();
        if (!$reader->XML($xml)) {
            return [];
        }

        $autoIndex = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'c') {
                continue;
            }

            $ref  = (string) $reader->getAttribute('r');
            $type = (string) $reader->getAttribute('t');
            $index = $ref !== '' ? $this->columnIndexFromRef($ref) : $autoIndex;
            $autoIndex = $index + 1;

            $cellXml = $reader->readOuterXml();
            $value = $this->cellValue($cellXml, $type, $sharedStrings);

            $cells[$index] = $value;
            $maxIndex = max($maxIndex, $index);
        }
        $reader->close();

        $row = [];
        for ($i = 0; $i <= $maxIndex; $i++) {
            $row[$i] = $cells[$i] ?? '';
        }

        return $row;
    }

    /** @param list<string> $sharedStrings */
    private function cellValue(string $cellXml, string $type, array $sharedStrings): string
    {
        if ($type === 'inlineStr') {
            if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $cellXml, $matches) > 0) {
                return $this->decode(implode('', $matches[1]));
            }
            return '';
        }

        if (preg_match('~<v[^>]*>(.*?)</v>~s', $cellXml, $match) !== 1) {
            return '';
        }
        $raw = $this->decode($match[1]);

        return match ($type) {
            's'  => $sharedStrings[(int) $raw] ?? '',
            'b'  => $raw === '1' ? '1' : '0',
            'e'  => '',
            default => $raw,
        };
    }

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function columnIndexFromRef(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
        if ($letters === '') {
            return 0;
        }

        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    /** @return list<string> */
    private function readSharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $file = $this->extract($zip, 'xl/sharedStrings.xml');
        $reader = new XMLReader();
        if (!$reader->open('file://' . $file)) {
            return [];
        }

        $strings = [];
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                $xml = $reader->readOuterXml();
                $text = '';
                if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $xml, $matches) > 0) {
                    $text = implode('', $matches[1]);
                }
                $strings[] = $this->decode($text);
            }
        }
        $reader->close();

        return $strings;
    }

    private function resolveSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $number   = max(1, $this->sheetIndex);

        if ($workbook !== false && $rels !== false
            && preg_match_all('/<sheet\b[^>]*>/i', $workbook, $sheetTags) > 0) {
            $tags = $sheetTags[0];
            if (!isset($tags[$number - 1])) {
                throw new RuntimeException(sprintf(
                    'В книге всего %d лист(ов), а указан лист №%d.',
                    count($tags),
                    $number
                ));
            }
            if (preg_match('/r:id="([^"]+)"/i', $tags[$number - 1], $idMatch) === 1
                && preg_match('/<Relationship[^>]*Id="' . preg_quote($idMatch[1], '/') . '"[^>]*Target="([^"]+)"/i', $rels, $targetMatch) === 1) {
                $target = ltrim($targetMatch[1], '/');
                $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                if ($zip->locateName($target) !== false) {
                    return $target;
                }
            }
        }

        $fallback = 'xl/worksheets/sheet' . $number . '.xml';
        if ($zip->locateName($fallback) === false) {
            throw new RuntimeException('В файле Excel не найден лист №' . $number . '.');
        }

        return $fallback;
    }

    private function extract(ZipArchive $zip, string $entry): string
    {
        $stream = $zip->getStream($entry);
        if ($stream === false) {
            throw new RuntimeException('Повреждён файл Excel: не читается ' . $entry);
        }

        $tmp = tempnam(APP_STORAGE . '/tmp', 'xlsx_');
        if ($tmp === false) {
            throw new RuntimeException('Нет доступа на запись в storage/tmp.');
        }
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw new RuntimeException('Не удалось создать временный файл.');
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        $this->tempFiles[] = $tmp;

        return $tmp;
    }

    private function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }
}
