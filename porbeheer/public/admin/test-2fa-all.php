<?php
declare(strict_types=1);

/* <test-2fa-all.php > 
   Alles-in-één 2FA testpagina, met opslag van de geheime sleutel bij de huidige gebruiker in de database.
   Hiermee kan je het volledige proces testen: genereren van een nieuwe sleutel, scannen van de QR-code, en verifiëren van codes uit de authenticator app.
*/

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireLogin();

use App\Qr\QrSvgProvider;
use RobThree\Auth\TwoFactorAuth;

$user = currentUser();
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    http_response_code(403);
    exit('Geen ingelogde gebruiker.');
}

$tfa = new TwoFactorAuth(
    new QrSvgProvider(),
    'Porbeheer'
);

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'info';

/* gebruiker opnieuw uit DB lezen */
$st = $pdo->prepare("
    SELECT id, username, email, totp_secret, totp_enabled, totp_verified_at
    FROM users
    WHERE id = ?
    LIMIT 1
");
$st->execute([$userId]);
$dbUser = $st->fetch(PDO::FETCH_ASSOC);

if (!$dbUser) {
    http_response_code(404);
    exit('Gebruiker niet gevonden.');
}

$username = (string)($dbUser['username'] ?? '');
$email = (string)($dbUser['email'] ?? '');
$accountLabel = $email !== '' ? $email : $username;

/* acties */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate') {
        $secret = $tfa->createSecret();

        $st = $pdo->prepare("
            UPDATE users
            SET totp_secret = ?, totp_enabled = 0, totp_verified_at = NULL
            WHERE id = ?
        ");
        $st->execute([$secret, $userId]);

        $dbUser['totp_secret'] = $secret;
        $dbUser['totp_enabled'] = 0;
        $dbUser['totp_verified_at'] = null;

        $message = 'Nieuwe 2FA sleutel gegenereerd en opgeslagen bij de huidige gebruiker.';
        $messageType = 'success';
    }

    if ($action === 'verify') {
        $secret = (string)($dbUser['totp_secret'] ?? '');
        $code = trim((string)($_POST['code'] ?? ''));

        if ($secret === '') {
            $message = 'Er is nog geen 2FA sleutel opgeslagen. Genereer eerst een sleutel.';
            $messageType = 'error';
        } elseif ($code === '') {
            $message = 'Vul een code uit de authenticator app in.';
            $messageType = 'error';
        } else {
            $ok = $tfa->verifyCode($secret, $code, 2);

            if ($ok) {
                $st = $pdo->prepare("
                    UPDATE users
                    SET totp_enabled = 1, totp_verified_at = NOW()
                    WHERE id = ?
                ");
                $st->execute([$userId]);

                $dbUser['totp_enabled'] = 1;
                $dbUser['totp_verified_at'] = date('Y-m-d H:i:s');

                $message = '2FA code correct. Deze sleutel is nu bevestigd voor de huidige gebruiker.';
                $messageType = 'success';
            } else {
                $message = 'De ingevoerde code is niet geldig.';
                $messageType = 'error';
            }
        }
    }

    if ($action === 'disable') {
        $st = $pdo->prepare("
            UPDATE users
            SET totp_secret = NULL, totp_enabled = 0, totp_verified_at = NULL
            WHERE id = ?
        ");
        $st->execute([$userId]);

        $dbUser['totp_secret'] = null;
        $dbUser['totp_enabled'] = 0;
        $dbUser['totp_verified_at'] = null;

        $message = '2FA verwijderd voor de huidige gebruiker.';
        $messageType = 'success';
    }
}

$secret = (string)($dbUser['totp_secret'] ?? '');
$hasSecret = ($secret !== '');
$serverCode = $hasSecret ? $tfa->getCode($secret) : '';
$qrDataUri = $hasSecret ? $tfa->getQRCodeImageAsDataUri($accountLabel, $secret) : '';
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>2FA test</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: Arial, Helvetica, sans-serif; background:#f5f7fb; color:#1f2937; margin:0; }
    .wrap { max-width:1000px; margin:24px auto; padding:0 20px 40px; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:22px; box-shadow:0 10px 24px rgba(0,0,0,.05); margin-bottom:20px; }
    .topbar { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
    .topbar h1 { margin:0; }
    .muted { color:#6b7280; }
    .secret { font-size:18px; font-weight:700; letter-spacing:.08em; }
    .ok { color:#166534; font-weight:700; }
    .bad { color:#991b1b; font-weight:700; }
    .info { color:#1d4ed8; font-weight:700; }
    .btn, button {
      display:inline-block; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px;
      background:#f8fafc; color:#111827; text-decoration:none; cursor:pointer;
    }
    input[type=text] { font-size:20px; padding:10px 12px; width:180px; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    @media (max-width: 900px) { .grid { grid-template-columns:1fr; } }
    img { background:#fff; border:1px solid #e5e7eb; padding:10px; }
  </style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div>
      <h1>2FA test</h1>
      <div class="muted">Test en verificatie op één pagina, met opslag bij de huidige gebruiker.</div>
    </div>
    <div>
      <a class="btn" href="/admin/tech-test.php">Terug naar Tech test</a>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="card">
      <div class="<?= $messageType === 'success' ? 'ok' : ($messageType === 'error' ? 'bad' : 'info') ?>">
        <?= h($message) ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid">
    <div class="card">
      <h2>Huidige gebruiker</h2>
      <p><strong>Gebruiker:</strong> <?= h($username) ?></p>
      <p><strong>Account label:</strong> <?= h($accountLabel) ?></p>
      <p><strong>2FA actief:</strong>
        <span class="<?= !empty($dbUser['totp_enabled']) ? 'ok' : 'bad' ?>">
          <?= !empty($dbUser['totp_enabled']) ? 'Ja' : 'Nee' ?>
        </span>
      </p>
      <p><strong>Laatst bevestigd:</strong> <?= h((string)($dbUser['totp_verified_at'] ?? '')) ?></p>

      <form method="post" style="margin-top:16px;">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="generate">
        <button type="submit">Nieuwe sleutel genereren</button>
      </form>

      <?php if ($hasSecret): ?>
        <form method="post" style="margin-top:10px;">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="action" value="disable">
          <button type="submit" onclick="return confirm('2FA sleutel verwijderen voor deze gebruiker?');">
            2FA verwijderen
          </button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Opgeslagen sleutel</h2>
      <?php if ($hasSecret): ?>
        <p class="secret"><?= h(chunk_split($secret, 4, ' ')) ?></p>
        <p class="muted">Deze sleutel komt uit de database van de huidige gebruiker en wordt hergebruikt bij deze test.</p>
        <p><strong>Servercode nu:</strong> <?= h($serverCode) ?></p>
      <?php else: ?>
        <p class="muted">Er is nog geen 2FA sleutel opgeslagen voor deze gebruiker.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($hasSecret): ?>
    <div class="grid">
      <div class="card">
        <h2>QR-code</h2>
        <img src="<?= h($qrDataUri) ?>" alt="QR code">
        <p class="muted">Scan deze code met Google Authenticator, Microsoft Authenticator of een andere TOTP app.</p>
        <p class="muted">Lukt scannen niet? Gebruik dan de sleutel hierboven handmatig als TOTP, 6 cijfers, 30 seconden.</p>
      </div>

      <div class="card">
        <h2>Controleer code</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="action" value="verify">

          <label for="code"><strong>Code uit authenticator</strong></label><br><br>
          <input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"><br><br>

          <button type="submit">Controleer code</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

</div>
</body>
</html>