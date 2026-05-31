<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

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
    <style>
        /* zelfde stijl als keys.php */
        :root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--shadow:0 14px 40px rgba(0,0,0,.45);}
        body{margin:0;font-family:Arial,sans-serif;color:var(--text);background:url('<?= h($bg) ?>') no-repeat center center fixed;background-size:cover;}
        .backdrop{min-height:100vh;background:radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
        .wrap{width:min(800px,96vw);}
        .panel{background:var(--glass);border-radius:20px;padding:20px;backdrop-filter:blur(12px);}
        a{color:#fff;}
    </style>
</head>
<body>
<div class="backdrop"><div class="wrap">
    <div class="panel">
        <h2>Sleutel: <?= h($key['key_code']) ?></h2>
        <p><strong>Omschrijving:</strong> <?= h($key['description'] ?? '—') ?></p>
        <p><strong>Type:</strong> <?= h($key['key_type']) ?></p>
        <p><strong>Kast:</strong> <?= h($key['locker_no'] ?? '—') ?></p>
        <p><strong>Band:</strong> <?= h($key['band_name'] ?? '—') ?></p>
        <p><strong>Status:</strong> <?= $key['active'] ? 'Actief' : 'Inactief' ?></p>
        <p><a href="/admin/keys_edit.php?id=<?= $id ?>">Bewerken</a> | <a href="/admin/keys.php">Terug naar overzicht</a></p>
    </div>
</div></div>
</body>
</html>