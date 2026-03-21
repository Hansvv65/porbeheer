<?php
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';

$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $pdo->prepare("DELETE FROM tracked_symbols WHERE id = ?");
    $stmt->execute([$id]);
}

header('Location: /symbols.php');
exit;