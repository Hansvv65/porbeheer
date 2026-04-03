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

auditLog($pdo, 'PAGE_VIEW', 'admin/finance.php');

/*

Voor het ophalen van de bedragen per maand en per keer, bepaald in beheer>config gebruik je dit scriptdeel :

function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
    $st->execute([$key]);
    $val = $st->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

$monthPrice = (float)getSetting($pdo, 'subscription_month_price', '0.00');
$daypartPrice = (float)getSetting($pdo, 'daypart_price', '0.00');
*/


/* Periode */
$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$monthEnd   = $today->modify('last day of this month')->format('Y-m-d');

/* Accounts */
$accounts = $pdo->query("SELECT id, name, opening_balance, is_default FROM finance_accounts WHERE deleted_at IS NULL ORDER BY is_default DESC, name")->fetchAll();
$defaultAccountId = (int)($accounts[0]['id'] ?? 0);
$accountId = (int)($_GET['account_id'] ?? $defaultAccountId);
if ($accountId <= 0) $accountId = $defaultAccountId;

/* Opening balance */
$st = $pdo->prepare("SELECT opening_balance FROM finance_accounts WHERE id=? AND deleted_at IS NULL");
$st->execute([$accountId]);
$opening = (float)($st->fetchColumn() ?? 0);

/* Delta all */
$st = $pdo->prepare("
  SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0)
  FROM transactions
  WHERE deleted_at IS NULL AND account_id = ?
");
$st->execute([$accountId]);
$deltaAll = (float)$st->fetchColumn();
$balance = $opening + $deltaAll;

/* Month summary */
$st = $pdo->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS incm,
    COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expm
  FROM transactions
  WHERE deleted_at IS NULL
    AND account_id = ?
    AND transaction_date BETWEEN ? AND ?
");
$st->execute([$accountId, $monthStart, $monthEnd]);
$m = $st->fetch() ?: ['incm'=>0,'expm'=>0];

/* Open invoices */
$openInvoices = $pdo->query("
  SELECT i.id, i.period_start, i.kind, i.amount, i.due_date, b.name AS band_name
  FROM band_invoices i
  JOIN bands b ON b.id=i.band_id AND b.deleted_at IS NULL
  WHERE i.status='open'
  ORDER BY i.due_date ASC, b.name ASC
  LIMIT 8
")->fetchAll();

/* Recent transactions */
$st = $pdo->prepare("
  SELECT t.id, t.transaction_date, t.type, t.amount, t.description, b.name AS band_name, c.name AS contact_name
  FROM transactions t
  LEFT JOIN bands b ON b.id=t.band_id
  LEFT JOIN contacts c ON c.id=t.contact_id
  WHERE t.deleted_at IS NULL AND t.account_id=?
  ORDER BY t.transaction_date DESC, t.id DESC
  LIMIT 10
");
$st->execute([$accountId]);
$recent = $st->fetchAll();

/* Finance tiles */
$tiles = [
  ['title'=>'Transacties',      'href'=>'/admin/transactions.php?account_id='.$accountId, 'roles'=>['ADMIN','BEHEER','FINANCIEEL']],
  ['title'=>'+ Invoer',         'href'=>'/admin/transaction_new.php?account_id='.$accountId, 'roles'=>['ADMIN','BEHEER','FINANCIEEL']],
  ['title'=>'Begroting',        'href'=>'/admin/budget.php', 'roles'=>['ADMIN','BEHEER','FINANCIEEL']],
  ['title'=>'Facturen',         'href'=>'/admin/invoices.php', 'roles'=>['ADMIN','BEHEER','FINANCIEEL']],
  ['title'=>'Genereer facturen','href'=>'/admin/invoice_run.php', 'roles'=>['ADMIN','BEHEER','FINANCIEEL']],
  ['title'=>'Dashboard',        'href'=>'/admin/dashboard.php', 'roles'=>['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER']],
  ['title'=>'Uitloggen',        'href'=>'/logout.php', 'roles'=>['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER']],
];

function allowedTile(array $tile, string $role): bool {
  return in_array($role, $tile['roles'], true);
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Financiën</title>
  <style>
    :root{
      --text:#fff;
      --muted:rgba(255,255,255,.78);
      --border:rgba(255,255,255,.22);
      --glass:rgba(255,255,255,.12);
      --glass2:rgba(255,255,255,.06);
      --shadow:0 14px 40px rgba(0,0,0,.45);
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
    .wrap{ width:min(1200px,96vw); }
    .topbar{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom:14px;
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
      min-width:280px;
    }
    .userbox .line1{ font-weight:bold; }
    .userbox .line2{ color:var(--muted); margin-top:6px; font-size:13px; display:flex; gap:10px; flex-wrap:wrap; }
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
      grid-template-columns:repeat(4, minmax(160px, 1fr));
      gap:14px;
    }
    @media (max-width:960px){
      .grid{ grid-template-columns:repeat(2, minmax(160px, 1fr)); }
      .userbox{ min-width:unset; width:100%; }
    }
    @media (max-width:520px){ .grid{ grid-template-columns:1fr; } }

    .tile{
      position:relative;
      border-radius:16px;
      border:1px solid rgba(255,255,255,.22);
      background:linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow:0 10px 22px rgba(0,0,0,.30);
      backdrop-filter:blur(10px);
      -webkit-backdrop-filter:blur(10px);
      overflow:hidden;
      min-height:92px;
      display:flex;
      align-items:center;
      justify-content:center;
      text-decoration:none;
      color:var(--text);
      font-weight:800;
      letter-spacing:.2px;
      transition:transform .12s ease, border-color .12s ease, background .12s ease;
      text-align:center;
      padding:10px 12px;
    }
    .tile:hover{
      transform:translateY(-2px);
      border-color:rgba(255,255,255,.38);
      background:linear-gradient(180deg, rgba(255,255,255,.20), rgba(255,255,255,.08));
    }
    .tile.disabled{ opacity:.45; pointer-events:none; filter:grayscale(35%); }
    .tile::before{
      content:"";
      position:absolute;
      inset:-40%;
      background:radial-gradient(circle at 20% 30%, rgba(255,255,255,.22), transparent 45%);
      transform:rotate(12deg);
    }

    a{ color:#fff; text-decoration:none; transition:color .15s ease; }
    a:hover{ color:#ffd9b3; }
    a:visited{ color:#ffe0c2; }

    .kpiRow{
      display:grid;
      grid-template-columns: repeat(3, minmax(160px, 1fr));
      gap:14px;
      margin-top:14px;
    }
    @media (max-width:960px){ .kpiRow{ grid-template-columns:1fr; } }

    .kpi{
      border-radius:16px;
      border:1px solid rgba(255,255,255,.22);
      background:linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow:0 10px 22px rgba(0,0,0,.30);
      padding:14px;
      overflow:hidden;
      position:relative;
    }
    .kpi h3{ margin:0 0 8px 0; font-size:14px; color:var(--muted); font-weight:700; }
    .kpi .v{ font-size:26px; font-weight:900; letter-spacing:.2px; }
    .kpi .s{ margin-top:6px; color:var(--muted); font-size:13px; }

    .table{
      width:100%;
      border-collapse:collapse;
      margin-top:10px;
      font-size:13px;
      color:#fff;
    }
    .table th, .table td{
      padding:10px 8px;
      border-bottom:1px solid rgba(255,255,255,.16);
      vertical-align:top;
    }
    .table th{ text-align:left; color:rgba(255,255,255,.85); font-size:12px; letter-spacing:.2px; }
    .pill{
      display:inline-block;
      padding:4px 8px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.22);
      background:rgba(255,255,255,.08);
      font-size:12px;
      color:rgba(255,255,255,.9);
      white-space:nowrap;
    }
    .rowflex{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    select, input{
      padding:10px 10px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.22);
      background:rgba(0,0,0,.20);
      color:#fff;
      outline:none;
    }
    option{ color:#000; }
    .btn{
      display:inline-block;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.22);
      background:rgba(255,255,255,.10);
      font-weight:800;
    }
    .btn:hover{ background:rgba(255,255,255,.16); }
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
          <div class="sub">POP Oefenruimte Zevenaar • financiën</div>
        </div>

        <div class="userbox">
          <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · rol <?= h($role) ?></div>
          <div class="line2">
            <a href="/admin/dashboard.php">Dashboard</a>
            <a href="/admin/finance.php">Financiën</a>
            <a href="/logout.php">Uitloggen</a>
          </div>
        </div>
      </div>

      <div class="panel">
        <form method="get" class="rowflex" style="justify-content:space-between;">
          <div class="rowflex">
            <span class="pill">Rekening</span>
            <select name="account_id" onchange="this.form.submit()">
              <?php foreach ($accounts as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id']===$accountId?'selected':'') ?>>
                  <?= h($a['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn">Toon</button></noscript>
          </div>
          <div class="rowflex">
            <span class="pill">Maand</span>
            <span><?= h($today->format('m-Y')) ?></span>
          </div>
        </form>

        <div class="kpiRow">
          <div class="kpi">
            <h3>Saldo</h3>
            <div class="v">€ <?= number_format($balance, 2, ',', '.') ?></div>
            <div class="s">Opening: € <?= number_format($opening, 2, ',', '.') ?> · Mutaties: € <?= number_format($deltaAll, 2, ',', '.') ?></div>
          </div>
          <div class="kpi">
            <h3>Inkomsten (deze maand)</h3>
            <div class="v">€ <?= number_format((float)$m['incm'], 2, ',', '.') ?></div>
            <div class="s">Periode: <?= h($monthStart) ?> t/m <?= h($monthEnd) ?></div>
          </div>
          <div class="kpi">
            <h3>Uitgaven (deze maand)</h3>
            <div class="v">€ <?= number_format((float)$m['expm'], 2, ',', '.') ?></div>
            <div class="s">Resultaat: € <?= number_format(((float)$m['incm']-(float)$m['expm']), 2, ',', '.') ?></div>
          </div>
        </div>

        <div class="grid" style="margin-top:14px;">
          <?php foreach ($tiles as $t): ?>
            <?php
              $ok = allowedTile($t, $role);
              $cls = $ok ? 'tile' : 'tile disabled';
              $href = $ok ? $t['href'] : '#';
            ?>
            <a class="<?= $cls ?>" href="<?= h($href) ?>"><?= h($t['title']) ?></a>
          <?php endforeach; ?>
        </div>

        <div class="kpiRow" style="margin-top:14px;">
          <div class="kpi" style="grid-column: span 2;">
            <h3>Laatste transacties</h3>
            <?php if (!$recent): ?>
              <div class="s">Nog geen transacties.</div>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr><th>Datum</th><th>Type</th><th>Bedrag</th><th>Band/Contact</th><th>Omschrijving</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td><?= h($r['transaction_date']) ?></td>
                      <td><span class="pill"><?= h($r['type']) ?></span></td>
                      <td>€ <?= number_format((float)$r['amount'], 2, ',', '.') ?></td>
                      <td><?= h($r['band_name'] ?? '') ?><?= (($r['band_name'] ?? '') && ($r['contact_name'] ?? '')) ? ' / ' : '' ?><?= h($r['contact_name'] ?? '') ?></td>
                      <td><?= h(mb_strimwidth((string)($r['description'] ?? ''), 0, 70, '…')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <div class="kpi">
            <h3>Open facturen</h3>
            <?php if (!$openInvoices): ?>
              <div class="s">Geen open facturen.</div>
            <?php else: ?>
              <table class="table">
                <thead><tr><th>Band</th><th>Periode</th><th>Bedrag</th><th>Verval</th></tr></thead>
                <tbody>
                  <?php foreach ($openInvoices as $i): ?>
                    <tr>
                      <td><?= h($i['band_name']) ?></td>
                      <td><?= h((new DateTimeImmutable($i['period_start']))->format('m-Y')) ?></td>
                      <td>€ <?= number_format((float)$i['amount'], 2, ',', '.') ?></td>
                      <td><?= h($i['due_date']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <div class="s"><a href="/admin/invoices.php">Alles bekijken</a></div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>
</body>
</html>