<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('contacts', $pdo);


function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$success = null;

$allowedRoles = ['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER'];
$priority = ['GEBRUIKER'=>1,'FINANCIEEL'=>2,'BEHEER'=>3,'ADMIN'=>4];

function primaryRoleFrom(array $roles, array $priority): string {
  $p = 'GEBRUIKER';
  foreach ($roles as $r) if (($priority[$r] ?? 0) > ($priority[$p] ?? 0)) $p = $r;
  return $p;
}

$csrf = csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrf($_POST['csrf'] ?? '');

  $username = trim((string)($_POST['username'] ?? ''));
  $email    = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  $roles = $_POST['roles'] ?? [];
  if (!is_array($roles)) $roles = [];
  $roles = array_values(array_unique(array_filter($roles, fn($r) => in_array($r, $allowedRoles, true))));

  if ($username === '' || mb_strlen($username) < 3) $errors[] = 'Gebruikersnaam minimaal 3 tekens.';
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ongeldig e-mailadres.';
  if ($password === '' || mb_strlen($password) < 10) $errors[] = 'Wachtwoord minimaal 10 tekens.';
  if (!$roles) $errors[] = 'Kies minimaal 1 rol.';

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $primary = primaryRoleFrom($roles, $priority);

      $stmt = $pdo->prepare("
      INSERT INTO users (username, email, password_hash, role, status, active, created_at, approved_at, email_verified_at, updated_at)
      VALUES (?, ?, ?, ?, 'ACTIVE', 1, NOW(), NOW(), NOW(), NOW())
    ");

      $stmt->execute([$username, $email, $hash, $primary]);

      $userId = (int)$pdo->lastInsertId();

      $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, ?)");
      foreach ($roles as $r) $ins->execute([$userId, $r]);

      $pdo->commit();

      auditLog($pdo, 'USER_CREATE', 'admin/user_create.php', [
        'id'=>$userId,
        'username'=>$username,
        'email'=>$email,
        'primary'=>$primary,
        'roles'=>implode(',', $roles)
      ]);

      // PRG: redirect voorkomt dubbel aanmaken bij refresh
      header('Location: /admin/users_detail.php?id=' . $userId . '&created=1');
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Aanmaken mislukt (bestaat username/e-mail al?)';
      auditLog($pdo, 'USER_CREATE_FAIL', 'admin/user_create.php', [
        'username'=>$username,
        'email'=>$email
      ]);
    }
  }
}

auditLog($pdo, 'PAGE_VIEW', 'admin/user_create.php');

?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Nieuwe gebruiker</title>
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
  .wrap{ width:min(980px, 96vw); }

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

  a{ color:#fff; }
  a:visited{ color:var(--accent); }
  a:hover{ opacity:.95; }

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

  label{ display:block; margin-top:10px; font-weight:800; }
  input[type="text"], input[type="password"]{
    width:100%;
    padding:10px 12px;
    margin-top:6px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(0,0,0,.25);
    color:#fff;
    outline:none;
  }
  input:focus{ border-color:rgba(255,255,255,.38); box-shadow:0 0 0 3px rgba(255,255,255,.10); }

  .roles{
    margin-top:10px;
    display:flex; flex-wrap:wrap; gap:10px;
    padding:10px 12px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(0,0,0,.14);
    border-radius:14px;
  }
  .roleitem{ display:flex; align-items:center; gap:8px; font-weight:800; font-size:13px; }
  .roleitem input{ transform:scale(1.12); }

  .row{
    display:flex; gap:12px; align-items:center; justify-content:space-between;
    margin-top:14px; flex-wrap:wrap;
  }
  .btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(255,255,255,.14);
    color:#fff;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 10px 22px rgba(0,0,0,.20);
    transition:transform .12s ease, background .12s ease, border-color .12s ease;
    text-decoration:none;
  }
  .btn:hover{ transform:translateY(-1px); background:rgba(255,255,255,.18); border-color:rgba(255,255,255,.35); }
  .btn.ok{ border-color:rgba(124,255,178,.35); background:rgba(124,255,178,.10); }

  .msg-ok{
    margin:0 0 10px 0; padding:10px 12px; border-radius:12px;
    border:1px solid rgba(124,255,178,.35);
    background:rgba(124,255,178,.12);
    color:var(--ok); font-weight:800;
  }
  .msg-err{
    margin:0 0 10px 0; padding:10px 12px; border-radius:12px;
    border:1px solid rgba(255,141,161,.35);
    background:rgba(255,141,161,.12);
    color:var(--err); font-weight:800;
  }
  .muted{ color:var(--muted); font-size:13px; margin-top:6px; }
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
        <div class="sub">POP Oefenruimte Zevenaar • admin</div>
      </div>
      <div class="userbox">
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a> •
          <a href="/admin/beheer.php">Beheer</a> •
          <a href="/admin/users.php">Users</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2 style="margin:0 0 8px 0;">Nieuwe gebruiker</h2>
      <div class="muted">Meerdere rollen mogelijk. De hoogste rol wordt automatisch als “primair” gezet.</div>

      <div style="margin-top:12px">
        <?php if ($success): ?><div class="msg-ok"><?= h($success) ?></div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="msg-err"><?= h($e) ?></div><?php endforeach; ?>
      </div>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label>Gebruikersnaam
          <input type="text" name="username" required minlength="3" autocomplete="off" value="<?= h((string)($_POST['username'] ?? '')) ?>">
        </label>

        <label>E-mail
          <input type="text" name="email" required autocomplete="email" value="<?= h((string)($_POST['email'] ?? '')) ?>">
        </label>

        <label>Wachtwoord
          <input type="password" name="password" required minlength="10" autocomplete="new-password">
        </label>

        <label>Rollen</label>
        <div class="roles">
          <?php
            $postedRoles = $_POST['roles'] ?? ['GEBRUIKER'];
            if (!is_array($postedRoles)) $postedRoles = ['GEBRUIKER'];
          ?>
          <?php foreach ($allowedRoles as $r): ?>
            <label class="roleitem">
              <input type="checkbox" name="roles[]" value="<?= h($r) ?>" <?= in_array($r, $postedRoles, true) ? 'checked' : '' ?>>
              <?= h($r) ?>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="row">
          <a class="btn" href="/admin/users.php">← Terug</a>
          <button class="btn ok" type="submit">Aanmaken</button>
        </div>
      </form>
    </div>

  </div>
</div>
</body>
</html>