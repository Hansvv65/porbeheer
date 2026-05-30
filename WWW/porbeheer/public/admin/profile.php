<?php
declare(strict_types=1);

/**
 * profile.php - Verplichte profielpagina na goedkeuring
 * 
 * Gebruiker moet hier zijn/haar aanspreektitel, voornaam, achternaam en 06-nummer invullen.
 * Na succesvol invullen wordt doorgestuurd naar het dashboard.
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';

// Alleen ingelogde gebruikers met status ACTIVE mogen hier komen
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
}

$user = currentUser();
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Optioneel: check of 2FA al is ingesteld? We gaan ervan uit dat dit al gebeurd is via de eerdere flow.
// De bootstrap-check stuurt je hierheen na 2FA en goedkeuring.

$errors = [];
$success = null;

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function isValidDutchMobile(string $phone): bool {
    return (bool)preg_match('/^(\+31|0)6[ -]?[1-9][0-9]{7}$/', $phone);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $title        = trim((string)($_POST['title'] ?? ''));
    $firstName    = trim((string)($_POST['first_name'] ?? ''));
    $tussenvoegsel = trim((string)($_POST['tussenvoegsel'] ?? ''));
    $lastName     = trim((string)($_POST['last_name'] ?? ''));
    $phone        = trim((string)($_POST['phone'] ?? ''));

    // Validatie
    if ($title === '') {
        $errors[] = 'Aanspreektitel is verplicht (bijv. Dhr. of Mevr.).';
    }
    if ($firstName === '') {
        $errors[] = 'Voornaam is verplicht.';
    }
    if ($lastName === '') {
        $errors[] = 'Achternaam is verplicht.';
    }
    if ($phone === '') {
        $errors[] = '06-nummer is verplicht.';
    } elseif (!isValidDutchMobile($phone)) {
        $errors[] = 'Ongeldig 06-nummer. Gebruik 0612345678, 06-12345678 of +31612345678.';
    }

    if (!$errors) {
        // Update de database
        $stmt = $pdo->prepare("UPDATE users SET title=?, first_name=?, tussenvoegsel=?, last_name=?, phone=? WHERE id=?");
        $stmt->execute([$title, $firstName, $tussenvoegsel, $lastName, $phone, $user['id']]);

        // Werk de sessie bij
        $_SESSION['user'] = array_merge($_SESSION['user'], [
            'title'         => $title,
            'first_name'    => $firstName,
            'tussenvoegsel' => $tussenvoegsel,
            'last_name'     => $lastName,
            'phone'         => $phone,
        ]);

        // Redirect naar dashboard
        header('Location: /admin/dashboard.php');
        exit;
    }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Profiel aanvullen</title>
<style>
:root{
  --text:#fff; --muted:rgba(255,255,255,.78); --border:rgba(255,255,255,.22);
  --glass:rgba(255,255,255,.14); --glass2:rgba(255,255,255,.08); --shadow:0 20px 50px rgba(0,0,0,.5);
  --err:#FF8DA1; --ok:#7CFFB2; --info:#bf721f; --warn:#ffd86b;
}
body{
  margin:0; font-family:Arial,Helvetica,sans-serif; color:var(--text);
  background:url('/assets/images/loginbg.png') no-repeat center center fixed; background-size:cover;
}
.backdrop{
  min-height:100vh; display:flex; align-items:center; justify-content:center;
  padding:26px; box-sizing:border-box;
  background:radial-gradient(circle at 25% 15%,rgba(0,0,0,.35),rgba(0,0,0,.75) 55%,rgba(0,0,0,.88));
}
.shell{ width:min(480px,96vw); }
.card{
  position:relative; border-radius:20px; border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg,rgba(255,255,255,.14),rgba(255,255,255,.06));
  box-shadow:var(--shadow); backdrop-filter:blur(12px); padding:22px;
}
.card h2 { margin-top: 0; margin-bottom: 8px; font-size: 1.6rem; }
.card .sub { color: var(--muted); margin-bottom: 24px; font-size: 0.95rem; }

label { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; font-weight: bold; font-size: 0.9rem; }
input, select { 
  padding: 12px; border-radius: 10px; border: 1px solid var(--border); 
  background: rgba(0,0,0,0.2); color: #fff; font-size: 1rem; width: 100%; box-sizing: border-box;
}
input:focus, select:focus { outline: none; border-color: var(--info); background: rgba(0,0,0,0.3); }
select option { background: #1a1a2e; color: #fff; }

.btn { 
  width: 100%; padding: 12px; border-radius: 10px; border: none; 
  background-color: var(--info); color: #fff; font-weight: 900; font-size: 1rem; cursor: pointer; margin-top: 10px;
  transition: opacity 0.2s;
}
.btn:hover { opacity: 0.9; }

.msg{
  padding:12px; border-radius:10px; margin-bottom:15px; font-size:0.9rem;
  border:1px solid rgba(255,255,255,0.15);
}
.msg.err{ background:rgba(255,107,129,0.15); color:var(--err); border-color:var(--err); }
.msg.info{ background:rgba(255,179,71,0.15); color:var(--info); border-color:var(--info); }
.actions { text-align: center; margin-top: 20px; }
.action { color: var(--info); text-decoration: none; font-size: 0.9rem; font-weight: 600; text-shadow: 0 1px 2px rgba(0,0,0,0.4); }
.action:hover { text-decoration: underline; filter: brightness(1.2); }
</style>
</head>
<body>
<div class="backdrop">
  <div class="shell">
    <div class="card">
      <h2>Welkom bij Porbeheer</h2>
      <div class="sub">Je account is goedgekeurd. Vul alsjeblieft je profiel aan om verder te gaan.</div>

      <?php if ($errors): ?>
        <?php foreach ($errors as $error): ?>
          <div class="msg err"><?= h($error) ?></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label>Aanspreektitel *
          <select name="title" required>
            <option value="">-- kies --</option>
            <option value="Dhr." <?= (($_POST['title'] ?? '') === 'Dhr.') ? 'selected' : '' ?>>Dhr.</option>
            <option value="Mevr." <?= (($_POST['title'] ?? '') === 'Mevr.') ? 'selected' : '' ?>>Mevr.</option>
            <option value="Anders" <?= (($_POST['title'] ?? '') === 'Anders') ? 'selected' : '' ?>>Anders</option>
          </select>
        </label>

        <label>Voornaam *
          <input type="text" name="first_name" required value="<?= h($_POST['first_name'] ?? '') ?>">
        </label>

        <label>Tussenvoegsel
          <input type="text" name="tussenvoegsel" value="<?= h($_POST['tussenvoegsel'] ?? '') ?>" placeholder="optioneel">
        </label>

        <label>Achternaam *
          <input type="text" name="last_name" required value="<?= h($_POST['last_name'] ?? '') ?>">
        </label>

        <label>06-nummer *
          <input type="text" name="phone" required value="<?= h($_POST['phone'] ?? '') ?>" placeholder="0612345678">
          <small style="font-weight: normal; font-size: 0.8rem; color: var(--muted);">Formaat: 0612345678, 06-12345678 of +31612345678</small>
        </label>

        <button class="btn" type="submit">Profiel opslaan en verder</button>
      </form>

      <div class="actions">
        <a class="action" href="/logout.php">Uitloggen</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>