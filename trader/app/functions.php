<?php
declare(strict_types=1);

function h(null|string|int|float $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function postBool(string $key): int
{
    return isset($_POST[$key]) ? 1 : 0;
}

function postInt(string $key, int $default = 0): int
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return $default;
    }
    return (int)$_POST[$key];
}

function postFloat(string $key, float $default = 0.0): float
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return $default;
    }
    return (float)str_replace(',', '.', (string)$_POST[$key]);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row !== false ? $row : null;
}

function fetchAllRows(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function executeSql(PDO $pdo, string $sql, array $params = []): bool
{
    $st = $pdo->prepare($sql);
    return $st->execute($params);
}

function settingRow(PDO $pdo): ?array
{
    return fetchOne($pdo, "SELECT * FROM bot_settings ORDER BY id ASC LIMIT 1");
}

function walletRow(PDO $pdo): ?array
{
    return fetchOne($pdo, "SELECT * FROM wallet WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
}

function formatEuro(null|string|float|int $value): string
{
    $num = (float)($value ?? 0);
    return '€ ' . number_format($num, 2, ',', '.');
}

function formatQty(null|string|float|int $value, int $decimals = 8): string
{
    $num = (float)($value ?? 0);
    return number_format($num, $decimals, '.', '');
}

function actionBadge(string $value): string
{
    $value = strtoupper(trim($value));

    return match ($value) {
        'BUY'  => '<span class="badge badge-buy">BUY</span>',
        'SELL' => '<span class="badge badge-sell">SELL</span>',
        default => '<span class="badge badge-neutral">' . h($value) . '</span>',
    };
}
