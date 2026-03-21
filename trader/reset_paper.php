<?php
declare(strict_types=1);

require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

try {
    $startBalance = postFloat('start_balance', 10000.00);
    if ($startBalance < 0) {
        $startBalance = 10000.00;
    }

    $pdo->beginTransaction();

    $pdo->exec("DELETE FROM wallet_transactions");
    $pdo->exec("DELETE FROM positions");
    $pdo->exec("DELETE FROM strategy_runs");
    $pdo->exec("DELETE FROM bot_logs");
    $pdo->exec("DELETE FROM trades");

    $st = $pdo->prepare("
        UPDATE wallet
        SET balance = ?,
            reserved_balance = 0.00000000,
            total_deposit = ?,
            total_withdrawal = 0.00000000,
            updated_at = NOW()
        WHERE is_active = 1
    ");
    $st->execute([$startBalance, $startBalance]);

    $st = $pdo->prepare("
        INSERT INTO bot_logs (level, message)
        VALUES (?, ?)
    ");
    $st->execute([
        'INFO',
        'Paper environment reset via dashboard. New start balance: ' . number_format($startBalance, 2, '.', '')
    ]);

    $pdo->commit();

    redirect('dashboard.php?msg=' . urlencode('Paper omgeving gereset.'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    redirect('dashboard.php?err=' . urlencode('Reset mislukt: ' . $e->getMessage()));
}
