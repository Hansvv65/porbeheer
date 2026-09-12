<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireSectionAccess('sleutels', 'read');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/keys.php');
    exit;
}

// Haal sleutelgegevens op
$stmt = $pdo->prepare("
    SELECT k.*, l.locker_no, b.name AS band_name
    FROM `keys` k
    LEFT JOIN lockers l ON l.id = k.locker_id AND l.deleted_at IS NULL
    LEFT JOIN bands b ON b.id = l.band_id AND b.deleted_at IS NULL
    WHERE k.id = ? AND k.deleted_at IS NULL
");
$stmt->execute([$id]);
$key = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$key) {
    header('Location: /admin/keys.php?msg=notfound');
    exit;
}

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('keys', $pdo);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Sleutel bekijken</title>
    <link rel="stylesheet" href="/assets/css/main.css">
<style>
.wrap{width:min(800px,96vw);}
.panel{background:var(--glass);border-radius:20px;padding:20px;backdrop-filter:blur(12px);}
a{color:#fff;}
</style>
</head>
<body style="background-image:url('<?= h($bg) ?>')">
<div class="backdrop"><div class="wrap">
    <div class="panel">
        <h2>Sleutel: <?= h($key['key_code']) ?></h2>
        <p><strong>Omschrijving:</strong> <?= h($key['description'] ?? '—') ?></p>
        <p><strong>Type:</strong> <?= h($key['key_type']) ?></p>
        <p><strong>Kast:</strong> <?= h($key['locker_no'] ?? '—') ?></p>
        <p><strong>Band:</strong> <?= h($key['band_name'] ?? '—') ?></p>
        <p><strong>Status:</strong> <?= $key['active'] ? 'Actief' : 'Inactief' ?></p>
        <p><?php if (hasSectionAccess($pdo, 'sleutels', 'write')): ?><a href="/admin/keys_edit.php?id=<?= $id ?>">Bewerken</a> | <?php endif; ?><a href="/admin/keys.php">Terug naar overzicht</a></p>
    </div>
</div></div>
</body>
</html>