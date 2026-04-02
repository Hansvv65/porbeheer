<?php
declare(strict_types=1);

/*
   bot_log.php

   Laat recente logs van de Python bots zien, inclusief errors, warnings,
   run-starts en run-ends.

   Ondersteunde bots:
   - BREAKOUT
   - NEWS_SYNC
   - CLEANUP
   - ASSET_SYNC
   - AI_ADVICE
   - OVERIG
*/

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/functions.php';
require __DIR__ . '/includes/layout.php';

$level = strtoupper(trim((string)getString('level')));
$q = trim((string)getString('q'));
$scope = strtoupper(trim((string)getString('scope')));

$allowedLevels = ['INFO', 'WARNING', 'ERROR'];
$allowedScopes = ['ALL', 'BREAKOUT', 'NEWS', 'CLEANUP', 'ASSET_SYNC', 'AI_ADVICE', 'OTHER'];

if (!in_array($level, $allowedLevels, true)) {
    $level = '';
}
if (!in_array($scope, $allowedScopes, true)) {
    $scope = 'ALL';
}

$limit = min(500, max(50, (int)getInt('limit', 200)));

$botCaseSql = "
    CASE
        WHEN message LIKE 'BREAKOUT %' THEN 'BREAKOUT'
        WHEN message LIKE 'NEWS_SYNC %' THEN 'NEWS_SYNC'
        WHEN message LIKE 'CLEANUP %' THEN 'CLEANUP'
        WHEN message LIKE 'ASSET_SYNC %' THEN 'ASSET_SYNC'
        WHEN message LIKE 'AI_ADVICE %' THEN 'AI_ADVICE'
        ELSE 'OVERIG'
    END
";

$sql = "
    SELECT
        id,
        level,
        message,
        created_at,
        $botCaseSql AS bot_name
    FROM bot_logs
    WHERE 1=1
";

$params = [];

if ($level !== '') {
    $sql .= ' AND level = ?';
    $params[] = $level;
}

if ($scope === 'BREAKOUT') {
    $sql .= " AND ($botCaseSql) = 'BREAKOUT'";
} elseif ($scope === 'NEWS') {
    $sql .= " AND ($botCaseSql) = 'NEWS_SYNC'";
} elseif ($scope === 'CLEANUP') {
    $sql .= " AND ($botCaseSql) = 'CLEANUP'";
} elseif ($scope === 'ASSET_SYNC') {
    $sql .= " AND ($botCaseSql) = 'ASSET_SYNC'";
} elseif ($scope === 'AI_ADVICE') {
    $sql .= " AND ($botCaseSql) = 'AI_ADVICE'";
} elseif ($scope === 'OTHER') {
    $sql .= " AND ($botCaseSql) = 'OVERIG'";
}

if ($q !== '') {
    $sql .= ' AND message LIKE ?';
    $params[] = '%' . $q . '%';
}

$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit;

$rows = fetchAllRows($pdo, $sql, $params);

$summary = fetchOne($pdo, "
    SELECT
        COUNT(*) AS total_logs,
        COALESCE(SUM(level = 'ERROR'), 0) AS error_count,
        COALESCE(SUM(level = 'WARNING'), 0) AS warning_count,
        COALESCE(SUM(($botCaseSql) = 'BREAKOUT'), 0) AS breakout_count,
        COALESCE(SUM(($botCaseSql) = 'NEWS_SYNC'), 0) AS news_count,
        COALESCE(SUM(($botCaseSql) = 'CLEANUP'), 0) AS cleanup_count,
        COALESCE(SUM(($botCaseSql) = 'ASSET_SYNC'), 0) AS asset_sync_count,
        COALESCE(SUM(($botCaseSql) = 'AI_ADVICE'), 0) AS ai_advice_count,
        COALESCE(SUM(($botCaseSql) = 'OVERIG'), 0) AS other_count,
        MAX(created_at) AS last_log
    FROM bot_logs
") ?: [];

function badgeClassForBot(?string $bot): string
{
    return match (strtoupper((string)$bot)) {
        'BREAKOUT'  => 'badge positive',
        'NEWS_SYNC' => 'badge warning',
        'CLEANUP'   => 'badge neutral',
        'ASSET_SYNC'=> 'badge neutral',
        'AI_ADVICE' => 'badge warning',
        default     => 'badge neutral',
    };
}

renderPageStart(
    'Bot log',
    'Hier zie je wat de Python bots recent gedaan hebben, inclusief starts, ends, warnings en errors.',
    [
        ['href' => appUrl('/dashboard.php'), 'label' => 'Dashboard'],
    ]
);
?>
<div class="grid">
    <div class="card span-12">
        <h2>Samenvatting</h2>

        <div class="stats stats-4">
            <div class="stat">
                <div class="label">Totaal logs</div>
                <div class="value"><?= (int)($summary['total_logs'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">Warnings</div>
                <div class="value"><?= (int)($summary['warning_count'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">Errors</div>
                <div class="value"><?= (int)($summary['error_count'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">Laatste log</div>
                <div class="value small"><?= formatDateTime($summary['last_log'] ?? null) ?></div>
            </div>
        </div>

        <div class="stats stats-4" style="margin-top:12px;">
            <div class="stat">
                <div class="label">BREAKOUT</div>
                <div class="value"><?= (int)($summary['breakout_count'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">NEWS_SYNC</div>
                <div class="value"><?= (int)($summary['news_count'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">CLEANUP</div>
                <div class="value"><?= (int)($summary['cleanup_count'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">ASSET_SYNC</div>
                <div class="value"><?= (int)($summary['asset_sync_count'] ?? 0) ?></div>
            </div>
        </div>

        <div class="stats stats-4" style="margin-top:12px;">
            <div class="stat">
                <div class="label">AI_ADVICE</div>
                <div class="value"><?= (int)($summary['ai_advice_count'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="label">Overig</div>
                <div class="value"><?= (int)($summary['other_count'] ?? 0) ?></div>
            </div>
        </div>
    </div>

    <div class="card span-12">
        <h2>Filter</h2>

        <form method="get">
            <div class="row-3">
                <div>
                    <label for="level">Level</label>
                    <select id="level" name="level">
                        <option value="">Alles</option>
                        <?php foreach ($allowedLevels as $item): ?>
                            <option value="<?= h($item) ?>" <?= $level === $item ? 'selected' : '' ?>>
                                <?= h($item) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="scope">Bot</label>
                    <select id="scope" name="scope">
                        <option value="ALL" <?= $scope === 'ALL' ? 'selected' : '' ?>>Alles</option>
                        <option value="BREAKOUT" <?= $scope === 'BREAKOUT' ? 'selected' : '' ?>>BREAKOUT</option>
                        <option value="NEWS" <?= $scope === 'NEWS' ? 'selected' : '' ?>>NEWS_SYNC</option>
                        <option value="CLEANUP" <?= $scope === 'CLEANUP' ? 'selected' : '' ?>>CLEANUP</option>
                        <option value="ASSET_SYNC" <?= $scope === 'ASSET_SYNC' ? 'selected' : '' ?>>ASSET_SYNC</option>
                        <option value="AI_ADVICE" <?= $scope === 'AI_ADVICE' ? 'selected' : '' ?>>AI_ADVICE</option>
                        <option value="OTHER" <?= $scope === 'OTHER' ? 'selected' : '' ?>>Overig</option>
                    </select>
                </div>

                <div>
                    <label for="q">Tekst bevat</label>
                    <input
                        type="text"
                        id="q"
                        name="q"
                        value="<?= h($q) ?>"
                        placeholder="bijv. ASML, status=FAILED, symbol=NVDA"
                    >
                </div>
            </div>

            <div class="row-3" style="margin-top:12px;">
                <div>
                    <label for="limit">Max regels</label>
                    <input type="number" id="limit" name="limit" min="50" max="500" value="<?= $limit ?>">
                </div>

                <div style="display:flex;align-items:end;">
                    <button type="submit">Filter toepassen</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card span-12">
        <h2>Recente bot logs</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tijd</th>
                        <th>Bot</th>
                        <th>Level</th>
                        <th>Bericht</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="4" class="muted">Geen logs gevonden.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= formatDateTime($row['created_at'] ?? null) ?></td>
                            <td>
                                <span class="<?= badgeClassForBot($row['bot_name'] ?? null) ?>">
                                    <?= h((string)($row['bot_name'] ?? 'OVERIG')) ?>
                                </span>
                            </td>
                            <td>
                                <span class="<?= badgeClassForAction($row['level'] ?? null) ?>">
                                    <?= h((string)($row['level'] ?? '')) ?>
                                </span>
                            </td>
                            <td class="code"><?= h((string)($row['message'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>