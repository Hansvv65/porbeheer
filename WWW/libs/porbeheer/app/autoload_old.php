<?php
declare(strict_types=1);

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));
}
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor/');
}

// Composer autoloader is de primaire autoloader
$composerAutoload = VENDOR_PATH . 'autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Alleen eigen namespaces die NIET in Composer zitten (zoals App\Qr)
spl_autoload_register(function (string $class): void {
    // Alleen voor App\Qr
    if (strpos($class, 'App\\Qr\\') === 0) {
        $relative = substr($class, strlen('App\\Qr\\'));
        $file = VENDOR_PATH . 'Qr/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});