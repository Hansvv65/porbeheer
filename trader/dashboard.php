<?php
declare(strict_types=1);

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/functions.php';
require __DIR__ . '/includes/layout.php';

if (isPost()) {
    requireCsrf();
    $action = postString('action');
    try {
        if ($action === 'save_settings') {
            $settings = settingRow($pdo);
            if (!$settings) {
                throw new RuntimeException('Geen instellingenrecord gevonden.');
            }
            executeSql($pdo, 'UPDATE bot_settings SET bot_enabled = ?, paper_mode = ?, trade_enabled = ?, breakout_window = ?, amount_per_trade_eur = ?, max_open_positions = ? WHERE id = ?', [
                postBool('bot_enabled'),
                postBool('paper_mode'),
                postBool('trade_enabled'),
                max(1, postInt('breakout_window', 14)),
                max(0.01, postFloat('amount_per_trade_eur', 100)),
                max(1, postInt('max_open_positions', 5)),
                $settings['id'],
            ]);
            redirectBackWithFlash('success', 'Instellingen opgeslagen.', appUrl('/dashboard.php'));
        }

        if ($action === 'update_wallet_balance') {
            $wallet = walletRow($pdo);
            if (!$wallet) {
                throw new RuntimeException('Geen actieve wallet gevonden.');
            }
            $newBalance = max(0, postFloat('wallet_balance', 0));
            $oldBalance = (float)$wallet['balance'];
            executeSql($pdo, 'UPDATE wallet SET balance = ? WHERE id = ?', [$newBalance, $wallet['id']]);
            executeSql($pdo, 'INSERT INTO wallet_transactions (wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $wallet['id'], 'CORRECTION', abs($newBalance - $oldBalance), $oldBalance, $newBalance, 'MANUAL', null, 'Handmatige correctie via dashboard',
            ]);
            redirectBackWithFlash('success', 'Wallet bijgewerkt.', appUrl('/dashboard.php'));
        }
    } catch (Throwable $e) {
        error_log('dashboard action failed: ' . $e->getMessage());
        redirectBackWithFlash('error', 'Actie mislukt.', appUrl('/dashboard.php'));
    }
}

$appName = config()['app']['name'] ?? 'Trading Dashboard';
$settings = settingRow($pdo);
$wallet = walletRow($pdo);
$summary = fetchOne($pdo, "SELECT COUNT(*) AS total_active, COALESCE(SUM(asset_type = 'stock'),0) AS stock_count, COALESCE(SUM(asset_type = 'crypto'),0) AS crypto_count FROM asset_universe WHERE status = 'active'") ?: [];
$trackedCount = fetchOne($pdo, 'SELECT COUNT(*) AS cnt FROM tracked_symbols');
$botLogSummary = fetchOne($pdo, 'SELECT COUNT(*) AS total_logs, MAX(created_at) AS last_log FROM bot_logs');
$openPositions = fetchAllRows($pdo, "
    SELECT p.symbol, p.quantity, p.avg_price, p.opened_at,
           s.price AS last_price, s.trend_state, s.trend_score, s.change_24h
    FROM positions p
    LEFT JOIN asset_snapshots s ON s.id = (
        SELECT s2.id FROM asset_snapshots s2 WHERE s2.symbol = p.symbol ORDER BY s2.snapshot_time DESC, s2.id DESC LIMIT 1
    )
    WHERE p.status = 'OPEN'
    ORDER BY p.opened_at DESC
    LIMIT 20
");
$assetRows = fetchAllRows($pdo, "
    SELECT b.id, b.symbol, b.asset_type, b.is_active, b.priority_order,
           t.display_name,
           snap.price AS last_price,
           snap.trend_state,
           snap.trend_score,
           snap.change_24h,
           sig.signal_reason,
           sig.signal_type,
           sig.signal_time,
           run.action_taken,
           run.notes AS run_notes,
           run.created_at AS run_created_at,
           pos.quantity,
           pos.avg_price
    FROM bot_symbols b
    LEFT JOIN tracked_symbols t ON t.symbol = b.symbol
    LEFT JOIN asset_snapshots snap ON snap.id = (
        SELECT s.id FROM asset_snapshots s WHERE s.symbol = b.symbol ORDER BY s.snapshot_time DESC, s.id DESC LIMIT 1
    )
    LEFT JOIN asset_signal_log sig ON sig.id = (
        SELECT l.id FROM asset_signal_log l WHERE l.symbol = b.symbol ORDER BY l.signal_time DESC, l.id DESC LIMIT 1
    )
    LEFT JOIN strategy_runs run ON run.id = (
        SELECT r.id FROM strategy_runs r WHERE r.symbol = b.symbol ORDER BY r.created_at DESC, r.id DESC LIMIT 1
    )
    LEFT JOIN positions pos ON pos.id = (
        SELECT p.id FROM positions p WHERE p.symbol = b.symbol AND p.status = 'OPEN' ORDER BY p.id DESC LIMIT 1
    )
    ORDER BY b.is_active DESC, b.priority_order ASC, b.symbol ASC
    LIMIT 50
");

renderPageStart($appName, 'Eén consistent dashboard voor status, wallet, trends en uitleg waarom assets wel of niet verhandeld worden.', [
    ['href' => appUrl('/asset_lookup.php'), 'label' => 'Asset zoeken'],
    ['href' => appUrl('/bot_log.php'), 'label' => 'Bot log', 'secondary' => true],
    ['href' => appUrl('/symbols.php'), 'label' => 'Mijn assets', 'secondary' => true],
]);
?>
<div class="grid">
    <div class="card span-4">
        <h2>Bot status</h2>
        <div class="stats">
            <div class="stat"><div class="label">Bot</div><div class="value small"><span class="<?= ((int)($settings['bot_enabled'] ?? 0) === 1) ? 'badge badge-good' : 'badge badge-bad' ?>"><?= ((int)($settings['bot_enabled'] ?? 0) === 1) ? 'AAN' : 'UIT' ?></span></div></div>
            <div class="stat"><div class="label">Paper mode</div><div class="value small"><span class="<?= ((int)($settings['paper_mode'] ?? 0) === 1) ? 'badge badge-good' : 'badge badge-bad' ?>"><?= ((int)($settings['paper_mode'] ?? 0) === 1) ? 'AAN' : 'UIT' ?></span></div></div>
            <div class="stat"><div class="label">Trade mode</div><div class="value small"><span class="<?= ((int)($settings['trade_enabled'] ?? 0) === 1) ? 'badge badge-good' : 'badge badge-bad' ?>"><?= ((int)($settings['trade_enabled'] ?? 0) === 1) ? 'AAN' : 'UIT' ?></span></div></div>
            <div class="stat"><div class="label">Laatste bot log</div><div class="value small"><?= formatDateTime($botLogSummary['last_log'] ?? null) ?></div></div>
        </div>
    </div>

    <div class="card span-4">
        <h2>Wallet</h2>
        <div class="stats">
            <div class="stat"><div class="label">Naam</div><div class="value small"><?= h((string)($wallet['wallet_name'] ?? '-')) ?></div></div>
            <div class="stat"><div class="label">Valuta</div><div class="value small"><?= h((string)($wallet['currency'] ?? 'EUR')) ?></div></div>
            <div class="stat"><div class="label">Saldo</div><div class="value small"><?= formatEuro($wallet['balance'] ?? 0) ?></div></div>
            <div class="stat"><div class="label">Gereserveerd</div><div class="value small"><?= formatEuro($wallet['reserved_balance'] ?? 0) ?></div></div>
        </div>
    </div>

    <div class="card span-4">
        <h2>Overzicht</h2>
        <div class="stats">
            <div class="stat"><div class="label">In watchlist</div><div class="value"><?= (int)($trackedCount['cnt'] ?? 0) ?></div></div>
            <div class="stat"><div class="label">In bot</div><div class="value"><?= count($assetRows) ?></div></div>
            <div class="stat"><div class="label">Open posities</div><div class="value"><?= count($openPositions) ?></div></div>
            <div class="stat"><div class="label">Actieve catalogus</div><div class="value"><?= (int)($summary['total_active'] ?? 0) ?></div></div>
        </div>
    </div>

    <div class="card span-6">
        <h2>Instellingen</h2>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_settings">
            <div class="row-3">
                <label><input type="checkbox" name="bot_enabled" value="1" <?= ((int)($settings['bot_enabled'] ?? 0) === 1) ? 'checked' : '' ?>> Bot actief</label>
                <label><input type="checkbox" name="paper_mode" value="1" <?= ((int)($settings['paper_mode'] ?? 0) === 1) ? 'checked' : '' ?>> Paper mode</label>
                <label><input type="checkbox" name="trade_enabled" value="1" <?= ((int)($settings['trade_enabled'] ?? 0) === 1) ? 'checked' : '' ?>> Orders toegestaan</label>
            </div>
            <div class="row">
                <div><label for="breakout_window">Breakout window</label><input type="number" id="breakout_window" name="breakout_window" min="1" value="<?= h((string)($settings['breakout_window'] ?? 14)) ?>"></div>
                <div><label for="amount_per_trade_eur">Bedrag per trade</label><input type="number" id="amount_per_trade_eur" name="amount_per_trade_eur" min="0.01" step="0.01" value="<?= h((string)($settings['amount_per_trade_eur'] ?? '100.00')) ?>"></div>
            </div>
            <div class="row">
                <div><label for="max_open_positions">Max open posities</label><input type="number" id="max_open_positions" name="max_open_positions" min="1" value="<?= h((string)($settings['max_open_positions'] ?? 5)) ?>"></div>
                <div style="display:flex;align-items:flex-end;"><button type="submit">Instellingen opslaan</button></div>
            </div>
        </form>
    </div>

    <div class="card span-6">
        <h2>Wallet bijwerken</h2>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_wallet_balance">
            <div class="row">
                <div>
                    <label for="wallet_balance">Nieuw saldo</label>
                    <input type="number" step="0.01" min="0" id="wallet_balance" name="wallet_balance" value="<?= h((string)($wallet['balance'] ?? '0')) ?>">
                </div>
                <div style="display:flex;align-items:flex-end;"><button type="submit" class="btn-amber">Saldo opslaan</button></div>
            </div>
        </form>
        <form method="post" action="<?= h(appUrl('/reset_paper.php')) ?>" onsubmit="return confirm('Weet je zeker dat je de paper omgeving wilt resetten?');">
            <?= csrfField() ?>
            <div class="row">
                <div><label for="start_balance">Reset startsaldo</label><input type="number" step="0.01" min="0" id="start_balance" name="start_balance" value="10000.00"></div>
                <div style="display:flex;align-items:flex-end;"><button type="submit" class="btn-red">Paper reset</button></div>
            </div>
        </form>
    </div>

    <div class="card span-12">
        <div class="section-tools">
            <h2>Actieve assets, trend en reden</h2>
            <div class="muted">Per asset zie je trend, laatste beslissing en link naar koersverloop.</div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Status</th>
                        <th>Laatste prijs</th>
                        <th>Trend</th>
                        <th>24u</th>
                        <th>Laatste actie</th>
                        <th>Waarom niet/wel</th>
                        <th>Open positie</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assetRows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= h((string)$row['symbol']) ?></strong><br>
                            <span class="muted"><?= h((string)($row['display_name'] ?: $row['asset_type'])) ?></span>
                        </td>
                        <td><span class="<?= ((int)$row['is_active'] === 1) ? 'badge badge-good' : 'badge badge-neutral' ?>"><?= ((int)$row['is_active'] === 1) ? 'Actief' : 'Inactief' ?></span></td>
                        <td><?= formatPrice($row['last_price'] ?? null, 4) ?></td>
                        <td><span class="<?= badgeClassForTrend($row['trend_state'] ?? null) ?>"><?= h((string)($row['trend_state'] ?? 'ONBEKEND')) ?></span><br><span class="muted">score <?= h((string)($row['trend_score'] ?? '-')) ?></span></td>
                        <td><?= formatPct($row['change_24h'] ?? null, 2) ?></td>
                        <td><span class="<?= badgeClassForAction($row['action_taken'] ?? ($row['signal_type'] ?? 'SKIP')) ?>"><?= h((string)($row['action_taken'] ?? ($row['signal_type'] ?? 'SKIP'))) ?></span><br><span class="muted"><?= formatDateTime($row['run_created_at'] ?? $row['signal_time'] ?? null) ?></span></td>
                        <td><?= h((string)($row['signal_reason'] ?: $row['run_notes'] ?: 'Geen toelichting')) ?></td>
                        <td>
                            <?php if ($row['quantity'] !== null): ?>
                                <?= formatQty($row['quantity'], 8) ?><br>
                                <span class="muted">avg <?= formatPrice($row['avg_price'], 4) ?></span>
                            <?php else: ?>
                                <span class="muted">Geen</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <a class="btn-link btn-link-secondary btn-small" href="<?= h(appUrl('/asset_analysis.php?symbol=' . urlencode((string)$row['symbol']))) ?>">Analyse</a>
                                <a class="btn-link btn-link-secondary btn-small" href="<?= h(appUrl('/asset_history.php?symbol=' . urlencode((string)$row['symbol']))) ?>">Koersverloop</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card span-12">
        <h2>Open posities</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Symbool</th><th>Aantal</th><th>Gem. prijs</th><th>Laatste prijs</th><th>Trend</th><th>24u</th><th>Geopend</th></tr></thead>
                <tbody>
                <?php if (!$openPositions): ?>
                    <tr><td colspan="7" class="muted">Geen open posities.</td></tr>
                <?php else: foreach ($openPositions as $row): ?>
                    <tr>
                        <td><strong><?= h((string)$row['symbol']) ?></strong></td>
                        <td><?= formatQty($row['quantity'] ?? null, 8) ?></td>
                        <td><?= formatPrice($row['avg_price'] ?? null, 4) ?></td>
                        <td><?= formatPrice($row['last_price'] ?? null, 4) ?></td>
                        <td><span class="<?= badgeClassForTrend($row['trend_state'] ?? null) ?>"><?= h((string)($row['trend_state'] ?? 'ONBEKEND')) ?></span></td>
                        <td><?= formatPct($row['change_24h'] ?? null, 2) ?></td>
                        <td><?= formatDateTime($row['opened_at'] ?? null) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
