<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/mail.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('contacts', $pdo);

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$success = null;

$allowedRoles = ['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER','BESTUURSLID'];
$priority = ['GEBRUIKER'=>1,'FINANCIEEL'=>2,'BEHEER'=>3,'BESTUURSLID'=>4,'ADMIN'=>5];
$titleOptions = ['', 'Dhr.', 'Mevr.', 'Dr.', 'Prof.'];

function primaryRoleFrom(array $roles, array $priority): string {
  $p = 'GEBRUIKER';
  foreach ($roles as $r) if (($priority[$r] ?? 0) > ($priority[$p] ?? 0)) $p = $r;
  return $p;
}

function generateUniqueUsername(PDO $pdo, string $email): string {
    // Gebruik het deel vóór de @ als basis
    $base = explode('@', $email)[0];
    // Alleen alfanumeriek en underscore, maximaal 50 tekens
    $base = preg_replace('/[^a-zA-Z0-9_]/', '_', $base);
    $base = substr($base, 0, 45);
    
    $username = $base;
    $counter = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) {
            return $username;
        }
        $username = $base . '_' . $counter;
        $counter++;
        if (strlen($username) > 50) {
            // Uiterste valt terug op unieke hash
            $username = substr($base, 0, 30) . '_' . bin2hex(random_bytes(8));
        }
    }
}

$csrf = csrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrf($_POST['csrf'] ?? '');

  $email    = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  // Nieuwe profielvelden
  $title        = trim((string)($_POST['title'] ?? ''));
  $first_name   = trim((string)($_POST['first_name'] ?? ''));
  $tussenvoegsel= trim((string)($_POST['tussenvoegsel'] ?? ''));
  $last_name    = trim((string)($_POST['last_name'] ?? ''));
  $phone        = trim((string)($_POST['phone'] ?? ''));

  $roles = $_POST['roles'] ?? [];
  if (!is_array($roles)) $roles = [];
  $roles = array_values(array_unique(array_filter($roles, fn($r) => in_array($r, $allowedRoles, true))));

  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ongeldig e-mailadres.';
  if ($password === '' || mb_strlen($password) < 10) $errors[] = 'Wachtwoord minimaal 10 tekens.';
  if (!$roles) $errors[] = 'Kies minimaal 1 rol.';

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // Genereer een unieke gebruikersnaam op basis van e-mail
      $username = generateUniqueUsername($pdo, $email);
      
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $primary = primaryRoleFrom($roles, $priority);

      // Genereer verificatietoken
      $verificationToken = bin2hex(random_bytes(32));
      $verificationExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
      $hashedToken = password_hash($verificationToken, PASSWORD_DEFAULT);

      $stmt = $pdo->prepare("
        INSERT INTO users 
        (username, email, password_hash, role, status, active, created_at, approved_at, 
         title, first_name, tussenvoegsel, last_name, phone,
         verification_token, verification_expires, email_verified_at)
        VALUES (?, ?, ?, ?, 'ACTIVE', 1, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, NULL)
      ");
      $stmt->execute([
        $username, $email, $hash, $primary,
        $title ?: null, $first_name ?: null, $tussenvoegsel ?: null, $last_name ?: null, $phone ?: null,
        $hashedToken, $verificationExpires
      ]);

      $userId = (int)$pdo->lastInsertId();

      $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, ?)");
      foreach ($roles as $r) $ins->execute([$userId, $r]);

      $pdo->commit();

      // Verstuur verificatiemail (gebruik voornaam of e-mail als aanhef)
      $greeting = $first_name ?: $email;
      $verifyLink = appUrl("/verify.php?email=" . urlencode($email) . "&token=" . $verificationToken);
      $html = "
        <div style='font-family:Arial; max-width:600px;'>
          <h2>Welkom bij Porbeheer</h2>
          <p>Beste " . h($greeting) . ",</p>
          <p>Je account is aangemaakt. Klik op onderstaande link om je e‑mailadres te bevestigen:</p>
          <p><a href='$verifyLink' style='display:inline-block;padding:10px 20px; background:#bf721f; color:#fff; text-decoration:none; border-radius:5px;'>Bevestig e‑mail</a></p>
          <p>Of kopieer: $verifyLink</p>
          <p>De link is 24 uur geldig.</p>
          <p>Met vriendelijke groet,<br>Porbeheer</p>
        </div>";
      sendEmail($email, 'Bevestig je e‑mailadres voor Porbeheer', $html);

      auditLog($pdo, 'USER_CREATE', 'admin/user_create.php', [
        'id'=>$userId, 'username'=>$username, 'email'=>$email, 'primary'=>$primary, 'roles'=>implode(',', $roles)
      ]);

      header('Location: /admin/users_detail.php?id=' . $userId . '&created=1');
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Aanmaken mislukt (e-mailadres mogelijk al in gebruik).';
      auditLog($pdo, 'USER_CREATE_FAIL', 'admin/user_create.php', [
        'email'=>$email, 'error'=>$e->getMessage()
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
    --glass:rgba(255,255,255,.12);
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
  .brand h1{ margin:0; font-size:28px; }
  .brand .sub{ margin-top:6px; color:var(--muted); font-size:14px; }
  .userbox{
    background:var(--glass);
    border:1px solid var(--border);
    border-radius:14px;
    padding:12px 14px;
    box-shadow:var(--shadow);
    backdrop-filter:blur(10px);
    min-width:260px;
  }
  .userbox .line1{font-weight:bold}
  .userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
  a{ color:#fff; text-decoration:none; }
  a:visited{ color:var(--accent); }
  a:hover{ opacity:.95; }

  .panel{
    margin-top:10px;
    border-radius:20px;
    border:1px solid rgba(255,255,255,.18);
    background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
    box-shadow:var(--shadow);
    backdrop-filter:blur(12px);
    padding:18px;
  }

  label{ display:block; margin-top:10px; font-weight:800; }
  input[type="text"], input[type="password"], input[type="email"], select{
    width:100%;
    padding:10px 12px;
    margin-top:6px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(0,0,0,.25);
    color:#fff;
    outline:none;
    box-sizing:border-box;
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
    transition:transform .12s ease, background .12s ease;
    text-decoration:none;
  }
  .btn:hover{ transform:translateY(-1px); background:rgba(255,255,255,.18); }
  .btn.ok{ border-color:rgba(124,255,178,.35); background:rgba(124,255,178,.10); }

  .msg-err{
    margin:0 0 10px 0; padding:10px 12px; border-radius:12px;
    border:1px solid rgba(255,141,161,.35);
    background:rgba(255,141,161,.12);
    color:var(--err); font-weight:800;
  }
  .muted{ color:var(--muted); font-size:13px; margin-top:6px; }
  .grid-2{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:12px;
  }
  @media (max-width: 700px){ .grid-2{ grid-template-columns:1fr; } }
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
      <div class="muted">Meerdere rollen mogelijk. De hoogste rol wordt automatisch als primair gezet. Na aanmaken wordt een verificatiemail verstuurd.</div>

      <?php foreach ($errors as $e): ?><div class="msg-err"><?= h($e) ?></div><?php endforeach; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <div class="grid-2">
          <div>
            <label>E‑mailadres *
              <input type="email" name="email" required autocomplete="email" value="<?= h((string)($_POST['email'] ?? '')) ?>">
            </label>
            <label>Wachtwoord *
              <input type="password" name="password" required minlength="10" autocomplete="new-password">
            </label>
          </div>
          <div>
            <label>Aanspreektitel (optioneel)
              <select name="title">
                <?php foreach ($titleOptions as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= (($_POST['title'] ?? '') === $opt) ? 'selected' : '' ?>><?= $opt === '' ? '(geen)' : h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Voornaam
              <input type="text" name="first_name" value="<?= h((string)($_POST['first_name'] ?? '')) ?>">
            </label>
            <label>Tussenvoegsel
              <input type="text" name="tussenvoegsel" value="<?= h((string)($_POST['tussenvoegsel'] ?? '')) ?>">
            </label>
            <label>Achternaam
              <input type="text" name="last_name" value="<?= h((string)($_POST['last_name'] ?? '')) ?>">
            </label>
            <label>Telefoon (06...)
              <input type="text" name="phone" value="<?= h((string)($_POST['phone'] ?? '')) ?>">
            </label>
          </div>
        </div>

        <label>Rollen *</label>
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
          <button class="btn ok" type="submit">Aanmaken & verificatiemail sturen</button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>