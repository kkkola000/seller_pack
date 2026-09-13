<?php
declare(strict_types=1);

namespace App\Support;

final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    /** @param array<string,mixed> $items */
    public static function load(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$items[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }
}
