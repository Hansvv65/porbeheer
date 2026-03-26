<?php
/* app/functions.php */
declare(strict_types=1);

function h(null|string|int|float $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function config(): array
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function appUrl(string $path = ''): string
{
    $base = rtrim((string)(config()['app']['base_url'] ?? ''), '/');
    $path = '/' . ltrim($path, '/');
    return $base !== '' ? $base . $path : $path;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function redirectBackWithFlash(string $type, string $message, string $to): never
{
    flash($type, $message);
    redirect($to);
}

function pullFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function requireCsrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(419);
        exit('Ongeldige sessie of formulier-token. Vernieuw de pagina en probeer opnieuw.');
    }
}

function isPost(): bool
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function postString(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function postBool(string $key): int
{
    return isset($_POST[$key]) ? 1 : 0;
}

function postInt(string $key, int $default = 0): int
{
    $value = postString($key, '');
    return $value === '' ? $default : (int)$value;
}

function postFloat(string $key, float $default = 0.0): float
{
    $value = postString($key, '');
    return $value === '' ? $default : (float)str_replace(',', '.', $value);
}

function getString(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function getInt(string $key, int $default = 0): int
{
    $value = getString($key, '');
    return $value === '' ? $default : (int)$value;
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
    return fetchOne($pdo, 'SELECT * FROM bot_settings ORDER BY id ASC LIMIT 1');
}

function walletRow(PDO $pdo): ?array
{
    return fetchOne($pdo, 'SELECT * FROM wallet WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
}

function formatEuro(null|string|float|int $value): string
{
    return '€ ' . number_format((float)($value ?? 0), 2, ',', '.');
}

function formatPrice(null|string|float|int $value, int $decimals = 4): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, $decimals, ',', '.');
}

function formatQty(null|string|float|int $value, int $decimals = 8): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, $decimals, '.', '');
}

function formatDateTime(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('d-m-Y H:i', $time) : h($value);
}

function formatPct(null|string|float|int $value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, $decimals, ',', '.') . '%';
}

function pageTitle(string $title): string
{
    return $title . ' · ' . (config()['app']['name'] ?? 'Trading PY');
}

function isValidSymbolFormat(string $symbol): bool
{
    return (bool) preg_match('/^[A-Z0-9^.=\/-]{1,40}$/', strtoupper(trim($symbol)));
}

function badgeClassForTrend(?string $trend): string
{
    return match (strtoupper(trim((string)$trend))) {
        'UP' => 'badge badge-good',
        'DOWN' => 'badge badge-bad',
        default => 'badge badge-neutral',
    };
}

function badgeClassForAction(?string $action): string
{
    return match (strtoupper(trim((string)$action))) {
        'BUY' => 'badge badge-good',
        'SELL' => 'badge badge-bad',
        'HOLD' => 'badge badge-info',
        default => 'badge badge-neutral',
    };
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $row = fetchOne($pdo, 'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
    return $cache[$key] = ((int)($row['cnt'] ?? 0) > 0);
}
