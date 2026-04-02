<?php
declare(strict_types=1);

/* asset_analysis.php
Onafhankelijke pagina voor diepgaande analyse per asset:
koersverloop, signalen, strategy runs, trades en nieuws.
*/

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/functions.php';
require_once __DIR__ . '/includes/layout.php';

$symbol = strtoupper(trim((string)getString('symbol')));
if ($symbol === '' || !isValidSymbolFormat($symbol)) {
    http_response_code(400);
    exit('Ongeldig of ontbrekend symbool.');
}

$asset = fetchOne($pdo, '
    SELECT
        b.symbol,
        b.asset_type,
        b.data_symbol,
        b.order_symbol,
        b.is_active,
        b.priority_order,
        t.display_name,
        t.exchange_name,
        u.currency,
        u.provider
    FROM bot_symbols b
    LEFT JOIN tracked_symbols t ON t.symbol = b.symbol
    LEFT JOIN asset_universe u ON u.symbol = b.symbol
    WHERE b.symbol = ?
    LIMIT 1
', [$symbol]);

if (!$asset) {
    $asset = fetchOne($pdo, '
        SELECT
            u.symbol,
            u.display_name,
            UPPER(COALESCE(u.asset_type, "STOCK")) AS asset_type,
            NULL AS data_symbol,
            NULL AS order_symbol,
            0 AS is_active,
            NULL AS priority_order,
            u.exchange_name,
            u.currency,
            u.provider
        FROM asset_universe u
        WHERE u.symbol = ?
        LIMIT 1
    ', [$symbol]);
}

if (!$asset) {
    http_response_code(404);
    exit('Asset niet gevonden.');
}

$openPosition = fetchOne(
    $pdo,
    "SELECT * FROM positions WHERE symbol = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1",
    [$symbol]
);

$latestSnapshot = fetchOne(
    $pdo,
    'SELECT * FROM asset_snapshots WHERE symbol = ? ORDER BY snapshot_time DESC, id DESC LIMIT 1',
    [$symbol]
);

$signals = fetchAllRows(
    $pdo,
    'SELECT * FROM asset_signal_log WHERE symbol = ? ORDER BY signal_time DESC, id DESC LIMIT 30',
    [$symbol]
);

$runs = fetchAllRows(
    $pdo,
    'SELECT * FROM strategy_runs WHERE symbol = ? ORDER BY created_at DESC, id DESC LIMIT 30',
    [$symbol]
);

$trades = fetchAllRows(
    $pdo,
    'SELECT * FROM trades WHERE asset = ? ORDER BY timestamp DESC, id DESC LIMIT 30',
    [$symbol]
);

$newsItems = fetchAllRows(
    $pdo,
    'SELECT
        id,
        symbol,
        published_at,
        title,
        summary,
        url,
        source_name,
        source_provider,
        language_code,
        sentiment_score,
        sentiment_label,
        importance_score,
        market_relevance,
        created_at
     FROM asset_news
     WHERE symbol = ? OR symbol IS NULL
     ORDER BY
        CASE WHEN symbol = ? THEN 0 ELSE 1 END,
        published_at DESC,
        id DESC
     LIMIT 20',
    [$symbol, $symbol]
);

$stats = fetchOne(
    $pdo,
    'SELECT
        COUNT(*) AS total_points,
        MIN(price) AS min_price,
        MAX(price) AS max_price,
        AVG(price) AS avg_price
     FROM asset_snapshots
     WHERE symbol = ?
       AND snapshot_time >= (NOW() - INTERVAL 7 DAY)',
    [$symbol]
) ?: [];

/*
|--------------------------------------------------------------------------
| Grafiekdata: laatste 100 meetpunten
|--------------------------------------------------------------------------
*/
$chartRows = fetchAllRows(
    $pdo,
    'SELECT snapshot_time, price
     FROM asset_snapshots
     WHERE symbol = ?
     ORDER BY snapshot_time ASC, id ASC
     LIMIT 100',
    [$symbol]
);

function buildSvgLineChart(array $rows, int $width = 1100, int $height = 280): string
{
    if (count($rows) < 2) {
        return '<div class="muted">Nog te weinig meetpunten voor een grafiek.</div>';
    }

    $prices = [];
    foreach ($rows as $row) {
        $price = isset($row['price']) ? (float)$row['price'] : null;
        if ($price !== null) {
            $prices[] = $price;
        }
    }

    if (count($prices) < 2) {
        return '<div class="muted">Nog te weinig bruikbare koersdata voor een grafiek.</div>';
    }

    $min = min($prices);
    $max = max($prices);

    if ($max <= $min) {
        $max = $min + 1.0;
    }

    $paddingLeft = 58;
    $paddingRight = 18;
    $paddingTop = 18;
    $paddingBottom = 34;

    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;

    $count = count($rows);
    $points = [];
    $labels = [];

    foreach ($rows as $index => $row) {
        $price = (float)($row['price'] ?? 0);
        $x = $paddingLeft + ($count > 1 ? ($index / ($count - 1)) * $plotWidth : 0);
        $yRatio = ($price - $min) / ($max - $min);
        $y = $paddingTop + $plotHeight - ($yRatio * $plotHeight);

        $points[] = round($x, 2) . ',' . round($y, 2);

        $labels[] = [
            'x' => $x,
            'y' => $y,
            'price' => $price,
            'time' => (string)($row['snapshot_time'] ?? ''),
        ];
    }

    $polyline = implode(' ', $points);

    $gridLines = '';
    for ($i = 0; $i <= 4; $i++) {
        $y = $paddingTop + ($plotHeight / 4) * $i;
        $gridLines .= '<line x1="' . $paddingLeft . '" y1="' . round($y, 2) . '" x2="' . ($paddingLeft + $plotWidth) . '" y2="' . round($y, 2) . '" class="chart-grid" />';
    }

    $axisLabels = '';
    for ($i = 0; $i <= 4; $i++) {
        $value = $max - (($max - $min) / 4) * $i;
        $y = $paddingTop + ($plotHeight / 4) * $i + 4;
        $axisLabels .= '<text x="8" y="' . round($y, 2) . '" class="chart-axis-label">' . htmlspecialchars(number_format($value, 4, ',', '.'), ENT_QUOTES, 'UTF-8') . '</text>';
    }

    $tickLabels = '';
    $tickIndexes = [0, (int)floor(($count - 1) / 2), $count - 1];
    $tickIndexes = array_values(array_unique($tickIndexes));

    foreach ($tickIndexes as $idx) {
        if (!isset($rows[$idx])) {
            continue;
        }
        $x = $paddingLeft + ($count > 1 ? ($idx / ($count - 1)) * $plotWidth : 0);
        $time = (string)($rows[$idx]['snapshot_time'] ?? '');
        $label = $time !== '' ? date('d-m H:i', strtotime($time)) : '';
        $tickLabels .= '<text x="' . round($x, 2) . '" y="' . ($paddingTop + $plotHeight + 22) . '" text-anchor="middle" class="chart-axis-label">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</text>';
    }

    $dots = '';
    foreach ($labels as $i => $p) {
        if ($i % 8 !== 0 && $i !== array_key_last($labels)) {
            continue;
        }

        $title = date('d-m-Y H:i', strtotime($p['time'])) . ' · ' . number_format($p['price'], 6, ',', '.');
        $dots .= '<circle cx="' . round($p['x'], 2) . '" cy="' . round($p['y'], 2) . '" r="3" class="chart-dot">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '</circle>';
    }

    return '
    <svg viewBox="0 0 ' . $width . ' ' . $height . '" class="chart-svg" role="img" aria-label="Koersgrafiek">
        ' . $gridLines . '
        ' . $axisLabels . '
        <polyline points="' . $polyline . '" class="chart-line" />
        ' . $dots . '
        ' . $tickLabels . '
    </svg>';
}

function safeExternalUrl(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $url)) {
        return '';
    }
    return $url;
}

function badgeClassForSentiment(?string $value): string
{
    $value = strtoupper((string)$value);
    return match ($value) {
        'POSITIVE' => 'badge positive',
        'NEGATIVE' => 'badge negative',
        'MIXED' => 'badge warning',
        'NEUTRAL' => 'badge neutral',
        default => 'badge neutral',
    };
}

function badgeClassForRelevance(?string $value): string
{
    $value = strtoupper((string)$value);
    return match ($value) {
        'HIGH' => 'badge positive',
        'LOW' => 'badge neutral',
        default => 'badge warning',
    };
}

$currentPrice = $latestSnapshot['price'] ?? null;
$avgPrice = $openPosition['avg_price'] ?? null;
$quantity = $openPosition['quantity'] ?? null;

$unrealizedValue = null;
$unrealizedPct = null;

if (
    $currentPrice !== null &&
    $avgPrice !== null &&
    $quantity !== null &&
    (float)$avgPrice > 0
) {
    $unrealizedValue = ((float)$currentPrice - (float)$avgPrice) * (float)$quantity;
    $unrealizedPct = (((float)$currentPrice - (float)$avgPrice) / (float)$avgPrice) * 100;
}

renderPageStart(
    'Asset analyse · ' . $symbol,
    'Verdieping per asset: status, koersverloop, signalen, strategy-runs, trades en nieuws.',
    [
        ['href' => appUrl('/dashboard.php'), 'label' => 'Dashboard', 'secondary' => true],
        ['href' => appUrl('/asset_lookup.php?q=' . urlencode($symbol)), 'label' => 'Zoeken', 'secondary' => true],
        ['href' => appUrl('/bot_log.php'), 'label' => 'Bot log', 'secondary' => true],
    ]
);
?>

<style>
.chart-wrap{
    width:100%;
    overflow-x:auto;
    padding-top:8px;
}
.chart-svg{
    width:100%;
    min-height:280px;
    display:block;
}
.chart-grid{
    stroke:#e6ebf1;
    stroke-width:1;
}
.chart-line{
    fill:none;
    stroke:#1f6feb;
    stroke-width:3;
    stroke-linejoin:round;
    stroke-linecap:round;
}
.chart-dot{
    fill:#1f6feb;
}
.chart-axis-label{
    fill:#64748b;
    font-size:12px;
}
.news-title{
    font-weight:600;
    line-height:1.4;
}
.news-summary{
    margin-top:6px;
    line-height:1.5;
}
</style>

<div class="grid">
    <div class="card span-12">
        <h2>
            <?= h($symbol) ?>
            <?php if (!empty($asset['display_name'])): ?>
                · <?= h((string)$asset['display_name']) ?>
            <?php endif; ?>
        </h2>

        <div class="sub">
            Type <?= h((string)($asset['asset_type'] ?? '-')) ?>
            · Data symbool <span class="code"><?= h((string)($asset['data_symbol'] ?? $symbol)) ?></span>
            · Order symbool <span class="code"><?= h((string)($asset['order_symbol'] ?? $symbol)) ?></span>
            · Exchange <?= h((string)($asset['exchange_name'] ?? '-')) ?>
            · Currency <?= h((string)($asset['currency'] ?? '-')) ?>
            · Provider <?= h((string)($asset['provider'] ?? '-')) ?>
            · Bot actief <?= !empty($asset['is_active']) ? 'JA' : 'NEE' ?>
        </div>
    </div>

    <div class="card span-12">
        <h2>Samenvatting</h2>
        <div class="stats stats-4">
            <div class="stat"><div class="label">Laatste prijs</div><div class="value small"><?= formatPrice($currentPrice, 4) ?></div></div>
            <div class="stat"><div class="label">Trend</div><div class="value small"><span class="<?= badgeClassForTrend($latestSnapshot['trend_state'] ?? null) ?>"><?= h((string)($latestSnapshot['trend_state'] ?? 'ONBEKEND')) ?></span></div></div>
            <div class="stat"><div class="label">Trend score</div><div class="value small"><?= h((string)($latestSnapshot['trend_score'] ?? '-')) ?></div></div>
            <div class="stat"><div class="label">Breakout</div><div class="value small"><?= !empty($latestSnapshot['breakout']) ? 'JA' : 'NEE' ?></div></div>
            <div class="stat"><div class="label">24u wijziging</div><div class="value small"><?= formatPct($latestSnapshot['change_24h'] ?? null, 2) ?></div></div>
            <div class="stat"><div class="label">Open positie</div><div class="value small"><?= $openPosition ? 'JA' : 'NEE' ?></div></div>
            <div class="stat"><div class="label">Aantal</div><div class="value small"><?= formatQty($quantity, 8) ?></div></div>
            <div class="stat"><div class="label">Gem. aankoop</div><div class="value small"><?= formatPrice($avgPrice, 4) ?></div></div>
            <div class="stat"><div class="label">Ongerealiseerd resultaat</div><div class="value small"><?= formatPrice($unrealizedValue, 4) ?></div></div>
            <div class="stat"><div class="label">Ongerealiseerd %</div><div class="value small"><?= formatPct($unrealizedPct, 2) ?></div></div>
        </div>
        <div class="panel-note">
            Meetpunten afgelopen 7 dagen:
            <?= (int)($stats['total_points'] ?? 0) ?>
            · min <?= formatPrice($stats['min_price'] ?? null, 4) ?>
            · max <?= formatPrice($stats['max_price'] ?? null, 4) ?>
            · gemiddeld <?= formatPrice($stats['avg_price'] ?? null, 4) ?>
        </div>
    </div>

    <div class="card span-12">
        <h2>Koersgrafiek</h2>
        <div class="chart-wrap">
            <?= buildSvgLineChart($chartRows) ?>
        </div>
    </div>

    <div class="card span-12">
        <h2>Laatste nieuws</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tijd</th>
                        <th>Titel / samenvatting</th>
                        <th>Bron</th>
                        <th>Relevantie</th>
                        <th>Sentiment</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$newsItems): ?>
                    <tr><td colspan="5" class="muted">Nog geen nieuws gevonden voor dit asset.</td></tr>
                <?php else: foreach ($newsItems as $row): ?>
                    <?php $newsUrl = safeExternalUrl($row['url'] ?? null); ?>
                    <tr>
                        <td><?= formatDateTime($row['published_at'] ?? null) ?></td>
                        <td>
                            <div class="news-title">
                                <?php if ($newsUrl !== ''): ?>
                                    <a href="<?= h($newsUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)($row['title'] ?? '')) ?></a>
                                <?php else: ?>
                                    <?= h((string)($row['title'] ?? '')) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($row['summary'])): ?>
                                <div class="news-summary muted"><?= h((string)$row['summary']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h((string)($row['source_name'] ?? '-')) ?>
                            <?php if (!empty($row['source_provider'])): ?>
                                <div class="muted small"><?= h((string)$row['source_provider']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= badgeClassForRelevance($row['market_relevance'] ?? null) ?>">
                                <?= h((string)($row['market_relevance'] ?? 'MEDIUM')) ?>
                            </span>
                            <?php if ($row['importance_score'] !== null): ?>
                                <div class="muted small"><?= h((string)$row['importance_score']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['sentiment_label'])): ?>
                                <span class="<?= badgeClassForSentiment($row['sentiment_label'] ?? null) ?>">
                                    <?= h((string)$row['sentiment_label']) ?>
                                </span>
                                <?php if ($row['sentiment_score'] !== null): ?>
                                    <div class="muted small"><?= h((string)$row['sentiment_score']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card span-6">
        <h2>Laatste signalen</h2>
        <div class="table-wrap"><table><thead><tr><th>Tijd</th><th>Type</th><th>Trend</th><th>Score</th><th>Reden</th></tr></thead><tbody>
        <?php if (!$signals): ?><tr><td colspan="5" class="muted">Geen signalen gevonden.</td></tr><?php else: foreach ($signals as $row): ?>
            <tr>
                <td><?= formatDateTime($row['signal_time'] ?? null) ?></td>
                <td><span class="<?= badgeClassForAction($row['signal_type'] ?? null) ?>"><?= h((string)($row['signal_type'] ?? '')) ?></span></td>
                <td><span class="<?= badgeClassForTrend($row['trend_state'] ?? null) ?>"><?= h((string)($row['trend_state'] ?? '')) ?></span></td>
                <td><?= h((string)($row['trend_score'] ?? '')) ?></td>
                <td><?= h((string)($row['signal_reason'] ?? '')) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>

    <div class="card span-6">
        <h2>Laatste strategy runs</h2>
        <div class="table-wrap"><table><thead><tr><th>Tijd</th><th>Prijs</th><th>Prev high</th><th>Breakout</th><th>Actie</th><th>Notitie</th></tr></thead><tbody>
        <?php if (!$runs): ?><tr><td colspan="6" class="muted">Geen runs gevonden.</td></tr><?php else: foreach ($runs as $row): ?>
            <tr>
                <td><?= formatDateTime($row['created_at'] ?? null) ?></td>
                <td><?= formatPrice($row['current_price'] ?? null, 4) ?></td>
                <td><?= formatPrice($row['previous_high'] ?? null, 4) ?></td>
                <td><?= !empty($row['breakout']) ? 'JA' : 'NEE' ?></td>
                <td><span class="<?= badgeClassForAction($row['action_taken'] ?? null) ?>"><?= h((string)($row['action_taken'] ?? '')) ?></span></td>
                <td><?= h((string)($row['notes'] ?? '')) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>

    <div class="card span-12">
        <h2>Trades</h2>
        <div class="table-wrap"><table><thead><tr><th>Tijd</th><th>Type</th><th>Prijs</th><th>Aantal/amount</th><th>P/L</th><th>Notitie</th></tr></thead><tbody>
        <?php if (!$trades): ?><tr><td colspan="6" class="muted">Nog geen trades.</td></tr><?php else: foreach ($trades as $row): ?>
            <tr>
                <td><?= formatDateTime($row['timestamp'] ?? null) ?></td>
                <td><span class="<?= badgeClassForAction($row['type'] ?? null) ?>"><?= h((string)($row['type'] ?? '')) ?></span></td>
                <td><?= formatPrice($row['price'] ?? null, 4) ?></td>
                <td><?= formatQty($row['amount'] ?? null, 8) ?></td>
                <td><?= formatPrice($row['profit_loss'] ?? null, 4) ?></td>
                <td><?= h((string)($row['notes'] ?? '')) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>
</div>

<?php renderPageEnd(); ?>