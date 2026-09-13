<?php
declare(strict_types=1);

namespace App\Import;

use App\Support\Config;
use RuntimeException;

/**
 * Превращает «человеческую» ссылку на таблицу в прямую ссылку на файл.
 *
 * Поддерживаются:
 *   • Google Таблицы     https://docs.google.com/spreadsheets/d/ID/edit#gid=0
 *   • Google Диск (файл) https://drive.google.com/file/d/ID/view
 *   • Яндекс Документы   https://docs.yandex.ru/docs/view?url=ya-disk-public://KEY&name=...
 *   • Яндекс Диск        https://disk.yandex.ru/d/KEY , /i/KEY , https://yadi.sk/d/KEY
 *
 * Ссылки Яндекса разворачиваются через публичный API Диска — он отдаёт
 * временную прямую ссылку на скачивание и не требует токена, пока файл
 * опубликован «по ссылке».
 */
final class LinkResolver
{
    /**
     * @param callable(string):array{status:int,body:string}|null $httpGet
     *        Загрузчик для запросов к API (подменяется в тестах).
     * @return array{url:string,service:string,filename:?string}
     */
    public static function resolve(string $url, string $type, ?callable $httpGet = null): array
    {
        $url = trim($url);

        if (self::isYandexDocsViewer($url)) {
            return self::yandexByPublicKey(self::publicKeyFromViewer($url), $url, $httpGet);
        }
        if (self::isYandexDisk($url)) {
            return self::yandexByPublicKey($url, $url, $httpGet);
        }
        if (($sheet = self::googleSheets($url, $type)) !== null) {
            return $sheet;
        }
        if (($drive = self::googleDrive($url)) !== null) {
            return $drive;
        }

        return ['url' => $url, 'service' => 'прямая ссылка', 'filename' => null];
    }

    /** Распознаётся ли ссылка как «умная» (нужна ли она пользователю в подсказке). */
    public static function service(string $url): ?string
    {
        $url = trim($url);
        if (self::isYandexDocsViewer($url)) {
            return 'Яндекс Документы';
        }
        if (self::isYandexDisk($url)) {
            return 'Яндекс Диск';
        }
        if (preg_match('~^https?://docs\.google\.com/spreadsheets/~i', $url) === 1) {
            return 'Google Таблицы';
        }
        if (preg_match('~^https?://drive\.google\.com/~i', $url) === 1) {
            return 'Google Диск';
        }

        return null;
    }

    /* ---------------- Яндекс ---------------- */

    private static function isYandexDocsViewer(string $url): bool
    {
        return preg_match('~^https?://docs\.yandex\.[a-z]+/docs/view\?~i', $url) === 1;
    }

    private static function isYandexDisk(string $url): bool
    {
        return preg_match('~^https?://(disk\.yandex\.[a-z.]+|yadi\.sk)/~i', $url) === 1;
    }

    /** Из ссылки просмотрщика достаём публичный ключ Диска. */
    private static function publicKeyFromViewer(string $url): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $inner = trim((string) ($params['url'] ?? ''));
        if ($inner === '') {
            throw new RuntimeException(
                'В ссылке Яндекс Документов нет параметра url. Скопируйте ссылку целиком '
                . 'или используйте ссылку на файл с Яндекс Диска.'
            );
        }

        return $inner;
    }

    /**
     * @param callable(string):array{status:int,body:string}|null $httpGet
     * @return array{url:string,service:string,filename:?string}
     */
    private static function yandexByPublicKey(string $publicKey, string $originalUrl, ?callable $httpGet): array
    {
        $httpGet ??= [self::class, 'httpGet'];
        $api = rtrim((string) Config::get('yandex_disk_api', 'https://cloud-api.yandex.net/v1/disk'), '/');

        // API принимает и сам ключ, и запись вида ya-disk-public://КЛЮЧ, и публичный URL.
        $candidates = [$publicKey];
        if (str_starts_with($publicKey, 'ya-disk-public://')) {
            $candidates[] = substr($publicKey, strlen('ya-disk-public://'));
        }

        $lastError = '';
        foreach ($candidates as $key) {
            $response = $httpGet($api . '/public/resources/download?public_key=' . rawurlencode($key));

            if ($response['status'] === 200) {
                $data = json_decode($response['body'], true);
                $href = is_array($data) ? (string) ($data['href'] ?? '') : '';
                if ($href !== '') {
                    return [
                        'url'      => $href,
                        'service'  => self::isYandexDocsViewer($originalUrl) ? 'Яндекс Документы' : 'Яндекс Диск',
                        'filename' => self::filenameFromUrl($originalUrl),
                    ];
                }
                $lastError = 'API Яндекс Диска не вернул ссылку на скачивание.';
                continue;
            }

            $lastError = self::yandexError($response, $key, $api, $httpGet);
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Не удалось получить файл по ссылке Яндекса.');
    }

    /**
     * @param array{status:int,body:string} $response
     * @param callable(string):array{status:int,body:string} $httpGet
     */
    private static function yandexError(array $response, string $key, string $api, callable $httpGet): string
    {
        $data = json_decode($response['body'], true);
        $description = is_array($data) ? (string) ($data['description'] ?? $data['message'] ?? '') : '';

        if ($response['status'] === 404) {
            return 'Яндекс Диск не нашёл файл по этой ссылке. Проверьте, что доступ открыт по ссылке '
                . 'и что ссылка не истекла.' . ($description !== '' ? ' Ответ сервиса: ' . $description : '');
        }

        // Возможно, по ссылке опубликована папка — подскажем, что в ней лежит.
        $meta = $httpGet($api . '/public/resources?public_key=' . rawurlencode($key) . '&limit=20');
        if ($meta['status'] === 200) {
            $info = json_decode($meta['body'], true);
            if (is_array($info) && ($info['type'] ?? '') === 'dir') {
                $names = [];
                foreach ($info['_embedded']['items'] ?? [] as $item) {
                    $names[] = (string) ($item['name'] ?? '');
                }
                $names = array_filter($names);

                return 'По ссылке опубликована папка, а нужен конкретный файл. '
                    . ($names !== []
                        ? 'В папке: ' . implode(', ', array_slice($names, 0, 10)) . '. Откройте нужный файл и скопируйте ссылку на него.'
                        : 'Откройте нужный файл и скопируйте ссылку на него.');
            }
        }

        return 'Яндекс Диск ответил кодом ' . $response['status'] . '.'
            . ($description !== '' ? ' ' . $description : '');
    }

    private static function filenameFromUrl(string $url): ?string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        $name = trim((string) ($params['name'] ?? ''));

        return $name === '' ? null : $name;
    }

    /* ---------------- Google ---------------- */

    /** @return array{url:string,service:string,filename:?string}|null */
    private static function googleSheets(string $url, string $type): ?array
    {
        if (preg_match('~^https?://docs\.google\.com/spreadsheets/~i', $url) !== 1) {
            return null;
        }

        // Ссылки «опубликовать в интернете» (/d/e/…/pub?output=csv) уже прямые
        if (preg_match('~/spreadsheets/d/e/~i', $url) === 1 || str_contains($url, '/gviz/tq')) {
            return ['url' => $url, 'service' => 'Google Таблицы', 'filename' => null];
        }

        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9_-]{10,})~', $url, $match) !== 1) {
            return null;
        }

        $format = $type === 'csv' ? 'csv' : 'xlsx';
        $export = 'https://docs.google.com/spreadsheets/d/' . $match[1] . '/export?format=' . $format;

        // Для CSV важен конкретный лист: его номер берём из ссылки (#gid=… или ?gid=…)
        if ($format === 'csv' && preg_match('~[#&?]gid=([0-9]+)~', $url, $gid) === 1) {
            $export .= '&gid=' . $gid[1];
        }

        return ['url' => $export, 'service' => 'Google Таблицы', 'filename' => null];
    }

    /** @return array{url:string,service:string,filename:?string}|null */
    private static function googleDrive(string $url): ?array
    {
        if (preg_match('~^https?://drive\.google\.com/file/d/([a-zA-Z0-9_-]{10,})~i', $url, $match) !== 1) {
            return null;
        }

        return [
            'url'      => 'https://drive.google.com/uc?export=download&id=' . $match[1],
            'service'  => 'Google Диск',
            'filename' => null,
        ];
    }

    /* ---------------- HTTP ---------------- */

    /** @return array{status:int,body:string} */
    public static function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Для работы со ссылками нужен модуль PHP «curl».');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'SupplierCatalog/1.0 (+PHP importer)',
        ]);
        $body   = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Не удалось обратиться к API: ' . ($error !== '' ? $error : 'нет ответа'));
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
