<?php
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/functions.php';
require_once __DIR__ . '/includes/layout.php';

if (isPost() && postString('action') === 'delete_symbol') {
    requireCsrf();
    $symbol = strtoupper(postString('symbol'));
    try {
        $pdo->beginTransaction();
        executeSql($pdo, 'DELETE FROM tracked_symbols WHERE symbol = ?', [$symbol]);
        executeSql($pdo, 'DELETE FROM bot_symbols WHERE symbol = ?', [$symbol]);
        $pdo->commit();
        redirectBackWithFlash('success', 'Asset verwijderd uit watchlist en bot.', appUrl('/symbols.php'));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('symbols delete failed: ' . $e->getMessage());
        redirectBackWithFlash('error', 'Verwijderen is mislukt.', appUrl('/symbols.php'));
    }
}

$rows = fetchAllRows($pdo, "
    SELECT COALESCE(t.symbol, b.symbol) AS symbol,
           t.display_name,
           COALESCE(t.asset_type, b.asset_type) AS asset_type,
           t.exchange_name,
           t.source_provider,
           b.is_active,
           b.priority_order,
           b.data_symbol,
           b.order_symbol,
           snap.price AS last_price,
           snap.trend_state,
           snap.trend_score,
           sig.signal_reason,
           pos.quantity
    FROM tracked_symbols t
    LEFT JOIN bot_symbols b ON b.symbol = t.symbol
    LEFT JOIN asset_snapshots snap ON snap.id = (
        SELECT s.id FROM asset_snapshots s WHERE s.symbol = COALESCE(t.symbol, b.symbol) ORDER BY s.snapshot_time DESC, s.id DESC LIMIT 1
    )
    LEFT JOIN asset_signal_log sig ON sig.id = (
        SELECT l.id FROM asset_signal_log l WHERE l.symbol = COALESCE(t.symbol, b.symbol) ORDER BY l.signal_time DESC, l.id DESC LIMIT 1
    )
    LEFT JOIN positions pos ON pos.id = (
        SELECT p.id FROM positions p WHERE p.symbol = COALESCE(t.symbol, b.symbol) AND p.status = 'OPEN' ORDER BY p.id DESC LIMIT 1
    )
    UNION
    SELECT COALESCE(t.symbol, b.symbol) AS symbol,
           t.display_name,
           COALESCE(t.asset_type, b.asset_type) AS asset_type,
           t.exchange_name,
           t.source_provider,
           b.is_active,
           b.priority_order,
           b.data_symbol,
           b.order_symbol,
           snap.price AS last_price,
           snap.trend_state,
           snap.trend_score,
           sig.signal_reason,
           pos.quantity
    FROM bot_symbols b
    LEFT JOIN tracked_symbols t ON t.symbol = b.symbol
    LEFT JOIN asset_snapshots snap ON snap.id = (
        SELECT s.id FROM asset_snapshots s WHERE s.symbol = COALESCE(t.symbol, b.symbol) ORDER BY s.snapshot_time DESC, s.id DESC LIMIT 1
    )
    LEFT JOIN asset_signal_log sig ON sig.id = (
        SELECT l.id FROM asset_signal_log l WHERE l.symbol = COALESCE(t.symbol, b.symbol) ORDER BY l.signal_time DESC, l.id DESC LIMIT 1
    )
    LEFT JOIN positions pos ON pos.id = (
        SELECT p.id FROM positions p WHERE p.symbol = COALESCE(t.symbol, b.symbol) AND p.status = 'OPEN' ORDER BY p.id DESC LIMIT 1
    )
    ORDER BY symbol ASC
");

renderPageStart('Mijn assets', 'Eén overzicht van watchlist, bot-activering, laatste trend en reden.', [
    ['href' => appUrl('/asset_lookup.php'), 'label' => 'Asset zoeken'],
    ['href' => appUrl('/dashboard.php'), 'label' => 'Dashboard', 'secondary' => true],
]);
?>
<div class="card span-12">
    <h2>Overzicht</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Symbool</th><th>Naam</th><th>Type</th><th>Bot</th><th>Prioriteit</th><th>Laatste prijs</th><th>Trend</th><th>Reden</th><th>Positie</th><th>Acties</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="muted">Nog geen assets geselecteerd.</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?= h((string)$row['symbol']) ?></strong></td>
                    <td><?= h((string)($row['display_name'] ?? '')) ?></td>
                    <td><?= h((string)($row['asset_type'] ?? '')) ?></td>
                    <td><span class="<?= ((int)($row['is_active'] ?? 0) === 1) ? 'badge badge-good' : 'badge badge-neutral' ?>"><?= ((int)($row['is_active'] ?? 0) === 1) ? 'Actief' : 'Uit' ?></span></td>
                    <td><?= h((string)($row['priority_order'] ?? '-')) ?></td>
                    <td><?= formatPrice($row['last_price'] ?? null, 4) ?></td>
                    <td><span class="<?= badgeClassForTrend($row['trend_state'] ?? null) ?>"><?= h((string)($row['trend_state'] ?? 'ONBEKEND')) ?></span><br><span class="muted">score <?= h((string)($row['trend_score'] ?? '-')) ?></span></td>
                    <td><?= h((string)($row['signal_reason'] ?? '')) ?></td>
                    <td><?= $row['quantity'] !== null ? formatQty($row['quantity'], 8) : '-' ?></td>
                    <td>
                        <div class="actions">
                            <a class="btn-link btn-link-secondary btn-small" href="<?= h(appUrl('/asset_analysis.php?symbol=' . urlencode((string)$row['symbol']))) ?>">Analyse</a>
                            <form method="post" class="inline-form" onsubmit="return confirm('Weet je zeker dat je dit asset wilt verwijderen?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_symbol">
                                <input type="hidden" name="symbol" value="<?= h((string)$row['symbol']) ?>">
                                <button type="submit" class="btn-small btn-red">Verwijderen</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php renderPageEnd(); ?>
