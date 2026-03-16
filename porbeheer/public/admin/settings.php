<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('schedule', $pdo);


function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    $st = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
    $st->execute([$key]);
    $val = $st->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $st = $pdo->prepare("
        INSERT INTO app_settings (`key`, `value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
            `value` = VALUES(`value`)
    ");
    $st->execute([$key, $value]);
}

function normalizePrice(string $value): ?string
{
    $value = trim($value);
    $value = str_replace(',', '.', $value);

    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) {
        return null;
    }

    return number_format((float)$value, 2, '.', '');
}

$errors = [];
$success = null;
$csrf = csrfToken();

$monthPrice = getSetting($pdo, 'subscription_month_price', '0.00');
$daypartPrice = getSetting($pdo, 'daypart_price', '0.00');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $postedMonthPrice = (string)($_POST['subscription_month_price'] ?? '');
    $postedDaypartPrice = (string)($_POST['daypart_price'] ?? '');

    $monthNorm = normalizePrice($postedMonthPrice);
    $daypartNorm = normalizePrice($postedDaypartPrice);

    if ($monthNorm === null) {
        $errors[] = 'Prijs per maand is ongeldig. Gebruik bijvoorbeeld 125,00 of 125.00';
    }

    if ($daypartNorm === null) {
        $errors[] = 'Prijs per dagdeel is ongeldig. Gebruik bijvoorbeeld 40,00 of 40.00';
    }

    if (!$errors) {
        try {
            setSetting($pdo, 'subscription_month_price', $monthNorm);
            setSetting($pdo, 'daypart_price', $daypartNorm);

            $monthPrice = $monthNorm;
            $daypartPrice = $daypartNorm;
            $success = 'Instellingen opgeslagen.';

            auditLog($pdo, 'SETTINGS_UPDATE', 'admin/settings.php', [
                'subscription_month_price' => $monthPrice,
                'daypart_price' => $daypartPrice,
            ]);
        } catch (Throwable $e) {
            $errors[] = 'Opslaan mislukt.';
            auditLog($pdo, 'SETTINGS_UPDATE_FAIL', 'admin/settings.php', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

auditLog($pdo, 'PAGE_VIEW', 'admin/settings.php');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Configuratie</title>
<style>
  :root{
    --text:#fff;
    --muted:rgba(255,255,255,.78);
    --border:rgba(255,255,255,.22);
    --glass:rgba(255,255,255,.12);
    --glass2:rgba(255,255,255,.06);
    --shadow:0 14px 40px rgba(0,0,0,.45);
    --ok:#7CFFB2;
    --err:#FF8DA1;
    --accent:#ffd86b;
  }

  body{
    margin:0;
    font-family:Arial,sans-serif;
    color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;  background-size:cover;
  }

  .backdrop{
    min-height:100vh;
    background:
      radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
      linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
    padding:26px;
    box-sizing:border-box;
    display:flex;
    justify-content:center;
  }

  .wrap{
    width:min(980px, 96vw);
  }

  .topbar{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:14px;
  }

  .brand h1{
    margin:0;
    font-size:28px;
    letter-spacing:.5px;
  }

  .brand .sub{
    margin-top:6px;
    color:var(--muted);
    font-size:14px;
  }

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

  .userbox .line1{
    font-weight:bold;
  }

  .userbox .line2{
    color:var(--muted);
    margin-top:4px;
    font-size:13px;
  }

  a{
    color:#fff;
    text-decoration:none;
  }

  a:visited{
    color:var(--accent);
  }

  a:hover{
    opacity:.95;
  }

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

  .grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    margin-top:16px;
  }

  @media (max-width: 780px){
    .grid{
      grid-template-columns:1fr;
    }
  }

  .field{
    padding:14px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(0,0,0,.14);
  }

  label{
    display:block;
    margin-bottom:8px;
    font-weight:800;
  }

  input[type="text"]{
    width:100%;
    padding:12px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(0,0,0,.25);
    color:#fff;
    outline:none;
    box-sizing:border-box;
    font-size:16px;
  }

  input[type="text"]:focus{
    border-color:rgba(255,255,255,.38);
    box-shadow:0 0 0 3px rgba(255,255,255,.10);
  }

  .hint{
    color:var(--muted);
    font-size:13px;
    margin-top:8px;
  }

  .row{
    display:flex;
    gap:12px;
    align-items:center;
    justify-content:space-between;
    margin-top:18px;
    flex-wrap:wrap;
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

  .btn:hover{
    transform:translateY(-1px);
    background:rgba(255,255,255,.18);
    border-color:rgba(255,255,255,.35);
  }

  .btn.ok{
    border-color:rgba(124,255,178,.35);
    background:rgba(124,255,178,.10);
  }

  .msg-ok{
    margin:0 0 10px 0;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid rgba(124,255,178,.35);
    background:rgba(124,255,178,.12);
    color:var(--ok);
    font-weight:800;
  }

  .msg-err{
    margin:0 0 10px 0;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid rgba(255,141,161,.35);
    background:rgba(255,141,161,.12);
    color:var(--err);
    font-weight:800;
  }

  .muted{
    color:var(--muted);
    font-size:13px;
    margin-top:6px;
  }
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
          <a href="/admin/beheer.php">Beheer</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2 style="margin:0 0 8px 0;">Configuratie prijzen</h2>
      <div class="muted">Hier stel je de prijs van het maandabonnement en een dagdeel in.</div>

      <div style="margin-top:12px;">
        <?php if ($success): ?>
          <div class="msg-ok"><?= h($success) ?></div>
        <?php endif; ?>

        <?php foreach ($errors as $e): ?>
          <div class="msg-err"><?= h($e) ?></div>
        <?php endforeach; ?>
      </div>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <div class="grid">
          <div class="field">
            <label for="subscription_month_price">Abonnement per maand (€)</label>
            <input
              type="text"
              id="subscription_month_price"
              name="subscription_month_price"
              value="<?= h($monthPrice) ?>"
              required
            >
            <div class="hint">Voorbeeld: 125,00 of 125.00</div>
          </div>

          <div class="field">
            <label for="daypart_price">Prijs per dagdeel (€)</label>
            <input
              type="text"
              id="daypart_price"
              name="daypart_price"
              value="<?= h($daypartPrice) ?>"
              required
            >
            <div class="hint">Voorbeeld: 40,00 of 40.00</div>
          </div>
        </div>

        <div class="row">
          <div class="btn"><a href="/admin/tech-test.php">Tech test</a></div>
          <a class="btn" href="/admin/beheer.php">Terug naar beheer</a>
          <button class="btn ok" type="submit">Opslaan</button>
        </div>
      </form>
    </div>

  </div>
</div>
</body>
</html>