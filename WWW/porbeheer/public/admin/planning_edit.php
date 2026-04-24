<?php
declare(strict_types=1);

/*
 * planning_edit.php
 * Planning bewerken (admin)
 * Aangepast:
 *   - Handmatige start- en eindtijd (optioneel)
 *   - Prijs per dagdeel uit app_settings, aanpasbaar
 *   - Mail naar bestuursleden (bestaand)
 *   - Mail naar bandcontacten (nieuw)
 *   - "-- Geen Band --" verwijdert de boeking
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/mail.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('schedule', $pdo);

/* ========== Helpers ========== */
function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $st = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key`=? LIMIT 1");
    $st->execute([$key]);
    $val = $st->fetchColumn();
    return ($val !== false) ? (string)$val : $default;
}

/* Prijs normaliseren (uit settings overgenomen) */
function normalizePrice(string $value): ?string {
    $value = trim($value);
    $value = str_replace(',', '.', $value);
    if ($value === '') return null;
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) return null;
    return number_format((float)$value, 2, '.', '');
}

$err = null;

// Bands ophalen
$bands = $pdo->query("SELECT id, name FROM bands WHERE deleted_at IS NULL ORDER BY name")->fetchAll();

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$isEdit = $id > 0;

$row = null;

/**
 * Standaard bloktijden
 */
$blockTimes = [
    'OCHTEND' => ['start' => '11:00', 'end' => '15:00'],
    'MIDDAG'  => ['start' => '15:00', 'end' => '19:00'],
    'AVOND'   => ['start' => '19:00', 'end' => '23:00'],
];

/**
 * Contract-context
 */
$hasContractEvents = false;
try {
    $chk = $pdo->query("SHOW TABLES LIKE 'band_planner_events'");
    $hasContractEvents = (bool)$chk->fetchColumn();
} catch (Throwable $e) {
    $hasContractEvents = false;
}
$contractSlot = null;
$contractNote = null;

// Huidige/default waardes ophalen
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
        'date'           => (string)($_GET['date'] ?? date('Y-m-d')),
        'timeslot'       => (string)($_GET['timeslot'] ?? 'AVOND'),
        'band_id'        => 0,
        'start_time'     => null,
        'end_time'       => null,
        'incidental_fee' => '0.00',
    ];
    if ($hasContractEvents) {
        $date = $row['date'];
        $ts   = $row['timeslot'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && in_array($ts, ['OCHTEND','MIDDAG','AVOND'], true)) {
            $st = $pdo->prepare("
                SELECT e.id AS event_id, e.contract_id, e.band_id, b.name AS band_name
                FROM band_planner_events e
                JOIN bands b ON b.id = e.band_id
                WHERE e.event_date=? AND e.daypart=? AND b.deleted_at IS NULL
                LIMIT 1
            ");
            $st->execute([$date,$ts]);
            $contractSlot = $st->fetch() ?: null;
            if ($contractSlot) {
                $row['band_id'] = (int)$contractSlot['band_id'];
                $contractNote = "Dit dagdeel is automatisch bezet via contract: " . $contractSlot['band_name'] . ".";
            }
        }
    }
}

// Bepaal weergavetijden
$ts          = (string)$row['timeslot'];
$defaultStart = $row['start_time'] ?? $blockTimes[$ts]['start'];
$defaultEnd   = $row['end_time']   ?? $blockTimes[$ts]['end'];

// Standaardprijs uit instellingen
$daypartPriceSetting = getSetting($pdo, 'daypart_price', '0.00');
// Gebruik al opgeslagen bedrag, of de standaardinstelling
$currentFee = (string)($row['incidental_fee'] ?? '0.00');
if ($currentFee === '0.00' && !$isEdit) {
    $currentFee = $daypartPriceSetting;  // alleen bij nieuwe boeking voorinvullen
}

/* ========== E-mail functies ========== */

function sendMailToBoardMembers(PDO $pdo, array $oldData, array $newData, string $action, string $changedBy, ?int $scheduleId = null): void
{
    // Haal alle bestuursleden op
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email
        FROM users u
        INNER JOIN user_roles ur ON u.id = ur.user_id
        WHERE ur.role = 'BESTUURSLID'
          AND u.deleted_at IS NULL AND u.status = 'ACTIVE'
          AND u.email IS NOT NULL AND u.email != ''
        GROUP BY u.id
    ");
    $stmt->execute();
    $boardMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($boardMembers)) return;

    $timeslotLabels = [
        'OCHTEND' => 'Ochtend (11:00 - 15:00)',
        'MIDDAG'  => 'Middag (15:00 - 19:00)',
        'AVOND'   => 'Avond (19:00 - 23:00)',
    ];

    $bandName = 'Geen band';
    if (($newData['band_id'] ?? 0) > 0) {
        $bandStmt = $pdo->prepare("SELECT name FROM bands WHERE id=?");
        $bandStmt->execute([$newData['band_id']]);
        $bandName = $bandStmt->fetchColumn() ?: 'Onbekende band';
    }
    $oldBandName = 'Geen band';
    if ($oldData && ($oldData['band_id'] ?? 0) > 0) {
        $oldBandStmt = $pdo->prepare("SELECT name FROM bands WHERE id=?");
        $oldBandStmt->execute([$oldData['band_id']]);
        $oldBandName = $oldBandStmt->fetchColumn() ?: 'Onbekende band';
    }

    $dateFormatted = date('d-m-Y', strtotime($newData['date']));
    $timeStr = '';
    if (!empty($newData['start_time']) && !empty($newData['end_time'])) {
        $timeStr = " ({$newData['start_time']} – {$newData['end_time']})";
    }
    $timeslotFormatted = ($timeslotLabels[$newData['timeslot']] ?? $newData['timeslot']) . $timeStr;

    $feeInfo = '';
    $fee = (float)($newData['incidental_fee'] ?? 0);
    if ($fee > 0) {
        $feeInfo = '<li><strong>Bedrag:</strong> €' . number_format($fee, 2, ',', '.') . '</li>';
    }

    $actionText = [
        'create' => 'toegevoegd aan de planning',
        'update' => 'gewijzigd in de planning',
        'delete' => 'verwijderd uit de planning'
    ][$action] ?? 'gewijzigd';

    $detailsHtml = "
        <p><strong>".ucfirst($action).":</strong></p>
        <ul>
            <li><strong>Datum:</strong> {$dateFormatted}</li>
            <li><strong>Dagdeel:</strong> {$timeslotFormatted}</li>
            <li><strong>Band:</strong> " . htmlspecialchars($bandName) . "</li>
            {$feeInfo}
        </ul>";

    if ($action === 'update') {
        $changes = [];
        if (($oldData['date'] ?? '') !== $newData['date']) $changes[] = "Datum: ".date('d-m-Y', strtotime($oldData['date']))." → {$dateFormatted}";
        if (($oldData['timeslot'] ?? '') !== $newData['timeslot']) $changes[] = "Dagdeel gewijzigd";
        if (($oldData['band_id'] ?? 0) !== ($newData['band_id'] ?? 0)) $changes[] = "Band: ".htmlspecialchars($oldBandName)." → ".htmlspecialchars($bandName);
        if (!empty($changes)) {
            $detailsHtml .= '<p><strong>Wijzigingen:</strong></p><ul><li>'.implode('</li><li>', $changes).'</li></ul>';
        }
    }

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
            Door: <strong>".htmlspecialchars($changedBy)."</strong>
          </p>
          <div style=\"font-size:15px;line-height:1.7;color:#243447;\">
            {$detailsHtml}
            <p style=\"margin-top:20px;\">
              <a href=\"".appUrl('/admin/planning.php')."\" style=\"display:inline-block;padding:12px 18px;background:#dfefff;border:1px solid #bdd3ea;border-radius:10px;color:#1f3b57;text-decoration:none;font-weight:700;\">
                → Bekijk volledige planning
              </a>
            </p>
          </div>
        </div>
        <div style=\"padding:16px 24px;background:#f8fbff;border-top:1px solid #d9e2ec;font-size:12px;color:#6b7c93;\">
          Dit is een automatisch bericht van Porbeheer.
        </div>
      </div>
    </div>";

    foreach ($boardMembers as $member) {
        try {
            sendEmail((string)$member['email'], $subject, $contentHtml);
            auditLog($pdo, 'BOARD_MAIL_SENT', 'admin/planning_edit.php', [
                'to' => $member['email'], 'action' => $action, 'schedule_id' => $scheduleId
            ]);
        } catch (Throwable $e) {
            auditLog($pdo, 'BOARD_MAIL_FAIL', 'admin/planning_edit.php', [
                'to' => $member['email'], 'error' => substr($e->getMessage(),0,200)
            ]);
        }
    }
}

/* ---------- Mail naar bandcontacten ---------- */
function sendMailToBandContacts(PDO $pdo, array $newData, string $action, string $changedBy): void
{
    $bandId = (int)($newData['band_id'] ?? 0);
    if ($bandId <= 0) return;

    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.email
        FROM bands b
        LEFT JOIN contacts c ON c.id = b.primary_contact_id OR c.id = b.secondary_contact_id
        WHERE b.id = :bid
          AND c.email IS NOT NULL AND c.email != ''
          AND (c.id = b.primary_contact_id OR c.id = b.secondary_contact_id)
    ");
    $stmt->execute(['bid' => $bandId]);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($contacts)) return;

    $timeslotLabels = [
        'OCHTEND' => 'Ochtend (11:00 - 15:00)',
        'MIDDAG'  => 'Middag (15:00 - 19:00)',
        'AVOND'   => 'Avond (19:00 - 23:00)',
    ];

    $bandName = '';
    $bandStmt = $pdo->prepare("SELECT name FROM bands WHERE id=?");
    $bandStmt->execute([$bandId]);
    $bandName = $bandStmt->fetchColumn() ?: 'Onbekende band';

    $dateFormatted = date('d-m-Y', strtotime($newData['date']));
    $timeStr = '';
    if (!empty($newData['start_time']) && !empty($newData['end_time'])) {
        $timeStr = " ({$newData['start_time']} – {$newData['end_time']})";
    }
    $timeslotFormatted = ($timeslotLabels[$newData['timeslot']] ?? $newData['timeslot']) . $timeStr;

    $feeInfo = '';
    $fee = (float)($newData['incidental_fee'] ?? 0);
    if ($fee > 0) {
        $feeInfo = '<li><strong>Bedrag:</strong> €' . number_format($fee, 2, ',', '.') . '</li>';
    }

    $actionText = [
        'create' => 'toegevoegd aan de planning',
        'update' => 'gewijzigd in de planning',
        'delete' => 'verwijderd uit de planning'
    ][$action] ?? 'gewijzigd';

    $detailsHtml = "
        <p><strong>Boeking:</strong></p>
        <ul>
            <li><strong>Datum:</strong> {$dateFormatted}</li>
            <li><strong>Dagdeel:</strong> {$timeslotFormatted}</li>
            <li><strong>Band:</strong> " . htmlspecialchars($bandName) . "</li>
            {$feeInfo}
        </ul>";

    $subject = "Bevestiging boeking - " . htmlspecialchars($bandName) . " - Porbeheer";
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
            Gewijzigd door: <strong>".htmlspecialchars($changedBy)."</strong>
          </p>
          <div style=\"font-size:15px;line-height:1.7;color:#243447;\">
            {$detailsHtml}
            <p style=\"margin-top:20px;\">
              <a href=\"".appUrl('/admin/planning.php')."\" style=\"display:inline-block;padding:12px 18px;background:#dfefff;border:1px solid #bdd3ea;border-radius:10px;color:#1f3b57;text-decoration:none;font-weight:700;\">
                → Bekijk planning
              </a>
            </p>
          </div>
        </div>
        <div style=\"padding:16px 24px;background:#f8fbff;border-top:1px solid #d9e2ec;font-size:12px;color:#6b7c93;\">
          Dit is een automatisch bericht van Porbeheer.
        </div>
      </div>
    </div>";

    foreach ($contacts as $contact) {
        try {
            sendEmail((string)$contact['email'], $subject, $contentHtml);
            auditLog($pdo, 'BAND_CONTACT_MAIL_SENT', 'admin/planning_edit.php', [
                'to' => $contact['email'], 'band_id' => $bandId
            ]);
        } catch (Throwable $e) {
            auditLog($pdo, 'BAND_CONTACT_MAIL_FAIL', 'admin/planning_edit.php', [
                'to' => $contact['email'], 'error' => substr($e->getMessage(),0,200)
            ]);
        }
    }
}

/* ========== POST-verwerking ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);

    $date     = (string)($_POST['date'] ?? '');
    $timeslot = (string)($_POST['timeslot'] ?? '');
    $bandId   = (int)($_POST['band_id'] ?? 0);
    $sendMailBoard    = isset($_POST['send_mail_to_board']) && $_POST['send_mail_to_board'] === '1';
    $sendMailContacts = isset($_POST['send_mail_to_contacts']) && $_POST['send_mail_to_contacts'] === '1';

    // Tijden
    $startTime = !empty($_POST['start_time']) ? (string)$_POST['start_time'] : null;
    $endTime   = !empty($_POST['end_time'])   ? (string)$_POST['end_time']   : null;

    // Prijs
    $feeInput = (string)($_POST['incidental_fee'] ?? '0.00');
    $feeNorm = normalizePrice($feeInput);
    if ($feeNorm === null) {
        $err = 'Voer een geldig bedrag in voor de dagdeelprijs (bijv. 40,00).';
        goto render;
    }
    $incidentalFee = $feeNorm;

    // Valideer tijdlogica
    if ($startTime && $endTime && $startTime >= $endTime) {
        $err = 'Eindtijd moet na de starttijd liggen.';
        goto render;
    }

    try {
        if ($date === '' || !in_array($timeslot, ['OCHTEND','MIDDAG','AVOND'], true)) {
            throw new RuntimeException("Vul datum en dagdeel in.");
        }

        // Verwijderen (band_id = 0)
        if ($bandId <= 0) {
            if (!$isEdit) throw new RuntimeException("Selecteer een band om een nieuwe boeking te maken.");

            $oldDataForMail = [
                'band_id'        => $row['band_id'],
                'date'           => $row['date'],
                'timeslot'       => $row['timeslot'],
                'start_time'     => $row['start_time'],
                'end_time'       => $row['end_time'],
                'incidental_fee' => $row['incidental_fee'] ?? '0.00'
            ];
            $del = $pdo->prepare("DELETE FROM schedule WHERE id=?");
            $del->execute([$id]);
            auditLog($pdo, 'DELETE', 'schedule', ['id'=>$id]);

            if ($sendMailBoard) {
                sendMailToBoardMembers($pdo, $oldDataForMail,
                    ['band_id' => 0, 'date' => $date, 'timeslot' => $timeslot, 'start_time' => null, 'end_time' => null, 'incidental_fee' => '0.00'],
                    'delete', $user['username'] ?? $user['email'] ?? 'Onbekend', $id);
            }
            if ($sendMailContacts) {
                sendMailToBandContacts($pdo, $oldDataForMail, 'delete', $user['username'] ?? $user['email'] ?? 'Onbekend');
            }
            header('Location: /admin/planning.php?deleted=1');
            exit;
        }

        // Dubbele boeking controle
        $chk = $pdo->prepare("SELECT id FROM schedule WHERE date=? AND timeslot=? LIMIT 1");
        $chk->execute([$date, $timeslot]);
        $existingId = (int)($chk->fetchColumn() ?? 0);
        if ($existingId && (!$isEdit || $existingId !== $id)) {
            throw new RuntimeException("Dit dagdeel is al ingepland. Klik op de bandnaam in de planning om te wijzigen.");
        }

        // Opslaan / bijwerken
        $newDataForMail = [
            'band_id'        => $bandId,
            'date'           => $date,
            'timeslot'       => $timeslot,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'incidental_fee' => $incidentalFee
        ];

        if ($isEdit) {
            $oldDataForMail = [
                'band_id'        => $row['band_id'],
                'date'           => $row['date'],
                'timeslot'       => $row['timeslot'],
                'start_time'     => $row['start_time'],
                'end_time'       => $row['end_time'],
                'incidental_fee' => $row['incidental_fee'] ?? '0.00'
            ];

            $up = $pdo->prepare("
                UPDATE schedule
                SET band_id=?, date=?, timeslot=?, start_time=?, end_time=?, incidental_fee=?
                WHERE id=?
            ");
            $up->execute([$bandId, $date, $timeslot, $startTime, $endTime, $incidentalFee, $id]);
            auditLog($pdo, 'UPDATE', 'schedule', [
                'id'=>$id, 'band_id'=>$bandId, 'date'=>$date, 'timeslot'=>$timeslot,
                'start_time'=>$startTime, 'end_time'=>$endTime, 'incidental_fee'=>$incidentalFee
            ]);

            if ($sendMailBoard) {
                sendMailToBoardMembers($pdo, $oldDataForMail, $newDataForMail, 'update', $user['username'] ?? $user['email'] ?? 'Onbekend', $id);
            }
            if ($sendMailContacts) {
                sendMailToBandContacts($pdo, $newDataForMail, 'update', $user['username'] ?? $user['email'] ?? 'Onbekend');
            }
        } else {
            $ins = $pdo->prepare("
                INSERT INTO schedule (band_id, parent_id, date, timeslot, start_time, end_time, incidental_fee, repeat_type, repeat_until)
                VALUES (?, NULL, ?, ?, ?, ?, ?, 'NONE', NULL)
            ");
            $ins->execute([$bandId, $date, $timeslot, $startTime, $endTime, $incidentalFee]);
            $newId = (int)$pdo->lastInsertId();
            auditLog($pdo, 'CREATE', 'schedule', ['id'=>$newId,'band_id'=>$bandId,'date'=>$date,'timeslot'=>$timeslot,'start_time'=>$startTime,'end_time'=>$endTime,'incidental_fee'=>$incidentalFee]);

            if ($sendMailBoard) {
                sendMailToBoardMembers($pdo, [], $newDataForMail, 'create', $user['username'] ?? $user['email'] ?? 'Onbekend', $newId);
            }
            if ($sendMailContacts) {
                sendMailToBandContacts($pdo, $newDataForMail, 'create', $user['username'] ?? $user['email'] ?? 'Onbekend');
            }
        }

        header('Location: /admin/planning.php?saved=1');
        exit;

    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    render:
}

/* ========== UI-variabelen ========== */
$dt = new DateTime((string)$row['date']);
$header = (function($dt) {
    $days = ['zondag','maandag','dinsdag','woensdag','donderdag','vrijdag','zaterdag'];
    $months = [1=>'januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
    return $days[(int)$dt->format('w')] . ' ' . $dt->format('j') . ' ' . $months[(int)$dt->format('n')] . ' ' . $dt->format('Y');
})($dt);

$tsLabels = [
    'OCHTEND' => 'Ochtend · 11:00 - 15:00',
    'MIDDAG'  => 'Middag · 15:00 - 19:00',
    'AVOND'   => 'Avond · 19:00 - 23:00',
];
$tsLabel = $tsLabels[$ts] ?? $ts;

auditLog($pdo, 'PAGE_VIEW', 'admin/planning_edit.php'.($isEdit ? " id=$id" : ''));
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Planning wijzigen</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('<?= h($bg) ?>') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
.wrap{width:min(900px,96vw);}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:18px;}
.topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.brand h1{margin:0;font-size:26px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);backdrop-filter:blur(10px);min-width:260px;}
.userbox .line1{font-weight:bold}
.userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
.userbox a{color:#fff;text-decoration:none}
.userbox a:hover{color:#ffd9b3}
label{display:block;margin-top:12px}
input,select{width:100%;padding:10px;border-radius:12px;border:none;outline:none;margin-top:6px;background:rgba(0,0,0,.3);color:#fff;border:1px solid rgba(255,255,255,.15);}
input:focus,select:focus{border-color:rgba(255,255,255,.35);box-shadow:0 0 0 2px rgba(255,255,255,.1);}
.btn{display:inline-block;margin-top:14px;padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:linear-gradient(180deg, var(--glass), var(--glass2));color:#fff;font-weight:800;cursor:pointer;text-decoration:none}
.btn:hover{border-color:rgba(255,255,255,.38);transform:translateY(-1px)}
.btn-danger{border-color:rgba(255,120,120,.45)}
.checkbox-label{display:flex;align-items:center;gap:10px;margin-top:16px;cursor:pointer;}
.checkbox-label input{width:auto;margin-top:0;transform:scale(1.1);cursor:pointer;background:transparent;border:1px solid var(--border);}
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
            <select name="timeslot" id="timeslotSelect" required>
              <option value="OCHTEND" <?= $ts === 'OCHTEND' ? 'selected' : '' ?>>Ochtend · 11:00 - 15:00</option>
              <option value="MIDDAG"  <?= $ts === 'MIDDAG'  ? 'selected' : '' ?>>Middag · 15:00 - 19:00</option>
              <option value="AVOND"   <?= $ts === 'AVOND'   ? 'selected' : '' ?>>Avond · 19:00 - 23:00</option>
            </select>
          </label>
        </div>

        <!-- Aangepaste tijden -->
        <div class="row" style="margin-top:8px;">
          <label>Starttijd
            <input type="time" name="start_time" id="startTimeField"
                   value="<?= h($defaultStart) ?>"
                   placeholder="Standaard: <?= h($defaultStart) ?>">
          </label>
          <label>Eindtijd
            <input type="time" name="end_time" id="endTimeField"
                   value="<?= h($defaultEnd) ?>"
                   placeholder="Standaard: <?= h($defaultEnd) ?>">
          </label>
        </div>
        <div class="hint-text">💡 Vul beide tijden in om van de standaardblokken af te wijken. Laat leeg voor standaardtijden.</div>

        <!-- Prijs per dagdeel -->
        <label>Prijs dagdeel (€)
          <input type="text" name="incidental_fee" value="<?= h($currentFee) ?>" placeholder="<?= h($daypartPriceSetting) ?>">
        </label>
        <div class="hint-text">Standaardprijs uit instellingen: € <?= h($daypartPriceSetting) ?>. Gebruik komma of punt.</div>

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
            <div class="hint-text">💡 Selecteer "-- Geen Band --" en klik op "Opslaan" om deze boeking te verwijderen.</div>
          <?php endif; ?>
        </label>

        <!-- Mail naar bestuur -->
        <label class="checkbox-label">
          <input type="checkbox" name="send_mail_to_board" value="1">
          <span>📧 Stuur een e-mail naar het bestuur over deze wijziging</span>
        </label>
        <div class="mail-hint">Alleen bestuursleden met een geregistreerd e-mailadres ontvangen een notificatie.</div>

        <!-- Mail naar bandcontacten (nieuw) -->
        <label class="checkbox-label" style="margin-top:8px;">
          <input type="checkbox" name="send_mail_to_contacts" value="1">
          <span>📧 Stuur bevestiging naar de contactpersonen van de band (indien e-mail bekend)</span>
        </label>
        <div class="mail-hint">De eerste en tweede contactpersoon uit de bandgegevens ontvangen een bericht.</div>

        <button class="btn" type="submit" name="do" value="save">Opslaan</button>
        <a class="btn" href="/admin/planning.php" style="margin-left:8px;">Annuleren</a>
      </form>
    </div>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tsSelect = document.getElementById('timeslotSelect');
    const startField = document.getElementById('startTimeField');
    const endField = document.getElementById('endTimeField');

    const defaults = {
        OCHTEND: { start: '11:00', end: '15:00' },
        MIDDAG:  { start: '15:00', end: '19:00' },
        AVOND:   { start: '19:00', end: '23:00' }
    };
    let previousTs = tsSelect.value;

    tsSelect.addEventListener('change', function() {
        const newTs = tsSelect.value;
        const prev = defaults[previousTs];
        const cur = defaults[newTs];

        // Vervang alleen als de gebruiker de tijden niet handmatig heeft aangepast
        if (startField.value === '' || startField.value === prev.start) startField.value = cur.start;
        if (endField.value === '' || endField.value === prev.end) endField.value = cur.end;
        previousTs = newTs;
    });
});
</script>

</body>
</html>