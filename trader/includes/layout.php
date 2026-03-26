<?php
/* includes/layout.php */
declare(strict_types=1);

require_once __DIR__ . '/../app/functions.php';

function mainNavigation(): array
{
    return [
        ['href' => appUrl('/dashboard.php'), 'label' => 'Dashboard'],
        ['href' => appUrl('/symbols.php'), 'label' => 'Assets'],
        ['href' => appUrl('/asset_lookup.php'), 'label' => 'Asset zoeken'],
        ['href' => appUrl('/bot_log.php'), 'label' => 'Bot log'],
    ];
}

function renderPageStart(string $title, string $subtitle = '', array $actions = []): void
{
    $flash = pullFlash();
    ?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(pageTitle($title)) ?></title>
    <link rel="stylesheet" href="<?= h(appUrl('/assets/style.css')) ?>">
</head>
<body>
<div class="container">
    <div class="sitebar">
        <div class="brandblock">
            <div class="eyebrow">Trading project</div>
            <div class="brandtitle"><?= h((string)(config()['app']['name'] ?? 'Trading Dashboard')) ?></div>
        </div>
        <div class="nav-actions nav-main">
            <?php foreach (mainNavigation() as $item): ?>
                <a class="btn-link btn-link-secondary" href="<?= h((string)$item['href']) ?>"><?= h((string)$item['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="topbar topbar-stack">
        <div>
            <h1><?= h($title) ?></h1>
            <?php if ($subtitle !== ''): ?>
                <div class="sub"><?= h($subtitle) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($actions): ?>
            <div class="nav-actions">
                <?php foreach ($actions as $action): ?>
                    <a class="btn-link <?= !empty($action['secondary']) ? 'btn-link-secondary' : '' ?>" href="<?= h((string)$action['href']) ?>">
                        <?= h((string)$action['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?= h((string)($flash['type'] ?? 'info')) ?>">
            <?= h((string)($flash['message'] ?? '')) ?>
        </div>
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
