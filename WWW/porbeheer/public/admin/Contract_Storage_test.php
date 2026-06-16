<?php
declare(strict_types=1);

/*
 * admin/contract_storage_test.php
 *
 * Diagnose voor de nieuwe contract-opslag. Draai dit op LAB én PRODUCTIE
 * om te controleren of:
 *   - de opslagmap buiten de webroot ligt en schrijfbaar is
 *   - de map NIET via een URL bereikbaar is
 *   - DomPDF / BaconQrCode / een beeldbibliotheek (Imagick of GD) aanwezig zijn
 *   - de PHP upload-limieten groot genoeg zijn voor telefoonfoto's
 *
 * Alleen toegankelijk voor ADMIN/BEHEER. Verwijder gerust na de uitrol.
 *
 * Plaats dit bestand naast je andere adminpagina's (bv. /admin/).
 */

// ---- bootstrap robuust vinden (werkt ongeacht mapdiepte) ----------------
$__bs = null;
$__d = __DIR__;
for ($__i = 0; $__i < 8; $__i++) {
    $__c = $__d . '/libs/porbeheer/app/bootstrap.php';
    if (is_file($__c)) { $__bs = $__c; break; }
    $__p = dirname($__d);
    if ($__p === $__d) break;
    $__d = $__p;
}
if ($__bs === null) {
    // val terug op het bekende relatieve pad uit je bestaande adminpagina's
    $__bs = __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
}
require_once $__bs;
require_once PROJECT_ROOT . '/app/contract_lib.php';

requireRole(['ADMIN', 'BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

// ---- losse test-actie: maak een mini foto->PDF -------------------------
if (($_GET['action'] ?? '') === 'imgpdf_test') {
    try {
        if (!contractCanProcessImages()) {
            throw new RuntimeException('Geen beeldbibliotheek (Imagick/GD) beschikbaar.');
        }
        // Maak een eenvoudige testafbeelding
        $img = imagecreatetruecolor(800, 1000);
        $bg  = imagecolorallocate($img, 245, 247, 251);
        $fg  = imagecolorallocate($img, 30, 58, 138);
        imagefilledrectangle($img, 0, 0, 800, 1000, $bg);
        imagerectangle($img, 20, 20, 779, 979, $fg);
        imagestring($img, 5, 60, 60, 'Porbeheer - foto naar PDF test', $fg);
        imagestring($img, 4, 60, 100, 'Gegenereerd: ' . date('Y-m-d H:i:s'), $fg);
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string)ob_get_clean();
        imagedestroy($img);

        $pdf = contractImagesToPdf([$jpeg, $jpeg]); // 2 pagina's
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="img2pdf-test.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Fout bij foto->PDF test: ' . h($e->getMessage()));
    }
}

// ---- verzamel diagnose -------------------------------------------------
$results = [];

// 1. Opslagmap
$base = contractStorageBaseDir();
$baseReal = realpath($base);
$docRoot  = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';

$dirExists = is_dir($base);
$createOk = true;
$createErr = '';
try {
    contractEnsureDir($base);
} catch (Throwable $e) {
    $createOk = false;
    $createErr = $e->getMessage();
}
$baseReal = realpath($base) ?: $base;

// schrijftest
$writeOk = false;
$writeErr = '';
try {
    $testFile = $base . '/_wtest_' . bin2hex(random_bytes(4)) . '.txt';
    if (@file_put_contents($testFile, 'ok') !== false) {
        $writeOk = (@file_get_contents($testFile) === 'ok');
        @unlink($testFile);
    } else {
        $writeErr = 'file_put_contents gaf false.';
    }
} catch (Throwable $e) {
    $writeErr = $e->getMessage();
}

// web-bereikbaarheid
$insideDocroot = ($docRoot !== '' && $baseReal !== false && str_starts_with($baseReal, $docRoot));

$results['Opslag'] = [
    ['Opslagmap (config/default)', h($base), null],
    ['Echt pad (realpath)', h((string)$baseReal), null],
    ['DOCUMENT_ROOT', h($docRoot), null],
    ['Map bestaat / aangemaakt', $createOk ? 'Ja' : ('Nee — ' . h($createErr)), $createOk],
    ['Schrijfbaar (test gelukt)', $writeOk ? 'Ja' : ('Nee' . ($writeErr ? ' — ' . h($writeErr) : '')), $writeOk],
    ['Buiten de webroot', $insideDocroot ? 'NEE — onveilig! Verplaats de opslagmap.' : 'Ja (niet via URL bereikbaar)', !$insideDocroot],
];

// 2. Libraries
$imagick = extension_loaded('imagick');
$gd      = function_exists('imagecreatefromstring');
$dompdf  = class_exists(\Dompdf\Dompdf::class);
$bacon   = class_exists(\BaconQrCode\Writer::class);

$results['Bibliotheken'] = [
    ['DomPDF (PDF genereren)', $dompdf ? 'Ja' : 'Nee', $dompdf],
    ['BaconQrCode (QR)', $bacon ? 'Ja' : 'Nee', $bacon],
    ['Imagick (beste fotoverwerking)', $imagick ? 'Ja' : 'Nee', $imagick],
    ['GD (terugval fotoverwerking)', $gd ? 'Ja' : 'Nee', $gd],
    ['Foto-verwerking mogelijk', contractCanProcessImages() ? 'Ja' : 'Nee — uploads als foto werken niet!', contractCanProcessImages()],
];

// 3. Upload-limieten
$toBytes = static function (string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $unit = strtolower($v[strlen($v) - 1]);
    $num = (int)$v;
    return match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => (int)$v,
    };
};
$umf = (string)ini_get('upload_max_filesize');
$pms = (string)ini_get('post_max_size');
$mem = (string)ini_get('memory_limit');
$mfu = (string)ini_get('max_file_uploads');

$umfOk = $toBytes($umf) >= 8 * 1024 * 1024;   // >= 8 MB
$pmsOk = $toBytes($pms) >= 24 * 1024 * 1024;  // >= 24 MB (meerdere foto's)

$results['Upload-limieten'] = [
    ['upload_max_filesize', h($umf), $umfOk],
    ['post_max_size', h($pms), $pmsOk],
    ['max_file_uploads', h($mfu), ((int)$mfu >= 5)],
    ['memory_limit', h($mem), null],
    ['HTTPS actief', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Ja' : 'Nee', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')],
];

// 4. Omgeving
$results['Omgeving'] = [
    ['APP_ENV', h(defined('APP_ENV') ? APP_ENV : '?'), null],
    ['APP_URL', h(defined('APP_URL') ? APP_URL : '?'), null],
    ['PROJECT_ROOT', h(defined('PROJECT_ROOT') ? PROJECT_ROOT : '?'), null],
    ['PHP-versie', h(PHP_VERSION), null],
    ['Voorbeeld upload-URL in QR', h(contractUploadUrl(str_repeat('a', 64))), null],
];

// QR-voorbeeld renderen
$qrSvg = '';
$qrErr = '';
try {
    $qrSvg = contractQrSvg(contractUploadUrl(str_repeat('a', 64)), 180);
} catch (Throwable $e) {
    $qrErr = $e->getMessage();
}

auditLog($pdo, 'PAGE_VIEW', 'admin/contract_storage_test.php');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contract-opslag diagnose</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;background:#f5f7fb;color:#1f2937;margin:0;}
  .wrap{max-width:1000px;margin:24px auto;padding:0 20px 40px;}
  .topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px;flex-wrap:wrap;}
  h1{margin:0;font-size:28px;}
  .sub{color:#6b7280;font-size:14px;margin-top:6px;}
  .userbox{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px 16px;box-shadow:0 8px 24px rgba(0,0,0,.05);}
  .line2 a{color:#2563eb;text-decoration:none;}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 10px 24px rgba(0,0,0,.05);margin-bottom:18px;}
  .card h2{margin:0 0 12px;font-size:18px;}
  table{width:100%;border-collapse:collapse;font-size:14px;}
  td{padding:8px 10px;border-bottom:1px solid #f0f2f7;vertical-align:top;}
  td:first-child{font-weight:600;color:#374151;width:42%;}
  .ok{color:#166534;font-weight:700;}
  .no{color:#991b1b;font-weight:700;}
  .pill{display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:700;}
  .pill-ok{background:#dcfce7;color:#166534;}
  .pill-no{background:#fee2e2;color:#991b1b;}
  .btn{display:inline-block;padding:9px 16px;border-radius:10px;background:#2563eb;color:#fff;text-decoration:none;font-weight:700;font-size:14px;}
  .muted{color:#6b7280;font-size:13px;}
  .qrbox{display:flex;gap:18px;align-items:center;flex-wrap:wrap;}
  .qrbox svg{width:180px;height:180px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;padding:6px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1>🔍 Contract-opslag diagnose</h1>
      <div class="sub">Controle van opslag, beveiliging en libraries vóór uitrol</div>
    </div>
    <div class="userbox">
      <div style="font-weight:700;"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div class="line2"><a href="/admin/tech-test.php">Tech test</a> • <a href="/admin/keys.php">Sleutels</a></div>
    </div>
  </div>

  <?php foreach ($results as $section => $rows): ?>
  <div class="card">
    <h2><?= h($section) ?></h2>
    <table>
      <?php foreach ($rows as [$label, $value, $state]): ?>
      <tr>
        <td><?= $label ?></td>
        <td>
          <?php if ($state === true): ?><span class="pill pill-ok">OK</span> <?php endif; ?>
          <?php if ($state === false): ?><span class="pill pill-no">LET OP</span> <?php endif; ?>
          <?= $value ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endforeach; ?>

  <div class="card">
    <h2>QR-code test</h2>
    <div class="qrbox">
      <?php if ($qrSvg !== ''): ?>
        <?= $qrSvg ?>
        <div>
          <p class="ok">QR-generatie werkt.</p>
          <p class="muted">Dit is een voorbeeld-QR met een nep-token. De echte QR komt straks op het geprinte contract.</p>
        </div>
      <?php else: ?>
        <p class="no">QR-generatie mislukt<?= $qrErr ? ': ' . h($qrErr) : '' ?></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>Foto → PDF test</h2>
    <p class="muted">Genereert een PDF van 2 testpagina's via dezelfde route als een echte foto-upload.</p>
    <a class="btn" href="?action=imgpdf_test" target="_blank">📄 Test foto → PDF</a>
  </div>

  <div class="card">
    <h2>Wat nu?</h2>
    <ul class="muted" style="line-height:1.7;">
      <li>Staat <strong>"Buiten de webroot"</strong> op LET OP? Stel dan in <code>app/config.php</code> een veilig pad in
        (zie hieronder) en herlaad deze pagina.</li>
      <li>Is <strong>"Schrijfbaar"</strong> nee op productie? Dan heeft de webserver geen schrijfrechten op die map —
        kies een map die wél schrijfbaar is (vraag eventueel de hostingpartij naar een privé-map buiten <code>public_html</code>).</li>
      <li>Ontbreekt <strong>Imagick</strong> maar is <strong>GD</strong> "Ja"? Prima — foto's worden dan via GD verwerkt.</li>
    </ul>
    <p class="muted" style="margin-top:12px;">Voorbeeld voor <code>app/config.php</code> (voeg toe aan het <code>$config</code>-array,
      buiten de omgevings-blokken):</p>
    <pre class="muted" style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px;overflow:auto;">$config['storage'] = [
    // Laat leeg voor de standaard: PROJECT_ROOT . '/storage/contracts'
    // Of geef een absoluut pad dat BUITEN de webroot ligt en schrijfbaar is:
    'contracts_dir' => '/pad/naar/prive/storage/contracts',
];</pre>
  </div>

</div>
</body>
</html>
