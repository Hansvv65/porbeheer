<?php
declare(strict_types=1);

/*
 * admin/contract_print.php
 *
 * Genereert het officiële sleutelcontract als PDF, inclusief een QR-code +
 * korte code waarmee de getekende versie later geüpload kan worden.
 * Het bijbehorende upload-token wordt aangemaakt of hergebruikt.
 *
 * Aanroep:  contract_print.php?contract_id=123   (key_contracts.id)
 *      of:  contract_print.php?key_id=45         (laatste contract voor die sleutel)
 *
 * Plaats naast contract_edit.php (bv. /admin/).
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once PROJECT_ROOT . '/app/contract_lib.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireRole(['ADMIN', 'BEHEER', 'BESTUURSLID']);

$user = currentUser();

$contractId = (int)($_GET['contract_id'] ?? 0);
$keyId      = (int)($_GET['key_id'] ?? 0);

if ($contractId <= 0 && $keyId <= 0) {
    http_response_code(400);
    exit('Geen contract opgegeven.');
}

if ($contractId > 0) {
    $st = $pdo->prepare("SELECT * FROM key_contracts WHERE id = ?");
    $st->execute([$contractId]);
} else {
    $st = $pdo->prepare("SELECT * FROM key_contracts WHERE key_id = ? ORDER BY created_at DESC LIMIT 1");
    $st->execute([$keyId]);
}
$kc = $st->fetch(PDO::FETCH_ASSOC);
if (!$kc) {
    http_response_code(404);
    exit('Contract niet gevonden.');
}
$contractId = (int)$kc['id'];

// Sleutel + kast + band ophalen (live, zodat naam/band actueel zijn)
$kst = $pdo->prepare("
    SELECT k.key_code, k.description, k.locker_id,
           l.locker_no, l.band_id,
           b.name AS band_name
    FROM `keys` k
    LEFT JOIN lockers l ON l.id = k.locker_id
    LEFT JOIN bands b   ON b.id = l.band_id AND b.deleted_at IS NULL
    WHERE k.id = ?
");
$kst->execute([(int)$kc['key_id']]);
$key = $kst->fetch(PDO::FETCH_ASSOC) ?: ['key_code'=>'','description'=>'','locker_no'=>'','band_name'=>''];

// Opgeslagen velden (alleen nog voor de datum en de vrij ingevulde bandnaam)
$d = [];
if (!empty($kc['contract_data'])) {
    $d = json_decode((string)$kc['contract_data'], true) ?: [];
}

$fnameBuild = static function (?string $f, ?string $t, ?string $l): string {
    return trim(implode(' ', array_filter([$f, $t, $l])));
};

// Bestuurslid: volledige naam (voornaam tussenvoegsel achternaam) uit users
$boardMemberName = '';
if (!empty($kc['board_member_id'])) {
    $bst = $pdo->prepare("SELECT first_name, tussenvoegsel, last_name, username FROM users WHERE id = ?");
    $bst->execute([(int)$kc['board_member_id']]);
    if ($bm = $bst->fetch(PDO::FETCH_ASSOC)) {
        $boardMemberName = $fnameBuild($bm['first_name'], $bm['tussenvoegsel'], $bm['last_name']);
        if ($boardMemberName === '') $boardMemberName = (string)($bm['username'] ?? '');
    }
}

// Bandlid / ondertekenaar: volledige naam uit contacts
$bandContactName = ''; $bandContactMail = '';
if (!empty($kc['band_contact_id'])) {
    $cst = $pdo->prepare("SELECT first_name, tussenvoegsel, last_name, name, email FROM contacts WHERE id = ?");
    $cst->execute([(int)$kc['band_contact_id']]);
    if ($c = $cst->fetch(PDO::FETCH_ASSOC)) {
        $bandContactName = $fnameBuild($c['first_name'], $c['tussenvoegsel'], $c['last_name']);
        if ($bandContactName === '') $bandContactName = (string)($c['name'] ?? '');
        $bandContactMail = (string)($c['email'] ?? '');
    }
}

// Band/organisatie: eerst de band van de kast, anders de vrij ingevulde naam
$bandName = (string)($key['band_name'] ?? '');
if ($bandName === '') $bandName = (string)($d['custom_band_name'] ?? '');
if ($bandName === '') $bandName = 'Onbekend';

$location        = 'Pop Oefenruimte Zevenaar';
$lockerNo        = $key['locker_no'] ?: ($kc['locker_no'] ?? ($d['locker_no'] ?? 'n.v.t.'));
$keyCode         = (string)($key['key_code'] ?? '');
$keyDescription  = (string)($key['description'] ?? '');
$startDate       = $d['start_date'] ?? date('Y-m-d');
$endDate         = $d['end_date']   ?? date('Y-m-d', strtotime('+1 year'));
$contractNumber  = $kc['contract_number'] ?? ('KEY-CON-' . date('Ymd') . '-' . str_pad((string)$contractId, 5, '0', STR_PAD_LEFT));

// Upload-token aanmaken of hergebruiken
$ttl = (int)($GLOBALS['config']['contracts']['upload_token_ttl_minutes'] ?? 30);
try {
    $tok = contractEnsureUploadToken($pdo, $contractId, $ttl, (int)($user['id'] ?? 0));
} catch (Throwable $e) {
    http_response_code(500);
    exit('Kon upload-code niet aanmaken: ' . h($e->getMessage()));
}
$uploadUrl  = contractUploadUrl($tok['token']);
$shortCode  = (string)$tok['short_code'];
$expiresFmt = date('d-m-Y H:i', strtotime((string)$tok['expires_at']));

// QR: PNG indien Imagick, anders inline SVG (en altijd de tekstcode als terugval)
$qrPng = contractQrPngDataUri($uploadUrl, 200);
$qrSvg = $qrPng ? null : null;
try {
    if ($qrPng === null) {
        $qrSvg = contractQrSvg($uploadUrl, 200);
    }
} catch (Throwable $e) {
    $qrSvg = null;
}

$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$baseUploadHost = (defined('APP_URL') ? APP_URL : '') . '/contract_upload.php';

// QR-blok voor in de PDF
if ($qrPng !== null) {
    $qrImgTag = '<img src="' . $qrPng . '" style="width:150px;height:150px;">';
} elseif ($qrSvg !== null) {
    // DomPDF kan eenvoudige inline-SVG aan; lukt het niet, dan blijft de tekstcode over
    $qrImgTag = '<div style="width:150px;height:150px;">' . $qrSvg . '</div>';
} else {
    $qrImgTag = '<div style="width:150px;height:150px;border:1px dashed #999;text-align:center;line-height:150px;color:#999;">QR</div>';
}

$qrBlock = '
<div class="qr-box">
  <table><tr>
    <td style="width:165px;">' . $qrImgTag . '</td>
    <td style="vertical-align:top;padding-left:10px;">
      <p style="margin:0 0 4px 0;"><span class="bold">Getekend contract uploaden</span></p>
      <p style="margin:0 0 4px 0;font-size:10pt;">Onderteken dit contract, maak er een foto van met je telefoon
      en scan de QR-code hiernaast om de foto te uploaden.</p>
      <p style="margin:0;font-size:10pt;">Werkt de QR niet? Ga naar <span class="bold">' . $esc($baseUploadHost) . '</span>
      en voer code <span class="bold" style="letter-spacing:1px;">' . $esc($shortCode) . '</span> in.</p>
      <p style="margin:6px 0 0 0;font-size:9pt;color:#64748b;">Geldig tot ' . $esc($expiresFmt) . '.</p>
    </td>
  </tr></table>
</div>';

// ---- Contract-HTML (zelfde opmaak als contract_edit.php, met QR-blok) ----
$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sleutelcontract ' . $esc($keyCode) . '</title>
<style>
body{font-family:Helvetica,sans-serif;margin:1.5cm 1.5cm;line-height:1.25;color:#1e293b;font-size:11pt}
h1{text-align:center;color:#1e3a8a;margin-bottom:12px;font-size:20px}
h2{font-size:13pt;margin-top:12px;margin-bottom:4px;color:#1e3a8a;border-bottom:1px solid #ccc;padding-bottom:3px}
.header{text-align:center;margin-bottom:16px}.parties{margin:12px 0}
.key-details{background:#f8fafc;padding:10px 12px;margin:12px 0;border-left:4px solid #2563eb}
.signature-box{margin-top:20px}
.signature-line{border-top:1px solid #000;width:220px;margin-top:30px;padding-top:4px;display:inline-block;font-size:10pt}
.footer{margin-top:18px;font-size:9pt;color:#64748b;text-align:center}
.bold{font-weight:bold}ol{padding-left:20px;margin:6px 0}li{margin-bottom:3px}p{margin:6px 0}
table{width:100%;border-collapse:collapse;margin-top:10px}td{vertical-align:top;padding:0}
.qr-box{margin-top:16px;padding:12px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc}
.qr-box table{margin-top:0}
</style></head><body>
<div class="header"><h1>SLEUTELOVEREENKOMST</h1><p>Stichting Popkultuur Zevenaar – POR-kast sleutel beheer</p></div>
<div class="parties">
  <p><span class="bold">ONDERGETEKENDEN:</span></p>
  <p><strong>1. Stichting Popkultuur Zevenaar</strong>, statutair gevestigd te Zevenaar,<br>
  vertegenwoordigd door <span class="bold">' . $esc($boardMemberName) . '</span> (bestuurslid),<br>
  hierna te noemen: <strong>"Verhuurder"</strong>;</p>
  <p><strong>2. ' . $esc($bandName) . '</strong>,<br>
  vertegenwoordigd door <span class="bold">' . $esc($bandContactName) . '</span><br>
  (e-mail: ' . $esc($bandContactMail) . '),<br>
  hierna te noemen: <strong>"Gebruiker"</strong>;</p>
</div>
<p>Verklaren het volgende te zijn overeengekomen:</p>
<div class="key-details">
  <p><span class="bold">Betreft sleutel:</span><br>
  Kastnummer: <strong>' . $esc($lockerNo) . '</strong><br>
  Sleutelnummer: <strong>' . $esc($keyCode) . '</strong><br>
  Omschrijving: ' . $esc($keyDescription ?: 'Standaard kastsleutel') . '<br>
  Locatie: <strong>' . $esc($location) . '</strong></p>
</div>
<h2>Artikel 1 – Doel en reikwijdte</h2>
<p>De sleutel wordt uitsluitend verstrekt om de Gebruiker toegang te verlenen tot de betreffende POR-kast. De sleutel blijft eigendom van Verhuurder.</p>
<h2>Artikel 2 – Verplichtingen Gebruiker</h2>
<ol>
  <li>De Gebruiker mag de sleutel niet vermenigvuldigen, aan derden uitlenen of op enige wijze overdragen zonder schriftelijke toestemming van Verhuurder.</li>
  <li>Bij verlies, diefstal of beschadiging dient de Gebruiker dit onmiddellijk (binnen 24 uur) te melden aan Verhuurder.</li>
  <li>De Gebruiker draagt zorg voor de sleutel en neemt redelijke maatregelen om misbruik te voorkomen.</li>
  <li>Na beëindiging van het gebruik van de kast dient de sleutel binnen 7 dagen te worden ingeleverd bij Verhuurder.</li>
</ol>
<div style="page-break-before: always; clear: both;"></div>
<h2>Artikel 3 – Aansprakelijkheid en kosten</h2>
<ol>
  <li>Bij verlies of diefstal is de Gebruiker een vergoeding verschuldigd van € 75,- per sleutel voor vervanging en administratie.</li>
  <li>Verhuurder is niet aansprakelijk voor schade ontstaan door onbevoegd gebruik van de sleutel, tenzij sprake is van opzet of grove schuld.</li>
</ol>
<h2>Artikel 4 – Duur en beëindiging</h2>
<p>Deze overeenkomst gaat in op <strong>' . $esc($startDate) . '</strong> en eindigt na schriftelijke opzegging door een der partijen, met inachtneming van een opzegtermijn van één maand.</p>
<h2>Artikel 5 – Slotbepalingen</h2>
<ol>
  <li>Op deze overeenkomst is Nederlands recht van toepassing.</li>
  <li>Wijzigingen of aanvullingen zijn slechts geldig indien schriftelijk overeengekomen.</li>
</ol>
<div class="signature-box"><table><tr>
  <td style="width:45%;"><strong>Verhuurder</strong><br>Stichting Popkultuur Zevenaar<br><br>Naam: ' . $esc($boardMemberName) . '<br><br><div class="signature-line">Handtekening</div><br>Datum: ____________________</td>
  <td style="width:10%;"></td>
  <td style="width:45%;"><strong>Gebruiker</strong><br>' . $esc($bandName) . '<br>Namens deze: ' . $esc($bandContactName) . '<br><br><div class="signature-line">Handtekening</div><br>Datum: ____________________</td>
</tr></table></div>
' . $qrBlock . '
<div class="footer"><p>Contractnummer: ' . $esc($contractNumber) . ' | Gegenereerd op ' . date('d-m-Y H:i') . '</p></div>
</body></html>';

try {
    $options = new Options();
    $options->set('defaultFont', 'Helvetica');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdf = $dompdf->output();

    auditLog($pdo, 'CONTRACT_PRINTED', 'key_contract_id=' . $contractId, [
        'contract_number' => $contractNumber,
        'token_id'        => $tok['id'],
    ]);

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="contract_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$keyCode) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Fout bij genereren PDF: ' . h($e->getMessage()));
}
