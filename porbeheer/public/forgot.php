<?php
declare(strict_types=1);

require_once __DIR__ . '/cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/cgi-bin/app/mail.php';

if (isLoggedIn()) {
  header('Location: /admin/dashboard.php');
  exit;
}

$errors = [];
$success = null;

function makeToken(int $bytes = 32): string {
  return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrf($_POST['csrf'] ?? null);

  $email = trim((string)($_POST['email'] ?? ''));

  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Ongeldig e-mailadres.';
  } else {
    // Altijd success message (privacy)
    $success = 'Als dit e-mailadres bekend is, ontvang je een e-mail met een resetlink.';

    $st = $pdo->prepare("SELECT id, username, status FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if ($u && (($u['status'] ?? 'ACTIVE') !== 'BLOCKED')) {
      $token = makeToken();
      $hash  = password_hash($token, PASSWORD_DEFAULT);
      $exp   = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');

      $pdo->prepare("UPDATE users
          SET reset_token_hash = ?,
              reset_expires_at = ?,
              reset_requested_at = NOW()
          WHERE id = ?")
        ->execute([$hash, $exp, (int)$u['id']]);

      $baseUrl = rtrim((string)(getenv('APP_BASE_URL') ?: 'https://porzbeheer.nl'), '/');
      $link = $baseUrl . "/reset.php?token=" . urlencode($token) . "&email=" . urlencode($email);

      $html = "
        <p>Hoi " . h((string)$u['username']) . ",</p>
        <p>Je hebt een wachtwoord-reset aangevraagd. Klik op de link hieronder (30 min geldig):</p>
        <p><a href=\"" . h($link) . "\">Wachtwoord resetten</a></p>
        <p>Heb jij dit niet aangevraagd? Dan kun je deze e-mail negeren.</p>
      ";

      sendEmail($email, 'Porbeheer wachtwoord reset', $html);
      auditLog($pdo, 'PWD_RESET_REQUEST', 'auth/forgot', ['email' => $email, 'user_id' => (int)$u['id']]);
    } else {
      auditLog($pdo, 'PWD_RESET_REQUEST_UNKNOWN', 'auth/forgot', ['email' => $email]);
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
<title>Porbeheer - Wachtwoord vergeten</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);--ok:#7CFFB2;--err:#FF8DA1;}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('/assets/images/loginbg.png') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:26px;box-sizing:border-box;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));}
.box{width:min(460px,92vw);border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:22px;}
label{display:block;margin-top:12px;font-weight:800}
input{width:100%;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);outline:none;margin-top:6px;background:rgba(0,0,0,.22);color:#fff;box-sizing:border-box}
.btn{margin-top:16px;width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:linear-gradient(180deg, var(--glass), var(--glass2));color:#fff;font-weight:900;cursor:pointer}
.msg{margin-top:10px;font-size:13px;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.msg.err{border-color: rgba(255,141,161,.35);background: rgba(255,141,161,.10);color: var(--err);font-weight:900;}
.msg.ok{border-color: rgba(124,255,178,.35);background: rgba(124,255,178,.10);color: var(--ok);font-weight:900;}
a{color:#fff}
</style>
</head>
<body>
<div class="backdrop">
  <div class="box">
    <h1>Wachtwoord vergeten</h1>
    <div style="color:var(--muted);font-size:13px;margin-bottom:14px">Vul je e-mailadres in voor een resetlink.</div>

    <?php foreach ($errors as $e): ?><div class="msg err"><?= h($e) ?></div><?php endforeach; ?>
    <?php if ($success): ?><div class="msg ok"><?= h($success) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label>E-mail
        <input name="email" type="email" required autocomplete="email">
      </label>
      <button class="btn" type="submit">Stuur resetlink</button>
    </form>

    <div style="margin-top:12px; font-size:13px; color: var(--muted);">
      <a href="/login.php">Terug naar inloggen</a>
    </div>
  </div>
</div>
</body>
</html>