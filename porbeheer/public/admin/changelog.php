<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

/* nieuwe versie toevoegen */
if(isset($_POST['new_version'])){
    requireCsrf($_POST['csrf'] ?? '');

    $version = trim($_POST['version'] ?? '');

    if($version !== ''){
        $stmt = $pdo->prepare("INSERT INTO changelog_versions (version) VALUES (?)");
        $stmt->execute([$version]);
    }
}

/* item toevoegen */
if(isset($_POST['new_item'])){
    requireCsrf($_POST['csrf'] ?? '');

    $version_id = $_POST['version_id'] ?: null;
    $type = $_POST['type'];
    $desc = trim($_POST['description']);

    if($desc !== ''){
        $stmt = $pdo->prepare("
            INSERT INTO changelog_items
            (version_id,type,description)
            VALUES (?,?,?)
        ");
        $stmt->execute([$version_id,$type,$desc]);
    }
}

/* ophalen versies */
$versions = $pdo->query("
SELECT *
FROM changelog_versions
ORDER BY id DESC
")->fetchAll();

/* ophalen items */
$items = $pdo->query("
SELECT i.*,v.version
FROM changelog_items i
LEFT JOIN changelog_versions v
ON v.id = i.version_id
ORDER BY i.created_at DESC
")->fetchAll();

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Changelog</title>
</head>
<body>

<h1>Changelog beheer</h1>

<h2>Nieuwe versie</h2>

<form method="post">
<input type="hidden" name="csrf" value="<?= csrfToken() ?>">

Versie:
<input name="version" placeholder="bijv 1.2.0">

<button name="new_version">Toevoegen</button>
</form>


<h2>Nieuw item</h2>

<form method="post">
<input type="hidden" name="csrf" value="<?= csrfToken() ?>">

Versie
<select name="version_id">
<option value="">Roadmap / wens</option>

<?php foreach($versions as $v): ?>
<option value="<?= $v['id'] ?>">
<?= h($v['version']) ?>
</option>
<?php endforeach; ?>

</select>

Type
<select name="type">
<option value="ADD">ADD</option>
<option value="CHANGE">CHANGE</option>
<option value="FIX">FIX</option>
<option value="IDEA">IDEA</option>
</select>

<br><br>

<textarea name="description" rows="3" cols="60"></textarea>

<br>

<button name="new_item">Opslaan</button>

</form>


<h2>Versie overzicht</h2>

<?php foreach($versions as $v): ?>

<h3>Versie <?= h($v['version']) ?></h3>

<ul>

<?php foreach($items as $i):
if($i['version_id'] != $v['id']) continue;
?>

<li>
<b><?= $i['type'] ?></b> :
<?= h($i['description']) ?>
</li>

<?php endforeach; ?>

</ul>

<?php endforeach; ?>


<h2>Wensenlijst / roadmap</h2>

<ul>

<?php foreach($items as $i):
if($i['type'] !== 'IDEA') continue;
?>

<li><?= h($i['description']) ?></li>

<?php endforeach; ?>

</ul>

</body>
</html>