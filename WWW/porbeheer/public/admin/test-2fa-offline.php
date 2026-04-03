<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
/**
 * Testpagina voor 2FA zonder database of gebruikersaccounts.
 * Hiermee kan je een willekeurige secrete genereren en de QR-code bekijken, en vervolgens codes verifiëren met een authenticator app. */

use App\Qr\QrSvgProvider;
use RobThree\Auth\TwoFactorAuth;

$tfa = new TwoFactorAuth(
    new QrSvgProvider(),
    'Porbeheer'
);

$secret = $tfa->createSecret();

echo '<h2>2FA Test Offline</h2>';
echo 'Secret: <b>' . h(chunk_split($secret, 4, ' ')) . '</b><br><br>';
echo '<img src="' . h($tfa->getQRCodeImageAsDataUri('hans@porbeheer', $secret)) . '" alt="QR code">';