<?php
declare(strict_types=1);

/* verify.php - verwerkt e-mailverificatielinks */

require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../libs/porbeheer/app/mail.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

if (isLoggedIn()) {
    header('Location: ' . appUrl('/admin/dashboard.php'));
    exit;
}

$errors = [];
$success = null;

$email = strtolower(trim((string) ($_GET['email'] ?? '')));
$token = trim((string) ($_GET['token'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
    $errors[] = 'Ongeldige bevestigingslink.';
    auditLog($pdo, 'EMAIL_VERIFY_INVALID', 'auth/verify', [
        'email'  => $email,
        'reason' => 'missing_data',
    ]);
} else {
    $st = $pdo->prepare("
        SELECT id, username, status, approved_at, email_verified_at,
               verification_token, verification_expires
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        usleep(random_int(100000, 220000)); // timing-attack mitigatie
        $errors[] = 'Ongeldige bevestigingslink.';
        auditLog($pdo, 'EMAIL_VERIFY_INVALID', 'auth/verify', [
            'email'  => $email,
            'reason' => 'user_not_found',
        ]);
    } elseif (!empty($user['email_verified_at'])) {
        // al bevestigd
        $success = (!empty($user['approved_at']) && $user['status'] === 'ACTIVE')
            ? 'Je e‑mailadres is al bevestigd en je account is actief. Je kunt nu inloggen.'
            : 'Je e‑mailadres is al bevestigd. Je account wacht op goedkeuring door de beheerder.';
        auditLog($pdo, 'EMAIL_VERIFY_ALREADY_DONE', 'auth/verify', [
            'email'   => $email,
            'user_id' => (int) $user['id'],
        ]);
    } else {
        // Token en expiry controleren
        $tokenHash = (string) ($user['verification_token'] ?? '');
        $expiresAt = (string) ($user['verification_expires'] ?? '');

        // Waterdichte expiry-validatie
        $expired = false;
        if ($expiresAt === '' || $expiresAt === '0000-00-00 00:00:00') {
            $expired = true; // geen geldige datum
        } else {
            $expTs = strtotime($expiresAt);
            if ($expTs === false || $expTs < time()) {
                $expired = true;
            }
        }

        if ($expired) {
            $errors[] = 'De bevestigingslink is verlopen. Ga terug naar de inlogpagina en klik op "Bevestigingsmail opnieuw verzenden".';
            auditLog($pdo, 'EMAIL_VERIFY_EXPIRED', 'auth/verify', [
                'email'      => $email,
                'user_id'    => (int) $user['id'],
                'expires_at' => $expiresAt,
            ]);
        } elseif ($tokenHash === '' || !password_verify($token, $tokenHash)) {
            usleep(random_int(100000, 220000));
            $errors[] = 'Ongeldige bevestigingslink.';
            auditLog($pdo, 'EMAIL_VERIFY_INVALID', 'auth/verify', [
                'email'   => $email,
                'user_id' => (int) $user['id'],
                'reason'  => 'token_mismatch',
            ]);
        } else {
            // Alles correct – markeer als bevestigd en wis token
            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare("
                    UPDATE users
                    SET email_verified_at = NOW(),
                        verification_token = NULL,
                        verification_expires = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update->execute([(int) $user['id']]);
                $pdo->commit();

                auditLog($pdo, 'EMAIL_VERIFY_OK', 'auth/verify', [
                    'email'   => $email,
                    'user_id' => (int) $user['id'],
                ]);

                $success = (!empty($user['approved_at']) && $user['status'] === 'ACTIVE')
                    ? 'Je e‑mailadres is bevestigd en je account is actief. Je kunt nu inloggen.'
                    : 'Je e‑mailadres is bevestigd. Je account wacht op goedkeuring door de beheerder.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Er is een fout opgetreden bij het bevestigen. Probeer het later opnieuw.';
                auditLog($pdo, 'EMAIL_VERIFY_DB_ERROR', 'auth/verify', [
                    'email'   => $email,
                    'user_id' => (int) $user['id'],
                    'error'   => $e->getMessage(),
                ]);
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