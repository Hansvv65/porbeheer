<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: /admin/bands.php");
    exit;
}

auditLog($pdo, 'PAGE_VIEW', 'admin/band_detail.php id=' . $id);

$stmt = $pdo->prepare("
    SELECT b.*,
           pc.name AS primary_name,
           sc.name AS secondary_name
    FROM bands b
    LEFT JOIN contacts pc ON pc.id = b.primary_contact_id AND pc.deleted_at IS NULL
    LEFT JOIN contacts sc ON sc.id = b.secondary_contact_id AND sc.deleted_at IS NULL
    WHERE b.id = ? AND b.deleted_at IS NULL
");
$stmt->execute([$id]);
$band = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$band) {
    header("Location: /admin/bands.php");
    exit;
}

/* Porkasten (nieuwe tabel lockers) */
$lockers = $pdo->prepare("
    SELECT locker_no
    FROM lockers
    WHERE band_id = ? AND deleted_at IS NULL
    ORDER BY locker_no
");
$lockers->execute([$id]);
$lockerList = $lockers->fetchAll(PDO::FETCH_ASSOC);

/**
 * Sleutels (laatste uitgiftes)
 * We tonen actieve uitgiftes: ISSUE zonder latere RETURN (voor dezelfde band+key).
 * + tonen kastnummer en sleutel-exemplaar (1/2)
 * + filter op soft-deleted sleutel/locker
 */
$keys = $pdo->prepare("
    SELECT
      k.key_code,
      k.description,
      k.key_slot,
      l.locker_no,
      kt.action_at,
      c.name AS contact_name
    FROM key_transactions kt
    JOIN `keys` k ON k.id = kt.key_id AND k.deleted_at IS NULL
    JOIN lockers l ON l.id = k.locker_id AND l.deleted_at IS NULL
    LEFT JOIN contacts c ON c.id = kt.contact_id AND c.deleted_at IS NULL
    WHERE kt.band_id = ?
      AND kt.action = 'ISSUE'
      AND NOT EXISTS (
        SELECT 1
        FROM key_transactions r
        WHERE r.band_id = kt.band_id
          AND r.key_id = kt.key_id
          AND r.action = 'RETURN'
          AND r.action_at > kt.action_at
      )
    ORDER BY kt.action_at DESC
    LIMIT 30
");
$keys->execute([$id]);
$keyList = $keys->fetchAll(PDO::FETCH_ASSOC);

/* Komende 4 dagdelen (schedule + contract events) */
$slots = $pdo->prepare("
    SELECT *
    FROM (
        SELECT
            'SCHEDULE' AS src,
            s.date     AS date,
            s.timeslot AS timeslot
        FROM schedule s
        WHERE s.band_id = ?
          AND s.date >= CURDATE()

        UNION ALL

        SELECT
            'CONTRACT'     AS src,
            e.event_date   AS date,
            e.daypart      AS timeslot
        FROM band_planner_events e
        WHERE e.band_id = ?
          AND e.event_date >= CURDATE()
    ) x
    ORDER BY
      x.date ASC,
      FIELD(x.timeslot,'OCHTEND','MIDDAG','AVOND') ASC,
      FIELD(x.src,'SCHEDULE','CONTRACT') ASC
    LIMIT 12
");
$slots->execute([$id, $id]);
$rows = $slots->fetchAll(PDO::FETCH_ASSOC);

/* Deduplicatie: schedule wint van contract op dezelfde date+timeslot */
$slotList = [];
$seen = [];
foreach ($rows as $r) {
    $k = $r['date'] . '|' . $r['timeslot'];
    if (!isset($seen[$k])) {
        $seen[$k] = $r['src'];
        $slotList[] = $r;
        continue;
    }
    if ($seen[$k] === 'CONTRACT' && $r['src'] === 'SCHEDULE') {
        $seen[$k] = 'SCHEDULE';
        foreach ($slotList as $i => $existing) {
            if (($existing['date'] . '|' . $existing['timeslot']) === $k) {
                $slotList[$i] = $r;
                break;
            }
        }
    }
}
$slotList = array_slice($slotList, 0, 4);

/* Contract */
$contract = $pdo->prepare("
    SELECT *
    FROM band_contracts
    WHERE band_id = ?
    ORDER BY end_date DESC
    LIMIT 1
");
$contract->execute([$id]);
$contractRow = $contract->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Band detail - Porbeheer</title>

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
  background:url('/assets/images/bands-a.png') no-repeat center center fixed;
  background-size:cover;
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
.wrap{width:min(1100px,96vw);}
.topbar{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
  margin-bottom:14px;
}
.brand h1{margin:0;font-size:28px;letter-spacing:.5px;}
.brand .sub{margin-top:6px;font-size:14px;}
.brand .sub a{color:#fff;text-decoration:none;margin-right:14px;}
.brand .sub a:hover{color:#ffd9b3}
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
.userbox .line1{font-weight:bold}
.userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
.userbox a{color:#fff;text-decoration:none}
.userbox a:hover{color:#ffd9b3}

.panel{
  border-radius:20px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  padding:24px;
}

.grid{display:grid;grid-template-columns: 1fr 1fr;gap:14px;}
@media (max-width: 920px){ .grid{grid-template-columns:1fr;} }

.card{
  border-radius:16px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));
  box-shadow:0 10px 22px rgba(0,0,0,.30);
  backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);
  padding:14px;
}
.card h2{margin:0 0 10px 0;font-size:18px;}

.badge{
  display:inline-block;
  padding:3px 8px;
  background:rgba(255,255,255,.10);
  border:1px solid rgba(255,255,255,.18);
  border-radius:999px;
  margin-right:6px;
  font-size:12px;
}
.small{font-size:13px;color:var(--muted)}
hr{border:none;border-top:1px solid rgba(255,255,255,.12);margin:10px 0}
a{color:#fff;text-decoration:none}
a:hover{color:#ffd9b3}
</style>
</head>
<body>

<div class="backdrop">
<div class="wrap">

  <div class="topbar">
    <div class="brand">
      <h1><?= h($band['name']) ?></h1>
      <div class="sub">
        <a href="/admin/bands.php">Overzicht</a>
        <a href="/admin/band_edit.php?id=<?= (int)$id ?>">Bewerken</a>
        <a href="/admin/band_keys.php?band_id=<?= (int)$id ?>">Sleutelbeheer</a>
      </div>
    </div>

    <div class="userbox">
      <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div class="line2">
        <a href="/admin/dashboard.php">Dashboard</a> ·
        <a href="/logout.php">Uitloggen</a>
      </div>
    </div>
  </div>

  <div class="panel">

    <div class="grid">

      <div class="card">
        <h2>Contacten</h2>
        <div><span class="badge">1e</span> <?= h($band['primary_name'] ?? '—') ?></div>
        <div style="margin-top:8px;"><span class="badge">2e</span> <?= h($band['secondary_name'] ?? '—') ?></div>

        <hr>
        <div class="small">
          (IBAN, email en telefoon komen straks uit contact-detail; nu staat dit alleen bij contacts.)
        </div>
      </div>

      <div class="card">
        <h2>Porkasten</h2>
        <?php if ($lockerList): ?>
          <?php foreach($lockerList as $l): ?>
            <span class="badge">Kast <?= h((string)$l['locker_no']) ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="small">Geen kast toegewezen.</div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Sleutels (actief uitgegeven)</h2>
        <?php if ($keyList): ?>
          <?php foreach($keyList as $k): ?>
            <div style="margin-bottom:10px;">
              <strong><?= h((string)$k['key_code']) ?></strong>
              <div class="small">
                Kast <?= h((string)$k['locker_no']) ?> · sleutel <?= h((string)$k['key_slot']) ?>
                <?php if (!empty($k['description'])): ?>
                  · <?= h((string)$k['description']) ?>
                <?php endif; ?>
              </div>
              <div class="small">
                aan: <?= h((string)($k['contact_name'] ?? '—')) ?> · <?= h((string)$k['action_at']) ?>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="small"><a href="/admin/band_keys.php?band_id=<?= (int)$id ?>">→ Naar sleutelbeheer</a></div>
        <?php else: ?>
          <div class="small">Geen sleutels actief uitgegeven.</div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Komende 4 dagdelen</h2>
        <?php if ($slotList): ?>
          <?php foreach($slotList as $s): ?>
            <div style="margin-bottom:6px;">
              <strong><?= h((string)$s['date']) ?></strong> — <?= h((string)$s['timeslot']) ?>
              <?php if (($s['src'] ?? '') === 'CONTRACT'): ?>
                <span class="small"> (contract)</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <div class="small"><a href="/admin/planning.php">→ Planning bekijken</a></div>
        <?php else: ?>
          <div class="small">Geen komende reserveringen.</div>
        <?php endif; ?>
      </div>

      <div class="card" style="grid-column:1 / -1;">
        <h2>Contract</h2>
        <?php if ($contractRow): ?>
          <div><span class="badge">Type</span> <?= h((string)($contractRow['contract_type'] ?? '—')) ?></div>
          <div style="margin-top:8px;"><span class="badge">Tot</span> <?= h((string)($contractRow['end_date'] ?? '—')) ?></div>
          <?php if (array_key_exists('monthly_fee', $contractRow)): ?>
            <div style="margin-top:8px;"><span class="badge">Maandbedrag</span> <?= h((string)$contractRow['monthly_fee']) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="small">Geen contract gevonden.</div>
        <?php endif; ?>
      </div>

    </div>

  </div>

</div>
</div>

</body>
</html>