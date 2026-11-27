<?php
declare(strict_types=1);

/*
 * bootstrap.php - Initialisatie van de Porbeheer applicatie
 * - Voert basis hardening uit door error display uit te schakelen
 * - Definieert essentiële constanten zoals PROJECT_ROOT, APP_ROOT, VENDOR_ROOT
 * - Laadt de autoloader voor het automatisch includen van klassen en libraries
 * - Biedt een generieke HTML escape functie 'h' voor veilige output
 * - Laadt en valideert de configuratie vanuit app/config.php
 * - Detecteert de omgeving (production, demo, development) op basis van hostnaam en configuratie
 * - Definieert helper functies voor het verkrijgen van app URL, versie en het injecteren van een omgevingsbanner in niet-productie omgevingen
 * - Stelt de standaard timezone in op basis van configuratie
 * - Initialiseert een PDO database connectie met foutafhandeling
 * - Laadt auth helpers en start een veilige sessie
 * - Controleert na inloggen of het verplichte profiel (voornaam, achternaam, 06) is ingevuld
 */

// ====== BASIC HARDENING ======
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ====== DEFINE CONSTANTS (alleen als ze nog niet bestaan) ======
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));  // /var/www/libs/porbeheer
}
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);                // /var/www/libs/porbeheer/app
}
if (!defined('VENDOR_ROOT')) {
    define('VENDOR_ROOT', PROJECT_ROOT . '/vendor');
}
if (!defined('PUBLIC_ROOT')) {
    define('PUBLIC_ROOT', dirname(PROJECT_ROOT, 2) . '/porbeheer/public');  // /var/www/porbeheer/public
}

// ====== AUTOLOADER ======
require_once VENDOR_ROOT . '/autoload.php';

// ====== GENERIC HTML ESCAPE ======
if (!function_exists('h')) {
    /**
     * Accepteert bewust elk scalair type (string/int/float/bool) plus null,
     * niet alleen ?string -- databasevelden als locker_id (int) of amount
     * (decimal) belanden hier via array-toegang soms rechtstreeks in, zonder
     * expliciete (string)-cast op de aanroepplek. Met een strikt ?string-type
     * gaf dat een fatale TypeError (zie het incident met contract_edit.php en
     * keys_edit.php); dit voorkomt die hele klasse fouten structureel.
     */
    function h(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_scalar($v)) {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        }
        return '';
    }
}



// ====== CONFIG ======
$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo 'Config ontbreekt (app/config.php).';
    exit;
}

$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    echo 'Config is ongeldig.';
    exit;
}


$GLOBALS['config'] = $config;

// ====== ENV / URL HELPERS ======
if (!function_exists('requestHost')) {
    function requestHost(): string
    {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost')));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return $host;
    }
}

if (!function_exists('requestScheme')) {
    function requestScheme(): string
    {
        $https = (string)($_SERVER['HTTPS'] ?? '');
        $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');

        if ($https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }
        if (strtolower($forwarded) === 'https') {
            return 'https';
        }
        return 'http';
    }
}

if (!function_exists('detectEnvironment')) {
    function detectEnvironment(array $config): string
    {
        $host = requestHost();
        $map = $config['app']['env_detection'] ?? [];

        $productionHosts = array_map('strtolower', (array)($map['production_hosts'] ?? []));
        $demoHosts = array_map('strtolower', (array)($map['demo_hosts'] ?? []));
        $developmentHosts = array_map('strtolower', (array)($map['development_hosts'] ?? []));

        if (in_array($host, $demoHosts, true) || str_contains($host, 'demo')) {
            return 'demo';
        }

        if (
            in_array($host, $developmentHosts, true)
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local')
            || str_contains($host, 'dev')
        ) {
            return 'development';
        }

        if (in_array($host, $productionHosts, true)) {
            return 'production';
        }

        return 'production';
    }
}

if (!defined('APP_ENV')) {
    define('APP_ENV', detectEnvironment($config));
}
if (!defined('APP_HOST')) {
    define('APP_HOST', requestHost());
}
if (!defined('APP_SCHEME')) {
    define('APP_SCHEME', requestScheme());
}
if (!defined('APP_URL')) {
    define('APP_URL', APP_SCHEME . '://' . APP_HOST);
}
if (!defined('APP_NAME')) {
    define('APP_NAME', (string)($config['app']['name'] ?? 'PorBeheer'));
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', (string)($config['app']['version'] ?? '0.0.0'));
}

$scriptFile = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
$scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? $scriptFile));
$scriptBase = $scriptName !== '' ? pathinfo($scriptName, PATHINFO_FILENAME) : 'unknown';
$scriptStamp = ($scriptFile !== '' && is_file($scriptFile))
    ? date('YmdHis', (int)filemtime($scriptFile))
    : date('YmdHis');

if (!defined('SCRIPT_NAME_ONLY')) {
    define('SCRIPT_NAME_ONLY', $scriptBase);
}
if (!defined('SCRIPT_VERSION')) {
    define('SCRIPT_VERSION', APP_VERSION . '-' . SCRIPT_NAME_ONLY . '-' . $scriptStamp);
}

$config['env'] = APP_ENV;
$config['app_url'] = APP_URL;
$config['app_host'] = APP_HOST;
$config['app_scheme'] = APP_SCHEME;
$config['app_version'] = APP_VERSION;
$config['script_name'] = SCRIPT_NAME_ONLY;
$config['script_version'] = SCRIPT_VERSION;
$config['db'] = $config[APP_ENV]['db'] ?? [];
$config['mail'] = $config[APP_ENV]['mail'] ?? [];
$GLOBALS['config'] = $config;

if (!function_exists('appEnv')) {
    function appEnv(): string
    {
        return APP_ENV;
    }
}

if (!function_exists('appUrl')) {
    function appUrl(string $path = ''): string
    {
        $path = trim($path);
        if ($path === '') {
            return APP_URL;
        }
        return APP_URL . '/' . ltrim($path, '/');
    }
}

if (!function_exists('appVersion')) {
    function appVersion(): string
    {
        return APP_VERSION;
    }
}

if (!function_exists('scriptVersion')) {
    function scriptVersion(): string
    {
        return SCRIPT_VERSION;
    }
}

if (!function_exists('environmentBannerHtml')) {
    function environmentBannerHtml(): string
    {
        $cfg = $GLOBALS['config'] ?? [];
        $enabled = (bool)($cfg['app']['banner_enabled'] ?? true);
        $allowed = (array)($cfg['app']['banner_envs'] ?? ['demo', 'development']);

        if (!$enabled || !in_array(APP_ENV, $allowed, true)) {
            return '';
        }

        $labels = [
            'demo' => 'DEMO OMGEVING',
            'development' => 'ONTWIKKELOMGEVING',
        ];

        $subtitles = [
            'demo' => 'Testdata en testacties kunnen afwijken van productie.',
            'development' => 'Ontwikkelomgeving — wijzigingen en tests zijn niet voor productie bedoeld.',
        ];

        $label = $labels[APP_ENV] ?? strtoupper(APP_ENV);
        $subtitle = $subtitles[APP_ENV] ?? 'Niet-productie omgeving.';
        $host = h(APP_HOST);
        $version = h(APP_VERSION);

        return <<<HTML
<div style="position:sticky;top:0;z-index:99999;background:linear-gradient(90deg,#7a1f1f 0%,#b54708 100%);color:#fff;padding:12px 18px;border-bottom:1px solid rgba(255,255,255,.25);box-shadow:0 2px 10px rgba(0,0,0,.18);font-family:Arial,Helvetica,sans-serif;">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;max-width:1400px;margin:0 auto;">
    <div>
      <div style="font-size:15px;font-weight:700;letter-spacing:.04em;">{$label}</div>
      <div style="font-size:13px;opacity:.95;">{$subtitle}</div>
    </div>
    <div style="font-size:12px;opacity:.92;text-align:right;white-space:nowrap;">
      <div>Host: {$host}</div>
      <div>Versie: {$version}</div>
    </div>
  </div>
</div>
HTML;
    }
}

if (!function_exists('injectEnvironmentBanner')) {
    function injectEnvironmentBanner(string $buffer): string
    {
        if (APP_ENV === 'production' || $buffer === '') {
            return $buffer;
        }

        if (stripos($buffer, '<html') === false || stripos($buffer, '<body') === false) {
            return $buffer;
        }

        if (stripos($buffer, 'data-env-banner="1"') !== false) {
            return $buffer;
        }

        $banner = str_replace('<div ', '<div data-env-banner="1" ', environmentBannerHtml());
        if ($banner === '') {
            return $buffer;
        }

        return (string)preg_replace('/<body([^>]*)>/i', '<body$1>' . $banner, $buffer, 1);
    }
}

if (PHP_SAPI !== 'cli' && APP_ENV !== 'production') {
    ob_start('injectEnvironmentBanner');
}

// ====== TIMEZONE ======
date_default_timezone_set((string)($config['app']['timezone'] ?? 'Europe/Amsterdam'));

// ====== PDO ======
$DB_HOST = (string)($config['db']['host'] ?? '127.0.0.1');
$DB_NAME = (string)($config['db']['name'] ?? '');
$DB_USER = (string)($config['db']['user'] ?? '');
$DB_PASS = (string)($config['db']['pass'] ?? '');

if ($DB_NAME === '' || $DB_USER === '') {
    http_response_code(500);
    echo 'Database config is incompleet voor omgeving: ' . h(APP_ENV) . '.';
    exit;
}

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    $GLOBALS['pdo'] = $pdo;

    // MySQL's NOW()/CURRENT_TIMESTAMP gebruiken standaard de tijdzone van de
    // MySQL-server zelf (vaak UTC), los van de Europe/Amsterdam-instelling
    // hierboven voor PHP. Daardoor liepen tijden in de database (bijv. de
    // audit-log) tot 2 uur achter. Dit zet de sessie-tijdzone gelijk aan wat
    // PHP nu net heeft ingesteld, inclusief zomer-/wintertijd.
    $tzOffset = (new DateTime('now'))->format('P');
    $pdo->exec("SET time_zone = " . $pdo->quote($tzOffset));
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connectie mislukt.';
    exit;
}

// ====== AUTH / HELPERS ======
require_once __DIR__ . '/auth.php';

// Start session veilig
startSecureSession();

// ==================== NIEUW: VERPLICHTE PROFIELCHECK ====================
// Alleen als de gebruiker is ingelogd, 2FA al is ingesteld (of niet nodig),
// en de huidige pagina niet de profielpagina of uitlogpagina is.
if (function_exists('isLoggedIn') && isLoggedIn()) {
    // mustSetup2fa() bestaat meestal in auth.php; we checken of die er is
    if (!function_exists('mustSetup2fa') || !mustSetup2fa()) {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if (!in_array($requestUri, ['/admin/profile.php', '/logout.php'], true)) {
            // Haal de actuele status en profielvelden uit de database
            $stmt = $pdo->prepare("SELECT status, first_name, last_name, phone FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user']['id']]);
            $dbUser = $stmt->fetch();

            if ($dbUser && $dbUser['status'] === 'ACTIVE') {
                // Verplichte velden: voornaam, achternaam en 06-nummer
                if (empty($dbUser['first_name']) || empty($dbUser['last_name']) || empty($dbUser['phone'])) {
                    // Update sessie met de nieuwste DB-gegevens
                    $_SESSION['user'] = array_merge($_SESSION['user'] ?? [], $dbUser);
                    header('Location: /admin/profile.php');
                    exit;
                }
            }
        }
    }
}

// ==================== GLOBALE FOUTAFHANDELING ====================
// Vangt onverwachte PHP-fouten (fatale errors, uncaught exceptions/TypeErrors
// zoals het eerdere incident met h() en een integer-veld) af met een nette
// pagina i.p.v. een stil afgebroken/lege pagina, en logt de details in de
// audit-log zodat zulke problemen niet meer onopgemerkt blijven.
if (!function_exists('renderFatalErrorPage')) {
    function renderFatalErrorPage(string $message, string $file, int $line): void
    {
        if (ob_get_level() > 0) {
            @ob_end_clean();
        }

        // Loggen: nooit laten falen op een kapotte DB-verbinding e.d.
        try {
            global $pdo;
            if (isset($pdo) && $pdo instanceof PDO && function_exists('auditLog')) {
                auditLog($pdo, 'PHP_ERROR', 'fatal', [
                    'message' => mb_substr($message, 0, 500),
                    'file'    => $file,
                    'line'    => $line,
                    'uri'     => (string)($_SERVER['REQUEST_URI'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {
            error_log('Kon fout niet naar audit_log schrijven: ' . $e->getMessage());
        }
        error_log("Porbeheer fatale fout: $message in $file:$line");

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        ?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Er ging iets mis - Porbeheer</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
.err-panel{max-width:440px;margin:40px auto;text-align:center;padding:34px 28px;}
.err-panel .icon{font-size:40px;line-height:1;margin-bottom:6px;}
.err-panel h1{margin:0 0 8px 0;font-size:20px;}
.err-panel p{color:var(--muted, #7b8aa0);margin:0 0 20px 0;font-size:14px;}
.err-panel .btn{
  display:inline-block;padding:10px 18px;border-radius:12px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.05));
  color:#fff;text-decoration:none;font-weight:800;
}
</style>
</head>
<body style="background:#0b0f16;">
<div class="backdrop">
  <div class="panel err-panel">
    <div class="icon">⚠️</div>
    <h1>Er ging iets mis</h1>
    <p>Er is een onverwachte fout opgetreden. Dit is automatisch gemeld zodat we het kunnen bekijken.</p>
    <a class="btn" href="/admin/dashboard.php">Terug naar dashboard</a>
  </div>
</div>
</body>
</html>
        <?php
    }
}

set_exception_handler(static function (Throwable $e): void {
    renderFatalErrorPage($e->getMessage(), $e->getFile(), $e->getLine());
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        renderFatalErrorPage((string)$err['message'], (string)$err['file'], (int)$err['line']);
    }
});