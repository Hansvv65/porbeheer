<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id <= 0) {
    header('Location: /admin/contact_edit.php');
    exit;
}

requireCsrf($_POST['csrf'] ?? '');

/* Alleen soft-deleted contacten terughalen */
$st = $pdo->prepare("
  SELECT id, name
  FROM contacts
  WHERE id = ? AND deleted_at IS NOT NULL
");
$st->execute([$id]);
$contact = $st->fetch();

if (!$contact) {
    header('Location: /admin/contact_edit.php?restorenotfound=1');
    exit;
}

/* Restore */
$uid = (int)($user['id'] ?? 0);
$upd = $pdo->prepare("
  UPDATE contacts
  SET deleted_at = NULL,
      deleted_by_user_id = NULL
  WHERE id = ? AND deleted_at IS NOT NULL
");
$upd->execute([$id]);

auditLog($pdo, 'CONTACT_RESTORE', 'contact_id=' . $id . ' restored_by=' . $uid);

header('Location: /admin/contact_edit.php?restored=1');
exit;