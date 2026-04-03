<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'App\\'             => __DIR__ . '/',
        'RobThree\\Auth\\'  => dirname(__DIR__) . '/vendor/robthree/twofactorauth/lib/',
        'BaconQrCode\\'     => dirname(__DIR__) . '/vendor/bacon/bacon-qr-code/src/',
        'DASPRiD\\Enum\\'   => dirname(__DIR__) . '/vendor/dasprid/enum/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);

        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});