<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
requireRole(['ADMIN','BEHEER']);

// ---------- AJAX ENDPOINTS ----------
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_GET['action'] === 'get_lockers_for_band') {
            $bandId = (int)($_GET['band_id'] ?? 0);
            if ($bandId <= 0) { echo json_encode([]); exit; }
            $stmt = $pdo->prepare("SELECT id, locker_no FROM lockers WHERE band_id = ? AND deleted_at IS NULL ORDER BY locker_no");
            $stmt->execute([$bandId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
        if ($_GET['action'] === 'get_band_contacts') {
            $lockerId = (int)($_GET['locker_id'] ?? 0);
            $response = ['band_id' => null, 'band_name' => null, 'contacts' => []];
            if ($lockerId > 0) {
                $stmt = $pdo->prepare("
                    SELECT l.band_id, b.name as band_name,
                           b.primary_contact_id, b.secondary_contact_id,
                           c1.id as c1_id, c1.name as c1_name, c1.email as c1_email,
                           c2.id as c2_id, c2.name as c2_name, c2.email as c2_email
                    FROM lockers l
                    LEFT JOIN bands b ON b.id = l.band_id AND b.deleted_at IS NULL
                    LEFT JOIN contacts c1 ON c1.id = b.primary_contact_id AND c1.deleted_at IS NULL
                    LEFT JOIN contacts c2 ON c2.id = b.secondary_contact_id AND c2.deleted_at IS NULL
                    WHERE l.id = ? AND l.deleted_at IS NULL
                ");
                $stmt->execute([$lockerId]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($data && $data['band_id']) {
                    $response['band_id'] = (int)$data['band_id'];
                    $response['band_name'] = $data['band_name'] ?? '';
                    if ($data['primary_contact_id']) {
                        $response['contacts'][] = [
                            'id' => (int)$data['primary_contact_id'],
                            'name' => $data['c1_name'],
                            'email' => $data['c1_email'],
                            'role' => 'Primair contact'
                        ];
                    }
                    if ($data['secondary_contact_id']) {
                        $response['contacts'][] = [
                            'id' => (int)$data['secondary_contact_id'],
                            'name' => $data['c2_name'],
                            'email' => $data['c2_email'],
                            'role' => 'Secondair contact'
                        ];
                    }
                }
            }
            echo json_encode($response);
            exit;
        }
        if ($_GET['action'] === 'get_all_contacts') {
            $stmt = $pdo->query("SELECT id, name, email FROM contacts WHERE deleted_at IS NULL ORDER BY name");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
        if ($_GET['action'] === 'get_contract_pdf') {
            $contractId = (int)($_GET['id'] ?? 0);
            if ($contractId <= 0) { http_response_code(400); exit('Ongeldig id'); }
            $stmt = $pdo->prepare("SELECT contract_pdf, contract_pdf_name, contract_pdf_mime FROM key_contracts WHERE id = ?");
            $stmt->execute([$contractId]);
            $row = $stmt->fetch();
            if (!$row || !$row['contract_pdf']) { http_response_code(404); exit('Geen PDF gevonden'); }
            $mime = $row['contract_pdf_mime'] ?: 'application/pdf';
            $filename = $row['contract_pdf_name'] ?: 'contract.pdf';
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . strlen($row['contract_pdf']));
            echo $row['contract_pdf'];
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

// DomPDF laden
$dompdfAutoloader = __DIR__ . '/../../../libs/porbeheer/vendor/dompdf/dompdf/src/Autoloader.php';
if (file_exists($dompdfAutoloader)) {
    require_once $dompdfAutoloader;
    Dompdf\Autoloader::register();
}
use Dompdf\Dompdf;
use Dompdf\Options;

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('keys', $pdo);
auditLog($pdo, 'PAGE_VIEW', 'admin/contract_edit.php');

function h($v): string {
    if ($v === null) return '';
    if (is_int($v) || is_float($v)) return (string)$v;
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Database kolommen voor BLOB (veilig aanmaken)
function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
try {
    addColumnIfNotExists($pdo, 'key_contracts', 'contract_pdf', 'LONGBLOB NULL');
    addColumnIfNotExists($pdo, 'key_contracts', 'contract_pdf_name', 'VARCHAR(255) NULL');
    addColumnIfNotExists($pdo, 'key_contracts', 'contract_pdf_mime', "VARCHAR(100) NULL DEFAULT 'application/pdf'");
} catch (Throwable $e) {
    error_log("Fout bij aanmaken contract BLOB kolommen: " . $e->getMessage());
}

$errors = [];
$msg = null;
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) { header('Location: /admin/keys.php'); exit; }

// Haal sleutelgegevens op
$st = $pdo->prepare("SELECT * FROM `keys` WHERE id = ? AND deleted_at IS NULL");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { header('Location: /admin/keys.php?msg=notfound'); exit; }

// Huidige locker en band info
$lockers = $pdo->query("SELECT l.id, l.locker_no, l.band_id, b.name AS band_name FROM lockers l LEFT JOIN bands b ON b.id = l.band_id AND b.deleted_at IS NULL WHERE l.deleted_at IS NULL ORDER BY l.locker_no")->fetchAll();
$currentLocker = null; $currentBandId = null; $currentBandName = null; $currentLockerNo = null;
if ($row['locker_id']) {
    foreach ($lockers as $l) if ($l['id'] == $row['locker_id']) {
        $currentLocker = $l; $currentBandId = $l['band_id']; $currentBandName = $l['band_name']; $currentLockerNo = $l['locker_no']; break;
    }
}

// Bepaal of de sleutel aan een band is gekoppeld
$hasBand = !empty($currentBandName);

// Bestaand contract ophalen (met blob velden)
$existingContract = null; $selectedBandContactId = null; $selectedBoardMemberId = null;
$contractLocation = 'Pop Oefenruimte Zevenaar';
$contractStartDate = date('Y-m-d'); $contractEndDate = date('Y-m-d', strtotime('+1 year'));
$contractPdfName = null; $contractPdfMime = null; $contractPdfBlob = null;
$contractCustomBandName = '';

$stmt = $pdo->prepare("SELECT * FROM key_contracts WHERE key_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$id]);
$existingContract = $stmt->fetch();
if ($existingContract) {
    $selectedBandContactId = $existingContract['band_contact_id'];
    $selectedBoardMemberId = $existingContract['board_member_id'];
    $contractLocation = $existingContract['location'] ?: 'Pop Oefenruimte Zevenaar';
    $contractPdfName = $existingContract['contract_pdf_name'] ?? null;
    $contractPdfMime = $existingContract['contract_pdf_mime'] ?? null;
    $contractPdfBlob = $existingContract['contract_pdf'] ?? null;
    if ($existingContract['contract_data']) {
        $data = json_decode($existingContract['contract_data'], true);
        if ($data) {
            $contractStartDate = $data['start_date'] ?? date('Y-m-d');
            $contractEndDate = $data['end_date'] ?? date('Y-m-d', strtotime('+1 year'));
            $contractCustomBandName = $data['custom_band_name'] ?? '';
        }
    }
}

// ---------- PDF generatie functie (compact, max 2 pag) ----------
function generateContractPDF(
    string $bandName,
    string $bandContactName,
    string $bandContactEmail,
    string $boardMemberName,
    string $location,
    string $lockerNo,
    string $keyCode,
    string $keyDescription,
    string $startDate,
    string $endDate
): string {
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Sleutelcontract ' . htmlspecialchars($keyCode) . '</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; margin: 1.5cm 1.5cm; line-height: 1.25; color: #1e293b; font-size: 11pt; }
        h1 { text-align: center; color: #1e3a8a; margin-bottom: 12px; font-size: 20px; }
        h2 { font-size: 13pt; margin-top: 12px; margin-bottom: 4px; color: #1e3a8a; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        .header { text-align: center; margin-bottom: 16px; }
        .parties { margin: 12px 0; }
        .key-details { background: #f8fafc; padding: 10px 12px; margin: 12px 0; border-left: 4px solid #2563eb; }
        .signature-box { margin-top: 24px; }
        .signature-line { border-top: 1px solid #000; width: 220px; margin-top: 30px; padding-top: 4px; display: inline-block; font-size: 10pt; }
        .footer { margin-top: 24px; font-size: 9pt; color: #64748b; text-align: center; }
        .bold { font-weight: bold; }
        ol { padding-left: 20px; margin: 6px 0; }
        li { margin-bottom: 3px; }
        p { margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        td { vertical-align: top; padding: 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>SLEUTELOVEREENKOMST</h1>
        <p>Stichting Popcultuur Zevenaar – POR-kast beheer</p>
    </div>
    <div class="parties">
        <p><span class="bold">ONDERGETEKENDEN:</span></p>
        <p><strong>1. Stichting Popcultuur Zevenaar</strong>, statutair gevestigd te Zevenaar,<br>
        vertegenwoordigd door <span class="bold">' . htmlspecialchars($boardMemberName) . '</span> (bestuurslid),<br>
        hierna te noemen: <strong>"Verhuurder"</strong>;</p>
        <p><strong>2. ' . htmlspecialchars($bandName) . '</strong>,<br>
        vertegenwoordigd door <span class="bold">' . htmlspecialchars($bandContactName) . '</span><br>
        (e-mail: ' . htmlspecialchars($bandContactEmail) . '),<br>
        hierna te noemen: <strong>"Gebruiker"</strong>;</p>
    </div>
    <p>Verklaren het volgende te zijn overeengekomen:</p>
    <div class="key-details">
        <p><span class="bold">Betreft sleutel:</span><br>
        Kastnummer: <strong>' . htmlspecialchars($lockerNo) . '</strong><br>
        Sleutelnummer: <strong>' . htmlspecialchars($keyCode) . '</strong><br>
        Omschrijving: ' . htmlspecialchars($keyDescription ?: 'Standaard kastsleutel') . '<br>
        Locatie: <strong>' . htmlspecialchars($location) . '</strong></p>
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
    <br style="page-break-before: always; clear: both;">
    <h2>Artikel 3 – Aansprakelijkheid en kosten</h2>
    <ol>
        <li>Bij verlies of diefstal is de Gebruiker een vergoeding verschuldigd van € 75,- per sleutel voor vervanging en administratie.</li>
        <li>Verhuurder is niet aansprakelijk voor schade ontstaan door onbevoegd gebruik van de sleutel, tenzij sprake is van opzet of grove schuld.</li>
    </ol>
    <h2>Artikel 4 – Duur en beëindiging</h2>
    <p>Deze overeenkomst gaat in op <strong>' . htmlspecialchars($startDate) . '</strong> en eindigt op <strong>' . htmlspecialchars($endDate) . '</strong>, tenzij tussentijds opgezegd met inachtneming van een opzegtermijn van één maand.</p>
    <h2>Artikel 5 – Slotbepalingen</h2>
    <ol>
        <li>Op deze overeenkomst is Nederlands recht van toepassing.</li>
        <li>Wijzigingen of aanvullingen zijn slechts geldig indien schriftelijk overeengekomen.</li>
    </ol>
    <div class="signature-box">
        <table>
            <tr>
                <td style="width:45%;">
                    <strong>Verhuurder</strong><br>
                    Stichting Popcultuur Zevenaar<br><br>
                    Naam: ' . htmlspecialchars($boardMemberName) . '<br><br>
                    <div class="signature-line">Handtekening</div><br>
                    Datum: ____________________
                </td>
                <td style="width:10%;"></td>
                <td style="width:45%;">
                    <strong>Gebruiker</strong><br>
                    ' . htmlspecialchars($bandName) . '<br>
                    Namens deze: ' . htmlspecialchars($bandContactName) . '<br><br>
                    <div class="signature-line">Handtekening</div><br>
                    Datum: ____________________
                </td>
            </tr>
        </table>
    </div>
    <div class="footer">
        <p>Contractnummer: KEY-CON-' . date('Ymd') . '-' . str_pad((string)$keyCode, 5, '0', STR_PAD_LEFT) . ' | Gegenereerd op ' . date('d-m-Y H:i') . '</p>
    </div>
</body>
</html>';
    try {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    } catch (Throwable $e) {
        error_log("PDF generatie fout: " . $e->getMessage());
        throw $e;
    }
}

// ---------- preview_pdf actie ----------
if (isset($_GET['preview_pdf'])) {
    try {
        $bandContactId = (int)($_GET['band_contact_id'] ?? 0);
        $boardMemberId = (int)($_GET['board_member_id'] ?? 0);
        $location = trim($_GET['location'] ?? 'Pop Oefenruimte Zevenaar');
        $startDate = trim($_GET['start_date'] ?? date('Y-m-d'));
        $endDate = trim($_GET['end_date'] ?? date('Y-m-d', strtotime('+1 year')));
        $lockerId = (int)($_GET['locker_id'] ?? 0);
        $customBandName = trim($_GET['custom_band_name'] ?? '');

        // Gebruik bandnaam uit locker indien aanwezig
        $bandName = '';
        $lockerNo = 'n.v.t.';
        if ($lockerId > 0) {
            $stmt = $pdo->prepare("SELECT l.locker_no, b.name as band_name FROM lockers l LEFT JOIN bands b ON b.id = l.band_id WHERE l.id = ?");
            $stmt->execute([$lockerId]);
            $info = $stmt->fetch();
            if ($info) {
                $lockerNo = $info['locker_no'] ?? 'n.v.t.';
                if (!empty($info['band_name'])) {
                    $bandName = $info['band_name'];
                }
            }
        }
        // Fallback: custom naam of Human Kind
        if (empty($bandName)) {
            $bandName = $customBandName ?: 'Human Kind';
        }

        $bandContactName = ''; $bandContactEmail = '';
        if ($bandContactId > 0) {
            $stmt = $pdo->prepare("SELECT name, email FROM contacts WHERE id = ?");
            $stmt->execute([$bandContactId]);
            $c = $stmt->fetch();
            if ($c) {
                $bandContactName = $c['name'];
                $bandContactEmail = $c['email'] ?? '';
            }
        }

        $boardMembersList = $pdo->query("SELECT DISTINCT u.id, u.username, u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE ur.role = 'BESTUURSLID' AND u.deleted_at IS NULL AND u.status = 'ACTIVE' ORDER BY u.username")->fetchAll();
        $boardMemberName = '';
        foreach ($boardMembersList as $m) {
            if ($m['id'] == $boardMemberId) { $boardMemberName = $m['username'] ?? ''; break; }
        }

        $pdfContent = generateContractPDF(
            $bandName, $bandContactName, $bandContactEmail, $boardMemberName,
            $location, $lockerNo, $row['key_code'], $row['description'] ?? '',
            $startDate, $endDate
        );

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="contract_' . $row['key_code'] . '.pdf"');
        echo $pdfContent;
        exit;
    } catch (Throwable $e) {
        die('Fout bij genereren PDF: ' . $e->getMessage());
    }
}

// ---------- save_contract (met BLOB) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contract'])) {
    try {
        requireCsrf($_POST['csrf'] ?? '');
        $bandContactId = (int)($_POST['band_contact_id'] ?? 0);
        $boardMemberId = (int)($_POST['board_member_id'] ?? 0);
        $location = trim($_POST['location'] ?? 'Pop Oefenruimte Zevenaar');
        $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));
        $endDate = trim($_POST['end_date'] ?? date('Y-m-d', strtotime('+1 year')));
        $lockerId = (int)($_POST['locker_id'] ?? 0);
        $customBandName = trim($_POST['custom_band_name'] ?? '');

        // Verwerk bandnaam volgens regels
        if ($hasBand) {
            // Gebruik bandnaam uit locker
            $customBandName = $currentBandName;
        } else {
            if (empty($customBandName)) {
                $customBandName = 'Human Kind';
            }
        }

        if ($bandContactId <= 0) throw new Exception('Selecteer een contactpersoon');
        if ($boardMemberId <= 0) throw new Exception('Selecteer een bestuurslid');

        $uploadedPdfBlob = null;
        $uploadedPdfName = null;
        if (isset($_FILES['signed_pdf']) && $_FILES['signed_pdf']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['signed_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') throw new Exception('Alleen PDF-bestanden toegestaan');
            $uploadedPdfBlob = file_get_contents($_FILES['signed_pdf']['tmp_name']);
            $uploadedPdfName = $_FILES['signed_pdf']['name'];
        }

        $lockerNo = $currentLockerNo ?? '';
        $bandName = $customBandName;

        $contactStmt = $pdo->prepare("SELECT name, email FROM contacts WHERE id = ?");
        $contactStmt->execute([$bandContactId]);
        $contact = $contactStmt->fetch();

        $boardMembersList = $pdo->query("SELECT DISTINCT u.id, u.username, u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE ur.role = 'BESTUURSLID' AND u.deleted_at IS NULL AND u.status = 'ACTIVE' ORDER BY u.username")->fetchAll();
        $boardMemberName = '';
        foreach ($boardMembersList as $m) if ($m['id'] == $boardMemberId) { $boardMemberName = $m['username'] ?? ''; break; }

        $contractNumber = 'KEY-CON-' . date('Ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        $contractData = json_encode([
            'band_contact_id' => $bandContactId,
            'band_contact_name' => $contact['name'] ?? '',
            'band_contact_email' => $contact['email'] ?? '',
            'board_member_id' => $boardMemberId,
            'board_member_name' => $boardMemberName,
            'location' => $location,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'locker_no' => $lockerNo,
            'key_code' => $row['key_code'],
            'key_description' => $row['description'],
            'custom_band_name' => $customBandName
        ]);

        $checkStmt = $pdo->prepare("SELECT id FROM key_contracts WHERE key_id = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $sql = "UPDATE key_contracts SET contract_number=?, band_contact_id=?, board_member_id=?, location=?, locker_no=?, contract_data=?, created_at=NOW()";
            $params = [$contractNumber, $bandContactId, $boardMemberId, $location, $lockerNo, $contractData];
            if ($uploadedPdfBlob !== null) {
                $sql .= ", contract_pdf=?, contract_pdf_name=?, contract_pdf_mime='application/pdf'";
                array_push($params, $uploadedPdfBlob, $uploadedPdfName);
            }
            $sql .= " WHERE key_id=?";
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            $msg = 'Contract bijgewerkt!';
        } else {
            $pdo->prepare("INSERT INTO key_contracts (key_id, contract_number, band_contact_id, board_member_id, location, locker_no, contract_data, contract_pdf, contract_pdf_name, contract_pdf_mime, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")->execute([$id, $contractNumber, $bandContactId, $boardMemberId, $location, $lockerNo, $contractData, $uploadedPdfBlob, $uploadedPdfName, $uploadedPdfBlob ? 'application/pdf' : null]);
            $msg = 'Contract opgeslagen!';
        }
        auditLog($pdo, 'CONTRACT_SAVED', 'key_id=' . $id);
        header("Location: /admin/contract_edit.php?id=$id&msg=saved");
        exit;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

$title = 'Sleutelcontract: ' . h($row['key_code']);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - <?= h($title) ?></title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('<?= h($bg) ?>') no-repeat center center fixed;background-size:cover;}
.backdrop{min-height:100vh;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
.wrap{width:min(1100px,96vw);}
.topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.brand h1{margin:0;font-size:28px;letter-spacing:.5px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);backdrop-filter:blur(10px);min-width:260px;}
.userbox .line1{font-weight:bold}
.userbox .line2{color:var(--muted);margin-top:6px;font-size:13px;display:flex;gap:10px;flex-wrap:wrap}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));box-shadow:var(--shadow);backdrop-filter:blur(12px);padding:18px;}
.card{border-radius:16px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));padding:20px;margin-bottom:20px;box-shadow:0 10px 22px rgba(0,0,0,.30);backdrop-filter:blur(10px);}
.card:last-child{margin-bottom:0;}
h2{margin:0 0 15px 0;font-size:20px;border-bottom:1px solid var(--border);padding-bottom:10px;}
h3{margin:0 0 10px 0;font-size:16px;}
.small{font-size:13px;color:var(--muted)}
a{color:#fff;text-decoration:none} a:hover{color:#ffd9b3}
.msg{margin:10px 0;font-size:13px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.err{color:#ffb3b3}
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
.button-group{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;}
.contract-info-line{margin:8px 0;padding:5px 0;border-bottom:1px solid var(--border);}
#keepAliveBtn { margin-left: auto; }
</style>
</head>
<body>
<div class="backdrop"><div class="wrap">
<div class="topbar">
    <div class="brand"><h1>📄 <?= h($title) ?></h1><div class="sub">Sleutelcontract beheren</div></div>
    <div class="userbox">
        <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
        <div class="line2">
            <a href="/admin/keys_edit.php?id=<?= $id ?>">← Terug naar sleutel</a>
            <a href="/admin/keys.php">Overzicht</a>
            <button class="btn btn-info" id="keepAliveBtn" style="padding:5px 12px; font-size:12px;">🔄 Verleng sessie</button>
        </div>
    </div>
</div>

<div class="panel">
    <?php if ($errors): ?><div class="msg err"><ul><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul></div><?php endif; ?>
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved') $msg = 'Contract opgeslagen!'; ?>
    <?php if ($msg): ?><div class="msg success"><?= h($msg) ?></div><?php endif; ?>

    <!-- PANEEL: Contractgegevens -->
    <div class="card">
        <h2>📜 Contract voor sleutel <?= h($row['key_code']) ?></h2>
        <form method="post" enctype="multipart/form-data" id="contractForm">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="save_contract" value="1">
            <input type="hidden" name="locker_id" id="contract_locker_id" value="<?= h($row['locker_id'] ?? '') ?>">

            <div class="row">
                <?php if ($hasBand): ?>
                    <!-- Gekoppelde band: readonly weergave -->
                    <div class="field">
                        <label>Band / Organisatie</label>
                        <input type="text" value="<?= h($currentBandName) ?>" readonly>
                    </div>
                <?php else: ?>
                    <!-- Geen band: invulveld met default Human Kind -->
                    <div class="field">
                        <label>Band / Organisatie</label>
                        <input type="text" name="custom_band_name" id="custom_band_name"
                               value="<?= h($contractCustomBandName ?: 'Human Kind') ?>"
                               placeholder="Bijv. Human Kind">
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label>Kast</label>
                    <input type="text" id="contract_locker_no" value="<?= h($currentLockerNo ?: 'n.v.t.') ?>" readonly>
                </div>
            </div>

            <div class="row">
                <div class="field">
                    <label>Contactpersoon (ondertekenaar)</label>
                    <select name="band_contact_id" id="band_contact_id" required><option value="">-- Kies contactpersoon --</option></select>
                    <div class="small">Wie tekent namens de gebruiker</div>
                </div>
                <div class="field">
                    <label>Bestuurslid</label>
                    <select name="board_member_id" id="board_member_id" required>
                        <option value="">-- Kies bestuurslid --</option>
                        <?php 
                        $boardMembers = $pdo->query("SELECT DISTINCT u.id, u.username, u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE ur.role = 'BESTUURSLID' AND u.deleted_at IS NULL AND u.status = 'ACTIVE' ORDER BY u.username")->fetchAll();
                        foreach ($boardMembers as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= ($selectedBoardMemberId == $m['id']) ? 'selected' : '' ?>><?= h($m['username']) ?> <?= $m['email'] ? ' - '.h($m['email']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="field"><label>Locatie</label><input type="text" name="location" value="<?= h($contractLocation) ?>" required></div>
            </div>
            <div class="row">
                <div class="field"><label>Ingangsdatum</label><input type="date" name="start_date" value="<?= h($contractStartDate) ?>" required></div>
                <div class="field"><label>Einddatum</label><input type="date" name="end_date" value="<?= h($contractEndDate) ?>" required></div>
            </div>
            <div class="field">
                <label>Getekend contract (PDF) uploaden</label>
                <input type="file" name="signed_pdf" accept=".pdf">
                <?php if ($existingContract && $contractPdfBlob): ?>
                    <div class="small" style="margin-top:5px;">✅ <a href="/admin/contract_edit.php?action=get_contract_pdf&id=<?= $existingContract['id'] ?>" target="_blank">Bekijk huidig contract</a></div>
                <?php endif; ?>
            </div>
            <div class="button-group">
                <button type="button" class="btn btn-warning" onclick="generateContractPDF()">📄 PDF Contract</button>
                <button type="submit" class="btn btn-success">💾 Contract opslaan</button>
            </div>
        </form>
    </div>

    <?php if ($existingContract): ?>
    <div class="card">
        <h2>📋 Opgeslagen contract</h2>
        <div class="contract-info-line"><strong>Contractnummer:</strong> <?= h($existingContract['contract_number']) ?></div>
        <div class="contract-info-line"><strong>Aangemaakt:</strong> <?= h($existingContract['created_at']) ?></div>
        <div class="contract-info-line"><strong>Locatie:</strong> <?= h($existingContract['location']) ?></div>
        <div class="contract-info-line"><strong>Kast:</strong> <?= h($existingContract['locker_no']) ?></div>
        <?php if ($contractPdfBlob): ?>
        <div class="contract-info-line"><strong>Getekend contract:</strong> 
            <a href="/admin/contract_edit.php?action=get_contract_pdf&id=<?= $existingContract['id'] ?>" target="_blank" class="btn btn-sm btn-info" style="padding:4px 10px; font-size:13px;">📄 Bekijk PDF</a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</div></div>

<script>
let allContacts = [];
fetch('/admin/contract_edit.php?action=get_all_contacts').then(r=>r.json()).then(d=>{allContacts=d; updateContractContacts();}).catch(console.error);

function updateContractContacts() {
    const bandContactSelect = document.getElementById('band_contact_id');
    bandContactSelect.innerHTML = '<option value="">-- Kies contactpersoon --</option>';
    allContacts.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.name}${c.email ? ' - ' + c.email : ''}`;
        if (c.id == <?= (int)$selectedBandContactId ?>) opt.selected = true;
        bandContactSelect.appendChild(opt);
    });
}

window.generateContractPDF = function() {
    const bandContact = document.getElementById('band_contact_id')?.value;
    const boardMember = document.getElementById('board_member_id')?.value;
    const location = document.querySelector('input[name="location"]')?.value || 'Pop Oefenruimte Zevenaar';
    const start = document.querySelector('input[name="start_date"]')?.value;
    const end = document.querySelector('input[name="end_date"]')?.value;
    const lockerId = document.getElementById('contract_locker_id')?.value;
    // Gebruik custom band naam veld als het bestaat, anders de bandnaam uit PHP
    const customBandInput = document.getElementById('custom_band_name');
    const customBand = customBandInput ? customBandInput.value : <?= json_encode($currentBandName ?: 'Human Kind') ?>;

    if (!bandContact) { alert('Selecteer een contactpersoon.'); return; }
    if (!boardMember) { alert('Selecteer een bestuurslid.'); return; }
    if (!start || !end) { alert('Vul de ingangs- en einddatum in.'); return; }

    let url = `/admin/contract_edit.php?id=<?= $id ?>&preview_pdf=1&band_contact_id=${encodeURIComponent(bandContact)}&board_member_id=${encodeURIComponent(boardMember)}&location=${encodeURIComponent(location)}&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`;
    if (lockerId) url += `&locker_id=${encodeURIComponent(lockerId)}`;
    if (customBand) url += `&custom_band_name=${encodeURIComponent(customBand)}`;
    window.open(url, '_blank', 'width=800,height=600,toolbar=yes,scrollbars=yes');
};

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