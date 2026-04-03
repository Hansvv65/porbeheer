<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER','FINANCIEEL']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('contacts', $pdo);

if (!function_exists('h')) {
    function h(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'noband', 'withband'], true)) {
    $filter = 'all';
}

/*
|--------------------------------------------------------------------------
| Contacts + bands
| Bands is leading:
| - primary_contact_id
| - secondary_contact_id
|--------------------------------------------------------------------------
*/
$sql = "
  SELECT
    c.id,
    c.name,
    c.email,
    c.phone,
    GROUP_CONCAT(DISTINCT b.name ORDER BY b.name SEPARATOR ', ') AS bands,
    COUNT(DISTINCT b.id) AS band_count
  FROM contacts c
  LEFT JOIN bands b
    ON b.deleted_at IS NULL
   AND (
        b.primary_contact_id = c.id
        OR b.secondary_contact_id = c.id
   )
  WHERE c.deleted_at IS NULL
  GROUP BY c.id, c.name, c.email, c.phone
";

if ($filter === 'noband') {
    $sql .= " HAVING COUNT(DISTINCT b.id) = 0";
} elseif ($filter === 'withband') {
    $sql .= " HAVING COUNT(DISTINCT b.id) > 0";
}

$sql .= " ORDER BY c.name";

$contacts = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

auditLog($pdo, 'PAGE_VIEW', 'admin/contacts.php');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Contacten</title>

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
 background:url('<?= h($bg) ?>') no-repeat center center fixed;
 background-size:cover;
}

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

.wrap{width:min(1200px,96vw);}

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

.userbox .line1{font-weight:bold}
.userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}

.panel{
 margin-top:10px;
 border-radius:20px;
 border:1px solid rgba(255,255,255,.18);
 background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
 box-shadow:var(--shadow);
 backdrop-filter:blur(12px);
 padding:18px;
}

.panelhead{
 display:flex;
 justify-content:space-between;
 flex-wrap:wrap;
 gap:10px;
 margin-bottom:12px;
}

.btn{
 display:inline-block;
 text-decoration:none;
 color:#fff;
 font-weight:800;
 padding:8px 12px;
 border-radius:12px;
 border:1px solid rgba(255,255,255,.22);
 background:linear-gradient(180deg, var(--glass), var(--glass2));
}

.btn:hover{
 border-color:rgba(255,255,255,.38);
}

.btn.active{
 border-color:rgba(255,255,255,.55);
 background:linear-gradient(180deg, rgba(255,255,255,.28), rgba(255,255,255,.10));
 box-shadow:0 6px 16px rgba(0,0,0,.35);
}

.tablewrap{
 overflow:auto;
 border-radius:14px;
 border:1px solid rgba(255,255,255,.12);
}

table{
 width:100%;
 border-collapse:collapse;
}

th,td{
 padding:10px;
 border-bottom:1px solid rgba(255,255,255,.12);
 text-align:left;
 vertical-align:top;
}

th{background:rgba(255,255,255,.05)}

.msg{
 margin-bottom:12px;
 padding:10px 12px;
 border-radius:10px;
 border:1px solid rgba(255,255,255,.18);
 background:rgba(255,255,255,.08);
 font-size:13px;
}

.ok{color:#b8ffb8}

a{
 color:#fff;
 text-decoration:none;
 transition:color .15s ease;
}
a:hover{color:#ffd9b3}
a:visited{color:#ffe0c2}
</style>
</head>
<body>
<div class="backdrop">
<div class="wrap">

<div class="topbar">
 <div class="brand">
   <h1>Contacten</h1>
   <div class="sub">
     <a href="/admin/dashboard.php">Dashboard</a> ·
     <a href="/admin/contact_edit.php">Nieuw contact</a>
   </div>
 </div>

 <div class="userbox">
    <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h((string)$role) ?></div>
    <div class="line2">
      <a href="/admin/dashboard.php">Dashboard</a> •
      <a href="/logout.php">Uitloggen</a>
    </div>
 </div>
</div>

<div class="panel">

<?php if (isset($_GET['deleted'])): ?>
  <div class="msg ok">Contact verwijderd.</div>
<?php endif; ?>

<div class="panelhead" style="justify-content:center;">
  <div style="display:flex; gap:12px; flex-wrap:wrap; justify-content:center;">
    <a class="btn <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">Alle</a>
    <a class="btn <?= $filter === 'noband' ? 'active' : '' ?>" href="?filter=noband">Zonder band</a>
    <a class="btn <?= $filter === 'withband' ? 'active' : '' ?>" href="?filter=withband">Met band</a>
  </div>
</div>

<div class="tablewrap">
<table>
<thead>
<tr>
 <th>Naam</th>
 <th>Email</th>
 <th>Telefoon</th>
 <th>Bands</th>
 <th>Acties</th>
</tr>
</thead>
<tbody>

<?php if (!$contacts): ?>
<tr>
  <td colspan="5">Geen contacten gevonden.</td>
</tr>
<?php else: ?>
<?php foreach ($contacts as $c): ?>
<tr>
 <td><?= h($c['name']) ?></td>
 <td><?= h($c['email'] ?? '') ?></td>
 <td><?= h($c['phone'] ?? '') ?></td>
 <td><?= h($c['bands'] ?? '—') ?></td>
 <td>
   <a href="/admin/contact_edit.php?id=<?= (int)$c['id'] ?>">Bewerken</a>
 </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>

</tbody>
</table>
</div>

</div>
</div>
</div>
</body>
</html>