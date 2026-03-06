<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER','FINANCIEEL']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

auditLog($pdo, 'PAGE_VIEW', 'admin/invoices.php');

$status = (string)($_GET['status'] ?? 'open');
if (!in_array($status, ['open','paid','void','all'], true)) $status = 'open';

$where = "1=1";
$params = [];
if ($status !== 'all') { $where = "i.status = :st"; $params[':st'] = $status; }

$st = $pdo->prepare("
  SELECT i.id, i.band_id, i.period_start, i.kind, i.amount, i.due_date, i.status, i.paid_transaction_id,
         b.name AS band_name
  FROM band_invoices i
  JOIN bands b ON b.id=i.band_id AND b.deleted_at IS NULL
  WHERE {$where}
  ORDER BY i.period_start DESC, b.name ASC
  LIMIT 500
");
$st->execute($params);
$rows = $st->fetchAll();

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Facturen</title>
  <style>
    :root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
    body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('/assets/images/finance-a.png') no-repeat center center fixed;background-size:cover;}
    .backdrop{min-height:100vh;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
    .wrap{width:min(1200px,96vw);}
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
    .pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.9);white-space:nowrap;}
    .rowflex{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    select{padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(0,0,0,.20);color:#fff;outline:none}
    option{color:#000}
    .table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px;color:#fff}
    .table th,.table td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.16);vertical-align:top}
    .table th{text-align:left;color:rgba(255,255,255,.85);font-size:12px;letter-spacing:.2px}
  </style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">
    <div class="topbar">
      <div class="brand"><h1>Porbeheer</h1><div class="sub">POP Oefenruimte Zevenaar • facturen</div></div>
      <div class="userbox">
        <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · rol <?= h($role) ?></div>
        <div class="line2"><a href="/admin/finance.php">Financiën</a><a href="/admin/dashboard.php">Dashboard</a><a href="/logout.php">Uitloggen</a></div>
      </div>
    </div>

    <div class="panel">
      <form method="get" class="rowflex">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <span class="pill">Status</span>
          <select name="status" onchange="this.form.submit()">
            <option value="open" <?= ($status==='open'?'selected':'') ?>>open</option>
            <option value="paid" <?= ($status==='paid'?'selected':'') ?>>paid</option>
            <option value="void" <?= ($status==='void'?'selected':'') ?>>void</option>
            <option value="all"  <?= ($status==='all'?'selected':'') ?>>all</option>
          </select>
          <noscript><button class="btn">Toon</button></noscript>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="btn" href="/admin/invoice_run.php">Genereer maandfacturen</a>
          <a class="btn" href="/admin/finance.php">Terug</a>
        </div>
      </form>

      <table class="table">
        <thead>
          <tr>
            <th>Band</th><th>Periode</th><th>Type</th><th>Bedrag</th><th>Vervaldatum</th><th>Status</th><th>Actie</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['band_name']) ?></td>
            <td><?= h((new DateTimeImmutable($r['period_start']))->format('m-Y')) ?></td>
            <td><span class="pill"><?= h($r['kind']) ?></span></td>
            <td>€ <?= number_format((float)$r['amount'], 2, ',', '.') ?></td>
            <td><?= h($r['due_date']) ?></td>
            <td><span class="pill"><?= h($r['status']) ?></span></td>
            <td>
              <?php if ($r['status'] === 'open'): ?>
                <a class="btn" href="/admin/invoice_pay.php?id=<?= (int)$r['id'] ?>">Markeer betaald</a>
              <?php else: ?>
                <?= $r['paid_transaction_id'] ? ('trx #'.(int)$r['paid_transaction_id']) : '' ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

    </div>
  </div>
</div>
</body>
</html>