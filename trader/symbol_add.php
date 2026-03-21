<?php
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/includes/asset_catalog.php';

$error = '';
$success = '';

$symbol = strtoupper(trim($_GET['symbol'] ?? $_POST['symbol'] ?? ''));
$displayName = trim($_GET['name'] ?? $_POST['display_name'] ?? '');
$assetType = trim($_GET['type'] ?? $_POST['asset_type'] ?? '');
$exchangeName = trim($_GET['exchange'] ?? $_POST['exchange_name'] ?? '');
$sourceProvider = trim($_GET['provider'] ?? $_POST['source_provider'] ?? 'manual');
$notes = trim($_POST['notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $symbol = strtoupper(trim($_POST['symbol'] ?? ''));
    $displayName = trim($_POST['display_name'] ?? '');
    $assetType = trim($_POST['asset_type'] ?? '');
    $exchangeName = trim($_POST['exchange_name'] ?? '');
    $sourceProvider = trim($_POST['source_provider'] ?? 'manual');
    $notes = trim($_POST['notes'] ?? '');

    if ($symbol === '') {
        $error = 'Symbool is verplicht.';
    } elseif (!isValidSymbolFormat($symbol)) {
        $error = 'Ongeldig symbool formaat.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tracked_symbols WHERE symbol = ?");
        $stmt->execute([$symbol]);

        if ((int)$stmt->fetchColumn() > 0) {
            $error = 'Symbool bestaat al.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tracked_symbols
                (symbol, display_name, asset_type, exchange_name, source_provider, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $symbol,
                $displayName !== '' ? $displayName : null,
                $assetType !== '' ? $assetType : null,
                $exchangeName !== '' ? $exchangeName : null,
                $sourceProvider !== '' ? $sourceProvider : 'manual',
                $notes !== '' ? $notes : null,
            ]);

            $success = 'Symbool toegevoegd aan dashboard-selectie.';
            $symbol = '';
            $displayName = '';
            $assetType = '';
            $exchangeName = '';
            $sourceProvider = 'manual';
            $notes = '';
        }
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Symbool toevoegen</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
body{background:linear-gradient(180deg,#0b1220,#0f172a);color:#e5e7eb;font-family:Arial,sans-serif}
.wrap{max-width:760px;margin:0 auto;padding:30px 18px}
.card{background:#111827;border:1px solid #263246;border-radius:18px;padding:22px}
h1{margin-top:0}
.sub{color:#94a3b8;margin-bottom:18px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field-full{grid-column:1 / -1}
label{display:block;margin-bottom:6px;color:#cbd5e1;font-size:14px}
input, textarea{width:100%;padding:12px 13px;border-radius:10px;border:1px solid #334155;background:#0b1220;color:#fff;box-sizing:border-box}
textarea{min-height:90px;resize:vertical}
.actions{display:flex;gap:12px;margin-top:18px;flex-wrap:wrap}
button, .btn{display:inline-block;padding:12px 16px;border-radius:10px;border:0;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;cursor:pointer}
.btn-secondary{background:#1e293b}
.msg{margin:0 0 16px;padding:12px 14px;border-radius:12px}
.error{background:#3b0d12;color:#fecaca;border:1px solid #7f1d1d}
.success{background:#0f2c1e;color:#bbf7d0;border:1px solid #166534}
@media (max-width:720px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Symbool toevoegen</h1>
        <div class="sub">Voeg een ticker toe aan jouw Trading PY watchlist.</div>

        <?php if ($error !== ''): ?>
            <div class="msg error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="msg success"><?= h($success) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="grid">
                <div>
                    <label for="symbol">Symbool</label>
                    <input type="text" id="symbol" name="symbol" value="<?= h($symbol) ?>" placeholder="Bijv. ASML.AS, BTC-USD, GC=F">
                </div>

                <div>
                    <label for="display_name">Naam</label>
                    <input type="text" id="display_name" name="display_name" value="<?= h($displayName) ?>" placeholder="Bijv. ASML Holding NV">
                </div>

                <div>
                    <label for="asset_type">Type</label>
                    <input type="text" id="asset_type" name="asset_type" value="<?= h($assetType) ?>" placeholder="Bijv. stock, crypto, commodity">
                </div>

                <div>
                    <label for="exchange_name">Beurs</label>
                    <input type="text" id="exchange_name" name="exchange_name" value="<?= h($exchangeName) ?>" placeholder="Bijv. Euronext Amsterdam">
                </div>

                <div>
                    <label for="source_provider">Bron</label>
                    <input type="text" id="source_provider" name="source_provider" value="<?= h($sourceProvider) ?>" placeholder="manual, local, finnhub">
                </div>

                <div class="field-full">
                    <label for="notes">Notities</label>
                    <textarea id="notes" name="notes" placeholder="Optionele notitie"><?= h($notes) ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button type="submit">Opslaan</button>
                <a class="btn btn-secondary" href="/asset_lookup.php">Zoek symbool</a>
                <a class="btn btn-secondary" href="/symbols.php">Mijn symbolen</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>