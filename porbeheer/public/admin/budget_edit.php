<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';


requireRole(['ADMIN','BEHEER','FINANCIEEL']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$isEdit = $id > 0;

auditLog($pdo, 'PAGE_VIEW', 'admin/budget_edit.php' . ($isEdit ? " id={$id}" : ''));

$err = null;
$msg = null;

$row = [
  'name' => '',
  'kind' => 'expense',
  'annual_amount' => '0.00',
  'notes' => '',
  'is_active' => 1,
];

if ($isEdit) {
  $st = $pdo->prepare("SELECT * FROM budget_items WHERE id=? AND deleted_at IS NULL");
  $st->execute([$id]);
  $db = $st->fetch();
  if (!$db) { header('Location: /admin/budget.php'); exit; }
  $row = array_merge($row, $db);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrf($_POST['csrf'] ?? '');

  $row['name'] = trim((string)($_POST['name'] ?? ''));
  $row['kind'] = (string)($_POST['kind'] ?? 'expense');
  $row['annual_amount'] = trim((string)($_POST['annual_amount'] ?? '0.00'));
  $row['notes'] = trim((string)($_POST['notes'] ?? ''));
  $row['is_active'] = isset($_POST['is_active']) ? 1 : 0;

  $amt = (float)str_replace(',', '.', $row['annual_amount']);

  if ($row['name'] === '') $err = 'Naam is verplicht.';
  elseif (!in_array($row['kind'], ['income','expense','both'], true)) $err = 'Ongeldig type.';
  elseif ($amt < 0) $err = 'Jaarbedrag mag niet negatief zijn.';

  if (!$err) {
    if ($isEdit) {
      $st = $pdo->prepare("
        UPDATE budget_items
        SET name=:name, kind=:kind, annual_amount=:amt, notes=:notes, is_active=:active
        WHERE id=:id
      ");
      $st->execute([
        ':name'=>$row['name'],
        ':kind'=>$row['kind'],
        ':amt'=>$amt,
        ':notes'=>($row['notes']!==''?$row['notes']:null),
        ':active'=>$row['is_active'],
        ':id'=>$id
      ]);
      $msg = 'Begrotingspost bijgewerkt.';
    } else {
      $st = $pdo->prepare("
        INSERT INTO budget_items (name, kind, annual_amount, notes, is_active)
        VALUES (:name, :kind, :amt, :notes, :active)
      ");
      $st->execute([
        ':name'=>$row['name'],
        ':kind'=>$row['kind'],
        ':amt'=>$amt,
        ':notes'=>($row['notes']!==''?$row['notes']:null),
        ':active'=>$row['is_active'],
      ]);
      $id = (int)$pdo->lastInsertId();
      $isEdit = true;
      $msg = 'Begrotingspost toegevoegd.';
    }
  }
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Begrotingspost</title>
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
    .btn{display:inline-block;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.10);font-weight:800}
    .btn:hover{background:rgba(255,255,255,.16)}
    .alert{border-radius:14px;padding:10px 12px;border:1px solid rgba(255,255,255,.22);margin-bottom:12px;background:rgba(0,0,0,.22)}
    label{display:block;color:rgba(255,255,255,.86);font-size:12px;margin:2px 0 6px}
    input,select,textarea{width:100%;padding:10px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(0,0,0,.20);color:#fff;outline:none;box-sizing:border-box}
    option{color:#000}
    textarea{min-height:110px}
    .grid2{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px}
    @media(max-width:800px){.grid2{grid-template-columns:1fr}}
    .row{margin-bottom:12px}
    .rowflex{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
  </style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">
    <div class="topbar">
      <div class="brand"><h1>Porbeheer</h1><div class="sub">POP Oefenruimte Zevenaar • begrotingspost</div></div>
      <div class="userbox">
        <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · rol <?= h($role) ?></div>
        <div class="line2"><a href="/admin/budget.php">Begroting</a><a href="/admin/finance.php">Financiën</a><a href="/logout.php">Uitloggen</a></div>
      </div>
    </div>

    <div class="panel">
      <?php if ($err): ?><div class="alert">⚠ <?= h($err) ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="alert">✅ <?= h($msg) ?></div><?php endif; ?>

      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="grid2">
          <div class="row">
            <label>Naam</label>
            <input type="text" name="name" value="<?= h($row['name']) ?>" required>
          </div>
          <div class="row">
            <label>Type</label>
            <select name="kind">
              <option value="income" <?= ($row['kind']==='income'?'selected':'') ?>>income</option>
              <option value="expense" <?= ($row['kind']==='expense'?'selected':'') ?>>expense</option>
              <option value="both" <?= ($row['kind']==='both'?'selected':'') ?>>both</option>
            </select>
          </div>
          <div class="row">
            <label>Jaarbedrag (target)</label>
            <input type="text" name="annual_amount" value="<?= h((string)$row['annual_amount']) ?>">
          </div>
          <div class="row">
            <label>Status</label>
            <label style="display:flex;gap:10px;align-items:center;margin-top:10px;">
              <input type="checkbox" name="is_active" <?= ((int)$row['is_active']===1?'checked':'') ?> style="width:auto;">
              Actief (zichtbaar in keuzes)
            </label>
          </div>
        </div>

        <div class="row">
          <label>Notities</label>
          <textarea name="notes"><?= h((string)($row['notes'] ?? '')) ?></textarea>
        </div>

        <div class="rowflex">
          <button class="btn">Opslaan</button>
          <a class="btn" href="/admin/budget.php">Terug</a>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>