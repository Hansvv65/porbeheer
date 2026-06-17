<?php
declare(strict_types=1);

/*
 * admin/contracts.php
 *
 * Beheer van sleutelcontracten:
 *   - zoeken/filteren op band, bandlid, kast of sleutel
 *   - getekend contract bekijken (filesystem, met terugval op oude blob)
 *   - getekend contract uploaden (door beheerder -> filesystem)
 *   - upload-QR + code tonen/(her)genereren voor de mobiele upload
 *   - printen (via contract_print.php) en openen in de contracteditor
 *
 * Plaats naast contract_edit.php (bv. /admin/).
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once PROJECT_ROOT . '/app/contract_lib.php';

requireRole(['ADMIN', 'BEHEER', 'BESTUURSLID']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

function fullName(?string $f, ?string $t, ?string $l, ?string $fallback = ''): string {
    $n = trim(implode(' ', array_filter([$f, $t, $l])));
    return $n !== '' ? $n : (string)$fallback;
}

// =====================================================================
// ACTIES (eindigen met exit) — vóór elke HTML-output
// =====================================================================

// --- getekend document tonen (filesystem) ---
if (($_GET['action'] ?? '') === 'serve' && isset($_GET['doc'])) {
    $docId = (int)$_GET['doc'];
    $st = $pdo->prepare("SELECT * FROM key_contract_documents WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { http_response_code(404); exit('Document niet gevonden.'); }
    auditLog($pdo, 'CONTRACT_DOC_VIEW', 'doc_id=' . $docId);
    contractStreamDocument($doc, true);
}

// --- oude blob tonen (backwards compatible) ---
if (($_GET['action'] ?? '') === 'serve_legacy' && isset($_GET['contract_id'])) {
    $cid = (int)$_GET['contract_id'];
    $st = $pdo->prepare("SELECT contract_pdf, contract_pdf_name, contract_pdf_mime FROM key_contracts WHERE id = ?");
    $st->execute([$cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['contract_pdf']) { http_response_code(404); exit('Geen PDF gevonden.'); }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . ($row['contract_pdf_mime'] ?: 'application/pdf'));
    header('Content-Disposition: inline; filename="' . addslashes($row['contract_pdf_name'] ?: 'contract.pdf') . '"');
    header('Content-Length: ' . strlen($row['contract_pdf']));
    echo $row['contract_pdf'];
    exit;
}

// --- QR + code ophalen (JSON) ---
if (($_GET['action'] ?? '') === 'qr' && isset($_GET['contract_id'])) {
    header('Content-Type: application/json');
    try {
        $cid = (int)$_GET['contract_id'];
        $ttl = (int)($GLOBALS['config']['contracts']['upload_token_ttl_minutes'] ?? 30);
        $tok = contractEnsureUploadToken($pdo, $cid, $ttl, (int)($user['id'] ?? 0));
        $url = contractUploadUrl($tok['token']);
        echo json_encode([
            'ok'         => true,
            'svg'        => contractQrSvg($url, 200),
            'short_code' => $tok['short_code'],
            'expires_at' => $tok['expires_at'],
            'url'        => $url,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- token (her)genereren (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regen_token') {
    header('Content-Type: application/json');
    try {
        requireCsrf($_POST['csrf'] ?? '');
        $cid = (int)($_POST['contract_id'] ?? 0);
        $ttl = (int)($GLOBALS['config']['contracts']['upload_token_ttl_minutes'] ?? 30);
        $tok = contractCreateUploadToken($pdo, $cid, $ttl, (int)($user['id'] ?? 0));
        $url = contractUploadUrl($tok['token']);
        auditLog($pdo, 'CONTRACT_TOKEN_REGEN', 'key_contract_id=' . $cid);
        echo json_encode([
            'ok'         => true,
            'svg'        => contractQrSvg($url, 200),
            'short_code' => $tok['short_code'],
            'expires_at' => $tok['expires_at'],
            'url'        => $url,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- beheerder uploadt getekend contract (POST, multipart) ---
$uploadMsg = null; $uploadErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_upload') {
    try {
        requireCsrf($_POST['csrf'] ?? '');
        $cid = (int)($_POST['contract_id'] ?? 0);
        if ($cid <= 0) throw new RuntimeException('Onbekend contract.');

        if (!isset($_FILES['signed']) || $_FILES['signed']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Geen bestand gekozen.');
        }
        if ($_FILES['signed']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload mislukt (foutcode ' . (int)$_FILES['signed']['error'] . ').');
        }

        $tmp  = (string)$_FILES['signed']['tmp_name'];
        if (!is_uploaded_file($tmp)) throw new RuntimeException('Ongeldige upload.');
        $orig = (string)$_FILES['signed']['name'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $bytes = file_get_contents($tmp);
        if ($bytes === false) throw new RuntimeException('Kon bestand niet lezen.');

        if ($mime === 'application/pdf') {
            $stored = contractStoreBytes($cid, $bytes, 'pdf', 'application/pdf');
            $pages = null;
        } elseif (in_array($mime, ['image/jpeg','image/png','image/webp','image/heic','image/heif','image/gif'], true)) {
            if (!contractCanProcessImages()) throw new RuntimeException('Server kan geen foto’s verwerken.');
            $pdf = contractImagesToPdf([$bytes]);
            $stored = contractStoreBytes($cid, $pdf, 'pdf', 'application/pdf');
            $pages = 1;
        } else {
            throw new RuntimeException('Alleen PDF of foto (jpg/png) toegestaan.');
        }

        contractAddDocument($pdo, $cid, $stored, 'SIGNED', 'ADMIN_UPLOAD', (int)($user['id'] ?? 0), null, $orig ?: 'getekend.pdf', $pages);
        auditLog($pdo, 'CONTRACT_SIGNED_UPLOAD', 'key_contract_id=' . $cid, ['source' => 'ADMIN_UPLOAD']);

        header('Location: /admin/contracts.php?msg=uploaded' . (isset($_GET['q']) ? '&q=' . urlencode((string)$_GET['q']) : ''));
        exit;
    } catch (Throwable $e) {
        $uploadErr = $e->getMessage();
    }
}

// =====================================================================
// LIJST OPHALEN
// =====================================================================
$q = trim((string)($_GET['q'] ?? ''));
$onlyMissing = isset($_GET['missing']);

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(k.key_code LIKE :q OR kc.locker_no LIKE :q OR kc.contract_number LIKE :q
                 OR c.name LIKE :q OR c.first_name LIKE :q OR c.last_name LIKE :q
                 OR b.name LIKE :q
                 OR JSON_UNQUOTE(JSON_EXTRACT(kc.contract_data,'$.custom_band_name')) LIKE :q
                 OR JSON_UNQUOTE(JSON_EXTRACT(kc.contract_data,'$.band_contact_name')) LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$sql = "
    SELECT kc.id, kc.key_id, kc.contract_number, kc.created_at, kc.location, kc.locker_no,
           kc.contract_pdf IS NOT NULL AS has_blob,
           kc.contract_data,
           k.key_code, k.description AS key_description,
           l.locker_no AS lk_locker_no,
           b.name AS band_name,
           c.first_name AS c_first, c.tussenvoegsel AS c_tussen, c.last_name AS c_last, c.name AS c_name,
           u.username AS bm_username, u.first_name AS bm_first, u.tussenvoegsel AS bm_tussen, u.last_name AS bm_last,
           (SELECT d.id FROM key_contract_documents d
              WHERE d.key_contract_id = kc.id AND d.kind='SIGNED' AND d.deleted_at IS NULL AND d.is_current=1
              ORDER BY d.id DESC LIMIT 1) AS signed_doc_id,
           (SELECT d.uploaded_at FROM key_contract_documents d
              WHERE d.key_contract_id = kc.id AND d.kind='SIGNED' AND d.deleted_at IS NULL AND d.is_current=1
              ORDER BY d.id DESC LIMIT 1) AS signed_at
    FROM key_contracts kc
    JOIN `keys` k        ON k.id = kc.key_id
    LEFT JOIN lockers l  ON l.id = k.locker_id
    LEFT JOIN bands b    ON b.id = l.band_id AND b.deleted_at IS NULL
    LEFT JOIN contacts c ON c.id = kc.band_contact_id
    LEFT JOIN users u    ON u.id = kc.board_member_id
";
if ($where) { $sql .= " WHERE " . implode(' AND ', $where); }
$sql .= " ORDER BY kc.created_at DESC LIMIT 300";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($onlyMissing) {
    $rows = array_filter($rows, static fn($r) => empty($r['signed_doc_id']) && empty($r['has_blob']));
}

$bg = themeImage('keys', $pdo);
$csrf = csrfToken();
auditLog($pdo, 'PAGE_VIEW', 'admin/contracts.php');

include __DIR__ . '/../assets/includes/header.php';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Contractbeheer</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('<?= h($bg) ?>') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88));padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
.wrap{width:min(1200px,96vw);}
.topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.brand h1{margin:0;font-size:28px;}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px;}
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);backdrop-filter:blur(10px);}
.userbox a{color:#fff;text-decoration:none;margin-right:10px;}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:18px;}
.searchbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;}
.searchbar input[type=text]{flex:1;min-width:220px;padding:11px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.25);color:#fff;}
.btn{display:inline-block;padding:9px 15px;border-radius:11px;border:1px solid rgba(255,255,255,.22);background:linear-gradient(180deg,var(--glass),rgba(255,255,255,.06));color:#fff;font-weight:700;cursor:pointer;font-size:13px;text-decoration:none;}
.btn:hover{border-color:rgba(255,255,255,.4);}
.btn-primary{background:linear-gradient(180deg,#2c7da0,#1f5068);border-color:#4a9fc5;}
.btn-success{background:linear-gradient(180deg,#28a745,#1e7e34);border-color:#34ce57;}
.btn-warning{background:linear-gradient(180deg,#ffc107,#e0a800);border-color:#ffce3a;color:#333;}
.msg{margin:8px 0;font-size:13px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08);}
.ok{color:#a3ffb3;} .err{color:#ffb3b3;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{text-align:left;padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.12);vertical-align:top;}
th{color:var(--muted);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
.tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;}
.tag-yes{background:rgba(34,197,94,.25);color:#bbf7d0;border:1px solid rgba(34,197,94,.4);}
.tag-no{background:rgba(248,113,113,.2);color:#fecaca;border:1px solid rgba(248,113,113,.35);}
.actions{display:flex;gap:6px;flex-wrap:wrap;}
.small{font-size:11px;color:var(--muted);}
.upform{display:none;margin-top:8px;}
.upform.open{display:block;}
/* modal */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:1000;padding:18px;}
.overlay.open{display:flex;}
.modal{background:#0f172a;border:1px solid rgba(255,255,255,.2);border-radius:18px;padding:22px;max-width:380px;width:100%;text-align:center;color:#fff;}
.modal svg{width:200px;height:200px;background:#fff;border-radius:12px;padding:8px;}
.modal .code{font-size:26px;font-weight:800;letter-spacing:3px;margin:12px 0 4px;}
.modal .url{font-size:11px;color:var(--muted);word-break:break-all;margin-bottom:10px;}
</style>
</head>
<body>
<div class="backdrop"><div class="wrap">
  <div class="topbar">
    <div class="brand"><h1>📑 Contractbeheer</h1><div class="sub">Sleutelcontracten zoeken, bekijken en getekende versies beheren</div></div>
    <div class="userbox">
      <div style="font-weight:bold;margin-bottom:6px;"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div><a href="/admin/keys.php">Sleutels</a><a href="/admin/contract_storage_test.php">Diagnose</a></div>
    </div>
  </div>

  <div class="panel">
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'uploaded'): ?>
      <div class="msg ok">Getekend contract opgeslagen.</div>
    <?php endif; ?>
    <?php if ($uploadErr): ?><div class="msg err"><?= h($uploadErr) ?></div><?php endif; ?>

    <form method="get" class="searchbar">
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Zoek op band, bandlid, kast of sleutel…" autofocus>
      <label class="small" style="display:flex;align-items:center;gap:6px;">
        <input type="checkbox" name="missing" value="1" <?= $onlyMissing ? 'checked' : '' ?>> alleen zonder getekend contract
      </label>
      <button class="btn btn-primary" type="submit">🔍 Zoeken</button>
      <?php if ($q !== '' || $onlyMissing): ?><a class="btn" href="/admin/contracts.php">✖ Wissen</a><?php endif; ?>
    </form>

    <table>
      <thead><tr>
        <th>Contract</th><th>Band</th><th>Bandlid (ondertekenaar)</th><th>Kast / Sleutel</th>
        <th>Getekend</th><th>Acties</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="small" style="padding:18px;">Geen contracten gevonden.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $cid = (int)$r['id'];
        $kid = (int)$r['key_id'];
        $cdata = $r['contract_data'] ? (json_decode((string)$r['contract_data'], true) ?: []) : [];
        $bandDisplay = $cdata['custom_band_name'] ?? ($r['band_name'] ?? '—');
        $contactDisplay = fullName($r['c_first'], $r['c_tussen'], $r['c_last'], $r['c_name'] ?? ($cdata['band_contact_name'] ?? '—'));
        $lockerDisplay = $r['locker_no'] ?: ($r['lk_locker_no'] ?? 'n.v.t.');
        $hasSigned = !empty($r['signed_doc_id']);
        $hasBlob = !empty($r['has_blob']);
      ?>
        <tr>
          <td><strong><?= h($r['contract_number']) ?></strong><br><span class="small"><?= h(date('d-m-Y', strtotime((string)$r['created_at']))) ?></span></td>
          <td><?= h($bandDisplay) ?></td>
          <td><?= h($contactDisplay) ?></td>
          <td>Kast <strong><?= h($lockerDisplay) ?></strong><br><span class="small">Sleutel <?= h($r['key_code']) ?></span></td>
          <td>
            <?php if ($hasSigned): ?>
              <span class="tag tag-yes">Ja</span><br>
              <span class="small"><?= h(date('d-m-Y', strtotime((string)$r['signed_at']))) ?></span>
            <?php elseif ($hasBlob): ?>
              <span class="tag tag-yes">Ja (oud)</span>
            <?php else: ?>
              <span class="tag tag-no">Nee</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="actions">
              <?php if ($hasSigned): ?>
                <a class="btn" href="/admin/contracts.php?action=serve&doc=<?= (int)$r['signed_doc_id'] ?>" target="_blank">👁 Bekijk</a>
              <?php elseif ($hasBlob): ?>
                <a class="btn" href="/admin/contracts.php?action=serve_legacy&contract_id=<?= $cid ?>" target="_blank">👁 Bekijk (oud)</a>
              <?php endif; ?>
              <a class="btn btn-warning" href="/admin/contract_print.php?contract_id=<?= $cid ?>" target="_blank">🖨 Print + QR</a>
              <button type="button" class="btn btn-primary" onclick="showQr(<?= $cid ?>)">📱 Upload-QR</button>
              <button type="button" class="btn" onclick="toggleUp(<?= $cid ?>)">⬆ Upload</button>
              <a class="btn" href="/admin/contract_edit.php?id=<?= $kid ?>">✏ Bewerk</a>
            </div>
            <form class="upform" id="up<?= $cid ?>" method="post" enctype="multipart/form-data">
              <input type="hidden" name="action" value="admin_upload">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="contract_id" value="<?= $cid ?>">
              <input type="file" name="signed" accept="application/pdf,image/*" required style="font-size:12px;">
              <button class="btn btn-success" type="submit" style="margin-top:6px;">💾 Opslaan</button>
              <span class="small">PDF of foto</span>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small" style="margin-top:10px;">Maximaal 300 resultaten. Verfijn met de zoekbalk indien nodig.</p>
  </div>
</div></div>

<!-- QR modal -->
<div class="overlay" id="qrOverlay" onclick="if(event.target===this)closeQr()">
  <div class="modal">
    <h3 style="margin:0 0 10px;">📱 Upload-code</h3>
    <div id="qrHolder"><p class="small">Laden…</p></div>
    <div class="code" id="qrCode"></div>
    <div class="url" id="qrUrl"></div>
    <div class="small" id="qrExpires" style="margin-bottom:12px;"></div>
    <div style="display:flex;gap:8px;justify-content:center;">
      <button class="btn btn-warning" onclick="regenQr()">🔄 Nieuwe code</button>
      <button class="btn" onclick="closeQr()">Sluiten</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let currentContractId = null;

function toggleUp(id){ document.getElementById('up'+id).classList.toggle('open'); }

function renderQr(d){
  document.getElementById('qrHolder').innerHTML = d.svg || '';
  document.getElementById('qrCode').textContent = d.short_code || '';
  document.getElementById('qrUrl').textContent = d.url || '';
  document.getElementById('qrExpires').textContent = d.expires_at ? ('Geldig tot ' + d.expires_at) : '';
}

async function showQr(id){
  currentContractId = id;
  document.getElementById('qrHolder').innerHTML = '<p class="small">Laden…</p>';
  document.getElementById('qrCode').textContent = '';
  document.getElementById('qrUrl').textContent = '';
  document.getElementById('qrExpires').textContent = '';
  document.getElementById('qrOverlay').classList.add('open');
  try {
    const r = await fetch('/admin/contracts.php?action=qr&contract_id=' + id);
    const d = await r.json();
    if (d.ok) renderQr(d); else document.getElementById('qrHolder').innerHTML = '<p class="err">'+(d.error||'Fout')+'</p>';
  } catch(e){ document.getElementById('qrHolder').innerHTML = '<p class="err">Netwerkfout</p>'; }
}

async function regenQr(){
  if(!currentContractId) return;
  const fd = new FormData();
  fd.append('action','regen_token'); fd.append('csrf',CSRF); fd.append('contract_id',currentContractId);
  try {
    const r = await fetch('/admin/contracts.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) renderQr(d); else alert(d.error||'Fout');
  } catch(e){ alert('Netwerkfout'); }
}

function closeQr(){ document.getElementById('qrOverlay').classList.remove('open'); currentContractId=null; }
</script>
</body>
</html>
