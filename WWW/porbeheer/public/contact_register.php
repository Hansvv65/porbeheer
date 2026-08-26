<?php
declare(strict_types=1);

/*
 * contact_register.php
 *
 * Landingspagina voor de link uit een contact-uitnodiging (zie
 * admin/contact_edit.php + invite_lib.php). Het token bepaalt exact welk
 * contact dit is -- er is geen matching op naam/e-mail nodig. Na een
 * geldige registratie is het account meteen bruikbaar (geen aparte
 * PENDING/goedkeuringsstap): de admin heeft door het versturen van de
 * uitnodiging al impliciet goedgekeurd wie dit mag zijn.
 */

require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../libs/porbeheer/app/auth.php';
require_once __DIR__ . '/../../libs/porbeheer/app/invite_lib.php';
include __DIR__ . '/assets/includes/header.php';

if (isLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$invite = inviteValidateToken($pdo, $token);

$errors = [];
$success = null;

if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);

    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($password === '' || mb_strlen($password) < 10) {
        $errors[] = 'Wachtwoord minimaal 10 tekens.';
    }
    if ($password !== $password2) {
        $errors[] = 'Wachtwoorden komen niet overeen.';
    }
    // Iemand kan tussen het tonen van het formulier en het versturen ervan
    // een account hebben gekregen (bijv. dubbel geopende link) -- opnieuw checken.
    if (!$errors && inviteExistingAccountForContact($pdo, (int)$invite['contact_id'])) {
        $errors[] = 'Er bestaat al een account voor dit contact.';
    }

    if (!$errors) {
        $email = (string)($invite['contact_email'] ?? '');
        if ($email === '') {
            $errors[] = 'Dit contact heeft geen e-mailadres meer, neem contact op met het bestuur.';
        } else {
            $dup = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $dup->execute([$email, $email]);
            if ($dup->fetchColumn()) {
                $errors[] = 'Er bestaat al een account met dit e-mailadres. Neem contact op met het bestuur.';
            }
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare("
                INSERT INTO users
                    (username, email, password_hash, role, contact_id, status, active, approved_at, created_at, updated_at)
                VALUES
                    (?, ?, ?, 'GEBRUIKER', ?, 'ACTIVE', 1, NOW(), NOW(), NOW())
            ");
            $ins->execute([$email, $email, $hash, (int)$invite['contact_id']]);
            $newUserId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, 'BANDLID')")->execute([$newUserId]);
            if (function_exists('syncPrimaryRole')) {
                syncPrimaryRole($pdo, $newUserId);
            }

            inviteMarkTokenUsed($pdo, (int)$invite['id']);

            $pdo->commit();

            auditLog($pdo, 'CONTACT_ACCOUNT_REGISTERED', 'contact_register.php', [
                'user_id' => $newUserId, 'contact_id' => (int)$invite['contact_id'],
            ]);

            $success = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Aanmaken van je account is mislukt. Probeer het later opnieuw of neem contact op met het bestuur.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Account aanmaken</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
:root{--err:#FF8DA1;--ok:#7CFFB2;--info:#bf721f;
--glass:rgba(255,255,255,.14);--glass2:rgba(255,255,255,.08);--shadow:0 20px 50px rgba(0,0,0,.5);}
.backdrop{min-height:100vh;display:flex;align-items:center;justify-content:center;
padding:26px;box-sizing:border-box;
background:radial-gradient(circle at 25% 15%,rgba(0,0,0,.35),rgba(0,0,0,.75) 55%,rgba(0,0,0,.88));}
.shell{width:min(440px,96vw);}
.card{position:relative;border-radius:20px;border:1px solid rgba(255,255,255,.18);
background:linear-gradient(180deg,rgba(255,255,255,.14),rgba(255,255,255,.06));
box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:26px;}
.card h1{margin-top:0;margin-bottom:6px;font-size:20px;}
.card .sub{color:var(--muted);margin-bottom:20px;font-size:0.9rem;}
label{display:block;margin-bottom:14px;font-weight:bold;font-size:0.85rem;}
label span{display:block;font-weight:normal;color:var(--muted);margin-top:4px;font-size:0.8rem;}
input{padding:11px;border-radius:10px;border:1px solid var(--border);
background:rgba(0,0,0,0.2);color:#fff;font-size:1rem;width:100%;box-sizing:border-box;margin-top:6px;}
input:focus{outline:none;border-color:var(--info);background:rgba(0,0,0,0.3);}
.btn{width:100%;padding:12px;border-radius:10px;border:none;
background-color:var(--info);color:#fff;font-weight:900;font-size:1rem;cursor:pointer;margin-top:6px;text-decoration:none;display:block;text-align:center;box-sizing:border-box;}
.btn:hover{opacity:.9;}
.msg{padding:12px;border-radius:10px;margin-bottom:15px;font-size:0.9rem;border:1px solid rgba(255,255,255,0.15);}
.msg.err{background:rgba(255,107,129,0.15);color:var(--err);}
.msg.ok{background:rgba(124,255,178,0.15);color:var(--ok);}
</style>
</head>
<body style="background-image:url('/assets/images/loginbg.png')">
<div class="backdrop">
  <div class="shell">
    <div class="card">
      <h1>Porbeheer</h1>

      <?php if (!$invite): ?>
        <div class="sub">Account aanmaken</div>
        <div class="msg err">Deze uitnodigingslink is ongeldig, verlopen of al gebruikt. Vraag het bestuur om een nieuwe uitnodiging.</div>
        <a class="btn" href="/login.php">Naar inloggen</a>

      <?php elseif ($success): ?>
        <div class="sub">Account aangemaakt</div>
        <div class="msg ok">Je account is aangemaakt. Je kunt nu inloggen.</div>
        <a class="btn" href="/login.php">Naar inloggen</a>

      <?php else: ?>
        <div class="sub">Welkom <?= h((string)$invite['contact_name']) ?>, stel hieronder je wachtwoord in om je eigen boekingen te kunnen beheren.</div>

        <?php if ($errors): ?>
          <div class="msg err"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="token" value="<?= h($token) ?>">

          <label for="password">Wachtwoord
            <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
            <span>Minimaal 10 tekens.</span>
          </label>
          <label for="password2">Wachtwoord (herhaal)
            <input type="password" id="password2" name="password2" required minlength="10" autocomplete="new-password">
          </label>

          <button type="submit" class="btn">Account aanmaken</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
