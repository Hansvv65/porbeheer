<?php
declare(strict_types=1);

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/functions.php';
require __DIR__ . '/includes/layout.php';

$symbol = strtoupper(getString('symbol'));
if ($symbol === '' || !isValidSymbolFormat($symbol)) {
    http_response_code(400);
    exit('Ongeldig of ontbrekend symbool.');
}

$hours = min(720, max(24, getInt('hours', 168)));
$asset = fetchOne($pdo, 'SELECT symbol, display_name, asset_type, exchange_name, currency FROM asset_universe WHERE symbol = ? LIMIT 1', [$symbol])
    ?: fetchOne($pdo, 'SELECT symbol, display_name, asset_type, exchange_name, NULL AS currency FROM tracked_symbols WHERE symbol = ? LIMIT 1', [$symbol])
    ?: ['symbol' => $symbol, 'display_name' => null, 'asset_type' => null, 'exchange_name' => null, 'currency' => null];

$history = fetchAllRows($pdo, '
    SELECT snapshot_time, price, trend_state, trend_score, change_24h, breakout
    FROM asset_snapshots
    WHERE symbol = ? AND snapshot_time >= (NOW() - INTERVAL ' . (int)$hours . ' HOUR)
    ORDER BY snapshot_time ASC, id ASC
', [$symbol]);

$stats = fetchOne($pdo, '
    SELECT COUNT(*) AS points, MIN(price) AS min_price, MAX(price) AS max_price, AVG(price) AS avg_price
    FROM asset_snapshots
    WHERE symbol = ? AND snapshot_time >= (NOW() - INTERVAL ' . (int)$hours . ' HOUR)
', [$symbol]) ?: [];

$chartPoints = [];
$min = null; $max = null;
foreach ($history as $row) {
    $price = (float)$row['price'];
    $min = $min === null ? $price : min($min, $price);
    $max = $max === null ? $price : max($max, $price);
}
$range = ($max !== null && $min !== null) ? max(0.0000001, $max - $min) : 1.0;
$width = 960; $height = 220;
$count = count($history);
foreach ($history as $index => $row) {
    $x = $count <= 1 ? 0 : ($index / ($count - 1)) * $width;
    $y = $height - ((((float)$row['price']) - (float)$min) / $range) * $height;
    $chartPoints[] = round($x, 2) . ',' . round($y, 2);
}

renderPageStart('Koersverloop · ' . $symbol, 'Historisch overzicht uit asset_snapshots. Eerst met grafieklijn en daarnaast de ruwe meetpunten.', [
    ['href' => appUrl('/asset_analysis.php?symbol=' . urlencode($symbol)), 'label' => 'Analyse'],
    ['href' => appUrl('/dashboard.php'), 'label' => 'Dashboard', 'secondary' => true],
]);
?>
<div class="grid">
    <div class="card span-12">
        <div class="section-tools">
            <h2><?= h($symbol) ?><?= !empty($asset['display_name']) ? ' · ' . h((string)$asset['display_name']) : '' ?></h2>
            <form method="get" class="actions">
                <input type="hidden" name="symbol" value="<?= h($symbol) ?>">
                <select name="hours">
                    <?php foreach ([24, 72, 168, 336, 720] as $option): ?>
                        <option value="<?= $option ?>" <?= $hours === $option ? 'selected' : '' ?>>Laatste <?= $option ?> uur</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Periode wijzigen</button>
            </form>
        </div>
        <div class="stats stats-4">
            <div class="stat"><div class="label">Meetpunten</div><div class="value"><?= (int)($stats['points'] ?? 0) ?></div></div>
            <div class="stat"><div class="label">Laagste prijs</div><div class="value small"><?= formatPrice($stats['min_price'] ?? null, 4) ?></div></div>
            <div class="stat"><div class="label">Hoogste prijs</div><div class="value small"><?= formatPrice($stats['max_price'] ?? null, 4) ?></div></div>
            <div class="stat"><div class="label">Gemiddelde prijs</div><div class="value small"><?= formatPrice($stats['avg_price'] ?? null, 4) ?></div></div>
        </div>
    </div>

    <div class="card span-12">
        <h2>Grafiek</h2>
        <?php if (count($chartPoints) < 2): ?>
            <div class="muted">Te weinig meetpunten voor een grafiek.</div>
        <?php else: ?>
            <svg viewBox="0 0 960 260" width="100%" height="260" aria-label="Koersverloop grafiek">
                <rect x="0" y="0" width="960" height="260" fill="#f8fafc" rx="16"></rect>
                <line x1="0" y1="220" x2="960" y2="220" stroke="#cbd5e1" stroke-width="1"></line>
                <polyline fill="none" stroke="#0284c7" stroke-width="3" points="<?= h(implode(' ', $chartPoints)) ?>"></polyline>
            </svg>
        <?php endif; ?>
        <div class="panel-note">De lijn is gebaseerd op de records uit <code>asset_snapshots</code> voor de gekozen periode.</div>
    </div>

    <div class="card span-12">
        <h2>Meetpunten</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tijd</th><th>Prijs</th><th>Trend</th><th>Score</th><th>24u</th><th>Breakout</th></tr></thead>
                <tbody>
                <?php if (!$history): ?>
                    <tr><td colspan="6" class="muted">Geen data voor deze periode.</td></tr>
                <?php else: foreach (array_reverse($history) as $row): ?>
                    <tr>
                        <td><?= formatDateTime($row['snapshot_time'] ?? null) ?></td>
                        <td><?= formatPrice($row['price'] ?? null, 4) ?></td>
                        <td><span class="<?= badgeClassForTrend($row['trend_state'] ?? null) ?>"><?= h((string)($row['trend_state'] ?? 'ONBEKEND')) ?></span></td>
                        <td><?= h((string)($row['trend_score'] ?? '-')) ?></td>
                        <td><?= formatPct($row['change_24h'] ?? null, 2) ?></td>
                        <td><?= !empty($row['breakout']) ? 'JA' : 'NEE' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
