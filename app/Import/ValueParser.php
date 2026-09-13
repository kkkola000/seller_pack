<?php
declare(strict_types=1);

namespace App\Import;

/**
 * Приведение «сырых» значений прайса к типам витрины.
 */
final class ValueParser
{
    /** Цена: «1 234,50 руб.» / «1234.5» / «1 234" -> 1234.50 */
    public static function price(string $raw, float $multiplier = 1.0): ?float
    {
        $value = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' ', "'"], '', trim($raw));
        $value = preg_replace('/[^0-9,.\-]/u', '', $value) ?? '';
        if ($value === '' || $value === '-') {
            return null;
        }

        // Разделители: последний из «,» или «.» считаем десятичным.
        $lastComma = strrpos($value, ',');
        $lastDot   = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $price = round((float) $value * $multiplier, 2);

        return $price < 0 ? null : $price;
    }

    /**
     * Остаток/наличие. Возвращает [количество|null, статус].
     *
     * @return array{0:?int,1:string}
     */
    public static function stock(string $raw, string $inStockValues, string $onOrderValues): array
    {
        $value = trim($raw);
        if ($value === '') {
            return [null, 'out_of_stock'];
        }

        $numeric = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], $value);
        $numeric = preg_replace('/^[>~≥+]+/u', '', $numeric) ?? $numeric;
        if (is_numeric($numeric)) {
            $qty = (int) round((float) $numeric);
            return [$qty, $qty > 0 ? 'in_stock' : 'out_of_stock'];
        }

        $normalized = self::normalizeText($value);

        foreach (self::splitList($onOrderValues) as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return [null, 'on_order'];
            }
        }
        foreach (self::splitList($inStockValues) as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return [null, 'in_stock'];
            }
        }

        return [null, 'out_of_stock'];
    }

    /** @return list<string> */
    public static function splitList(string $list): array
    {
        $items = preg_split('/[,;\n]+/u', $list) ?: [];
        $result = [];
        foreach ($items as $item) {
            $item = self::normalizeText($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    public static function normalizeText(string $value): string
    {
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = trim(mb_strtolower($value, 'UTF-8'));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /** Название/артикул: чистим переносы и лишние пробелы. */
    public static function text(string $value, int $maxLength): string
    {
        $value = str_replace(["\r", "\n", "\t", "\xc2\xa0"], ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    public static function currency(string $raw, string $default): string
    {
        $value = strtoupper(trim($raw));
        if ($value === '') {
            return $default;
        }
        $map = ['РУБ' => 'RUB', 'Р' => 'RUB', '₽' => 'RUB', 'RUR' => 'RUB', 'ТГ' => 'KZT', '$' => 'USD', '€' => 'EUR'];
        $value = $map[$value] ?? $value;

        return preg_match('/^[A-Z]{2,8}$/', $value) === 1 ? $value : $default;
    }

    public static function imageUrl(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '' || mb_strlen($value) > 1000) {
            return null;
        }

        return preg_match('~^https?://~i', $value) === 1 ? $value : null;
    }
}
