<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
requireSectionAccess('sleutels', 'read');

$keyId = (int)($_GET['key_id'] ?? 0);
if ($keyId <= 0) die('Ongeldige sleutel');

$stmt = $pdo->prepare("
    SELECT kc.signed_contract_path, l.band_id
    FROM key_contracts kc
    JOIN `keys` k ON k.id = kc.key_id
    LEFT JOIN lockers l ON l.id = k.locker_id
    WHERE kc.key_id = ?
");
$stmt->execute([$keyId]);
$contract = $stmt->fetch();
if (!$contract || empty($contract['signed_contract_path'])) {
    die('Geen getekend contract gevonden.');
}

// Alleen-eigen (BANDLID): zelfde beperking als elders -- anders is dit een
// tweede, ongecontroleerde weg naar andermans getekende contract.
if (hasOwnOnlySectionAccess($pdo, 'sleutels')) {
    $myContactId = currentContactId();
    $ownsThisBand = false;
    if ($myContactId !== null && !empty($contract['band_id'])) {
        $chk = $pdo->prepare("SELECT id FROM bands WHERE id = ? AND (primary_contact_id = ? OR secondary_contact_id = ?) AND deleted_at IS NULL");
        $chk->execute([(int)$contract['band_id'], $myContactId, $myContactId]);
        $ownsThisBand = (bool)$chk->fetchColumn();
    }
    if (!$ownsThisBand) {
        renderNoAccessPage('sleutels');
    }
}

// Padvalidatie: het opgeslagen pad mag niet buiten de verwachte projectmap
// uitkomen (extra vangnet, ook al komt de waarde uit de database en niet
// rechtstreeks van de gebruiker).
$baseDir  = realpath(__DIR__ . '/../../../');
$filePath = realpath($baseDir . '/' . ltrim((string)$contract['signed_contract_path'], '/'));
if ($baseDir === false || $filePath === false || !str_starts_with($filePath, $baseDir . DIRECTORY_SEPARATOR)) {
    die('Bestand niet gevonden.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="contract_' . $keyId . '.pdf"');
readfile($filePath);
exit;