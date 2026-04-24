<?php
// /public/admin/generate_contract.php
declare(strict_types=1);

require_once '/var/www/libs/porbeheer/app/bootstrap.php';
require_once '/var/www/libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN', 'BEHEER']);

// Dompdf fix
$cpdfPath = __DIR__ . '/../../../libs/porbeheer/vendor/DomPDF/lib/Cpdf.php';
if (file_exists($cpdfPath) && !class_exists('Dompdf\\Cpdf')) {
    require_once $cpdfPath;
}

use Dompdf\Dompdf;
use Dompdf\Options;

$pdo = $GLOBALS['pdo'];
$user = currentUser();
$message = '';
$error = '';

// Haal kasten op met band
$lockers = $pdo->query("
    SELECT l.id, l.locker_no, l.band_id, b.name AS band_name
    FROM lockers l
    LEFT JOIN bands b ON b.id = l.band_id AND b.deleted_at IS NULL
    WHERE l.deleted_at IS NULL AND l.band_id IS NOT NULL
    ORDER BY b.name ASC, l.locker_no ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Haal bestuursleden op
$boardMembers = $pdo->query("
    SELECT id, username, email
    FROM users 
    WHERE deleted_at IS NULL AND status = 'ACTIVE'
    AND role IN ('BESTUURSLID', 'ADMIN')
    ORDER BY username ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf($_POST['csrf'] ?? '');
        
        $lockerId = (int)($_POST['locker_id'] ?? 0);
        $bandContactName = trim((string)($_POST['band_contact_name'] ?? ''));
        $bandContactEmail = trim((string)($_POST['band_contact_email'] ?? ''));
        $boardMemberId = (int)($_POST['board_member_id'] ?? 0);
        $location = trim((string)($_POST['location'] ?? 'POR-kast Zevenaar'));
        $startDate = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
        $endDate = trim((string)($_POST['end_date'] ?? date('Y-m-d', strtotime('+1 year'))));
        
        // Haal kast gegevens op
        $stmt = $pdo->prepare("
            SELECT l.locker_no, l.band_id, b.name as band_name
            FROM lockers l
            LEFT JOIN bands b ON b.id = l.band_id
            WHERE l.id = ?
        ");
        $stmt->execute([$lockerId]);
        $locker = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$locker) throw new Exception('Kast niet gevonden');
        
        // Haal bestuurslid op
        $boardMember = null;
        foreach ($boardMembers as $bm) {
            if ($bm['id'] == $boardMemberId) $boardMember = $bm;
        }
        if (!$boardMember) throw new Exception('Bestuurslid niet gevonden');
        
        // Haal sleutels van deze kast
        $keys = $pdo->prepare("
            SELECT key_code, description FROM `keys` 
            WHERE locker_id = ? AND deleted_at IS NULL AND active = 1
        ");
        $keys->execute([$lockerId]);
        $keyList = $keys->fetchAll(PDO::FETCH_ASSOC);
        
        // Genereer PDF
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sleutelcontract</title>';
        $html .= '<style>body{font-family:Arial;margin:40px;} h1{text-align:center;} .signature-table{width:100%;margin-top:40px;} .signature-table td{width:50%;padding:20px;} .signature-box{border-top:1px solid #000;margin-top:40px;padding-top:10px;} .key-details{background:#ecf0f1;padding:15px;margin:20px 0;}</style>';
        $html .= '</head><body>';
        $html .= '<h1>SLEUTELCONTRACT</h1><p style="text-align:center">Stichting Popcultuur Zevenaar</p>';
        $html .= '<p><strong>Tussen:</strong><br>Stichting Popcultuur Zevenaar, vertegenwoordigd door <strong>' . htmlspecialchars($boardMember['username']) . '</strong> (bestuurslid)<br>(hierna: "Verhuurder")</p>';
        $html .= '<p><strong>En:</strong><br>Band: <strong>' . htmlspecialchars($locker['band_name']) . '</strong><br>vertegenwoordigd door: <strong>' . htmlspecialchars($bandContactName) . '</strong><br>(hierna: "Gebruiker")</p>';
        $html .= '<div class="key-details"><p><strong>Betreft:</strong> uitgifte van sleutels van kast ' . htmlspecialchars($locker['locker_no']) . '<br>Locatie: ' . htmlspecialchars($location) . '</p>';
        $html .= '<p><strong>Sleutel(s):</strong><ul>';
        foreach ($keyList as $key) {
            $html .= '<li>Sleutel ' . htmlspecialchars($key['key_code']) . ' - ' . htmlspecialchars($key['description'] ?? 'Geen omschrijving') . '</li>';
        }
        $html .= '</ul></p></div>';
        $html .= '<p><strong>Periode:</strong> ' . htmlspecialchars($startDate) . ' tot ' . htmlspecialchars($endDate) . '</p>';
        $html .= '<table class="signature-table"><tr>';
        $html .= '<td class="signature-box"><strong>Stichting Popcultuur Zevenaar</strong><br><br>Naam: ' . htmlspecialchars($boardMember['username']) . '<br><br>Handtekening: ____________________<br><br>Datum: ____________________</td>';
        $html .= '<td class="signature-box"><strong>' . htmlspecialchars($locker['band_name']) . '</strong><br><br>Naam: ' . htmlspecialchars($bandContactName) . '<br>E-mail: ' . htmlspecialchars($bandContactEmail) . '<br><br>Handtekening: ____________________<br><br>Datum: ____________________</td>';
        $html .= '</tr></table>';
        $html .= '<p style="margin-top:50px;font-size:10px;text-align:center">Dit contract is digitaal gegenereerd en dient te worden uitgeprint en ondertekend.</p>';
        $html .= '</body></html>';
        
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="contract_kast_' . $locker['locker_no'] . '_' . date('Ymd') . '.pdf"');
        echo $dompdf->output();
        exit;
        
    } catch (Throwable $e) {
        $error = 'Fout: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8"><title>Sleutelcontract Genereren</title>
<style>
body{font-family:Arial;background:#1a472a;color:#fff;margin:0;padding:20px}
.container{max-width:800px;margin:0 auto;background:rgba(255,255,255,.1);padding:20px;border-radius:10px}
.form-group{margin-bottom:15px}
label{display:block;margin-bottom:5px;font-weight:bold}
select,input{width:100%;padding:8px;border-radius:5px;border:1px solid #ccc}
button{background:#2c7da0;color:#fff;padding:10px 20px;border:none;border-radius:5px;cursor:pointer}
.error{color:#ffb3b3;padding:10px;background:rgba(255,0,0,.2);border-radius:5px;margin-bottom:15px}
.success{color:#a3ffb3;padding:10px;background:rgba(0,255,0,.1);border-radius:5px;margin-bottom:15px}
</style>
</head>
<body>
<div class="container">
    <h1>Sleutelcontract Genereren</h1>
    <?php if($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <?php if($message): ?><div class="success"><?= h($message) ?></div><?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        
        <div class="form-group">
            <label>Kast:</label>
            <select name="locker_id" required>
                <option value="">Kies kast...</option>
                <?php foreach($lockers as $l): ?>
                <option value="<?= $l['id'] ?>">Kast <?= h($l['locker_no']) ?> - <?= h($l['band_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>Bandvertegenwoordiger (naam):</label>
            <input type="text" name="band_contact_name" required>
        </div>
        
        <div class="form-group">
            <label>E-mail bandvertegenwoordiger:</label>
            <input type="email" name="band_contact_email">
        </div>
        
        <div class="form-group">
            <label>Bestuurslid (Stichting):</label>
            <select name="board_member_id" required>
                <option value="">Kies bestuurslid...</option>
                <?php foreach($boardMembers as $m): ?>
                <option value="<?= $m['id'] ?>"><?= h($m['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>Locatie:</label>
            <input type="text" name="location" value="POR-kast Zevenaar" required>
        </div>
        
        <div class="form-group">
            <label>Ingangsdatum:</label>
            <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        
        <div class="form-group">
            <label>Einddatum:</label>
            <input type="date" name="end_date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
        </div>
        
        <button type="submit">📄 Genereer PDF Contract</button>
    </form>
</div>
</body>
</html>