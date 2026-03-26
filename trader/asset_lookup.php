<?php
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/functions.php';
require_once __DIR__ . '/app/layout.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO-verbinding ontbreekt in asset_lookup.php');
}

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Eenvoudige flash helper als die nog niet elders bestaat.
 */
if (!function_exists('setFlashMessage')) {
    function setFlashMessage(string $type, string $message): void
    {
        $_SESSION['flash_message'] = [
            'type' => $type,
            'text' => $message,
        ];
    }
}

if (!function_exists('getFlashMessage')) {
    function getFlashMessage(): ?array
    {
        $msg = $_SESSION['flash_message'] ?? null;
        unset($_SESSION['flash_message']);
        return is_array($msg) ? $msg : null;
    }
}

/**
 * Symbolen consistent maken.
 */
function normalizeSymbol(string $symbol): string
{
    $symbol = trim($symbol);
    $symbol = strtoupper($symbol);
    return $symbol;
}

/**
 * Asset type normaliseren naar jouw db-conventie.
 */
function normalizeAssetType(?string $assetType): string
{
    $type = strtoupper(trim((string)$assetType));

    return match ($type) {
        'CRYPTO', 'COIN', 'TOKEN' => 'CRYPTO',
        default => 'STOCK',
    };
}

/**
 * Bepaal data_symbol voor python/yfinance-achtige feeds.
 */
function deriveDataSymbol(string $symbol, string $assetType): string
{
    $symbol = normalizeSymbol($symbol);
    $assetType = normalizeAssetType($assetType);

    if ($assetType === 'CRYPTO') {
        // Als er al een feed-symbool staat zoals BTC-USD, laten we dat elders intact.
        if (str_contains($symbol, '-')) {
            return $symbol;
        }

        // Voor veel crypto's is BTC-USD stijl gewenst.
        return $symbol . '-USD';
    }

    return $symbol;
}

/**
 * Bepaal order_symbol voor broker/order-laag.
 * Hier bewust simpel gehouden.
 */
function deriveOrderSymbol(string $symbol, string $assetType): string
{
    $symbol = normalizeSymbol($symbol);
    $assetType = normalizeAssetType($assetType);

    if ($assetType === 'CRYPTO') {
        // Kies hier een eenvoudige slash-notatie voor orders.
        if (str_contains($symbol, '/')) {
            return $symbol;
        }
        if (str_contains($symbol, '-')) {
            return str_replace('-', '/', $symbol);
        }
        return $symbol . '/USD';
    }

    return $symbol;
}

$q = trim((string)($_GET['q'] ?? ''));
$typeFilter = strtoupper(trim((string)($_GET['type'] ?? '')));
if (!in_array($typeFilter, ['', 'STOCK', 'CRYPTO'], true)) {
    $typeFilter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'oneclick_add') {
        $symbol         = normalizeSymbol((string)($_POST['symbol'] ?? ''));
        $displayName    = trim((string)($_POST['display_name'] ?? ''));
        $assetType      = normalizeAssetType((string)($_POST['asset_type'] ?? 'STOCK'));
        $exchangeName   = trim((string)($_POST['exchange_name'] ?? ''));
        $currency       = trim((string)($_POST['currency'] ?? ''));
        $provider       = trim((string)($_POST['provider'] ?? ''));
        $sourceProvider = $provider !== '' ? $provider : 'asset_universe';
        $notes          = 'Toegevoegd via asset_lookup op ' . date('Y-m-d H:i:s');

        if ($symbol === '') {
            setFlashMessage('error', 'Geen geldig symbool ontvangen.');
            header('Location: ' . appUrl('/asset_lookup.php'));
            exit;
        }

        try {
            $pdo->beginTransaction();

            /**
             * 1. tracked_symbols bijwerken of invoeren
             */
            $sqlTracked = "
                INSERT INTO tracked_symbols (
                    symbol,
                    created_at,
                    display_name,
                    asset_type,
                    exchange_name,
                    source_provider,
                    notes
                ) VALUES (
                    :symbol_insert,
                    NOW(),
                    :display_name_insert,
                    :asset_type_insert,
                    :exchange_name_insert,
                    :source_provider_insert,
                    :notes_insert
                )
                ON DUPLICATE KEY UPDATE
                    display_name    = :display_name_update,
                    asset_type      = :asset_type_update,
                    exchange_name   = :exchange_name_update,
                    source_provider = :source_provider_update,
                    notes           = :notes_update
            ";

            $stTracked = $pdo->prepare($sqlTracked);
            $stTracked->execute([
                ':symbol_insert'            => $symbol,
                ':display_name_insert'      => $displayName !== '' ? $displayName : $symbol,
                ':asset_type_insert'        => $assetType,
                ':exchange_name_insert'     => $exchangeName,
                ':source_provider_insert'   => $sourceProvider,
                ':notes_insert'             => $notes,

                ':display_name_update'      => $displayName !== '' ? $displayName : $symbol,
                ':asset_type_update'        => $assetType,
                ':exchange_name_update'     => $exchangeName,
                ':source_provider_update'   => $sourceProvider,
                ':notes_update'             => $notes,
            ]);

            /**
             * 2. bot_symbols bijwerken of invoeren
             */
            $dataSymbol  = deriveDataSymbol($symbol, $assetType);
            $orderSymbol = deriveOrderSymbol($symbol, $assetType);

            $sqlBot = "
                INSERT INTO bot_symbols (
                    symbol,
                    asset_type,
                    data_symbol,
                    order_symbol,
                    is_active,
                    priority_order,
                    created_at
                ) VALUES (
                    :symbol_insert,
                    :asset_type_insert,
                    :data_symbol_insert,
                    :order_symbol_insert,
                    1,
                    0,
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    asset_type   = :asset_type_update,
                    data_symbol  = :data_symbol_update,
                    order_symbol = :order_symbol_update,
                    is_active    = 1
            ";

            $stBot = $pdo->prepare($sqlBot);
            $stBot->execute([
                ':symbol_insert'       => $symbol,
                ':asset_type_insert'   => $assetType,
                ':data_symbol_insert'  => $dataSymbol,
                ':order_symbol_insert' => $orderSymbol,

                ':asset_type_update'   => $assetType,
                ':data_symbol_update'  => $dataSymbol,
                ':order_symbol_update' => $orderSymbol,
            ]);

            $pdo->commit();

            setFlashMessage(
                'success',
                sprintf(
                    'Asset %s is toegevoegd/bijgewerkt. Type: %s, data_symbol: %s, order_symbol: %s.',
                    $symbol,
                    $assetType,
                    $dataSymbol,
                    $orderSymbol
                )
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('asset_lookup oneclick_add failed: ' . $e->getMessage());

            setFlashMessage(
                'error',
                'Toevoegen mislukt: ' . $e->getMessage()
            );
        }

        $redirectUrl = appUrl('/asset_lookup.php');
        $qs = [];

        if ($q !== '') {
            $qs['q'] = $q;
        }
        if ($typeFilter !== '') {
            $qs['type'] = $typeFilter;
        }

        if ($qs !== []) {
            $redirectUrl .= '?' . http_build_query($qs);
        }

        header('Location: ' . $redirectUrl);
        exit;
    }
}

$trackedMap = [];
foreach (fetchAllRows($pdo, 'SELECT symbol FROM tracked_symbols') as $trackedRow) {
    $trackedMap[normalizeSymbol((string)$trackedRow['symbol'])] = true;
}

$botMap = [];
foreach (fetchAllRows($pdo, 'SELECT symbol, is_active FROM bot_symbols') as $botRow) {
    $botMap[normalizeSymbol((string)$botRow['symbol'])] = (int)($botRow['is_active'] ?? 0);
}

$results = [];

if ($q !== '' || $typeFilter !== '') {
    $sql = '
        SELECT
            symbol,
            display_name,
            asset_type,
            exchange_name,
            currency,
            provider
        FROM asset_universe
        WHERE status = "active"
    ';

    $params = [];

    if ($q !== '') {
        $sql .= '
            AND (
                symbol LIKE :q_symbol
                OR display_name LIKE :q_name
                OR search_text LIKE :q_text
            )
        ';
        $like = '%' . $q . '%';
        $params[':q_symbol'] = $like;
        $params[':q_name']   = $like;
        $params[':q_text']   = $like;
    }

    if ($typeFilter !== '') {
        $sql .= ' AND UPPER(asset_type) = :type_filter';
        $params[':type_filter'] = $typeFilter;
    }

    $sql .= '
        ORDER BY
            CASE
                WHEN UPPER(symbol) = :exact_symbol THEN 0
                WHEN UPPER(symbol) LIKE :start_symbol THEN 1
                ELSE 2
            END,
            symbol ASC
        LIMIT 100
    ';

    $params[':exact_symbol'] = strtoupper($q);
    $params[':start_symbol'] = strtoupper($q) . '%';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $results = $st->fetchAll();
} else {
    $results = fetchAllRows(
        $pdo,
        '
        SELECT
            symbol,
            display_name,
            asset_type,
            exchange_name,
            currency,
            provider
        FROM asset_universe
        WHERE status = "active"
        ORDER BY updated_at DESC, symbol ASC
        LIMIT 30
        '
    );
}

$flash = getFlashMessage();

renderPageStart(
    'Asset zoeken',
    'Zoek in asset_universe en voeg een asset met één klik toe aan tracked_symbols en bot_symbols.',
    []
);
?>

<style>
.lookup-wrap {
    max-width: 1320px;
    margin: 0 auto;
    padding: 24px;
}

.lookup-card {
    background: rgba(255,255,255,0.88);
    border: 1px solid rgba(255,255,255,0.45);
    border-radius: 18px;
    box-shadow: 0 18px 50px rgba(0,0,0,0.14);
    backdrop-filter: blur(10px);
    padding: 20px;
    margin-bottom: 20px;
}

.lookup-form {
    display: grid;
    grid-template-columns: 1.8fr 180px 160px;
    gap: 12px;
    align-items: end;
}

.lookup-form label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 6px;
}

.lookup-form input,
.lookup-form select {
    width: 100%;
    border: 1px solid #cfd6df;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 14px;
    box-sizing: border-box;
}

.lookup-form button,
.btn {
    display: inline-block;
    border: 0;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}

.btn-primary {
    background: #1f6feb;
    color: #fff;
}

.btn-secondary {
    background: #eef2f7;
    color: #233044;
}

.btn-success {
    background: #1f8f4e;
    color: #fff;
}

.flash {
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 14px;
    margin-bottom: 16px;
}

.flash-success {
    background: #eaf8ef;
    color: #14532d;
    border: 1px solid #b7e2c5;
}

.flash-error {
    background: #fff1f2;
    color: #991b1b;
    border: 1px solid #fecdd3;
}

.lookup-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 10px;
    font-size: 13px;
    color: #445066;
}

.table-wrap {
    overflow-x: auto;
}

.lookup-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 980px;
}

.lookup-table th,
.lookup-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #e6ebf1;
    vertical-align: middle;
    text-align: left;
    font-size: 14px;
}

.lookup-table th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #607086;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: #eef2f7;
    color: #334155;
}

.badge-green {
    background: #eaf8ef;
    color: #14532d;
}

.badge-blue {
    background: #eaf2ff;
    color: #1849a9;
}

.badge-yellow {
    background: #fff7dd;
    color: #8a5a00;
}

.inline-form {
    margin: 0;
}

.asset-title {
    font-weight: 700;
    color: #0f172a;
}

.asset-sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 3px;
}

.empty-state {
    padding: 28px 12px;
    color: #64748b;
    text-align: center;
    font-size: 15px;
}

.top-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

@media (max-width: 920px) {
    .lookup-form {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="lookup-wrap">
    <?php if ($flash): ?>
        <div class="flash <?= $flash['type'] === 'success' ? 'flash-success' : 'flash-error' ?>">
            <?= h((string)$flash['text']) ?>
        </div>
    <?php endif; ?>

    <div class="lookup-card">
        <form method="get" action="<?= h(appUrl('/asset_lookup.php')) ?>" class="lookup-form">
            <div>
                <label for="q">Zoek op symbool of naam</label>
                <input
                    type="text"
                    id="q"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="Bijv. AAPL, ASML, BTC, ETH, Microsoft"
                >
            </div>

            <div>
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">Alles</option>
                    <option value="STOCK" <?= $typeFilter === 'STOCK' ? 'selected' : '' ?>>STOCK</option>
                    <option value="CRYPTO" <?= $typeFilter === 'CRYPTO' ? 'selected' : '' ?>>CRYPTO</option>
                </select>
            </div>

            <div class="top-actions">
                <button type="submit" class="btn btn-primary">Zoeken</button>
                <a class="btn btn-secondary" href="<?= h(appUrl('/asset_lookup.php')) ?>">Reset</a>
            </div>
        </form>

        <div class="lookup-meta">
            <div><strong><?= count($results) ?></strong> resultaten</div>
            <div>Bron: <span class="badge">asset_universe</span></div>
            <div>Toevoegen schrijft naar <span class="badge badge-blue">tracked_symbols</span> en <span class="badge badge-green">bot_symbols</span></div>
        </div>
    </div>

    <div class="lookup-card">
        <div class="table-wrap">
            <table class="lookup-table">
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Type</th>
                        <th>Exchange</th>
                        <th>Currency</th>
                        <th>Provider</th>
                        <th>Status</th>
                        <th>Actie</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($results === []): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            Geen resultaten gevonden.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($results as $row): ?>
                        <?php
                        $symbol       = normalizeSymbol((string)($row['symbol'] ?? ''));
                        $displayName  = trim((string)($row['display_name'] ?? ''));
                        $assetType    = normalizeAssetType((string)($row['asset_type'] ?? 'STOCK'));
                        $exchangeName = trim((string)($row['exchange_name'] ?? ''));
                        $currency     = trim((string)($row['currency'] ?? ''));
                        $provider     = trim((string)($row['provider'] ?? ''));

                        $isTracked = isset($trackedMap[$symbol]);
                        $isBot     = isset($botMap[$symbol]);
                        $isBotActive = $isBot ? ((int)$botMap[$symbol] === 1) : false;
                        ?>
                        <tr>
                            <td>
                                <div class="asset-title"><?= h($symbol) ?></div>
                                <div class="asset-sub"><?= h($displayName !== '' ? $displayName : '—') ?></div>
                            </td>
                            <td>
                                <span class="badge"><?= h($assetType) ?></span>
                            </td>
                            <td><?= h($exchangeName !== '' ? $exchangeName : '—') ?></td>
                            <td><?= h($currency !== '' ? $currency : '—') ?></td>
                            <td><?= h($provider !== '' ? $provider : '—') ?></td>
                            <td>
                                <?php if ($isTracked): ?>
                                    <span class="badge badge-blue">Tracked</span>
                                <?php endif; ?>

                                <?php if ($isBotActive): ?>
                                    <span class="badge badge-green">Bot actief</span>
                                <?php elseif ($isBot): ?>
                                    <span class="badge badge-yellow">Bot aanwezig</span>
                                <?php endif; ?>

                                <?php if (!$isTracked && !$isBot): ?>
                                    <span class="badge">Nog niet toegevoegd</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= h(appUrl('/asset_lookup.php' . ($q !== '' || $typeFilter !== '' ? '?' . http_build_query(array_filter([
                                    'q' => $q,
                                    'type' => $typeFilter,
                                ])) : ''))) ?>" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="oneclick_add">
                                    <input type="hidden" name="symbol" value="<?= h($symbol) ?>">
                                    <input type="hidden" name="display_name" value="<?= h($displayName) ?>">
                                    <input type="hidden" name="asset_type" value="<?= h($assetType) ?>">
                                    <input type="hidden" name="exchange_name" value="<?= h($exchangeName) ?>">
                                    <input type="hidden" name="currency" value="<?= h($currency) ?>">
                                    <input type="hidden" name="provider" value="<?= h($provider) ?>">

                                    <button type="submit" class="btn btn-success">
                                        <?= ($isTracked || $isBot) ? 'Bijwerken / activeren' : 'Toevoegen met 1 klik' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderPageEnd(); ?>