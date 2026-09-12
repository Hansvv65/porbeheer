<?php
/* admin/lockers_keys.php - Beheer van kasten (lockers) en losse sleutels (fysieke inventaris).
 * Dit is bewust NIET dezelfde plek als het toewijzen van een kast aan een band
 * en het uitgeven van sleutels -- dat gebeurt via de sleutelbeheer-pagina van
 * de band zelf (admin/band_keys.php). Hier gaat het puur om: welke kasten
 * bestaan er, en welke sleutels horen bij welke kast (nog geen band nodig). */

declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireSectionAccess('admin', 'read');

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('keys', $pdo);

auditLog($pdo, 'PAGE_VIEW', 'admin/lockers_keys.php');

$canWrite = hasSectionAccess($pdo, 'admin', 'write');
$tab = ((string)($_GET['tab'] ?? 'kasten') === 'sleutels') ? 'sleutels' : 'kasten';

$msg = null;
$err = null;

/* ========== POST: acties ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSectionAccess('admin', 'write');
    requireCsrf($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'locker_create') {
            $lockerNo = trim((string)($_POST['locker_no'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($lockerNo === '') throw new RuntimeException('Vul een kastnummer/naam in.');

            $chk = $pdo->prepare("SELECT id FROM lockers WHERE locker_no = ? AND deleted_at IS NULL");
            $chk->execute([$lockerNo]);
            if ($chk->fetchColumn()) throw new RuntimeException('Er bestaat al een kast met dit nummer/naam.');

            $ins = $pdo->prepare("INSERT INTO lockers (locker_no, notes) VALUES (?, ?)");
            $ins->execute([$lockerNo, $notes !== '' ? $notes : null]);
            $newId = (int)$pdo->lastInsertId();
            auditLog($pdo, 'CREATE', 'lockers', ['id' => $newId, 'locker_no' => $lockerNo]);
            header('Location: /admin/lockers_keys.php?tab=kasten&msg=saved');
            exit;
        }

        if ($action === 'locker_update') {
            $lockerId = (int)($_POST['locker_id'] ?? 0);
            $lockerNo = trim((string)($_POST['locker_no'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($lockerId <= 0) throw new RuntimeException('Onbekende kast.');
            if ($lockerNo === '') throw new RuntimeException('Vul een kastnummer/naam in.');

            $chk = $pdo->prepare("SELECT id FROM lockers WHERE locker_no = ? AND deleted_at IS NULL AND id != ?");
            $chk->execute([$lockerNo, $lockerId]);
            if ($chk->fetchColumn()) throw new RuntimeException('Er bestaat al een andere kast met dit nummer/naam.');

            $up = $pdo->prepare("UPDATE lockers SET locker_no = ?, notes = ? WHERE id = ?");
            $up->execute([$lockerNo, $notes !== '' ? $notes : null, $lockerId]);
            auditLog($pdo, 'UPDATE', 'lockers', ['id' => $lockerId, 'locker_no' => $lockerNo]);
            header('Location: /admin/lockers_keys.php?tab=kasten&msg=saved');
            exit;
        }

        if ($action === 'locker_delete') {
            $lockerId = (int)($_POST['locker_id'] ?? 0);
            if ($lockerId <= 0) throw new RuntimeException('Onbekende kast.');

            $chk = $pdo->prepare("SELECT COUNT(*) FROM `keys` WHERE locker_id = ? AND deleted_at IS NULL");
            $chk->execute([$lockerId]);
            if ((int)$chk->fetchColumn() > 0) {
                throw new RuntimeException('Deze kast heeft nog gekoppelde sleutels; ontkoppel die eerst.');
            }

            $pdo->prepare("UPDATE lockers SET deleted_at = NOW() WHERE id = ?")->execute([$lockerId]);
            auditLog($pdo, 'DELETE', 'lockers', ['id' => $lockerId]);
            header('Location: /admin/lockers_keys.php?tab=kasten&msg=deleted');
            exit;
        }

        if ($action === 'key_create' || $action === 'key_update') {
            $keyId = (int)($_POST['key_id'] ?? 0);
            $keyCode = trim((string)($_POST['key_code'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $keyType = (string)($_POST['key_type'] ?? 'LOCKER');
            $lockerId = (int)($_POST['locker_id'] ?? 0) ?: null;
            $keySlot = (int)($_POST['key_slot'] ?? 0) ?: null;
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($keyCode === '') throw new RuntimeException('Vul een sleutelcode in.');
            if (!in_array($keyType, ['LOCKER', 'MASTER', 'EXTERNAL'], true)) {
                throw new RuntimeException('Ongeldig sleuteltype.');
            }

            // Zelfde regel als de databaseconstraint: LOCKER-sleutels moeten een
            // kast + vaknummer (1 of 2) hebben; MASTER/EXTERNAL juist niet.
            if ($keyType === 'LOCKER') {
                if ($lockerId === null) throw new RuntimeException('Kies een kast voor deze sleutel.');
                if ($keySlot === null || $keySlot < 1) throw new RuntimeException('Vul een kopienummer in (1 of hoger).');
            } else {
                $lockerId = null;
                $keySlot = null;
            }

            $chkCode = $pdo->prepare("SELECT id FROM `keys` WHERE key_code = ? AND deleted_at IS NULL AND id != ?");
            $chkCode->execute([$keyCode, $keyId]);
            if ($chkCode->fetchColumn()) throw new RuntimeException('Er bestaat al een sleutel met deze code.');

            if ($lockerId !== null) {
                $chkSlot = $pdo->prepare("SELECT id FROM `keys` WHERE locker_id = ? AND key_slot = ? AND deleted_at IS NULL AND id != ?");
                $chkSlot->execute([$lockerId, $keySlot, $keyId]);
                if ($chkSlot->fetchColumn()) throw new RuntimeException('Kopie ' . $keySlot . ' van deze kast is al bezet door een andere sleutel.');
            }

            if ($action === 'key_create') {
                $ins = $pdo->prepare("
                    INSERT INTO `keys` (key_code, description, key_type, locker_id, key_slot, notes, active)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ");
                $ins->execute([$keyCode, $description ?: null, $keyType, $lockerId, $keySlot, $notes ?: null]);
                $newId = (int)$pdo->lastInsertId();
                auditLog($pdo, 'CREATE', 'keys', ['id' => $newId, 'key_code' => $keyCode]);
            } else {
                if ($keyId <= 0) throw new RuntimeException('Onbekende sleutel.');
                $up = $pdo->prepare("
                    UPDATE `keys` SET key_code=?, description=?, key_type=?, locker_id=?, key_slot=?, notes=?
                    WHERE id = ?
                ");
                $up->execute([$keyCode, $description ?: null, $keyType, $lockerId, $keySlot, $notes ?: null, $keyId]);
                auditLog($pdo, 'UPDATE', 'keys', ['id' => $keyId, 'key_code' => $keyCode]);
            }
            header('Location: /admin/lockers_keys.php?tab=sleutels&msg=saved');
            exit;
        }

        if ($action === 'key_delete') {
            $keyId = (int)($_POST['key_id'] ?? 0);
            if ($keyId <= 0) throw new RuntimeException('Onbekende sleutel.');
            $pdo->prepare("UPDATE `keys` SET deleted_at = NOW() WHERE id = ?")->execute([$keyId]);
            auditLog($pdo, 'DELETE', 'keys', ['id' => $keyId]);
            header('Location: /admin/lockers_keys.php?tab=sleutels&msg=deleted');
            exit;
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$qmsg = (string)($_GET['msg'] ?? '');
if ($qmsg === 'deleted') $msg = 'Verwijderd.';
if ($qmsg === 'saved')   $msg = 'Opgeslagen.';

/* ========== Data ophalen ========== */
$lockers = $pdo->query("
    SELECT l.id, l.locker_no, l.notes, b.name AS band_name,
           (SELECT COUNT(*) FROM `keys` k WHERE k.locker_id = l.id AND k.deleted_at IS NULL) AS key_count
    FROM lockers l
    LEFT JOIN bands b ON b.id = l.band_id
    WHERE l.deleted_at IS NULL
    ORDER BY l.locker_no
")->fetchAll(PDO::FETCH_ASSOC);

$keysList = $pdo->query("
    SELECT k.id, k.key_code, k.description, k.key_type, k.locker_id, k.key_slot, k.notes, k.active, k.lost_at,
           l.locker_no
    FROM `keys` k
    LEFT JOIN lockers l ON l.id = k.locker_id
    WHERE k.deleted_at IS NULL
    ORDER BY l.locker_no, k.key_slot, k.key_code
")->fetchAll(PDO::FETCH_ASSOC);

$lockerOptions = $pdo->query("SELECT id, locker_no FROM lockers WHERE deleted_at IS NULL ORDER BY locker_no")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Kasten & Sleutels beheer</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
.tabbar{display:flex;gap:8px;margin-bottom:14px;}
.tabbar a{
  padding:9px 16px;border-radius:10px;text-decoration:none;font-weight:800;font-size:14px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);color:var(--muted);
}
.tabbar a.active{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.35);color:#fff;}
.formrow{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.formrow .fld{display:flex;flex-direction:column;gap:4px;}
.formrow label{font-size:12px;color:var(--muted);}
.formrow input, .formrow select, .formrow textarea{
  background:rgba(0,0,0,.25);color:#fff;border:1px solid rgba(255,255,255,.22);
  border-radius:8px;padding:8px 10px;font-size:14px;
}
.formrow textarea{min-width:220px;min-height:38px;}
.inlineform{display:inline;}
.badge-ok{border-color:rgba(140,255,170,.35)}
.badge-warn{border-color:rgba(255,220,140,.35)}
.badge-off{border-color:rgba(190,190,190,.35);color:rgba(255,255,255,.9)}
</style>
</head>
<body style="background-image:url('<?= h($bg) ?>')">
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div class="brand">
        <h1><a href="/admin/dashboard.php">Porbeheer</a></h1>
        <div class="sub">POP Oefenruimte Zevenaar • kasten & sleutels beheer</div>
      </div>
      <div class="userbox">
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a>
          <a href="/admin/keys.php">Sleutels & Kasten</a>
          <a href="/logout.php">Uitloggen</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2>Kasten & sleutels beheer</h2>
      <p class="small">Fysieke inventaris: kasten aanmaken/hernoemen, en losse sleutels aanmaken en aan een kast koppelen.</p>
      <p class="small">💡 Volgorde: eerst hier een kast (en eventueel sleutels) aanmaken → daarna via een band de kast koppelen en sleutels uitgeven, op de sleutelbeheer-pagina van die band (<a href="/admin/bands.php">bands.php</a> → kies een band → "Sleutelbeheer").</p>

      <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

      <div class="tabbar">
        <a href="/admin/lockers_keys.php?tab=kasten" class="<?= $tab === 'kasten' ? 'active' : '' ?>">Kasten</a>
        <a href="/admin/lockers_keys.php?tab=sleutels" class="<?= $tab === 'sleutels' ? 'active' : '' ?>">Sleutels</a>
      </div>

      <?php if ($tab === 'kasten'): ?>

        <?php if ($canWrite): ?>
          <h3>Nieuwe kast</h3>
          <form method="post" class="formrow">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="locker_create">
            <div class="fld"><label>Kastnummer/naam</label><input type="text" name="locker_no" required></div>
            <div class="fld"><label>Notitie</label><textarea name="notes" rows="1"></textarea></div>
            <div class="fld"><button class="btn" type="submit">Aanmaken</button></div>
          </form>
        <?php endif; ?>

        <div class="tablewrap">
          <table>
            <tr><th>Kast</th><th>Band (huidig)</th><th>Notitie</th><th>Sleutels</th><?php if ($canWrite): ?><th></th><?php endif; ?></tr>
            <?php foreach ($lockers as $l): ?>
              <tr>
                <?php if ($canWrite): ?>
                  <td colspan="4">
                    <form method="post" class="formrow" style="margin-bottom:0;">
                      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                      <input type="hidden" name="action" value="locker_update">
                      <input type="hidden" name="locker_id" value="<?= (int)$l['id'] ?>">
                      <div class="fld"><input type="text" name="locker_no" value="<?= h($l['locker_no']) ?>" required style="width:110px;"></div>
                      <div class="fld"><span class="small"><?= h($l['band_name'] ?? '—') ?></span></div>
                      <div class="fld"><textarea name="notes" rows="1" style="min-width:260px;"><?= h((string)($l['notes'] ?? '')) ?></textarea></div>
                      <div class="fld"><span class="badge"><?= (int)$l['key_count'] ?></span></div>
                      <div class="fld"><button class="btn btn-sm" type="submit">Opslaan</button></div>
                    </form>
                  </td>
                  <td>
                    <?php if ((int)$l['key_count'] === 0): ?>
                      <form method="post" class="inlineform" onsubmit="return confirm('Kast verwijderen?');">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="locker_delete">
                        <input type="hidden" name="locker_id" value="<?= (int)$l['id'] ?>">
                        <button class="btn btn-sm" type="submit">Verwijderen</button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php else: ?>
                  <td><?= h($l['locker_no']) ?></td>
                  <td><?= h($l['band_name'] ?? '—') ?></td>
                  <td><?= h((string)($l['notes'] ?? '')) ?></td>
                  <td><span class="badge"><?= (int)$l['key_count'] ?></span></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            <?php if (!$lockers): ?><tr><td colspan="5" class="small">Geen kasten gevonden.</td></tr><?php endif; ?>
          </table>
        </div>

      <?php else: /* tab = sleutels */ ?>

        <?php if ($canWrite): ?>
          <h3>Nieuwe sleutel</h3>
          <form method="post" class="formrow">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="key_create">
            <div class="fld"><label>Sleutelcode</label><input type="text" name="key_code" required></div>
            <div class="fld"><label>Omschrijving</label><input type="text" name="description"></div>
            <div class="fld"><label>Type</label>
              <select name="key_type" onchange="this.form.locker_id.disabled=this.form.key_slot.disabled=(this.value!=='LOCKER');">
                <option value="LOCKER">Kastsleutel</option>
                <option value="MASTER">Master</option>
                <option value="EXTERNAL">Extern</option>
              </select>
            </div>
            <div class="fld"><label>Kast</label>
              <select name="locker_id">
                <option value="">-- kies kast --</option>
                <?php foreach ($lockerOptions as $lo): ?>
                  <option value="<?= (int)$lo['id'] ?>"><?= h($lo['locker_no']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fld"><label>Kopie</label>
              <input type="number" name="key_slot" min="1" step="1" value="1" style="width:70px;">
            </div>
            <div class="fld"><label>Notitie</label><textarea name="notes" rows="1"></textarea></div>
            <div class="fld"><button class="btn" type="submit">Aanmaken</button></div>
          </form>
        <?php endif; ?>

        <div class="tablewrap">
          <table>
            <tr><th>Code</th><th>Omschrijving</th><th>Type</th><th>Kast</th><th>Kopie</th><th>Status</th><?php if ($canWrite): ?><th></th><?php endif; ?></tr>
            <?php foreach ($keysList as $k): ?>
              <tr>
                <td><?= h($k['key_code']) ?></td>
                <td><?= h((string)($k['description'] ?? '')) ?></td>
                <td><span class="badge"><?= h($k['key_type']) ?></span></td>
                <td><?= h($k['locker_no'] ?? '—') ?></td>
                <td><?= h($k['key_slot'] !== null ? (string)$k['key_slot'] : '—') ?></td>
                <td><?= $k['active'] ? '<span class="badge badge-ok">actief</span>' : '<span class="badge badge-off">inactief</span>' ?><?= $k['lost_at'] ? ' <span class="badge badge-warn">vermist</span>' : '' ?></td>
                <?php if ($canWrite): ?>
                  <td>
                    <form method="post" class="inlineform" onsubmit="return confirm('Sleutel verwijderen?');">
                      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                      <input type="hidden" name="action" value="key_delete">
                      <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                      <button class="btn btn-sm" type="submit">Verwijderen</button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            <?php if (!$keysList): ?><tr><td colspan="7" class="small">Geen sleutels gevonden.</td></tr><?php endif; ?>
          </table>
        </div>

      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
