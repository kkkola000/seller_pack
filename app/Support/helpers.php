<?php
declare(strict_types=1);

/** Экранирование для вывода в HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Ответ в формате JSON и завершение скрипта. */
function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Сообщение пользователю между редиректами. */
function flash(?string $type = null, ?string $message = null): array
{
    if ($type !== null) {
        $_SESSION['flash'][] = ['type' => $type, 'message' => (string) $message];
        return [];
    }
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/** Форматирование цены для витрины. */
function format_price(?float $price, string $currency = 'RUB'): string
{
    if ($price === null) {
        return '—';
    }
    $symbols = ['RUB' => '₽', 'RUR' => '₽', 'USD' => '$', 'EUR' => '€', 'KZT' => '₸', 'BYN' => 'Br', 'UAH' => '₴'];
    $symbol = $symbols[strtoupper($currency)] ?? $currency;
    $decimals = fmod($price, 1.0) === 0.0 ? 0 : 2;

    return number_format($price, $decimals, ',', ' ') . ' ' . $symbol;
}

function format_datetime(?string $value): string
{
    if ($value === null || $value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('d.m.Y H:i', $ts);
}
