<?php
/* symbol_delete.php */
declare(strict_types=1);

require_once __DIR__ . '/app/db.php';

function redirectToSymbols(string $msg = '', string $type = 'success'): never
{
    $url = '/symbols.php';

    if ($msg !== '') {
        $url .= '?msg=' . urlencode($msg) . '&type=' . urlencode($type);
    }

    header('Location: ' . $url);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$trackedId = (int)($_GET['tracked_id'] ?? 0);
$botId = (int)($_GET['bot_id'] ?? 0);
$scope = strtolower(trim((string)($_GET['scope'] ?? 'tracked')));
$symbol = strtoupper(trim((string)($_GET['symbol'] ?? '')));

$allowedScopes = ['tracked', 'bot', 'both'];
if (!in_array($scope, $allowedScopes, true)) {
    $scope = 'tracked';
}

try {
    $pdo->beginTransaction();

    $deletedTracked = 0;
    $deletedBot = 0;

    /*
    |--------------------------------------------------------------------------
    | Backward compatibility:
    | oude links gebruikten ?id=123 en dat bedoelde tracked_symbols.id
    |--------------------------------------------------------------------------
    */
    if ($id > 0 && $trackedId === 0 && $botId === 0 && $symbol === '') {
        $trackedId = $id;
        $scope = 'tracked';
    }

    /*
    |--------------------------------------------------------------------------
    | Verwijderen op basis van expliciete ids
    |--------------------------------------------------------------------------
    */
    if (($scope === 'tracked' || $scope === 'both') && $trackedId > 0) {
        $stmt = $pdo->prepare("DELETE FROM tracked_symbols WHERE id = ?");
        $stmt->execute([$trackedId]);
        $deletedTracked += $stmt->rowCount();
    }

    if (($scope === 'bot' || $scope === 'both') && $botId > 0) {
        $stmt = $pdo->prepare("DELETE FROM bot_symbols WHERE id = ?");
        $stmt->execute([$botId]);
        $deletedBot += $stmt->rowCount();
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback: verwijderen op symbool
    |--------------------------------------------------------------------------
    */
    if ($symbol !== '') {
        if ($scope === 'tracked' || $scope === 'both') {
            $stmt = $pdo->prepare("DELETE FROM tracked_symbols WHERE symbol = ?");
            $stmt->execute([$symbol]);
            $deletedTracked += $stmt->rowCount();
        }

        if ($scope === 'bot' || $scope === 'both') {
            $stmt = $pdo->prepare("DELETE FROM bot_symbols WHERE symbol = ?");
            $stmt->execute([$symbol]);
            $deletedBot += $stmt->rowCount();
        }
    }

    $pdo->commit();

    if ($deletedTracked === 0 && $deletedBot === 0) {
        redirectToSymbols('Geen record verwijderd.', 'info');
    }

    if ($deletedTracked > 0 && $deletedBot > 0) {
        redirectToSymbols('Symbool verwijderd uit tracked_symbols en bot_symbols.', 'success');
    }

    if ($deletedTracked > 0) {
        redirectToSymbols('Symbool verwijderd uit tracked_symbols.', 'success');
    }

    redirectToSymbols('Symbool verwijderd uit bot_symbols.', 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    redirectToSymbols('Fout bij verwijderen: ' . $e->getMessage(), 'error');
}