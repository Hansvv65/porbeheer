<?php
declare(strict_types=1);

/* register.php - Aanmelden voor een account
 * - Gebruikers kunnen zich aanmelden met e‑mailadres en wachtwoord.
 * - Na aanmelding ontvangen ze een e‑mail met een bevestigingslink.
 * - Er wordt geen gebruikersnaam gevraagd; het e‑mailadres fungeert als loginnaam.
*/

require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../libs/porbeheer/app/mail.php';
include __DIR__ . '/assets/includes/header.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);

    // Alleen e‑mail en wachtwoord zijn verplicht
    $email     = strtolower(trim((string)($_POST['email'] ?? '')));
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ongeldig e‑mailadres.';
    }
    if ($password === '' || mb_strlen($password) < 10) {
        $errors[] = 'Wachtwoord minimaal 10 tekens.';
    }
    if ($password !== $password2) {
        $errors[] = 'Wachtwoorden komen niet overeen.';
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $chkUser = $pdo->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
        $chkUser->execute([$email]);
        if ($chkUser->fetchColumn()) {
            $errors[] = 'Er bestaat al een account met dit e-mailadres. Ben je het wachtwoord vergeten? Gebruik "wachtwoord vergeten".';
        }
    }

    if (!$errors) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->beginTransaction();

            // Gebruik e‑mailadres als gebruikersnaam (username) voor backward compatibiliteit
            $username = $email;

            // Matchen met een bestaand contact op e-mailadres: voorkomt een los,
            // ongekoppeld account voor iemand die al als contact bekend is (bijv.
            // een bandlid dat al eerder is opgevoerd bij zijn band).
            $matchedContactId = null;
            $chkContact = $pdo->prepare("SELECT id, name FROM contacts WHERE email = ? AND deleted_at IS NULL");
            $chkContact->execute([$email]);
            $matchedContact = $chkContact->fetch(PDO::FETCH_ASSOC);
            if ($matchedContact) {
                $matchedContactId = (int)$matchedContact['id'];
            }

            $st = $pdo->prepare("
                INSERT INTO users
                    (username, email, password_hash, role, status, active, contact_id, created_at, updated_at)
                VALUES
                    (?, ?, ?, 'GEBRUIKER', 'PENDING', 1, ?, NOW(), NOW())
            ");
            $st->execute([$username, $email, $hash, $matchedContactId]);
            $uid = (int)$pdo->lastInsertId();

            // Token genereren (zelfde methode als in login.php voor consistentie)
            $token = bin2hex(random_bytes(32));
            $tokenHash = password_hash($token, PASSWORD_DEFAULT);
            $exp = (new DateTime('+48 hours'))->format('Y-m-d H:i:s');

            // Gebruik consistente kolomnamen: verification_token en verification_expires
            $pdo->prepare("
                UPDATE users
                SET verification_token = ?, verification_expires = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$tokenHash, $exp, $uid]);

            // Los token voor snelle goedkeuring door de admin vanuit de meldingsmail.
            // Bewust een aparte token/kolom dan verification_token hierboven: dit
            // token keurt het account goed (admin-actie), dat token bevestigt het
            // e-mailadres (actie van de aanmelder zelf) -- verschillende dingen.
            $approveToken = bin2hex(random_bytes(32));
            $approveTokenHash = password_hash($approveToken, PASSWORD_DEFAULT);
            $approveExp = (new DateTime('+4 days'))->format('Y-m-d H:i:s');
            $pdo->prepare("
                UPDATE users
                SET approve_token_hash = ?, approve_expires_at = ?
                WHERE id = ?
            ")->execute([$approveTokenHash, $approveExp, $uid]);
            $approveLink = appUrl("/admin/quick_approve.php?id=" . $uid . "&token=" . $approveToken);

            $matchedBandName = null;
            if ($matchedContactId) {
                $mb = $pdo->prepare("
                    SELECT name FROM bands
                    WHERE (primary_contact_id = ? OR secondary_contact_id = ?) AND deleted_at IS NULL
                    LIMIT 1
                ");
                $mb->execute([$matchedContactId, $matchedContactId]);
                $matchedBandName = $mb->fetchColumn() ?: null;
            }

            $pdo->commit();

            // Link bouwen met dezelfde volgorde als in login.php: email eerst, token daarna
            $verifyLink = appUrl("/verify.php?email=" . urlencode($email) . "&token=" . $token);

            // E‑mail zonder voornaam, dus algemene aanhef
            $html = mailLayout(
                'Aanmelding ontvangen',
                'Je accountaanvraag voor Porbeheer is goed ontvangen.',
                '
                <p style="margin:0 0 14px 0;">Hoi,</p>

                <p style="margin:0 0 14px 0;">
                  Je account is aangemaakt, maar nog niet actief.
                </p>
' . ($matchedContact ? '
                <p style="margin:0 0 14px 0;background:#eef8ee;border:1px solid #b8e0b8;border-radius:8px;padding:10px 12px;">
                  ✅ We hebben je gekoppeld aan het bestaande contact <strong>' . h((string)$matchedContact['name']) . '</strong>' . ($matchedBandName ? ' (bandlid van <strong>' . h((string)$matchedBandName) . '</strong>)' : '') . '.
                </p>
' : '') . '

                <p style="margin:0 0 14px 0;">
                  Stap 1: bevestig eerst je e‑mailadres via onderstaande knop.
                  Deze link is <strong>48 uur geldig</strong>.
                </p>

                <p style="margin:18px 0;">
                  <a href="' . h($verifyLink) . '" style="display:inline-block;padding:12px 18px;background:#bf721f;border-radius:10px;color:#ffffff;text-decoration:none;font-weight:700;">
                    E‑mailadres bevestigen
                  </a>
                </p>

                <p style="margin:0 0 14px 0;">
                  Stap 2: daarna wacht je op goedkeuring door de beheerder.
                </p>

                <p style="margin:0 0 14px 0;">
                  Stap 3: bij je eerste login stel je ook nog een authenticator-app in voor extra beveiliging.
                </p>

                <p style="margin:0;">
                  Heb jij dit niet aangevraagd? Dan kun je deze e‑mail negeren.
                </p>
                '
            );

            // Gebruik dezelfde mailfunctie als in login.php (sendMail)
            sendMail($email, 'Bevestig je aanmelding (Porbeheer)', $html);

            // Beheerder informeren over de nieuwe aanmelding, zodat deze goedgekeurd kan worden.
            // Gaat naar iedereen met de rol ADMIN of BEHEER (niet meer hardcoded naar
            // één persoon) -- alleen ADMIN kan daadwerkelijk goedkeuren, BEHEER krijgt
            // 'm ook omdat die rol qua naam en taak het dichtst bij "beheerder" ligt.
            try {
                $adminRecipients = $pdo->query("
                    SELECT DISTINCT u.email, u.username
                    FROM users u
                    JOIN user_roles ur ON ur.user_id = u.id
                    WHERE ur.role IN ('ADMIN','BEHEER')
                      AND u.status = 'ACTIVE'
                      AND u.deleted_at IS NULL
                      AND u.email IS NOT NULL AND u.email <> ''
                    ORDER BY u.username
                ")->fetchAll(PDO::FETCH_ASSOC);

                $recipientNamesText = $adminRecipients
                    ? implode(', ', array_map(static fn($r) => (string)$r['username'], $adminRecipients))
                    : '';

                $adminHtml = mailLayout(
                    'Nieuwe aanmelding',
                    'Er heeft zich zojuist iemand aangemeld voor Porbeheer.',
                    '
                    <p style="margin:0 0 14px 0;">Hoi,</p>

                    <p style="margin:0 0 14px 0;">
                      Er is een nieuwe accountaanvraag binnengekomen:
                    </p>

                    <p style="margin:0 0 14px 0;">
                      <strong>E‑mail:</strong> ' . h($email) . '<br>
                      <strong>Aangevraagd op:</strong> ' . h((new DateTime())->format('d-m-Y H:i')) . '
                    </p>
' . ($matchedContact ? '
                    <p style="margin:0 0 14px 0;background:#eef8ee;border:1px solid #b8e0b8;border-radius:8px;padding:10px 12px;">
                      ✅ Automatisch gekoppeld aan bestaand contact: <strong>' . h((string)$matchedContact['name']) . '</strong> (op basis van e-mailadres).
                    </p>
' : '') . '
                    <p style="margin:0 0 14px 0;">
                      Het account staat op status <strong>PENDING</strong> en wacht nog op jouw goedkeuring
                      (de gebruiker moet eerst zelf nog het e‑mailadres bevestigen).
                    </p>

                    <p style="margin:18px 0;">
                      <a href="' . h($approveLink) . '" style="display:inline-block;padding:12px 18px;background:#2e7d32;border-radius:10px;color:#ffffff;text-decoration:none;font-weight:700;">
                        Goed- of afkeuren
                      </a>
                    </p>

                    <p style="margin:0;font-size:12px;color:#6b7c93;">
                      Deze link is 4 dagen geldig en werkt zonder in te loggen; je krijgt eerst nog een bevestigingsscherm te zien.
                    </p>
' . ($recipientNamesText !== '' ? '
                    <p style="margin:16px 0 0 0;font-size:12px;color:#6b7c93;">
                      Deze melding is verstuurd naar alle beheerders: ' . h($recipientNamesText) . '.
                    </p>
' : '') . '
                    '
                );

                foreach ($adminRecipients as $recipient) {
                    sendMail(
                        (string)$recipient['email'],
                        '🚨🚨 Nieuwe aanmelding Porbeheer – goedkeuring vereist 🚨🚨',
                        $adminHtml
                    );
                }
            } catch (Throwable $e) {
                // Het mislukken van deze notificatie mag de aanmelding van de gebruiker niet blokkeren.
                auditLog($pdo, 'REGISTER_ADMIN_NOTIFY_FAIL', 'auth/register', [
                    'email' => $email,
                    'error' => substr($e->getMessage(), 0, 200),
                ]);
            }

            auditLog($pdo, 'REGISTER_OK', 'auth/register', [
                'email'    => $email,
                'user_id'  => $uid,
            ]);

            $success = 'Aanmelding ontvangen. Controleer je e‑mail en bevestig eerst je e‑mailadres.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            auditLog($pdo, 'REGISTER_FAIL', 'auth/register', [
                'email'    => $email,
                'error'    => substr($e->getMessage(), 0, 200),
            ]);

            $errors[] = 'Aanmaken mislukt. Bestaat dit e‑mailadres al?';
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
<link rel="stylesheet" href="/assets/css/main.css">
<style>
:root{
  --err: #FF8DA1;
  --info: #bf721f;
  --ok: #7CFFB2;
}
.backdrop{
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  padding:26px;box-sizing:border-box;
  background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));
}
.wrap{
  width:min(980px,96vw);
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:22px;
}
@media (max-width: 920px){
  .wrap{ grid-template-columns:1fr; }
}
.box{
  border-radius:20px;border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:22px;
}
label{display:block;margin-top:12px;font-weight:800}
input{
  width:100%;padding:11px 12px;border-radius:12px;
  border:1px solid rgba(255,255,255,.18);outline:none;margin-top:6px;
  background:rgba(0,0,0,.22);color:#fff;box-sizing:border-box
}
input:focus { border-color: var(--info); outline: none; }
.btn{
  margin-top:16px;width:100%;padding:12px 14px;border-radius:12px;
  border:none; background:var(--info);
  color:#fff;font-weight:900;cursor:pointer;
  transition:opacity 0.2s;
}
.btn:hover{opacity:0.9;}
.msg{
  margin-top:10px;font-size:13px;padding:10px 12px;border-radius:12px;
  border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)
}
.msg.err{
  border-color:rgba(255,141,161,.35);background:rgba(255,141,161,.10);
  color:var(--err);font-weight:900;
}
.msg.ok{
  border-color:rgba(124,255,178,.35);background:rgba(124,255,178,.10);
  color:var(--ok);font-weight:900;
}
a{color:var(--info);text-decoration:none;font-weight:600;text-shadow:0 1px 2px rgba(0,0,0,0.4);}
a:hover{text-decoration:underline;filter:brightness(1.2);}
.flow{ display:grid; gap:12px; }
.step{
  display:grid; grid-template-columns:48px 1fr; gap:12px;
  padding:12px; border-radius:16px;
  background:rgba(0,0,0,.16); border:1px solid rgba(255,255,255,.12);
}
.num{
  width:48px;height:48px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;
  font-weight:900;font-size:18px;background:var(--info);color:#fff;
}
</style>
</head>
<body style="background-image:url('/assets/images/loginbg.png')">
<div class="backdrop">
  <div class="wrap">

    <div class="box">
      <h1>Aanmelden</h1>
      <div class="small">Account aanvragen voor Porbeheer</div>

      <?php foreach ($errors as $e): ?>
        <div class="msg err"><?= h($e) ?></div>
      <?php endforeach; ?>

      <?php if ($success): ?>
        <div class="msg ok"><?= h($success) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <!-- Geen gebruikersnaam, alleen e‑mail -->
        <label>E‑mail
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
        <a href="/login.php">Terug naar inloggen</a>
      </div>
    </div>

    <div class="box">
      <h2 style="margin-top:0;">Wat gebeurt er daarna?</h2>
      <div class="flow">
        <div class="step">
          <div class="num">1</div>
          <div><strong>E‑mail bevestigen</strong> Je ontvangt een link om je e‑mailadres te bevestigen.</div>
        </div>
        <div class="step">
          <div class="num">2</div>
          <div><strong>Goedkeuring beheerder</strong> Een beheerder zet je account daarna actief.</div>
        </div>
        <div class="step">
          <div class="num">3</div>
          <div><strong>Eerste login</strong> Je logt in met je e‑mailadres en wachtwoord.</div>
        </div>
        <div class="step">
          <div class="num">4</div>
          <div><strong>2FA instellen</strong> Je koppelt een authenticator-app op je telefoon en scant een QR-code.</div>
        </div>
        <div class="step">
          <div class="num">5</div>
          <div><strong>Profiel aanvullen</strong> Na goedkeuring vul je je naam en 06‑nummer in.</div>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>