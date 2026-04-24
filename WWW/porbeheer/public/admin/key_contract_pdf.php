<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
requireRole(['ADMIN','BEHEER']);

$keyId = (int)($_GET['key_id'] ?? 0);
if ($keyId <= 0) die('Ongeldige sleutel');

$stmt = $pdo->prepare("SELECT signed_contract_path FROM key_contracts WHERE key_id = ?");
$stmt->execute([$keyId]);
$contract = $stmt->fetch();
if (!$contract || empty($contract['signed_contract_path'])) {
    die('Geen getekend contract gevonden.');
}

$filePath = __DIR__ . '/../../../' . ltrim($contract['signed_contract_path'], '/');
if (!file_exists($filePath)) die('Bestand niet gevonden.');

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="contract_' . $keyId . '.pdf"');
readfile($filePath);
exit;