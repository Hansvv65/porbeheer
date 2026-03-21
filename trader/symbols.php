<?php
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/includes/asset_catalog.php';

$stmt = $pdo->query("
    SELECT id, symbol, display_name, asset_type, exchange_name, source_provider, notes, created_at
    FROM tracked_symbols
    ORDER BY symbol ASC
");
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mijn symbolen</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(180deg,#0b1220,#0f172a);color:#e5e7eb}
.wrap{max-width:1180px;margin:0 auto;padding:28px 18px 40px}
.top{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:18px}
h1{margin:0 0 6px}
.sub{color:#94a3b8}
.actions a{display:inline-block;text-decoration:none;color:#fff;background:#2563eb;padding:11px 14px;border-radius:10px;font-weight:bold;margin-left:8px}
.card{background:#111827;border:1px solid #263246;border-radius:18px;overflow:auto}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{padding:13px 10px;text-align:left;border-bottom:1px solid #263246;vertical-align:top}
th{background:#172033}
.muted{color:#94a3b8;font-size:13px}
.delete-btn{display:inline-block;background:#dc2626;color:#fff;text-decoration:none;padding:8px 12px;border-radius:10px;font-weight:bold}
.empty{padding:30px;color:#94a3b8}
</style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Mijn symbolen</h1>
            <div class="sub">Overzicht van opgeslagen tickers voor Trading PY.</div>
        </div>
        <div class="actions">
            <a href="/asset_lookup.php">Asset lookup</a>
            <a href="/symbol_add.php">Handmatig toevoegen</a>
        </div>
    </div>

    <div class="card">
        <?php if (!$rows): ?>
            <div class="empty">Nog geen symbolen toegevoegd.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Symbool</th>
                    <th>Naam</th>
                    <th>Type</th>
                    <th>Beurs</th>
                    <th>Bron</th>
                    <th>Notities</th>
                    <th>Aangemaakt</th>
                    <th>Actie</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= h($row['symbol']) ?></strong></td>
                        <td><?= h($row['display_name'] ?? '') ?></td>
                        <td><?= h($row['asset_type'] ?? '') ?></td>
                        <td><?= h($row['exchange_name'] ?? '') ?></td>
                        <td><?= h($row['source_provider'] ?? '') ?></td>
                        <td><?= h($row['notes'] ?? '') ?></td>
                        <td><span class="muted"><?= h($row['created_at']) ?></span></td>
                        <td>
                            <a class="delete-btn" href="/symbol_delete.php?id=<?= (int)$row['id'] ?>" onclick="return confirm('Symbool verwijderen?');">
                                Verwijderen
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>