<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('bands', $pdo);

if (!function_exists('h')) {
    function h(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

auditLog($pdo, 'PAGE_VIEW', 'admin/bands.php');

$err = null;
$msg = null;

$qmsg = (string)($_GET['msg'] ?? '');
if ($qmsg === 'saved') {
    $msg = 'Band opgeslagen.';
} elseif ($qmsg === 'deleted') {
    $msg = 'Band verwijderd.';
} elseif ($qmsg !== '') {
    $msg = $qmsg;
}

$daypartLabels = [
    'OCHTEND' => 'Ochtend (11:00 - 15:00)',
    'MIDDAG'  => 'Middag (15:00 - 19:00)',
    'AVOND'   => 'Avond (19:00 - 23:00)',
];

$weekdayNames = [
    1 => 'Maandag',
    2 => 'Dinsdag',
    3 => 'Woensdag',
    4 => 'Donderdag',
    5 => 'Vrijdag',
    6 => 'Zaterdag',
    7 => 'Zondag',
];

try {
    $bands = $pdo->query("
      SELECT
        b.id,
        b.name,
        b.city,
        pc.name  AS primary_name,
        pc.phone AS primary_phone
      FROM bands b
      LEFT JOIN contacts pc ON pc.id = b.primary_contact_id AND pc.deleted_at IS NULL
      WHERE b.deleted_at IS NULL
      ORDER BY b.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $bandIds = array_map(static fn($r) => (int)$r['id'], $bands);

    $byBand = [];
    foreach ($bands as $b) {
        $bid = (int)$b['id'];
        $byBand[$bid] = [
            'lockers'       => [],
            'contract_type' => '',
            'repeat_text'   => '',
            'issued_keys'   => 0,
        ];
    }

    $in = static function (array $ids): string {
        return implode(',', array_fill(0, count($ids), '?'));
    };

    if ($bandIds) {
        /*
         * Lockers per band
         */
        $stL = $pdo->prepare("
          SELECT id, band_id, locker_no, notes
          FROM lockers
          WHERE deleted_at IS NULL
            AND band_id IN (" . $in($bandIds) . ")
          ORDER BY band_id, locker_no
        ");
        $stL->execute($bandIds);
        $lockers = $stL->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lockers as $l) {
            $bid = (int)$l['band_id'];
            $lid = (int)$l['id'];

            if (!isset($byBand[$bid])) {
                continue;
            }

            $byBand[$bid]['lockers'][$lid] = [
                'id'        => $lid,
                'locker_no' => (string)$l['locker_no'],
                'notes'     => (string)($l['notes'] ?? ''),
            ];
        }

        /*
         * Actueel contract per band
         * Let op: hier gebruiken we MIN(sortkey), niet MAX.
         * 0 = open/einddatum NULL, 1 = lopend, 2 = verlopen
         */
        $stC = $pdo->prepare("
          SELECT c.*
          FROM band_contracts c
          JOIN (
            SELECT
              band_id,
              MIN(
                CONCAT(
                  CASE
                    WHEN end_date IS NULL THEN '0'
                    WHEN end_date >= CURDATE() THEN '1'
                    ELSE '2'
                  END,
                  '|',
                  COALESCE(DATE_FORMAT(end_date, '%Y-%m-%d'), '9999-12-31'),
                  '|',
                  LPAD(id, 10, '0')
                )
              ) AS sortkey
            FROM band_contracts
            WHERE band_id IN (" . $in($bandIds) . ")
            GROUP BY band_id
          ) x
            ON x.band_id = c.band_id
           AND CONCAT(
                CASE
                  WHEN c.end_date IS NULL THEN '0'
                  WHEN c.end_date >= CURDATE() THEN '1'
                  ELSE '2'
                END,
                '|',
                COALESCE(DATE_FORMAT(c.end_date, '%Y-%m-%d'), '9999-12-31'),
                '|',
                LPAD(c.id, 10, '0')
              ) = x.sortkey
        ");
        $stC->execute($bandIds);
        $contracts = $stC->fetchAll(PDO::FETCH_ASSOC);

        $contractByBand = [];
        $contractIdToBand = [];

        foreach ($contracts as $c) {
            $bid = (int)$c['band_id'];
            $cid = (int)$c['id'];

            $contractByBand[$bid] = $c;
            $contractIdToBand[$cid] = $bid;

            if (isset($byBand[$bid])) {
                $byBand[$bid]['contract_type'] = (string)($c['contract_type'] ?? '');
            }
        }

        /*
         * Repeterend dagdeel per actueel contract
         */
        if ($contractIdToBand) {
            $contractIds = array_keys($contractIdToBand);

            $stR = $pdo->prepare("
              SELECT e.contract_id, e.daypart, e.event_date
              FROM band_planner_events e
              JOIN (
                SELECT
                  contract_id,
                  MIN(
                    CONCAT(
                      CASE WHEN event_date >= CURDATE() THEN '0' ELSE '1' END,
                      '|',
                      DATE_FORMAT(event_date, '%Y-%m-%d'),
                      '|',
                      LPAD(id, 10, '0')
                    )
                  ) AS sortkey
                FROM band_planner_events
                WHERE contract_id IN (" . $in($contractIds) . ")
                GROUP BY contract_id
              ) x
                ON x.contract_id = e.contract_id
               AND CONCAT(
                    CASE WHEN e.event_date >= CURDATE() THEN '0' ELSE '1' END,
                    '|',
                    DATE_FORMAT(e.event_date, '%Y-%m-%d'),
                    '|',
                    LPAD(e.id, 10, '0')
                  ) = x.sortkey
            ");
            $stR->execute($contractIds);
            $repeatRows = $stR->fetchAll(PDO::FETCH_ASSOC);

            foreach ($repeatRows as $r) {
                $cid = (int)$r['contract_id'];
                $bid = $contractIdToBand[$cid] ?? 0;

                if ($bid <= 0 || !isset($byBand[$bid])) {
                    continue;
                }

                $weekdayNo = (int)(new DateTimeImmutable((string)$r['event_date']))->format('N');
                $weekday   = $weekdayNames[$weekdayNo] ?? '';
                $daypart   = $daypartLabels[(string)$r['daypart']] ?? (string)$r['daypart'];

                $byBand[$bid]['repeat_text'] = trim($weekday . ' · ' . $daypart, ' ·');
            }
        }

        /*
         * Actief uitgegeven sleutels:
         * alleen de LAATSTE transactie per key telt.
         * Als die laatste transactie ISSUE is, dan is die sleutel actief uitgegeven.
         */
        $stIssued = $pdo->prepare("
          SELECT kt.band_id, COUNT(*) AS issued_count
          FROM key_transactions kt
          JOIN (
            SELECT
              key_id,
              MAX(
                CONCAT(
                  DATE_FORMAT(action_at, '%Y-%m-%d %H:%i:%s'),
                  '|',
                  LPAD(id, 10, '0')
                )
              ) AS sortkey
            FROM key_transactions
            GROUP BY key_id
          ) x
            ON x.key_id = kt.key_id
           AND CONCAT(
                DATE_FORMAT(kt.action_at, '%Y-%m-%d %H:%i:%s'),
                '|',
                LPAD(kt.id, 10, '0')
              ) = x.sortkey
          WHERE kt.band_id IN (" . $in($bandIds) . ")
            AND kt.action = 'ISSUE'
          GROUP BY kt.band_id
        ");
        $stIssued->execute($bandIds);
        $issuedRows = $stIssued->fetchAll(PDO::FETCH_ASSOC);

        foreach ($issuedRows as $r) {
            $bid = (int)$r['band_id'];
            if (isset($byBand[$bid])) {
                $byBand[$bid]['issued_keys'] = (int)$r['issued_count'];
            }
        }
    }
} catch (Throwable $e) {
    $bands = [];
    $byBand = [];
    $err = $e->getMessage();
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Bands</title>
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
  background:url('<?= h($bg) ?>') no-repeat center center fixed;
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
.wrap{width:min(1280px,96vw);}
.topbar{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
  margin-bottom:14px;
}
.brand h1{margin:0;font-size:28px;letter-spacing:.5px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}
.brand .sub a{color:#fff;text-decoration:none}
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
.card{
  border-radius:16px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));
  padding:14px;
  box-shadow:0 10px 22px rgba(0,0,0,.30);
  backdrop-filter:blur(10px);
  margin-bottom:14px;
}
h2{margin:0 0 8px 0;font-size:18px}
.small{font-size:13px;color:var(--muted)}
.tablewrap{overflow:auto;border-radius:14px;border:1px solid rgba(255,255,255,.12)}
table{width:100%;border-collapse:collapse}
th,td{
  padding:10px;
  border-bottom:1px solid rgba(255,255,255,.12);
  text-align:left;
  font-size:14px;
  vertical-align:top
}
th{background:rgba(255,255,255,.06)}
a{color:#fff;text-decoration:none;transition:color .15s ease}
a:hover{color:#ffd9b3}
a:visited{color:#ffe0c2}
.msg{
  margin:10px 0;
  font-size:13px;
  padding:10px 12px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.08)
}
.err{color:#ffb3b3}
.btn{
  display:inline-block;
  padding:10px 14px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));
  color:#fff;
  font-weight:800;
  cursor:pointer
}
.btn:hover{border-color:rgba(255,255,255,.38);transform:translateY(-1px)}
.bandlink{
  font-weight:bold;
  font-size:15px;
}
.meta{
  display:flex;
  flex-direction:column;
  gap:4px;
}
.badge{
  display:inline-block;
  padding:3px 8px;
  border-radius:999px;
  font-size:12px;
  border:1px solid rgba(255,255,255,.22);
  background:rgba(255,255,255,.08)
}
</style>
</head>
<body>
<div class="backdrop"><div class="wrap">

  <div class="topbar">
    <div class="brand">
      <h1>Bands</h1>
      <div class="sub">
        <a href="/admin/band_keys.php">Sleutels en Kasten</a>
        <?php if (in_array($role, ['ADMIN','BEHEER'], true)): ?>
          • <a href="/admin/band_edit.php">Nieuwe Band</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="userbox">
      <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div class="line2"><a href="/admin/dashboard.php">Dashboard</a> • <a href="/logout.php">Uitloggen</a></div>
    </div>
  </div>

  <div class="panel">
    <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

    <div class="card">
      <h2>Alle bands</h2>
      <div class="small"><?= count($bands) ?> bands</div>

      <div class="tablewrap" style="margin-top:12px">
        <table>
          <thead>
            <tr>
              <th>Band</th>
              <th>1e contact</th>
              <th>Contract</th>
              <th>Repeterend dagdeel</th>
              <th>Porkast</th>
              <th>Uitgegeven sleutels</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$bands): ?>
            <tr><td colspan="6" class="small">Geen bands gevonden.</td></tr>
          <?php else: foreach ($bands as $b): ?>
            <?php
              $bid = (int)$b['id'];
              $pack = $byBand[$bid] ?? [
                  'lockers' => [],
                  'contract_type' => '',
                  'repeat_text' => '',
                  'issued_keys' => 0,
              ];

              $lockerNames = [];
              foreach ($pack['lockers'] as $l) {
                  $lockerNames[] = $l['locker_no'];
              }

              $lockerText = $lockerNames ? implode(', ', $lockerNames) : '—';
              $contractText = $pack['contract_type'] !== '' ? $pack['contract_type'] : '—';
              $repeatText = $pack['repeat_text'] !== '' ? $pack['repeat_text'] : '—';
              $issuedKeys = (int)$pack['issued_keys'];
            ?>
            <tr>
              <td>
                <div class="meta">
                  <a class="bandlink" href="/admin/band_detail.php?id=<?= $bid ?>"><?= h((string)$b['name']) ?></a>
                  <div class="small"><?= h((string)($b['city'] ?? '')) ?></div>
                </div>
              </td>

              <td>
                <div class="meta">
                  <div><?= h((string)($b['primary_name'] ?? '—')) ?></div>
                  <div class="small"><?= h((string)($b['primary_phone'] ?? '—')) ?></div>
                </div>
              </td>

              <td><?= h($contractText) ?></td>
              <td><?= h($repeatText) ?></td>
              <td><?= h($lockerText) ?></td>
              <td><span class="badge"><?= $issuedKeys ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</div></div>
</body>
</html>