<?php
declare(strict_types=1);

require_once __DIR__ . '/cgi-bin/app/bootstrap.php';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (isLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || strlen($username) > 80 || strlen($password) > 200) {
        $err = 'Inloggen mislukt.';
    } else {
        $res = attemptLogin($pdo, $username, $password);

        if (!empty($res['ok'])) {
            auditLog($pdo, 'LOGIN_OK', 'auth/login', [
                'username' => $username,
                'user_id'  => $res['user_id'] ?? null
            ]);
            header('Location: /admin/dashboard.php');
            exit;
        }

        $code = (string)($res['code'] ?? '');
        if ($code === 'LOCKED' && !empty($res['locked_until'])) {
            $err = 'Account tijdelijk vergrendeld tot: ' . (string)$res['locked_until'];
        } else {
            $err = 'Inloggen mislukt.';
        }

        auditLog($pdo, 'LOGIN_FAIL', 'auth/login', [
            'username' => $username,
            'user_id'  => $res['user_id'] ?? null,
            'code'     => $code ?: null
        ]);
    }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Login</title>
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
  width:min(520px, 92vw);
}

.topActions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:12px;
}

.topAction{
  flex:1;
  display:flex;
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
  letter-spacing:.2px;
  box-shadow:0 10px 22px rgba(0,0,0,.18);
  transition: transform .12s ease, background .12s ease, border-color .12s ease, opacity .12s ease;
}
.topAction:hover{
  transform: translateY(-1px);
  background: rgba(255,255,255,.14);
  border-color: rgba(255,255,255,.35);
  opacity:.98;
}
.topAction.primary{
  background: rgba(124,255,178,.10);
  border-color: rgba(124,255,178,.28);
}
.topAction.primary:hover{
  background: rgba(124,255,178,.14);
  border-color: rgba(124,255,178,.42);
}

.icon{ width:18px; height:18px; display:inline-block; opacity:.95; }

.box{
  border-radius:20px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  padding:22px;
}

h1{ margin:0 0 6px 0; font-size:22px; letter-spacing:.2px; }
.sub{ color:var(--muted); font-size:13px; margin-bottom:14px; }

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
  transition: transform .12s ease, background .12s ease, border-color .12s ease, opacity .12s ease;
}
.btn:hover{
  transform: translateY(-1px);
  background: rgba(255,255,255,.16);
  border-color: rgba(255,255,255,.35);
  opacity:.98;
}

.msg{
  margin-top:10px;
  font-size:13px;
  padding:10px 12px;
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

.footerhint{
  margin-top:12px;
  font-size:12px;
  color: var(--muted);
  display:flex;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}

@media (max-width: 460px){
  .topAction{ flex: 1 0 100%; }
}
</style>
<link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="96x96" href="../assets/images/favicon-96x96.png">
<link rel="icon" type="image/png" sizes="16x16" href="../assets/images/favicon-16x16.png">
</head>
<body>
<div class="backdrop">
  <div class="shell">

    <!-- Buttons netjes boven de login-box -->
    <div class="topActions">
      <a class="topAction" href="/forgot.php" aria-label="Wachtwoord vergeten">
        <svg class="icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M7 10V8a5 5 0 0 1 10 0v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <path d="M7 10h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M12 14v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Wachtwoord vergeten
      </a>

      <a class="topAction primary" href="/register.php" aria-label="Aanmelden">
        <svg class="icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <path d="M19 8v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <path d="M16 11h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Aanmelden
      </a>
    </div>

    <div class="box">
      <h1>POP Oefenruimte Zevenaar beheer</h1>
      <div class="sub">Inloggen</div>
      <?php if (!empty($_GET['timeout'])): ?>
        <div class="msg err">Je sessie is verlopen door inactiviteit. Log opnieuw in.</div>
      <?php endif; ?>

      <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label>Gebruikersnaam
          <input name="username" required autocomplete="username" autofocus>
        </label>

        <label>Wachtwoord
          <input name="password" type="password" required autocomplete="current-password">
        </label>

        <button class="btn" type="submit">Inloggen</button>

        <div class="footerhint">
          <span>Tip: gebruik een sterk wachtwoord.</span>
          <span>© Porbeheer</span>
        </div>
      </form>
    </div>

  </div>
</div>
</body>
</html>