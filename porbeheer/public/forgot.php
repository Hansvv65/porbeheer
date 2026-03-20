<?php
declare(strict_types=1);

require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../libs/porbeheer/app/mail.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isLoggedIn()) {
    header('Location: ' . appUrl('/admin/dashboard.php'));
    exit;
}

$errors = [];
$success = null;

function makeToken(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function mailLayout(string $title, string $intro, string $contentHtml): string {
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

          <div style="font-size:15px;line-height:1.7;color:#243447;">
            ' . $contentHtml . '
          </div>
        </div>

        <div style="padding:16px 24px;background:#f8fbff;border-top:1px solid #d9e2ec;font-size:12px;color:#6b7c93;">
          Dit is een automatisch bericht van Porbeheer.
        </div>
      </div>
    </div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);

    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ongeldig e-mailadres.';
    } else {
        $success = 'Als dit e-mailadres bekend is, ontvang je een e-mail met een resetlink.';

        $st = $pdo->prepare("
            SELECT id, username, status, reset_requested_at
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if ($u && (($u['status'] ?? 'ACTIVE') !== 'BLOCKED')) {
            $uid = (int)$u['id'];

            if (!empty($u['reset_requested_at']) &&
                strtotime((string)$u['reset_requested_at']) > time() - 60) {

                auditLog($pdo, 'PWD_RESET_RATE_LIMIT', 'auth/forgot', [
                    'user_id' => $uid,
                    'email' => $email,
                ]);

            } else {
                $token = makeToken();
                $hash  = password_hash($token, PASSWORD_DEFAULT);
                $exp   = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');

                $pdo->prepare("
                    UPDATE users
                    SET reset_token_hash = ?,
                        reset_expires_at = ?,
                        reset_requested_at = NOW()
                    WHERE id = ?
                ")->execute([$hash, $exp, $uid]);

                $link = appUrl('/reset.php?' . http_build_query([
                    'token' => $token,
                    'email' => $email,
                ]));

                $html = mailLayout(
                    'Wachtwoord reset aanvragen',
                    'Er is een verzoek gedaan om je wachtwoord opnieuw in te stellen.',
                    '
                    <p style="margin:0 0 14px 0;">Hoi ' . h((string)$u['username']) . ',</p>

                    <p style="margin:0 0 14px 0;">
                    Je hebt een wachtwoord-reset aangevraagd voor je Porbeheer-account.
                    </p>

                    <p style="margin:0 0 14px 0;">
                    Via onderstaande knop kun je een nieuw wachtwoord instellen. Deze link is
                    <strong>30 minuten geldig</strong>.
                    </p>

                    <p style="margin:18px 0;">
                    <a href="' . h($link) . '" style="display:inline-block;padding:12px 18px;background:#dfefff;border:1px solid #bdd3ea;border-radius:10px;color:#1f3b57;text-decoration:none;font-weight:700;">
                        Wachtwoord resetten
                    </a>
                    </p>

                    <p style="margin:0;">
                    Heb jij dit niet aangevraagd? Dan kun je deze e-mail negeren.
                    </p>
                    '
                );

                try {
                    sendEmail($email, 'Porbeheer wachtwoord reset', $html);

                    auditLog($pdo, 'PWD_RESET_REQUEST', 'auth/forgot', [
                        'user_id' => $uid,
                        'email' => $email,
                    ]);
                } catch (Throwable $e) {
                    auditLog($pdo, 'MAIL_ERROR', 'auth/forgot', [
                        'user_id' => $uid,
                        'email' => $email,
                        'error' => substr($e->getMessage(), 0, 200),
                    ]);
                }
            }
        } else {
            usleep(random_int(120000, 250000));

            auditLog($pdo, 'PWD_RESET_REQUEST_UNKNOWN', 'auth/forgot', [
                'email' => $email,
            ]);
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
.msg.err{border-color:rgba(255,141,161,.35);background:rgba(255,141,161,.10);color:var(--err);font-weight:900;}
.msg.ok{border-color:rgba(124,255,178,.35);background:rgba(124,255,178,.10);color:var(--ok);font-weight:900;}
a{color:#fff}
</style>
</head>
<body>
<div class="backdrop">
  <div class="box">
    <h1>Wachtwoord vergeten</h1>
    <div style="color:var(--muted);font-size:13px;margin-bottom:14px">Vul je e-mailadres in voor een resetlink.</div>

    <?php foreach ($errors as $e): ?>
      <div class="msg err"><?= h($e) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
      <div class="msg ok"><?= h($success) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label>E-mail
        <input name="email" type="email" required autocomplete="email">
      </label>
      <button class="btn" type="submit">Stuur resetlink</button>
    </form>

    <div style="margin-top:12px;font-size:13px;color:var(--muted);">
      <a href="<?= h(appUrl('/login.php')) ?>">Terug naar inloggen</a>
    </div>
  </div>
</div>
</body>
</html>