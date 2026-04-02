<?php
/* app/config.php */
declare(strict_types=1);

// API-sleutels
define('AI_API_KEY', '2rP5DYZa8bDvmy8IjOg8VshUsDCZ63qZ');
define('NEWS_API_KEY', 'jouw_nieuws_api_sleutel_hier');

return [
    'db' => [
        'host' => getenv('TRADING_DB_HOST') ?: 'localhost',
        'port' => (int)(getenv('TRADING_DB_PORT') ?: 3306),
        'dbname' => getenv('TRADING_DB_NAME') ?: 'trading_db',
        'user' => getenv('TRADING_DB_USER') ?: 'trading_user',
        'pass' => getenv('TRADING_DB_PASS') ?: 'fdssgg643tghh$edf#',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => getenv('TRADING_APP_NAME') ?: 'Trading PY',
        'timezone' => getenv('TRADING_TIMEZONE') ?: 'Europe/Amsterdam',
        'base_url' => rtrim((string)(getenv('TRADING_BASE_URL') ?: ''), '/'),
        'env' => getenv('TRADING_ENV') ?: 'local',
        'debug' => (getenv('TRADING_DEBUG') ?: '0') === '1',
        'session_name' => getenv('TRADING_SESSION_NAME') ?: 'trading_py_session',
        'csrf_key' => getenv('TRADING_CSRF_KEY') ?: 'change-this-local-key',
    ],
];
