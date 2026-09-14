<?php
declare(strict_types=1);

namespace App\View;


/**
 * Рендер карточек товаров. Используется и при первой отрисовке страницы,
 * и в API подгрузки — разметка всегда одна и та же.
 */
final class Catalog
{
    /** @param array<string,mixed> $product */
    public static function card(array $product): string
    {
        $availability = (string) $product['availability'];

        // Наличие показываем ровно так, как его передал поставщик.
        // Пусто — значит поставщик его не передаёт: тогда бейджа просто нет.
        $stockText = trim((string) ($product['stock_text'] ?? ''));

        $image = $product['image_url'] ?? null;
        $thumb = $image
            ? '<img class="card__img" src="' . e($image) . '" alt="' . e($product['name']) . '" loading="lazy" decoding="async"'
                . ' onerror="this.closest(\'.card__thumb\').classList.add(\'card__thumb--empty\');this.remove();">'
            : '';

        $html = '<article class="card" data-id="' . (int) $product['id'] . '">';
        $html .= '<div class="card__thumb' . ($image ? '' : ' card__thumb--empty') . '">' . $thumb . '</div>';
        $html .= '<div class="card__body">';
        // Пустой бейдж всё равно выводим: в списке на ПК он держит колонку «Наличие»
        $html .= $stockText !== ''
            ? '<span class="badge badge--' . e($availability) . '" title="Наличие по данным поставщика">'
                . e($stockText) . '</span>'
            : '<span class="badge badge--none"></span>';
        $html .= '<h3 class="card__title">' . e($product['name']) . '</h3>';
        $html .= '<div class="card__sku" title="Артикул"><span class="card__sku-icon">#</span>' . e($product['sku']) . '</div>';
        $html .= '<div class="card__footer">';
        $html .= '<span class="card__price">' . e(format_price(
            $product['price'] === null ? null : (float) $product['price'],
            (string) $product['currency']
        )) . '</span>';
        $html .= '<span class="card__supplier" title="Поставщик">' . e($product['supplier_name']) . '</span>';
        $html .= '</div></div></article>';

        return $html;
    }

    /** @param list<array<string,mixed>> $products */
    public static function cards(array $products): string
    {
        $html = '';
        foreach ($products as $product) {
            $html .= self::card($product);
        }

        return $html;
    }

    public static function emptyState(): string
    {
        return '<div class="empty">'
            . '<div class="empty__icon">🔍</div>'
            . '<p class="empty__title">Ничего не найдено</p>'
            . '<p class="empty__text">Попробуйте изменить запрос или сбросить фильтры.</p>'
            . '</div>';
    }

    /** Текст «Найдено N товаров» с правильным окончанием. */
    public static function foundLabel(int $total): string
    {
        $mod10 = $total % 10;
        $mod100 = $total % 100;
        $word = 'товаров';
        if ($mod10 === 1 && $mod100 !== 11) {
            $word = 'товар';
        } elseif ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            $word = 'товара';
        }

        return number_format($total, 0, ',', ' ') . ' ' . $word;
    }
}
