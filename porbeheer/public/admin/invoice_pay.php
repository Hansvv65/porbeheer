<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER','FINANCIEEL']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('finance', $pdo);

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) { header('Location: /admin/invoices.php'); exit; }

auditLog($pdo, 'PAGE_VIEW', 'admin/invoice_pay.php id=' . $id);

$accounts = $pdo->query("SELECT id, name, is_default FROM finance_accounts WHERE deleted_at IS NULL ORDER BY is_default DESC, name")->fetchAll();
$defaultAccountId = (int)($accounts[0]['id'] ?? 0);

$st = $pdo->prepare("
  SELECT i.*, b.name AS band_name
  FROM band_invoices i
  JOIN bands b ON b.id=i.band_id AND b.deleted_at IS NULL
  WHERE i.id = ?
");
$st->execute([$id]);
$inv = $st->fetch();
if (!$inv) { header('Location: /admin/invoices.php'); exit; }

$err = null; $msg = null;

$data = [
  'account_id' => (int)($_GET['account_id'] ?? $defaultAccountId),
  'pay_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
  'reference' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrf($_POST['csrf'] ?? '');

  $data['account_id'] = (int)($_POST['account_id'] ?? 0);
  $data['pay_date'] = (string)($_POST['pay_date'] ?? $data['pay_date']);
  $data['reference'] = trim((string)($_POST['reference'] ?? ''));

  if ($inv['status'] !== 'open') $err = 'Deze factuur is niet open.';
  elseif ($data['account_id'] <= 0) $err = 'Kies een rekening.';
  elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['pay_date'])) $err = 'Ongeldige datum.';

  if (!$err) {
    $pdo->beginTransaction();
    try {
      // transactie aanmaken
      $desc = 'Betaling factuur #' . (int)$inv['id'] . ' - ' . (string)$inv['band_name'] . ' (' . (new DateTimeImmutable($inv['period_start']))->format('m-Y') . ')';
      $stIns = $pdo->prepare("
        INSERT INTO transactions (band_id, account_id, amount, type, category, description, reference, transaction_date, created_by)
        VALUES (:band_id, :account_id, :amount, 'income', :category, :description, :reference, :transaction_date, :created_by)
      ");
      $stIns->execute([
        ':band_id' => (int)$inv['band_id'],
        ':account_id' => $data['account_id'],
        ':amount' => (float)$inv['amount'],
        ':category' => 'abonnement',
        ':description' => $desc,
        ':reference' => ($data['reference'] !== '' ? $data['reference'] : null),
        ':transaction_date' => $data['pay_date'],
        ':created_by' => (int)($user['id'] ?? 0) ?: null,
      ]);
      $trxId = (int)$pdo->lastInsertId();

      // factuur op paid
      $stUp = $pdo->prepare("
        UPDATE band_invoices
        SET status='paid', paid_transaction_id=:trx
        WHERE id=:id AND status='open'
      ");
      $stUp->execute([':trx'=>$trxId, ':id'=>$id]);

      $pdo->commit();
      header('Location: /admin/invoices.php?status=open');
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Fout bij betalen: ' . $e->getMessage();
    }
  }
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Factuur betalen</title>
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
    label{display:block;color:rgba(255,255,255,.86);font-size:12px;margin:2px 0 6px}
    input,select{width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(0,0,0,.20);color:#fff;outline:none;box-sizing:border-box}
    option{color:#000}
    .grid2{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px}
    @media(max-width:800px){.grid2{grid-template-columns:1fr}}
    .row{margin-bottom:12px}
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
      <div class="brand"><h1>Porbeheer</h1><div class="sub">POP Oefenruimte Zevenaar • factuur betalen</div></div>
      <div class="userbox">
        <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · rol <?= h($role) ?></div>
        <div class="line2"><a href="/admin/invoices.php">Facturen</a><a href="/admin/finance.php">Financiën</a><a href="/logout.php">Uitloggen</a></div>
      </div>
    </div>

    <div class="panel">
      <?php if ($err): ?><div class="alert">⚠ <?= h($err) ?></div><?php endif; ?>

      <div class="rowflex">
        <div>
          <span class="pill">Factuur #<?= (int)$inv['id'] ?></span>
          <span style="margin-left:10px;"><?= h($inv['band_name']) ?></span>
          <span style="margin-left:10px;color:var(--muted);"><?= h((new DateTimeImmutable($inv['period_start']))->format('m-Y')) ?> · € <?= number_format((float)$inv['amount'], 2, ',', '.') ?></span>
        </div>
        <div><span class="pill"><?= h($inv['status']) ?></span></div>
      </div>

      <form method="post" style="margin-top:14px;">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="grid2">
          <div class="row">
            <label>Rekening</label>
            <select name="account_id" required>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id']===(int)$data['account_id']?'selected':'') ?>><?= h($a['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row">
            <label>Betaaldatum</label>
            <input type="date" name="pay_date" value="<?= h($data['pay_date']) ?>" required>
          </div>
          <div class="row" style="grid-column: span 2;">
            <label>Referentie (optioneel)</label>
            <input type="text" name="reference" value="<?= h($data['reference']) ?>" placeholder="bankkenmerk / notitie">
          </div>
        </div>

        <div class="rowflex">
          <button class="btn">Markeer betaald</button>
          <a class="btn" href="/admin/invoices.php">Terug</a>
        </div>

        <div style="margin-top:12px;color:var(--muted);font-size:13px;">
          Bij “Markeer betaald” wordt automatisch een <strong>income</strong> transactie aangemaakt en gekoppeld aan deze factuur.
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>