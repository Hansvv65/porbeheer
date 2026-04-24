<?php
declare(strict_types=1);

// Controleer of constante al bestaat voordat je hem definieert
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));  // /var/www/libs/porbeheer
}
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor/');
}

// Composer autoloader
$composerAutoload = VENDOR_PATH . 'autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// PSR-4 autoloader voor libraries
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'PHPMailer\\PHPMailer\\' => VENDOR_PATH . 'PHPMailer/src/',
        'Dompdf\\' => VENDOR_PATH . 'DomPDF/src/',
        'RobThree\\Auth\\' => VENDOR_PATH . 'robthree/twofactorauth/lib/',
        'BaconQrCode\\' => VENDOR_PATH . 'bacon/bacon-qr-code/src/',
        'DASPRiD\\Enum\\' => VENDOR_PATH . 'dasprid/enum/src/',
        'App\\Qr\\' => VENDOR_PATH . 'Qr/',
    ];
    
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Laad PHPMailer als die nog niet geladen is
if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    $phpmailerFile = VENDOR_PATH . 'PHPMailer/src/PHPMailer.php';
    if (file_exists($phpmailerFile)) {
        require_once $phpmailerFile;
        require_once VENDOR_PATH . 'PHPMailer/src/Exception.php';
        require_once VENDOR_PATH . 'PHPMailer/src/SMTP.php';
    }
}