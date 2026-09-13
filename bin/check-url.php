<?php
declare(strict_types=1);

/**
 * Проверка ссылки на прайс: что за сервис, какая получится прямая ссылка,
 * скачивается ли файл и что в нём лежит.
 *
 *   php bin/check-url.php "https://docs.yandex.ru/docs/view?url=ya-disk-public://..." --type=excel
 *   php bin/check-url.php "https://docs.google.com/spreadsheets/d/ID/edit#gid=0" --type=csv
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Этот скрипт запускается только из командной строки.');
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Import\Fetcher;
use App\Import\LinkResolver;
use App\Import\Readers\CsvReader;
use App\Import\Readers\ExcelReader;
use App\Import\Readers\YmlReader;

$args = array_slice($argv, 1);
$url = '';
$type = '';

foreach ($args as $arg) {
    if (str_starts_with($arg, '--type=')) {
        $type = substr($arg, 7);
    } elseif ($url === '') {
        $url = $arg;
    }
}

if ($url === '') {
    fwrite(STDERR, "Укажите ссылку:\n  php bin/check-url.php \"ССЫЛКА\" [--type=excel|csv|yml]\n");
    exit(1);
}

if ($type === '') {
    // Тип угадываем по ссылке: у Яндекс Документов он есть в параметре name
    $type = preg_match('~\.(xlsx|xlsm)~i', $url) === 1 ? 'excel'
        : (preg_match('~\.(csv|txt|tsv)~i', $url) === 1 ? 'csv'
        : (preg_match('~\.(xml|yml)~i', $url) === 1 ? 'yml' : 'excel'));
    echo "Тип источника не указан, предполагаю: $type (задать явно: --type=excel|csv|yml)\n";
}

echo "\nСсылка: $url\n";

try {
    $service = LinkResolver::service($url);
    echo 'Сервис: ' . ($service ?? 'обычная прямая ссылка') . "\n";

    $resolved = LinkResolver::resolve($url, $type);
    if ($resolved['url'] !== $url) {
        echo 'Прямая ссылка на файл: ' . mb_substr($resolved['url'], 0, 160)
            . (mb_strlen($resolved['url']) > 160 ? '…' : '') . "\n";
    }
    if ($resolved['filename'] !== null) {
        echo 'Имя файла: ' . $resolved['filename'] . "\n";
    }

    echo "Скачиваю…\n";
    $start = microtime(true);
    $file = Fetcher::download($url, $type);
    printf("Готово: %s, %.1f КБ, за %.1f с\n", basename($file), filesize($file) / 1024, microtime(true) - $start);

    echo "\nПервые строки файла:\n";
    if ($type === 'yml') {
        $preview = (new YmlReader($file))->preview(3);
        foreach ($preview['offers'] as $i => $offer) {
            $line = [];
            foreach ($offer as $tag => $values) {
                $line[] = $tag . '=' . mb_substr((string) ($values[0] ?? ''), 0, 30);
            }
            echo '  ' . ($i + 1) . ') ' . implode(' | ', array_slice($line, 0, 8)) . "\n";
        }
        echo "\nДоступные теги: " . implode(', ', $preview['tags']) . "\n";
    } else {
        $reader = $type === 'excel' ? ExcelReader::open($file, 1) : new CsvReader($file);
        foreach ($reader->preview(6) as $i => $row) {
            $cells = [];
            foreach (array_slice($row, 0, 8) as $index => $value) {
                $cells[] = App\Import\ColumnRef::toLetter($index) . ': ' . mb_substr((string) $value, 0, 24);
            }
            echo '  строка ' . ($i + 1) . ' — ' . implode(' | ', $cells) . "\n";
        }
        echo "\nСтолбцы для маппинга указывайте буквами из вывода выше.\n";
    }

    @unlink($file);
    echo "\nСсылка рабочая — её можно указать в источнике.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "\nНе получилось: " . $exception->getMessage() . "\n");
    exit(1);
}
