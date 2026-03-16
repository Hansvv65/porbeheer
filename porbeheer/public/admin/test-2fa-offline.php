<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';

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