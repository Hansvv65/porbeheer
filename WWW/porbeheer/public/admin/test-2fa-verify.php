<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';

use RobThree\Auth\TwoFactorAuth;
use App\Qr\QrSvgProvider;

$tfa = new TwoFactorAuth(
    new QrSvgProvider(),
    'Porbeheer'
);

$secret = $_GET['secret'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $code = trim($_POST['code']);

    if ($tfa->verifyCode($secret, $code)) {
        echo "<h2>✅ Code correct</h2>";
    } else {
        echo "<h2>❌ Code fout</h2>";
    }
}

?>

<form method="post">
    <p>Secret: <b><?= h($secret) ?></b></p>

    <label>Authenticator code:</label><br>
    <input name="code" style="font-size:20px;width:120px">
    <button>Verify</button>
</form>