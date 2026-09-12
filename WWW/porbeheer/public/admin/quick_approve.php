<?php
declare(strict_types=1);

/*
 * admin/quick_approve.php
 *
 * Directe goedkeuring van een nieuwe accountaanvraag via de link in de
 * meldingsmail (zie register.php). Werkt bewust zonder login -- het
 * geheime token zelf is de autorisatie, net als bij verify.php voor
 * e-mailbevestiging. Om te voorkomen dat een virusscanner of e-mailclient
 * de link per ongeluk activeert door 'm te "bezoeken", voert het openen
 * van de link (GET) de goedkeuring nog niet uit -- pas een knop op het
 * bevestigingsscherm (POST) doet dat. Naast "Ja, goedkeuren" staat ook
 * "Nee, nog niet" -- dat maakt de link ongeldig maar laat het account
 * gewoon op PENDING staan (handmatig alsnog goed te keuren via de
 * gebruikerspagina).
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/mail.php';
include __DIR__ . '/../assets/includes/header.php';

$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');

// screen: 'error' | 'already_active' | 'declined_now' | 'approved_now' | 'confirm'
$screen = 'error';
$errorMsg = '';
$user = null;

if ($id <= 0 || $token === '') {
    $errorMsg = 'Ongeldige link.';
} else {
    $st = $pdo->prepare("SELECT id, username, email, status, approve_token_hash, approve_expires_at FROM users WHERE id = ?");
    $st->execute([$id]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $errorMsg = 'Account niet gevonden.';
    } elseif ((string)$user['status'] === 'ACTIVE') {
        // Al actief -- of via deze link, of via de gewone knop. In beide
        // gevallen is er niets meer te doen, en is dat geen foutmelding.
        $screen = 'already_active';
    } elseif ((string)($user['approve_token_hash'] ?? '') === '') {
        // Token al gebruikt/ingetrokken (bijv. eerder al afgewezen), account
        // staat nog op PENDING.
        $errorMsg = 'Deze link is niet meer geldig. Het account staat nog op PENDING; keur eventueel handmatig goed via de gebruikerspagina.';
    } else {
        $expiresAt = (string)($user['approve_expires_at'] ?? '');
        $expired = ($expiresAt === '' || (strtotime($expiresAt) ?: 0) < time());

        if ($expired) {
            $errorMsg = 'Deze goedkeuringslink is verlopen. Keur het account goed via de gebruikerspagina.';
        } elseif (!password_verify($token, (string)$user['approve_token_hash'])) {
            usleep(random_int(100000, 220000));
            $errorMsg = 'Ongeldige goedkeuringslink.';
        } else {
            $screen = 'confirm';
        }
    }
}

if ($screen === 'confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = (string)($_POST['decision'] ?? '');

    if ($decision === 'approve') {
        $pdo->prepare("
            UPDATE users
            SET status = 'ACTIVE',
                approved_at = COALESCE(approved_at, NOW()),
                approve_token_hash = NULL,
                approve_expires_at = NULL
            WHERE id = ?
        ")->execute([$id]);
        syncActiveWithStatus($pdo, $id);

        if (!empty($user['email'])) {
            $loginLink = appUrl('/login.php');
            $html = mailLayout('Aanmelding goedgekeurd', 'Je account is goedgekeurd.',
                '<p>Je kunt nu inloggen.</p><p style="margin-top:16px;"><a href="' . h($loginLink) . '" style="display:inline-block;padding:10px 20px;background:#bf721f;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Naar inloggen</a></p>'
            );
            try {
                sendEmail((string)$user['email'], 'Je aanmelding is goedgekeurd', $html);
            } catch (Throwable $e) {
                auditLog($pdo, 'MAIL', 'quick_approve_confirm_mail_failed', ['id' => $id, 'error' => substr($e->getMessage(), 0, 200)]);
            }
        }

        auditLog($pdo, 'USER_APPROVED', 'admin/quick_approve.php', ['id' => $id, 'via' => 'email_link']);
        $screen = 'approved_now';
    } elseif ($decision === 'decline') {
        $pdo->prepare("UPDATE users SET approve_token_hash = NULL, approve_expires_at = NULL WHERE id = ?")->execute([$id]);
        auditLog($pdo, 'USER_APPROVE_DECLINED', 'admin/quick_approve.php', ['id' => $id, 'via' => 'email_link']);
        $screen = 'declined_now';
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Account goedkeuren</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
.approve-panel{max-width:460px;margin:0 auto;text-align:center;padding:34px 28px;}
.approve-panel .icon{font-size:40px;line-height:1;margin-bottom:6px;}
.approve-panel h1{margin:0 0 8px 0;font-size:20px;}
.approve-panel p{color:var(--muted);margin:0 0 20px 0;font-size:14px;}
.approve-panel .btnrow{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
.approve-panel .btn{
  display:inline-block;padding:12px 20px;border-radius:12px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));
  color:#fff;text-decoration:none;font-weight:800;cursor:pointer;font-size:15px;
}
.approve-panel .btn:hover{border-color:rgba(255,255,255,.38)}
.approve-panel .btn.decline{background:rgba(255,255,255,.06);}
</style>
</head>
<body>
<div class="backdrop">
  <div class="panel approve-panel">
    <?php if ($screen === 'error'): ?>
      <div class="icon">⚠️</div>
      <h1>Kan niet goedkeuren</h1>
      <p><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></p>
      <a class="btn" href="/login.php">Naar inloggen</a>
    <?php elseif ($screen === 'already_active'): ?>
      <div class="icon">ℹ️</div>
      <h1>Deze link is al gebruikt</h1>
      <p><?= $user ? htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') : '' ?> is al eerder goedgekeurd en is al actief.</p>
      <a class="btn" href="/login.php">Naar inloggen</a>
    <?php elseif ($screen === 'approved_now'): ?>
      <div class="icon">✅</div>
      <h1>Account goedgekeurd</h1>
      <p><?= $user ? htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') : '' ?> is nu actief. Er is een bevestigingsmail verstuurd.</p>
      <a class="btn" href="/login.php">Naar inloggen</a>
    <?php elseif ($screen === 'declined_now'): ?>
      <div class="icon">↩️</div>
      <h1>Nog niet goedgekeurd</h1>
      <p><?= $user ? htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') : '' ?> staat nog op PENDING. Deze link werkt niet meer; keur eventueel later handmatig goed via de gebruikerspagina.</p>
      <a class="btn" href="/login.php">Naar inloggen</a>
    <?php else: /* confirm */ ?>
      <div class="icon">❓</div>
      <h1>Account goedkeuren?</h1>
      <p><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?> heeft een account aangevraagd.</p>
      <div class="btnrow">
        <form method="post">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="decision" value="approve">
          <button class="btn" type="submit">Ja, goedkeuren</button>
        </form>
        <form method="post">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="decision" value="decline">
          <button class="btn decline" type="submit">Nee, nog niet</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
