<?php
declare(strict_types=1);

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/functions.php';

if (!isPost()) {
    http_response_code(405);
    exit('Method not allowed');
}

requireCsrf();

try {
    $startBalance = postFloat('start_balance', 10000.00);
    if ($startBalance < 0) {
        $startBalance = 10000.00;
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM wallet_transactions');
    $pdo->exec('DELETE FROM positions');
    $pdo->exec('DELETE FROM strategy_runs');
    $pdo->exec('DELETE FROM bot_logs');
    $pdo->exec('DELETE FROM trades');

    executeSql($pdo, 'UPDATE wallet SET balance = ?, reserved_balance = 0.00000000, total_deposit = ?, total_withdrawal = 0.00000000, updated_at = NOW() WHERE is_active = 1', [$startBalance, $startBalance]);
    executeSql($pdo, 'INSERT INTO bot_logs (level, message) VALUES (?, ?)', ['INFO', 'Paper reset via dashboard. Start balance: ' . number_format($startBalance, 2, '.', '')]);

    $pdo->commit();
    redirectBackWithFlash('success', 'Paper omgeving is gereset.', appUrl('/dashboard.php'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('reset_paper failed: ' . $e->getMessage());
    redirectBackWithFlash('error', 'Reset mislukt.', appUrl('/dashboard.php'));
}
