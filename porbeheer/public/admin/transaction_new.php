<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER','FINANCIEEL']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

auditLog($pdo, 'PAGE_VIEW', 'admin/transaction_new.php');

$accounts = $pdo->query("SELECT id, name FROM finance_accounts WHERE deleted_at IS NULL ORDER BY is_default DESC, name")->fetchAll();
$bands    = $pdo->query("SELECT id, name FROM bands WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
$budget   = $pdo->query("SELECT id, name, kind FROM budget_items WHERE deleted_at IS NULL AND is_active=1 ORDER BY kind, name")->fetchAll();

$prefAccountId = (int)($_GET['account_id'] ?? 0);

$err = null; $msg = null;

$data = [
  'account_id' => $prefAccountId ?: (int)($accounts[0]['id'] ?? 0),
  'type' => 'income',
  'amount' => '',
  'category' => '',
  'budget_item_id' => 0,
  'band_id' => 0,
  'reference' => '',
  'description' => '',
  'transaction_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrf($_POST['csrf'] ?? '');

  $data['account_id'] = (int)($_POST['account_id'] ?? 0);
  $data['type'] = (string)($_POST['type'] ?? 'income');
  $data['amount'] = trim((string)($_POST['amount'] ?? ''));
  $data['category'] = trim((string)($_POST['category'] ?? ''));
  $data['budget_item_id'] = (int)($_POST['budget_item_id'] ?? 0);
  $data['band_id'] = (int)($_POST['band_id'] ?? 0);
  $data['reference'] = trim((string)($_POST['reference'] ?? ''));
  $data['description'] = trim((string)($_POST['description'] ?? ''));
  $data['transaction_date'] = (string)($_POST['transaction_date'] ?? $data['transaction_date']);

  $amount = (float)str_replace(',', '.', $data['amount']);

  if ($data['account_id'] <= 0) $err = 'Kies een rekening.';
  elseif (!in_array($data['type'], ['income','expense'], true)) $err = 'Ongeldig type.';
  elseif ($amount <= 0) $err = 'Bedrag moet > 0 zijn.';
  elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['transaction_date'])) $err = 'Ongeldige datum.';

  if (!$err) {
    $st = $pdo->prepare("
      INSERT INTO transactions (band_id, account_id, budget_item_id, amount, type, category, description, reference, transaction_date, created_by)
      VALUES (:band_id, :account_id, :budget_item_id, :amount, :type, :category, :description, :reference, :transaction_date, :created_by)
    ");
    $st->execute([
      ':band_id' => $data['band_id'] ?: null,
      ':account_id' => $data['account_id'] ?: null,
      ':budget_item_id' => $data['budget_item_id'] ?: null,
      ':amount' => $amount,
      ':type' => $data['type'],
      ':category' => ($data['category'] !== '' ? $data['category'] : null),
      ':description' => ($data['description'] !== '' ? $data['description'] : null),
      ':reference' => ($data['reference'] !== '' ? $data['reference'] : null),
      ':transaction_date' => $data['transaction_date'],
      ':created_by' => (int)($user['id'] ?? 0) ?: null,
    ]);
    $msg = 'Opgeslagen.';
    $data['amount'] = '';
    $data['description'] = '';
    $data['reference'] = '';
  }
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Invoer</title>
  <style>
    :root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
    body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('/assets/images/finance-a.png') no-repeat center center fixed;background-size:cover;}
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
    .pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.9);white-space:nowrap;}
    .btn{display:inline-block;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.10);font-weight:800}
    .btn:hover{background:rgba(255,255,255,.16)}
    .alert{border-radius:14px;padding:10px 12px;border:1px solid rgba(255,255,255,.22);margin-bottom:12px;background:rgba(0,0,0,.22)}
    .grid2{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px}
    @media(max-width:800px){.grid2{grid-template-columns:1fr}}
    label{display:block;color:rgba(255,255,255,.86);font-size:12px;margin:2px 0 6px}
    input,select,textarea{width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(0,0,0,.20);color:#fff;outline:none;box-sizing:border-box}
    option{color:#000}
    textarea{min-height:90px}
    .row{margin-bottom:12px}
    .rowflex{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
  </style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">
    <div class="topbar">
      <div class="brand"><h1>Porbeheer</h1><div class="sub">POP Oefenruimte Zevenaar • nieuwe transactie</div></div>
      <div class="userbox">
        <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · rol <?= h($role) ?></div>
        <div class="line2"><a href="/admin/finance.php">Financiën</a><a href="/admin/dashboard.php">Dashboard</a><a href="/logout.php">Uitloggen</a></div>
      </div>
    </div>

    <div class="panel">
      <?php if ($err): ?><div class="alert">⚠ <?= h($err) ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="alert">✅ <?= h($msg) ?></div><?php endif; ?>

      <form method="post">
        <?= csrfField() ?>

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
            <label>Datum</label>
            <input type="date" name="transaction_date" value="<?= h($data['transaction_date']) ?>" required>
          </div>

          <div class="row">
            <label>Type</label>
            <select name="type">
              <option value="income" <?= ($data['type']==='income'?'selected':'') ?>>inkomen</option>
              <option value="expense" <?= ($data['type']==='expense'?'selected':'') ?>>uitgave</option>
            </select>
          </div>
          <div class="row">
            <label>Bedrag</label>
            <input type="text" name="amount" value="<?= h($data['amount']) ?>" placeholder="bijv. 25.00" required>
          </div>

          <div class="row">
            <label>Band (optioneel)</label>
            <select name="band_id">
              <option value="0">-</option>
              <?php foreach ($bands as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id']===(int)$data['band_id']?'selected':'') ?>><?= h($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row">
            <label>Begrotingspost (optioneel)</label>
            <select name="budget_item_id">
              <option value="0">-</option>
              <?php foreach ($budget as $bi): ?>
                <option value="<?= (int)$bi['id'] ?>" <?= ((int)$bi['id']===(int)$data['budget_item_id']?'selected':'') ?>>
                  <?= h($bi['kind']) ?> · <?= h($bi['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row">
            <label>Categorie (vrij)</label>
            <input type="text" name="category" value="<?= h($data['category']) ?>" placeholder="bijv. huur, onderhoud, drank">
          </div>
          <div class="row">
            <label>Referentie (optioneel)</label>
            <input type="text" name="reference" value="<?= h($data['reference']) ?>" placeholder="factuurnr / bankkenmerk">
          </div>
        </div>

        <div class="row">
          <label>Omschrijving</label>
          <textarea name="description" placeholder="korte uitleg"><?= h($data['description']) ?></textarea>
        </div>

        <div class="rowflex">
          <button class="btn">Opslaan</button>
          <a class="btn" href="/admin/transactions.php?account_id=<?= (int)$data['account_id'] ?>">Naar transacties</a>
          <a class="btn" href="/admin/finance.php">Terug</a>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>