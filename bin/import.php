<?php
declare(strict_types=1);

/**
 * Импорт из командной строки (планировщик задач Plesk → «Запустить команду»).
 *
 *   php /var/www/vhosts/example.com/catalog/bin/import.php --due
 *   php bin/import.php --all
 *   php bin/import.php --source=3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Этот скрипт запускается только из командной строки.');
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Import\ImportService;
use App\Models\SourceRepository;

@set_time_limit(0);

$options = getopt('', ['all', 'due', 'source::', 'quiet']);
$quiet = isset($options['quiet']);

$log = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
    }
};

try {
    if (isset($options['source'])) {
        $source = SourceRepository::find((int) $options['source']);
        if ($source === null) {
            fwrite(STDERR, 'Источник не найден: ' . $options['source'] . PHP_EOL);
            exit(1);
        }
        $sources = [$source];
    } elseif (isset($options['all'])) {
        $sources = SourceRepository::list(['only_active' => true]);
    } else {
        $sources = SourceRepository::dueForImport();
    }

    if ($sources === []) {
        $log('Нечего импортировать.');
        exit(0);
    }

    $log('Источников к импорту: ' . count($sources));
    $failed = 0;

    foreach (ImportService::runMany($sources, isset($options['source']) || isset($options['all']) ? 'manual' : 'cron') as $result) {
        $log(sprintf('%s — %s. %s', $result['source_name'], $result['status'] === 'ok' ? 'OK' : 'ОШИБКА', $result['message']));
        if ($result['status'] !== 'ok') {
            $failed++;
        }
    }

    exit($failed > 0 ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Критическая ошибка: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
