<?php
// /public/admin/locker_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('keys', $pdo);

auditLog($pdo, 'PAGE_VIEW', 'admin/locker_edit.php');

if (!function_exists('h')) {
  function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$errors = [];
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
  header('Location: /admin/keys.php');
  exit;
}

// Bands lijst
$bands = [];
try {
  $bands = $pdo->query("SELECT id, name FROM bands WHERE deleted_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $errors[] = 'Kan bands niet laden: ' . $e->getMessage();
}

// Locker record
$row = null;
try {
  $st = $pdo->prepare("
    SELECT l.*, b.name AS band_name
    FROM lockers l
    LEFT JOIN bands b ON b.id = l.band_id AND b.deleted_at IS NULL
    WHERE l.id = ? AND l.deleted_at IS NULL
  ");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    header('Location: /admin/keys.php?msg=notfound');
    exit;
  }
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    requireCsrf($_POST['csrf'] ?? '');

    $band_id = $_POST['band_id'] !== '' ? (int)$_POST['band_id'] : null;
    $notes = trim((string)($_POST['notes'] ?? ''));

    // band_id valideren (bestaat / of null)
    if ($band_id !== null) {
      $st = $pdo->prepare("SELECT id FROM bands WHERE id = ? AND deleted_at IS NULL");
      $st->execute([$band_id]);
      if (!$st->fetch()) $errors[] = 'Gekozen band bestaat niet (of is verwijderd).';
    }

    if (!$errors) {
      // notes kolom bestaat alleen als je SQL hierboven hebt uitgevoerd.
      // Heb je notes nog niet, comment dan de notes regel hieronder uit.
      $st = $pdo->prepare("
        UPDATE lockers
        SET band_id = ?, notes = ?
        WHERE id = ? AND deleted_at IS NULL
      ");
      $st->execute([
        $band_id,
        $notes !== '' ? $notes : null,
        $id
      ]);

      auditLog($pdo, 'LOCKER_UPDATE', 'locker_id=' . $id . ' band_id=' . ($band_id ?? 'NULL'));
      header('Location: /admin/keys.php?msg=saved');
      exit;
    }

    // terug in scherm
    $row['band_id'] = $band_id;
    $row['notes'] = $notes;

  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

$lockerNo = (string)($row['locker_no'] ?? '');
$currentBandName = (string)($row['band_name'] ?? '');
$currentBandId = $row['band_id'] !== null ? (int)$row['band_id'] : 0;
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Kast bewerken</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;  background-size:cover;
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
.field{margin:10px 0}
label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
input[type=text], select, textarea{
  width:100%; box-sizing:border-box; padding:10px 12px; border-radius:12px;
  border:1px solid rgba(255,255,255,.18); background:rgba(0,0,0,.25); color:#fff;
  outline:none;
}
textarea{min-height:110px;resize:vertical}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width: 820px){ .row{grid-template-columns:1fr} }
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08)}
.badge-ok{border-color:rgba(140,255,170,.35)}
.badge-warn{border-color:rgba(255,220,140,.35)}
  a{color:#fff;text-decoration:none;transition:color .15s ease}
  a:hover{color:#ffd9b3}
  a:visited{color:#ffe0c2}

</style>
</head>
<body>
<div class="backdrop"><div class="wrap">

  <div class="topbar">
    <div class="brand">
      <h1>Kast bewerken</h1>
      <div class="sub">Kasten kun je niet toevoegen/verwijderen — alleen toewijzen aan band of vrij maken.</div>
    </div>
    <div class="userbox">
      <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div class="line2">
        <a href="/admin/keys.php#kasten">← Terug</a>
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
      <h2>Kast <?= h($lockerNo) ?></h2>

      <form method="post" action="/admin/locker_edit.php?id=<?= (int)$id ?>">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="row">
          <div class="field">
            <label>Kastnummer</label>
            <input type="text" value="<?= h($lockerNo) ?>" readonly>
            <div class="small">Read-only (geen hernummering via deze pagina).</div>
          </div>

          <div class="field">
            <label>Band (of vrij)</label>
            <select name="band_id">
              <option value="">Vrij (geen band)</option>
              <?php foreach ($bands as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id'] === $currentBandId ? 'selected' : '') ?>>
                  <?= h((string)$b['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="small">Vrij maken: kies “Vrij (geen band)”.</div>
          </div>
        </div>

        <div class="field">
          <label>Notities (optioneel)</label>
          <textarea name="notes"><?= h((string)($row['notes'] ?? '')) ?></textarea>
          <div class="small">Gebruik dit voor opmerkingen over kast/inhoud/afspraken.</div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
          <button class="btn" type="submit">Opslaan</button>
          <a class="btn" href="/admin/keys.php#kasten">Annuleren</a>
        </div>
      </form>
    </div>
  </div>

</div></div>
</body>
</html>