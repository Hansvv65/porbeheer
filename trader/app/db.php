<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Amsterdam');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionName = (string)($config['app']['session_name'] ?? 'trading_py_session');

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
    );

    session_name($sessionName);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

$db = $config['db'] ?? [];

$host = $db['host'] ?? '127.0.0.1';
$port = (int)($db['port'] ?? 3306);
$name = $db['database'] ?? $db['dbname'] ?? null;
$user = $db['user'] ?? null;
$pass = $db['password'] ?? $db['pass'] ?? '';
$charset = $db['charset'] ?? 'utf8mb4';

if (!$name) {
    throw new RuntimeException('Databaseconfig mist sleutel: database of dbname');
}

if (!$user) {
    throw new RuntimeException('Databaseconfig mist sleutel: user');
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $host,
    $port,
    $name,
    $charset
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];

$pdo = new PDO($dsn, $user, $pass, $options);