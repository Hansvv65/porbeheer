<?php
declare(strict_types=1);

require_once __DIR__ . '/cgi-bin/app/bootstrap.php';
include __DIR__ . '/assets/includes/header.php';

if (isLoggedIn()) {
  header('Location: /admin/dashboard.php');
  exit;
}

$errors = [];
$success = null;

$email = trim((string)($_GET['email'] ?? $_POST['email'] ?? ''));
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');

if ($email === '' || $token === '') {
  $errors[] = 'Ongeldige resetlink.';
}

$user = null;
if (!$errors) {
  $st = $pdo->prepare("SELECT id, username, reset_token_hash, reset_expires_at, status FROM users WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $user = $st->fetch(PDO::FETCH_ASSOC);

  if (!$user) $errors[] = 'Ongeldige resetlink.';
  if (($user['status'] ?? 'ACTIVE') === 'BLOCKED') $errors[] = 'Account is geblokkeerd.';
  if (!$errors) {
    $exp = $user['reset_expires_at'] ?? null;
    if (!$exp || strtotime((string)$exp) < time()) $errors[] = 'Resetlink is verlopen. Vraag een nieuwe aan.';
    $hash = (string)($user['reset_token_hash'] ?? '');
    if ($hash === '' || !password_verify($token, $hash)) $errors[] = 'Ongeldige resetlink.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
  requireCsrf($_POST['csrf'] ?? null);

  $p1 = (string)($_POST['password'] ?? '');
  $p2 = (string)($_POST['password2'] ?? '');
  if ($p1 === '' || mb_strlen($p1) < 10) $errors[] = 'Wachtwoord minimaal 10 tekens.';
  if ($p1 !== $p2) $errors[] = 'Wachtwoorden komen niet overeen.';

  if (!$errors) {
    $newHash = password_hash($p1, PASSWORD_DEFAULT);

    $pdo->prepare("UPDATE users
      SET password_hash=?, reset_token_hash=NULL, reset_expires_at=NULL, reset_requested_at=NULL
      WHERE id=?")->execute([$newHash, (int)$user['id']]);

    auditLog($pdo, 'PWD_RESET_OK', 'auth/reset', ['email' => $email]);
    $success = 'Wachtwoord aangepast. Je kunt nu inloggen.';
  }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Reset</title>
<style>
/* zelfde stijl als login/register */
:root{--text:#fff;--muted:rgba(255,255,255,.78);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('/assets/images/loginbg.png') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:26px;box-sizing:border-box;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));}
.box{width:min(460px,92vw);border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:22px;}
label{display:block;margin-top:12px}
input{width:100%;padding:11px;border-radius:12px;border:none;outline:none;margin-top:6px}
.btn{margin-top:16px;width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:linear-gradient(180deg, var(--glass), var(--glass2));color:#fff;font-weight:800;cursor:pointer}
.msg{margin-top:10px;font-size:13px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.err{color:#ffb3b3}
.ok{color:#7CFFB2}
a{color:#fff}
</style>
</head>
<body>
<div class="backdrop">
  <div class="box">
    <h1>Wachtwoord resetten</h1>

    <?php foreach ($errors as $e): ?><div class="msg err"><?= h($e) ?></div><?php endforeach; ?>
    <?php if ($success): ?>
      <div class="msg ok"><?= h($success) ?></div>
      <div style="margin-top:12px"><a href="/login.php">Naar inloggen</a></div>
    <?php endif; ?>

    <?php if (!$success && !$errors): ?>
    <div style="color:var(--muted);font-size:13px;margin-bottom:14px">
      Reset voor: <?= h($email) ?>
    </div>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="email" value="<?= h($email) ?>">
      <input type="hidden" name="token" value="<?= h($token) ?>">

      <label>Nieuw wachtwoord
        <input name="password" type="password" required minlength="10" autocomplete="new-password">
      </label>

      <label>Herhaal wachtwoord
        <input name="password2" type="password" required minlength="10" autocomplete="new-password">
      </label>

      <button class="btn" type="submit">Opslaan</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>