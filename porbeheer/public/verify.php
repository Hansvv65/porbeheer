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

$email = trim((string)($_GET['email'] ?? ''));
$token = (string)($_GET['token'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
    $errors[] = 'Ongeldige bevestigingslink.';
} else {
    $st = $pdo->prepare("SELECT id, username, status, verify_token_hash, verify_expires_at, email_verified_at
                         FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        $errors[] = 'Ongeldige bevestigingslink.';
    } else {
        if (!empty($u['email_verified_at'])) {
            $success = 'Je e-mailadres is al bevestigd. Je kunt nu (na eventuele goedkeuring) inloggen.';
        } else {
            $exp = (string)($u['verify_expires_at'] ?? '');
            if ($exp === '' || strtotime($exp) < time()) {
                $errors[] = 'Bevestigingslink is verlopen. Meld je opnieuw aan.';
            } else {
                $hash = (string)($u['verify_token_hash'] ?? '');
                if ($hash === '' || !password_verify($token, $hash)) {
                    $errors[] = 'Ongeldige bevestigingslink.';
                } else {
                    $pdo->prepare("UPDATE users
                                   SET email_verified_at = NOW(),
                                       verify_token_hash = NULL,
                                       verify_expires_at = NULL
                                   WHERE id = ?")->execute([(int)$u['id']]);

                    auditLog($pdo, 'EMAIL_VERIFY_OK', 'auth/verify', ['email' => $email, 'user_id' => (int)$u['id']]);
                    $success = 'E-mailadres bevestigd. Als je account nog goedkeuring nodig heeft, ontvang je bericht.';
                }
            }
        }
    }
}

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Bevestigen</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);--ok:#7CFFB2;--err:#FF8DA1;}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('/assets/images/loginbg.png') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:26px;box-sizing:border-box;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));}
.box{width:min(520px,92vw);border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:22px;}
.msg{margin-top:10px;font-size:13px;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.msg.err{border-color: rgba(255,141,161,.35);background: rgba(255,141,161,.10);color: var(--err);font-weight:900;}
.msg.ok{border-color: rgba(124,255,178,.35);background: rgba(124,255,178,.10);color: var(--ok);font-weight:900;}
a{color:#fff}
</style>
</head>
<body>
<div class="backdrop">
  <div class="box">
    <h1>E-mail bevestigen</h1>
    <div style="color:var(--muted);font-size:13px;margin-bottom:14px">Bevestiging voor Porbeheer.</div>

    <?php foreach ($errors as $e): ?><div class="msg err"><?= h($e) ?></div><?php endforeach; ?>
    <?php if ($success): ?><div class="msg ok"><?= h($success) ?></div><?php endif; ?>

    <div style="margin-top:12px; font-size:13px; color: var(--muted);">
      <a href="/login.php">Terug naar inloggen</a>
    </div>
  </div>
</div>
</body>
</html>