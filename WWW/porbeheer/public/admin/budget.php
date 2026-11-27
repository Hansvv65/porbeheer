<?php
declare(strict_types=1);

/*
 * budget.php
 * Beheer begrotingsposten (admin)
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireSectionAccess('financien', 'read');

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('finance', $pdo);

/**
 * Bereken de verwachte maandelijkse inkomsten uit abonnementen voor de huidige maand.
 */
function calculateMonthlySubscriptionIncome(PDO $pdo, string $monthStart, string $monthEnd): float
{
    $st = $pdo->prepare("
        SELECT SUM(bc.monthly_fee) AS total_monthly_income
        FROM band_contracts bc
        JOIN bands b ON b.id = bc.band_id
        WHERE bc.contract_type = 'ABONNEMENT'
          AND bc.start_date <= :monthEnd
          AND bc.end_date >= :monthStart
          AND b.deleted_at IS NULL
    ");
    $st->execute([':monthStart' => $monthStart, ':monthEnd' => $monthEnd]);
    $result = $st->fetch();
    return (float)($result['total_monthly_income'] ?? 0);
}

auditLog($pdo, 'PAGE_VIEW', 'admin/budget.php');

// Bereken de maandelijkse abonnementsinkomsten voor de huidige maand
$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$monthEnd = $today->modify('last day of this month')->format('Y-m-d');
$monthlySubscriptionIncome = calculateMonthlySubscriptionIncome($pdo, $monthStart, $monthEnd);

// Bereken de jaarlijkse abonnementsinkomsten
$annualSubscriptionIncome = $monthlySubscriptionIncome * 12;

// Haal de bestaande begrotingsposten op
$rows = $pdo->query("
  SELECT id, name, kind, annual_amount, is_active
  FROM budget_items
  WHERE deleted_at IS NULL
  ORDER BY is_active DESC, kind, name
")->fetchAll();

// Voeg de abonnementsinkomsten toe aan de lijst met begrotingsposten
$subscriptionBudgetItem = [
    'id' => 0,
    'name' => 'Abonnementsinkomsten',
    'kind' => 'income',
    'annual_amount' => $annualSubscriptionIncome,
    'is_active' => 1,
];

// Voeg de abonnementsinkomsten toe aan de bestaande lijst
$rows[] = $subscriptionBudgetItem;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Begroting</title>
  <link rel="stylesheet" href="/assets/css/main.css">
<style>
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);min-width:280px;}
.userbox .line2{color:var(--muted);margin-top:6px;font-size:13px;display:flex;gap:10px;flex-wrap:wrap;}
.btn{display:inline-block;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.10);font-weight:800}
.btn:hover{background:rgba(255,255,255,.16)}
.rowflex{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px;color:#fff}
.table th,.table td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.16);vertical-align:top}
.table th{text-align:left;color:rgba(255,255,255,.85);font-size:12px;letter-spacing:.2px}
.pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.9);white-space:nowrap;}
</style>
</head>
<body style="background-image:url('<?= h($bg) ?>')">
<div class="backdrop">
  <div class="wrap">
    <div class="topbar">
      <div class="brand"><h1><a href="/admin/dashboard.php">Porbeheer</a></h1><div class="sub">POP Oefenruimte Zevenaar • begroting</div></div>
      <div class="userbox">
        <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · rol <?= h($role) ?></div>
        <div class="line2"><a href="/admin/finance.php">Financiën</a><a href="/admin/dashboard.php">Dashboard</a><a href="/logout.php">Uitloggen</a></div>
      </div>
    </div>

    <div class="panel">
      <div class="rowflex">
        <div>
          <span class="pill">Begrotingsposten</span>
          <span style="color:var(--muted);margin-left:10px;">beheer je jaarlijkse targets per post</span>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="btn" href="/admin/budget_edit.php">+ Nieuwe post</a>
          <a class="btn" href="/admin/finance.php">Terug</a>
        </div>
      </div>

      <table class="table">
        <thead>
          <tr><th>Naam</th><th>Type</th><th>Jaarbedrag</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['name']) ?></td>
            <td><span class="pill"><?= h($r['kind']) ?></span></td>
            <td>€ <?= number_format((float)$r['annual_amount'], 2, ',', '.') ?></td>
            <td><?= ((int)$r['is_active']===1 ? 'actief' : 'inactief') ?></td>
            <td>
              <?php if ($r['id'] !== 0 && hasSectionAccess($pdo, 'financien', 'write')): ?>
                <a class="btn" href="/admin/budget_edit.php?id=<?= (int)$r['id'] ?>">Bewerken</a>
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