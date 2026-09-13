<?php
declare(strict_types=1);

namespace App\Import;

use App\Support\Config;
use RuntimeException;

/**
 * Получение файла источника: локальная загрузка или скачивание по ссылке.
 */
final class Fetcher
{
    /**
     * @param array<string,mixed> $source
     * @return array{0:string,1:bool} путь к файлу и признак «временный»
     */
    public static function resolve(array $source): array
    {
        if ($source['fetch_method'] === 'url') {
            $url = trim((string) ($source['source_url'] ?? ''));
            if ($url === '') {
                throw new RuntimeException('Для источника не указана ссылка на файл.');
            }

            return [self::download($url), true];
        }

        $file = trim((string) ($source['file_path'] ?? ''));
        if ($file === '') {
            throw new RuntimeException('Файл не загружен. Загрузите прайс на странице редактирования источника.');
        }

        $path = APP_STORAGE . '/uploads/' . basename($file);
        if (!is_file($path)) {
            throw new RuntimeException('Загруженный файл не найден на диске: ' . basename($file));
        }

        return [$path, false];
    }

    public static function download(string $url): string
    {
        if (!preg_match('~^https?://~i', $url)) {
            throw new RuntimeException('Ссылка должна начинаться с http:// или https://');
        }

        $target = tempnam(APP_STORAGE . '/tmp', 'dl_');
        if ($target === false) {
            throw new RuntimeException('Нет доступа на запись в storage/tmp.');
        }

        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось создать временный файл для загрузки.');
        }

        if (!function_exists('curl_init')) {
            fclose($handle);
            @unlink($target);
            throw new RuntimeException('Для скачивания по ссылке нужен модуль PHP «curl».');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => (int) Config::get('download_timeout', 120),
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'SupplierCatalog/1.0 (+PHP importer)',
            CURLOPT_FAILONERROR    => false,
        ]);

        $ok    = curl_exec($curl);
        $code  = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok === false) {
            @unlink($target);
            throw new RuntimeException('Ошибка скачивания файла: ' . ($error !== '' ? $error : 'нет ответа от сервера'));
        }
        if ($code >= 400) {
            @unlink($target);
            throw new RuntimeException('Сервер поставщика вернул код ' . $code . ' при скачивании файла.');
        }
        if (filesize($target) === 0) {
            @unlink($target);
            throw new RuntimeException('По ссылке скачан пустой файл.');
        }

        $max = (int) Config::get('max_upload_bytes', 52428800);
        if ($max > 0 && filesize($target) > $max) {
            @unlink($target);
            throw new RuntimeException('Файл по ссылке больше допустимого размера (' . round($max / 1048576) . ' МБ).');
        }

        return $target;
    }

    /** Сохраняет загруженный админом файл, возвращает имя внутри storage/uploads. */
    public static function storeUpload(array $file, string $type): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $max = (int) Config::get('max_upload_bytes', 52428800);
        if ($max > 0 && (int) $file['size'] > $max) {
            throw new RuntimeException('Файл больше допустимого размера (' . round($max / 1048576) . ' МБ).');
        }

        $original  = (string) ($file['name'] ?? 'price');
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = match ($type) {
            'excel' => ['xlsx', 'xlsm'],
            'csv'   => ['csv', 'txt', 'tsv'],
            'yml'   => ['xml', 'yml'],
            default => [],
        };
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'Для типа «%s» ожидается файл %s, а загружен .%s',
                $type,
                implode(' / ', array_map(static fn ($e) => '.' . $e, $allowed)),
                $extension !== '' ? $extension : '(без расширения)'
            ));
        }

        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $path = APP_STORAGE . '/uploads/' . $name;

        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $path)
            : rename((string) $file['tmp_name'], $path);

        if (!$moved) {
            throw new RuntimeException('Не удалось сохранить файл в storage/uploads (проверьте права доступа).');
        }

        return $name;
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит загрузки на сервере (upload_max_filesize / post_max_size).',
            UPLOAD_ERR_PARTIAL   => 'Файл загружен не полностью, повторите попытку.',
            UPLOAD_ERR_NO_FILE   => 'Файл не выбран.',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере нет временной папки для загрузок.',
            UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать файл на диск.',
            default => 'Ошибка загрузки файла (код ' . $code . ').',
        };
    }
}
