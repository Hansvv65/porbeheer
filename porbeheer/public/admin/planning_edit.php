<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('schedule', $pdo);

$err = null;

// Bands
$bands = $pdo->query("SELECT id, name FROM bands WHERE deleted_at IS NULL ORDER BY name")->fetchAll();

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$isEdit = $id > 0;

$row = null;

/**
 * Detecteer of band_planner_events bestaat (zodat deze pagina niet crasht).
 */
$hasContractEvents = false;
try {
    $chk = $pdo->query("SHOW TABLES LIKE 'band_planner_events'");
    $hasContractEvents = (bool)$chk->fetchColumn();
} catch (Throwable $e) {
    $hasContractEvents = false;
}

/**
 * Contract-context (voor date+timeslot view)
 */
$contractSlot = null;   // ['band_id'=>..,'band_name'=>..,'contract_id'=>..,'event_id'=>..]
$contractNote = null;   // string

if ($isEdit) {
    // Edit bestaand schedule record
    $st = $pdo->prepare("
      SELECT s.*, b.name AS band_name
      FROM schedule s
      JOIN bands b ON b.id = s.band_id
      WHERE s.id = ?
    ");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        header('Location: /admin/planning.php');
        exit;
    }
} else {
    // Nieuw: date+timeslot vanuit querystring (of default)
    $row = [
        'date'     => (string)($_GET['date'] ?? date('Y-m-d')),
        'timeslot' => (string)($_GET['timeslot'] ?? 'AVOND'),
        'band_id'  => 0,
    ];

    // Als contract events bestaan: zoek of dit slot automatisch bezet is
    if ($hasContractEvents) {
        $date = (string)$row['date'];
        $ts   = (string)$row['timeslot'];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && in_array($ts, ['OCHTEND','MIDDAG','AVOND'], true)) {
            $st = $pdo->prepare("
              SELECT
                e.id AS event_id,
                e.contract_id,
                e.band_id,
                b.name AS band_name
              FROM band_planner_events e
              JOIN bands b ON b.id = e.band_id
              WHERE e.event_date = ?
                AND e.daypart = ?
                AND b.deleted_at IS NULL
              LIMIT 1
            ");
            $st->execute([$date, $ts]);
            $contractSlot = $st->fetch() ?: null;

            if ($contractSlot) {
                // Preselect de band zodat “Opslaan” meteen een override maakt
                $row['band_id'] = (int)$contractSlot['band_id'];

                $contractNote = "Dit dagdeel is automatisch bezet via contract: " . (string)$contractSlot['band_name'] . ".";
            }
        }
    }
}

function dayHeader(DateTimeInterface $dt): string
{
    if (class_exists(\IntlDateFormatter::class)) {
        $fmt = new \IntlDateFormatter(
            'nl_NL',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            $dt->getTimezone()?->getName() ?: 'Europe/Amsterdam',
            \IntlDateFormatter::GREGORIAN,
            'EEEE d MMMM y'
        );
        $out = $fmt->format($dt);
        if (is_string($out) && $out !== '') {
            return $out;
        }
    }

    $days = ['zondag','maandag','dinsdag','woensdag','donderdag','vrijdag','zaterdag'];
    $months = [1=>'januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];

    $w = (int)$dt->format('w');   // 0=zo
    $n = (int)$dt->format('n');   // 1-12
    $d = (int)$dt->format('j');
    $y = (int)$dt->format('Y');

    return $days[$w] . " $d " . ($months[$n] ?? $dt->format('m')) . " $y";
}

auditLog($pdo, 'PAGE_VIEW', 'admin/planning_edit.php'.($isEdit ? " id=$id" : ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);

    $date    = (string)($_POST['date'] ?? '');
    $timeslot= (string)($_POST['timeslot'] ?? '');
    $bandId  = (int)($_POST['band_id'] ?? 0);
    $do      = (string)($_POST['do'] ?? 'save');

    try {
        if ($do === 'delete') {
            if (!$isEdit) throw new RuntimeException("Niet gevonden.");
            $del = $pdo->prepare("DELETE FROM schedule WHERE id=?");
            $del->execute([$id]);
            auditLog($pdo, 'DELETE', 'schedule', ['id'=>$id]);
            header('Location: /admin/planning.php?deleted=1');
            exit;
        }

        if ($date === '' || $bandId <= 0 || !in_array($timeslot, ['OCHTEND','MIDDAG','AVOND'], true)) {
            throw new RuntimeException("Vul datum, dagdeel en band in.");
        }

        // dubbele check: alleen binnen schedule (contract events tellen niet als schedule-dup)
        $chk = $pdo->prepare("SELECT id FROM schedule WHERE date=? AND timeslot=? LIMIT 1");
        $chk->execute([$date, $timeslot]);
        $existingId = (int)($chk->fetchColumn() ?? 0);

        if ($existingId && (!$isEdit || $existingId !== $id)) {
            throw new RuntimeException("Dit dagdeel is al ingepland. Klik op de bandnaam in de kalender om te wijzigen.");
        }

        if ($isEdit) {
            $up = $pdo->prepare("UPDATE schedule SET band_id=?, date=?, timeslot=? WHERE id=?");
            $up->execute([$bandId, $date, $timeslot, $id]);
            auditLog($pdo, 'UPDATE', 'schedule', ['id'=>$id,'band_id'=>$bandId,'date'=>$date,'timeslot'=>$timeslot]);
        } else {
            // nieuw schedule record (override)
            $ins = $pdo->prepare("
              INSERT INTO schedule (band_id, parent_id, date, timeslot, repeat_type, repeat_until)
              VALUES (?, NULL, ?, ?, 'NONE', NULL)
            ");
            $ins->execute([$bandId, $date, $timeslot]);
            $newId = (int)$pdo->lastInsertId();
            auditLog($pdo, 'CREATE', 'schedule', ['id'=>$newId,'band_id'=>$bandId,'date'=>$date,'timeslot'=>$timeslot]);
        }

        header('Location: /admin/planning.php?saved=1');
        exit;

    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$dt = new DateTime((string)$row['date']);
$header = dayHeader($dt);

// nette label voor dagdeel
$tsLabel = [
    'OCHTEND' => 'Ochtend',
    'MIDDAG'  => 'Middag',
    'AVOND'   => 'Avond'
][(string)$row['timeslot']] ?? (string)$row['timeslot'];

?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Planning wijzigen</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;  background-size:cover;
.backdrop{min-height:100vh;background:
  radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
  linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
  padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
.wrap{width:min(900px,96vw);}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:18px;}
.topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.brand h1{margin:0;font-size:26px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);
  backdrop-filter:blur(10px);min-width:260px;}
.userbox .line1{font-weight:bold}
.userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
.userbox a{color:#fff;text-decoration:none}
.userbox a:hover{color:#ffd9b3}
label{display:block;margin-top:12px}
input,select{width:100%;padding:10px;border-radius:12px;border:none;outline:none;margin-top:6px}
.btn{display:inline-block;margin-top:14px;padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));color:#fff;font-weight:800;cursor:pointer;text-decoration:none}
.btn:hover{border-color:rgba(255,255,255,.38);transform:translateY(-1px)}
.btn-danger{border-color:rgba(255,120,120,.45)}
.msg{margin-top:12px;font-size:13px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.err{color:#ffb3b3}
.info{color:rgba(255,255,255,.92)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:720px){.row{grid-template-columns:1fr}}
a{color:#fff} a:hover{color:#ffd9b3}
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
        <h1><?= $isEdit ? 'Planning aanpassen' : 'Inplannen' ?></h1>
        <div class="sub"><?= h($header) ?> · <?= h($tsLabel) ?></div>
      </div>
      <div class="userbox">
        <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a> • 
          <a href="/admin/planning.php">Planning</a> • 
          <a href="/logout.php">Uitloggen</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

      <?php if (!$isEdit && $contractSlot): ?>
        <div class="msg info">
          <?= h($contractNote ?? 'Dit dagdeel is automatisch bezet via contract.') ?>
          <div style="margin-top:6px;">
            <a href="/admin/band_detail.php?id=<?= (int)$contractSlot['band_id'] ?>">→ Band detail openen</a>
          </div>
          <div style="margin-top:6px;color:<?= h('rgba(255,255,255,.78)') ?>;">
            Als je hieronder opslaat, maak je een <strong>handmatige booking</strong> die vóórgaat op het contract.
          </div>
        </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="row">
          <label>Datum
            <input type="date" name="date" required value="<?= h((string)$row['date']) ?>">
          </label>

          <label>Dagdeel
            <select name="timeslot" required>
              <?php foreach (['OCHTEND','MIDDAG','AVOND'] as $t): ?>
                <option value="<?= h($t) ?>" <?= ((string)$row['timeslot'] === $t) ? 'selected' : '' ?>>
                  <?= h($t) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label>Band
          <select name="band_id" required>
            <option value="">-- kies band --</option>
            <?php foreach ($bands as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= ((int)($row['band_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>>
                <?= h($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <button class="btn" type="submit" name="do" value="save">Opslaan</button>

        <?php if ($isEdit): ?>
          <button class="btn btn-danger" type="submit" name="do" value="delete"
                  onclick="return confirm('Boeking verwijderen?');"
                  style="margin-left:8px;">
            Verwijderen
          </button>
        <?php endif; ?>

        <a class="btn" href="/admin/planning.php" style="margin-left:8px;">Annuleren</a>
      </form>
    </div>

  </div>
</div>
</body>
</html>