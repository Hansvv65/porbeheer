<?php
declare(strict_types=1);

require_once __DIR__ . '/cgi-bin/app/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isLoggedIn()) {
    header('Location: ' . appUrl('/admin/dashboard.php'));
    exit;
}

$errors = [];
$success = null;

$email = strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? '')));
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
    $errors[] = 'Ongeldige resetlink.';
}

$user = null;

if (!$errors) {
    $st = $pdo->prepare("
        SELECT id, username, status, reset_token_hash, reset_expires_at, reset_requested_at
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        usleep(random_int(100000, 220000));
        $errors[] = 'Ongeldige resetlink.';
        auditLog($pdo, 'PWD_RESET_INVALID', 'auth/reset', [
            'email' => $email,
            'reason' => 'user_not_found',
        ]);
    }

    if ($user && (($user['status'] ?? 'ACTIVE') === 'BLOCKED')) {
        $errors[] = 'Deze resetlink is niet geldig.';
        auditLog($pdo, 'PWD_RESET_INVALID', 'auth/reset', [
            'email' => $email,
            'user_id' => (int)$user['id'],
            'reason' => 'blocked',
        ]);
    }

    if ($user && !$errors) {
        $exp  = (string)($user['reset_expires_at'] ?? '');
        $hash = (string)($user['reset_token_hash'] ?? '');

        if ($exp === '' || strtotime($exp) < time()) {
            $errors[] = 'Resetlink is verlopen. Vraag een nieuwe aan.';
            auditLog($pdo, 'PWD_RESET_EXPIRED', 'auth/reset', [
                'email' => $email,
                'user_id' => (int)$user['id'],
            ]);
        } elseif ($hash === '' || !password_verify($token, $hash)) {
            usleep(random_int(100000, 220000));
            $errors[] = 'Ongeldige resetlink.';
            auditLog($pdo, 'PWD_RESET_INVALID', 'auth/reset', [
                'email' => $email,
                'user_id' => (int)$user['id'],
                'reason' => 'token_mismatch',
            ]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors && $user) {
    requireCsrf($_POST['csrf'] ?? null);

    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');

    if ($p1 === '' || mb_strlen($p1) < 10) {
        $errors[] = 'Wachtwoord minimaal 10 tekens.';
    }
    if ($p1 !== $p2) {
        $errors[] = 'Wachtwoorden komen niet overeen.';
    }

    if (!$errors) {
        $newHash = password_hash($p1, PASSWORD_DEFAULT);

        $newStatus = (($user['status'] ?? 'ACTIVE') === 'PENDING')
            ? 'ACTIVE'
            : (string)($user['status'] ?? 'ACTIVE');

        $pdo->prepare("
            UPDATE users
            SET password_hash = ?,
                status = ?,
                reset_token_hash = NULL,
                reset_expires_at = NULL,
                reset_requested_at = NULL,
                failed_attempts = 0,
                locked_until = NULL
            WHERE id = ?
        ")->execute([
            $newHash,
            $newStatus,
            (int)$user['id'],
        ]);

        auditLog($pdo, 'PWD_RESET_OK', 'auth/reset', [
            'email' => $email,
            'user_id' => (int)$user['id'],
        ]);

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
<title>Porbeheer - Wachtwoord resetten</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);--ok:#7CFFB2;--err:#FF8DA1;}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('/assets/images/loginbg.png') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:26px;box-sizing:border-box;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));}
.box{width:min(460px,92vw);border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:22px;}
label{display:block;margin-top:12px;font-weight:800}
input{width:100%;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);outline:none;margin-top:6px;background:rgba(0,0,0,.22);color:#fff;box-sizing:border-box}
.btn{margin-top:16px;width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:linear-gradient(180deg, var(--glass), var(--glass2));color:#fff;font-weight:900;cursor:pointer}
.msg{margin-top:10px;font-size:13px;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.msg.err{border-color:rgba(255,141,161,.35);background:rgba(255,141,161,.10);color:var(--err);font-weight:900;}
.msg.ok{border-color:rgba(124,255,178,.35);background:rgba(124,255,178,.10);color:var(--ok);font-weight:900;}
a{color:#fff}
</style>
</head>
<body>
<div class="backdrop">
  <div class="box">
    <h1>Wachtwoord resetten</h1>

    <?php foreach ($errors as $e): ?>
      <div class="msg err"><?= h($e) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
      <div class="msg ok"><?= h($success) ?></div>
      <div style="margin-top:12px">
        <a href="<?= h(appUrl('/login.php')) ?>">Naar inloggen</a>
      </div>
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