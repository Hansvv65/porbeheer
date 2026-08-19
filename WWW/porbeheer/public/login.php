<?php
declare(strict_types=1);
require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
include __DIR__ . '/assets/includes/header.php';
require_once __DIR__ . '/../../libs/porbeheer/app/mail.php';
require_once __DIR__ . '/../../libs/porbeheer/app/auth.php';

function getClientIp(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

if (isLoggedIn()) {
    header('Location: ' . (mustSetup2fa() ? '/admin/setup-2fa.php' : '/admin/dashboard.php'));
    exit;
}
if (isset($_GET['cancel2fa'])) {
    clearPending2fa();
    header('Location: /login.php');
    exit;
}

$err = null;
$info = null;
$email = '';
$ip = getClientIp();
$timestamp = date('Y-m-d H:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? 'login');

    // ---- verificatiemail opnieuw verzenden (robuust) ----
    if ($action === 'resend_verification') {
        $lastResend = $_SESSION['last_resend_verification'] ?? 0;
        if (time() - $lastResend < 120) {
            $err = "Je kunt maximaal één keer per 2 minuten een verificatiemail aanvragen. Probeer het later opnieuw.";
        } elseif (empty($_SESSION['pending_verify_email'])) {
            $err = "Geen e-mailadres beschikbaar. Probeer opnieuw in te loggen.";
        } else {
            $pendingEmail = $_SESSION['pending_verify_email'];
            $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ? AND email_verified_at IS NULL");
            $stmt->execute([$pendingEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $err = "Geen niet-bevestigde gebruiker gevonden met dit e-mailadres.";
                unset($_SESSION['pending_verify_email']);
                auditLog($pdo, 'RESEND_VERIFICATION_USER_NOT_FOUND', 'auth/login', [
                    'email' => $pendingEmail,
                    'ip'    => $ip,
                    'timestamp' => $timestamp
                ]);
            } else {
                // Token aanmaken en hashed opslaan
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                $hashedToken = password_hash($token, PASSWORD_DEFAULT);

                // Update database – we committen deze ALTIJD (geen rollback op mailprobleem)
                try {
                    $update = $pdo->prepare("UPDATE users SET verification_token = ?, verification_expires = ?, updated_at = NOW() WHERE id = ?");
                    $update->execute([$hashedToken, $expires, $user['id']]);
                } catch (PDOException $e) {
                    $err = "Er is een databasefout opgetreden. Probeer het later opnieuw.";
                    auditLog($pdo, 'RESEND_VERIFICATION_DB_ERROR', 'auth/login', [
                        'email'   => $user['email'],
                        'user_id' => $user['id'],
                        'ip'      => $ip,
                        'timestamp' => $timestamp,
                        'error'   => $e->getMessage()
                    ]);
                    // stop hier – geen mail sturen met een ongeldige token
                    // we tonen de fout en gaan niet verder
                    goto end_resend; // we springen naar het label om de rest over te slaan
                }

                // Link opbouwen
                $verifyLink = appUrl("/verify.php?email=" . urlencode($user['email']) . "&token=" . $token);
                $subject = "Bevestig je e-mailadres voor Porbeheer";
                $htmlBody = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2>Bevestig je e-mailadres</h2>
                    <p>Beste " . htmlspecialchars($user['username']) . ",</p>
                    <p>Klik op de onderstaande link om je e-mailadres te bevestigen en je account te activeren:</p>
                    <p><a href='" . htmlspecialchars($verifyLink) . "' style='display: inline-block; padding: 10px 20px; background-color: #bf721f; color: #fff; text-decoration: none; border-radius: 5px;'>Bevestig nu</a></p>
                    <p>Of kopieer deze link in je browser:<br>" . htmlspecialchars($verifyLink) . "</p>
                    <p>Deze link is <strong>24 uur geldig</strong>.</p>
                    <p>Met vriendelijke groet,<br>Porbeheer</p>
                </div>";
                $textBody = "Beste " . $user['username'] . ",\n\n"
                          . "Klik op de volgende link om je e-mailadres te bevestigen:\n"
                          . $verifyLink . "\n\n"
                          . "Deze link is 24 uur geldig.\n\n"
                          . "Met vriendelijke groet,\nPorbeheer";

                // Verstuur e-mail
                $mailSent = sendMail($user['email'], $subject, $htmlBody, $textBody);

                if ($mailSent) {
                    $_SESSION['last_resend_verification'] = time();
                    $info = "Er is een nieuwe bevestigingsmail verzonden naar " . h($user['email']) . ". Check je inbox (ook spam).";
                    auditLog($pdo, 'RESEND_VERIFICATION_OK', 'auth/login', [
                        'email'   => $user['email'],
                        'user_id' => $user['id'],
                        'ip'      => $ip,
                        'timestamp' => $timestamp
                    ]);
                } else {
                    // Mailfunctie gaf false terug, maar token is al opgeslagen en geldig
                    $info = "Bevestigingsmail naar " . h($user['email']) . " kon niet onmiddellijk worden verzonden, maar de link is al actief. Controleer je spamfolder of vraag de mail later opnieuw aan. (Technische foutmelding bij verzenden, neem contact op met beheerder indien nodig.)";
                    auditLog($pdo, 'RESEND_VERIFICATION_MAIL_WARNING', 'auth/login', [
                        'email'   => $user['email'],
                        'user_id' => $user['id'],
                        'ip'      => $ip,
                        'timestamp' => $timestamp,
                        'note'    => 'sendMail returned false'
                    ]);
                }
            }
        }
        end_resend: // label om DB-fout af te handelen zonder de rest van de code te storen
    }
    // ---- einde resend ----

    if ($action === 'login') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $logResult = '';
        $userId = null;

        if ($email === '' || strlen($email) > 80 || strlen($password) > 200) {
            $err = 'Controleer je e‑mailadres en wachtwoord.';
            $logResult = 'validation_failed';
            auditLog($pdo, 'LOGIN_ATTEMPT', 'auth/login', [
                'email'      => $email ?: '(leeg)',
                'ip'         => $ip,
                'timestamp'  => $timestamp,
                'result'     => $logResult,
                'reason'     => $err
            ]);
        } else {
            $res = attemptPrimaryLogin($pdo, $email, $password);
            $code = (string)($res['code'] ?? '');
            $userId = $res['user_id'] ?? null;

            if (!empty($res['ok'])) {
                $logResult = 'success_needs_2fa';
                auditLog($pdo, 'LOGIN_ATTEMPT', 'auth/login', [
                    'email'      => $email,
                    'user_id'    => $userId,
                    'ip'         => $ip,
                    'timestamp'  => $timestamp,
                    'result'     => $logResult
                ]);
                header('Location: /admin/setup-2fa.php?welcome=1');
                exit;
            }

            switch ($code) {
                case 'NEEDS_2FA':
                    $info = 'Je wachtwoord is goed. Vul nu de 6‑cijferige code uit je authenticator‑app in.';
                    $logResult = 'password_ok_needs_2fa';
                    break;
                case 'PENDING_EMAIL':
                    $err = 'Je e‑mailadres is nog niet bevestigd.';
                    $logResult = 'pending_email';
                    $_SESSION['pending_verify_email'] = $email;
                    break;
                case 'PENDING_APPROVAL':
                    $err = 'Je account wacht nog op goedkeuring.';
                    $logResult = 'pending_approval';
                    break;
                case 'BLOCKED':
                    $err = 'Je account is geblokkeerd.';
                    $logResult = 'blocked';
                    break;
                case 'LOCKED':
                    $lockedUntil = $res['locked_until'] ?? '';
                    $err = 'Je account is vergrendeld tot ' . (string)$lockedUntil . '.';
                    $logResult = 'locked';
                    break;
                default:
                    $err = 'Inloggen mislukt.';
                    $logResult = 'invalid_credentials';
                    break;
            }

            auditLog($pdo, 'LOGIN_ATTEMPT', 'auth/login', [
                'email'      => $email,
                'user_id'    => $userId,
                'ip'         => $ip,
                'timestamp'  => $timestamp,
                'result'     => $logResult,
                'code'       => $code
            ]);
        }
    }

    if ($action === 'totp') {
        $codeInput = trim((string)($_POST['totp_code'] ?? ''));
        $res = verifyPending2faCode($pdo, $codeInput);
        if (!empty($res['ok'])) {
            auditLog($pdo, 'LOGIN_2FA_OK', 'auth/login', [
                'email'      => $email,
                'ip'         => $ip,
                'user_id'    => $res['user_id'] ?? null,
                'timestamp'  => $timestamp
            ]);
            header('Location: /admin/dashboard.php');
            exit;
        }
        $err = 'De ingevoerde code klopt niet. Probeer het opnieuw.';
        auditLog($pdo, 'LOGIN_2FA_FAILED', 'auth/login', [
            'email'    => $email,
            'username' => loadPending2faUser($pdo)['username'] ?? 'unknown',
            'ip'       => $ip,
            'timestamp'=> $timestamp,
            'result'   => 'invalid_totp'
        ]);
    }
}

$csrf = csrfToken();
$pending2fa = hasPending2fa();
$pendingUser = $pending2fa ? loadPending2faUser($pdo) : null;
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Inloggen</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
/* Loginpagina heeft bewust een eigen, compacte kaartstijl i.p.v. de admin-layout. */
:root{--err:#FF8DA1;--ok:#7CFFB2;--info:#bf721f;--warn:#ffd86b;
--glass:rgba(255,255,255,.14);--glass2:rgba(255,255,255,.08);--shadow:0 20px 50px rgba(0,0,0,.5);}
.backdrop{min-height:100vh;display:flex;align-items:center;justify-content:center;
padding:26px;box-sizing:border-box;
background:radial-gradient(circle at 25% 15%,rgba(0,0,0,.35),rgba(0,0,0,.75) 55%,rgba(0,0,0,.88));}
.shell{width:min(460px,96vw);}
.card{position:relative;border-radius:20px;border:1px solid rgba(255,255,255,.18);
background:linear-gradient(180deg,rgba(255,255,255,.14),rgba(255,255,255,.06));
box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:22px;}

.card h1, .card h2 { margin-top: 0; margin-bottom: 8px; }
.card .sub { color: var(--muted); margin-bottom: 24px; font-size: 0.95rem; }

label { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; font-weight: bold; font-size: 0.9rem; }
input { 
  padding: 12px; border-radius: 10px; border: 1px solid var(--border); 
  background: rgba(0,0,0,0.2); color: #fff; font-size: 1rem; width: 100%; box-sizing: border-box;
}
input:focus { outline: none; border-color: var(--info); background: rgba(0,0,0,0.3); }

.btn { 
  width: 100%; padding: 12px; border-radius: 10px; border: none; 
  background-color: var(--info); color: #fff; font-weight: 900; font-size: 1rem; cursor: pointer; margin-top: 10px;
  transition: opacity 0.2s;
}
.btn:hover { opacity: 0.9; }
.actions { display: flex; justify-content: space-between; margin-top: 20px; gap: 10px; }
.action { color: var(--info); text-decoration: none; font-size: 0.9rem; font-weight: 600; text-shadow: 0 1px 2px rgba(0,0,0,0.4); }
.action:hover { text-decoration: underline; filter: brightness(1.2); }

.helpbtn{
  position:absolute;top:16px;right:16px;
  display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,.35);border:1px solid var(--border);
  border-radius:50%;width:38px;height:38px;
  color:#fff;font-weight:900;font-size:18px;
  text-decoration:none;line-height:1;
  cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.4);
}
.helpbtn:hover{background:rgba(255,255,255,.1);}
.msg{padding:12px;border-radius:10px;margin-bottom:15px;font-size:0.9rem;border:1px solid rgba(255,255,255,0.15);}
.msg.err{background:rgba(255,107,129,0.15);color:var(--err);border-color:var(--err);}
.msg.info{background:rgba(255,179,71,0.15);color:var(--info);border-color:var(--info);}
</style>
</head>
<body style="background-image:url('/assets/images/loginbg.png')">
<div class="backdrop">
  <div class="shell">
    <div class="card">
      <a class="helpbtn" href="/Handleiding-Inloggen-Porbeheer.pdf" target="_blank" rel="noopener noreferrer" title="Handleiding inloggen (PDF)">?</a>

      <h1>POP Zevenaar</h1>
      <div class="sub">Veilig inloggen met wachtwoord en 2FA</div>

      <?php if($err):?><div class="msg err"><?=h($err)?></div><?php endif;?>
      <?php if($info):?><div class="msg info"><?=h($info)?></div><?php endif;?>

      <?php if(!$pending2fa):?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="login">
        <label>E‑mail
          <input name="email" type="email" required autocomplete="email" value="<?=h($email)?>" placeholder="jouw@email.nl">
        </label>
        <label>Wachtwoord
          <input name="password" type="password" required autocomplete="current-password" placeholder="••••••••">
        </label>
        <button class="btn" type="submit">Inloggen</button>
        <div class="actions">
          <a class="action" href="/forgot.php">Wachtwoord vergeten</a>
          <a class="action" href="/register.php">Aanmelden</a>
        </div>
        <div class="footerhint">
          <span>© Porbeheer</span>
        </div>
      </form>

      <!-- knop om verificatiemail opnieuw te sturen bij fout 'nog niet bevestigd' -->
      <?php if($err === 'Je e‑mailadres is nog niet bevestigd.' && !empty($_SESSION['pending_verify_email'])): ?>
      <form method="post" style="margin-top: 10px;">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="resend_verification">
        <button type="submit" class="btn" style="background-color: var(--info); margin-top: 0;">Bevestigingsmail opnieuw verzenden</button>
      </form>
      <?php endif; ?>

      <?php else:?>
      <h2>Stap 2 van 2: 2FA‑code</h2>
      <div class="sub">Hallo, <?=h((string)($pendingUser['username']??''))?>!</div>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="totp">
        <label>6‑cijferige code uit je Authenticator‑app
          <input class="codeInput" name="totp_code" maxlength="6" required inputmode="numeric" placeholder="000 000" style="text-align:center;letter-spacing:4px;font-size:1.5rem;">
        </label>
        <button class="btn" type="submit">Log in</button>
        <div class="actions"><a class="action" href="/login.php?cancel2fa=1">Opnieuw beginnen</a></div>
      </form>
      <?php endif;?>
    </div>
  </div>
</div>

</body>
</html>