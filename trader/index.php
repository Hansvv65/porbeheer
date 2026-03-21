<?php
declare(strict_types=1);

$config = require __DIR__ . '/app/config.php';
$appName = $config['app']['name'] ?? 'Trading Dashboard';
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --panel2: #1f2937;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --accent: #22c55e;
            --border: #334155;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #0f172a, #111827 55%, #1e293b);
            color: var(--text);
            min-height: 100vh;
            display: grid;
            place-items: center;
        }
        .wrap {
            width: min(900px, 92vw);
            background: rgba(17, 24, 39, 0.92);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 40px;
            box-shadow: 0 30px 80px rgba(0,0,0,.35);
        }
        h1 {
            margin: 0 0 12px;
            font-size: 40px;
        }
        p {
            color: var(--muted);
            line-height: 1.6;
            font-size: 18px;
        }
        .cta {
            display: inline-block;
            margin-top: 24px;
            padding: 14px 22px;
            border-radius: 12px;
            background: var(--accent);
            color: #052e16;
            text-decoration: none;
            font-weight: 700;
        }
        .meta {
            margin-top: 18px;
            color: var(--muted);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>
            Beheer hier je trading bot, virtuele wallet, symbols, strategy-runs en trades.
            De Python bot leest en schrijft via dezelfde MySQL-database.
        </p>
        <a class="cta" href="dashboard.php">Open dashboard</a>
        <div class="meta">Losstaande beheeromgeving voor paper trading en latere live trading.</div>
    </div>
</body>
</html>
