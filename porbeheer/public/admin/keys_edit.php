<?php
// /public/admin/keys_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

auditLog($pdo, 'PAGE_VIEW', 'admin/keys_edit.php');

if (!function_exists('h')) {
  function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$err = null;
$msg = null;
$errors = [];

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$isEdit = $id > 0;

// Lockers lijst (voor LOCKER-type selectie)
$lockers = [];
try {
  $lockers = $pdo->query("
    SELECT l.id, l.locker_no, l.band_id, b.name AS band_name
    FROM lockers l
    LEFT JOIN bands b ON b.id = l.band_id AND b.deleted_at IS NULL
    WHERE l.deleted_at IS NULL
    ORDER BY (b.name IS NULL) ASC, b.name ASC, l.locker_no ASC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $errors[] = 'Kan kastenlijst niet laden: ' . $e->getMessage();
}

// Record laden
$row = [
  'id' => 0,
  'key_code' => '',
  'description' => '',
  'key_type' => 'LOCKER',
  'locker_id' => null,
  'key_slot' => null,
  'notes' => '',
  'active' => 1,
  'lost_at' => null,
  'lost_note' => '',
];

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  try {
    $st = $pdo->prepare("SELECT * FROM `keys` WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $db = $st->fetch(PDO::FETCH_ASSOC);
    if (!$db) {
      header('Location: /admin/keys.php?msg=notfound');
      exit;
    }
    $row = array_merge($row, $db);
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

// POST: opslaan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    requireCsrf($_POST['csrf'] ?? '');

    $key_code    = trim((string)($_POST['key_code'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $key_type    = (string)($_POST['key_type'] ?? 'LOCKER');
    $locker_id   = $_POST['locker_id'] !== '' ? (int)$_POST['locker_id'] : null;
    $key_slot    = $_POST['key_slot'] !== '' ? (int)$_POST['key_slot'] : null;
    $notes       = trim((string)($_POST['notes'] ?? ''));
    $active      = isset($_POST['active']) ? 1 : 0;

    $isLost      = isset($_POST['is_lost']);
    $lost_note   = trim((string)($_POST['lost_note'] ?? ''));

    $allowedTypes = ['LOCKER','MASTER','EXTERNAL'];
    if (!in_array($key_type, $allowedTypes, true)) $key_type = 'LOCKER';

    if ($key_code === '' || strlen($key_code) < 2) $errors[] = 'Sleutelnummer (key_code) is verplicht (minimaal 2 tekens).';

    // Type-regels
    if ($key_type === 'LOCKER') {
      if (!$locker_id || $locker_id <= 0) $errors[] = 'Kies een POR-kast voor type "Kast".';
    } else {
      // MASTER/EXTERNAL: geen locker en geen slot
      $locker_id = null;
      $key_slot  = null;
    }

    // Slot normaliseren
    if ($key_slot !== null && ($key_slot < 1 || $key_slot > 50)) {
      $errors[] = 'Sleutel slot moet tussen 1 en 50 liggen (of leeg laten).';
    }

    // Verloren logica: verloren => lost_at zetten & active=0
    $lost_at = null;
    if ($isLost) {
      $lost_at = date('Y-m-d H:i:s');
      $active = 0;
      if ($lost_note === '') $lost_note = 'Verloren';
    } else {
      // als niet verloren aangevinkt: lost_at verwijderen
      $lost_note = $lost_note; // mag leeg
      $lost_at = null;
    }

    // Uniek key_code check
    if (!$errors) {
      if ($isEdit) {
        $st = $pdo->prepare("SELECT id FROM `keys` WHERE key_code = ? AND deleted_at IS NULL AND id <> ? LIMIT 1");
        $st->execute([$key_code, $id]);
      } else {
        $st = $pdo->prepare("SELECT id FROM `keys` WHERE key_code = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$key_code]);
      }
      if ($st->fetch()) $errors[] = 'Sleutelnummer bestaat al. Kies een uniek key_code.';
    }

    if (!$errors) {
      if ($isEdit) {
        $st = $pdo->prepare("
          UPDATE `keys`
          SET key_code = ?, description = ?, key_type = ?, locker_id = ?, key_slot = ?, notes = ?, active = ?, lost_at = ?, lost_note = ?
          WHERE id = ? AND deleted_at IS NULL
        ");
        $st->execute([
          $key_code,
          $description !== '' ? $description : null,
          $key_type,
          $locker_id,
          $key_slot,
          $notes !== '' ? $notes : null,
          $active,
          $lost_at,
          $lost_note !== '' ? $lost_note : null,
          $id
        ]);
        auditLog($pdo, 'KEY_UPDATE', 'key_id=' . $id . ' code=' . $key_code);
      } else {
        $st = $pdo->prepare("
          INSERT INTO `keys` (key_code, description, key_type, locker_id, key_slot, notes, active, lost_at, lost_note)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
          $key_code,
          $description !== '' ? $description : null,
          $key_type,
          $locker_id,
          $key_slot,
          $notes !== '' ? $notes : null,
          $active,
          $lost_at,
          $lost_note !== '' ? $lost_note : null
        ]);
        $id = (int)$pdo->lastInsertId();
        auditLog($pdo, 'KEY_CREATE', 'key_id=' . $id . ' code=' . $key_code);
      }

      header('Location: /admin/keys.php?msg=saved');
      exit;
    }

    // terug in form
    $row = [
      'id' => $id,
      'key_code' => $key_code,
      'description' => $description,
      'key_type' => $key_type,
      'locker_id' => $locker_id,
      'key_slot' => $key_slot,
      'notes' => $notes,
      'active' => $active,
      'lost_at' => $isLost ? date('Y-m-d H:i:s') : null,
      'lost_note' => $lost_note,
    ];

  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

$title = $isEdit ? 'Sleutel bewerken' : 'Nieuwe sleutel';
$keyType = (string)($row['key_type'] ?? 'LOCKER');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - <?= h($title) ?></title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);
  background:url('/assets/images/keys-a.png') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;background:
  radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
  linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
  padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
.wrap{width:min(980px,96vw);}
.topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.brand h1{margin:0;font-size:28px;letter-spacing:.5px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);
  backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);min-width:260px;}
.userbox .line1{font-weight:bold}
.userbox .line2{color:var(--muted);margin-top:6px;font-size:13px;display:flex;gap:10px;flex-wrap:wrap}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);padding:18px;}
.card{border-radius:16px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));
  padding:14px;box-shadow:0 10px 22px rgba(0,0,0,.30);backdrop-filter:blur(10px);}
h2{margin:0 0 8px 0;font-size:18px}
.small{font-size:13px;color:var(--muted)}
a{color:#fff;text-decoration:none} a:hover{color:#ffd9b3}
.msg{margin:10px 0;font-size:13px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.err{color:#ffb3b3}
.btn{display:inline-block;padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));color:#fff;font-weight:800;cursor:pointer}
.btn:hover{border-color:rgba(255,255,255,.38);transform:translateY(-1px)}
.btn-sm{padding:6px 10px;font-weight:800;border-radius:10px}
.field{margin:10px 0}
label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
input[type=text], input[type=number], select, textarea{
  width:100%; box-sizing:border-box; padding:10px 12px; border-radius:12px;
  border:1px solid rgba(255,255,255,.18); background:rgba(0,0,0,.25); color:#fff;
  outline:none;
}
textarea{min-height:110px;resize:vertical}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width: 820px){ .row{grid-template-columns:1fr} }
hr.sep{border:none;border-top:1px solid rgba(255,255,255,.12);margin:14px 0}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08)}
.badge-warn{border-color:rgba(255,220,140,.35)}
.badge-ok{border-color:rgba(140,255,170,.35)}
.badge-off{border-color:rgba(190,190,190,.35);color:rgba(255,255,255,.9)}
.inline{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
</style>
</head>
<body>
<div class="backdrop"><div class="wrap">

  <div class="topbar">
    <div class="brand">
      <h1><?= h($title) ?></h1>
      <div class="sub">Maak sleutels bij of zet ze op verloren (geen delete).</div>
    </div>
    <div class="userbox">
      <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div class="line2">
        <a href="/admin/keys.php">← Terug</a>
        <a href="/admin/dashboard.php">Dashboard</a>
      </div>
    </div>
  </div>

  <div class="panel">
    <?php if ($errors): ?>
      <div class="msg err">
        <strong>Controleer:</strong>
        <ul style="margin:8px 0 0 18px">
          <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Gegevens</h2>

      <form method="post" action="/admin/keys_edit.php<?= $isEdit ? '?id=' . (int)$id : '' ?>">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="row">
          <div class="field">
            <label>Sleutelnummer (key_code)</label>
            <input type="text" name="key_code" value="<?= h((string)($row['key_code'] ?? '')) ?>" required>
            <div class="small">Uniek nummer/code op de sleutel.</div>
          </div>

          <div class="field">
            <label>Sleutelnaam (optioneel)</label>
            <input type="text" name="description" value="<?= h((string)($row['description'] ?? '')) ?>">
            <div class="small">Bijv. “Master sleutel beheerder”, “Kast 12 sleutel 1”.</div>
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label>Type sleutel</label>
            <select name="key_type" id="key_type">
              <option value="LOCKER" <?= $keyType==='LOCKER'?'selected':'' ?>>Kast (POR)</option>
              <option value="MASTER" <?= $keyType==='MASTER'?'selected':'' ?>>Master (past op alle POR-kasten)</option>
              <option value="EXTERNAL" <?= $keyType==='EXTERNAL'?'selected':'' ?>>Extern (past op geen POR-kast)</option>
            </select>
          </div>

          <div class="field" id="locker_field">
            <label>POR-kast</label>
            <select name="locker_id" id="locker_id">
              <option value="">— kies kast —</option>
              <?php
                $selLocker = $row['locker_id'] !== null ? (int)$row['locker_id'] : 0;
                foreach ($lockers as $l):
                  $lid = (int)$l['id'];
                  $txtBand = trim((string)($l['band_name'] ?? ''));
                  $txt = ($txtBand !== '' ? $txtBand . ' · ' : 'Vrij · ') . (string)$l['locker_no'];
              ?>
                <option value="<?= $lid ?>" <?= $selLocker===$lid?'selected':'' ?>><?= h($txt) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="small">Alleen verplicht bij type “Kast”.</div>
          </div>
        </div>

        <div class="row">
          <div class="field" id="slot_field">
            <label>Sleutel slot (optioneel)</label>
            <input type="number" name="key_slot" min="1" max="50" value="<?= h($row['key_slot'] !== null ? (string)$row['key_slot'] : '') ?>">
            <div class="small">Bijv. 1 of 2 (per kast standaard 2 sleutels).</div>
          </div>

          <div class="field">
            <label>Actief</label>
            <div class="inline">
              <label style="margin:0;color:#fff">
                <input type="checkbox" name="active" <?= ((int)($row['active'] ?? 1)===1 ? 'checked' : '') ?>>
                In gebruik / inzetbaar
              </label>
              <?php if (!empty($row['lost_at'])): ?>
                <span class="badge badge-warn">Huidig: Verloren</span>
              <?php endif; ?>
            </div>
            <div class="small">Bij “verloren” zetten we automatisch actief uit.</div>
          </div>
        </div>

        <hr class="sep">

        <h2 style="margin-top:0">Verloren</h2>

        <?php $isLostNow = !empty($row['lost_at']); ?>
        <div class="row">
          <div class="field">
            <label>Status</label>
            <div class="inline">
              <label style="margin:0;color:#fff">
                <input type="checkbox" name="is_lost" id="is_lost" <?= $isLostNow ? 'checked' : '' ?>>
                Zet deze sleutel op “verloren”
              </label>
              <?php if ($isLostNow): ?>
                <span class="badge badge-warn">Verloren sinds <?= h((string)$row['lost_at']) ?></span>
              <?php else: ?>
                <span class="badge badge-ok">Niet verloren</span>
              <?php endif; ?>
            </div>
            <div class="small">Geen delete; je kunt dit later terugdraaien.</div>
          </div>

          <div class="field">
            <label>Notitie (verloren)</label>
            <input type="text" name="lost_note" id="lost_note" value="<?= h((string)($row['lost_note'] ?? '')) ?>">
            <div class="small">Bijv. “verloren bij repetitie”, “kwijt na optreden”.</div>
          </div>
        </div>

        <hr class="sep">

        <div class="field">
          <label>Notities (algemeen)</label>
          <textarea name="notes"><?= h((string)($row['notes'] ?? '')) ?></textarea>
        </div>

        <div class="inline" style="margin-top:12px">
          <button class="btn" type="submit">Opslaan</button>
          <a class="btn btn-sm" href="/admin/keys.php">Annuleren</a>
        </div>

        <div class="small" style="margin-top:12px">
          Tip: Uitgifte/retour blijft via <code>Band → Sleutelbeheer</code> (transacties). Deze pagina beheert alleen het sleutel-item.
        </div>
      </form>
    </div>

  </div>

</div></div>

<script>
(function(){
  const keyTypeEl = document.getElementById('key_type');
  const lockerField = document.getElementById('locker_field');
  const slotField = document.getElementById('slot_field');
  const lockerIdEl = document.getElementById('locker_id');
  const isLostEl = document.getElementById('is_lost');
  const activeEl = document.querySelector('input[name="active"]');

  function applyTypeUI(){
    const t = (keyTypeEl.value || 'LOCKER');
    const isLocker = (t === 'LOCKER');
    lockerField.style.display = isLocker ? '' : 'none';
    slotField.style.display = isLocker ? '' : 'none';
    if (!isLocker){
      if (lockerIdEl) lockerIdEl.value = '';
      const slotInput = document.querySelector('input[name="key_slot"]');
      if (slotInput) slotInput.value = '';
    }
  }

  function applyLostUI(){
    if (!isLostEl || !activeEl) return;
    if (isLostEl.checked){
      activeEl.checked = false;
    }
  }

  if (keyTypeEl) keyTypeEl.addEventListener('change', applyTypeUI);
  if (isLostEl) isLostEl.addEventListener('change', applyLostUI);

  applyTypeUI();
})();
</script>
</body>
</html>