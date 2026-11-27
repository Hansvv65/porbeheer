<?php
declare(strict_types=1);

/*
 * admin/locker_edit.php  (vervangen door band_keys.php)
 *
 * Deze pagina is samengevoegd in band_keys.php. Dit bestand stuurt oude
 * links netjes door:
 *   - kast met band  -> de band-pagina (daar beheer je kast/sleutels/contract)
 *   - vrije kast      -> bandenoverzicht (wijs de kast vanaf een band toe)
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
requireSectionAccess('bands', 'read');

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) { header('Location: /admin/keys.php'); exit; }

$st = $pdo->prepare("SELECT band_id FROM lockers WHERE id = ? AND deleted_at IS NULL");
$st->execute([$id]);
$bandId = $st->fetchColumn();

if ($bandId !== false && $bandId !== null && (int)$bandId > 0) {
    header('Location: /admin/band_keys.php?band_id=' . (int)$bandId);
    exit;
}

// Vrije kast: laat de gebruiker een band kiezen en daar toewijzen
header('Location: /admin/bands.php');
exit;
