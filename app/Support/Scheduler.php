<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Отметки о запусках планировщика.
 *
 * Нужны, чтобы в админке было видно: задача в Plesk действительно
 * обращается к сервису — или не настроена вовсе, и автоимпорт молчит
 * не из-за настроек источника.
 */
final class Scheduler
{
    private const FILE = '/scheduler.json';

    /** @param 'cli'|'url' $via как именно вызвали планировщик */
    public static function ping(string $via, int $dueCount, int $importedSources = 0): void
    {
        $data = [
            'last_ping_at'      => date('Y-m-d H:i:s'),
            'via'               => $via,
            'due_count'         => $dueCount,
            'imported_sources'  => $importedSources,
        ];

        @file_put_contents(APP_STORAGE . self::FILE, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** @return array{last_ping_at:string,via:string,due_count:int,imported_sources:int}|null */
    public static function lastPing(): ?array
    {
        $file = APP_STORAGE . self::FILE;
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) && isset($data['last_ping_at']) ? $data : null;
    }

    /** Сколько минут назад планировщик обращался к сервису; null — ни разу. */
    public static function minutesSinceLastPing(): ?int
    {
        $ping = self::lastPing();
        if ($ping === null) {
            return null;
        }
        $time = strtotime((string) $ping['last_ping_at']);

        return $time === false ? null : (int) floor((time() - $time) / 60);
    }

    /** Человекочитаемое «5 минут назад». */
    public static function humanAgo(int $minutes): string
    {
        if ($minutes < 1) {
            return 'только что';
        }
        if ($minutes < 60) {
            return $minutes . ' ' . self::plural($minutes, 'минуту', 'минуты', 'минут') . ' назад';
        }
        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return $hours . ' ' . self::plural($hours, 'час', 'часа', 'часов') . ' назад';
        }
        $days = (int) floor($hours / 24);

        return $days . ' ' . self::plural($days, 'день', 'дня', 'дней') . ' назад';
    }

    private static function plural(int $number, string $one, string $few, string $many): string
    {
        $mod10 = $number % 10;
        $mod100 = $number % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }

        return $many;
    }
}
