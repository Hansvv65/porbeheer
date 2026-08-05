<?php
declare(strict_types=1);

/*
 * admin/band_keys.php
 *
 * Centrale band-pagina (samenvoeging van band_keys.php + locker_edit.php):
 *   - POR-kasten aan de band toewijzen / vrijmaken, met notitie per kast
 *   - Sleutels & contracten: staat van elke sleutel + contractstatus,
 *     met doorlink naar de contractpagina (contract_edit.php) en Print + QR
 *   - Sleutels uitgeven / retour registreren
 *   - Historie
 *
 * Vervangt locker_edit.php. Vrije kasten wijs je vanaf de band toe.
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('bands', $pdo);

if (!function_exists('h')) {
    function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$bandId = (int)($_GET['band_id'] ?? ($_POST['band_id'] ?? 0));
if ($bandId <= 0) { header('Location: /admin/bands.php'); exit; }

auditLog($pdo, 'PAGE_VIEW', 'admin/band_keys.php band_id=' . $bandId);

/* ===== Band ===== */
$st = $pdo->prepare("SELECT id, name, primary_contact_id, secondary_contact_id FROM bands WHERE id = ? AND deleted_at IS NULL");
$st->execute([$bandId]);
$band = $st->fetch(PDO::FETCH_ASSOC);
if (!$band) { http_response_code(404); exit('Band niet gevonden.'); }

/* ===== Contacten van deze band ===== */
$contacts = $pdo->prepare("
    SELECT DISTINCT c.id, c.name, c.email, c.phone
    FROM contacts c
    LEFT JOIN band_contacts bc ON bc.contact_id = c.id
    WHERE c.deleted_at IS NULL AND (bc.band_id = ? OR c.id = ? OR c.id = ?)
    ORDER BY c.name
");
$contacts->execute([$bandId, (int)($band['primary_contact_id'] ?? 0), (int)($band['secondary_contact_id'] ?? 0)]);
$contactList = $contacts->fetchAll(PDO::FETCH_ASSOC);

/* ===== Kasten: vrij + toegewezen ===== */
$freeLockers = $pdo->query("SELECT id, locker_no, notes FROM lockers WHERE deleted_at IS NULL AND band_id IS NULL ORDER BY locker_no")->fetchAll(PDO::FETCH_ASSOC);

$bandLockersSt = $pdo->prepare("SELECT id, locker_no, notes FROM lockers WHERE deleted_at IS NULL AND band_id = ? ORDER BY locker_no");
$bandLockersSt->execute([$bandId]);
$bandLockers = $bandLockersSt->fetchAll(PDO::FETCH_ASSOC);

/* ===== Sleutels beschikbaar voor ISSUE (laatste transactie != ISSUE) ===== */
$availableKeys = $pdo->prepare("
    SELECT k.id, k.key_code, k.description, k.key_slot, l.locker_no
    FROM `keys` k
    JOIN lockers l ON l.id = k.locker_id AND l.deleted_at IS NULL
    LEFT JOIN key_transactions kt_last
      ON kt_last.key_id = k.id
     AND CONCAT(DATE_FORMAT(kt_last.action_at, '%Y-%m-%d %H:%i:%s'),'|',LPAD(kt_last.id,10,'0')) =
         (SELECT MAX(CONCAT(DATE_FORMAT(kt2.action_at, '%Y-%m-%d %H:%i:%s'),'|',LPAD(kt2.id,10,'0')))
          FROM key_transactions kt2 WHERE kt2.key_id = k.id)
    WHERE k.deleted_at IS NULL AND k.key_type = 'LOCKER' AND k.lost_at IS NULL AND l.band_id = ?
      AND (kt_last.id IS NULL OR kt_last.action <> 'ISSUE')
    ORDER BY l.locker_no, k.key_slot, k.key_code
");
$availableKeys->execute([$bandId]);
$availableKeyList = $availableKeys->fetchAll(PDO::FETCH_ASSOC);

/* ===== Uitgegeven sleutels (laatste transactie = ISSUE) — gebruikt voor status én retour ===== */
$issued = $pdo->prepare("
    SELECT kt.id AS issue_id, k.id AS key_id, k.key_code, k.description, k.key_slot, l.locker_no,
           c.id AS contact_id, c.name AS contact_name, kt.action_at AS issued_at, kt.notes
    FROM key_transactions kt
    JOIN `keys` k ON k.id = kt.key_id AND k.deleted_at IS NULL
    LEFT JOIN lockers l ON l.id = k.locker_id AND l.deleted_at IS NULL
    LEFT JOIN contacts c ON c.id = kt.contact_id AND c.deleted_at IS NULL
    WHERE kt.band_id = ? AND kt.action = 'ISSUE'
      AND CONCAT(DATE_FORMAT(kt.action_at, '%Y-%m-%d %H:%i:%s'),'|',LPAD(kt.id,10,'0')) =
          (SELECT MAX(CONCAT(DATE_FORMAT(kt2.action_at, '%Y-%m-%d %H:%i:%s'),'|',LPAD(kt2.id,10,'0')))
           FROM key_transactions kt2 WHERE kt2.key_id = kt.key_id)
    ORDER BY kt.action_at DESC, kt.id DESC
");
$issued->execute([$bandId]);
$issuedList = $issued->fetchAll(PDO::FETCH_ASSOC);
$issuedByKey = [];
foreach ($issuedList as $a) { $issuedByKey[(int)$a['key_id']] = $a; }

/* ===== Alle sleutels van de band + contractstatus ===== */
$bandKeysSt = $pdo->prepare("
    SELECT k.id, k.key_code, k.description, k.key_slot, k.lost_at, l.locker_no,
           kc.id AS contract_id, kc.contract_number, (kc.contract_pdf IS NOT NULL) AS has_blob
    FROM `keys` k
    JOIN lockers l ON l.id = k.locker_id AND l.deleted_at IS NULL
    LEFT JOIN key_contracts kc
      ON kc.id = (SELECT kc2.id FROM key_contracts kc2 WHERE kc2.key_id = k.id ORDER BY kc2.created_at DESC LIMIT 1)
    WHERE k.deleted_at IS NULL AND l.band_id = ?
    ORDER BY l.locker_no, k.key_slot, k.key_code
");
$bandKeysSt->execute([$bandId]);
$bandKeyList = $bandKeysSt->fetchAll(PDO::FETCH_ASSOC);

// Getekende documenten per contract (nieuwe tabel; veilig opvangen als die ontbreekt)
$signedByContract = [];
try {
    $cids = array_values(array_filter(array_map(static fn($r) => (int)($r['contract_id'] ?? 0), $bandKeyList)));
    if ($cids) {
        $in = implode(',', array_fill(0, count($cids), '?'));
        $ds = $pdo->prepare("SELECT key_contract_id, COUNT(*) c FROM key_contract_documents
                             WHERE kind='SIGNED' AND deleted_at IS NULL AND is_current=1 AND key_contract_id IN ($in)
                             GROUP BY key_contract_id");
        $ds->execute($cids);
        foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $r) { $signedByContract[(int)$r['key_contract_id']] = (int)$r['c']; }
    }
} catch (Throwable $e) { /* documenten-tabel mogelijk nog niet gemigreerd */ }

/* ===== Historie ===== */
$hist = $pdo->prepare("
    SELECT kt.id, kt.action, kt.action_at, kt.notes, k.key_code, k.key_slot, l.locker_no,
           c.name AS contact_name, u.username AS performed_by
    FROM key_transactions kt
    JOIN `keys` k ON k.id = kt.key_id AND k.deleted_at IS NULL
    LEFT JOIN lockers l ON l.id = k.locker_id
    LEFT JOIN contacts c ON c.id = kt.contact_id
    LEFT JOIN users u ON u.id = kt.performed_by_user_id
    WHERE kt.band_id = ?
    ORDER BY kt.action_at DESC, kt.id DESC
    LIMIT 50
");
$hist->execute([$bandId]);
$history = $hist->fetchAll(PDO::FETCH_ASSOC);

/* ===== POST: kast toewijzen / vrijmaken / notitie / transactie ===== */
$msg = null; $err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? null);
    $mode = (string)($_POST['mode'] ?? 'tx');

    try {
        if ($mode === 'locker') {
            $op = (string)($_POST['op'] ?? '');
            $lockerId = (int)($_POST['locker_id'] ?? 0);
            if ($lockerId <= 0) throw new RuntimeException('Kies een kast.');

            $pdo->beginTransaction();

            if ($op === 'ASSIGN') {
                $chk = $pdo->prepare("SELECT band_id FROM lockers WHERE id = ? AND deleted_at IS NULL LIMIT 1");
                $chk->execute([$lockerId]);
                $cur = $chk->fetchColumn();
                if ($cur === false) throw new RuntimeException('Kast bestaat niet (meer).');
                if ($cur !== null && (int)$cur !== 0) throw new RuntimeException('Deze kast is al aan een band toegewezen.');

                $up = $pdo->prepare("UPDATE lockers SET band_id = ? WHERE id = ? AND deleted_at IS NULL AND band_id IS NULL");
                $up->execute([$bandId, $lockerId]);
                if ($up->rowCount() < 1) throw new RuntimeException('Kast kon niet worden toegewezen (mogelijk niet meer vrij).');

                auditLog($pdo, 'LOCKER_ASSIGN', 'lockers/assign', ['band_id'=>$bandId, 'locker_id'=>$lockerId]);
                $msg = 'Kast toegewezen aan band.';

            } elseif ($op === 'UNASSIGN') {
                $up = $pdo->prepare("UPDATE lockers SET band_id = NULL WHERE id = ? AND deleted_at IS NULL AND band_id = ?");
                $up->execute([$lockerId, $bandId]);
                if ($up->rowCount() < 1) throw new RuntimeException('Kast kon niet worden vrijgemaakt (hoort mogelijk niet bij deze band).');

                auditLog($pdo, 'LOCKER_UNASSIGN', 'lockers/unassign', ['band_id'=>$bandId, 'locker_id'=>$lockerId]);
                $msg = 'Kast vrijgemaakt.';

            } elseif ($op === 'NOTES') {
                $notes = trim((string)($_POST['notes'] ?? ''));
                $up = $pdo->prepare("UPDATE lockers SET notes = ? WHERE id = ? AND deleted_at IS NULL AND band_id = ?");
                $up->execute([$notes !== '' ? $notes : null, $lockerId, $bandId]);
                auditLog($pdo, 'LOCKER_UPDATE', 'locker_id=' . $lockerId);
                $msg = 'Notitie opgeslagen.';

            } else {
                throw new RuntimeException('Ongeldige kast-actie.');
            }

            $pdo->commit();
            header("Location: /admin/band_keys.php?band_id=" . $bandId . "&ok=1");
            exit;
        }

        // ----- transactie (ISSUE / RETURN) -----
        $action = (string)($_POST['action'] ?? '');
        $keyId = (int)($_POST['key_id'] ?? 0);
        $contactId = (int)($_POST['contact_id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));

        if (!in_array($action, ['ISSUE','RETURN'], true)) throw new RuntimeException('Ongeldige actie.');
        if ($keyId <= 0) throw new RuntimeException('Kies een sleutel.');
        if ($action === 'ISSUE' && $contactId <= 0) throw new RuntimeException('Bij uitgifte moet een contact gekozen worden.');

        $pdo->beginTransaction();

        if ($action === 'ISSUE') {
            $chk = $pdo->prepare("
                SELECT k.id FROM `keys` k
                JOIN lockers l ON l.id = k.locker_id AND l.deleted_at IS NULL
                LEFT JOIN key_transactions kt_last
                  ON kt_last.key_id = k.id
                 AND CONCAT(DATE_FORMAT(kt_last.action_at, '%Y-%m-%d %H:%i:%s'),'|',LPAD(kt_last.id,10,'0')) =
                     (SELECT MAX(CONCAT(DATE_FORMAT(kt2.action_at, '%Y-%m-%d %H:%i:%s'),'|',LPAD(kt2.id,10,'0')))
                      FROM key_transactions kt2 WHERE kt2.key_id = k.id)
                WHERE k.id = ? AND k.deleted_at IS NULL AND k.key_type = 'LOCKER' AND k.lost_at IS NULL AND l.band_id = ?
                  AND (kt_last.id IS NULL OR kt_last.action <> 'ISSUE')
                LIMIT 1
            ");
            $chk->execute([$keyId, $bandId]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Deze sleutel is niet beschikbaar voor uitgifte.');
        } else {
            $chk = $pdo->prepare("
                SELECT kt.id FROM key_transactions kt
                WHERE kt.band_id = ? AND kt.key_id = ? AND kt.action = 'ISSUE'
                  AND CONCAT(DATE_FORMAT(kt.action_at, '%Y-%m-%d %H:%i:%s'),'|',LPAD(kt.id,10,'0')) =
                      (SELECT MAX(CONCAT(DATE_FORMAT(kt2.action_at, '%Y-%m-%d %H:%i:%s'),'|',LPAD(kt2.id,10,'0')))
                       FROM key_transactions kt2 WHERE kt2.key_id = kt.key_id)
                LIMIT 1
            ");
            $chk->execute([$bandId, $keyId]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Deze sleutel staat niet als actief uitgegeven. Retour kan niet.');

            if ($contactId <= 0) {
                $lastIssue = $pdo->prepare("SELECT contact_id FROM key_transactions WHERE band_id = ? AND key_id = ? AND action = 'ISSUE' ORDER BY action_at DESC, id DESC LIMIT 1");
                $lastIssue->execute([$bandId, $keyId]);
                $cid = (int)($lastIssue->fetchColumn() ?? 0);
                $contactId = $cid > 0 ? $cid : 0;
            }
        }

        $uid = (int)($user['id'] ?? 0);
        $ins = $pdo->prepare("INSERT INTO key_transactions (band_id, key_id, contact_id, action, action_at, performed_by_user_id, notes) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
        $ins->execute([$bandId, $keyId, ($contactId > 0 ? $contactId : null), $action, ($uid > 0 ? $uid : null), ($notes !== '' ? $notes : null)]);

        auditLog($pdo, $action, 'keys/transaction', ['band_id'=>$bandId, 'key_id'=>$keyId, 'contact_id'=>($contactId > 0 ? $contactId : null)]);

        $pdo->commit();
        header("Location: /admin/band_keys.php?band_id=" . $bandId . "&ok=1");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

if (isset($_GET['ok']) && !$msg) $msg = 'Opgeslagen.';

/* helper: contractstatus van een sleutelrij */
function keyContractStatus(array $r, array $signedByContract): array {
    $cid = (int)($r['contract_id'] ?? 0);
    if ($cid <= 0) return ['label' => 'Geen contract', 'cls' => 'st-mut'];
    $signed = ($signedByContract[$cid] ?? 0) > 0 || !empty($r['has_blob']);
    return $signed ? ['label' => 'Getekend ✓', 'cls' => 'st-ok'] : ['label' => 'Niet getekend', 'cls' => 'st-warn'];
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Sleutels &amp; contracten</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('<?= h($bg) ?>') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
.wrap{width:min(1200px,96vw);}
.topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.brand h1{margin:0;font-size:28px;letter-spacing:.5px}
.bandpill{display:inline-block;margin-top:10px;padding:10px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.20);background:linear-gradient(180deg, rgba(29,53,87,.70), rgba(29,53,87,.35));box-shadow:0 10px 22px rgba(0,0,0,.30);font-weight:900;font-size:18px;}
.bandpill .small{display:block;margin-top:6px;font-weight:600;font-size:12px;color:rgba(255,255,255,.85)}
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);backdrop-filter:blur(10px);min-width:260px;}
.userbox .line1{font-weight:bold}
.userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:18px;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media (max-width:960px){.grid{grid-template-columns:1fr}.userbox{min-width:unset;width:100%}}
.card{border-radius:16px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));padding:14px;box-shadow:0 10px 22px rgba(0,0,0,.30);backdrop-filter:blur(10px);margin-bottom:14px;}
h2{margin:0 0 8px 0;font-size:18px}
.small{font-size:13px;color:var(--muted)}
.tablewrap{overflow:auto;border-radius:14px;border:1px solid rgba(255,255,255,.12)}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.12);text-align:left;font-size:14px;vertical-align:top}
th{background:rgba(255,255,255,.06)}
a{color:#fff;text-decoration:none;transition:color .15s ease}
a:hover{color:#ffd9b3}
.msg{margin:10px 0;font-size:13px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.err{color:#ffb3b3}
input,select,textarea{width:100%;padding:10px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.25);color:#fff;outline:none;margin-top:6px;box-sizing:border-box}
label{display:block;margin-top:10px;font-size:13px;color:var(--muted);font-weight:700}
.btn{display:inline-block;margin-top:12px;padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:linear-gradient(180deg, var(--glass), var(--glass2));color:#fff;font-weight:800;cursor:pointer}
.btn:hover{border-color:rgba(255,255,255,.38);transform:translateY(-1px)}
.btn-sm{margin-top:0;padding:6px 10px;font-weight:800;border-radius:10px;font-size:13px}
.btn-info{background:linear-gradient(180deg,#17a2b8,#117a8b);border-color:#3ab5d4;}
.btn-warning{background:linear-gradient(180deg,#ffc107,#e0a800);border-color:#ffce3a;color:#333;}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:720px){.row{grid-template-columns:1fr}}
.hint{margin-top:6px;font-size:12px;color:rgba(255,255,255,.70)}
.st{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid rgba(255,255,255,.2);}
.st-ok{background:rgba(34,197,94,.22);color:#bbf7d0;border-color:rgba(34,197,94,.4);}
.st-warn{background:rgba(255,193,7,.18);color:#ffe08a;border-color:rgba(255,193,7,.4);}
.st-bad{background:rgba(248,113,113,.18);color:#fecaca;border-color:rgba(248,113,113,.4);}
.st-mut{background:rgba(255,255,255,.1);color:rgba(255,255,255,.85);}
details summary{cursor:pointer;color:var(--muted);font-size:13px;}
.actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;}
</style>
<script>
function toggleKeyOptions(){
  var actionEl = document.getElementById('action');
  var action = actionEl ? actionEl.value : 'ISSUE';
  var isIssue = (action === 'ISSUE');
  var contactWrap = document.getElementById('contactWrap');
  var contactSel  = document.getElementById('contact_id');
  if (contactWrap) contactWrap.style.display = isIssue ? '' : 'none';
  if (contactSel)  contactSel.required = isIssue;
  var sel = document.getElementById('key_id');
  if (!sel) return;
  var current = sel.value, currentOk = false;
  for (var i=0;i<sel.options.length;i++){
    var opt = sel.options[i], mode = opt.getAttribute('data-mode');
    if (!mode){ opt.disabled=false; continue; }
    var ok = (mode === action);
    opt.disabled = !ok;
    if (ok && current && opt.value === current) currentOk = true;
  }
  if (current && !currentOk) sel.value = '';
}
</script>
</head>
<body onload="toggleKeyOptions()">
<div class="backdrop"><div class="wrap">

  <div class="topbar">
    <div class="brand">
      <h1>Sleutels &amp; contracten</h1>
      <div class="bandpill"><?= h($band['name']) ?><span class="small">Band in behandeling</span></div>
    </div>
    <div class="userbox">
      <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h((string)$role) ?></div>
      <div class="line2"><a href="/admin/bands.php">Bands</a> • <a href="/admin/keys.php">Sleutels</a> • <a href="/admin/dashboard.php">Dashboard</a></div>
    </div>
  </div>

  <div class="panel">

    <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

    <!-- ===== Sleutels & contracten ===== -->
    <div class="card">
      <h2>Sleutels &amp; contracten</h2>
      <div class="small">Staat van elke sleutel van deze band en de bijbehorende contractstatus.</div>
      <div class="tablewrap" style="margin-top:10px">
        <table>
          <thead><tr><th>Kast · sleutel</th><th>Status</th><th>Contract</th><th>Acties</th></tr></thead>
          <tbody>
          <?php foreach ($bandKeyList as $k):
              $kid = (int)$k['id'];
              $iss = $issuedByKey[$kid] ?? null;
              if (!empty($k['lost_at'])) { $stLabel='Vermist'; $stCls='st-bad'; }
              elseif ($iss) { $stLabel='Uitgegeven'; $stCls='st-warn'; }
              else { $stLabel='Beschikbaar'; $stCls='st-ok'; }
              $cs = keyContractStatus($k, $signedByContract);
              $hasContract = (int)($k['contract_id'] ?? 0) > 0;
          ?>
            <tr>
              <td>
                <strong><?= h((string)($k['locker_no'] ?? '—')) ?></strong> · sleutel <?= h((string)($k['key_slot'] ?? '—')) ?>
                <div class="small"><?= h((string)$k['key_code']) ?><?php if (!empty($k['description'])): ?> — <?= h((string)$k['description']) ?><?php endif; ?></div>
              </td>
              <td>
                <span class="st <?= $stCls ?>"><?= h($stLabel) ?></span>
                <?php if ($iss): ?><div class="small">aan <?= h((string)($iss['contact_name'] ?? '—')) ?><br><?= h(date('d-m-Y', strtotime((string)$iss['issued_at']))) ?></div><?php endif; ?>
              </td>
              <td><span class="st <?= $cs['cls'] ?>"><?= h($cs['label']) ?></span></td>
              <td>
                <div class="actions">
                  <a class="btn btn-sm btn-info" href="/admin/contract_edit.php?id=<?= $kid ?>"><?= $hasContract ? '📄 Contract' : '📝 Opstellen' ?></a>
                  <?php if ($hasContract): ?>
                    <a class="btn btn-sm btn-warning" href="/admin/contract_print.php?key_id=<?= $kid ?>" target="_blank">🖨️ Print + QR</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$bandKeyList): ?>
            <tr><td colspan="4" class="small">Geen sleutels. Wijs eerst een kast toe en zorg dat daar sleutels aan hangen.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="grid">

      <!-- ===== POR-kasten ===== -->
      <div class="card">
        <h2>POR-kasten van deze band</h2>
        <div class="small">Koppel een vrije kast of maak een kast weer vrij. Kasten zelf kun je niet toevoegen/verwijderen.</div>

        <form method="post" action="/admin/band_keys.php?band_id=<?= (int)$bandId ?>">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="band_id" value="<?= (int)$bandId ?>">
          <input type="hidden" name="mode" value="locker">
          <input type="hidden" name="op" value="ASSIGN">
          <label>Vrije kast toewijzen</label>
          <select name="locker_id" required>
            <option value="">-- kies kast --</option>
            <?php foreach ($freeLockers as $l): ?>
              <option value="<?= (int)$l['id'] ?>"><?= h((string)$l['locker_no']) ?><?= !empty($l['notes']) ? ' — ' . h((string)$l['notes']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$freeLockers): ?><div class="hint">Geen vrije kasten beschikbaar.</div><?php endif; ?>
          <button class="btn" type="submit">Kast toewijzen</button>
        </form>

        <div class="tablewrap" style="margin-top:12px">
          <table>
            <thead><tr><th>Toegewezen kast</th><th style="width:120px">Actie</th></tr></thead>
            <tbody>
            <?php foreach ($bandLockers as $l): ?>
              <tr>
                <td>
                  <strong><?= h((string)$l['locker_no']) ?></strong>
                  <?php if (!empty($l['notes'])): ?><div class="small"><?= h((string)$l['notes']) ?></div><?php endif; ?>
                  <details style="margin-top:6px;">
                    <summary>✏️ Notitie</summary>
                    <form method="post" action="/admin/band_keys.php?band_id=<?= (int)$bandId ?>" style="margin-top:6px">
                      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                      <input type="hidden" name="band_id" value="<?= (int)$bandId ?>">
                      <input type="hidden" name="mode" value="locker">
                      <input type="hidden" name="op" value="NOTES">
                      <input type="hidden" name="locker_id" value="<?= (int)$l['id'] ?>">
                      <textarea name="notes" rows="2" placeholder="Opmerking over kast/inhoud/afspraken"><?= h((string)($l['notes'] ?? '')) ?></textarea>
                      <button class="btn btn-sm" type="submit" style="margin-top:6px">Opslaan</button>
                    </form>
                  </details>
                </td>
                <td>
                  <form method="post" action="/admin/band_keys.php?band_id=<?= (int)$bandId ?>" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="band_id" value="<?= (int)$bandId ?>">
                    <input type="hidden" name="mode" value="locker">
                    <input type="hidden" name="op" value="UNASSIGN">
                    <input type="hidden" name="locker_id" value="<?= (int)$l['id'] ?>">
                    <button class="btn btn-sm" type="submit">Vrijmaken</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$bandLockers): ?><tr><td colspan="2" class="small">Nog geen kasten toegewezen.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ===== Transactie ===== -->
      <div class="card">
        <h2>Sleutel uitgeven / retour</h2>
        <div class="small">Registreer een uitgifte of retour. Voor de sleutelovereenkomst gebruik je de contractpagina (knop “Contract” hierboven).</div>

        <form method="post" action="/admin/band_keys.php?band_id=<?= (int)$bandId ?>">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="band_id" value="<?= (int)$bandId ?>">
          <input type="hidden" name="mode" value="tx">

          <div class="row">
            <div>
              <label>Actie</label>
              <select name="action" id="action" onchange="toggleKeyOptions()" required>
                <option value="ISSUE">Uitgifte</option>
                <option value="RETURN">Retour</option>
              </select>
            </div>
            <div>
              <label>Sleutel</label>
              <select name="key_id" id="key_id" required>
                <option value="">-- kies sleutel --</option>
                <?php if ($availableKeyList): ?>
                <optgroup label="Beschikbaar voor uitgifte">
                  <?php foreach ($availableKeyList as $k): ?>
                    <option data-mode="ISSUE" value="<?= (int)$k['id'] ?>"><?= h((string)$k['locker_no']) ?> · sleutel <?= h((string)$k['key_slot']) ?> — <?= h((string)$k['key_code']) ?><?= !empty($k['description']) ? ' - '.h((string)$k['description']) : '' ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if ($issuedList): ?>
                <optgroup label="Actief (voor retour)">
                  <?php foreach ($issuedList as $k): ?>
                    <option data-mode="RETURN" value="<?= (int)$k['key_id'] ?>"><?= h((string)($k['locker_no'] ?? '—')) ?> · sleutel <?= h((string)($k['key_slot'] ?? '—')) ?> — <?= h((string)$k['key_code']) ?><?= !empty($k['description']) ? ' - '.h((string)$k['description']) : '' ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
              </select>
              <?php if (!$availableKeyList && !$issuedList): ?><div class="hint">Geen sleutels beschikbaar. Koppel eerst een kast met sleutels aan deze band.</div><?php endif; ?>
            </div>
          </div>

          <div id="contactWrap">
            <label>Contact (bij uitgifte verplicht)</label>
            <select name="contact_id" id="contact_id">
              <option value="">-- kies contact --</option>
              <?php foreach ($contactList as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <label>Notitie (optioneel)</label>
          <textarea name="notes" rows="3" placeholder="Bijv. datum, bijzonderheden"></textarea>
          <button class="btn" type="submit">Opslaan</button>
        </form>
      </div>

    </div>

    <!-- ===== Historie ===== -->
    <div class="card">
      <h2>Historie (laatste 50)</h2>
      <div class="tablewrap" style="margin-top:10px">
        <table>
          <thead><tr><th>Datum</th><th>Actie</th><th>Sleutel</th><th>Contact</th><th>Door</th><th>Notitie</th></tr></thead>
          <tbody>
          <?php foreach ($history as $r): ?>
            <tr>
              <td><?= h((string)$r['action_at']) ?></td>
              <td><strong><?= h((string)$r['action']) ?></strong></td>
              <td><?= h((string)$r['key_code']) ?>
                <?php if (!empty($r['locker_no']) && !empty($r['key_slot'])): ?><div class="small">Kast: <?= h((string)$r['locker_no']) ?> · sleutel <?= h((string)$r['key_slot']) ?></div><?php endif; ?>
              </td>
              <td><?= h((string)($r['contact_name'] ?? '—')) ?></td>
              <td><?= h((string)($r['performed_by'] ?? '—')) ?></td>
              <td class="small"><?= h((string)($r['notes'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$history): ?><tr><td colspan="6" class="small">Nog geen transacties.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</div></div>
</body>
</html>
