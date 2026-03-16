use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;

$tfa = new TwoFactorAuth(
    new BaconQrCodeProvider(),
    'Porbeheer'
);

$secret = $tfa->createSecret();

echo '<img src="' .
$tfa->getQRCodeImageAsDataUri('hans@porbeheer', $secret) .
'">';