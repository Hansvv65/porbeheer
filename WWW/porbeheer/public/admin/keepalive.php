<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';

// Alleen toegankelijk voor ingelogde gebruikers
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Niet ingelogd']);
    exit;
}

// Verleng de sessie door de last-activity tijd bij te werken
$_SESSION['last_activity'] = time();

// Geef nieuwe expire-tijd terug (optioneel)
$expires = time() + (int)($config['security']['session_idle_timeout'] ?? 300);

header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'expires' => $expires]);