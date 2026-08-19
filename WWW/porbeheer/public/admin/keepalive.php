<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';

// Alleen toegankelijk voor ingelogde gebruikers (zelfde check als de rest van de app)
if (!isLoggedIn()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Niet ingelogd']);
    exit;
}

// Verleng de sessie door dezelfde activity-sleutel bij te werken die startSecureSession()
// in auth.php ook daadwerkelijk controleert. Let op: hier stond eerder 'last_activity'
// (zonder underscore) -- een andere sleutel dan '_last_activity' die de echte
// idle-timeout-check gebruikt. Daardoor deed deze ping feitelijk niets: hij meldde
// succes, maar verlengde de sessie niet, en de timeout in auth.php kon alsnog toeslaan.
$_SESSION['_last_activity'] = time();

// Geef nieuwe expire-tijd terug, met dezelfde config-lookup (en fallback) als de echte check.
$expires = time() + sessionIdleTimeoutSeconds();

header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'expires' => $expires]);