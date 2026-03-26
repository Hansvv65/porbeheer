<?php
declare(strict_types=1);

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/functions.php';
require __DIR__ . '/includes/layout.php';

$level = strtoupper(getString('level'));
$q = getString('q');
$allowedLevels = ['INFO', 'WARNING', 'ERROR'];
if (!in_array($level, $allowedLevels, true)) {
    $level = '';
}
$limit = min(500, max(50, getInt('limit', 200)));

$sql = 'SELECT id, level, message, created_at FROM bot_logs WHERE 1=1';
$params = [];
if ($level !== '') {
    $sql .= ' AND level = ?';
    $params[] = $level;
}
if ($q !== '') {
    $sql .= ' AND message LIKE ?';
    $params[] = '%' . $q . '%';
}
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit;
$rows = fetchAllRows($pdo, $sql, $params);
$summary = fetchOne($pdo, 'SELECT COUNT(*) AS total_logs, SUM(level = "ERROR") AS error_count, SUM(level = "WARNING") AS warning_count, MAX(created_at) AS last_log FROM bot_logs') ?: [];

renderPageStart('Bot log', 'Hier zie je wat de Python bots recent gedaan hebben, inclusief errors, warnings en run-starts.', [
    ['href' => appUrl('/dashboard.php'), 'label' => 'Dashboard'],
]);
?>
<div class="grid">
    <div class="card span-12">
        <h2>Samenvatting</h2>
        <div class="stats stats-4">
            <div class="stat"><div class="label">Totaal logs</div><div class="value"><?= (int)($summary['total_logs'] ?? 0) ?></div></div>
            <div class="stat"><div class="label">Warnings</div><div class="value"><?= (int)($summary['warning_count'] ?? 0) ?></div></div>
            <div class="stat"><div class="label">Errors</div><div class="value"><?= (int)($summary['error_count'] ?? 0) ?></div></div>
            <div class="stat"><div class="label">Laatste log</div><div class="value small"><?= formatDateTime($summary['last_log'] ?? null) ?></div></div>
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
                            <option value="<?= h($item) ?>" <?= $level === $item ? 'selected' : '' ?>><?= h($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="q">Tekst bevat</label>
                    <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="bijv. breakout, BTC, fout">
                </div>
                <div>
                    <label for="limit">Max regels</label>
                    <input type="number" id="limit" name="limit" min="50" max="500" value="<?= $limit ?>">
                </div>
            </div>
            <button type="submit">Filter toepassen</button>
        </form>
    </div>

    <div class="card span-12">
        <h2>Recente bot logs</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tijd</th><th>Level</th><th>Bericht</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="3" class="muted">Geen logs gevonden.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?= formatDateTime($row['created_at'] ?? null) ?></td>
                        <td><span class="<?= badgeClassForAction($row['level'] ?? null) ?>"><?= h((string)$row['level']) ?></span></td>
                        <td class="code"><?= h((string)$row['message']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php renderPageEnd(); ?>
