<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireLogin();

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$userId = (int)($user['id'] ?? 0);

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = null;
$csrf = csrfToken();

$allowedThemes = ['a', 'b', 'c'];

$st = $pdo->prepare("SELECT id, username, email, password_hash, theme_variant FROM users WHERE id = ? LIMIT 1");
$st->execute([$userId]);
$dbUser = $st->fetch(PDO::FETCH_ASSOC);

if (!$dbUser) {
    http_response_code(403);
    exit('Gebruiker niet gevonden.');
}

$currentTheme = normalizeThemeVariant((string)($dbUser['theme_variant'] ?? 'a'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword     = (string)($_POST['new_password'] ?? '');
        $newPassword2    = (string)($_POST['new_password_2'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $newPassword2 === '') {
            $errors[] = 'Vul alle wachtwoordvelden in.';
        } elseif (!password_verify($currentPassword, (string)$dbUser['password_hash'])) {
            $errors[] = 'Huidig wachtwoord is onjuist.';
        } elseif (mb_strlen($newPassword) < 10) {
            $errors[] = 'Nieuw wachtwoord moet minimaal 10 tekens hebben.';
        } elseif ($newPassword !== $newPassword2) {
            $errors[] = 'Nieuwe wachtwoorden komen niet overeen.';
        } elseif (password_verify($newPassword, (string)$dbUser['password_hash'])) {
            $errors[] = 'Nieuw wachtwoord mag niet gelijk zijn aan het huidige wachtwoord.';
        } else {
            try {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $pdo->prepare("
                    UPDATE users
                    SET password_hash = ?, failed_attempts = 0, locked_until = NULL
                    WHERE id = ?
                ")->execute([$newHash, $userId]);

                $success = 'Wachtwoord gewijzigd.';
                auditLog($pdo, 'ACCOUNT_PASSWORD_CHANGE', 'admin/account.php', ['id' => $userId]);
            } catch (Throwable $e) {
                $errors[] = 'Wachtwoord wijzigen mislukt.';
                auditLog($pdo, 'ACCOUNT_PASSWORD_CHANGE_FAIL', 'admin/account.php', ['id' => $userId]);
            }
        }
    }

    if ($action === 'change_theme') {
        $themeVariant = normalizeThemeVariant((string)($_POST['theme_variant'] ?? 'a'));

        if (!in_array($themeVariant, $allowedThemes, true)) {
            $errors[] = 'Ongeldig thema.';
        } else {
            try {
                $pdo->prepare("UPDATE users SET theme_variant = ? WHERE id = ?")
                    ->execute([$themeVariant, $userId]);

                $_SESSION['user']['theme_variant'] = $themeVariant;
                $currentTheme = $themeVariant;

                $success = 'Thema opgeslagen.';
                auditLog($pdo, 'ACCOUNT_THEME_CHANGE', 'admin/account.php', [
                    'id' => $userId,
                    'theme_variant' => $themeVariant
                ]);
            } catch (Throwable $e) {
                $errors[] = 'Thema opslaan mislukt.';
                auditLog($pdo, 'ACCOUNT_THEME_CHANGE_FAIL', 'admin/account.php', ['id' => $userId]);
            }
        }
    }
}

auditLog($pdo, 'PAGE_VIEW', 'admin/account.php');
$bg = themeImage('admin', $pdo);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Mijn account</title>
<style>
  :root{
    --text:#fff; --muted:rgba(255,255,255,.78);
    --border:rgba(255,255,255,.22);
    --glass:rgba(255,255,255,.12); --glass2:rgba(255,255,255,.06);
    --shadow:0 14px 40px rgba(0,0,0,.45);
    --ok:#7CFFB2; --err:#FF8DA1; --accent:#ffd86b;
  }
  body{
    margin:0; font-family:Arial,sans-serif; color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;
    background-size:cover;
  }
  .backdrop{
    min-height:100vh;
    background:
      radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
      linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
    padding:26px; box-sizing:border-box;
    display:flex; justify-content:center;
  }
  .wrap{ width:min(1100px, 96vw); }
  .topbar{
    display:flex; align-items:flex-end; justify-content:space-between;
    gap:16px; flex-wrap:wrap; margin-bottom:14px;
  }
  .brand h1{ margin:0; font-size:28px; letter-spacing:.5px; }
  .brand .sub{ margin-top:6px; color:var(--muted); font-size:14px; }
  .userbox{
    background:var(--glass);
    border:1px solid var(--border);
    border-radius:14px;
    padding:12px 14px;
    box-shadow:var(--shadow);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    min-width:260px;
  }
  .userbox .line1{font-weight:bold}
  .userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
  a{ color:#fff; text-decoration:none; }
  a:visited{ color:var(--accent); }

  .panel{
    margin-top:10px;
    border-radius:20px;
    border:1px solid rgba(255,255,255,.18);
    background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
    box-shadow:var(--shadow);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    padding:18px;
  }

  .msg{
    padding:12px 14px;
    border-radius:12px;
    margin-bottom:12px;
    font-weight:700;
  }
  .msg.ok{ background:rgba(124,255,178,.12); border:1px solid rgba(124,255,178,.35); color:var(--ok); }
  .msg.err{ background:rgba(255,141,161,.10); border:1px solid rgba(255,141,161,.35); color:#fff; }

  .grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:18px;
  }
  @media (max-width: 900px){
    .grid{ grid-template-columns:1fr; }
  }

  label{ display:block; margin-top:10px; font-weight:800; }
  input[type="password"]{
    width:100%;
    padding:10px 12px;
    margin-top:6px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(255,255,255,.10);
    color:#fff;
    box-sizing:border-box;
    outline:none;
  }

  .btn{
    display:inline-block;
    margin-top:14px;
    padding:10px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(255,255,255,.14);
    color:#fff;
    font-weight:900;
    cursor:pointer;
  }

  .themes{
    display:grid;
    grid-template-columns: repeat(3, 1fr);
    gap:14px;
    margin-top:10px;
  }
  @media (max-width: 900px){
    .themes{ grid-template-columns:1fr; }
  }
  .theme-card{
    border:1px solid rgba(255,255,255,.18);
    border-radius:16px;
    padding:12px;
    background:rgba(0,0,0,.16);
  }
  .theme-card.active{
    border-color:rgba(124,255,178,.45);
    box-shadow:0 0 0 2px rgba(124,255,178,.18) inset;
  }
  .theme-card img{
    width:100%;
    display:block;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.14);
    margin-bottom:10px;
  }
  .theme-card .row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
  }
  a{color:#fff;text-decoration:none;transition:color .15s ease}
  a:hover{color:#ffd9b3}
  a:visited{color:#ffe0c2}

</style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div class="brand">
        <h1>Porbeheer</h1>
        <div class="sub">Mijn account</div>
      </div>

      <div class="userbox">
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2 style="margin-top:0;">Mijn instellingen</h2>

      <?php if ($success): ?>
        <div class="msg ok"><?= h($success) ?></div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="msg err">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="grid">
        <div>
          <h3>Wachtwoord wijzigen</h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="change_password">

            <label for="current_password">Huidig wachtwoord</label>
            <input type="password" id="current_password" name="current_password" required>

            <label for="new_password">Nieuw wachtwoord</label>
            <input type="password" id="new_password" name="new_password" required>

            <label for="new_password_2">Herhaal nieuw wachtwoord</label>
            <input type="password" id="new_password_2" name="new_password_2" required>

            <button class="btn" type="submit">Wachtwoord opslaan</button>
          </form>
        </div>

        <div>
          <h3>Thema kiezen</h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="change_theme">

            <div class="themes">
              <?php foreach ($allowedThemes as $variant): ?>
                <label class="theme-card <?= $currentTheme === $variant ? 'active' : '' ?>">
                  <img src="/assets/images/overzicht-<?= h($variant) ?>.png" alt="Thema <?= strtoupper($variant) ?>">
                  <div class="row">
                    <div>Thema <?= strtoupper($variant) ?></div>
                    <div>
                      <input type="radio" name="theme_variant" value="<?= h($variant) ?>" <?= $currentTheme === $variant ? 'checked' : '' ?>>
                    </div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>

            <button class="btn" type="submit">Thema opslaan</button>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>
</body>
</html>