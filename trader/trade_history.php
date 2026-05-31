<?php
declare(strict_types=1);

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/functions.php';
require __DIR__ . '/includes/layout.php';

$appName = config()['app']['name'] ?? 'Trading Dashboard';

// Haal alle trades op, inclusief fees, alleen van gewenste symbolen
$allTrades = fetchAllRows($pdo, "
    SELECT 
        t.id,
        t.asset AS symbol,
        COALESCE(track.display_name, uni.display_name, t.asset) AS display_name,
        t.type,
        t.price,
        t.amount,
        t.timestamp,
        t.profit_loss,
        t.fees,
        t.notes,
        t.position_id,
        bs.asset_type,
        bs.order_symbol
    FROM trades t
    LEFT JOIN bot_symbols bs ON bs.symbol = t.asset
    LEFT JOIN tracked_symbols track ON track.symbol = t.asset
    LEFT JOIN asset_universe uni ON uni.symbol = t.asset
    WHERE (bs.is_active = 1 OR bs.id IS NULL)
      AND t.asset NOT LIKE '%=F'          -- futures uitsluiten
      AND t.asset NOT LIKE '%.SS'         -- Chinese aandelen (Shanghai)
      AND t.asset NOT LIKE '%.SZ'         -- Chinese aandelen (Shenzhen)
    ORDER BY t.timestamp DESC
");

// Haal openstaande posities op met actuele koers, gefilterd
$openPositions = fetchAllRows($pdo, "
    SELECT 
        p.id,
        p.symbol,
        COALESCE(track.display_name, uni.display_name, p.symbol) AS display_name,
        p.quantity,
        p.avg_price,
        p.opened_at,
        bs.asset_type,
        bs.order_symbol,
        s.price AS last_price,
        s.trend_state,
        s.change_24h
    FROM positions p
    LEFT JOIN bot_symbols bs ON bs.symbol = p.symbol
    LEFT JOIN tracked_symbols track ON track.symbol = p.symbol
    LEFT JOIN asset_universe uni ON uni.symbol = p.symbol
    LEFT JOIN asset_snapshots s ON s.id = (
        SELECT s2.id FROM asset_snapshots s2 
        WHERE s2.symbol = p.symbol 
        ORDER BY s2.snapshot_time DESC, s2.id DESC LIMIT 1
    )
    WHERE p.status = 'OPEN'
      AND p.symbol NOT LIKE '%=F'
      AND p.symbol NOT LIKE '%.SS'
      AND p.symbol NOT LIKE '%.SZ'
    ORDER BY p.opened_at DESC
");

$duplicates = fetchAllRows($pdo, "
    SELECT symbol, COUNT(*) AS cnt 
    FROM bot_symbols 
    WHERE is_active = 1 
    GROUP BY symbol 
    HAVING cnt > 1
");
if ($duplicates) {
    echo '<div class="card span-12" style="background:#fff3cd;"><p><strong>⚠️ Waarschuwing:</strong> Er zijn meerdere actieve symbolen voor dezelfde asset. Dit veroorzaakt dubbele trades. Controleer de tabel bot_symbols.</p></div>';
}

// Bereken voor elke open positie de ongerealiseerde winst/verlies
foreach ($openPositions as &$pos) {
    $currentPrice = (float)($pos['last_price'] ?? 0);
    $avgPrice = (float)$pos['avg_price'];
    $quantity = (float)$pos['quantity'];
    if ($currentPrice > 0 && $avgPrice > 0) {
        $pos['current_value'] = $quantity * $currentPrice;
        $pos['unrealized_pnl'] = ($currentPrice - $avgPrice) * $quantity;
        $pos['unrealized_pnl_pct'] = (($currentPrice - $avgPrice) / $avgPrice) * 100;
    } else {
        $pos['current_value'] = null;
        $pos['unrealized_pnl'] = null;
        $pos['unrealized_pnl_pct'] = null;
    }
}
unset($pos);

renderPageStart($appName, 'Volledig overzicht van alle trades en openstaande posities.', [
    ['href' => appUrl('/dashboard.php'), 'label' => '← Terug naar dashboard', 'secondary' => true],
]);
?>

<div class="grid">
    <!-- Openstaande posities -->
    <div class="card span-12">
        <h2>📈 Openstaande posities (ongerealiseerd)</h2>
        <?php if (empty($openPositions)): ?>
            <p class="muted">Geen openstaande posities.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Symbool</th>
                            <th>Naam</th>
                            <th>Type</th>
                            <th>Hoeveelheid</th>
                            <th>Gem. prijs</th>
                            <th>Laatste prijs</th>
                            <th>Huidige waarde</th>
                            <th>W/V (€)</th>
                            <th>W/V (%)</th>
                            <th>Trend</th>
                            <th>Geopend</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openPositions as $pos): ?>
                            <tr>
                                <td><strong><?= h($pos['symbol']) ?></strong></td>
                                <td><?= h($pos['display_name'] ?? '-') ?></td>
                                <td>
                                    <?php if ($pos['asset_type'] === 'STOCK'): ?>
                                        <span class="badge badge-neutral">Aandeel</span>
                                    <?php elseif ($pos['asset_type'] === 'CRYPTO'): ?>
                                        <span class="badge badge-neutral">Crypto</span>
                                    <?php else: ?>
                                        <span class="badge">Onbekend</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatQty($pos['quantity'], 8) ?></td>
                                <td><?= formatPrice($pos['avg_price'], 4) ?></td>
                                <td><?= formatPrice($pos['last_price'] ?? null, 4) ?: '<span class="muted">n/b</span>' ?></td>
                                <td><?= $pos['current_value'] !== null ? formatEuro($pos['current_value']) : '-' ?></td>
                                <td class="<?= ($pos['unrealized_pnl'] ?? 0) >= 0 ? 'profit' : 'loss' ?>">
                                    <?= $pos['unrealized_pnl'] !== null ? formatEuro($pos['unrealized_pnl']) : '-' ?>
                                </td>
                                <td class="<?= ($pos['unrealized_pnl_pct'] ?? 0) >= 0 ? 'profit' : 'loss' ?>">
                                    <?= $pos['unrealized_pnl_pct'] !== null ? number_format($pos['unrealized_pnl_pct'], 2) . '%' : '-' ?>
                                </td>
                                <td><span class="<?= badgeClassForTrend($pos['trend_state'] ?? null) ?>"><?= h($pos['trend_state'] ?? 'ONBEKEND') ?></span></td>
                                <td><?= formatDateTime($pos['opened_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Trade geschiedenis (alle BUY en SELL) met kosten -->
    <div class="card span-12">
        <h2>📜 Trade geschiedenis (gerealiseerd)</h2>
        <?php if (empty($allTrades)): ?>
            <p class="muted">Nog geen trades uitgevoerd.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Symbool</th>
                            <th>Naam</th>
                            <th>Type</th>
                            <th>Prijs</th>
                            <th>Hoeveelheid</th>
                            <th>Winst/Verlies</th>
                            <th>Kosten</th>
                            <th>Opmerkingen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allTrades as $trade): ?>
                            <tr>
                                <td><?= formatDateTime($trade['timestamp']) ?></td>
                                <td><strong><?= h($trade['symbol']) ?></strong></td>
                                <td><?= h($trade['display_name'] ?? '-') ?></td>
                                <td>
                                    <?php if ($trade['type'] === 'BUY'): ?>
                                        <span class="badge badge-good">Koop</span>
                                    <?php else: ?>
                                        <span class="badge badge-bad">Verkoop</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatPrice($trade['price'], 4) ?></td>
                                <td><?= formatQty($trade['amount'], 8) ?></td>
                                <td class="<?= ($trade['profit_loss'] ?? 0) >= 0 ? 'profit' : 'loss' ?>">
                                    <?= $trade['profit_loss'] !== null ? formatEuro($trade['profit_loss']) : '-' ?>
                                </td>
                                <td>
                                    <?php if ($trade['fees'] > 0): ?>
                                        <?= formatEuro($trade['fees']) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?= h($trade['notes'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php renderPageEnd(); ?>