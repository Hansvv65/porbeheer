<?php
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/functions.php';
require_once __DIR__ . '/includes/layout.php';

$symbol = strtoupper(trim((string)($_GET['symbol'] ?? $_POST['symbol'] ?? '')));
$displayName = trim((string)($_GET['name'] ?? $_POST['display_name'] ?? ''));
$assetType = trim((string)($_GET['type'] ?? $_POST['asset_type'] ?? ''));
$exchangeName = trim((string)($_GET['exchange'] ?? $_POST['exchange_name'] ?? ''));
$sourceProvider = trim((string)($_GET['provider'] ?? $_POST['source_provider'] ?? 'manual'));
$notes = trim((string)($_POST['notes'] ?? ''));

if (isPost()) {
    requireCsrf();

    if ($symbol === '') {
        flash('error', 'Symbool is verplicht.');
    } elseif (!isValidSymbolFormat($symbol)) {
        flash('error', 'Ongeldig symbool formaat.');
    } elseif (fetchOne($pdo, 'SELECT id FROM tracked_symbols WHERE symbol = ? LIMIT 1', [$symbol])) {
        flash('error', 'Dit symbool bestaat al in je selectie.');
    } else {
        executeSql($pdo, 'INSERT INTO tracked_symbols (symbol, display_name, asset_type, exchange_name, source_provider, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())', [
            $symbol,
            $displayName !== '' ? $displayName : null,
            $assetType !== '' ? $assetType : null,
            $exchangeName !== '' ? $exchangeName : null,
            $sourceProvider !== '' ? $sourceProvider : 'manual',
            $notes !== '' ? $notes : null,
        ]);
        redirectBackWithFlash('success', 'Asset handmatig toegevoegd aan je selectie.', appUrl('/symbols.php'));
    }
}

renderPageStart('Handmatig asset toevoegen', 'Gebruik dit formulier alleen als je het instrument niet direct in de zoekpagina vindt.', [
    ['href' => appUrl('/dashboard.php'), 'label' => 'Dashboard'],
    ['href' => appUrl('/asset_lookup.php'), 'label' => 'Asset zoeken', 'secondary' => true],
    ['href' => appUrl('/symbols.php'), 'label' => 'Mijn assets', 'secondary' => true],
]);
?>
<div class="card span-12">
    <h2>Nieuw assetrecord</h2>
    <form method="post">
        <?= csrfField() ?>
        <div class="row">
            <div>
                <label for="symbol">Symbool</label>
                <input type="text" id="symbol" name="symbol" value="<?= h($symbol) ?>" placeholder="Bijv. ASML.AS, BTC-USD of GC=F">
            </div>
            <div>
                <label for="display_name">Naam</label>
                <input type="text" id="display_name" name="display_name" value="<?= h($displayName) ?>" placeholder="Bijv. ASML Holding NV">
            </div>
        </div>
        <div class="row-3">
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
                <input type="text" id="source_provider" name="source_provider" value="<?= h($sourceProvider) ?>" placeholder="Bijv. manual of finnhub">
            </div>
        </div>
        <div>
            <label for="notes">Notities</label>
            <textarea id="notes" name="notes" placeholder="Optionele notitie"><?= h($notes) ?></textarea>
        </div>
        <div class="actions" style="margin-top:16px;">
            <button type="submit" class="btn-green">Opslaan</button>
            <a class="btn-link btn-link-secondary" href="<?= h(appUrl('/asset_lookup.php')) ?>">Terug naar zoeken</a>
        </div>
    </form>
</div>
<?php renderPageEnd(); ?>
