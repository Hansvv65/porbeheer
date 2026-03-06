<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER','FINANCIEEL']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors  = [];
$success = null;

$id     = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$isEdit = $id > 0;

/* Basis record */
$row = [
  'name'  => '',
  'email' => '',
  'phone' => '',
  'notes' => '',
];

/* Record laden */
if ($isEdit) {
    $st = $pdo->prepare("
        SELECT id, name, email, phone, notes
        FROM contacts
        WHERE id = ? AND deleted_at IS NULL
    ");
    $st->execute([$id]);
    $dbRow = $st->fetch();
    if (!$dbRow) {
        header('Location: /admin/contacts.php');
        exit;
    }
    $row = array_merge($row, $dbRow);
}

auditLog($pdo, 'PAGE_VIEW', 'admin/contact_edit.php id=' . $id);

/* Prullenbak (soft-deleted contacten) - alleen ADMIN/BEHEER */
$trash = [];
if (in_array($role, ['ADMIN','BEHEER'], true)) {
    $trash = $pdo->query("
      SELECT id, name, email, phone, deleted_at
      FROM contacts
      WHERE deleted_at IS NOT NULL
      ORDER BY deleted_at DESC
      LIMIT 50
    ")->fetchAll();
}

/* POST verwerken */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $name  = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($name === '' || mb_strlen($name) < 2) {
        $errors[] = 'Naam is verplicht (minimaal 2 tekens).';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-mail is niet geldig.';
    }

    // basic normalisatie telefoon (optioneel)
    if ($phone !== '') {
        $phone = preg_replace('/[^\d\+\-\s\(\)]/', '', $phone) ?? $phone;
        if (mb_strlen($phone) < 6) {
            $errors[] = 'Telefoonnummer lijkt te kort.';
        }
    }

    if (!$errors) {
        $emailDb = ($email === '' ? null : $email);
        $phoneDb = ($phone === '' ? null : $phone);
        $notesDb = ($notes === '' ? null : $notes);

        if ($isEdit) {
            // updated_at wordt door MySQL automatisch bijgewerkt (ON UPDATE) als je die kolom hebt toegevoegd
            $upd = $pdo->prepare("
                UPDATE contacts
                SET name = ?, email = ?, phone = ?, notes = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $upd->execute([$name, $emailDb, $phoneDb, $notesDb, $id]);

            auditLog($pdo, 'CONTACT_UPDATE', 'contact_id=' . $id);
            $success = 'Contact opgeslagen.';
        } else {
            // created_at default + updated_at default via DB (indien aanwezig)
            $ins = $pdo->prepare("
                INSERT INTO contacts (name, email, phone, notes)
                VALUES (?,?,?,?)
            ");
            $ins->execute([$name, $emailDb, $phoneDb, $notesDb]);

            $newId = (int)$pdo->lastInsertId();
            auditLog($pdo, 'CONTACT_CREATE', 'contact_id=' . $newId);

            header('Location: /admin/contacts.php?created=1');
            exit;
        }

        // form values terugzetten
        $row['name']  = $name;
        $row['email'] = $email;
        $row['phone'] = $phone;
        $row['notes'] = $notes;
    } else {
        // ook bij errors: ingevulde waarden behouden
        $row['name']  = $name;
        $row['email'] = $email;
        $row['phone'] = $phone;
        $row['notes'] = $notes;
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Contact <?= $isEdit ? 'bewerken' : 'nieuw' ?></title>

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

.formgrid{
 display:grid;
 grid-template-columns: 1fr 1fr;
 gap:12px;
}

@media (max-width:720px){
 .formgrid{grid-template-columns:1fr;}
}

.field{
 background:rgba(255,255,255,.06);
 border:1px solid rgba(255,255,255,.14);
 border-radius:14px;
 padding:12px;
}

label{
 display:block;
 font-size:13px;
 color:var(--muted);
 margin-bottom:6px;
}

input, textarea{
 width:100%;
 box-sizing:border-box;
 border-radius:12px;
 border:1px solid rgba(255,255,255,.18);
 padding:10px 10px;
 background:rgba(0,0,0,.25);
 color:#fff;
 outline:none;
}

textarea{
 min-height:120px;
 resize:vertical;
 line-height:1.35;
}

input:focus, textarea:focus{
 border-color:rgba(255,255,255,.45);
}

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

.smallnote{
 margin-top:10px;
 color:var(--muted);
 font-size:12px;
}

/* Prullenbak styling */
.hr{
  height:1px;
  background:rgba(255,255,255,.14);
  margin:16px 0;
  border:0;
}
.table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  overflow:hidden;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.05);
}
.table th, .table td{
  padding:10px 10px;
  border-bottom:1px solid rgba(255,255,255,.10);
  vertical-align:top;
  font-size:13px;
}
.table th{
  text-align:left;
  color:var(--muted);
  font-weight:800;
  background:rgba(255,255,255,.06);
}
.table tr:last-child td{ border-bottom:0; }
.badge{
  display:inline-block;
  padding:4px 8px;
  border-radius:999px;
  font-size:12px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.06);
  color:var(--muted);
}
.btn.small{
  padding:8px 10px;
  border-radius:10px;
  font-weight:800;
  font-size:13px;
}
.btn.danger{
  border-color:rgba(255,80,80,.55);
}
.btn.danger:hover{
  border-color:rgba(255,120,120,.75);
}
</style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div class="brand">
        <h1><?= $isEdit ? 'Contact bewerken' : 'Nieuw contact' ?></h1>
        <div class="sub">
          <a href="/admin/dashboard.php">Dashboard</a> ·
          <a href="/admin/contacts.php">Contacten</a>
        </div>
      </div>
      <div class="userbox">
        <div><strong><?= h($user['username'] ?? '') ?></strong></div>
        <div style="font-size:13px;color:var(--muted)">Rol: <?= h($role) ?></div>
      </div>
    </div>

    <div class="panel">

      <?php if ($success): ?>
        <div class="msg ok"><?= h($success) ?></div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="msg err">
          <strong>Controleer het formulier:</strong>
          <ul style="margin:8px 0 0 18px;">
            <?php foreach ($errors as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" action="/admin/contact_edit.php">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="formgrid">
          <div class="field" style="grid-column:1/-1;">
            <label for="name">Naam *</label>
            <input id="name" name="name" type="text" required minlength="2" value="<?= h($row['name'] ?? '') ?>">
          </div>

          <div class="field">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="<?= h($row['email'] ?? '') ?>">
          </div>

          <div class="field">
            <label for="phone">Telefoon</label>
            <input id="phone" name="phone" type="text" value="<?= h($row['phone'] ?? '') ?>">
          </div>

          <div class="field" style="grid-column:1/-1;">
            <label for="notes">Notities</label>
            <textarea id="notes" name="notes" placeholder="Interne notities..."><?= h($row['notes'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="actions">
          <button class="btn" type="submit"><?= $isEdit ? 'Opslaan' : 'Aanmaken' ?></button>
          <a class="btn secondary" href="/admin/contacts.php">Terug</a>

          <?php if ($isEdit && in_array($role, ['ADMIN','BEHEER'], true)): ?>
            <a class="btn secondary" href="/admin/contact_delete.php?id=<?= (int)$id ?>"
               onclick="return confirm('Weet je zeker dat je dit contact wilt verwijderen?')">
              Verwijderen
            </a>
          <?php endif; ?>
        </div>

        <div class="smallnote">
          * verplicht veld. Verwijderen is soft-delete (kan later eventueel hersteld worden).
        </div>
      </form>

      <?php if (in_array($role, ['ADMIN','BEHEER'], true)): ?>
        <hr class="hr">

        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div>
            <strong style="font-size:16px;">Prullenbak (soft-deleted contacten)</strong><br>
            <span style="color:var(--muted);font-size:13px;">Laatste 50 verwijderde contacten. Terughalen zet deleted_at en deleted_by_user_id terug naar NULL.</span>
          </div>
          <span class="badge"><?= count($trash) ?> item(s)</span>
        </div>

        <?php if (!empty($_GET['restored'])): ?>
          <div class="msg ok" style="margin-top:12px;">Contact is teruggehaald.</div>
        <?php endif; ?>

        <?php if (!empty($_GET['restorenotfound'])): ?>
          <div class="msg err" style="margin-top:12px;">Kon contact niet terughalen (niet gevonden of niet verwijderd).</div>
        <?php endif; ?>

        <?php if (!empty($_GET['purged'])): ?>
          <div class="msg ok" style="margin-top:12px;">Contact is definitief verwijderd.</div>
        <?php endif; ?>

        <?php if (!empty($_GET['purgenotfound'])): ?>
          <div class="msg err" style="margin-top:12px;">Kon contact niet definitief verwijderen (niet gevonden of niet verwijderd).</div>
        <?php endif; ?>

        <div style="margin-top:12px;">
          <?php if (!$trash): ?>
            <div class="msg">Geen verwijderde contacten gevonden.</div>
          <?php else: ?>
            <table class="table">
              <thead>
                <tr>
                  <th style="width:70px;">ID</th>
                  <th>Naam</th>
                  <th>E-mail</th>
                  <th>Telefoon</th>
                  <th style="width:170px;">Verwijderd op</th>
                  <th style="width:220px;">Actie</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($trash as $t): ?>
                <tr>
                  <td><?= (int)$t['id'] ?></td>
                  <td><strong><?= h($t['name'] ?? '') ?></strong></td>
                  <td><?= h($t['email'] ?? '-') ?></td>
                  <td><?= h($t['phone'] ?? '-') ?></td>
                  <td><?= h((string)($t['deleted_at'] ?? '')) ?></td>
                  <td style="display:flex;gap:6px;flex-wrap:wrap;">

                    <!-- Terughalen -->
                    <form method="post" action="/admin/contact_restore.php" style="margin:0;">
                      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <button class="btn small" type="submit"
                              onclick="return confirm('Dit contact terughalen?');">
                        Terughalen
                      </button>
                    </form>

                    <?php if ($role === 'ADMIN'): ?>
                      <!-- Echt verwijderen -->
                      <form method="post" action="/admin/contact_purge.php" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn small danger" type="submit"
                                onclick="return confirm('LET OP: Dit verwijdert het contact definitief uit de database. Doorgaan?');">
                          Echt verwijderen
                        </button>
                      </form>
                    <?php endif; ?>

                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <div class="smallnote">Alleen ADMIN ziet “Echt verwijderen”.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>