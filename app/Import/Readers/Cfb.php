<?php
declare(strict_types=1);

namespace App\Import\Readers;

use RuntimeException;

/**
 * Контейнер Compound File Binary (OLE2) — в нём лежат файлы .xls и .doc.
 * Нужен, чтобы достать из книги поток «Workbook» с данными BIFF.
 */
final class Cfb
{
    private const SIGNATURE   = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const END_OF_CHAIN = 0xFFFFFFFE;
    private const FREE_SECTOR  = 0xFFFFFFFF;
    private const MAX_SECTORS  = 2000000; // защита от зацикливания на битом файле

    private string $data;
    private int $sectorSize;
    private int $miniSectorSize;
    private int $miniStreamCutoff;
    /** @var list<int> */
    private array $fat = [];
    /** @var list<int> */
    private array $miniFat = [];
    /** @var list<array{name:string,type:int,start:int,size:int}> */
    private array $entries = [];
    private string $miniStream = '';

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 512) {
            throw new RuntimeException('Файл Excel не читается или повреждён.');
        }
        $this->data = $data;

        if (substr($data, 0, 8) !== self::SIGNATURE) {
            throw new RuntimeException('Это не файл Excel 97–2003 (.xls).');
        }

        $this->sectorSize       = 1 << $this->uint16(30);
        $this->miniSectorSize   = 1 << $this->uint16(32);
        $this->miniStreamCutoff = $this->uint32(56);

        $this->readFat();
        $this->readDirectory();
        $this->readMiniFat();
    }

    /** Содержимое потока по имени (без учёта регистра), null — если такого нет. */
    public function stream(string $name): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['type'] === 2 && mb_strtolower($entry['name']) === mb_strtolower($name)) {
                return $this->readEntry($entry);
            }
        }

        return null;
    }

    /** @return list<string> имена потоков — для диагностики */
    public function streamNames(): array
    {
        $names = [];
        foreach ($this->entries as $entry) {
            if ($entry['type'] === 2) {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }

    /* ---------------- разбор служебных структур ---------------- */

    private function readFat(): void
    {
        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $sector = $this->uint32(76 + $i * 4);
            if ($sector === self::FREE_SECTOR) {
                break;
            }
            $difat[] = $sector;
        }

        // Продолжение DIFAT в отдельных секторах
        $next = $this->uint32(68);
        $count = $this->uint32(72);
        $perSector = intdiv($this->sectorSize, 4) - 1;
        for ($i = 0; $i < $count && $next !== self::END_OF_CHAIN && $next !== self::FREE_SECTOR; $i++) {
            $offset = $this->sectorOffset($next);
            for ($j = 0; $j < $perSector; $j++) {
                $sector = $this->uint32($offset + $j * 4);
                if ($sector !== self::FREE_SECTOR) {
                    $difat[] = $sector;
                }
            }
            $next = $this->uint32($offset + $perSector * 4);
        }

        foreach ($difat as $sector) {
            $offset = $this->sectorOffset($sector);
            for ($i = 0; $i < intdiv($this->sectorSize, 4); $i++) {
                $this->fat[] = $this->uint32($offset + $i * 4);
            }
        }
    }

    private function readDirectory(): void
    {
        $dir = $this->readChain($this->uint32(48));
        $count = intdiv(strlen($dir), 128);

        for ($i = 0; $i < $count; $i++) {
            $base = $i * 128;
            $nameLength = max(0, $this->uint16FromString($dir, $base + 64) - 2);
            $name = @mb_convert_encoding(substr($dir, $base, $nameLength), 'UTF-8', 'UTF-16LE');
            $type = ord($dir[$base + 66] ?? "\0");
            if ($type !== 2 && $type !== 5) {
                continue;
            }

            $entry = [
                'name'  => is_string($name) ? $name : '',
                'type'  => $type,
                'start' => $this->uint32FromString($dir, $base + 116),
                'size'  => $this->uint32FromString($dir, $base + 120),
            ];

            if ($type === 5) {
                // Корневая запись хранит мини-поток, в котором лежат мелкие файлы
                $this->miniStream = $this->readChain($entry['start'], $entry['size']);
            }
            $this->entries[] = $entry;
        }
    }

    private function readMiniFat(): void
    {
        $data = $this->readChain($this->uint32(60));
        for ($i = 0; $i < intdiv(strlen($data), 4); $i++) {
            $this->miniFat[] = $this->uint32FromString($data, $i * 4);
        }
    }

    /** @param array{name:string,type:int,start:int,size:int} $entry */
    private function readEntry(array $entry): string
    {
        if ($entry['size'] < $this->miniStreamCutoff) {
            return $this->readMiniChain($entry['start'], $entry['size']);
        }

        return $this->readChain($entry['start'], $entry['size']);
    }

    private function readChain(int $sector, ?int $size = null): string
    {
        $out = '';
        $guard = 0;
        while ($sector !== self::END_OF_CHAIN && $sector !== self::FREE_SECTOR) {
            if (++$guard > self::MAX_SECTORS) {
                throw new RuntimeException('Файл Excel повреждён: зациклена таблица секторов.');
            }
            $out .= substr($this->data, $this->sectorOffset($sector), $this->sectorSize);
            $sector = $this->fat[$sector] ?? self::END_OF_CHAIN;
        }

        return $size === null ? $out : substr($out, 0, $size);
    }

    private function readMiniChain(int $sector, int $size): string
    {
        $out = '';
        $guard = 0;
        while ($sector !== self::END_OF_CHAIN && $sector !== self::FREE_SECTOR) {
            if (++$guard > self::MAX_SECTORS) {
                throw new RuntimeException('Файл Excel повреждён: зациклен мини-поток.');
            }
            $out .= substr($this->miniStream, $sector * $this->miniSectorSize, $this->miniSectorSize);
            $sector = $this->miniFat[$sector] ?? self::END_OF_CHAIN;
        }

        return substr($out, 0, $size);
    }

    private function sectorOffset(int $sector): int
    {
        return ($sector + 1) * $this->sectorSize;
    }

    private function uint16(int $offset): int
    {
        return $this->uint16FromString($this->data, $offset);
    }

    private function uint32(int $offset): int
    {
        return $this->uint32FromString($this->data, $offset);
    }

    private function uint16FromString(string $string, int $offset): int
    {
        $chunk = substr($string, $offset, 2);
        return strlen($chunk) < 2 ? 0 : (int) unpack('v', $chunk)[1];
    }

    private function uint32FromString(string $string, int $offset): int
    {
        $chunk = substr($string, $offset, 4);
        return strlen($chunk) < 4 ? self::END_OF_CHAIN : (int) unpack('V', $chunk)[1];
    }
}
