<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
requireSectionAccess('sleutels', 'write');

// ---------- AJAX ENDPOINTS ----------
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_GET['action'] === 'get_all_contacts') {
            $stmt = $pdo->query("SELECT id, name, email FROM contacts WHERE deleted_at IS NULL ORDER BY name");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldige action']);
    exit;
}

// ---------- PAGINA OPBOUW ----------
include __DIR__ . '/../assets/includes/header.php';

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('keys', $pdo);
auditLog($pdo, 'PAGE_VIEW', 'admin/keys_edit.php');

$errors = [];
$msg = null;
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$isEdit = $id > 0;

$row = ['id' => 0, 'key_code' => '', 'description' => '', 'key_type' => 'LOCKER', 'locker_id' => null, 'notes' => '', 'active' => 1, 'lost_at' => null, 'lost_note' => ''];

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare("SELECT * FROM `keys` WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $db = $st->fetch(PDO::FETCH_ASSOC);
    if (!$db) { header('Location: /admin/keys.php?msg=notfound'); exit; }
    $row = array_merge($row, $db);
}

// Huidige transactie & status
$currentTransaction = null; $currentContact = null; $currentBandForTx = null;
if ($isEdit) {
    $stmt = $pdo->prepare("
        SELECT kt.*, c.name AS contact_name, c.email AS contact_email, b.name AS band_name
        FROM key_transactions kt
        LEFT JOIN contacts c ON c.id = kt.contact_id
        LEFT JOIN bands b ON b.id = kt.band_id
        WHERE kt.key_id = ? ORDER BY kt.id DESC LIMIT 1
    ");
    $stmt->execute([$id]);
    $currentTransaction = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($currentTransaction && $currentTransaction['action'] === 'ISSUE') {
        $currentContact = ['id' => $currentTransaction['contact_id'], 'name' => $currentTransaction['contact_name'], 'email' => $currentTransaction['contact_email']];
        $currentBandForTx = $currentTransaction['band_name'] ?? '';
    }
}

$lockers = $pdo->query("SELECT l.id, l.locker_no, l.band_id, b.name AS band_name FROM lockers l LEFT JOIN bands b ON b.id = l.band_id AND b.deleted_at IS NULL WHERE l.deleted_at IS NULL ORDER BY l.locker_no")->fetchAll();

$currentLocker = null; $currentBandId = null; $currentBandName = null; $currentLockerNo = null;
if ($row['locker_id']) {
    foreach ($lockers as $l) if ($l['id'] == $row['locker_id']) {
        $currentLocker = $l; $currentBandId = $l['band_id']; $currentBandName = $l['band_name']; $currentLockerNo = $l['locker_no']; break;
    }
}

// Contactpersonen van de huidige band (voor uitgifte) -- rechtstreeks op de
// server bepaald, geen los te kiezen band meer: die staat al vast via de
// kast van deze sleutel. Zelfde bron als elders (band_contacts + primair/
// secundair contact).
$currentBandContacts = [];
if ($currentBandId) {
    $cbc = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, c.email
        FROM contacts c
        LEFT JOIN band_contacts bc ON bc.contact_id = c.id
        LEFT JOIN bands b ON b.id = ? AND (b.primary_contact_id = c.id OR b.secondary_contact_id = c.id)
        WHERE c.deleted_at IS NULL AND (bc.band_id = ? OR b.id IS NOT NULL)
        ORDER BY c.name
    ");
    $cbc->execute([$currentBandId, $currentBandId]);
    $currentBandContacts = $cbc->fetchAll(PDO::FETCH_ASSOC);
}

// Status tekst voor read-only paneel
$statusText = 'Vrij';
if ($currentTransaction && $currentTransaction['action'] === 'ISSUE') {
    $statusText = 'Uitgegeven aan ' . h($currentContact['name'] ?? 'onbekend');
    if ($currentBandForTx) $statusText .= ' (' . h($currentBandForTx) . ')';
}

// ------------------- POST handlers (alleen notities & uitgifte/retour) -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_key'])) {
    try {
        requireCsrf($_POST['csrf'] ?? '');
        $locker_id = $_POST['locker_id'] !== '' ? (int)$_POST['locker_id'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $lost_at = isset($_POST['is_lost']) ? date('Y-m-d H:i:s') : null;
        $lost_note = trim($_POST['lost_note'] ?? '');
        $pdo->prepare("UPDATE `keys` SET locker_id=?, notes=?, lost_at=?, lost_note=?, active=1 WHERE id=? AND deleted_at IS NULL")->execute([$locker_id, $notes ?: null, $lost_at, $lost_note, $id]);
        auditLog($pdo, 'KEY_UPDATE', 'key_id=' . $id);
        header("Location: /admin/keys_edit.php?id=$id&msg=notes_saved");
        exit;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_key'])) {
    try {
        requireCsrf($_POST['csrf'] ?? '');
        $contactId = (int)$_POST['contact_id'];
        $recipientType = $_POST['recipient_type'] ?? 'band';
        if ($contactId <= 0) throw new Exception('Selecteer een contactpersoon');
        $check = $pdo->prepare("SELECT action FROM key_transactions WHERE key_id=? ORDER BY id DESC LIMIT 1");
        $check->execute([$id]);
        if ($check->fetchColumn() === 'ISSUE') throw new Exception('Sleutel is al uitgegeven');
        $pdo->beginTransaction();
        if ($recipientType === 'band') {
            $bandId = !empty($_POST['band_id']) ? (int)$_POST['band_id'] : $currentBandId;
            if ($bandId <= 0) throw new Exception('Geen band geselecteerd');
        } else {
            $stmt = $pdo->prepare("SELECT id FROM bands WHERE name='Algemeen (geen band)' AND deleted_at IS NULL");
            $stmt->execute();
            $generalBandId = $stmt->fetchColumn();
            if (!$generalBandId) {
                $pdo->prepare("INSERT INTO bands (name, is_subscription_active) VALUES ('Algemeen (geen band)', 0)")->execute();
                $generalBandId = $pdo->lastInsertId();
            }
            $bandId = $generalBandId;
        }
        $pdo->prepare("INSERT INTO key_transactions (band_id, key_id, contact_id, action, performed_by_user_id, action_at) VALUES (?,?,?,'ISSUE',?,NOW())")->execute([$bandId, $id, $contactId, $user['id']]);
        $pdo->commit();
        auditLog($pdo, 'KEY_ISSUE', "key_id=$id, contact_id=$contactId, band_id=$bandId, type=$recipientType");
        header("Location: /admin/keys.php?msg=issued");
        exit;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errors[] = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_key'])) {
    try {
        requireCsrf($_POST['csrf'] ?? '');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT band_id, contact_id FROM key_transactions WHERE key_id=? AND action='ISSUE' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$id]);
        $last = $stmt->fetch();
        if (!$last) throw new Exception('Niet als uitgegeven geregistreerd');
        $pdo->prepare("INSERT INTO key_transactions (band_id, key_id, contact_id, action, performed_by_user_id, action_at) VALUES (?,?,?,'RETURN',?,NOW())")->execute([$last['band_id'], $id, $last['contact_id'], $user['id']]);
        $pdo->commit();
        auditLog($pdo, 'KEY_RETURN', "key_id=$id");
        header("Location: /admin/keys.php?msg=returned");
        exit;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errors[] = $e->getMessage(); }
}

$title = $isEdit ? 'Sleutel bewerken' : 'Nieuwe sleutel';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - <?= h($title) ?></title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
.wrap{width:min(1100px,96vw);}
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);backdrop-filter:blur(10px);min-width:260px;}
.userbox .line2{color:var(--muted);margin-top:6px;font-size:13px;display:flex;gap:10px;flex-wrap:wrap}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:18px;}
.card{border-radius:16px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));padding:20px;margin-bottom:20px;box-shadow:0 10px 22px rgba(0,0,0,.30);backdrop-filter:blur(10px);}
.card:last-child{margin-bottom:0;}
h2{margin:0 0 15px 0;font-size:20px;border-bottom:1px solid var(--border);padding-bottom:10px;}
h3{margin:0 0 10px 0;font-size:16px;}
a{color:#fff;text-decoration:none}
.success{color:#a3ffb3}
.btn{display:inline-block;padding:10px 18px;border-radius:12px;border:1px solid rgba(255,255,255,.22);background:linear-gradient(180deg, var(--glass), rgba(255,255,255,.06));color:#fff;font-weight:800;cursor:pointer;font-size:14px;}
.btn-primary{background:linear-gradient(180deg, #2c7da0, #1f5068);border-color:#4a9fc5;}
.btn-success{background:linear-gradient(180deg, #28a745, #1e7e34);border-color:#34ce57;}
.btn-warning{background:linear-gradient(180deg, #ffc107, #e0a800);border-color:#ffce3a;color:#333;}
.btn-info{background:linear-gradient(180deg, #17a2b8, #117a8b);border-color:#3ab5d4;}
.btn:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.38);}
.field{margin-bottom:15px;}
label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px;font-weight:bold;}
input[type=text], input[type=date], input[type=file], select, textarea{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.25);color:#fff;box-sizing:border-box;}
input[readonly], input:read-only{background:rgba(0,0,0,.15);cursor:not-allowed;color:var(--muted);}
textarea{min-height:60px;resize:vertical;}
.row{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
@media (max-width:820px){.row{grid-template-columns:1fr}}
hr.sep{border:none;border-top:1px solid rgba(255,255,255,.12);margin:15px 0;}
.inline{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);}
.contract-info-line{margin:8px 0;padding:5px 0;border-bottom:1px solid var(--border);}
.button-group{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;}
.lost-field{margin-top:10px;padding:10px;background:rgba(255,100,100,.1);border-radius:10px;display:none;}
.lost-field.visible{display:block;}
#keepAliveBtn { margin-left: auto; }
.recipient-type { display: flex; gap: 20px; margin-bottom: 15px; }
.recipient-type label { display: flex; align-items: center; gap: 5px; font-weight: normal; }
.readonly-text { padding: 10px 12px; background: rgba(0,0,0,.15); border-radius: 12px; border: 1px solid rgba(255,255,255,.18); color: var(--muted); }
</style>
</head>
<body style="background-image:url('<?= h($bg) ?>')">
<div class="backdrop"><div class="wrap">
<div class="topbar">
    <div class="brand"><h1>🔑 <?= h($title) ?></h1><div class="sub">Sleuteladministratie</div></div>
    <div class="userbox">
        <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
        <div class="line2">
            <a href="/admin/keys.php">← Terug naar overzicht</a>
            <a href="/admin/dashboard.php">Dashboard</a>
            <?php if ($isEdit): ?>
            <a href="/admin/contract_edit.php?id=<?= $id ?>" class="btn btn-info" style="padding:5px 12px; font-size:12px;">📄 Contract</a>
            <?php endif; ?>
            <button class="btn btn-info" id="keepAliveBtn" style="padding:5px 12px; font-size:12px;">🔄 Verleng sessie</button>
        </div>
    </div>
</div>

<div class="panel">
    <?php if ($errors): ?><div class="msg err"><ul><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul></div><?php endif; ?>
    <?php
    $msgParam = $_GET['msg'] ?? '';
    if ($msgParam === 'notes_saved') $msg = 'Notities en verloren-status opgeslagen!';
    if ($msg) echo '<div class="msg success">'.h($msg).'</div>';
    ?>

    <!-- PANEEL 1: Read-only sleutelgegevens + bewerkbare notities/verloren -->
    <div class="card">
        <h2>📋 Sleutelinformatie (alleen notities wijzigbaar)</h2>
        <form method="post" id="keyForm">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="save_key" value="1">
            <input type="hidden" name="locker_id" id="locker_id" value="<?= h((string)($row['locker_id'] ?? '')) ?>">

            <div class="row">
                <div class="field"><label>Sleutelnummer</label><div class="readonly-text"><?= h($row['key_code']) ?></div></div>
                <div class="field"><label>Sleutelnaam</label><div class="readonly-text"><?= h($row['description']) ?></div></div>
            </div>
            <div class="row">
                <div class="field"><label>POR-kast</label><div class="readonly-text"><?= h($currentLockerNo ?: 'Geen kast gekoppeld') ?></div></div>
                <div class="field"><label>Band (indien van toepassing)</label><div class="readonly-text"><?= h($currentBandName ?: 'Geen band (algemeen)') ?></div></div>
            </div>
            <div class="field"><label>Huidige status</label><div class="readonly-text"><?= $statusText ?></div></div>
            
            <hr class="sep">
            <div class="field"><label>Notities (vrij bewerkbaar)</label><textarea name="notes" rows="2"><?= h($row['notes']) ?></textarea></div>
            <div class="row">
                <div class="field"><label>Verloren?</label><input type="checkbox" name="is_lost" id="lostCheckbox" <?= !empty($row['lost_at']) ? 'checked' : '' ?>></div>
            </div>
            <div class="lost-field" id="lostNoteField"><label>Verloren notitie</label><input type="text" name="lost_note" value="<?= h($row['lost_note']) ?>"></div>
            
            <div class="button-group"><button type="submit" class="btn btn-primary">💾 Notities & verloren opslaan</button></div>
        </form>
    </div>

    <!-- PANEEL 2: Uitgifte / Retour -->
    <div class="card">
        <h2>🔁 Uitgifte / Retour</h2>
        <?php if ($currentTransaction && $currentTransaction['action'] === 'ISSUE'): ?>
            <p>Uitgegeven aan: <strong><?= h($currentContact['name'] ?? '') ?></strong> <?= $currentContact['email'] ? '('.h($currentContact['email']).')' : '' ?></p>
            <p>Op: <?= h($currentTransaction['action_at']) ?></p>
            <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="return_key" value="1"><button type="submit" class="btn btn-warning">↩️ Retour</button></form>
        <?php else: ?>
            <p>De sleutel is momenteel <strong>in voorraad</strong> en kan worden uitgegeven.</p>
            <?php if ($currentBandId): ?>
                <div class="msg info">
                    💡 Deze sleutel hoort bij kast <strong><?= h((string)$currentLockerNo) ?></strong> van band <strong><?= h((string)$currentBandName) ?></strong>.
                    Voor uitgifte aan een bandlid is <a href="/admin/band_keys.php?band_id=<?= (int)$currentBandId ?>">het sleutelbeheer van die band</a> de aanbevolen route
                    (daar staan de contactpersonen van de band al klaar). Onderstaand formulier werkt ook, maar is bedoeld voor uitzonderingen.
                </div>
            <?php elseif ($currentLockerNo): ?>
                <div class="msg err">
                    ⚠️ Kast <strong><?= h((string)$currentLockerNo) ?></strong> is nog niet aan een band gekoppeld. Ga naar de betreffende band → Sleutelbeheer
                    om eerst een band aan deze kast te koppelen — dan verschijnen ook de juiste contactpersonen automatisch.
                    Wil je deze sleutel toch nu al uitgeven (bijv. aan iemand buiten een band)? Gebruik dan hieronder "Uitgeven aan algemeen contact".
                </div>
            <?php endif; ?>
            <form method="post" id="issueForm">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="issue_key" value="1">
                <div class="recipient-type">
                    <label><input type="radio" name="recipient_type" value="band" <?= $currentBandId ? 'checked' : 'disabled' ?>> Uitgeven aan bandlid<?= $currentBandId ? '' : ' (geen band gekoppeld aan deze kast)' ?></label>
                    <label><input type="radio" name="recipient_type" value="general" <?= $currentBandId ? '' : 'checked' ?>> Uitgeven aan algemeen contact</label>
                </div>
                <div class="field" id="bandField" style="<?= $currentBandId ? '' : 'display:none;' ?>">
                    <label>Band</label>
                    <input type="text" value="<?= h((string)$currentBandName) ?>" readonly>
                    <input type="hidden" name="band_id" id="issue_band_id" value="<?= h((string)($currentBandId ?? '')) ?>">
                </div>
                <div class="field">
                    <label>Contactpersoon</label>
                    <select name="contact_id" id="contact_select" required>
                        <option value="">-- Kies contactpersoon --</option>
                        <?php foreach ($currentBandContacts as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['name']) ?><?= $c['email'] ? ' - ' . h((string)$c['email']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="small" id="contactHint"><?= $currentBandId ? 'Selecteer de ontvanger' : '' ?></div>
                </div>
                <div class="button-group"><button type="submit" class="btn btn-success">📤 Uitgeven</button></div>
            </form>
        <?php endif; ?>
    </div>
</div>
</div></div>

<script>
// Globale variabelen
let allContacts = [];
fetch('/admin/keys_edit.php?action=get_all_contacts').then(r=>r.json()).then(d=>{allContacts=d;}).catch(console.error);

// Contactpersonen van de huidige band: al server-side gerenderd in de
// select bij het laden van de pagina (zie PHP hierboven), hier bewaard
// zodat we na het wisselen naar "algemeen contact" weer kunnen terugzetten.
const bandContacts = <?= json_encode($currentBandContacts, JSON_UNESCAPED_UNICODE) ?>;

const bandField = document.getElementById('bandField');
const contactSelect = document.getElementById('contact_select');
const contactHint = document.getElementById('contactHint');
const recipientRadios = document.querySelectorAll('input[name="recipient_type"]');

function fillGeneralContacts() {
    contactSelect.innerHTML = '<option value="">-- Kies contactpersoon --</option>';
    allContacts.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.name}${c.email ? ' - ' + c.email : ''}`;
        contactSelect.appendChild(opt);
    });
    contactSelect.disabled = false;
    contactHint.textContent = 'Selecteer een algemeen contact';
}

function fillBandContacts() {
    contactSelect.innerHTML = '<option value="">-- Kies contactpersoon --</option>';
    bandContacts.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.name}${c.email ? ' - ' + c.email : ''}`;
        contactSelect.appendChild(opt);
    });
    contactSelect.disabled = false;
    contactHint.textContent = 'Selecteer de ontvanger';
}

function updateIssueDropdown() {
    const type = document.querySelector('input[name="recipient_type"]:checked').value;
    if (type === 'band') {
        bandField.style.display = 'block';
        fillBandContacts();
    } else {
        bandField.style.display = 'none';
        if (allContacts.length) fillGeneralContacts();
        else { contactSelect.innerHTML = '<option value="">Laden...</option>'; }
    }
}
recipientRadios.forEach(r => r.addEventListener('change', updateIssueDropdown));

// Init uitgifte
document.addEventListener('DOMContentLoaded', () => {
    updateIssueDropdown();
});

// Lost toggle
const lostChk = document.getElementById('lostCheckbox');
const lostField = document.getElementById('lostNoteField');
if (lostChk) { lostChk.addEventListener('change', ()=>lostField.classList.toggle('visible', lostChk.checked)); lostField.classList.toggle('visible', lostChk.checked); }

// Keep-alive
function keepAlive() {
    fetch('/admin/keepalive.php', {method:'POST'}).then(r=>r.json()).then(d=>{
        if (d.status==='ok') { const b=document.getElementById('keepAliveBtn'); b.textContent='✅ Sessie actief'; setTimeout(()=>b.textContent='🔄 Verleng sessie',2000); }
    });
}
let keepInt = setInterval(keepAlive, 4*60*1000);
document.getElementById('keepAliveBtn').addEventListener('click', ()=>{ keepAlive(); clearInterval(keepInt); keepInt=setInterval(keepAlive, 4*60*1000); });
window.addEventListener('beforeunload', ()=>clearInterval(keepInt));
</script>
</body>
</html>