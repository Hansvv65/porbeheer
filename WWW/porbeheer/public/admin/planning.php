<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('schedule', $pdo);

auditLog($pdo, 'PAGE_VIEW', 'admin/planning.php');

/*
|--------------------------------------------------------------------------
| 2-weken navigatie
|--------------------------------------------------------------------------
*/
$offset = (int)($_GET['offset'] ?? 0);

$today = new DateTimeImmutable('today');
$weekStart = $today->modify('monday this week')->modify(($offset * 7) . ' days');
$rangeStart = $weekStart;
$rangeEnd   = $weekStart->modify('+13 days');

$prevOffset = $offset - 1;
$nextOffset = $offset + 1;

/**
 * Check of band_planner_events bestaat
 */
$hasContractEvents = false;
try {
    $chk = $pdo->query("SHOW TABLES LIKE 'band_planner_events'");
    $hasContractEvents = (bool)$chk->fetchColumn();
} catch (Throwable $e) {
    $hasContractEvents = false;
}

/**
 * 1) schedule (handmatig / bestaand) – haal nu ook start_time en end_time op
 */
$stmt = $pdo->prepare("
  SELECT
    'SCHEDULE'   AS src,
    s.id         AS item_id,
    s.date       AS date,
    s.timeslot   AS timeslot,
    s.start_time AS start_time,
    s.end_time   AS end_time,
    b.id         AS band_id,
    b.name       AS band_name
  FROM schedule s
  JOIN bands b ON b.id = s.band_id
  WHERE s.date BETWEEN ? AND ?
    AND b.deleted_at IS NULL
");
$stmt->execute([$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')]);
$data = $stmt->fetchAll();

/**
 * 2) band_planner_events (contract) – deze hebben geen handmatige tijden
 */
if ($hasContractEvents) {
    $stmt = $pdo->prepare("
      SELECT
        'CONTRACT'   AS src,
        e.id         AS item_id,
        e.event_date AS date,
        e.daypart    AS timeslot,
        NULL         AS start_time,
        NULL         AS end_time,
        b.id         AS band_id,
        b.name       AS band_name
      FROM band_planner_events e
      JOIN bands b ON b.id = e.band_id
      WHERE e.event_date BETWEEN ? AND ?
        AND b.deleted_at IS NULL
    ");
    $stmt->execute([$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')]);
    $data = array_merge($data, $stmt->fetchAll());
}

/**
 * $bookings[date][timeslot] = row
 * schedule wint van contract
 */
$bookings = [];
foreach ($data as $row) {
    $d  = $row['date'];
    $ts = $row['timeslot'];

    if (!isset($bookings[$d][$ts])) {
        $bookings[$d][$ts] = $row;
        continue;
    }

    if (($bookings[$d][$ts]['src'] ?? '') === 'CONTRACT' && ($row['src'] ?? '') === 'SCHEDULE') {
        $bookings[$d][$ts] = $row;
    }
}

$dagenVoluit = [
    1 => 'maandag',
    2 => 'dinsdag',
    3 => 'woensdag',
    4 => 'donderdag',
    5 => 'vrijdag',
    6 => 'zaterdag',
    7 => 'zondag',
];

$maandenVoluit = [
    1 => 'januari',
    2 => 'februari',
    3 => 'maart',
    4 => 'april',
    5 => 'mei',
    6 => 'juni',
    7 => 'juli',
    8 => 'augustus',
    9 => 'september',
    10 => 'oktober',
    11 => 'november',
    12 => 'december',
];

// Standaard bloktijden – worden gebruikt als er geen handmatige tijd is
$defaultTimes = [
    'OCHTEND' => '11:00 - 15:00',
    'MIDDAG'  => '15:00 - 19:00',
    'AVOND'   => '19:00 - 23:00',
];

function weekTitle(DateTimeImmutable $monday, array $maandenVoluit): string
{
    $sunday = $monday->modify('+6 days');

    $startDay   = (int)$monday->format('j');
    $startMonth = (int)$monday->format('n');
    $startYear  = (int)$monday->format('Y');

    $endDay     = (int)$sunday->format('j');
    $endMonth   = (int)$sunday->format('n');
    $endYear    = (int)$sunday->format('Y');

    return 'Week ' . $monday->format('W') . ' · '
        . $startDay . ' ' . $maandenVoluit[$startMonth] . ' ' . $startYear
        . ' t/m '
        . $endDay . ' ' . $maandenVoluit[$endMonth] . ' ' . $endYear;
}

$headerTitle = weekTitle($weekStart, $maandenVoluit) . ' + ' . weekTitle($weekStart->modify('+7 days'), $maandenVoluit);
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Planning - Porbeheer</title>

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

.wrap{width:min(1400px,96vw);}

.topbar{
display:flex;
align-items:flex-end;
justify-content:space-between;
gap:16px;
flex-wrap:wrap;
margin-bottom:14px;
}

.brand h1{margin:0;font-size:28px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}

.userbox{
background:var(--glass);
border:1px solid var(--border);
border-radius:14px;
padding:12px 14px;
box-shadow:var(--shadow);
backdrop-filter:blur(10px);
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
padding:18px;
}

.nav{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:15px;
flex-wrap:wrap;
gap:10px;
}

.navleft{
display:flex;
flex-wrap:wrap;
gap:8px;
}

.btn{
text-decoration:none;
color:#fff;
font-weight:bold;
padding:8px 12px;
border-radius:12px;
border:1px solid rgba(255,255,255,.22);
background:linear-gradient(180deg,var(--glass),var(--glass2));
}

.btn:hover{border-color:rgba(255,255,255,.38)}

.calendar{
display:grid;
grid-template-columns:repeat(7, minmax(0,1fr));
gap:10px;
}

.weekrow{
grid-column:1 / -1;
padding:10px 12px;
border-radius:14px;
border:1px solid rgba(255,255,255,.18);
background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
font-weight:bold;
letter-spacing:.2px;
color:rgba(255,255,255,.92);
}

.day{
border-radius:16px;
border:1px solid rgba(255,255,255,.18);
background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));
padding:10px;
min-height:155px;
position:relative;
}

.datefull{
font-weight:bold;
margin-bottom:8px;
padding-bottom:6px;
border-bottom:1px solid rgba(255,255,255,.12);
font-size:13px;
color:rgba(255,255,255,.92);
}

/* SLOT */
a.slot{
display:flex;
justify-content:space-between;
flex-direction:column;
align-items:flex-start;
gap:4px;
padding:8px 9px;
border-radius:12px;
background:rgba(0,0,0,.22);
border:1px solid rgba(255,255,255,.08);
text-decoration:none;
color:#fff;
margin-bottom:6px;
min-height:54px;
}

a.slot:hover{
border-color:rgba(255,255,255,.35);
background:rgba(255,255,255,.08);
}

a.slot .ts{
font-size:11px;
letter-spacing:.2px;
color:rgba(255,255,255,.82);
padding:3px 8px;
border-radius:999px;
background:rgba(255,255,255,.10);
border:1px solid rgba(255,255,255,.10);
flex:0 0 auto;
}

a.slot .val{
font-size:13px;
font-weight:bold;
width:100%;
display:-webkit-box;
-webkit-line-clamp:2;
-webkit-box-orient:vertical;
overflow:hidden;
}

a.slot.empty .val{
color:rgba(255,255,255,.72);
font-weight:bold;
}

a.slot.booked .val{
color:rgba(255,255,255,.95);
}

a.slot.contract{
border-color:rgba(255,255,255,.16);
background:rgba(0,0,0,.18);
}

.day.today{
border:1px solid rgba(255, 217, 179, .65);
background:
linear-gradient(180deg,
rgba(255,217,179,.22),
rgba(255,255,255,.08)
);
box-shadow:
0 0 0 1px rgba(255,217,179,.25),
0 0 18px rgba(255,217,179,.18),
var(--shadow);
}

.todayBadge{
display:inline-block;
margin-left:8px;
padding:2px 8px;
border-radius:999px;
font-size:11px;
font-weight:bold;
background:rgba(255,217,179,.22);
border:1px solid rgba(255,217,179,.35);
color:#fff2de;
}

a{color:#fff;text-decoration:none;transition:color .15s ease}
a:hover{color:#ffd9b3}
a:visited{color:#ffe0c2}

@media (max-width: 900px){
  .calendar{
    grid-template-columns:repeat(2, minmax(0,1fr));
  }
}

@media (max-width: 640px){
  .calendar{
    grid-template-columns:1fr;
  }
}
</style>
</head>
<body>

<div class="backdrop">
<div class="wrap">

<div class="topbar">
  <div class="brand">
    <h1>Planning</h1>
    <div class="sub">Twee weken overzicht</div>
  </div>

  <div class="userbox">
    <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
    <div class="line2"><a href="/admin/dashboard.php">Dashboard</a> • <a href="/logout.php">Uitloggen</a></div>
  </div>
</div>

<div class="panel">

  <div class="nav">
    <div class="navleft">
      <a class="btn" href="?offset=<?= h((string)$prevOffset) ?>">← Vorige</a>
      <a class="btn" href="?offset=0">Huidige</a>
      <a class="btn" href="?offset=<?= h((string)$nextOffset) ?>">Volgende →</a>
      <a class="btn" href="?offset=0#today">Vandaag</a>
    </div>
    <strong><?= h($headerTitle) ?></strong>
  </div>

  <div class="calendar">
  <?php
  $cursor = $rangeStart;

  while ($cursor <= $rangeEnd):

      $weekNo = $cursor->format('W');
      $weekStartLabel = $cursor;
      $weekEndLabel = $cursor->modify('+6 days');
      ?>
      <div class="weekrow">
        Week <?= h($weekNo) ?> ·
        <?= h($weekStartLabel->format('j')) ?> <?= h($maandenVoluit[(int)$weekStartLabel->format('n')]) ?>
        t/m
        <?= h($weekEndLabel->format('j')) ?> <?= h($maandenVoluit[(int)$weekEndLabel->format('n')]) ?> <?= h($weekEndLabel->format('Y')) ?>
      </div>
      <?php

      for ($i = 0; $i < 7; $i++):
          $dateStr = $cursor->format('Y-m-d');
          $isToday = ($dateStr === $today->format('Y-m-d'));

          $dayNr   = (int)$cursor->format('N');
          $day     = (int)$cursor->format('j');
          $monthNr = (int)$cursor->format('n');

          $fullLabel = ucfirst($dagenVoluit[$dayNr] . ' ' . $day . ' ' . $maandenVoluit[$monthNr]);
          ?>
          <div class="day <?= $isToday ? 'today' : '' ?>" <?= $isToday ? 'id="today"' : '' ?>>
              <div class="datefull">
                <?= h($fullLabel) ?>
                <?php if ($isToday): ?><span class="todayBadge">Vandaag</span><?php endif; ?>
              </div>

              <?php foreach ($defaultTimes as $ts => $defaultTimeLabel):
                  $booking = $bookings[$dateStr][$ts] ?? null;

                  // Bepaal de weer te geven tijd
                  $timeDisplay = $defaultTimeLabel; // standaard bloktijd

                  if ($booking && $booking['src'] === 'SCHEDULE' && !empty($booking['start_time']) && !empty($booking['end_time'])) {
                      // Er is een handmatige tijd ingesteld → toon deze
                      $start = substr($booking['start_time'], 0, 5);
                      $end   = substr($booking['end_time'], 0, 5);
                      $timeDisplay = $start . ' – ' . $end;
                  }

                  // Bepaal link en visuele stijl
                  if ($booking) {
                      if ($booking['src'] === 'SCHEDULE') {
                          $href  = "/admin/planning_edit.php?id=" . urlencode((string)$booking['item_id']);
                          $cls   = "slot booked";
                          $label = (string)$booking['band_name'];
                      } else {
                          // contract
                          $href  = "/admin/planning_edit.php?date=" . urlencode($dateStr) . "&timeslot=" . urlencode($ts);
                          $cls   = "slot booked contract";
                          $label = (string)$booking['band_name'] . " (contract)";
                      }
                  } else {
                      $href  = "/admin/planning_edit.php?date=" . urlencode($dateStr) . "&timeslot=" . urlencode($ts);
                      $label = "Vrij";
                      $cls   = "slot empty";
                  }
                  ?>
                  <a class="<?= h($cls) ?>" href="<?= h($href) ?>">
                      <span class="ts"><?= h($timeDisplay) ?></span>
                      <span class="val"><?= h($label) ?></span>
                  </a>
              <?php endforeach; ?>
          </div>
          <?php
          $cursor = $cursor->modify('+1 day');
      endfor;

  endwhile;
  ?>
  </div>

</div>
</div>
</div>

</body>
</html>