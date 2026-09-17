<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Support\Config;

$siteName = (string) Config::get('site_name', 'Каталог товаров');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<meta name="robots" content="noindex">
<title>Заказы — <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/catalog.css')) ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar__inner">
    <a class="topbar__logo" href="index.php"><?= e($siteName) ?></a>
    <a class="orders-link orders-link--back" href="index.php">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span>В каталог</span>
    </a>
  </div>
</header>

<main class="orders">
  <div class="orders__head">
    <h1 class="orders__title">Заказы <span class="orders__count" id="js-orders-total">Список пуст</span></h1>
    <button type="button" class="btn btn--ghost is-hidden" id="js-clear-all">Очистить всё</button>
  </div>

  <p class="orders__hint" id="js-orders-hint">
    Списки формируются по поставщикам. Каждый можно скачать одним файлом или очистить.
  </p>

  <div id="js-orders-list"></div>
</main>

<footer class="footer">
  <span>Список хранится в этом браузере</span>
  <a href="index.php">К каталогу</a>
</footer>

<script src="<?= e(asset('assets/js/order-store.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/orders.js')) ?>" defer></script>
</body>
</html>
