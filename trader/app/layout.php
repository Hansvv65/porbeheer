<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function renderPageStart(string $title, string $subtitle = ''): void
{
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title><?= h($title) ?></title>

<style>

body{
    margin:0;
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell;
    background: linear-gradient(135deg,#eef2f7,#e6ebf3);
}

.topbar{
    background:white;
    border-bottom:1px solid #d7dde6;
    padding:14px 24px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.brand{
    font-size:18px;
    font-weight:700;
    color:#0f172a;
}

.nav a{
    margin-left:16px;
    text-decoration:none;
    color:#1f6feb;
    font-weight:600;
}

.page{
    max-width:1400px;
    margin:24px auto;
    padding:0 20px;
}

.page-title{
    font-size:28px;
    font-weight:700;
    margin-bottom:4px;
}

.page-sub{
    color:#667085;
    margin-bottom:20px;
}

.card{
    background:white;
    border-radius:14px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 6px 20px rgba(0,0,0,.08);
}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    padding:10px;
    border-bottom:1px solid #edf1f6;
    text-align:left;
}

th{
    font-size:12px;
    text-transform:uppercase;
    color:#667085;
}

.btn{
    background:#1f6feb;
    color:white;
    padding:8px 12px;
    border-radius:8px;
    border:none;
    cursor:pointer;
    text-decoration:none;
    font-weight:600;
}

.btn:hover{
    opacity:.9;
}

.badge{
    background:#eef2f7;
    padding:4px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:600;
}

.badge-green{
    background:#eaf8ef;
    color:#166534;
}

.badge-blue{
    background:#eaf2ff;
    color:#1849a9;
}

</style>

</head>

<body>

<div class="topbar">
<div class="brand">Trading dashboard</div>

<div class="nav">
<a href="/dashboard.php">Dashboard</a>
<a href="/asset_lookup.php">Assets</a>
<a href="/bot_log.php">Bot log</a>
</div>

</div>

<div class="page">

<div class="page-title"><?= h($title) ?></div>

<?php if($subtitle): ?>
<div class="page-sub"><?= h($subtitle) ?></div>
<?php endif; ?>

<?php
}

function renderPageEnd(): void
{
?>
</div>
</body>
</html>
<?php
}