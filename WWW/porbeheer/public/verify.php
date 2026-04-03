<?php
declare(strict_types=1);

/* verify.php - verwerkt e-mailverificatie links. */

require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../libs/porbeheer/app/mail.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if (isLoggedIn()) {
    header('Location: ' . appUrl('/admin/dashboard.php'));
    exit;
}

$errors = [];
$success = null;

$email = strtolower(trim((string)($_GET['email'] ?? '')));
$token = trim((string)($_GET['token'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
    $errors[] = 'Ongeldige bevestigingslink.';
} else {
    $st = $pdo->prepare("
        SELECT id, username, status, approved_at, verify_token_hash, verify_expires_at, email_verified_at
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        usleep(random_int(100000, 220000));

        $errors[] = 'Ongeldige bevestigingslink.';
        auditLog($pdo, 'EMAIL_VERIFY_INVALID', 'auth/verify', [
            'email'  => $email,
            'reason' => 'user_not_found',
        ]);
    } else {
        if (!empty($u['email_verified_at'])) {
            if (!empty($u['approved_at']) && (string)$u['status'] === 'ACTIVE') {
                $success = 'Je e-mailadres is bevestigd en je account is actief. Je kunt nu inloggen. Bij je eerste login stel je meteen 2FA in.';
            } else {
                $success = 'Je e-mailadres is bevestigd. Je account wacht nu nog op goedkeuring door de beheerder.';
            }

            auditLog($pdo, 'EMAIL_VERIFY_ALREADY_DONE', 'auth/verify', [
                'email'   => $email,
                'user_id' => (int)$u['id'],
            ]);
        } else {
            $exp  = (string)($u['verify_expires_at'] ?? '');
            $hash = (string)($u['verify_token_hash'] ?? '');

            if ($exp === '' || strtotime($exp) < time()) {
                $errors[] = 'Bevestigingslink is verlopen. Meld je opnieuw aan.';

                auditLog($pdo, 'EMAIL_VERIFY_EXPIRED', 'auth/verify', [
                    'email'   => $email,
                    'user_id' => (int)$u['id'],
                ]);
            } elseif ($hash === '' || !password_verify($token, $hash)) {
                usleep(random_int(100000, 220000));

                $errors[] = 'Ongeldige bevestigingslink.';

                auditLog($pdo, 'EMAIL_VERIFY_INVALID', 'auth/verify', [
                    'email'   => $email,
                    'user_id' => (int)$u['id'],
                    'reason'  => 'token_mismatch',
                ]);
            } else {
                $pdo->prepare("
                    UPDATE users
                    SET email_verified_at = NOW(),
                        verify_token_hash = NULL,
                        verify_expires_at = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    (int)$u['id']
                ]);

                auditLog($pdo, 'EMAIL_VERIFY_OK', 'auth/verify', [
                    'email'   => $email,
                    'user_id' => (int)$u['id'],
                ]);

                if (!empty($u['approved_at']) && (string)$u['status'] === 'ACTIVE') {
                    $success = 'Je e-mailadres is bevestigd en je account is actief. Je kunt nu inloggen. Bij je eerste login stel je meteen 2FA in.';
                } else {
                    $success = 'Je e-mailadres is bevestigd. Je account wacht nu nog op goedkeuring door de beheerder.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - E-mail bevestigen</title>
<style>
:root{
  --text:#fff;
  --muted:rgba(255,255,255,.78);
  --glass:rgba(255,255,255,.12);
  --glass2:rgba(255,255,255,.06);
  --shadow:0 14px 40px rgba(0,0,0,.45);
  --ok:#7CFFB2;
  --err:#FF8DA1;
  --info:#bf721f;
}
body{
  margin:0;
  font-family:Arial,sans-serif;
  color:var(--text);
  background:url('/assets/images/loginbg.png') no-repeat center center fixed;
  background-size:cover;
}
.backdrop{
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:26px;
  box-sizing:border-box;
  background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));
}
.box{
  width:min(520px,92vw);
  border-radius:20px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);
  backdrop-filter:blur(12px);
  padding:22px;
}
.msg{
  margin-top:10px;
  font-size:13px;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.08)
}
.msg.err{
  border-color:rgba(255,141,161,.35);
  background:rgba(255,141,161,.10);
  color:var(--err);
  font-weight:900;
}
.msg.ok{
  border-color:rgba(124,255,178,.35);
  background:rgba(124,255,178,.10);
  color:var(--ok);
  font-weight:900;
}
a{color:var(--info);text-decoration:none;font-weight:600;text-shadow:0 1px 2px rgba(0,0,0,0.4);}
a:hover{text-decoration:underline;filter:brightness(1.2);}
</style>
</head>
<body>
<div class="backdrop">
  <div class="box">
    <h1>E-mail bevestigen</h1>
    <div style="color:var(--muted);font-size:13px;margin-bottom:14px">Bevestiging voor Porbeheer.</div>

    <?php foreach ($errors as $e): ?>
      <div class="msg err"><?= h($e) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
      <div class="msg ok"><?= h($success) ?></div>
    <?php endif; ?>

    <div style="margin-top:12px;font-size:13px;color:var(--muted);">
      <a href="<?= h(appUrl('/login.php')) ?>">Terug naar inloggen</a>
    </div>
  </div>
</div>
</body>
</html>