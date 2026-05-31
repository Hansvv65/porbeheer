<?php
declare(strict_types=1);
require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

if (!function_exists('currentUser')) {
    // safety: deze nav verwacht bootstrap/auth
    return;
}

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

function hnav($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function navActive(string $needle): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (strpos($uri, $needle) !== false) ? 'active' : '';
}

$canFinance = in_array($role, ['ADMIN','BEHEER','FINANCIEEL'], true);
$canAdmin   = ($role === 'ADMIN');
?>
<div class="card" style="margin-bottom: 16px;">
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <a class="link <?= navActive('/admin/dashboard.php') ?>" href="/admin/dashboard.php">Dashboard</a>
      <a class="link <?= navActive('/admin/planning.php') ?>" href="/admin/planning.php">Planning</a>
      <a class="link <?= navActive('/admin/bands.php') ?>" href="/admin/bands.php">Bands</a>
      <a class="link <?= navActive('/admin/contacts.php') ?>" href="/admin/contacts.php">Contacten</a>
      <a class="link <?= navActive('/admin/keys.php') ?>" href="/admin/keys.php">Sleutels</a>

      <?php if ($canFinance): ?>
        <a class="link <?= navActive('/admin/finance.php') ?>" href="/admin/finance.php">Financiën</a>
      <?php endif; ?>

      <?php if ($canAdmin): ?>
        <a class="link <?= navActive('/admin/users.php') ?>" href="/admin/users.php">Admin</a>
      <?php endif; ?>
    </div>

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <span class="muted">Ingelogd: <strong><?= hnav($user['username'] ?? '') ?></strong> (<?= hnav($role) ?>)</span>
      <a class="link" href="/logout.php">Uitloggen</a>
    </div>
  </div>
</div>