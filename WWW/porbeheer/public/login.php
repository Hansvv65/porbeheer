<?php
declare(strict_types=1);
require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../libs/porbeheer/app/mail.php';
require_once __DIR__ . '/../../libs/porbeheer/app/autoload.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Bepaal het werkelijke IP-adres van de client (rekening houdend met proxies)
 */
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
$username = '';
$ip = getClientIp();          // IP-adres bepalen voor de hele sessie
$timestamp = date('Y-m-d H:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? 'login');

    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $logResult = '';
        $userId = null;

        // Validatie van invoer
        if ($username === '' || strlen($username) > 80 || strlen($password) > 200) {
            $err = 'Controleer je gebruikersnaam en wachtwoord.';
            $logResult = 'validation_failed';
            // Log de mislukte poging wegens validatiefout
            auditLog($pdo, 'LOGIN_ATTEMPT', 'auth/login', [
                'username'   => $username ?: '(leeg)',
                'ip'         => $ip,
                'timestamp'  => $timestamp,
                'result'     => $logResult,
                'reason'     => $err
            ]);
        } else {
            $res = attemptPrimaryLogin($pdo, $username, $password);
            $code = (string)($res['code'] ?? '');
            $userId = $res['user_id'] ?? null;

            if (!empty($res['ok'])) {
                // Succesvolle wachtwoordcontrole
                $logResult = 'success_needs_2fa';
                auditLog($pdo, 'LOGIN_ATTEMPT', 'auth/login', [
                    'username'   => $username,
                    'user_id'    => $userId,
                    'ip'         => $ip,
                    'timestamp'  => $timestamp,
                    'result'     => $logResult
                ]);
                header('Location: /admin/setup-2fa.php?welcome=1');
                exit;
            }

            // Bepaal het resultaat voor de auditlog op basis van de foutcode
            switch ($code) {
                case 'NEEDS_2FA':
                    $info = 'Je wachtwoord is goed. Vul nu de 6‑cijferige code uit je authenticator‑app in.';
                    $logResult = 'password_ok_needs_2fa';
                    break;
                case 'PENDING_EMAIL':
                    $err = 'Je e-mailadres is nog niet bevestigd.';
                    $logResult = 'pending_email';
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

            // Log elke mislukte poging (inclusief de "NEEDS_2FA" telt als mislukt? Nee, die is deels succesvol, maar we loggen als apart resultaat)
            auditLog($pdo, 'LOGIN_ATTEMPT', 'auth/login', [
                'username'   => $username,
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
            // 2FA geslaagd – optioneel ook loggen (niet vereist volgens opdracht, maar voor compleetheid)
            auditLog($pdo, 'LOGIN_2FA_OK', 'auth/login', [
              'username' => $username,
              'ip'       => $ip,
              'user_id'  => $res['user_id'] ?? null,
              'timestamp'=> $timestamp
            ]);
            header('Location: /admin/dashboard.php');
            exit;
        }
        $err = 'De ingevoerde code klopt niet. Probeer het opnieuw.';
        // Optioneel: mislukte 2FA poging loggen
        auditLog($pdo, 'LOGIN_2FA_FAILED', 'auth/login', [
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
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);
--glass:rgba(255,255,255,.14);--glass2:rgba(255,255,255,.08);--shadow:0 20px 50px rgba(0,0,0,.5);
--err:#FF8DA1;--ok:#7CFFB2;--info:#bf721f;--warn:#ffd86b;}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);
background:url('/assets/images/loginbg.png') no-repeat center center fixed;background-size:cover;}
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
  background:rgba(0,0,0,.35);border:1px solid var(--border);
  border-radius:50%;width:38px;height:38px;
  color:#fff;font-weight:900;font-size:18px;
  cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.4);
}
.helpbtn:hover{background:rgba(255,255,255,.1);}
.helpoverlay{
  display:none;position:fixed;inset:0;z-index:100;
  backdrop-filter:blur(8px);background:rgba(0,0,0,.8);
  align-items:center;justify-content:center;
}
.helpoverlay.active{display:flex;}
.helpcard{
  width:min(480px,90vw);border-radius:20px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg,var(--glass),var(--glass2));
  padding:24px;text-align:center;box-shadow:var(--shadow);
}
.helpcard h3{margin-top:0;margin-bottom:12px;color:var(--info);}
.helpcard p{text-align:left;line-height:1.5;margin:10px 0;}
.helpcard ul{text-align:left;padding-left:20px;margin:15px 0;}
.helpcard li{margin-bottom:8px;color:var(--muted);}
.helpcard h3{margin-top:0;margin-bottom:16px;color:var(--info);font-size:1.4rem;}
.helpcard p{text-align:left;line-height:1.6;margin:12px 0;font-size:0.95rem;}
.helpcard ul{text-align:left;padding-left:22px;margin:18px 0;list-style-type: none;}
.helpcard li{margin-bottom:12px;color:var(--muted);position:relative;line-height:1.4;}
.helpcard li::before{content:"→";position:absolute;left:-20px;color:var(--info);font-weight:bold;}
.helpcard svg{width:50px;height:50px;margin:0 auto 15px;display:block;}
.helpcard button{margin-top:18px;padding:10px 16px;
  border:1px solid var(--border);border-radius:10px;background:rgba(255,255,255,.1);
  color:#fff;font-weight:900;cursor:pointer;}
.msg{padding:12px;border-radius:10px;margin-bottom:15px;font-size:0.9rem;border:1px solid rgba(255,255,255,0.15);}
.msg.err{background:rgba(255,107,129,0.15);color:var(--err);border-color:var(--err);}
.msg.info{background:rgba(255,179,71,0.15);color:var(--info);border-color:var(--info);}
</style>
</head>
<body>
<div class="backdrop">
  <div class="shell">
    <div class="card">
      <button type="button" class="helpbtn" id="helpbtn">?</button>

      <h1>POP Zevenaar</h1>
      <div class="sub">Veilig inloggen met wachtwoord en 2FA</div>

      <?php if($err):?><div class="msg err"><?=h($err)?></div><?php endif;?>
      <?php if($info):?><div class="msg info"><?=h($info)?></div><?php endif;?>

      <?php if(!$pending2fa):?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="login">
        <label>Gebruikersnaam
          <input name="username" required autocomplete="username" value="<?=h($username)?>" placeholder="e.g. jansen01">
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
      <?php else:?>
      <h2>Stap 2 van 2: 2FA‑code</h2>
      <div class="sub">Hallo, <?=h((string)($pendingUser['username']??''))?>!</div>
      <!-- bestaande telefoon‑SVG behouden -->
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

<!-- help-overlay -->
<div class="helpoverlay" id="helpbox">
  <div class="helpcard">
    <h3>Zo werkt het inloggen</h3>
    <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect x="16" y="8" width="32" height="48" rx="6" stroke="var(--info)" stroke-width="3" fill="none"/>
      <circle cx="32" cy="52" r="2" fill="var(--info)"/>
      <path d="M24 20h16v12H24z" stroke="var(--info)" stroke-width="2" fill="rgba(255,157,71,.15)"/>
    </svg>
    <p>Om de veiligheid te waarborgen gebruiken we Twee-Factor Authenticatie (2FA).</p>
    <ul>
      <li><strong>Stap 1:</strong> Log in met je gebruikersnaam en wachtwoord.</li>
      <li><strong>Stap 2:</strong> Open je Authenticator app (Google, Microsoft of 2FAS).</li>
      <li><strong>Stap 3:</strong> Typ de 6-cijferige code over in het portaal.</li>
      <li><strong>Nieuw?</strong> Na je eerste login word je begeleid bij het instellen van de app.</li>
    </ul>
    <p><small style="color:var(--warn)">Lukt het niet? Neem contact op met de beheerder voor een reset van je 2FA instellingen.</small></p>
    <button type="button" id="helpclose">Sluiten</button>
  </div>
</div>

<script>
const hb=document.getElementById('helpbtn');
const box=document.getElementById('helpbox');
const close=document.getElementById('helpclose');
hb?.addEventListener('click',()=>box.classList.add('active'));
close?.addEventListener('click',()=>box.classList.remove('active'));
box?.addEventListener('click',e=>{if(e.target===box)box.classList.remove('active');});
</script>
</body>
</html>