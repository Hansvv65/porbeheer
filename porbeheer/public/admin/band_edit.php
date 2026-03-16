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

function normDate(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return '';
    }
    return $s;
}

function getSetting(PDO $pdo, string $key, string $default = '0.00'): string
{
    $st = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
    $st->execute([$key]);
    $val = $st->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

function normalizePriceString(string $value): string
{
    $value = trim(str_replace(',', '.', $value));
    if ($value === '' || !is_numeric($value)) {
        return '0.00';
    }
    return number_format((float)$value, 2, '.', '');
}

$err = null;

$id     = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$isEdit = $id > 0;

$band = null;

$contacts = $pdo->query("
    SELECT id, name
    FROM contacts
    WHERE deleted_at IS NULL
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/*
 * Vaste lockerset uit database ophalen.
 * Er worden GEEN nieuwe lockers aangemaakt vanuit deze pagina.
 */
$lockerRows = $pdo->query("
    SELECT locker_no
    FROM lockers
    WHERE deleted_at IS NULL
    GROUP BY locker_no
    ORDER BY locker_no
")->fetchAll(PDO::FETCH_ASSOC);

$validLockerNos = array_map(
    static fn(array $r): string => (string)$r['locker_no'],
    $lockerRows
);

$subscriptionPrice = normalizePriceString(getSetting($pdo, 'subscription_month_price', '0.00'));
$incidentalPrice   = normalizePriceString(getSetting($pdo, 'daypart_price', '0.00'));

$priceForContractType = static function (string $contractType) use ($subscriptionPrice, $incidentalPrice): string {
    if ($contractType === 'ABONNEMENT') {
        return $subscriptionPrice;
    }
    if ($contractType === 'INCIDENTEEL') {
        return $incidentalPrice;
    }
    return '0.00';
};

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM bands WHERE id = ?");
    $stmt->execute([$id]);
    $band = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$band) {
        header("Location: /admin/bands.php");
        exit;
    }
}

$isDeleted = $isEdit && !empty($band['deleted_at']);

$currentContract = null;
if ($isEdit) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM band_contracts
        WHERE band_id = ?
        ORDER BY
          CASE
            WHEN end_date IS NULL THEN 0
            WHEN end_date >= CURDATE() THEN 1
            ELSE 2
          END ASC,
          COALESCE(end_date, '9999-12-31') DESC,
          id DESC
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $currentContract = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$currentContractId = $currentContract ? (int)$currentContract['id'] : 0;

$defaultRepeatDaypart = '';
$defaultRepeatWeekday = 0;

if ($isEdit && $currentContractId > 0) {
    $stmt = $pdo->prepare("
        SELECT daypart, event_date
        FROM band_planner_events
        WHERE contract_id = ?
        ORDER BY
          CASE WHEN event_date >= CURDATE() THEN 0 ELSE 1 END,
          event_date ASC,
          id ASC
        LIMIT 1
    ");
    $stmt->execute([$currentContractId]);
    $ev = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ev) {
        $defaultRepeatDaypart = (string)$ev['daypart'];
        $defaultRepeatWeekday = (int)(new DateTimeImmutable((string)$ev['event_date']))->format('N');
    }
}

$currentLockers = [];
if ($isEdit) {
    $stmt = $pdo->prepare("
        SELECT locker_no
        FROM lockers
        WHERE band_id = ? AND deleted_at IS NULL
        ORDER BY locker_no
    ");
    $stmt->execute([$id]);
    $currentLockers = array_map(
        static fn($v): string => (string)$v,
        array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'locker_no')
    );
}

/* ---------- Acties: delete / restore ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'save') {
    requireCsrf($_POST['csrf'] ?? null);

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($id <= 0) {
            throw new RuntimeException('Ongeldig band-id.');
        }

        $pdo->beginTransaction();

        if ($action === 'soft_delete') {
            $pdo->prepare("UPDATE bands SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            auditLog($pdo, 'BAND_SOFT_DELETE', 'bands id='.$id);

            $pdo->commit();
            header("Location: /admin/bands.php?msg=" . urlencode('Band verwijderd (soft).'));
            exit;
        }

        if ($action === 'restore') {
            $pdo->prepare("UPDATE bands SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            auditLog($pdo, 'BAND_RESTORE', 'bands id='.$id);

            $pdo->commit();
            header("Location: /admin/band_edit.php?id=".$id);
            exit;
        }

        if ($action === 'hard_delete') {
            $st = $pdo->prepare("SELECT deleted_at FROM bands WHERE id = ?");
            $st->execute([$id]);
            $b = $st->fetch(PDO::FETCH_ASSOC);

            if (!$b) {
                throw new RuntimeException('Band niet gevonden.');
            }
            if (empty($b['deleted_at'])) {
                throw new RuntimeException('Hard delete kan alleen nadat de band eerst soft-deleted is.');
            }

            $pdo->prepare("DELETE FROM schedule WHERE band_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM band_planner_events WHERE band_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM band_contracts WHERE band_id = ?")->execute([$id]);

            $pdo->prepare("UPDATE lockers SET band_id = NULL WHERE band_id = ? AND deleted_at IS NULL")->execute([$id]);

            $pdo->prepare("DELETE FROM bands WHERE id = ?")->execute([$id]);

            auditLog($pdo, 'BAND_HARD_DELETE', 'bands id='.$id);

            $pdo->commit();
            header("Location: /admin/bands.php?msg=" . urlencode('Band definitief verwijderd. Planning opgeschoond, lockers vrijgegeven.'));
            exit;
        }

        throw new RuntimeException('Onbekende actie.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $err = $e->getMessage();
    }
}

$isPostSave = ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? 'save') === 'save'));

$form = [
    'name'              => (string)($band['name'] ?? ''),
    'city'              => (string)($band['city'] ?? ''),
    'primary_contact'   => (string)($band['primary_contact_id'] ?? ''),
    'secondary_contact' => (string)($band['secondary_contact_id'] ?? ''),
    'lockers'           => $currentLockers,

    'contract_type'     => (string)($currentContract['contract_type'] ?? ''),
    'contract_start'    => (string)($currentContract['start_date'] ?? date('Y-m-d')),
    'contract_end'      => (string)($currentContract['end_date'] ?? ''),
    'monthly_fee'       => $currentContract
        ? (string)($currentContract['monthly_fee'] ?? $priceForContractType((string)$currentContract['contract_type']))
        : '0.00',

    'repeat_daypart'    => $defaultRepeatDaypart,
    'repeat_weekday'    => (string)$defaultRepeatWeekday,
];

if ($isPostSave) {
    $form['name'] = trim((string)($_POST['name'] ?? $form['name']));
    $form['city'] = trim((string)($_POST['city'] ?? $form['city']));
    $form['primary_contact'] = (string)($_POST['primary_contact'] ?? $form['primary_contact']);
    $form['secondary_contact'] = (string)($_POST['secondary_contact'] ?? $form['secondary_contact']);

    $lockers = $_POST['lockers'] ?? [];
    if (!is_array($lockers)) {
        $lockers = [];
    }

    $lockers = array_map(static fn($v): string => trim((string)$v), $lockers);
    $lockers = array_values(array_unique(array_filter(
        $lockers,
        static fn(string $v): bool => $v !== '' && $v !== '0'
    )));
    $lockers = array_values(array_filter(
        $lockers,
        static fn(string $v): bool => in_array($v, $validLockerNos, true)
    ));

    $form['lockers'] = $lockers;

    $form['contract_type']  = trim((string)($_POST['contract_type'] ?? $form['contract_type']));
    $form['contract_start'] = normDate((string)($_POST['contract_start'] ?? $form['contract_start'])) ?: $form['contract_start'];
    $form['contract_end']   = normDate((string)($_POST['contract_end'] ?? $form['contract_end']));

    $form['repeat_daypart'] = trim((string)($_POST['repeat_daypart'] ?? $form['repeat_daypart']));
    $rw = (int)($_POST['repeat_weekday'] ?? 0);
    $form['repeat_weekday'] = (string)(($rw >= 1 && $rw <= 7) ? $rw : 0);

    $form['monthly_fee'] = $priceForContractType($form['contract_type']);
}

if ($isPostSave) {
    requireCsrf($_POST['csrf'] ?? null);

    $name      = trim((string)$form['name']);
    $city      = trim((string)$form['city']);
    $primary   = (int)($form['primary_contact'] ?: 0);
    $secondary = (int)($form['secondary_contact'] ?: 0);

    $lockers = $form['lockers'];
    if (!is_array($lockers)) {
        $lockers = [];
    }

    $contractType  = trim((string)$form['contract_type']);
    $contractStart = normDate((string)$form['contract_start']);
    $contractEnd   = normDate((string)$form['contract_end']);

    $allowedContractTypes = ['ABONNEMENT', 'INCIDENTEEL'];
    if ($contractType !== '' && !in_array($contractType, $allowedContractTypes, true)) {
        $contractType = '';
    }

    $monthlyFee = (float)$priceForContractType($contractType);

    if ($contractStart === '') {
        $contractStart = date('Y-m-d');
    }

    $repeatDaypart = trim((string)$form['repeat_daypart']);
    $repeatWeekday = (int)($form['repeat_weekday'] ?? 0);
    if ($repeatWeekday < 1 || $repeatWeekday > 7) {
        $repeatWeekday = 0;
    }

    $daypartTimes = [
        'OCHTEND' => ['11:00:00', '15:00:00'],
        'MIDDAG'  => ['15:00:00', '19:00:00'],
        'AVOND'   => ['19:00:00', '23:00:00'],
    ];

    $hasContractInput = ($contractType !== '' || $contractEnd !== '');
    $hasRepeatInput   = ($repeatDaypart !== '' || $repeatWeekday > 0);
    $contractComplete = ($contractType !== '' && $contractEnd !== '');

    try {
        if ($name === '') {
            throw new RuntimeException('Vul een bandnaam in.');
        }

        if (($hasContractInput || $hasRepeatInput) && !$contractComplete) {
            throw new RuntimeException('Vul voor een contract minimaal contracttype en contract-einddatum in.');
        }

        if ($contractType !== '' && !in_array($contractType, $allowedContractTypes, true)) {
            throw new RuntimeException('Ongeldig contracttype.');
        }

        if ($repeatDaypart !== '' && !isset($daypartTimes[$repeatDaypart])) {
            throw new RuntimeException('Ongeldig repeterend dagdeel.');
        }

        if ($repeatDaypart !== '' && $repeatWeekday <= 0) {
            throw new RuntimeException('Kies ook een weekdag voor het repeterende dagdeel.');
        }

        if ($repeatWeekday > 0 && $repeatDaypart === '') {
            throw new RuntimeException('Kies ook een dagdeel voor de herhaling.');
        }

        if ($contractComplete) {
            $start = new DateTimeImmutable($contractStart);
            $end   = new DateTimeImmutable($contractEnd);
            if ($start > $end) {
                throw new RuntimeException('Contractdatum vanaf mag niet na contractdatum tot liggen.');
            }
        }

        /*
         * Alle gekozen lockers moeten echt bestaan in de vaste lockerset.
         */
        foreach ($lockers as $lockerNo) {
            if (!in_array((string)$lockerNo, $validLockerNos, true)) {
                throw new RuntimeException("Ongeldige porkast geselecteerd: {$lockerNo}");
            }
        }

        $pdo->beginTransaction();

        if (!$isEdit) {
            $stmt = $pdo->prepare("
                INSERT INTO bands (name, city, primary_contact_id, secondary_contact_id)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $name,
                $city !== '' ? $city : null,
                $primary ?: null,
                $secondary ?: null
            ]);
            $id = (int)$pdo->lastInsertId();
            $isEdit = true;
        } else {
            $stmt = $pdo->prepare("
                UPDATE bands
                SET name = ?, city = ?, primary_contact_id = ?, secondary_contact_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name,
                $city !== '' ? $city : null,
                $primary ?: null,
                $secondary ?: null,
                $id
            ]);
        }

        /*
         * Eerst huidige band-lockers vrijgeven, daarna bestaande lockers opnieuw koppelen.
         * BELANGRIJK: hier worden geen nieuwe lockers toegevoegd.
         */
        $pdo->prepare("UPDATE lockers SET band_id = NULL WHERE band_id = ? AND deleted_at IS NULL")->execute([$id]);

        if ($lockers) {
            $chk = $pdo->prepare("
                SELECT locker_no, band_id
                FROM lockers
                WHERE locker_no = ? AND deleted_at IS NULL
                  AND band_id IS NOT NULL AND band_id <> ?
                LIMIT 1
            ");

            $upd = $pdo->prepare("
                UPDATE lockers
                SET band_id = ?
                WHERE locker_no = ? AND deleted_at IS NULL
            ");

            $exists = $pdo->prepare("
                SELECT id
                FROM lockers
                WHERE locker_no = ? AND deleted_at IS NULL
                LIMIT 1
            ");

            foreach ($lockers as $lockerNo) {
                $chk->execute([$lockerNo, $id]);
                $conf = $chk->fetch(PDO::FETCH_ASSOC);
                if ($conf) {
                    throw new RuntimeException("Kast {$lockerNo} is al toegewezen aan een andere band (band_id={$conf['band_id']}).");
                }

                $exists->execute([$lockerNo]);
                $ex = $exists->fetch(PDO::FETCH_ASSOC);
                if (!$ex) {
                    throw new RuntimeException("Kast {$lockerNo} bestaat niet in de vaste kastenset.");
                }

                $upd->execute([$id, $lockerNo]);
            }
        }

        $contractId = $currentContractId ?: null;

        if ($contractComplete) {
            if ($contractId) {
                $pdo->prepare("
                    UPDATE band_contracts
                    SET contract_type = ?, start_date = ?, end_date = ?, monthly_fee = ?
                    WHERE id = ? AND band_id = ?
                ")->execute([
                    $contractType,
                    $contractStart,
                    $contractEnd,
                    $monthlyFee,
                    (int)$contractId,
                    $id
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO band_contracts (band_id, contract_type, start_date, end_date, monthly_fee)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $id,
                    $contractType,
                    $contractStart,
                    $contractEnd,
                    $monthlyFee
                ]);
                $contractId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM band_planner_events WHERE contract_id = ?")->execute([(int)$contractId]);

            if ($repeatDaypart !== '' && isset($daypartTimes[$repeatDaypart]) && $repeatWeekday > 0) {
                [$startTime, $endTime] = $daypartTimes[$repeatDaypart];

                $start = new DateTimeImmutable($contractStart);
                $end   = new DateTimeImmutable($contractEnd);

                if ($start <= $end) {
                    $insEvent = $pdo->prepare("
                        INSERT INTO band_planner_events
                            (band_id, contract_id, event_date, daypart, start_time, end_time)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                        if ((int)$d->format('N') !== $repeatWeekday) {
                            continue;
                        }

                        $insEvent->execute([
                            $id,
                            (int)$contractId,
                            $d->format('Y-m-d'),
                            $repeatDaypart,
                            $startTime,
                            $endTime,
                        ]);
                    }
                }
            }
        } elseif ($currentContractId > 0) {
            $pdo->prepare("DELETE FROM band_planner_events WHERE contract_id = ?")->execute([$currentContractId]);
            $pdo->prepare("DELETE FROM band_contracts WHERE id = ? AND band_id = ?")->execute([$currentContractId, $id]);
        }

        auditLog($pdo, 'SAVE', 'band_edit id='.$id);

        $pdo->commit();
        header("Location: /admin/band_detail.php?id=".$id);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $err = $e->getMessage();
    }
}

$maxLockerSelects = 3;
$lockerSelections = $form['lockers'];
while (count($lockerSelections) < $maxLockerSelects) {
    $lockerSelections[] = '0';
}
$lockerSelections = array_slice($lockerSelections, 0, $maxLockerSelects);

$days = [
    1 => 'Maandag',
    2 => 'Dinsdag',
    3 => 'Woensdag',
    4 => 'Donderdag',
    5 => 'Vrijdag',
    6 => 'Zaterdag',
    7 => 'Zondag',
];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isEdit ? 'Band bewerken' : 'Nieuwe band' ?> - Porbeheer</title>

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
.wrap{width:min(1100px,96vw);}
.topbar{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
  margin-bottom:14px;
}
.brand h1{margin:0;font-size:28px}
.brand .sub{margin-top:6px;font-size:14px}
.brand .sub a{color:#fff;text-decoration:none;margin-right:14px}
.brand .sub a:hover{color:#ffd9b3}
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
  padding:24px;
}
label{display:block;margin-top:18px;font-weight:bold}
input,select{
  width:100%;
  padding:10px;
  margin-top:6px;
  border-radius:12px;
  border:none;
  outline:none;
  box-sizing:border-box;
}
.grid2{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
}
@media (max-width:900px){
  .grid2{grid-template-columns:1fr;}
}
.btn{
  margin-top:25px;
  padding:12px 18px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.3);
  background:linear-gradient(180deg,var(--glass),var(--glass2));
  color:#fff;
  cursor:pointer;
  font-weight:bold;
}
.btn:hover{border-color:rgba(255,255,255,.5)}
.btn-danger{border-color:rgba(255,120,120,.55);}
.btn-primary{border-color:rgba(120,180,255,.55);}
.smallnote{
  margin-top:8px;
  color:var(--muted);
  font-size:13px;
  line-height:1.4;
}
.alert{
  margin:10px 0 16px;
  padding:12px 14px;
  border:1px solid rgba(255,255,255,.25);
  border-radius:12px;
  background:rgba(255,255,255,.10);
}
.badge{
  display:inline-block;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  border:1px solid rgba(255,255,255,.25);
  background:rgba(255,90,90,.18);
  margin-left:10px;
}
.actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:18px;
}
.actions form{margin:0;}
a{color:#fff;text-decoration:none;transition:color .15s ease}
a:hover{color:#ffd9b3}
a:visited{color:#ffe0c2}
.readonly{
  background:rgba(255,255,255,.10);
  color:#fff;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const select = document.querySelector('select[name="contract_type"]');
  const tariff = document.getElementById('tariff_display');

  const prices = {
    'ABONNEMENT': '<?= h($subscriptionPrice) ?>',
    'INCIDENTEEL': '<?= h($incidentalPrice) ?>'
  };

  function updateTariff() {
    const v = select ? select.value : '';
    tariff.value = prices[v] || '0.00';
  }

  if (select && tariff) {
    select.addEventListener('change', updateTariff);
    updateTariff();
  }
});
</script>
</head>
<body>

<div class="backdrop">
<div class="wrap">

<div class="topbar">
  <div class="brand">
    <h1>
      <?= $isEdit ? 'Band bewerken' : 'Nieuwe band' ?>
      <?php if ($isDeleted): ?><span class="badge">Verwijderd</span><?php endif; ?>
    </h1>
    <div class="sub">
      <a href="/admin/bands.php">Overzicht</a>
      <?php if ($isEdit): ?>
        <a href="/admin/band_detail.php?id=<?= (int)$id ?>">Detail</a>
      <?php endif; ?>
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

<?php if ($err): ?>
  <div class="alert"><?= h($err) ?></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="id" value="<?= (int)$id ?>">
<input type="hidden" name="action" value="save">

<label>Bandnaam
  <input name="name" required value="<?= h($form['name']) ?>" <?= $isDeleted ? 'disabled' : '' ?>>
</label>

<label>Stad
  <input name="city" value="<?= h($form['city']) ?>" <?= $isDeleted ? 'disabled' : '' ?>>
</label>

<div class="grid2">
  <label>Eerste contact
    <select name="primary_contact" <?= $isDeleted ? 'disabled' : '' ?>>
      <option value="">-- geen --</option>
      <?php foreach ($contacts as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ((string)$form['primary_contact'] === (string)$c['id']) ? 'selected' : '' ?>>
          <?= h($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label>Tweede contact
    <select name="secondary_contact" <?= $isDeleted ? 'disabled' : '' ?>>
      <option value="">-- geen --</option>
      <?php foreach ($contacts as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ((string)$form['secondary_contact'] === (string)$c['id']) ? 'selected' : '' ?>>
          <?= h($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
</div>

<label>Porkasten (selecteer tot <?= (int)$maxLockerSelects ?> kasten)
  <div class="grid2">
    <?php for ($k = 0; $k < $maxLockerSelects; $k++): ?>
      <select name="lockers[]" <?= $isDeleted ? 'disabled' : '' ?>>
        <option value="0">-- geen --</option>
        <?php foreach ($validLockerNos as $lockerNo): ?>
          <option value="<?= h($lockerNo) ?>" <?= ((string)$lockerSelections[$k] === (string)$lockerNo) ? 'selected' : '' ?>>
            Kast <?= h($lockerNo) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endfor; ?>
  </div>
  <div class="smallnote">Er worden alleen bestaande vaste lockers gebruikt. Deze pagina maakt geen nieuwe kasten aan.</div>
</label>

<hr style="border:none;border-top:1px solid rgba(255,255,255,.12);margin:18px 0">

<label>Contract type
  <select name="contract_type" <?= $isDeleted ? 'disabled' : '' ?>>
    <option value="">-- geen --</option>
    <option value="ABONNEMENT" <?= ($form['contract_type'] === 'ABONNEMENT') ? 'selected' : '' ?>>ABONNEMENT</option>
    <option value="INCIDENTEEL" <?= ($form['contract_type'] === 'INCIDENTEEL') ? 'selected' : '' ?>>INCIDENTEEL</option>
  </select>
</label>

<div class="grid2">
  <label>Contract vanaf
    <input type="date" name="contract_start" value="<?= h((string)$form['contract_start']) ?>" <?= $isDeleted ? 'disabled' : '' ?>>
  </label>

  <label>Contract tot
    <input type="date" name="contract_end" value="<?= h((string)$form['contract_end']) ?>" <?= $isDeleted ? 'disabled' : '' ?>>
  </label>
</div>

<label>Tarief uit instellingen
  <input
    type="text"
    id="tariff_display"
    class="readonly"
    value="<?= h((string)$form['monthly_fee']) ?>"
    readonly
  >
  <div class="smallnote">
    Abonnement per maand: € <?= h($subscriptionPrice) ?> · Incidenteel per dagdeel: € <?= h($incidentalPrice) ?>
  </div>
</label>

<hr style="border:none;border-top:1px solid rgba(255,255,255,.12);margin:18px 0">

<label>Repeterend dagdeel (planner)
  <select name="repeat_daypart" <?= $isDeleted ? 'disabled' : '' ?>>
    <option value="">-- geen --</option>
    <option value="OCHTEND" <?= ($form['repeat_daypart'] === 'OCHTEND') ? 'selected' : '' ?>>Ochtend (11:00–15:00)</option>
    <option value="MIDDAG"  <?= ($form['repeat_daypart'] === 'MIDDAG')  ? 'selected' : '' ?>>Middag (15:00–19:00)</option>
    <option value="AVOND"   <?= ($form['repeat_daypart'] === 'AVOND')   ? 'selected' : '' ?>>Avond (19:00–23:00)</option>
  </select>
</label>

<label>Herhaal op (weekdag)
  <select name="repeat_weekday" <?= $isDeleted ? 'disabled' : '' ?>>
    <option value="">-- geen --</option>
    <?php foreach ($days as $num => $label): ?>
      <option value="<?= $num ?>" <?= ((int)$form['repeat_weekday'] === $num) ? 'selected' : '' ?>>
        <?= h($label) ?>
      </option>
    <?php endforeach; ?>
  </select>
</label>

<?php if (!$isDeleted): ?>
  <button class="btn" type="submit">Opslaan</button>
<?php else: ?>
  <div class="smallnote">Deze band is verwijderd. Herstel eerst om te kunnen wijzigen.</div>
<?php endif; ?>

</form>

<?php if ($isEdit): ?>
  <div class="actions">
    <?php if (!$isDeleted): ?>
      <form method="post" onsubmit="return confirm('Band verwijderen? (Je kunt later herstellen)');">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="soft_delete">
        <button class="btn btn-danger" type="submit">Verwijderen</button>
      </form>
    <?php else: ?>
      <form method="post" onsubmit="return confirm('Band herstellen?');">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="restore">
        <button class="btn btn-primary" type="submit">Herstellen</button>
      </form>

      <form method="post" onsubmit="return confirm('DEFINITIEF verwijderen? Planning wordt opgeschoond en lockers worden vrijgegeven. Dit kan niet ongedaan.');">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="hard_delete">
        <button class="btn btn-danger" type="submit">Definitief verwijderen</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

</div>
</div>
</div>
</body>
</html>