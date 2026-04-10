<?php
declare(strict_types=1);

/**
 * app/config.php
 * Centrale configuratie.
 *
 * - Geen secrets hardcoded in dit bestand.
 * - Vul secrets via environment variables OF via app/config.secrets.php.
 * - Dit bestand hoort niet in de public webroot.
 */

$secretsFile = __DIR__ . '/config.secrets.php';
$secrets = is_file($secretsFile) ? (require $secretsFile) : [];

$getSecret = static function (string $key, mixed $default = '') use ($secrets): mixed {
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return $secrets[$key] ?? $default;
};

// Eerst de basis configuratie opbouwen met env_detection
$config = [
    'app' => [
        'name'            => 'PorBeheer',
        'version'         => '2.1.0',
        'timezone'        => 'Europe/Amsterdam',
        'banner_enabled'  => true,
        'banner_envs'     => ['demo', 'development'],
        'env_detection'   => [
            'production_hosts'  => [
                'porzbeheer.nl',
                'www.porzbeheer.nl',
            ],
            'demo_hosts' => [
                'demo.porzbeheer.nl',
                'www.demo.porzbeheer.nl',
            ],
            'development_hosts' => [
                'localhost',
                '127.0.0.1',
                'porbeheer.local',
                'www.porbeheer.local',
            ],
        ],
    ],

    'production' => [
        'db' => [
            'host' => (string)$getSecret('POR_DB_HOST_PRODUCTION', ''),
            'name' => (string)$getSecret('POR_DB_NAME_PRODUCTION', ''),
            'user' => (string)$getSecret('POR_DB_USER_PRODUCTION', ''),
            'pass' => (string)$getSecret('POR_DB_PASS_PRODUCTION', ''),
        ],
        'mail' => [
            'smtp_host'  => (string)$getSecret('POR_SMTP_HOST_PRODUCTION', ''),
            'smtp_port'  => (int)$getSecret('POR_SMTP_PORT_PRODUCTION', 587),
            'smtp_user'  => (string)$getSecret('POR_SMTP_USER_PRODUCTION', ''),
            'smtp_pass'  => (string)$getSecret('POR_SMTP_PASS_PRODUCTION', ''),
            'from_email' => (string)$getSecret('POR_MAIL_FROM_EMAIL_PRODUCTION', ''),
            'from_name'  => (string)$getSecret('POR_MAIL_FROM_NAME_PRODUCTION', 'Administratie PorBeheer'),
            'debug'      => (int)$getSecret('POR_MAIL_DEBUG_PRODUCTION', 0),
        ],
    ],

    'demo' => [
        'db' => [
            'host' => (string)$getSecret('POR_DB_HOST_DEMO', ''),
            'name' => (string)$getSecret('POR_DB_NAME_DEMO', ''),
            'user' => (string)$getSecret('POR_DB_USER_DEMO', ''),
            'pass' => (string)$getSecret('POR_DB_PASS_DEMO', ''),
        ],
        'mail' => [
            'smtp_host'  => (string)$getSecret('POR_SMTP_HOST_DEMO', ''),
            'smtp_port'  => (int)$getSecret('POR_SMTP_PORT_DEMO', 587),
            'smtp_user'  => (string)$getSecret('POR_SMTP_USER_DEMO', ''),
            'smtp_pass'  => (string)$getSecret('POR_SMTP_PASS_DEMO', ''),
            'from_email' => (string)$getSecret('POR_MAIL_FROM_EMAIL_DEMO', ''),
            'from_name'  => (string)$getSecret('POR_MAIL_FROM_NAME_DEMO', 'PorBeheer DEMO'),
            'debug'      => (int)$getSecret('POR_MAIL_DEBUG_DEMO', 0),
        ],
    ],

    'development' => [
        'db' => [
            'host' => (string)$getSecret('POR_DB_HOST_DEVELOPMENT', '127.0.0.1'),
            'name' => (string)$getSecret('POR_DB_NAME_DEVELOPMENT', ''),
            'user' => (string)$getSecret('POR_DB_USER_DEVELOPMENT', ''),
            'pass' => (string)$getSecret('POR_DB_PASS_DEVELOPMENT', ''),
        ],
        'mail' => [
            'smtp_host'  => (string)$getSecret('POR_SMTP_HOST_DEVELOPMENT', ''),
            'smtp_port'  => (int)$getSecret('POR_SMTP_PORT_DEVELOPMENT', 587),
            'smtp_user'  => (string)$getSecret('POR_SMTP_USER_DEVELOPMENT', ''),
            'smtp_pass'  => (string)$getSecret('POR_SMTP_PASS_DEVELOPMENT', ''),
            'from_email' => (string)$getSecret('POR_MAIL_FROM_EMAIL_DEVELOPMENT', ''),
            'from_name'  => (string)$getSecret('POR_MAIL_FROM_NAME_DEVELOPMENT', 'PorBeheer DEVELOPMENT'),
            'debug'      => (int)$getSecret('POR_MAIL_DEBUG_DEVELOPMENT', 2),
        ],
    ],
];

// Detecteer de huidige omgeving op basis van de hostname
$hostname = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$isDevelopment = false;

foreach ($config['app']['env_detection']['development_hosts'] as $devHost) {
    if (strpos($hostname, $devHost) !== false) {
        $isDevelopment = true;
        break;
    }
}

// Bepaal de session idle timeout (1200 voor development, anders 300)
$sessionIdleTimeout = $isDevelopment ? 1200 : 300;

// Voeg de security configuratie toe
$config['security'] = [
    'session_idle_timeout' => $sessionIdleTimeout,
];

return $config;