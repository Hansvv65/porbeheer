<?php
declare(strict_types=1);

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/functions.php';

$config = require __DIR__ . '/app/config.php';
$appName = $config['app']['name'] ?? 'Trading Dashboard';

$message = null;
$error = null;

if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $message = (string)$_GET['msg'];
}
if (isset($_GET['err']) && $_GET['err'] !== '') {
    $error = (string)$_GET['err'];
}

function priceDirectionBadge(?float $current, ?float $reference): string
{
    if ($current === null || $reference === null) {
        return '<span class="badge badge-neutral">?</span>';
    }

    if ($current > $reference) {
        return '<span class="badge badge-buy" title="Omhoog">▲</span>';
    }

    if ($current < $reference) {
        return '<span class="badge badge-sell" title="Omlaag">▼</span>';
    }

    return '<span class="badge badge-neutral" title="Gelijk">•</span>';
}

function priceDiffText(?float $current, ?float $reference): string
{
    if ($current === null || $reference === null) {
        return '-';
    }

    $diff = $current - $reference;
    if (abs($diff) < 0.00000001) {
        return '0.00000000';
    }

    return number_format($diff, 8, '.', '');
}

function priceClass(?float $current, ?float $reference): string
{
    if ($current === null || $reference === null) {
        return 'muted';
    }
    if ($current > $reference) {
        return 'status-on';
    }
    if ($current < $reference) {
        return 'status-off';
    }
    return 'muted';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_settings') {
            $settings = settingRow($pdo);

            if (!$settings) {
                throw new RuntimeException('Geen bot_settings record gevonden.');
            }

            $botEnabled = postBool('bot_enabled');
            $paperMode = postBool('paper_mode');
            $tradeEnabled = postBool('trade_enabled');
            $breakoutWindow = max(1, postInt('breakout_window', 14));
            $amountPerTrade = max(0.01, postFloat('amount_per_trade_eur', 100));
            $maxOpenPositions = max(1, postInt('max_open_positions', 5));

            executeSql(
                $pdo,
                "UPDATE bot_settings
                 SET bot_enabled = ?, paper_mode = ?, trade_enabled = ?, breakout_window = ?, amount_per_trade_eur = ?, max_open_positions = ?
                 WHERE id = ?",
                [
                    $botEnabled,
                    $paperMode,
                    $tradeEnabled,
                    $breakoutWindow,
                    $amountPerTrade,
                    $maxOpenPositions,
                    $settings['id']
                ]
            );

            $message = 'Instellingen opgeslagen.';
        }

        if ($action === 'update_wallet_balance') {
            $wallet = walletRow($pdo);

            if (!$wallet) {
                throw new RuntimeException('Geen actieve wallet gevonden.');
            }

            $newBalance = max(0, postFloat('wallet_balance', 0));
            $oldBalance = (float)$wallet['balance'];
            $walletId = (int)$wallet['id'];

            executeSql(
                $pdo,
                "UPDATE wallet SET balance = ? WHERE id = ?",
                [$newBalance, $walletId]
            );

            $delta = $newBalance - $oldBalance;

            executeSql(
                $pdo,
                "INSERT INTO wallet_transactions
                 (wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $walletId,
                    'CORRECTION',
                    abs($delta),
                    $oldBalance,
                    $newBalance,
                    'MANUAL',
                    null,
                    'Handmatige saldo-correctie via dashboard'
                ]
            );

            $message = 'Wallet saldo bijgewerkt.';
        }

        if ($action === 'add_symbol') {
            $symbol = strtoupper(trim($_GET['symbol'] ?? $_POST['symbol'] ?? ''));
            #$symbol = strtoupper(trim((string)($_POST['symbol'] ?? '')));
            $assetType = strtoupper(trim((string)($_POST['asset_type'] ?? 'STOCK')));
            $dataSymbol = trim((string)($_POST['data_symbol'] ?? ''));
            $orderSymbol = trim((string)($_POST['order_symbol'] ?? ''));
            $priority = max(0, postInt('priority_order', 999));

            if ($symbol === '') {
                throw new RuntimeException('Geen symbool opgegeven.');
            }

            if (!in_array($assetType, ['STOCK', 'CRYPTO'], true)) {
                throw new RuntimeException('Ongeldig asset type.');
            }

            if ($dataSymbol === '') {
                $dataSymbol = $symbol;
            }

            if ($orderSymbol === '') {
                $orderSymbol = $symbol;
            }

            $exists = fetchOne(
                $pdo,
                "SELECT id FROM bot_symbols WHERE symbol = ? LIMIT 1",
                [$symbol]
            );

            if ($exists) {
                throw new RuntimeException('Dit symbool bestaat al.');
            }

            executeSql(
                $pdo,
                "INSERT INTO bot_symbols (symbol, asset_type, data_symbol, order_symbol, is_active, priority_order)
                 VALUES (?, ?, ?, ?, 1, ?)",
                [$symbol, $assetType, $dataSymbol, $orderSymbol, $priority]
            );

            $message = 'Symbool toegevoegd: ' . $symbol;
        }

        if ($action === 'toggle_symbol') {
            $id = postInt('id');
            $row = fetchOne($pdo, "SELECT * FROM bot_symbols WHERE id = ? LIMIT 1", [$id]);

            if (!$row) {
                throw new RuntimeException('Symbool niet gevonden.');
            }

            $newState = ((int)$row['is_active'] === 1) ? 0 : 1;

            executeSql(
                $pdo,
                "UPDATE bot_symbols SET is_active = ? WHERE id = ?",
                [$newState, $id]
            );

            $message = 'Symboolstatus bijgewerkt.';
        }

        if ($action === 'update_symbol_priority') {
            $id = postInt('id');
            $priority = max(0, postInt('priority_order', 0));

            $row = fetchOne($pdo, "SELECT * FROM bot_symbols WHERE id = ? LIMIT 1", [$id]);

            if (!$row) {
                throw new RuntimeException('Symbool niet gevonden.');
            }

            executeSql(
                $pdo,
                "UPDATE bot_symbols SET priority_order = ? WHERE id = ?",
                [$priority, $id]
            );

            $message = 'Prioriteit bijgewerkt voor ' . $row['symbol'] . '.';
        }

        if ($action === 'delete_symbol') {
            $id = postInt('id');
            $row = fetchOne($pdo, "SELECT * FROM bot_symbols WHERE id = ? LIMIT 1", [$id]);

            if (!$row) {
                throw new RuntimeException('Symbool niet gevonden.');
            }

            executeSql(
                $pdo,
                "DELETE FROM bot_symbols WHERE id = ?",
                [$id]
            );

            $message = 'Symbool verwijderd: ' . $row['symbol'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$settings = settingRow($pdo);
$wallet = walletRow($pdo);

$symbols = fetchAllRows(
    $pdo,
    "SELECT * FROM bot_symbols ORDER BY priority_order ASC, symbol ASC"
);

$strategyRuns = fetchAllRows(
    $pdo,
    "SELECT * FROM strategy_runs ORDER BY id DESC LIMIT 20"
);

$trades = fetchAllRows(
    $pdo,
    "SELECT * FROM trades ORDER BY id DESC LIMIT 20"
);

$logs = fetchAllRows(
    $pdo,
    "SELECT * FROM bot_logs ORDER BY id DESC LIMIT 20"
);

$openPositions = fetchAllRows(
    $pdo,
    "SELECT * FROM positions WHERE status = 'OPEN' ORDER BY opened_at DESC LIMIT 20"
);

$walletTransactions = fetchAllRows(
    $pdo,
    "SELECT * FROM wallet_transactions ORDER BY id DESC LIMIT 20"
);

$activeCount = 0;
foreach ($symbols as $s) {
    if ((int)$s['is_active'] === 1) {
        $activeCount++;
    }
}

$symbolStats = [];

foreach ($symbols as $row) {
    $symbol = (string)$row['symbol'];

    $latestRuns = fetchAllRows(
        $pdo,
        "SELECT id, current_price, action_taken, created_at
         FROM strategy_runs
         WHERE symbol = ?
         ORDER BY id DESC
         LIMIT 2",
        [$symbol]
    );

    $lastTrade = fetchOne(
        $pdo,
        "SELECT id, price, type, timestamp
         FROM trades
         WHERE asset = ?
         ORDER BY id DESC
         LIMIT 1",
        [$symbol]
    );

    $latestPrice = null;
    $previousPollPrice = null;

    if (isset($latestRuns[0])) {
        $latestPrice = (float)$latestRuns[0]['current_price'];
    }

    if (isset($latestRuns[1])) {
        $previousPollPrice = (float)$latestRuns[1]['current_price'];
    }

    $lastTradePrice = null;
    if ($lastTrade && isset($lastTrade['price'])) {
        $lastTradePrice = (float)$lastTrade['price'];
    }

    $symbolStats[$symbol] = [
        'latest_price' => $latestPrice,
        'previous_poll_price' => $previousPollPrice,
        'last_trade_price' => $lastTradePrice,
        'last_action' => $latestRuns[0]['action_taken'] ?? null,
        'last_poll_at' => $latestRuns[0]['created_at'] ?? null,
        'last_trade_type' => $lastTrade['type'] ?? null,
        'last_trade_at' => $lastTrade['timestamp'] ?? null,
    ];
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title><?= h($appName) ?> - Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">

    <div class="topbar">
        <div>
            <h1><?= h($appName) ?></h1>
            <div class="sub">Trading bot dashboard · databasegestuurd bedienpaneel</div>
        </div>
        <div class="sub"><?= h(date('d-m-Y H:i:s')) ?></div>
    </div>

    <?php if ($message): ?>
        <div class="msg"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="err"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="grid">

        <div class="card span-4">
            <h2>Bot status</h2>
            <div class="stats">
                <div class="stat">
                    <div class="label">Bot enabled</div>
                    <div class="value">
                        <span class="<?= ((int)($settings['bot_enabled'] ?? 0) === 1) ? 'status-on' : 'status-off' ?>">
                            <?= ((int)($settings['bot_enabled'] ?? 0) === 1) ? 'AAN' : 'UIT' ?>
                        </span>
                    </div>
                </div>
                <div class="stat">
                    <div class="label">Paper mode</div>
                    <div class="value">
                        <span class="<?= ((int)($settings['paper_mode'] ?? 0) === 1) ? 'status-on' : 'status-off' ?>">
                            <?= ((int)($settings['paper_mode'] ?? 0) === 1) ? 'AAN' : 'UIT' ?>
                        </span>
                    </div>
                </div>
                <div class="stat">
                    <div class="label">Trade enabled</div>
                    <div class="value">
                        <span class="<?= ((int)($settings['trade_enabled'] ?? 0) === 1) ? 'status-on' : 'status-off' ?>">
                            <?= ((int)($settings['trade_enabled'] ?? 0) === 1) ? 'AAN' : 'UIT' ?>
                        </span>
                    </div>
                </div>
                <div class="stat">
                    <div class="label">Breakout window</div>
                    <div class="value"><?= h($settings['breakout_window'] ?? 14) ?></div>
                </div>
            </div>
            <div class="panel-note">De Python bot leest deze waarden direct uit de database.</div>
        </div>

        <div class="card span-4">
            <h2>Wallet</h2>
            <div class="stats">
                <div class="stat">
                    <div class="label">Naam</div>
                    <div class="value small"><?= h($wallet['wallet_name'] ?? '-') ?></div>
                </div>
                <div class="stat">
                    <div class="label">Valuta</div>
                    <div class="value"><?= h($wallet['currency'] ?? 'EUR') ?></div>
                </div>
                <div class="stat">
                    <div class="label">Saldo</div>
                    <div class="value"><?= formatEuro($wallet['balance'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label">Gereserveerd</div>
                    <div class="value"><?= formatEuro($wallet['reserved_balance'] ?? 0) ?></div>
                </div>
            </div>

            <form method="post" style="margin-top:16px;">
                <input type="hidden" name="action" value="update_wallet_balance">
                <div class="row">
                    <div>
                        <label for="wallet_balance">Nieuw saldo</label>
                        <input type="number" step="0.01" min="0" id="wallet_balance" name="wallet_balance" value="<?= h((string)($wallet['balance'] ?? '0')) ?>">
                    </div>
                    <div style="display:flex;align-items:flex-end;">
                        <button type="submit" class="btn-yellow">Saldo aanpassen</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card span-4">
            <h2>Snel overzicht</h2>
            <div class="stats">
                <div class="stat">
                    <div class="label">Aantal symbols</div>
                    <div class="value"><?= count($symbols) ?></div>
                </div>
                <div class="stat">
                    <div class="label">Actieve symbols</div>
                    <div class="value"><?= h($activeCount) ?></div>
                </div>
                <div class="stat">
                    <div class="label">Laatste runs</div>
                    <div class="value"><?= count($strategyRuns) ?></div>
                </div>
                <div class="stat">
                    <div class="label">Open posities</div>
                    <div class="value"><?= count($openPositions) ?></div>
                </div>
            </div>
        </div>

        <div class="card span-6">
            <h2>Instellingen</h2>
            <form method="post">
                <input type="hidden" name="action" value="save_settings">

                <div class="checks">
                    <label><input type="checkbox" name="bot_enabled" value="1" <?= ((int)($settings['bot_enabled'] ?? 0) === 1) ? 'checked' : '' ?>> Bot enabled</label>
                    <label><input type="checkbox" name="paper_mode" value="1" <?= ((int)($settings['paper_mode'] ?? 0) === 1) ? 'checked' : '' ?>> Paper mode</label>
                    <label><input type="checkbox" name="trade_enabled" value="1" <?= ((int)($settings['trade_enabled'] ?? 0) === 1) ? 'checked' : '' ?>> Trade enabled</label>
                </div>

                <div class="row">
                    <div>
                        <label for="breakout_window">Breakout window</label>
                        <input type="number" id="breakout_window" name="breakout_window" min="1" value="<?= h($settings['breakout_window'] ?? 14) ?>">
                    </div>
                    <div>
                        <label for="amount_per_trade_eur">Bedrag per trade (EUR)</label>
                        <input type="number" id="amount_per_trade_eur" name="amount_per_trade_eur" min="0.01" step="0.01" value="<?= h($settings['amount_per_trade_eur'] ?? '100.00') ?>">
                    </div>
                </div>

                <div class="row">
                    <div>
                        <label for="max_open_positions">Max open positions</label>
                        <input type="number" id="max_open_positions" name="max_open_positions" min="1" value="<?= h($settings['max_open_positions'] ?? 5) ?>">
                    </div>
                    <div></div>
                </div>

                <button type="submit">Instellingen opslaan</button>
            </form>
        </div>

        <div class="card span-6">
            <h2>Symbool toevoegen</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_symbol">

                <div class="row-3">
                    <div>
                        <label for="symbol">Interne code</label>
                        <input type="text" id="symbol" name="symbol" placeholder="Bijv. AAPL of BTCUSD">
                    </div>
                    <div>
                        <label for="asset_type">Type</label>
                        <select id="asset_type" name="asset_type" style="width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);">
                            <option value="STOCK">STOCK</option>
                            <option value="CRYPTO">CRYPTO</option>
                        </select>
                    </div>
                    <div>
                        <label for="priority_order">Prioriteit</label>
                        <input type="number" id="priority_order" name="priority_order" min="0" value="999">
                    </div>
                </div>

                <div class="row">
                    <div>
                        <label for="data_symbol">Data symbool</label>
                        <input type="text" id="data_symbol" name="data_symbol" placeholder="Bijv. AAPL of BTC-USD">
                    </div>
                    <div>
                        <label for="order_symbol">Order symbool</label>
                        <input type="text" id="order_symbol" name="order_symbol" placeholder="Bijv. AAPL of BTC/USD">
                    </div>
                </div>

		<button type="submit">Symbool toevoegen</button>
		<button type="button" onclick="window.location.href='asset_lookup.php'">Symbool opzoeken</button>
            </form>
            <div class="panel-note">Gebruik voor crypto meestal data_symbol zoals BTC-USD en order_symbol zoals BTC/USD.</div>
        </div>

        <div class="card span-12">
            <h2>Symbolbeheer</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Symbol</th>
                        <th>Type</th>
                        <th>Data symbool</th>
                        <th>Order symbool</th>
                        <th>Laatste prijs</th>
                        <th>Poll</th>
                        <th>Wijziging</th>
                        <th>Prioriteit</th>
                        <th>Status</th>
                        <th>Wijzig prioriteit</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($symbols as $row): ?>
                    <?php
                    $symbol = (string)$row['symbol'];
                    $stats = $symbolStats[$symbol] ?? [];

                    $latestPrice = $stats['latest_price'] ?? null;
                    $previousPollPrice = $stats['previous_poll_price'] ?? null;
                    $lastTradePrice = $stats['last_trade_price'] ?? null;
                    ?>
                    <tr>
                        <td><?= h($row['id']) ?></td>
                        <td><strong><?= h($row['symbol']) ?></strong></td>
                        <td><?= h($row['asset_type'] ?? 'STOCK') ?></td>
                        <td><?= h($row['data_symbol'] ?? '') ?></td>
                        <td><?= h($row['order_symbol'] ?? '') ?></td>

                        <td>
                            <span class="<?= h(priceClass($latestPrice, $previousPollPrice)) ?>">
                                <?= $latestPrice !== null ? h(number_format((float)$latestPrice, 8, '.', '')) : '-' ?>
                            </span>
                        </td>

                        <td>
                            <?= priceDirectionBadge($latestPrice, $previousPollPrice) ?>
                            <span class="muted">
                                <?= h(priceDiffText($latestPrice, $previousPollPrice)) ?>
                            </span>
                        </td>

                        <td>
                            <?= priceDirectionBadge($latestPrice, $lastTradePrice) ?>
                            <span class="muted">
                                <?= h(priceDiffText($latestPrice, $lastTradePrice)) ?>
                            </span>
                        </td>

                        <td><?= h($row['priority_order']) ?></td>

                        <td>
                            <span class="<?= ((int)$row['is_active'] === 1) ? 'status-on' : 'status-off' ?>">
                                <?= ((int)$row['is_active'] === 1) ? 'ACTIEF' : 'INACTIEF' ?>
                            </span>
                        </td>

                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="update_symbol_priority">
                                <input type="hidden" name="id" value="<?= h($row['id']) ?>">
                                <div class="actions">
                                    <input type="number" name="priority_order" min="0" value="<?= h($row['priority_order']) ?>" style="width:100px;">
                                    <button type="submit" class="btn-small btn-yellow">Opslaan</button>
                                </div>
                            </form>
                        </td>

                        <td>
                            <div class="actions">
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="toggle_symbol">
                                    <input type="hidden" name="id" value="<?= h($row['id']) ?>">
                                    <button type="submit" class="btn-small <?= ((int)$row['is_active'] === 1) ? 'btn-blue' : 'btn-purple' ?>">
                                        <?= ((int)$row['is_active'] === 1) ? 'Pauzeren' : 'Activeren' ?>
                                    </button>
                                </form>

                                <form method="post" class="inline-form" onsubmit="return confirm('Weet je zeker dat je dit symbool wilt verwijderen?');">
                                    <input type="hidden" name="action" value="delete_symbol">
                                    <input type="hidden" name="id" value="<?= h($row['id']) ?>">
                                    <button type="submit" class="btn-small btn-red">Verwijderen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$symbols): ?>
                    <tr><td colspan="12" class="muted">Geen symbols gevonden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card span-12">
            <h2>Laatste strategy runs</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Symbol</th>
                        <th>Current price</th>
                        <th>Previous high</th>
                        <th>Breakout</th>
                        <th>Action</th>
                        <th>Notes</th>
                        <th>Tijd</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($strategyRuns as $row): ?>
                    <tr>
                        <td><?= h($row['id']) ?></td>
                        <td><strong><?= h($row['symbol']) ?></strong></td>
                        <td><?= h($row['current_price']) ?></td>
                        <td><?= h($row['previous_high']) ?></td>
                        <td><?= ((int)$row['breakout'] === 1) ? 'JA' : 'NEE' ?></td>
                        <td><?= actionBadge((string)$row['action_taken']) ?></td>
                        <td><?= h($row['notes'] ?? '') ?></td>
                        <td><?= h($row['created_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$strategyRuns): ?>
                    <tr><td colspan="8" class="muted">Nog geen strategy runs.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card span-6">
            <h2>Laatste trades</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Asset</th>
                        <th>Type</th>
                        <th>Prijs</th>
                        <th>Aantal</th>
                        <th>P/L</th>
                        <th>Tijd</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($trades as $row): ?>
                    <tr>
                        <td><?= h($row['id']) ?></td>
                        <td><strong><?= h($row['asset']) ?></strong></td>
                        <td><?= actionBadge((string)$row['type']) ?></td>
                        <td><?= h($row['price']) ?></td>
                        <td><?= h($row['amount']) ?></td>
                        <td><?= h($row['profit_loss'] ?? '') ?></td>
                        <td><?= h($row['timestamp'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$trades): ?>
                    <tr><td colspan="7" class="muted">Nog geen trades.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card span-6">
            <h2>Open posities</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Symbol</th>
                        <th>Quantity</th>
                        <th>Avg price</th>
                        <th>Opened at</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($openPositions as $row): ?>
                    <tr>
                        <td><?= h($row['id']) ?></td>
                        <td><strong><?= h($row['symbol']) ?></strong></td>
                        <td><?= h($row['quantity']) ?></td>
                        <td><?= h($row['avg_price']) ?></td>
                        <td><?= h($row['opened_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$openPositions): ?>
                    <tr><td colspan="5" class="muted">Nog geen open posities.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card span-12">
            <h2>Laatste wallet transacties</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Before</th>
                        <th>After</th>
                        <th>Reference</th>
                        <th>Omschrijving</th>
                        <th>Tijd</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($walletTransactions as $row): ?>
                    <tr>
                        <td><?= h($row['id']) ?></td>
                        <td><?= h($row['transaction_type']) ?></td>
                        <td><?= h($row['amount']) ?></td>
                        <td><?= h($row['balance_before']) ?></td>
                        <td><?= h($row['balance_after']) ?></td>
                        <td><?= h(($row['reference_type'] ?? '') . ' #' . ($row['reference_id'] ?? '')) ?></td>
                        <td><?= h($row['description'] ?? '') ?></td>
                        <td><?= h($row['created_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$walletTransactions): ?>
                    <tr><td colspan="8" class="muted">Nog geen wallet transacties.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card span-12">
            <h2>Laatste bot logs</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Level</th>
                        <th>Bericht</th>
                        <th>Tijd</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $row): ?>
                    <tr>
                        <td><?= h($row['id']) ?></td>
                        <td><?= h($row['level']) ?></td>
                        <td><?= h($row['message']) ?></td>
                        <td><?= h($row['created_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr><td colspan="4" class="muted">Nog geen logs.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

	<div class="card span-4">
	    <h2>Paper reset</h2>
	    <form method="post" action="reset_paper.php" onsubmit="return confirm('Weet je zeker dat je alle paper data wilt wissen?');">
	        <div class="row">
	            <div>
	                <label for="start_balance">Nieuw startsaldo</label>
	                <input type="number" step="0.01" min="0" id="start_balance" name="start_balance" value="10000.00">
	            </div>
	            <div style="display:flex;align-items:flex-end;">
	                <button type="submit" class="btn-red">Reset paper omgeving</button>
	            </div>
	        </div>
	    </form>
	    <div class="panel-note">
	        Wis trades, posities, runs, logs en wallet-transacties en zet het saldo opnieuw op het startbedrag.
	    </div>
	</div>

    </div>
</div>
</body>
</html>
