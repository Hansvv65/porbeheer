<?php
declare(strict_types=1);

/*
 * band_quick_individual.php
 *
 * Snelle weg om een boekbare "band" aan te maken die eigenlijk gewoon één
 * persoon is (een individu dat de ruimte wil boeken zonder een volledige
 * band). Vraagt alleen een naam + optioneel e-mail/telefoon, maakt een
 * contact + een band met primary_contact_id aan, en stuurt door naar de
 * gewone band_edit.php zodat alles (adres, IBAN, kastnummer, contract, ...)
 * later alsnog aangevuld kan worden.
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireSectionAccess('bands', 'write');

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('bands', $pdo);

$err = null;
$firstName = '';
$lastName = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName  = trim((string)($_POST['last_name'] ?? ''));
    $email     = trim((string)($_POST['email'] ?? ''));
    $phone     = trim((string)($_POST['phone'] ?? ''));

    if ($firstName === '' && $lastName === '') {
        $err = 'Vul minimaal een voor- of achternaam in.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Ongeldig e-mailadres.';
    } else {
        $fullName = trim($firstName . ' ' . $lastName);

        try {
            $pdo->beginTransaction();

            $insContact = $pdo->prepare("
                INSERT INTO contacts (name, first_name, tussenvoegsel, aanhef, last_name, email, phone, notes, deleted_at)
                VALUES (?, ?, NULL, NULL, ?, ?, ?, NULL, NULL)
            ");
            $insContact->execute([
                $fullName,
                $firstName,
                $lastName,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
            ]);
            $contactId = (int)$pdo->lastInsertId();

            $insBand = $pdo->prepare("
                INSERT INTO bands (name, primary_contact_id)
                VALUES (?, ?)
            ");
            $insBand->execute([$fullName, $contactId]);
            $bandId = (int)$pdo->lastInsertId();

            $pdo->commit();

            auditLog($pdo, 'BAND_QUICK_INDIVIDUAL_CREATE', 'admin/band_quick_individual.php', [
                'band_id' => $bandId, 'contact_id' => $contactId, 'name' => $fullName,
            ]);

            header('Location: /admin/band_edit.php?id=' . $bandId . '&quick=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Aanmaken mislukt: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Individu toevoegen</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
.quickform{max-width:420px;margin:0 auto;}
.quickform label{display:block;margin-bottom:4px;font-size:13px;color:var(--muted);}
.quickform input{width:100%;padding:9px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(0,0,0,.2);color:#fff;margin-bottom:14px;box-sizing:border-box;}
.quickform input:focus{outline:none;border-color:rgba(255,255,255,.5);}
.btn{
  display:inline-block;padding:10px 14px;border-radius:12px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));
  color:#fff;font-weight:800;cursor:pointer;text-decoration:none;
}
.btn:hover{border-color:rgba(255,255,255,.38)}
</style>
</head>
<body style="background-image:url('<?= h($bg) ?>')">
<div class="backdrop">
  <div class="wrap" style="width:min(560px,96vw);">

    <div class="topbar">
      <div class="brand">
        <h1><a href="/admin/dashboard.php">Porbeheer</a></h1>
        <div class="sub">
          <a href="/admin/bands.php">Overzicht</a>
        </div>
      </div>
      <div class="userbox">
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a>
          <a href="/logout.php">Uitloggen</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2>Individu toevoegen</h2>
      <p class="small">Voor iemand die zelfstandig wil boeken, zonder dat er meteen een volledige band met adres, IBAN of contract nodig is. Er wordt een band aangemaakt met de naam van deze persoon (achteraf aan te passen) en een gekoppeld contact.</p>

      <?php if ($err): ?>
        <div class="msg err"><?= h($err) ?></div>
      <?php endif; ?>

      <form method="post" class="quickform">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">

        <label for="first_name">Voornaam</label>
        <input type="text" id="first_name" name="first_name" value="<?= h($firstName) ?>" autofocus>

        <label for="last_name">Achternaam</label>
        <input type="text" id="last_name" name="last_name" value="<?= h($lastName) ?>">

        <label for="email">E-mailadres (optioneel)</label>
        <input type="email" id="email" name="email" value="<?= h($email) ?>">

        <label for="phone">Telefoon (optioneel)</label>
        <input type="text" id="phone" name="phone" value="<?= h($phone) ?>">

        <button type="submit" class="btn">Aanmaken en verder bewerken</button>
        <a href="/admin/bands.php" class="btn" style="margin-left:8px;">Annuleren</a>
      </form>
    </div>
  </div>
</div>
</body>
</html>
