<?php
declare(strict_types=1);

require_once __DIR__ . '/cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/cgi-bin/app/mail.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isLoggedIn()) {
    header('Location: ' . appUrl('/admin/dashboard.php'));
    exit;
}

function makeToken(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
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
                INSERT INTO users (username, email, password_hash, role, status, active, created_at)
                VALUES (?, ?, ?, 'GEBRUIKER', 'PENDING', 1, NOW())
            ");
            $st->execute([$username, $email, $hash]);
            $uid = (int)$pdo->lastInsertId();

            $token     = makeToken();
            $tokenHash = password_hash($token, PASSWORD_DEFAULT);
            $exp       = (new DateTime('+48 hours'))->format('Y-m-d H:i:s');

            $pdo->prepare("
                UPDATE users
                SET verify_token_hash = ?, verify_expires_at = ?
                WHERE id = ?
            ")->execute([$tokenHash, $exp, $uid]);

            $pdo->commit();

            $link = appUrl('/verify.php?' . http_build_query([
                'token' => $token,
                'email' => $email,
            ]));

            $attachments = [];
            $voorwaarden = __DIR__ . '/assets/docs/voorwaarden.pdf';
            if (is_file($voorwaarden)) {
                $attachments[] = [
                    'path' => $voorwaarden,
                    'name' => 'voorwaarden.pdf',
                    'type' => 'application/pdf',
                ];
            }

            $html = "
                <p>Hoi " . h($username) . ",</p>
                <p>Je aanmelding voor Porbeheer is ontvangen.</p>
                <p>Bevestig eerst je e-mailadres via deze link. Deze link is <strong>48 uur geldig</strong>.</p>
                <p><a href=\"" . h($link) . "\">E-mailadres bevestigen</a></p>
                <p>Daarna kan je account, indien nodig, door een beheerder worden goedgekeurd.</p>
                <p>Heb jij dit niet aangevraagd? Dan kun je deze e-mail negeren.</p>
            ";

            try {
                sendEmail($email, 'Bevestig je aanmelding (Porbeheer)', $html, '', $attachments);

                auditLog($pdo, 'REGISTER_OK', 'auth/register', [
                    'username' => $username,
                    'email' => $email,
                    'user_id' => $uid,
                ]);

                $success = 'Aanmelding ontvangen. Controleer je e-mail om je e-mailadres te bevestigen.';
            } catch (Throwable $e) {
                auditLog($pdo, 'REGISTER_MAIL_ERROR', 'auth/register', [
                    'username' => $username,
                    'email' => $email,
                    'user_id' => $uid,
                    'error' => substr($e->getMessage(), 0, 200),
                ]);

                $success = 'Aanmelding ontvangen. De bevestigingsmail kon nog niet worden verzonden.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            auditLog($pdo, 'REGISTER_FAIL', 'auth/register', [
                'username' => $username,
                'email' => $email,
                'error' => substr($e->getMessage(), 0, 200),
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
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);--ok:#7CFFB2;--err:#FF8DA1;}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('/assets/images/loginbg.png') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:26px;box-sizing:border-box;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));}
.box{width:min(480px,92vw);border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:22px;}
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
    <h1>Aanmelden</h1>
    <div style="color:var(--muted);font-size:13px;margin-bottom:14px">Account aanvragen voor Porbeheer</div>

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
      <a href="<?= h(appUrl('/login.php')) ?>">Terug naar inloggen</a>
    </div>
  </div>
</div>
</body>
</html>