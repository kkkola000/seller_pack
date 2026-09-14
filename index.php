<?php
declare(strict_types=1);

/**
 * Запасная точка входа для случая, когда корень документов сайта указывает
 * на папку проекта, а не на public (и правила .htaccess не работают —
 * например, на nginx). Открывает витрину, лежащую в public/.
 *
 * Если корень документов указывает на public, этот файл вообще не нужен:
 * он окажется вне зоны видимости веб-сервера.
 */

$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$target = 'public/index.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $target, true, 302);
header('Content-Type: text/html; charset=utf-8');

echo '<!doctype html><meta charset="utf-8">'
    . '<title>Каталог товаров</title>'
    . '<p>Каталог находится по адресу <a href="' . htmlspecialchars($target, ENT_QUOTES) . '">'
    . htmlspecialchars($target, ENT_QUOTES) . '</a>.</p>';
