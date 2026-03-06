<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors  = [];
$success = null;

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
    header('Location: /admin/contacts.php');
    exit;
}

/* Contact laden (alleen niet-deleted) */
$st = $pdo->prepare("
    SELECT id, name, email, phone, notes, created_at
    FROM contacts
    WHERE id = ? AND deleted_at IS NULL
");
$st->execute([$id]);
$contact = $st->fetch();

if (!$contact) {
    header('Location: /admin/contacts.php');
    exit;
}

auditLog($pdo, 'PAGE_VIEW', 'admin/contact_delete.php id=' . $id);

/* POST: soft delete uitvoeren */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    // Extra zekerheid: alleen ADMIN/BEHEER (requireRole deed dat al)
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) {
        $errors[] = 'Gebruiker niet bekend in sessie (user id ontbreekt).';
    }

    if (!$errors) {
        $del = $pdo->prepare("
            UPDATE contacts
            SET deleted_at = NOW(),
                deleted_by_user_id = ?
            WHERE id = ? AND deleted_at IS NULL
        ");
        $del->execute([$uid, $id]);

        auditLog($pdo, 'CONTACT_DELETE', 'contact_id=' . $id . ' deleted_by=' . $uid);

        header('Location: /admin/contacts.php?deleted=1');
        exit;
    }
}

?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Contact verwijderen</title>

<style>
:root{
 --text:#fff;
 --muted:rgba(255,255,255,.78);
 --border:rgba(255,255,255,.22);
 --glass:rgba(255,255,255,.12);
 --glass2:rgba(255,255,255,.06);
 --shadow:0 14px 40px rgba(0,0,0,.45);
}

body{
 margin:0;
 font-family:Arial,sans-serif;
 color:var(--text);
 background:url('/assets/images/contacts-a.png') no-repeat center center fixed;
 background-size:cover;
}

a{ color:#fff; }

.backdrop{
 min-height:100vh;
 background:
   radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
   linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
 padding:26px;
 box-sizing:border-box;
 display:flex;
 justify-content:center;
}

.wrap{width:min(900px,96vw);}

.topbar{
 display:flex;
 align-items:flex-end;
 justify-content:space-between;
 gap:16px;
 flex-wrap:wrap;
 margin-bottom:14px;
}

.brand h1{margin:0;font-size:28px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}

.userbox{
 background:var(--glass);
 border:1px solid var(--border);
 border-radius:14px;
 padding:12px 14px;
 box-shadow:var(--shadow);
 backdrop-filter:blur(10px);
 min-width:260px;
}

.panel{
 margin-top:10px;
 border-radius:20px;
 border:1px solid rgba(255,255,255,.18);
 background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
 box-shadow:var(--shadow);
 backdrop-filter:blur(12px);
 padding:18px;
}

.msg{
 margin-bottom:12px;
 padding:10px 12px;
 border-radius:10px;
 border:1px solid rgba(255,255,255,.18);
 background:rgba(255,255,255,.08);
 font-size:13px;
}

.ok{color:#b8ffb8}
.err{color:#ffb8b8}

.card{
 border-radius:16px;
 border:1px solid rgba(255,255,255,.16);
 background:rgba(255,255,255,.06);
 padding:14px;
}

.kv{
 display:grid;
 grid-template-columns: 160px 1fr;
 gap:8px 12px;
 margin-top:8px;
}
@media (max-width:720px){
 .kv{grid-template-columns:1fr;}
}

.k{color:var(--muted);font-size:13px}
.v{font-size:14px}

.actions{
 display:flex;
 gap:10px;
 flex-wrap:wrap;
 margin-top:14px;
}

.btn{
 display:inline-block;
 text-decoration:none;
 color:#fff;
 font-weight:800;
 padding:10px 14px;
 border-radius:12px;
 border:1px solid rgba(255,255,255,.22);
 background:linear-gradient(180deg, var(--glass), var(--glass2));
 cursor:pointer;
}

.btn:hover{ border-color:rgba(255,255,255,.38); }

.btn.secondary{
 font-weight:700;
 opacity:.95;
}

.btn.danger{
 border-color:rgba(255,120,120,.45);
}

.smallnote{
 margin-top:10px;
 color:var(--muted);
 font-size:12px;
}
</style>
</head>

<body>
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div class="brand">
        <h1>Contact verwijderen</h1>
        <div class="sub">
          <a href="/admin/dashboard.php">Dashboard</a> ·
          <a href="/admin/contacts.php">Contacten</a> ·
          <a href="/admin/contact_edit.php?id=<?= (int)$id ?>">Terug naar bewerken</a>
        </div>
      </div>
      <div class="userbox">
        <div><strong><?= h($user['username'] ?? '') ?></strong></div>
        <div style="font-size:13px;color:var(--muted)">Rol: <?= h($role) ?></div>
      </div>
    </div>

    <div class="panel">

      <?php if ($errors): ?>
        <div class="msg err">
          <strong>Actie niet uitgevoerd:</strong>
          <ul style="margin:8px 0 0 18px;">
            <?php foreach ($errors as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="msg">
        Je staat op het punt dit contact te verwijderen. Dit is een <strong>soft-delete</strong>:
        het contact verdwijnt uit de lijsten, maar kan later eventueel hersteld worden.
      </div>

      <div class="card">
        <strong style="font-size:16px;"><?= h($contact['name'] ?? '') ?></strong>

        <div class="kv">
          <div class="k">E-mail</div>
          <div class="v"><?= h($contact['email'] ?? '-') ?></div>

          <div class="k">Telefoon</div>
          <div class="v"><?= h($contact['phone'] ?? '-') ?></div>

          <div class="k">Notities</div>
          <div class="v"><?= nl2br(h($contact['notes'] ?? '')) ?: '-' ?></div>

          <div class="k">Aangemaakt</div>
          <div class="v"><?= h((string)($contact['created_at'] ?? '')) ?></div>
        </div>
      </div>

      <form method="post" action="/admin/contact_delete.php" style="margin-top:14px;">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="actions">
          <button class="btn danger" type="submit"
                  onclick="return confirm('Weet je zeker dat je dit contact wilt verwijderen?')">
            Definitief verwijderen (soft-delete)
          </button>

          <a class="btn secondary" href="/admin/contact_edit.php?id=<?= (int)$id ?>">Annuleren</a>
          <a class="btn secondary" href="/admin/contacts.php">Terug naar lijst</a>
        </div>

        <div class="smallnote">
          Alleen ADMIN/BEHEER kan verwijderen. De gebruiker die verwijdert wordt opgeslagen in <code>deleted_by_user_id</code>.
        </div>
      </form>

    </div>
  </div>
</div>
</body>
</html>