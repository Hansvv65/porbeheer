<?php
declare(strict_types=1);

/*
 * contract_upload.php  (PUBLIEK - in de webroot)
 *
 * Mobiele uploadpagina voor het getekende contract. GEEN login vereist:
 * toegang loopt uitsluitend via een geldig, eenmalig, niet-verlopen token
 * (uit de QR-code) of de bijbehorende korte code. Zonder geldig token/code
 * wordt de pagina geweigerd.
 *
 * Foto's worden verkleind en samengevoegd tot één PDF en opgeslagen bij het
 * juiste contract (en daarmee de juiste band/persoon/kast/sleutel).
 *
 * Plaats dit bestand in de webroot:
 *   /var/www/domains/porzbeheer.nl/subdomains/administratie/contract_upload.php
 */

// ---- bootstrap robuust vinden, ongeacht mapdiepte ----------------------
$__bs = null; $__d = __DIR__;
for ($__i = 0; $__i < 8; $__i++) {
    $__c = $__d . '/libs/porbeheer/app/bootstrap.php';
    if (is_file($__c)) { $__bs = $__c; break; }
    $__p = dirname($__d);
    if ($__p === $__d) break;
    $__d = $__p;
}
if ($__bs === null) { $__bs = __DIR__ . '/../../libs/porbeheer/app/bootstrap.php'; }
require_once $__bs;
require_once PROJECT_ROOT . '/app/contract_lib.php';

// Geen requireRole / requireLogin: dit is een publieke, token-beveiligde pagina.

$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$MAX_FILES        = 10;
$MAX_BYTES_EACH   = 12 * 1024 * 1024; // 12 MB per foto
$ALLOWED_MIME     = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/gif'];

// ---- token of code bepalen ---------------------------------------------
$tokenStr = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$codeStr  = (string)($_POST['code']  ?? $_GET['code']  ?? '');

$ctx = null; // gevalideerd token + contractgegevens
if ($tokenStr !== '') {
    $ctx = contractValidateUploadToken($pdo, $tokenStr);
} elseif ($codeStr !== '') {
    $ctx = contractValidateShortCode($pdo, $codeStr);
    if ($ctx) { $tokenStr = (string)$ctx['token']; } // verder met het echte token
}

$flash = null;   // ['type'=>'ok|err', 'msg'=>...]
$done  = false;

// ---- POST: upload verwerken --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_upload'])) {
    try {
        requireCsrf($_POST['csrf'] ?? '');

        if (!$ctx) {
            throw new RuntimeException('De upload-link of code is verlopen of al gebruikt. Vraag de beheerder om een nieuwe.');
        }
        if (!contractCanProcessImages()) {
            throw new RuntimeException('De server kan op dit moment geen foto’s verwerken. Neem contact op met de beheerder.');
        }

        $files = $_FILES['photos'] ?? null;
        if (!$files || !isset($files['tmp_name']) || !is_array($files['tmp_name'])) {
            throw new RuntimeException('Geen foto gekozen.');
        }

        $count = count($files['tmp_name']);
        if ($count < 1) {
            throw new RuntimeException('Geen foto gekozen.');
        }
        if ($count > $MAX_FILES) {
            throw new RuntimeException('Te veel foto’s (maximaal ' . $MAX_FILES . ').');
        }

        $finfo  = new finfo(FILEINFO_MIME_TYPE);
        $blobs  = [];
        for ($i = 0; $i < $count; $i++) {
            $err = (int)$files['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) { continue; }
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Er ging iets mis bij het uploaden (foutcode ' . $err . ').');
            }
            $size = (int)$files['size'][$i];
            if ($size <= 0) { continue; }
            if ($size > $MAX_BYTES_EACH) {
                throw new RuntimeException('Eén van de foto’s is te groot (max 12 MB per foto).');
            }
            $tmp  = (string)$files['tmp_name'][$i];
            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('Ongeldige upload.');
            }
            $mime = (string)$finfo->file($tmp);
            if (!in_array($mime, $ALLOWED_MIME, true)) {
                throw new RuntimeException('Alleen foto’s zijn toegestaan (jpg, png).');
            }
            $bytes = file_get_contents($tmp);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Kon de foto niet lezen.');
            }
            $blobs[] = $bytes;
        }

        if (count($blobs) === 0) {
            throw new RuntimeException('Geen geldige foto gekozen.');
        }

        // Foto's -> PDF
        $pdfBytes = contractImagesToPdf($blobs);

        $keyContractId = (int)$ctx['key_contract_id'];
        $tokenId       = (int)$ctx['id'];

        $stored = contractStoreBytes($keyContractId, $pdfBytes, 'pdf', 'application/pdf');
        contractAddDocument(
            $pdo,
            $keyContractId,
            $stored,
            'SIGNED',
            'QR_UPLOAD',
            null,                 // geen ingelogde gebruiker
            $tokenId,
            'getekend_contract.pdf',
            count($blobs)
        );
        contractConsumeUploadToken($pdo, $tokenId);

        auditLog($pdo, 'CONTRACT_SIGNED_UPLOAD', 'key_contract_id=' . $keyContractId, [
            'source'   => 'QR_UPLOAD',
            'token_id' => $tokenId,
            'pages'    => count($blobs),
        ]);

        $flash = ['type' => 'ok', 'msg' => 'Bedankt! Het getekende contract is ontvangen en opgeslagen.'];
        $done  = true;
        $ctx   = null; // token is verbruikt; formulier niet opnieuw tonen
    } catch (Throwable $e) {
        // Token NIET verbruiken bij een fout, zodat opnieuw proberen kan
        $flash = ['type' => 'err', 'msg' => $e->getMessage()];
    }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Contract uploaden – Porbeheer</title>
<style>
  :root{--blue:#2563eb;--green:#16a34a;--red:#dc2626;--ink:#1e293b;--muted:#64748b;}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#0f172a;color:var(--ink);}
  .screen{min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:18px;
          background:radial-gradient(circle at 30% 10%,#1e3a8a,#0f172a 70%);}
  .card{width:100%;max-width:460px;background:#fff;border-radius:20px;padding:22px;margin-top:24px;
        box-shadow:0 20px 50px rgba(0,0,0,.4);}
  h1{font-size:21px;margin:0 0 4px;}
  .sub{color:var(--muted);font-size:14px;margin:0 0 16px;}
  .meta{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;font-size:14px;margin-bottom:16px;}
  .meta b{color:#111827;}
  label.filebtn{display:block;border:2px dashed #93c5fd;border-radius:16px;padding:26px 16px;text-align:center;
                color:var(--blue);font-weight:700;font-size:16px;cursor:pointer;background:#eff6ff;}
  label.filebtn:active{background:#dbeafe;}
  input[type=file]{display:none;}
  .hint{font-size:13px;color:var(--muted);margin:10px 2px 0;}
  #preview{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;}
  #preview img{width:72px;height:72px;object-fit:cover;border-radius:10px;border:1px solid #e5e7eb;}
  .btn{display:block;width:100%;border:none;border-radius:14px;padding:16px;font-size:17px;font-weight:800;
       color:#fff;background:var(--green);margin-top:18px;cursor:pointer;}
  .btn:disabled{background:#9ca3af;cursor:not-allowed;}
  .btn-blue{background:var(--blue);}
  .flash{border-radius:12px;padding:13px 15px;font-size:15px;margin-bottom:16px;line-height:1.4;}
  .flash.ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
  .flash.err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
  .codeform input[type=text]{width:100%;padding:14px;font-size:18px;letter-spacing:2px;text-align:center;text-transform:uppercase;
       border:1px solid #cbd5e1;border-radius:12px;margin-bottom:10px;}
  .footer{text-align:center;color:#94a3b8;font-size:12px;margin-top:18px;}
  .big-emoji{font-size:42px;text-align:center;margin:6px 0 10px;}
</style>
</head>
<body>
<div class="screen">
  <div class="card">

    <?php if ($flash): ?>
      <div class="flash <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>"><?= $esc($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
      <div class="big-emoji">✅</div>
      <h1 style="text-align:center;">Gelukt!</h1>
      <p class="sub" style="text-align:center;">Je kunt dit venster sluiten. Bedankt voor het uploaden.</p>

    <?php elseif ($ctx): ?>
      <h1>📄 Getekend contract uploaden</h1>
      <p class="sub">Maak een foto van elke pagina van het ondertekende contract.</p>

      <div class="meta">
        <div>Contract: <b><?= $esc($ctx['contract_number'] ?? '') ?></b></div>
        <div>Sleutel: <b><?= $esc($ctx['key_code'] ?? '') ?></b></div>
      </div>

      <form method="post" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="csrf" value="<?= $esc($csrf) ?>">
        <input type="hidden" name="token" value="<?= $esc($tokenStr) ?>">
        <input type="hidden" name="do_upload" value="1">

        <label class="filebtn" for="photos">
          📷 Foto maken / kiezen
        </label>
        <input type="file" id="photos" name="photos[]" accept="image/*" capture="environment" multiple>

        <div class="hint">Je kunt meerdere foto’s toevoegen (max 10). Tik nogmaals om er meer te maken.</div>
        <div id="preview"></div>

        <button type="submit" class="btn" id="submitBtn" disabled>Versturen</button>
      </form>

    <?php else: ?>
      <div class="big-emoji">🔒</div>
      <h1 style="text-align:center;">Toegang geweigerd</h1>
      <p class="sub" style="text-align:center;">Deze link is verlopen of al gebruikt. Heb je een code van de beheerder? Voer die hieronder in.</p>

      <form method="get" class="codeform">
        <input type="text" name="code" maxlength="12" placeholder="CODE" value="<?= $esc($codeStr) ?>" autocapitalize="characters" autocomplete="off">
        <button type="submit" class="btn btn-blue">Doorgaan</button>
      </form>
    <?php endif; ?>

    <div class="footer">Porbeheer · Stichting Popkultuur Zevenaar</div>
  </div>
</div>

<script>
  (function () {
    var input = document.getElementById('photos');
    var btn = document.getElementById('submitBtn');
    var preview = document.getElementById('preview');
    var form = document.getElementById('uploadForm');
    if (!input) return;

    input.addEventListener('change', function () {
      preview.innerHTML = '';
      var files = Array.prototype.slice.call(input.files || []);
      files.slice(0, 10).forEach(function (f) {
        if (!f.type.indexOf || f.type.indexOf('image/') !== 0) return;
        var img = document.createElement('img');
        img.src = URL.createObjectURL(f);
        preview.appendChild(img);
      });
      btn.disabled = files.length === 0;
    });

    if (form) {
      form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.textContent = 'Bezig met uploaden…';
      });
    }
  })();
</script>
</body>
</html>
