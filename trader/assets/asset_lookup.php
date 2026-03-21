<?php
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$allowedTypes = ['stock', 'etf', 'index', 'crypto', 'commodity', 'forex'];
$typeFilter = in_array($type, $allowedTypes, true) ? $type : '';

/*
|--------------------------------------------------------------------------
| Overzicht totals
|--------------------------------------------------------------------------
*/
$summary = [
    'total_active' => 0,
    'stock' => 0,
    'etf' => 0,
    'index' => 0,
    'crypto' => 0,
    'commodity' => 0,
    'forex' => 0,
];

$stmt = $pdo->query("
    SELECT
        COUNT(*) AS total_active,
        SUM(asset_type = 'stock') AS stock_count,
        SUM(asset_type = 'etf') AS etf_count,
        SUM(asset_type = 'index') AS index_count,
        SUM(asset_type = 'crypto') AS crypto_count,
        SUM(asset_type = 'commodity') AS commodity_count,
        SUM(asset_type = 'forex') AS forex_count
    FROM asset_universe
    WHERE status = 'active'
");
$row = $stmt->fetch();
if ($row) {
    $summary['total_active'] = (int)($row['total_active'] ?? 0);
    $summary['stock'] = (int)($row['stock_count'] ?? 0);
    $summary['etf'] = (int)($row['etf_count'] ?? 0);
    $summary['index'] = (int)($row['index_count'] ?? 0);
    $summary['crypto'] = (int)($row['crypto_count'] ?? 0);
    $summary['commodity'] = (int)($row['commodity_count'] ?? 0);
    $summary['forex'] = (int)($row['forex_count'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Delta 7 dagen
|--------------------------------------------------------------------------
*/
$deltaStmt = $pdo->query("
    SELECT
        run_date,
        total_active,
        inserted_count,
        updated_count,
        inactivated_count
    FROM asset_universe_sync_runs
    ORDER BY run_date DESC
    LIMIT 7
");
$deltas = $deltaStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Zoekresultaten
|--------------------------------------------------------------------------
*/
$results = [];

if ($q !== '' || $typeFilter !== '') {
    $sql = "
        SELECT
            symbol,
            display_name,
            asset_type,
            exchange_name,
            currency,
            provider
        FROM asset_universe
        WHERE status = 'active'
    ";

    $params = [];

    if ($q !== '') {
        $sql .= "
          AND (
              symbol LIKE :q
              OR display_name LIKE :q
              OR search_text LIKE :q
          )
        ";
        $params[':q'] = '%' . $q . '%';
        $params[':q_start'] = $q . '%';
    } else {
        $params[':q_start'] = '';
    }

    if ($typeFilter !== '') {
        $sql .= " AND asset_type = :type ";
        $params[':type'] = $typeFilter;
    }

    $sql .= "
        ORDER BY
            CASE WHEN symbol LIKE :q_start THEN 0 ELSE 1 END,
            symbol ASC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Asset lookup</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
:root{
    --bg:#0b1220;
    --panel:#111827;
    --panel2:#172033;
    --line:#263246;
    --text:#e5e7eb;
    --muted:#94a3b8;
    --blue:#2563eb;
    --green:#16a34a;
    --chip:#1e293b;
}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(180deg,#0b1220,#0f172a);color:var(--text)}
.wrap{max-width:1320px;margin:0 auto;padding:28px 18px 40px}
.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;margin-bottom:20px}
h1{margin:0 0 6px;font-size:30px}
.sub{color:var(--muted)}
.actions a{display:inline-block;text-decoration:none;color:#fff;background:var(--blue);padding:11px 14px;border-radius:10px;font-weight:bold}
.card{background:rgba(17,24,39,.92);border:1px solid var(--line);border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.18);margin-bottom:18px}
.summary-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:12px}
.stat{background:#0d1528;border:1px solid var(--line);border-radius:14px;padding:14px}
.stat .label{color:var(--muted);font-size:13px}
.stat .value{font-size:24px;font-weight:bold;margin-top:6px}
.search-grid{display:grid;grid-template-columns:1.5fr 220px 140px;gap:12px}
input[type=text], select{width:100%;padding:14px;border-radius:12px;border:1px solid #334155;background:#0a1020;color:#fff}
button{width:100%;padding:14px;border:0;border-radius:12px;background:var(--blue);color:#fff;font-weight:bold;cursor:pointer}
.quick{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.quick a{text-decoration:none;color:#dbeafe;background:var(--chip);padding:8px 12px;border-radius:999px;font-size:14px}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:12px 10px;text-align:left;border-bottom:1px solid var(--line);vertical-align:top}
th{background:#172033}
tr:hover td{background:#0c1325}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;background:#1f2937;color:#d1d5db}
.use-btn{display:inline-block;background:var(--green);color:#fff;text-decoration:none;padding:9px 12px;border-radius:10px;font-weight:bold;white-space:nowrap}
.empty{padding:20px;color:var(--muted)}
@media (max-width:1100px){
    .summary-grid{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:800px){
    .search-grid{grid-template-columns:1fr}
    .summary-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">

    <div class="topbar">
        <div>
            <h1>Asset lookup</h1>
            <div class="sub">Zoek in de database, bekijk dagelijkse deltas en voeg direct toe aan de dashboard-selectie.</div>
        </div>
        <div class="actions">
            <a href="/dashboard.php">Dashboard</a>
        </div>
    </div>

    <div class="card">
        <div class="summary-grid">
            <div class="stat"><div class="label">Actief totaal</div><div class="value"><?= $summary['total_active'] ?></div></div>
            <div class="stat"><div class="label">Stocks</div><div class="value"><?= $summary['stock'] ?></div></div>
            <div class="stat"><div class="label">ETF</div><div class="value"><?= $summary['etf'] ?></div></div>
            <div class="stat"><div class="label">Indices</div><div class="value"><?= $summary['index'] ?></div></div>
            <div class="stat"><div class="label">Crypto</div><div class="value"><?= $summary['crypto'] ?></div></div>
            <div class="stat"><div class="label">Commodity</div><div class="value"><?= $summary['commodity'] ?></div></div>
            <div class="stat"><div class="label">Forex</div><div class="value"><?= $summary['forex'] ?></div></div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Deltas laatste 7 dagen</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Actief totaal</th>
                        <th>Nieuw</th>
                        <th>Bijgewerkt</th>
                        <th>Inactive</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$deltas): ?>
                    <tr><td colspan="5" class="empty">Nog geen sync-historie beschikbaar.</td></tr>
                <?php else: ?>
                    <?php foreach ($deltas as $d): ?>
                        <tr>
                            <td><?= h($d['run_date']) ?></td>
                            <td><?= (int)$d['total_active'] ?></td>
                            <td><?= (int)$d['inserted_count'] ?></td>
                            <td><?= (int)$d['updated_count'] ?></td>
                            <td><?= (int)$d['inactivated_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <form method="get">
            <div class="search-grid">
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Zoek op symbool, naam of trefwoord...">
                <select name="type">
                    <option value="">Alle types</option>
                    <?php foreach ($allowedTypes as $t): ?>
                        <option value="<?= h($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= h(ucfirst($t)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Zoeken</button>
            </div>
        </form>

        <div class="quick">
            <a href="?q=asml">ASML</a>
            <a href="?q=goud">Goud</a>
            <a href="?q=olie">Olie</a>
            <a href="?q=aardgas">Aardgas</a>
            <a href="?q=bitcoin">Bitcoin</a>
            <a href="?q=nasdaq">NASDAQ</a>
            <a href="?q=eurusd">EUR/USD</a>
        </div>
    </div>

    <?php if ($q !== '' || $typeFilter !== ''): ?>
        <div class="card">
            <h2 style="margin-top:0;">Zoekresultaten</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Symbool</th>
                            <th>Naam</th>
                            <th>Type</th>
                            <th>Beurs</th>
                            <th>Valuta</th>
                            <th>Bron</th>
                            <th>Selecteer</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$results): ?>
                        <tr><td colspan="7" class="empty">Geen resultaten gevonden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <td><strong><?= h($row['symbol']) ?></strong></td>
                                <td><?= h($row['display_name']) ?></td>
                                <td><span class="badge"><?= h($row['asset_type']) ?></span></td>
                                <td><?= h($row['exchange_name']) ?></td>
                                <td><?= h($row['currency']) ?></td>
                                <td><?= h($row['provider']) ?></td>
                                <td>
                                    <a class="use-btn"
                                       href="/symbol_add.php?symbol=<?= urlencode((string)$row['symbol']) ?>&name=<?= urlencode((string)$row['display_name']) ?>&type=<?= urlencode((string)$row['asset_type']) ?>&exchange=<?= urlencode((string)$row['exchange_name']) ?>&provider=<?= urlencode((string)$row['provider']) ?>">
                                        Selecteer
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>
</body>
</html>