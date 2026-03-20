<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER','FINANCIEEL']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('finance', $pdo);

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

auditLog($pdo, 'PAGE_VIEW', 'admin/invoice_run.php');

$today = new DateTimeImmutable('today');
$periodStart = $today->modify('first day of this month')->format('Y-m-d');
$dueDate = $today->modify('first day of this month')->modify('+14 days')->format('Y-m-d');

$st = $pdo->prepare("SELECT value FROM app_settings WHERE `key`='subscription_monthly_default'");
$st->execute();
$monthlyDefault = (float)($st->fetchColumn() ?? 0);

$bands = $pdo->query("
  SELECT id, name, monthly_fee
  FROM bands
  WHERE deleted_at IS NULL AND is_subscription_active=1
  ORDER BY name
")->fetchAll();

$err = null; $msg = null;
$created = 0; $skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrf($_POST['csrf'] ?? '');

  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare("
      INSERT INTO band_invoices (band_id, period_start, kind, amount, description, due_date, status, created_by)
      VALUES (:band_id, :period_start, 'monthly', :amount, :description, :due_date, 'open', :created_by)
    ");

    foreach ($bands as $b) {
      $amount = (float)$b['monthly_fee'];
      if ($amount <= 0) $amount = $monthlyDefault;

      if ($amount <= 0) { $skipped++; continue; }

      try {
        $ins->execute([
          ':band_id' => (int)$b['id'],
          ':period_start' => $periodStart,
          ':amount' => $amount,
          ':description' => 'Maandabonnement ' . (new DateTimeImmutable($periodStart))->format('m-Y'),
          ':due_date' => $dueDate,
          ':created_by' => (int)($user['id'] ?? 0) ?: null,
        ]);
        $created++;
      } catch (PDOException $e) {
        // duplicate unique key => overslaan
        $skipped++;
      }
    }

    $pdo->commit();
    $msg = "Klaar: {$created} gemaakt, {$skipped} overgeslagen.";
  } catch (Throwable $e) {
    $pdo->rollBack();
    $err = 'Fout: ' . $e->getMessage();
  }
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Genereer facturen</title>
  <style>
    :root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
    body{margin:0;font-family:Arial,sans-serif;color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;  background-size:cover;
    .backdrop{min-height:100vh;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
    .wrap{width:min(980px,96vw);}
    .topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
    .brand h1{margin:0;font-size:28px;letter-spacing:.5px;}
    .brand .sub{margin-top:6px;color:var(--muted);font-size:14px;}
    .userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);min-width:280px;}
    .userbox .line1{font-weight:bold}
    .userbox .line2{color:var(--muted);margin-top:6px;font-size:13px;display:flex;gap:10px;flex-wrap:wrap;}
    .panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);padding:18px;}
    a{color:#fff;text-decoration:none;transition:color .15s ease} a:hover{color:#ffd9b3} a:visited{color:#ffe0c2}
    .btn{display:inline-block;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.10);font-weight:800}
    .btn:hover{background:rgba(255,255,255,.16)}
    .alert{border-radius:14px;padding:10px 12px;border:1px solid rgba(255,255,255,.22);margin-bottom:12px;background:rgba(0,0,0,.22)}
    .pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.9);white-space:nowrap;}
    .rowflex{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
      a{color:#fff;text-decoration:none;transition:color .15s ease}
  a:hover{color:#ffd9b3}
  a:visited{color:#ffe0c2}

  </style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">
    <div class="topbar">
      <div class="brand"><h1>Porbeheer</h1><div class="sub">POP Oefenruimte Zevenaar • genereer maandfacturen</div></div>
      <div class="userbox">
        <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · rol <?= h($role) ?></div>
        <div class="line2"><a href="/admin/invoices.php">Facturen</a><a href="/admin/finance.php">Financiën</a><a href="/logout.php">Uitloggen</a></div>
      </div>
    </div>

    <div class="panel">
      <?php if ($err): ?><div class="alert">⚠ <?= h($err) ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="alert">✅ <?= h($msg) ?></div><?php endif; ?>

      <div class="rowflex">
        <div>
          <span class="pill">Periode</span>
          <span style="margin-left:10px;"><?= h((new DateTimeImmutable($periodStart))->format('m-Y')) ?></span>
          <span style="margin-left:14px;color:var(--muted);">Vervaldatum: <?= h($dueDate) ?></span>
        </div>
        <div style="color:var(--muted);">
          Default maandbedrag (settings): € <?= number_format($monthlyDefault, 2, ',', '.') ?>
        </div>
      </div>

      <form method="post" style="margin-top:14px;">
        <?= csrfField() ?>
        <button class="btn">Genereer</button>
        <a class="btn" href="/admin/invoices.php">Naar facturen</a>
        <a class="btn" href="/admin/finance.php">Terug</a>
      </form>

      <div style="margin-top:14px;color:var(--muted);font-size:13px;">
        Regels: per band wordt <code>bands.monthly_fee</code> gebruikt als &gt; 0, anders <code>app_settings.subscription_monthly_default</code>.
        Duplicates worden automatisch overgeslagen (unique key per band/periode/type).
      </div>
    </div>
  </div>
</div>
</body>
</html>