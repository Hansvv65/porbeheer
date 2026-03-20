<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';

requireLogin();

use App\Qr\QrSvgProvider;
use RobThree\Auth\TwoFactorAuth;

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../../../libs/porbeheer/vendor/autoload.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/Qr/QrSvgProvider.php';

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

$message = '';
$messageType = 'info';

$st = $pdo->prepare("
    SELECT id, username, email, role, theme_variant, totp_secret, totp_enabled, totp_verified_at
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

if (empty($dbUser['totp_secret'])) {
    $secret = $tfa->createSecret();

    $pdo->prepare("
        UPDATE users
        SET totp_secret = ?, totp_enabled = 0, totp_verified_at = NULL, updated_at = NOW()
        WHERE id = ?
    ")->execute([$secret, $userId]);

    $dbUser['totp_secret'] = $secret;
    $dbUser['totp_enabled'] = 0;
    $dbUser['totp_verified_at'] = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'regenerate') {
        $secret = $tfa->createSecret();

        $pdo->prepare("
            UPDATE users
            SET totp_secret = ?, totp_enabled = 0, totp_verified_at = NULL, updated_at = NOW()
            WHERE id = ?
        ")->execute([$secret, $userId]);

        $dbUser['totp_secret'] = $secret;
        $dbUser['totp_enabled'] = 0;
        $dbUser['totp_verified_at'] = null;

        auditLog($pdo, 'TOTP_SECRET_REGENERATE', 'admin/setup-2fa.php', [
            'user_id' => $userId,
        ]);

        $message = 'Er is een nieuwe QR-code gemaakt. Scan deze opnieuw in je app.';
        $messageType = 'success';
    }

    if ($action === 'verify') {
        $secret = (string)($dbUser['totp_secret'] ?? '');
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));

        if ($secret === '') {
            $message = 'Er is nog geen 2FA-sleutel beschikbaar.';
            $messageType = 'error';
        } elseif ($code === '' || strlen($code) !== 6) {
            $message = 'Vul de 6 cijfers uit de authenticator-app in.';
            $messageType = 'error';
        } else {
            $ok = $tfa->verifyCode($secret, $code, 2);

            if ($ok) {
                $pdo->prepare("
                    UPDATE users
                    SET totp_enabled = 1,
                        totp_verified_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$userId]);

                refreshSessionUser($pdo, $userId);

                auditLog($pdo, 'TOTP_SETUP_OK', 'admin/setup-2fa.php', [
                    'user_id' => $userId,
                ]);

                header('Location: /admin/dashboard.php?setup2fa=1');
                exit;
            } else {
                auditLog($pdo, 'TOTP_SETUP_FAIL', 'admin/setup-2fa.php', [
                    'user_id' => $userId,
                ]);

                $message = 'De code klopt niet. Controleer of je de actuele 6 cijfers uit de app hebt ingevuld.';
                $messageType = 'error';
            }
        }
    }
}

$secret = (string)($dbUser['totp_secret'] ?? '');
$qrDataUri = $tfa->getQRCodeImageAsDataUri($accountLabel, $secret);
$bg = themeImage('admin', $pdo);
$csrf = csrfToken();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - 2FA instellen</title>
<style>
:root{
  --text:#fff; --muted:rgba(255,255,255,.78); --border:rgba(255,255,255,.22);
  --glass:rgba(255,255,255,.12); --glass2:rgba(255,255,255,.06);
  --shadow:0 14px 40px rgba(0,0,0,.45);
  --ok:#7CFFB2; --err:#FF8DA1; --info:#9fd1ff;
}
body{
  margin:0;font-family:Arial,sans-serif;color:var(--text);
  background:url('<?= h($bg) ?>') no-repeat center center fixed;
  background-size:cover;
}
.backdrop{
  min-height:100vh;
  background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));
  padding:26px; box-sizing:border-box;
}
.wrap{
  width:min(1180px, 96vw);
  margin:0 auto;
}
.topbar{
  display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px;
}
.userbox{
  background:var(--glass);
  border:1px solid var(--border);
  border-radius:14px;
  padding:12px 14px;
  box-shadow:var(--shadow);
}
.grid{
  display:grid;
  grid-template-columns: 1.05fr .95fr;
  gap:22px;
}
@media (max-width: 960px){
  .grid{ grid-template-columns:1fr; }
}
.card{
  border-radius:20px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  padding:22px;
}
h1,h2,h3{ margin-top:0; }
.msg{
  margin-bottom:14px;
  font-size:14px;
  padding:11px 12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.08);
}
.msg.error{
  border-color:rgba(255,141,161,.35);
  background:rgba(255,141,161,.10);
  color:var(--err);
  font-weight:900;
}
.msg.success{
  border-color:rgba(124,255,178,.35);
  background:rgba(124,255,178,.10);
  color:var(--ok);
  font-weight:900;
}
.msg.info{
  border-color:rgba(159,209,255,.35);
  background:rgba(159,209,255,.10);
  color:var(--info);
  font-weight:900;
}
.steps{
  display:grid;
  gap:12px;
}
.step{
  display:grid;
  grid-template-columns:56px 1fr;
  gap:12px;
  padding:14px;
  border-radius:16px;
  background:rgba(0,0,0,.16);
  border:1px solid rgba(255,255,255,.12);
}
.n{
  width:56px;height:56px;border-radius:16px;
  display:flex;align-items:center;justify-content:center;
  font-weight:900;font-size:20px;background:rgba(255,255,255,.12);
}
.illus{
  width:100%;
  max-width:140px;
  margin:8px auto 0;
  display:block;
}
.qrwrap{
  text-align:center;
}
.qrwrap img{
  background:#fff;
  border-radius:18px;
  padding:14px;
  max-width:100%;
  border:1px solid #e5e7eb;
}
.secret{
  margin-top:12px;
  font-size:18px;
  font-weight:900;
  letter-spacing:.10em;
  text-align:center;
}
.small{ color:var(--muted); font-size:13px; }
label{ display:block; margin-top:12px; font-weight:800; }
input{
  width:100%;
  padding:12px 14px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(0,0,0,.22);
  color:#fff;
  box-sizing:border-box;
  margin-top:6px;
}
.code{
  font-size:28px;
  letter-spacing:.35em;
  text-align:center;
  font-weight:900;
}
.row{
  display:flex; gap:10px; flex-wrap:wrap; margin-top:16px;
}
.btn{
  display:inline-block;
  padding:12px 16px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));
  color:#fff;
  font-weight:900;
  text-decoration:none;
  cursor:pointer;
}
.btn.secondary{
  background:rgba(0,0,0,.18);
}
.note{
  margin-top:14px;
  padding:14px;
  border-radius:16px;
  background:rgba(0,0,0,.16);
  border:1px solid rgba(255,255,255,.12);
}
</style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div>
        <h1>2FA instellen</h1>
        <div class="small">Eenmalig instellen voor extra beveiliging van je account</div>
      </div>
      <div class="userbox">
        <div><strong><?= h($username) ?></strong></div>
        <div class="small"><?= h($email !== '' ? $email : 'geen e-mailadres') ?></div>
      </div>
    </div>

    <div class="grid">
      <div class="card">
        <?php if ($message !== ''): ?>
          <div class="msg <?= h($messageType) ?>"><?= h($message) ?></div>
        <?php else: ?>
          <div class="msg info">Je bent bijna klaar. Volg rustig de 3 stappen hieronder.</div>
        <?php endif; ?>

        <div class="steps">
          <div class="step">
            <div class="n">1</div>
            <div>
              <strong>Open een authenticator-app op je telefoon</strong>
              <div class="small">Bijvoorbeeld Google Authenticator, Microsoft Authenticator of 2FAS.</div>

              <svg class="illus" viewBox="0 0 120 160" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="20" y="5" width="80" height="150" rx="14" fill="rgba(255,255,255,.10)" stroke="rgba(255,255,255,.25)"/>
                <rect x="32" y="28" width="56" height="56" rx="10" fill="rgba(255,255,255,.16)"/>
                <path d="M60 40v32M44 56h32" stroke="white" stroke-width="6" stroke-linecap="round"/>
              </svg>
            </div>
          </div>

          <div class="step">
            <div class="n">2</div>
            <div>
              <strong>Kies in de app: account toevoegen</strong>
              <div class="small">Meestal is dat een plusje <strong>+</strong> of de knop <strong>Scan QR-code</strong>.</div>

              <svg class="illus" viewBox="0 0 120 160" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="20" y="5" width="80" height="150" rx="14" fill="rgba(255,255,255,.10)" stroke="rgba(255,255,255,.25)"/>
                <circle cx="60" cy="65" r="26" fill="rgba(255,255,255,.16)"/>
                <path d="M60 49v32M44 65h32" stroke="white" stroke-width="6" stroke-linecap="round"/>
              </svg>
            </div>
          </div>

          <div class="step">
            <div class="n">3</div>
            <div>
              <strong>Scan de QR-code en vul daarna de 6 cijfers in</strong>
              <div class="small">Lukt scannen niet? Typ dan de geheime sleutel hieronder handmatig in.</div>

              <svg class="illus" viewBox="0 0 120 160" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="16" y="20" width="88" height="88" rx="10" fill="rgba(255,255,255,.12)" stroke="rgba(255,255,255,.25)"/>
                <rect x="28" y="32" width="16" height="16" fill="white"/>
                <rect x="48" y="32" width="8" height="8" fill="white"/>
                <rect x="60" y="32" width="20" height="20" fill="white"/>
                <rect x="84" y="32" width="8" height="8" fill="white"/>
                <rect x="28" y="52" width="8" height="8" fill="white"/>
                <rect x="40" y="52" width="20" height="20" fill="white"/>
                <rect x="64" y="56" width="8" height="8" fill="white"/>
                <rect x="76" y="52" width="16" height="16" fill="white"/>
                <path d="M30 130h60" stroke="white" stroke-width="8" stroke-linecap="round"/>
              </svg>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Jouw QR-code</h2>
        <div class="qrwrap">
          <img src="<?= h($qrDataUri) ?>" alt="QR-code voor 2FA">
        </div>

        <div class="secret"><?= h(trim(chunk_split($secret, 4, ' '))) ?></div>
        <div class="small" style="text-align:center;margin-top:8px;">
          Handmatige sleutel voor het geval scannen niet lukt.
        </div>

        <form method="post" style="margin-top:18px;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="verify">

          <label>Vul hier de 6 cijfers uit je app in
            <input
              class="code"
              type="text"
              name="code"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              placeholder="123456"
              required
            >
          </label>

          <div class="row">
            <button class="btn" type="submit">2FA opslaan en afronden</button>
          </div>
        </form>

        <form method="post" style="margin-top:10px;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="regenerate">
          <button class="btn secondary" type="submit">Nieuwe QR-code maken</button>
        </form>

        <div class="note">
          <strong>Belangrijk</strong>
          <div class="small" style="margin-top:6px;">
            Zolang 2FA nog niet is afgerond, kom je niet verder in het systeem. Dat is bewust zo ingesteld.
          </div>
        </div>

        <div class="row">
          <a class="btn secondary" href="/logout.php">Uitloggen</a>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>