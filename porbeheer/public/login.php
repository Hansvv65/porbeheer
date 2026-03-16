<?php
declare(strict_types=1);

require_once __DIR__ . '/cgi-bin/app/bootstrap.php';

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? 'login');

    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || strlen($username) > 80 || strlen($password) > 200) {
            $err = 'Controleer je gebruikersnaam en wachtwoord.';
        } else {
            $res = attemptPrimaryLogin($pdo, $username, $password);
            $code = (string)($res['code'] ?? '');

            if (!empty($res['ok'])) {
                auditLog($pdo, 'LOGIN_PASSWORD_OK', 'auth/login', [
                    'username' => $username,
                    'user_id'  => $res['user_id'] ?? null,
                    'code'     => $code,
                ]);

                header('Location: /admin/setup-2fa.php?welcome=1');
                exit;
            }

            if ($code === 'NEEDS_2FA') {
                auditLog($pdo, 'LOGIN_2FA_REQUIRED', 'auth/login', [
                    'username' => $username,
                    'user_id'  => $res['user_id'] ?? null,
                ]);

                $info = 'Je wachtwoord is goed. Vul nu de 6-cijferige code uit je authenticator-app in.';
            } elseif ($code === 'PENDING_EMAIL') {
                $err = 'Je e-mailadres is nog niet bevestigd. Gebruik eerst de link uit de e-mail.';
            } elseif ($code === 'PENDING_APPROVAL') {
                $err = 'Je account is aangemaakt, maar wacht nog op goedkeuring door de beheerder.';
            } elseif ($code === 'BLOCKED') {
                $err = 'Je account is geblokkeerd. Neem contact op met de beheerder.';
            } elseif ($code === 'LOCKED' && !empty($res['locked_until'])) {
                $err = 'Je account is tijdelijk vergrendeld tot ' . (string)$res['locked_until'] . '.';
            } else {
                $err = 'Inloggen mislukt.';
            }

            auditLog($pdo, 'LOGIN_PASSWORD_FAIL', 'auth/login', [
                'username' => $username,
                'user_id'  => $res['user_id'] ?? null,
                'code'     => $code ?: null,
            ]);
        }
    }

    if ($action === 'totp') {
        $codeInput = trim((string)($_POST['totp_code'] ?? ''));
        $res = verifyPending2faCode($pdo, $codeInput);
        $code = (string)($res['code'] ?? '');

        if (!empty($res['ok'])) {
            auditLog($pdo, 'LOGIN_2FA_OK', 'auth/login', [
                'user_id' => $res['user_id'] ?? null,
            ]);

            header('Location: /admin/dashboard.php');
            exit;
        }

        if ($code === 'BAD_2FA_FORMAT') {
            $err = 'Vul precies 6 cijfers in.';
        } elseif ($code === 'BAD_2FA_CODE') {
            $err = 'De ingevoerde code klopt niet. Probeer het opnieuw.';
        } else {
            $err = 'De 2FA-controle kon niet worden afgerond. Log opnieuw in.';
            clearPending2fa();
        }

        auditLog($pdo, 'LOGIN_2FA_FAIL', 'auth/login', [
            'user_id' => $res['user_id'] ?? null,
            'code'    => $code ?: null,
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
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Inloggen</title>
<style>
:root{
  --text:#fff;
  --muted:rgba(255,255,255,.78);
  --border:rgba(255,255,255,.22);
  --glass:rgba(255,255,255,.12);
  --glass2:rgba(255,255,255,.06);
  --shadow:0 14px 40px rgba(0,0,0,.45);
  --err:#FF8DA1;
  --ok:#7CFFB2;
  --info:#9fd1ff;
  --warn:#ffd86b;
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
  background: radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));
}
.shell{
  width:min(980px, 96vw);
  display:grid;
  grid-template-columns: 1.05fr .95fr;
  gap:22px;
}
@media (max-width: 920px){
  .shell{ grid-template-columns: 1fr; }
}
.card{
  border-radius:20px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  padding:22px;
}
h1{ margin:0 0 6px 0; font-size:28px; }
h2{ margin:0 0 10px 0; font-size:22px; }
.sub{ color:var(--muted); font-size:14px; margin-bottom:14px; }
label{ display:block; margin-top:12px; font-weight:800; }
input{
  width:100%;
  padding:11px 12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.18);
  outline:none;
  margin-top:6px;
  background:rgba(0,0,0,.22);
  color:#fff;
  box-sizing:border-box;
}
input:focus{
  border-color: rgba(255,255,255,.35);
  box-shadow: 0 0 0 3px rgba(255,255,255,.10);
}
.btn{
  margin-top:16px;
  width:100%;
  padding:12px 14px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));
  color:#fff;
  font-weight:900;
  cursor:pointer;
  box-shadow:0 10px 22px rgba(0,0,0,.18);
}
.btn:hover{ opacity:.96; }
.actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:14px;
}
.action{
  flex:1;
  min-width:180px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.22);
  background:rgba(0,0,0,.18);
  color:#fff;
  text-decoration:none;
  font-weight:900;
}
.action.primary{
  background: rgba(124,255,178,.10);
  border-color: rgba(124,255,178,.28);
}
.msg{
  margin-top:10px;
  font-size:14px;
  padding:11px 12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.08);
}
.msg.err{
  border-color: rgba(255,141,161,.35);
  background: rgba(255,141,161,.10);
  color: var(--err);
  font-weight:900;
}
.msg.ok{
  border-color: rgba(124,255,178,.35);
  background: rgba(124,255,178,.10);
  color: var(--ok);
  font-weight:900;
}
.msg.info{
  border-color: rgba(159,209,255,.35);
  background: rgba(159,209,255,.10);
  color: var(--info);
  font-weight:900;
}
.flow{
  display:grid;
  gap:12px;
}
.step{
  display:grid;
  grid-template-columns:48px 1fr;
  gap:12px;
  align-items:start;
  padding:12px;
  border-radius:16px;
  background:rgba(0,0,0,.16);
  border:1px solid rgba(255,255,255,.12);
}
.num{
  width:48px;height:48px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;
  font-weight:900;font-size:18px;
  background:rgba(255,255,255,.12);
}
.step strong{ display:block; margin-bottom:4px; }
.tiplist{
  display:grid;
  gap:12px;
  margin-top:14px;
}
.tip{
  padding:14px;
  border-radius:16px;
  background:rgba(0,0,0,.16);
  border:1px solid rgba(255,255,255,.12);
}
.phone{
  width:100%;
  max-width:260px;
  margin:4px auto 16px;
  display:block;
}
.small{ font-size:13px; color:var(--muted); }
.footerhint{
  margin-top:12px;
  font-size:12px;
  color: var(--muted);
  display:flex;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}
.codeInput{
  font-size:26px;
  text-align:center;
  letter-spacing:.35em;
  font-weight:900;
}
</style>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon-96x96.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
</head>
<body>
<div class="backdrop">
  <div class="shell">

    <div class="card">
      <h1>POP Oefenruimte Zevenaar beheer</h1>
      <div class="sub">Veilig inloggen met wachtwoord en 2FA</div>

      <?php if (!empty($_GET['timeout'])): ?>
        <div class="msg err">Je sessie is verlopen door inactiviteit. Log opnieuw in.</div>
      <?php endif; ?>

      <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>
      <?php if ($info): ?><div class="msg info"><?= h($info) ?></div><?php endif; ?>

      <?php if (!$pending2fa): ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="login">

          <label>Gebruikersnaam
            <input name="username" required autocomplete="username" autofocus value="<?= h($username) ?>">
          </label>

          <label>Wachtwoord
            <input name="password" type="password" required autocomplete="current-password">
          </label>

          <button class="btn" type="submit">Inloggen</button>

          <div class="actions">
            <a class="action" href="/forgot.php">Wachtwoord vergeten</a>
            <a class="action primary" href="/register.php">Aanmelden</a>
          </div>

          <div class="footerhint">
            <span>Na je eerste login stel je een authenticator-app in.</span>
            <span>© Porbeheer</span>
          </div>
        </form>
      <?php else: ?>
        <h2>Stap 2 van 2: 2FA-code</h2>
        <div class="sub">
          Account:
          <strong><?= h((string)($pendingUser['username'] ?? '')) ?></strong>
        </div>

        <svg class="phone" viewBox="0 0 260 240" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <rect x="70" y="10" width="120" height="220" rx="20" fill="rgba(255,255,255,.10)" stroke="rgba(255,255,255,.25)"/>
          <rect x="88" y="38" width="84" height="84" rx="10" fill="rgba(255,255,255,.16)" stroke="rgba(255,255,255,.25)"/>
          <rect x="98" y="48" width="18" height="18" fill="white"/>
          <rect x="121" y="48" width="18" height="18" fill="white"/>
          <rect x="144" y="48" width="18" height="18" fill="white"/>
          <rect x="98" y="71" width="18" height="18" fill="white"/>
          <rect x="121" y="71" width="18" height="18" fill="white"/>
          <rect x="144" y="71" width="18" height="18" fill="white"/>
          <rect x="98" y="94" width="18" height="18" fill="white"/>
          <rect x="121" y="94" width="18" height="18" fill="white"/>
          <rect x="144" y="94" width="18" height="18" fill="white"/>
          <rect x="95" y="142" width="70" height="18" rx="9" fill="rgba(255,255,255,.18)"/>
          <rect x="95" y="170" width="52" height="18" rx="9" fill="rgba(255,255,255,.18)"/>
        </svg>

        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="totp">

          <label>6-cijferige code uit je app
            <input
              class="codeInput"
              name="totp_code"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              placeholder="123456"
              required
            >
          </label>

          <button class="btn" type="submit">Code controleren</button>

          <div class="actions">
            <a class="action" href="/login.php?cancel2fa=1">Opnieuw beginnen</a>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Zo werkt het</h2>
      <div class="flow">
        <div class="step">
          <div class="num">1</div>
          <div>
            <strong>Aanmelden</strong>
            Vraag een account aan via de knop <em>Aanmelden</em>.
          </div>
        </div>
        <div class="step">
          <div class="num">2</div>
          <div>
            <strong>E-mail bevestigen</strong>
            Klik op de link in de bevestigingsmail.
          </div>
        </div>
        <div class="step">
          <div class="num">3</div>
          <div>
            <strong>Goedkeuring door beheer</strong>
            Daarna zet de beheerder je account actief.
          </div>
        </div>
        <div class="step">
          <div class="num">4</div>
          <div>
            <strong>Eerste login</strong>
            Log in met gebruikersnaam en wachtwoord.
          </div>
        </div>
        <div class="step">
          <div class="num">5</div>
          <div>
            <strong>2FA instellen</strong>
            Je koppelt een authenticator-app op je telefoon.
          </div>
        </div>
      </div>

      <div class="tiplist">
        <div class="tip">
          <strong>Welke app mag ik gebruiken?</strong>
          <div class="small">Bijvoorbeeld Google Authenticator, Microsoft Authenticator of 2FAS.</div>
        </div>
        <div class="tip">
          <strong>Wat moet ik invullen?</strong>
          <div class="small">Altijd de actuele 6 cijfers uit de app. Die vernieuwen vanzelf.</div>
        </div>
        <div class="tip">
          <strong>Voor digibeten</strong>
          <div class="small">Je hoeft niets over te typen bij het instellen als je de QR-code scant. Daarna tik je alleen nog de 6 cijfers over.</div>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>