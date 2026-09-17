<?php
/**
 * Скопируйте этот файл в config.php и заполните своими данными.
 * config.php в git не попадает (см. .gitignore).
 */
return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'supplier_catalog',
        'username' => 'catalog_user',
        'password' => 'СМЕНИТЕ_МЕНЯ',
        'charset'  => 'utf8mb4',
    ],

    // Название магазина в шапке публичного каталога.
    'site_name' => 'Каталог товаров',

    // Валюта по умолчанию, если источник её не передаёт.
    'default_currency' => 'RUB',

    // Товаров на странице публичного каталога.
    'per_page' => 24,

    // Часовой пояс сервиса: по нему считается расписание импорта и показываются даты.
    // Примеры: Asia/Almaty, Asia/Aqtobe, Europe/Moscow, Asia/Tashkent.
    'timezone' => 'Asia/Almaty',

    // Секрет для запуска импорта по URL (cron через «Планировщик задач» Plesk
    // либо внешний планировщик): /api/cron.php?token=...
    // Сгенерируйте случайную строку, например: openssl rand -hex 24
    'cron_token' => '',

    // Максимальный размер загружаемого прайса, байт (50 МБ).
    'max_upload_bytes' => 52428800,

    // Таймаут скачивания файла по ссылке, секунд.
    'download_timeout' => 120,

    // Показывать подробности ошибок PHP (только для отладки).
    'debug' => false,
];
