<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg   = themeImage('contacts', $pdo);

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$id     = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$isEdit = $id > 0;

$row = [
    'first_name'    => '',
    'tussenvoegsel' => '',
    'aanhef'        => '',
    'last_name'     => '',
    'email'         => '',
    'phone'         => '',
    'notes'         => '',
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT id, first_name, tussenvoegsel, aanhef, last_name, email, phone, notes FROM contacts WHERE id = ?");
    $stmt->execute([$id]);
    $dbRow = $stmt->fetch();
    if (!$dbRow) {
        header('Location: /admin/contacts.php');
        exit;
    }
    $row = array_merge($row, $dbRow);
}

auditLog($pdo, 'PAGE_VIEW', "admin/contact_edit.php id=$id");

$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $action = $_POST['action'] ?? '';

    // Hard-delete
    if ($action === 'delete') {
        if (!in_array($role, ['ADMIN','BEHEER'], true)) {
            header('Location: /admin/contact_edit.php');
            exit;
        }
        $targetId = (int)($_POST['id'] ?? 0);
        if ($targetId <= 0) {
            header('Location: /admin/contacts.php');
            exit;
        }

        // Haal naam op voor logging vóór het verwijderen
        $st = $pdo->prepare("SELECT name FROM contacts WHERE id = ?");
        $st->execute([$targetId]);
        $contactName = $st->fetchColumn();

        if (!$contactName) {
            header('Location: /admin/contacts.php?notfound=1');
            exit;
        }

        // Verwijder het contact
        $del = $pdo->prepare("DELETE FROM contacts WHERE id = ?");
        $del->execute([$targetId]);

        if ($del->rowCount() > 0) {
            $username = $user['username'] ?? 'onbekend';
            $userId   = (int)$user['id'];
            auditLog($pdo, 'CONTACT_DELETE', "Contact '$contactName' (ID $targetId) definitief verwijderd door gebruiker '$username' (ID $userId)");
            header('Location: /admin/contacts.php?deleted=1');
        } else {
            header('Location: /admin/contacts.php?notfound=1');
        }
        exit;
    }

    // Normaal formulier
    $firstName     = trim((string)($_POST['first_name'] ?? ''));
    $tussenvoegsel = trim((string)($_POST['tussenvoegsel'] ?? ''));
    $aanhef        = trim((string)($_POST['aanhef'] ?? ''));
    $lastName      = trim((string)($_POST['last_name'] ?? ''));
    $email         = trim((string)($_POST['email'] ?? ''));
    $phone         = trim((string)($_POST['phone'] ?? ''));
    $notes         = trim((string)($_POST['notes'] ?? ''));

    $fullName = trim(implode(' ', array_filter([$firstName, $tussenvoegsel, $lastName])));

    // Validatie (telefoon, email, etc. – blijft zoals eerder)
    if ($firstName === '' || mb_strlen($firstName) < 2) {
        $errors[] = 'Voornaam is verplicht (minimaal 2 tekens).';
    }
    if ($lastName === '' || mb_strlen($lastName) < 2) {
        $errors[] = 'Achternaam is verplicht (minimaal 2 tekens).';
    }
    if ($email === '') {
        $errors[] = 'E‑mailadres is verplicht.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E‑mailadres is niet geldig (moet xxx@xxx.xxx).';
    }
    if ($phone === '') {
        $errors[] = 'Telefoonnummer is verplicht.';
    } else {
        $originalPhone = $phone;
        if (str_starts_with($phone, '+')) {
            $phone = preg_replace('/[^\d+]/', '', $phone);
            if (!preg_match('/^\+\d{7,}$/', $phone)) {
                $errors[] = 'Buitenlands nummer moet beginnen met + en minimaal 7 cijfers hebben.';
            }
        } elseif (preg_match('/^06/', $phone)) {
            $phone = preg_replace('/[^\d\-]/', '', $phone);
            $phoneDigits = str_replace('-', '', $phone);
            if (strlen($phoneDigits) !== 10) {
                $errors[] = 'Nederlands 06-nummer moet 10 cijfers bevatten.';
            } else {
                $phone = substr($phoneDigits, 0, 2) . '-' . substr($phoneDigits, 2);
            }
        } else {
            $errors[] = 'Telefoonnummer moet beginnen met 06 (Nederlands) of + (buitenlands).';
        }
    }

    if (!$errors) {
        $tussenvoegselDb = ($tussenvoegsel === '' ? null : $tussenvoegsel);
        $aanhefDb        = ($aanhef === '' ? null : $aanhef);
        $notesDb         = ($notes === '' ? null : $notes);
        $username        = $user['username'] ?? 'onbekend';
        $userId          = (int)$user['id'];

        if ($isEdit) {
            $upd = $pdo->prepare("UPDATE contacts SET name=?, first_name=?, tussenvoegsel=?, aanhef=?, last_name=?, email=?, phone=?, notes=? WHERE id=?");
            $upd->execute([$fullName, $firstName, $tussenvoegselDb, $aanhefDb, $lastName, $email, $phone, $notesDb, $id]);
            if ($upd->rowCount() === 0) {
                header('Location: /admin/contacts.php?notfound=1');
                exit;
            }
            auditLog($pdo, 'CONTACT_UPDATE', "Contact '$fullName' (ID $id) bijgewerkt door gebruiker '$username' (ID $userId)");
            header('Location: /admin/contacts.php?updated=1');
            exit;
        } else {
            $ins = $pdo->prepare("INSERT INTO contacts (name, first_name, tussenvoegsel, aanhef, last_name, email, phone, notes, deleted_at) VALUES (?,?,?,?,?,?,?,?, NULL)");
            $ins->execute([$fullName, $firstName, $tussenvoegselDb, $aanhefDb, $lastName, $email, $phone, $notesDb]);
            $newId = (int)$pdo->lastInsertId();
            auditLog($pdo, 'CONTACT_CREATE', "Nieuw contact '$fullName' (ID $newId) aangemaakt door gebruiker '$username' (ID $userId)");
            header('Location: /admin/contacts.php?created=1');
            exit;
        }
    } else {
        $row['first_name']    = $firstName;
        $row['tussenvoegsel'] = $tussenvoegsel;
        $row['aanhef']        = $aanhef;
        $row['last_name']     = $lastName;
        $row['email']         = $email;
        $row['phone']         = $phone;
        $row['notes']         = $notes;
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
body{ margin:0; font-family:Arial,sans-serif; color:var(--text); background:url('<?= h($bg) ?>') no-repeat center center fixed; background-size:cover; }
a{ color:#fff; }
.backdrop{ min-height:100vh; background: radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)), linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35)); padding:26px; box-sizing:border-box; display:flex; justify-content:center; }
.wrap{width:min(900px,96vw);}
.topbar{ display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:14px; }
.brand h1{margin:0;font-size:28px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}
.userbox{ background:var(--glass); border:1px solid var(--border); border-radius:14px; padding:12px 14px; box-shadow:var(--shadow); backdrop-filter:blur(10px); min-width:260px; }
.panel{ margin-top:10px; border-radius:20px; border:1px solid rgba(255,255,255,.18); background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06)); box-shadow:var(--shadow); backdrop-filter:blur(12px); padding:18px; }
.msg{ margin-bottom:12px; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.08); font-size:13px; }
.ok{color:#b8ffb8} .err{color:#ffb8b8}
.formgrid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media (max-width:720px){ .formgrid{grid-template-columns:1fr;} }
.field{ background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.14); border-radius:14px; padding:12px; }
label{ display:block; font-size:13px; color:var(--muted); margin-bottom:6px; }
input, textarea, select{ width:100%; box-sizing:border-box; border-radius:12px; border:1px solid rgba(255,255,255,.18); padding:10px 10px; background:rgba(0,0,0,.25); color:#fff; outline:none; }
textarea{ min-height:120px; resize:vertical; line-height:1.35;}
input:focus, textarea:focus, select:focus{ border-color:rgba(255,255,255,.45); }
select option{ background:#222; color:#fff; }
.actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
.btn{ display:inline-block; text-decoration:none; color:#fff; font-weight:800; padding:10px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.22); background:linear-gradient(180deg, var(--glass), var(--glass2)); cursor:pointer; }
.btn:hover{ border-color:rgba(255,255,255,.38); }
.btn.secondary{ font-weight:700; opacity:.95;}
.btn.danger{ border-color:rgba(255,80,80,.55);}
.btn.danger:hover{ border-color:rgba(255,120,120,.75);}
.smallnote{ margin-top:10px; color:var(--muted); font-size:12px; }
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
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h((string)$role) ?></div>
        <div class="line2">
          <a href="/admin/contacts.php">Contacten</a> •
          <a href="/admin/dashboard.php">Dashboard</a> •
          <a href="/logout.php">Uitloggen</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <?php if ($success): ?><div class="msg ok"><?= h($success) ?></div><?php endif; ?>
      <?php if ($errors): ?>
        <div class="msg err"><strong>Controleer het formulier:</strong><ul style="margin:8px 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form method="post" action="/admin/contact_edit.php">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="formgrid">
          <div class="field">
            <label for="aanhef">Aanhef</label>
            <select id="aanhef" name="aanhef">
              <option value="">-- geen --</option>
              <option value="Dhr." <?= ($row['aanhef'] ?? '') === 'Dhr.' ? 'selected' : '' ?>>Dhr.</option>
              <option value="Mevr." <?= ($row['aanhef'] ?? '') === 'Mevr.' ? 'selected' : '' ?>>Mevr.</option>
            </select>
          </div>
          <div class="field">
            <label for="first_name">Voornaam *</label>
            <input id="first_name" name="first_name" type="text" required minlength="2" value="<?= h($row['first_name']) ?>">
          </div>
          <div class="field">
            <label for="tussenvoegsel">Tussenvoegsel</label>
            <input id="tussenvoegsel" name="tussenvoegsel" type="text" value="<?= h($row['tussenvoegsel']) ?>">
          </div>
          <div class="field">
            <label for="last_name">Achternaam *</label>
            <input id="last_name" name="last_name" type="text" required minlength="2" value="<?= h($row['last_name']) ?>">
          </div>
          <div class="field">
            <label for="email">E-mail *</label>
            <input id="email" name="email" type="email" required value="<?= h($row['email']) ?>" placeholder="voorbeeld@domein.nl">
          </div>
          <div class="field">
            <label for="phone">Telefoon *</label>
            <input id="phone" name="phone" type="text" required value="<?= h($row['phone']) ?>" placeholder="06-12345678 of +32 475 12 34 56">
          </div>
          <div class="field" style="grid-column:1/-1;">
            <label for="notes">Notities</label>
            <textarea id="notes" name="notes" placeholder="Interne notities..."><?= h($row['notes']) ?></textarea>
          </div>
        </div>

        <div class="actions">
          <button class="btn" type="submit"><?= $isEdit ? 'Opslaan' : 'Aanmaken' ?></button>
          <a class="btn secondary" href="/admin/contacts.php">Annuleren</a>

          <?php if ($isEdit && in_array($role, ['ADMIN','BEHEER'], true)): ?>
            <form method="post" action="/admin/contact_edit.php" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn danger" type="submit" onclick="return confirm('Definitief verwijderen? Dit kan niet ongedaan gemaakt worden.')">Definitief verwijderen</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="smallnote">* verplicht veld. Telefoon: Nederlands 06-formaat (06-12345678) of buitenlands (+32...).</div>
      </form>
    </div>
  </div>
</div>
</body>
</html>