<?php
declare(strict_types=1);

require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../libs/porbeheer/app/mail.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function makeToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function mailLayout(string $title, string $intro, string $contentHtml): string
{
    return '
    <div style="margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#243447;">
      <div style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #d9e2ec;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.08);">
        <div style="padding:18px 24px;background:linear-gradient(180deg,#eef6ff,#e6f0fb);border-bottom:1px solid #d9e2ec;">
          <div style="font-size:22px;font-weight:700;color:#1f3b57;">Porbeheer</div>
          <div style="margin-top:4px;font-size:13px;color:#5b7083;">POP Oefenruimte Zevenaar</div>
        </div>
        <div style="padding:28px 24px;">
          <h2 style="margin:0 0 12px 0;font-size:22px;color:#1f3b57;">' . h($title) . '</h2>
          <p style="margin:0 0 18px 0;font-size:15px;line-height:1.6;color:#425466;">' . h($intro) . '</p>
          <div style="font-size:15px;line-height:1.7;color:#243447;">' . $contentHtml . '</div>
        </div>
        <div style="padding:16px 24px;background:#f8fbff;border-top:1px solid #d9e2ec;font-size:12px;color:#6b7c93;">
          Dit is een automatisch bericht van Porbeheer.
        </div>
      </div>
    </div>';
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);

    $username  = trim((string)($_POST['username'] ?? ''));
    $email     = strtolower(trim((string)($_POST['email'] ?? '')));
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($username === '' || mb_strlen($username) < 3) {
        $errors[] = 'Gebruikersnaam minimaal 3 tekens.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ongeldig e-mailadres.';
    }
    if ($password === '' || mb_strlen($password) < 10) {
        $errors[] = 'Wachtwoord minimaal 10 tekens.';
    }
    if ($password !== $password2) {
        $errors[] = 'Wachtwoorden komen niet overeen.';
    }

    if (!$errors) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->beginTransaction();

            $st = $pdo->prepare("
                INSERT INTO users
                    (username, email, password_hash, role, status, active, created_at, updated_at)
                VALUES
                    (?, ?, ?, 'GEBRUIKER', 'PENDING', 1, NOW(), NOW())
            ");
            $st->execute([$username, $email, $hash]);
            $uid = (int)$pdo->lastInsertId();

            $token = makeToken();
            $tokenHash = password_hash($token, PASSWORD_DEFAULT);
            $exp = (new DateTime('+48 hours'))->format('Y-m-d H:i:s');

            $pdo->prepare("
                UPDATE users
                SET verify_token_hash = ?, verify_expires_at = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$tokenHash, $exp, $uid]);

            $pdo->commit();

            $link = '/verify.php?' . http_build_query([
                'token' => $token,
                'email' => $email,
            ]);

            $fullLink = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                . $link;

            $html = mailLayout(
                'Aanmelding ontvangen',
                'Je accountaanvraag voor Porbeheer is goed ontvangen.',
                '
                <p style="margin:0 0 14px 0;">Hoi ' . h($username) . ',</p>

                <p style="margin:0 0 14px 0;">
                  Je account is aangemaakt, maar nog niet actief.
                </p>

                <p style="margin:0 0 14px 0;">
                  Stap 1: bevestig eerst je e-mailadres via onderstaande knop.
                  Deze link is <strong>48 uur geldig</strong>.
                </p>

                <p style="margin:18px 0;">
                  <a href="' . h($fullLink) . '" style="display:inline-block;padding:12px 18px;background:#dfefff;border:1px solid #bdd3ea;border-radius:10px;color:#1f3b57;text-decoration:none;font-weight:700;">
                    E-mailadres bevestigen
                  </a>
                </p>

                <p style="margin:0 0 14px 0;">
                  Stap 2: daarna wacht je op goedkeuring door de beheerder.
                </p>

                <p style="margin:0 0 14px 0;">
                  Stap 3: bij je eerste login stel je ook nog een authenticator-app in voor extra beveiliging.
                </p>

                <p style="margin:0;">
                  Heb jij dit niet aangevraagd? Dan kun je deze e-mail negeren.
                </p>
                '
            );

            sendEmail($email, 'Bevestig je aanmelding (Porbeheer)', $html);

            auditLog($pdo, 'REGISTER_OK', 'auth/register', [
                'username' => $username,
                'email'    => $email,
                'user_id'  => $uid,
            ]);

            $success = 'Aanmelding ontvangen. Controleer je e-mail en bevestig eerst je e-mailadres.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            auditLog($pdo, 'REGISTER_FAIL', 'auth/register', [
                'username' => $username,
                'email'    => $email,
                'error'    => substr($e->getMessage(), 0, 200),
            ]);

            $errors[] = 'Aanmaken mislukt. Bestaat gebruikersnaam of e-mailadres al?';
        }
    }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Aanmelden</title>
<style>
:root{
  --text:#fff; --muted:rgba(255,255,255,.78); --border:rgba(255,255,255,.22);
  --glass:rgba(255,255,255,.12); --glass2:rgba(255,255,255,.06);
  --shadow:0 14px 40px rgba(0,0,0,.45); --ok:#7CFFB2; --err:#FF8DA1;
}
body{
  margin:0;font-family:Arial,sans-serif;color:var(--text);
  background:url('/assets/images/loginbg.png') no-repeat center center fixed;
  background-size:cover;
}
.backdrop{
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  padding:26px;box-sizing:border-box;
  background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));
}
.wrap{
  width:min(980px,96vw);
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:22px;
}
@media (max-width: 920px){
  .wrap{ grid-template-columns:1fr; }
}
.box{
  border-radius:20px;border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:22px;
}
label{display:block;margin-top:12px;font-weight:800}
input{
  width:100%;padding:11px 12px;border-radius:12px;
  border:1px solid rgba(255,255,255,.18);outline:none;margin-top:6px;
  background:rgba(0,0,0,.22);color:#fff;box-sizing:border-box
}
.btn{
  margin-top:16px;width:100%;padding:12px 14px;border-radius:12px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));
  color:#fff;font-weight:900;cursor:pointer
}
.msg{
  margin-top:10px;font-size:13px;padding:10px 12px;border-radius:12px;
  border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)
}
.msg.err{
  border-color:rgba(255,141,161,.35);background:rgba(255,141,161,.10);
  color:var(--err);font-weight:900;
}
.msg.ok{
  border-color:rgba(124,255,178,.35);background:rgba(124,255,178,.10);
  color:var(--ok);font-weight:900;
}
a{color:#fff}
.flow{ display:grid; gap:12px; }
.step{
  display:grid; grid-template-columns:48px 1fr; gap:12px;
  padding:12px; border-radius:16px;
  background:rgba(0,0,0,.16); border:1px solid rgba(255,255,255,.12);
}
.num{
  width:48px;height:48px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;
  font-weight:900;font-size:18px;background:rgba(255,255,255,.12);
}
.small{ font-size:13px; color:var(--muted); }
</style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">

    <div class="box">
      <h1>Aanmelden</h1>
      <div class="small">Account aanvragen voor Porbeheer</div>

      <?php foreach ($errors as $e): ?>
        <div class="msg err"><?= h($e) ?></div>
      <?php endforeach; ?>

      <?php if ($success): ?>
        <div class="msg ok"><?= h($success) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label>Gebruikersnaam
          <input name="username" required minlength="3" autocomplete="username">
        </label>

        <label>E-mail
          <input name="email" type="email" required autocomplete="email">
        </label>

        <label>Wachtwoord
          <input name="password" type="password" required minlength="10" autocomplete="new-password">
        </label>

        <label>Herhaal wachtwoord
          <input name="password2" type="password" required minlength="10" autocomplete="new-password">
        </label>

        <button class="btn" type="submit">Aanmelden</button>
      </form>

      <div style="margin-top:12px;font-size:13px;color:var(--muted);">
        <a href="/login.php">Terug naar inloggen</a>
      </div>
    </div>

    <div class="box">
      <h2 style="margin-top:0;">Wat gebeurt er daarna?</h2>
      <div class="flow">
        <div class="step">
          <div class="num">1</div>
          <div><strong>E-mail bevestigen</strong> Je ontvangt een link om je e-mailadres te bevestigen.</div>
        </div>
        <div class="step">
          <div class="num">2</div>
          <div><strong>Goedkeuring beheerder</strong> Een beheerder zet je account daarna actief.</div>
        </div>
        <div class="step">
          <div class="num">3</div>
          <div><strong>Eerste login</strong> Je logt in met gebruikersnaam en wachtwoord.</div>
        </div>
        <div class="step">
          <div class="num">4</div>
          <div><strong>2FA instellen</strong> Je koppelt een app op je telefoon en scant een QR-code.</div>
        </div>
        <div class="step">
          <div class="num">5</div>
          <div><strong>Klaar</strong> Daarna kun je het systeem normaal gebruiken.</div>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>