<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN']); // Alleen ADMIN mag echt verwijderen

$user = currentUser();

function redirectBack(): void {
    header('Location: /admin/contact_edit.php?purged=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectBack();
}

requireCsrf($_POST['csrf'] ?? '');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    redirectBack();
}

/* Alleen records die al soft-deleted zijn */
$st = $pdo->prepare("
  SELECT id, name
  FROM contacts
  WHERE id = ? AND deleted_at IS NOT NULL
");
$st->execute([$id]);
$row = $st->fetch();

if (!$row) {
    header('Location: /admin/contact_edit.php?purgenotfound=1');
    exit;
}

/* Hard delete */
$del = $pdo->prepare("DELETE FROM contacts WHERE id = ?");
$del->execute([$id]);

auditLog($pdo, 'CONTACT_PURGE', 'contact_id=' . $id . ' purged_by=' . (int)$user['id']);

redirectBack();