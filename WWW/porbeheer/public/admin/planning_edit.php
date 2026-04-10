<?php
declare(strict_types=1);

/*
 * planning_edit.php
 * Planning bewerken (admin)
 * Aangepast: Mail naar bestuursleden bij wijzigen
 * Aangepast: "-- Geen Band --" verwijdert de boeking bij opslaan
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/mail.php';
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
$contractSlot = null;
$contractNote = null;

if ($isEdit) {
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
    $row = [
        'date'     => (string)($_GET['date'] ?? date('Y-m-d')),
        'timeslot' => (string)($_GET['timeslot'] ?? 'AVOND'),
        'band_id'  => 0,
    ];

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

    $w = (int)$dt->format('w');
    $n = (int)$dt->format('n');
    $d = (int)$dt->format('j');
    $y = (int)$dt->format('Y');

    return $days[$w] . " $d " . ($months[$n] ?? $dt->format('m')) . " $y";
}

auditLog($pdo, 'PAGE_VIEW', 'admin/planning_edit.php'.($isEdit ? " id=$id" : ''));

/**
 * Stuur een e-mail naar alle bestuursleden over wijziging in de planning
 */
function sendMailToBoardMembers(PDO $pdo, array $oldData, array $newData, string $action, string $changedBy, ?int $scheduleId = null): void
{
    // Haal alle bestuursleden op uit de database (via user_roles tabel)
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email
        FROM users u
        INNER JOIN user_roles ur ON u.id = ur.user_id
        WHERE ur.role = 'BESTUURSLID'
          AND u.deleted_at IS NULL
          AND u.status = 'ACTIVE'
          AND u.email IS NOT NULL
          AND u.email != ''
        GROUP BY u.id
    ");
    $stmt->execute();
    $boardMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($boardMembers)) {
        auditLog($pdo, 'BOARD_MAIL_NO_RECIPIENTS', 'admin/planning_edit.php', ['notice' => 'Geen bestuursleden gevonden met geldig e-mailadres']);
        return;
    }

    // Dagdeel vertaling
    $timeslotLabels = [
        'OCHTEND' => 'Ochtend (11:00 - 15:00)',
        'MIDDAG'  => 'Middag (15:00 - 19:00)',
        'AVOND'   => 'Avond (19:00 - 23:00)',
    ];
    
    // Band naam ophalen (alleen als er een band is)
    $bandName = 'Geen band';
    if (($newData['band_id'] ?? 0) > 0) {
        $bandStmt = $pdo->prepare("SELECT name FROM bands WHERE id = ?");
        $bandStmt->execute([$newData['band_id']]);
        $bandName = $bandStmt->fetchColumn() ?: 'Onbekende band';
    }
    
    $oldBandName = 'Geen band';
    if ($oldData && ($oldData['band_id'] ?? 0) > 0) {
        $oldBandStmt = $pdo->prepare("SELECT name FROM bands WHERE id = ?");
        $oldBandStmt->execute([$oldData['band_id']]);
        $oldBandName = $oldBandStmt->fetchColumn() ?: 'Onbekende band';
    }
    
    $dateFormatted = date('d-m-Y', strtotime($newData['date']));
    $timeslotFormatted = $timeslotLabels[$newData['timeslot']] ?? $newData['timeslot'];
    
    // Bepaal actie beschrijving
    $actionText = '';
    $detailsHtml = '';
    
    if ($action === 'create') {
        $actionText = 'toegevoegd aan de planning';
        $detailsHtml = "
            <p><strong>Toegevoegde boeking:</strong></p>
            <ul>
                <li><strong>Datum:</strong> {$dateFormatted}</li>
                <li><strong>Dagdeel:</strong> {$timeslotFormatted}</li>
                <li><strong>Band:</strong> " . htmlspecialchars($bandName) . "</li>
            </ul>
        ";
    } elseif ($action === 'update') {
        $actionText = 'gewijzigd in de planning';
        
        // Toon wijzigingen
        $changes = [];
        if (($oldData['date'] ?? '') !== $newData['date']) {
            $changes[] = "<strong>Datum:</strong> " . date('d-m-Y', strtotime($oldData['date'] ?? $newData['date'])) . " → " . $dateFormatted;
        }
        if (($oldData['timeslot'] ?? '') !== $newData['timeslot']) {
            $oldTs = $timeslotLabels[$oldData['timeslot']] ?? $oldData['timeslot'];
            $changes[] = "<strong>Dagdeel:</strong> {$oldTs} → {$timeslotFormatted}";
        }
        if (($oldData['band_id'] ?? 0) !== ($newData['band_id'] ?? 0)) {
            $changes[] = "<strong>Band:</strong> " . htmlspecialchars($oldBandName) . " → " . htmlspecialchars($bandName);
        }
        
        $detailsHtml = "
            <p><strong>Gewijzigde boeking:</strong></p>
            <ul>
                <li><strong>Datum:</strong> {$dateFormatted}</li>
                <li><strong>Dagdeel:</strong> {$timeslotFormatted}</li>
                <li><strong>Band:</strong> " . htmlspecialchars($bandName) . "</li>
            </ul>
        ";
        
        if (!empty($changes)) {
            $detailsHtml .= "
                <p><strong>Wijzigingen:</strong></p>
                <ul>
                    <li>" . implode("</li>\n<li>", $changes) . "</li>
                </ul>
            ";
        }
    } elseif ($action === 'delete') {
        $actionText = 'verwijderd uit de planning';
        $detailsHtml = "
            <p><strong>Verwijderde boeking:</strong></p>
            <ul>
                <li><strong>Datum:</strong> {$dateFormatted}</li>
                <li><strong>Dagdeel:</strong> {$timeslotFormatted}</li>
                <li><strong>Band:</strong> " . htmlspecialchars($oldBandName) . "</li>
            </ul>
        ";
    }
    
    // E-mail inhoud opbouwen (layout geïnspireerd op users_detail.php)
    $subject = "Planning gewijzigd - Porbeheer";
    
    $contentHtml = "
    <div style=\"margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#243447;\">
      <div style=\"max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #d9e2ec;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.08);\">
        
        <div style=\"padding:18px 24px;background:linear-gradient(180deg,#eef6ff,#e6f0fb);border-bottom:1px solid #d9e2ec;\">
          <div style=\"font-size:22px;font-weight:700;color:#1f3b57;\">Porbeheer</div>
          <div style=\"margin-top:4px;font-size:13px;color:#5b7083;\">POP Oefenruimte Zevenaar</div>
        </div>

        <div style=\"padding:28px 24px;\">
          <h2 style=\"margin:0 0 12px 0;font-size:22px;color:#1f3b57;\">Boeking {$actionText}</h2>
          <p style=\"margin:0 0 18px 0;font-size:15px;line-height:1.6;color:#425466;\">
            Door: <strong>" . htmlspecialchars($changedBy) . "</strong>
          </p>

          <div style=\"font-size:15px;line-height:1.7;color:#243447;\">
            {$detailsHtml}
            
            <p style=\"margin-top:20px;\">
              <a href=\"" . appUrl('/admin/planning.php') . "\" style=\"display:inline-block;padding:12px 18px;background:#dfefff;border:1px solid #bdd3ea;border-radius:10px;color:#1f3b57;text-decoration:none;font-weight:700;\">
                → Bekijk volledige planning
              </a>
            </p>
          </div>
        </div>

        <div style=\"padding:16px 24px;background:#f8fbff;border-top:1px solid #d9e2ec;font-size:12px;color:#6b7c93;\">
          Dit is een automatisch bericht van Porbeheer. Je ontvangt deze e-mail omdat je bent geregistreerd als bestuurslid.
        </div>
      </div>
    </div>";
    
    // Verstuur naar alle bestuursleden
    $successCount = 0;
    $failCount = 0;
    
    foreach ($boardMembers as $member) {
        try {
            sendEmail((string)$member['email'], $subject, $contentHtml);
            $successCount++;
            auditLog($pdo, 'BOARD_MAIL_SENT', 'admin/planning_edit.php', [
                'to' => $member['email'],
                'to_name' => $member['username'],
                'action' => $action,
                'schedule_id' => $scheduleId
            ]);
        } catch (Throwable $e) {
            $failCount++;
            auditLog($pdo, 'BOARD_MAIL_FAIL', 'admin/planning_edit.php', [
                'to' => $member['email'],
                'error' => substr($e->getMessage(), 0, 200)
            ]);
        }
    }
    
    auditLog($pdo, 'BOARD_MAIL_BATCH', 'admin/planning_edit.php', [
        'total' => count($boardMembers),
        'success' => $successCount,
        'failed' => $failCount,
        'action' => $action
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);

    $date     = (string)($_POST['date'] ?? '');
    $timeslot = (string)($_POST['timeslot'] ?? '');
    $bandId   = (int)($_POST['band_id'] ?? 0);
    $do       = (string)($_POST['do'] ?? 'save');
    $sendMail = isset($_POST['send_mail_to_board']) && $_POST['send_mail_to_board'] === '1';

    try {
        // Check of datum en dagdeel geldig zijn (altijd nodig)
        if ($date === '' || !in_array($timeslot, ['OCHTEND','MIDDAG','AVOND'], true)) {
            throw new RuntimeException("Vul datum en dagdeel in.");
        }

        // Als band_id = 0 (Geen Band), dan verwijderen we de boeking
        if ($bandId <= 0) {
            // Alleen verwijderen als het een bestaande boeking is
            if (!$isEdit) {
                throw new RuntimeException("Selecteer een band om een nieuwe boeking te maken.");
            }
            
            // Oude data opslaan voor e-mail
            $oldDataForMail = [
                'band_id' => $row['band_id'],
                'date' => $row['date'],
                'timeslot' => $row['timeslot']
            ];
            
            $del = $pdo->prepare("DELETE FROM schedule WHERE id=?");
            $del->execute([$id]);
            auditLog($pdo, 'DELETE', 'schedule', ['id'=>$id]);
            
            // Stuur e-mail naar bestuursleden indien aangevinkt
            if ($sendMail) {
                sendMailToBoardMembers($pdo, $oldDataForMail, ['band_id' => 0, 'date' => $date, 'timeslot' => $timeslot], 'delete', $user['username'] ?? $user['email'] ?? 'Onbekende gebruiker', $id);
            }
            
            header('Location: /admin/planning.php?deleted=1');
            exit;
        }

        // Vanaf hier: bandId > 0, dus opslaan of wijzigen
        $chk = $pdo->prepare("SELECT id FROM schedule WHERE date=? AND timeslot=? LIMIT 1");
        $chk->execute([$date, $timeslot]);
        $existingId = (int)($chk->fetchColumn() ?? 0);

        if ($existingId && (!$isEdit || $existingId !== $id)) {
            throw new RuntimeException("Dit dagdeel is al ingepland. Klik op de bandnaam in de planning om te wijzigen.");
        }

        if ($isEdit) {
            // Oude data opslaan voor e-mail
            $oldDataForMail = [
                'band_id' => $row['band_id'],
                'date' => $row['date'],
                'timeslot' => $row['timeslot']
            ];
            
            $up = $pdo->prepare("UPDATE schedule SET band_id=?, date=?, timeslot=? WHERE id=?");
            $up->execute([$bandId, $date, $timeslot, $id]);
            auditLog($pdo, 'UPDATE', 'schedule', ['id'=>$id,'band_id'=>$bandId,'date'=>$date,'timeslot'=>$timeslot]);
            
            // Stuur e-mail naar bestuursleden indien aangevinkt
            if ($sendMail) {
                $newDataForMail = [
                    'band_id' => $bandId,
                    'date' => $date,
                    'timeslot' => $timeslot
                ];
                sendMailToBoardMembers($pdo, $oldDataForMail, $newDataForMail, 'update', $user['username'] ?? $user['email'] ?? 'Onbekende gebruiker', $id);
            }
        } else {
            $ins = $pdo->prepare("
              INSERT INTO schedule (band_id, parent_id, date, timeslot, repeat_type, repeat_until)
              VALUES (?, NULL, ?, ?, 'NONE', NULL)
            ");
            $ins->execute([$bandId, $date, $timeslot]);
            $newId = (int)$pdo->lastInsertId();
            auditLog($pdo, 'CREATE', 'schedule', ['id'=>$newId,'band_id'=>$bandId,'date'=>$date,'timeslot'=>$timeslot]);
            
            // Stuur e-mail naar bestuursleden indien aangevinkt
            if ($sendMail) {
                $newDataForMail = [
                    'band_id' => $bandId,
                    'date' => $date,
                    'timeslot' => $timeslot
                ];
                sendMailToBoardMembers($pdo, [], $newDataForMail, 'create', $user['username'] ?? $user['email'] ?? 'Onbekende gebruiker', $newId);
            }
        }

        header('Location: /admin/planning.php?saved=1');
        exit;

    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$dt = new DateTime((string)$row['date']);
$header = dayHeader($dt);

$tsLabels = [
    'OCHTEND' => 'Ochtend · 11:00 - 15:00',
    'MIDDAG'  => 'Middag · 15:00 - 19:00',
    'AVOND'   => 'Avond · 19:00 - 23:00',
];

$tsLabel = $tsLabels[(string)$row['timeslot']] ?? (string)$row['timeslot'];

?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Planning wijzigen</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{
  margin:0;
  font-family:Arial,sans-serif;
  color:var(--text);
  background:url('<?= h($bg) ?>') no-repeat center center fixed;
  background-size:cover;
}
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
.checkbox-label{display:flex;align-items:center;gap:10px;margin-top:16px;cursor:pointer;}
.checkbox-label input{width:auto;margin-top:0;transform:scale(1.1);cursor:pointer;}
.checkbox-label span{font-weight:normal;font-size:14px;color:rgba(255,255,255,.9);}
.msg{margin-top:12px;font-size:13px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.err{color:#ffb3b3}
.info{color:rgba(255,255,255,.92)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:720px){.row{grid-template-columns:1fr}}
a{color:#fff;text-decoration:none;transition:color .15s ease}
a:hover{color:#ffd9b3}
a:visited{color:#ffe0c2}
.mail-hint{font-size:12px;color:rgba(255,255,255,.6);margin-top:4px;margin-left:28px;}
.hint-text{font-size:12px;color:rgba(255,255,255,.5);margin-top:4px;}
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
          <div style="margin-top:6px;color:rgba(255,255,255,.78);">
            Als je hieronder opslaat, maak je een <strong>handmatige boeking</strong> die vóórgaat op het contract.
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
              <option value="OCHTEND" <?= ((string)$row['timeslot'] === 'OCHTEND') ? 'selected' : '' ?>>Ochtend · 11:00 - 15:00</option>
              <option value="MIDDAG"  <?= ((string)$row['timeslot'] === 'MIDDAG')  ? 'selected' : '' ?>>Middag · 15:00 - 19:00</option>
              <option value="AVOND"   <?= ((string)$row['timeslot'] === 'AVOND')   ? 'selected' : '' ?>>Avond · 19:00 - 23:00</option>
            </select>
          </label>
        </div>

        <label>Band
          <select name="band_id">
            <option value="0">-- Geen Band (verwijder boeking) --</option>
            <?php foreach ($bands as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= ((int)($row['band_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>>
                <?= h($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($isEdit): ?>
            <div class="hint-text">
              💡 Tip: Selecteer "-- Geen Band --" en klik op "Opslaan" om deze boeking te verwijderen.
            </div>
          <?php endif; ?>
        </label>

        <!-- Checkbox voor mail naar bestuur -->
        <label class="checkbox-label">
          <input type="checkbox" name="send_mail_to_board" value="1">
          <span>📧 Stuur een e-mail naar het bestuur over deze wijziging</span>
        </label>
        <div class="mail-hint">
          Alleen bestuursleden met een geregistreerd e-mailadres ontvangen een notificatie.
        </div>

        <button class="btn" type="submit" name="do" value="save">Opslaan</button>

        <a class="btn" href="/admin/planning.php" style="margin-left:8px;">Annuleren</a>
      </form>
    </div>

  </div>
</div>
</body>
</html>