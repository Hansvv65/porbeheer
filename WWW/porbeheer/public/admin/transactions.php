<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireSectionAccess('financien', 'read');

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('finance', $pdo);

auditLog($pdo, 'PAGE_VIEW', 'admin/transactions.php');

$accountId = (int)($_GET['account_id'] ?? 0);
$type = (string)($_GET['type'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$bandId = (int)($_GET['band_id'] ?? 0);

$accounts = $pdo->query("SELECT id, name FROM finance_accounts WHERE deleted_at IS NULL ORDER BY is_default DESC, name")->fetchAll();
$bands = $pdo->query("SELECT id, name FROM bands WHERE deleted_at IS NULL ORDER BY name")->fetchAll();

$where = ["t.deleted_at IS NULL"];
$params = [];

if ($accountId > 0) { $where[] = "t.account_id = :aid"; $params[':aid'] = $accountId; }
if ($type === 'income' || $type === 'expense') { $where[] = "t.type = :type"; $params[':type'] = $type; }
if ($bandId > 0) { $where[] = "t.band_id = :bid"; $params[':bid'] = $bandId; }
if ($q !== '') {
  $where[] = "(t.description LIKE :q OR t.category LIKE :q OR t.reference LIKE :q OR b.name LIKE :q)";
  $params[':q'] = "%{$q}%";
}

$sql = "
  SELECT t.id, t.transaction_date, t.type, t.amount, t.category, t.description, t.reference,
         a.name AS account_name, b.name AS band_name
  FROM transactions t
  LEFT JOIN finance_accounts a ON a.id=t.account_id
  LEFT JOIN bands b ON b.id=t.band_id
  WHERE " . implode(" AND ", $where) . "
  ORDER BY t.transaction_date DESC, t.id DESC
  LIMIT 300
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Transacties</title>
  <link rel="stylesheet" href="/assets/css/main.css">
<style>
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);min-width:280px;}
.userbox .line2{color:var(--muted);margin-top:6px;font-size:13px;display:flex;gap:10px;flex-wrap:wrap;}
.pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.9);white-space:nowrap;}
.rowflex{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
select,input{padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(0,0,0,.20);color:#fff;outline:none}
option{color:#000}
.btn{display:inline-block;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.10);font-weight:800}
.btn:hover{background:rgba(255,255,255,.16)}
.table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px;color:#fff}
.table th,.table td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.16);vertical-align:top}
.table th{text-align:left;color:rgba(255,255,255,.85);font-size:12px;letter-spacing:.2px}
</style>
</head>
<body style="background-image:url('<?= h($bg) ?>')">
<div class="backdrop">
  <div class="wrap">
    <div class="topbar">
      <div class="brand">
        <h1><a href="/admin/dashboard.php">Porbeheer</a></h1>
        <div class="sub">POP Oefenruimte Zevenaar • transacties</div>
      </div>
      <div class="userbox">
        <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · rol <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/finance.php">Financiën</a>
          <a href="/admin/dashboard.php">Dashboard</a>
          <a href="/logout.php">Uitloggen</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <form method="get" class="rowflex" style="justify-content:space-between;">
        <div class="rowflex">
          <span class="pill">Rekening</span>
          <select name="account_id">
            <option value="0">Alle</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id']===$accountId?'selected':'') ?>><?= h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>

          <span class="pill">Type</span>
          <select name="type">
            <option value="">Alle</option>
            <option value="income" <?= ($type==='income'?'selected':'') ?>>income</option>
            <option value="expense" <?= ($type==='expense'?'selected':'') ?>>expense</option>
          </select>

          <span class="pill">Band</span>
          <select name="band_id">
            <option value="0">Alle</option>
            <?php foreach ($bands as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id']===$bandId?'selected':'') ?>><?= h($b['name']) ?></option>
            <?php endforeach; ?>
          </select>

          <span class="pill">Zoek</span>
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="omschrijving/categorie/ref/band">
        </div>

        <div class="rowflex">
          <button class="btn">Filter</button>
          <a class="btn" href="/admin/transaction_new.php<?= $accountId?('?account_id='.(int)$accountId):'' ?>">+ Invoer</a>
          <a class="btn" href="/admin/finance.php">Terug</a>
        </div>
      </form>

      <table class="table">
        <thead>
          <tr>
            <th>Datum</th><th>Rekening</th><th>Type</th><th>Bedrag</th><th>Band</th><th>Categorie</th><th>Ref</th><th>Omschrijving</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['transaction_date']) ?></td>
            <td><?= h($r['account_name'] ?? '-') ?></td>
            <td><span class="pill"><?= h($r['type']) ?></span></td>
            <td>€ <?= number_format((float)$r['amount'], 2, ',', '.') ?></td>
            <td><?= h($r['band_name'] ?? '') ?></td>
            <td><?= h($r['category'] ?? '') ?></td>
            <td><?= h($r['reference'] ?? '') ?></td>
            <td><?= h(mb_strimwidth((string)($r['description'] ?? ''), 0, 90, '…')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

    </div>
  </div>
</div>
</body>
</html>