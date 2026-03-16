<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg   = function_exists('themeImage') ? themeImage('settings', $pdo) : '/assets/images/admin-a.png';

if (!function_exists('changelogBadgeClass')) {
    function changelogBadgeClass(string $type): string {
        return match ($type) {
            'NEW'      => 'badge new',
            'IMPROVE'  => 'badge improve',
            'FIX'      => 'badge fix',
            'SECURITY' => 'badge security',
            'IDEA'     => 'badge idea',
            default    => 'badge',
        };
    }
}

if (!function_exists('changelogTypeLabel')) {
    function changelogTypeLabel(string $type): string {
        return match ($type) {
            'NEW'      => 'Nieuw',
            'IMPROVE'  => 'Verbeteringen',
            'FIX'      => 'Fixes',
            'SECURITY' => 'Security',
            'IDEA'     => 'Wensenlijst',
            default    => $type,
        };
    }
}

if (!function_exists('compareVersionDesc')) {
    function compareVersionDesc(array $a, array $b): int {
        return version_compare((string)$b['version'], (string)$a['version']);
    }
}

if (!function_exists('isLockedVersionValue')) {
    function isLockedVersionValue(string $version, string $appVersion): bool {
        return version_compare($version, $appVersion, '<=');
    }
}

$appVersion = (string)($config['app']['version'] ?? '0.0.0');
$allowedTypes = ['NEW', 'IMPROVE', 'FIX', 'SECURITY', 'IDEA'];

auditLog($pdo, 'PAGE_VIEW', 'admin/changelog.php', [
    'app_version' => $appVersion,
]);

$errors = [];
$success = null;

/* versies laden voor validatie */
$versions = $pdo->query("
    SELECT id, version, release_date, created_at
    FROM changelog_versions
")->fetchAll(PDO::FETCH_ASSOC);

usort($versions, 'compareVersionDesc');

$versionsById = [];
foreach ($versions as $v) {
    $versionsById[(int)$v['id']] = $v;
}

$futureVersions = array_values(array_filter(
    $versions,
    fn(array $v): bool => version_compare((string)$v['version'], $appVersion, '>')
));

$currentVersionRow = null;
foreach ($versions as $v) {
    if ((string)$v['version'] === $appVersion) {
        $currentVersionRow = $v;
        break;
    }
}

/* helper editable target */
$canAssignToVersionId = function (?int $versionId) use ($versionsById, $appVersion): bool {
    if ($versionId === null) {
        return true;
    }
    if (!isset($versionsById[$versionId])) {
        return false;
    }
    return version_compare((string)$versionsById[$versionId]['version'], $appVersion, '>');
};

/* nieuwe toekomstige versie toevoegen */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_version'])) {
    requireCsrf($_POST['csrf'] ?? '');

    $version = trim((string)($_POST['version'] ?? ''));
    $releaseDate = trim((string)($_POST['release_date'] ?? ''));

    if ($version === '') {
        $errors[] = 'Vul een versienummer in.';
    } elseif (!preg_match('/^[0-9]+(\.[0-9]+){1,3}$/', $version)) {
        $errors[] = 'Gebruik een geldig versienummer, bijvoorbeeld 1.2.2 of 1.3.0.';
    } elseif (!version_compare($version, $appVersion, '>')) {
        $errors[] = 'Alleen toekomstige versies groter dan de huidige versie mogen worden toegevoegd.';
    }

    if ($releaseDate !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $releaseDate);
        if (!$dt || $dt->format('Y-m-d') !== $releaseDate) {
            $errors[] = 'Ongeldige releasedatum. Gebruik JJJJ-MM-DD.';
        }
    } else {
        $releaseDate = null;
    }

    if (!$errors) {
        $check = $pdo->prepare("SELECT id FROM changelog_versions WHERE version = ? LIMIT 1");
        $check->execute([$version]);

        if ($check->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = 'Deze versie bestaat al.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO changelog_versions (version, release_date)
                VALUES (?, ?)
            ");
            $stmt->execute([$version, $releaseDate]);

            auditLog($pdo, 'CHANGELOG_VERSION_ADD', 'Toekomstige versie toegevoegd', [
                'version' => $version,
                'release_date' => $releaseDate,
            ]);

            header('Location: /admin/changelog.php?msg=version_added');
            exit;
        }
    }
}

/* nieuw item toevoegen */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_item'])) {
    requireCsrf($_POST['csrf'] ?? '');

    $versionIdRaw = trim((string)($_POST['version_id'] ?? ''));
    $type         = strtoupper(trim((string)($_POST['type'] ?? '')));
    $description  = trim((string)($_POST['description'] ?? ''));
    $authorName   = trim((string)($_POST['author_name'] ?? ''));

    $versionId = ($versionIdRaw === '') ? null : (int)$versionIdRaw;

    if (!in_array($type, $allowedTypes, true)) {
        $errors[] = 'Ongeldig changelog-type.';
    }

    if ($description === '') {
        $errors[] = 'Vul een omschrijving in.';
    }

    if ($authorName === '') {
        $authorName = (string)($user['username'] ?? '');
    }

    if (!$canAssignToVersionId($versionId)) {
        $errors[] = 'Je kunt alleen items koppelen aan toekomstige versies of aan de wensenlijst.';
    }

    if ($versionId === null && $type !== 'IDEA') {
        $errors[] = 'Zonder doelversie mag alleen een IDEA / wensenlijst-item worden opgeslagen.';
    }

    if ($versionId !== null && $type === 'IDEA') {
        /* toegestaan: wishlist-item gericht op toekomstige versie */
    }

    if (!$errors) {
        $stmt = $pdo->prepare("
            INSERT INTO changelog_items (version_id, type, description, author_name)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$versionId, $type, $description, $authorName]);

        auditLog($pdo, 'CHANGELOG_ITEM_ADD', 'Nieuw changelog-item toegevoegd', [
            'type' => $type,
            'version_id' => $versionId,
            'description' => mb_substr($description, 0, 180),
        ]);

        header('Location: /admin/changelog.php?msg=item_added');
        exit;
    }
}

/* bestaand item aanpassen */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    requireCsrf($_POST['csrf'] ?? '');

    $itemId       = (int)($_POST['item_id'] ?? 0);
    $versionIdRaw = trim((string)($_POST['version_id'] ?? ''));
    $type         = strtoupper(trim((string)($_POST['type'] ?? '')));
    $description  = trim((string)($_POST['description'] ?? ''));
    $authorName   = trim((string)($_POST['author_name'] ?? ''));

    $versionId = ($versionIdRaw === '') ? null : (int)$versionIdRaw;

    $st = $pdo->prepare("
        SELECT i.id, i.version_id, i.type, i.description, i.author_name, v.version
        FROM changelog_items i
        LEFT JOIN changelog_versions v ON v.id = i.version_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $st->execute([$itemId]);
    $existingItem = $st->fetch(PDO::FETCH_ASSOC);

    if (!$existingItem) {
        $errors[] = 'Het item bestaat niet meer.';
    } else {
        $existingVersion = $existingItem['version'] ?? null;
        $existingLocked = ($existingVersion !== null && isLockedVersionValue((string)$existingVersion, $appVersion));

        if ($existingLocked) {
            $errors[] = 'Items van de huidige of eerdere versies zijn vergrendeld en kunnen niet meer worden aangepast.';
        }
    }

    if (!in_array($type, $allowedTypes, true)) {
        $errors[] = 'Ongeldig changelog-type.';
    }

    if ($description === '') {
        $errors[] = 'Vul een omschrijving in.';
    }

    if ($authorName === '') {
        $authorName = (string)($user['username'] ?? '');
    }

    if (!$canAssignToVersionId($versionId)) {
        $errors[] = 'Je kunt alleen opslaan naar toekomstige versies of naar de wensenlijst.';
    }

    if ($versionId === null && $type !== 'IDEA') {
        $errors[] = 'Zonder doelversie mag alleen een IDEA / wensenlijst-item worden opgeslagen.';
    }

    if (!$errors) {
        $upd = $pdo->prepare("
            UPDATE changelog_items
            SET version_id = ?, type = ?, description = ?, author_name = ?
            WHERE id = ?
            LIMIT 1
        ");
        $upd->execute([$versionId, $type, $description, $authorName, $itemId]);

        auditLog($pdo, 'CHANGELOG_ITEM_UPDATE', 'Changelog-item aangepast', [
            'item_id' => $itemId,
            'type' => $type,
            'version_id' => $versionId,
            'description' => mb_substr($description, 0, 180),
        ]);

        header('Location: /admin/changelog.php?msg=item_updated');
        exit;
    }
}

/* melding */
$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'version_added') {
    $success = 'Nieuwe toekomstige versie toegevoegd.';
} elseif ($msg === 'item_added') {
    $success = 'Nieuw changelog-item opgeslagen.';
} elseif ($msg === 'item_updated') {
    $success = 'Changelog-item aangepast.';
}

/* items ophalen */
$items = $pdo->query("
    SELECT
        i.id,
        i.version_id,
        i.type,
        i.description,
        i.author_name,
        i.created_at,
        v.version,
        v.release_date
    FROM changelog_items i
    LEFT JOIN changelog_versions v ON v.id = i.version_id
    ORDER BY i.created_at DESC, i.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$itemsByVersion = [];
$roadmapItems = [];
$editableItems = [];

foreach ($items as $item) {
    $vid = $item['version_id'] !== null ? (int)$item['version_id'] : null;
    $type = (string)$item['type'];
    $itemVersion = $item['version'] ?? null;

    $isEditable = ($itemVersion === null)
        ? true
        : version_compare((string)$itemVersion, $appVersion, '>');

    if ($isEditable) {
        $editableItems[] = $item;
    }

    if ($type === 'IDEA' || $vid === null) {
        $roadmapItems[] = $item;
        continue;
    }

    if (!isset($itemsByVersion[$vid])) {
        $itemsByVersion[$vid] = [
            'NEW' => [],
            'IMPROVE' => [],
            'FIX' => [],
            'SECURITY' => [],
        ];
    }

    if (isset($itemsByVersion[$vid][$type])) {
        $itemsByVersion[$vid][$type][] = $item;
    }
}

$currentGroups = [
    'NEW' => [],
    'IMPROVE' => [],
    'FIX' => [],
    'SECURITY' => [],
];

if ($currentVersionRow) {
    $currentGroups = $itemsByVersion[(int)$currentVersionRow['id']] ?? $currentGroups;
}

$releasedVersions = array_values(array_filter(
    $versions,
    fn(array $v): bool => version_compare((string)$v['version'], $appVersion, '<=')
));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Changelog beheer</title>
  <style>
    :root{
      --text: #fff;
      --muted: rgba(255,255,255,.78);
      --border: rgba(255,255,255,.22);
      --glass: rgba(255,255,255,.12);
      --glass2: rgba(255,255,255,.06);
      --shadow: 0 14px 40px rgba(0,0,0,.45);
      --ok: rgba(70, 190, 120, .22);
      --okb: rgba(120, 255, 170, .32);
      --err: rgba(210, 80, 80, .22);
      --errb: rgba(255, 140, 140, .30);
      --warn: rgba(225, 180, 70, .18);
      --warnb: rgba(255, 210, 120, .34);
    }

    *{box-sizing:border-box}

    body{
      margin:0;
      font-family: Arial, sans-serif;
      color: var(--text);
      background:url('<?= h($bg) ?>') no-repeat center center fixed;
      background-size:cover;
    }

    .backdrop{
      min-height: 100vh;
      background:
        radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
        linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
      padding: 26px;
      display:flex;
      justify-content:center;
    }

    .wrap{ width: min(1380px, 96vw); }

    .topbar{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom: 14px;
    }

    .brand h1{ margin:0; font-size: 28px; letter-spacing: .5px; }
    .brand .sub{ margin-top:6px; color: var(--muted); font-size: 14px; }

    .userbox{
      background: var(--glass);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px 14px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      min-width: 260px;
    }
    .userbox .line1{font-weight:bold}
    .userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}

    .panel{
      border-radius: 20px;
      border: 1px solid rgba(255,255,255,.18);
      background: linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
      box-shadow: var(--shadow);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      padding: 18px;
    }

    a{color:#fff;text-decoration:none}
    a:hover{color:#ffd9b3}
    a:visited{color:#ffe0c2}

    .pagehead{
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom: 12px;
    }
    .pagehead h2{margin:0;font-size:20px}
    .pagehead .hint{color:var(--muted);font-size:13px;margin-top:6px}

    .kbar{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top: 12px;
      margin-bottom: 14px;
    }
    .kbtn{
      display:inline-block;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow: 0 10px 22px rgba(0,0,0,.28);
      font-weight: 800;
    }

    .success,.errorbox,.notice,.warning{
      margin-bottom: 14px;
      padding: 12px 14px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.20);
      font-size: 13px;
      line-height: 1.45;
    }
    .success{background: var(--ok); border-color: var(--okb); color:#f4fff7;}
    .errorbox{background: var(--err); border-color: var(--errb); color:#fff3f3;}
    .warning{background: var(--warn); border-color: var(--warnb); color:#fff8ec;}
    .notice{background: rgba(255,255,255,.06); color: var(--muted);}
    .errorbox ul{margin:0;padding-left:18px}

    .hero{
      display:grid;
      grid-template-columns: 1.1fr .9fr;
      gap:16px;
      margin-bottom:16px;
    }

    .grid{
      display:grid;
      grid-template-columns: 420px 1fr;
      gap:16px;
      align-items:start;
    }

    @media (max-width: 1100px){
      .hero,.grid{grid-template-columns:1fr}
      .userbox{min-width: unset; width: 100%;}
    }

    .card{
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow: 0 10px 22px rgba(0,0,0,.30);
      padding: 14px;
    }

    .sectiontitle{margin:0 0 10px 0;font-size:17px}
    .subtle{color:var(--muted);font-size:13px}

    .formrow{margin-bottom:12px}
    .label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}

    input[type="text"],
    input[type="date"],
    select,
    textarea{
      width:100%;
      border:1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.10);
      color:#fff;
      border-radius:12px;
      padding:10px 12px;
      outline:none;
      font: inherit;
    }

    textarea{min-height:110px;resize:vertical}
    select option{color:#111;background:#fff}

    .btn{
      display:inline-block;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow: 0 10px 22px rgba(0,0,0,.28);
      font-weight: 800;
      color:#fff;
      cursor:pointer;
    }

    .versionhero{
      padding:16px;
      border-radius:18px;
      border:1px solid rgba(255,255,255,.20);
      background: linear-gradient(180deg, rgba(255,255,255,.16), rgba(255,255,255,.07));
      box-shadow: 0 10px 24px rgba(0,0,0,.28);
    }
    .vlabel{color:var(--muted);font-size:13px;margin-bottom:6px}
    .vnumber{font-size:30px;font-weight:800;margin:0 0 8px 0}
    .vmeta{color:var(--muted);font-size:13px;margin-bottom:10px}

    .quickstats{
      display:grid;
      grid-template-columns: repeat(4, 1fr);
      gap:10px;
    }
    @media (max-width: 700px){
      .quickstats{grid-template-columns: repeat(2, 1fr);}
    }

    .stat{
      padding:12px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.05);
    }
    .stat .n{font-size:24px;font-weight:800;line-height:1;margin-bottom:5px}
    .stat .t{font-size:12px;color:var(--muted)}

    .itemlist{
      list-style:none;
      padding:0;
      margin:0;
      display:flex;
      flex-direction:column;
      gap:10px;
    }

    .item{
      display:flex;
      gap:10px;
      align-items:flex-start;
      padding:10px 12px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.05);
    }

    .badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:94px;
      padding:7px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:800;
      border:1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.10);
      flex:0 0 auto;
    }
    .badge.new{background:rgba(70, 180, 110, .26)}
    .badge.improve{background:rgba(70, 120, 220, .26)}
    .badge.fix{background:rgba(205, 145, 55, .26)}
    .badge.security{background:rgba(190, 70, 70, .26)}
    .badge.idea{background:rgba(140, 95, 205, .26)}
    .badge.locked{background:rgba(160,160,160,.26)}
    .badge.future{background:rgba(90,160,230,.26)}

    .itembody{flex:1}
    .itemtext{line-height:1.45}
    .itemmeta{margin-top:6px;font-size:12px;color:var(--muted)}
    .empty{color:var(--muted);font-size:13px;padding:6px 0 2px 0}

    .versionblock{
      margin-top:16px;
      padding-top:14px;
      border-top:1px solid rgba(255,255,255,.10);
    }
    .versionblock:first-child{margin-top:0;padding-top:0;border-top:none}

    .typetitle{
      display:flex;
      align-items:center;
      gap:10px;
      margin:0 0 10px 0;
      font-size:15px;
    }

    .typeblock{
      margin-top:14px;
      padding-top:14px;
      border-top:1px solid rgba(255,255,255,.10);
    }
    .typeblock:first-child{margin-top:0;padding-top:0;border-top:none}

    .editbox{
      margin-top:10px;
      padding:12px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.04);
    }

    code{
      color:#fff;
      background: rgba(0,0,0,.25);
      padding: 2px 6px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,.12);
    }
  </style>
</head>

<body>
  <div class="backdrop">
    <div class="wrap">
      <div class="topbar">
        <div class="brand">
          <h1>Porbeheer</h1>
          <div class="sub">POP Oefenruimte Zevenaar • changelog beheer</div>
        </div>

        <div class="userbox">
          <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · Jouw rol is <?= h($role) ?></div>
          <div class="line2">
            <a href="/admin/dashboard.php">Dashboard</a>
            &nbsp;•&nbsp;
            <a href="/changelog.php">Publieke changelog</a>
            &nbsp;•&nbsp;
            <a href="/logout.php">Uitloggen</a>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="pagehead">
          <div>
            <h2>Changelog beheer</h2>
            <div class="hint">Alleen toekomstige versies en wensenlijst-items zijn wijzigbaar. Huidige en eerdere versies zijn vergrendeld.</div>
          </div>
        </div>

        <div class="kbar">
          <a class="kbtn" href="/changelog.php">Publiek overzicht</a>
        </div>

        <?php if ($success): ?>
          <div class="success"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="errorbox">
            <ul>
              <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="warning">
          Huidige versie uit configuratie: <code><?= h($appVersion) ?></code>.
          Alles met versie <code>&lt;= <?= h($appVersion) ?></code> is geblokkeerd voor wijziging.
        </div>

        <div class="hero">
          <div class="versionhero">
            <div class="vlabel">Actieve applicatieversie</div>
            <div class="vnumber">Versie <?= h($appVersion) ?></div>
            <div class="vmeta">
              Deze versie komt uit <code>config.php</code> en bepaalt welke changelog-versies vergrendeld zijn.
            </div>

            <div class="quickstats">
              <div class="stat">
                <div class="n"><?= count($releasedVersions) ?></div>
                <div class="t">Vergrendelde versies</div>
              </div>
              <div class="stat">
                <div class="n"><?= count($futureVersions) ?></div>
                <div class="t">Toekomstige versies</div>
              </div>
              <div class="stat">
                <div class="n"><?= count($roadmapItems) ?></div>
                <div class="t">Wensenlijst-items</div>
              </div>
              <div class="stat">
                <div class="n"><?= count($editableItems) ?></div>
                <div class="t">Wijzigbare items</div>
              </div>
            </div>
          </div>

          <div class="card">
            <h3 class="sectiontitle">Regels</h3>
            <ul class="itemlist">
              <li class="item"><span class="badge locked">LOCKED</span><div class="itembody"><div class="itemtext">Huidige en eerdere versies mogen niet meer worden aangepast</div></div></li>
              <li class="item"><span class="badge future">FUTURE</span><div class="itembody"><div class="itemtext">Nieuwe items mogen aan toekomstige versies worden gekoppeld</div></div></li>
              <li class="item"><span class="badge idea">IDEA</span><div class="itembody"><div class="itemtext">Wensenlijst-item zonder versie of gericht op een latere versie</div></div></li>
            </ul>
          </div>
        </div>

        <div class="grid">
          <div>
            <div class="card" style="margin-bottom:16px;">
              <h3 class="sectiontitle">Nieuwe toekomstige versie</h3>
              <div class="subtle" style="margin-bottom:10px;">Alleen versies groter dan <?= h($appVersion) ?> zijn toegestaan.</div>

              <form method="post" action="/admin/changelog.php">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">

                <div class="formrow">
                  <label class="label" for="version">Versienummer</label>
                  <input type="text" id="version" name="version" placeholder="bijv. 1.2.2 of 1.3.0">
                </div>

                <div class="formrow">
                  <label class="label" for="release_date">Geplande releasedatum</label>
                  <input type="date" id="release_date" name="release_date">
                </div>

                <button class="btn" type="submit" name="new_version" value="1">Versie toevoegen</button>
              </form>
            </div>

            <div class="card" style="margin-bottom:16px;">
              <h3 class="sectiontitle">Nieuw changelog-item</h3>

              <form method="post" action="/admin/changelog.php">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">

                <div class="formrow">
                  <label class="label" for="version_id">Doelversie</label>
                  <select id="version_id" name="version_id">
                    <option value="">Wensenlijst / roadmap</option>
                    <?php foreach ($futureVersions as $v): ?>
                      <option value="<?= (int)$v['id'] ?>"><?= h((string)$v['version']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="formrow">
                  <label class="label" for="type">Type</label>
                  <select id="type" name="type">
                    <option value="NEW">NEW</option>
                    <option value="IMPROVE">IMPROVE</option>
                    <option value="FIX">FIX</option>
                    <option value="SECURITY">SECURITY</option>
                    <option value="IDEA">IDEA</option>
                  </select>
                </div>

                <div class="formrow">
                  <label class="label" for="description">Omschrijving</label>
                  <textarea id="description" name="description" placeholder="Omschrijf de wijziging, fix of wens..."></textarea>
                </div>

                <div class="formrow">
                  <label class="label" for="author_name">Auteur</label>
                  <input type="text" id="author_name" name="author_name" value="<?= h((string)($user['username'] ?? '')) ?>">
                </div>

                <button class="btn" type="submit" name="new_item" value="1">Item opslaan</button>
              </form>
            </div>

            <div class="notice">
              Laat <code>Doelversie</code> leeg voor de wensenlijst.  
              Zonder doelversie mag alleen <code>IDEA</code> gebruikt worden.
            </div>
          </div>

          <div>
            <div class="card" style="margin-bottom:16px;">
              <h3 class="sectiontitle">Vergrendelde versies</h3>

              <?php if ($releasedVersions): ?>
                <?php foreach ($releasedVersions as $version): ?>
                  <?php
                    $vid = (int)$version['id'];
                    $groups = $itemsByVersion[$vid] ?? [
                      'NEW' => [],
                      'IMPROVE' => [],
                      'FIX' => [],
                      'SECURITY' => [],
                    ];
                  ?>
                  <div class="versionblock">
                    <h4 style="margin:0 0 8px 0;">Versie <?= h((string)$version['version']) ?> <span class="badge locked">LOCKED</span></h4>
                    <div class="subtle">
                      Releasedatum:
                      <?= !empty($version['release_date']) ? h((string)$version['release_date']) : 'nog niet ingevuld' ?>
                    </div>

                    <?php foreach (['NEW','IMPROVE','FIX','SECURITY'] as $typeKey): ?>
                      <div class="typeblock">
                        <h5 class="typetitle">
                          <span class="<?= h(changelogBadgeClass($typeKey)) ?>"><?= h($typeKey) ?></span>
                          <span><?= h(changelogTypeLabel($typeKey)) ?></span>
                        </h5>

                        <?php if (!empty($groups[$typeKey])): ?>
                          <ul class="itemlist">
                            <?php foreach ($groups[$typeKey] as $item): ?>
                              <li class="item">
                                <span class="<?= h(changelogBadgeClass((string)$item['type'])) ?>"><?= h((string)$item['type']) ?></span>
                                <div class="itembody">
                                  <div class="itemtext"><?= nl2br(h((string)$item['description'])) ?></div>
                                  <div class="itemmeta">
                                    <?= !empty($item['author_name']) ? 'Door ' . h((string)$item['author_name']) . ' • ' : '' ?>
                                    <?= h((string)$item['created_at']) ?>
                                  </div>
                                </div>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        <?php else: ?>
                          <div class="empty">Geen items in deze categorie.</div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="empty">Er zijn nog geen vergrendelde versies.</div>
              <?php endif; ?>
            </div>

            <div class="card" style="margin-bottom:16px;">
              <h3 class="sectiontitle">Wijzigbare toekomstige items</h3>

              <?php if ($editableItems): ?>
                <?php foreach ($editableItems as $item): ?>
                  <div class="item" style="margin-bottom:12px;">
                    <span class="<?= h(changelogBadgeClass((string)$item['type'])) ?>"><?= h((string)$item['type']) ?></span>

                    <div class="itembody">
                      <div class="itemtext"><?= nl2br(h((string)$item['description'])) ?></div>
                      <div class="itemmeta">
                        <?= !empty($item['author_name']) ? 'Door ' . h((string)$item['author_name']) . ' • ' : '' ?>
                        <?= h((string)$item['created_at']) ?>
                        <?php if (!empty($item['version'])): ?>
                          &nbsp;•&nbsp; Doelversie: <?= h((string)$item['version']) ?>
                        <?php else: ?>
                          &nbsp;•&nbsp; Wensenlijst
                        <?php endif; ?>
                      </div>

                      <div class="editbox">
                        <form method="post" action="/admin/changelog.php">
                          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                          <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">

                          <div class="formrow">
                            <label class="label">Doelversie</label>
                            <select name="version_id">
                              <option value="" <?= $item['version_id'] === null ? 'selected' : '' ?>>Wensenlijst / roadmap</option>
                              <?php foreach ($futureVersions as $v): ?>
                                <option value="<?= (int)$v['id'] ?>" <?= ((int)$item['version_id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                  <?= h((string)$v['version']) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>

                          <div class="formrow">
                            <label class="label">Type</label>
                            <select name="type">
                              <?php foreach ($allowedTypes as $type): ?>
                                <option value="<?= h($type) ?>" <?= ((string)$item['type'] === $type) ? 'selected' : '' ?>>
                                  <?= h($type) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>

                          <div class="formrow">
                            <label class="label">Omschrijving</label>
                            <textarea name="description"><?= h((string)$item['description']) ?></textarea>
                          </div>

                          <div class="formrow">
                            <label class="label">Auteur</label>
                            <input type="text" name="author_name" value="<?= h((string)($item['author_name'] ?? '')) ?>">
                          </div>

                          <button class="btn" type="submit" name="update_item" value="1">Item aanpassen</button>
                        </form>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="empty">Er zijn nog geen wijzigbare toekomstige of roadmap-items.</div>
              <?php endif; ?>
            </div>

            <div class="card">
              <h3 class="sectiontitle">Roadmap / wensenlijst</h3>

              <?php if ($roadmapItems): ?>
                <ul class="itemlist">
                  <?php foreach ($roadmapItems as $item): ?>
                    <li class="item">
                      <span class="<?= h(changelogBadgeClass('IDEA')) ?>">IDEA</span>
                      <div class="itembody">
                        <div class="itemtext"><?= nl2br(h((string)$item['description'])) ?></div>
                        <div class="itemmeta">
                          <?= !empty($item['author_name']) ? 'Door ' . h((string)$item['author_name']) . ' • ' : '' ?>
                          <?= h((string)$item['created_at']) ?>
                          <?php if (!empty($item['version'])): ?>
                            &nbsp;•&nbsp; Doelversie: <?= h((string)$item['version']) ?>
                          <?php endif; ?>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="empty">Er staan nog geen roadmap-items in de wensenlijst.</div>
              <?php endif; ?>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>