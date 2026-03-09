<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg   = themeImage('settings', $pdo);

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

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

auditLog($pdo, 'PAGE_VIEW', 'admin/changelog.php');

$errors = [];
$success = null;

$allowedTypes = ['NEW', 'IMPROVE', 'FIX', 'SECURITY', 'IDEA'];

/* nieuwe versie toevoegen */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_version'])) {
    requireCsrf($_POST['csrf'] ?? '');

    $version = trim((string)($_POST['version'] ?? ''));
    $releaseDate = trim((string)($_POST['release_date'] ?? ''));

    if ($version === '') {
        $errors[] = 'Vul een versienummer in.';
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

            auditLog($pdo, 'CHANGELOG_VERSION_ADD', 'Versie toegevoegd: ' . $version);

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

    if (!$errors) {
        $stmt = $pdo->prepare("
            INSERT INTO changelog_items (version_id, type, description, author_name)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$versionId, $type, $description, $authorName]);

        auditLog(
            $pdo,
            'CHANGELOG_ITEM_ADD',
            'Type=' . $type . '; versie_id=' . ($versionId === null ? 'NULL' : (string)$versionId) . '; omschrijving=' . mb_substr($description, 0, 180)
        );

        header('Location: /admin/changelog.php?msg=item_added');
        exit;
    }
}

/* melding */
$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'version_added') {
    $success = 'Nieuwe versie toegevoegd.';
} elseif ($msg === 'item_added') {
    $success = 'Nieuw changelog-item opgeslagen.';
}

/* versies ophalen */
$versions = $pdo->query("
    SELECT id, version, release_date, created_at
    FROM changelog_versions
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

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
    ORDER BY
        COALESCE(i.version_id, 0) DESC,
        CASE i.type
            WHEN 'NEW' THEN 1
            WHEN 'IMPROVE' THEN 2
            WHEN 'FIX' THEN 3
            WHEN 'SECURITY' THEN 4
            WHEN 'IDEA' THEN 5
            ELSE 9
        END,
        i.created_at DESC,
        i.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* nieuwste versie */
$currentVersion = $versions[0] ?? null;

/* items per versie groeperen */
$itemsByVersion = [];
$roadmapItems = [];

foreach ($items as $item) {
    $vid = $item['version_id'] !== null ? (int)$item['version_id'] : null;

    if (($item['type'] ?? '') === 'IDEA' || $vid === null) {
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

    if (isset($itemsByVersion[$vid][$item['type']])) {
        $itemsByVersion[$vid][$item['type']][] = $item;
    }
}

$currentGroups = [
    'NEW' => [],
    'IMPROVE' => [],
    'FIX' => [],
    'SECURITY' => [],
];

if ($currentVersion) {
    $currentGroups = $itemsByVersion[(int)$currentVersion['id']] ?? $currentGroups;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Changelog</title>

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
      box-sizing: border-box;
      display:flex;
      justify-content:center;
    }

    .wrap{ width: min(1280px, 96vw); }

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
      margin-top: 10px;
      border-radius: 20px;
      border: 1px solid rgba(255,255,255,.18);
      background: linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
      box-shadow: var(--shadow);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      padding: 18px;
    }

    a{color:#fff;text-decoration:none;transition:color .15s ease}
    a:hover{color:#ffd9b3}
    a:visited{color:#ffe0c2}

    .pagehead{
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom: 10px;
    }
    .pagehead h2{margin:0;font-size:20px;letter-spacing:.2px}
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
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      font-weight: 800;
      letter-spacing: .2px;
      transition: transform .12s ease, border-color .12s ease, background .12s ease;
    }
    .kbtn:hover{
      transform: translateY(-2px);
      border-color: rgba(255,255,255,.38);
      background: linear-gradient(180deg, rgba(255,255,255,.20), rgba(255,255,255,.08));
    }

    .success,
    .errorbox,
    .notice{
      margin-bottom: 14px;
      padding: 12px 14px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.20);
      font-size: 13px;
      line-height: 1.45;
    }
    .success{
      background: var(--ok);
      border-color: var(--okb);
      color:#f4fff7;
    }
    .errorbox{
      background: var(--err);
      border-color: var(--errb);
      color:#fff3f3;
    }
    .notice{
      background: rgba(255,255,255,.06);
      color: var(--muted);
    }
    .errorbox ul{
      margin:0;
      padding-left:18px;
    }

    .hero{
      display:grid;
      grid-template-columns: 1.25fr .95fr;
      gap:16px;
      margin-bottom:16px;
    }

    .grid{
      display:grid;
      grid-template-columns: 420px 1fr;
      gap:16px;
      align-items:start;
    }

    @media (max-width: 1024px){
      .hero,
      .grid{
        grid-template-columns:1fr;
      }
      .userbox{min-width: unset; width: 100%;}
    }

    .card{
      position: relative;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow: 0 10px 22px rgba(0,0,0,.30);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      overflow:hidden;
      padding: 14px 14px 12px 14px;
      min-height: 110px;
    }
    .card::before{
      content:"";
      position:absolute;
      inset:-40%;
      background: radial-gradient(circle at 20% 30%, rgba(255,255,255,.20), transparent 45%);
      transform: rotate(12deg);
      pointer-events:none;
    }
    .card > *{position:relative}

    .sectiontitle{
      margin:0 0 10px 0;
      font-size: 17px;
      letter-spacing:.2px;
    }

    .formrow{margin-bottom:12px}
    .label{
      display:block;
      font-size:13px;
      color:var(--muted);
      margin-bottom:6px;
    }

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

    textarea{
      min-height:110px;
      resize:vertical;
    }

    select option{
      color:#111;
      background:#fff;
    }

    .btn{
      display:inline-block;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow: 0 10px 22px rgba(0,0,0,.28);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      font-weight: 800;
      letter-spacing: .2px;
      transition: transform .12s ease, border-color .12s ease, background .12s ease;
      color:#fff;
      cursor:pointer;
    }
    .btn:hover{
      transform: translateY(-2px);
      border-color: rgba(255,255,255,.38);
      background: linear-gradient(180deg, rgba(255,255,255,.20), rgba(255,255,255,.08));
    }

    .versionhero{
      padding:16px;
      border-radius:18px;
      border:1px solid rgba(255,255,255,.20);
      background: linear-gradient(180deg, rgba(255,255,255,.16), rgba(255,255,255,.07));
      box-shadow: 0 10px 24px rgba(0,0,0,.28);
    }
    .vlabel{
      color:var(--muted);
      font-size:13px;
      margin-bottom:6px;
    }
    .vnumber{
      font-size:30px;
      font-weight:800;
      margin:0 0 8px 0;
      letter-spacing:.3px;
    }
    .vmeta{
      color:var(--muted);
      font-size:13px;
      margin-bottom:10px;
    }

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
    .stat .n{
      font-size:24px;
      font-weight:800;
      line-height:1;
      margin-bottom:5px;
    }
    .stat .t{
      font-size:12px;
      color:var(--muted);
      letter-spacing:.2px;
    }

    .typeblock{
      margin-top:14px;
      padding-top:14px;
      border-top:1px solid rgba(255,255,255,.10);
    }
    .typeblock:first-child{
      margin-top:0;
      padding-top:0;
      border-top:none;
    }

    .typetitle{
      display:flex;
      align-items:center;
      gap:10px;
      margin:0 0 10px 0;
      font-size:15px;
    }

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
      letter-spacing:.4px;
      border:1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.10);
      flex:0 0 auto;
    }

    .badge.new{background:rgba(70, 180, 110, .26)}
    .badge.improve{background:rgba(70, 120, 220, .26)}
    .badge.fix{background:rgba(205, 145, 55, .26)}
    .badge.security{background:rgba(190, 70, 70, .26)}
    .badge.idea{background:rgba(140, 95, 205, .26)}

    .itembody{flex:1}
    .itemtext{
      line-height:1.45;
      color:#fff;
    }
    .itemmeta{
      margin-top:6px;
      font-size:12px;
      color:var(--muted);
    }

    .empty{
      color:var(--muted);
      font-size:13px;
      padding:6px 0 2px 0;
    }

    .versionblock{
      margin-top:16px;
      padding-top:14px;
      border-top:1px solid rgba(255,255,255,.10);
    }
    .versionblock:first-child{
      margin-top:0;
      padding-top:0;
      border-top:none;
    }

    .versiontitle{
      margin:0 0 8px 0;
      font-size:19px;
    }

    .versionsub{
      color:var(--muted);
      font-size:13px;
      margin-bottom:10px;
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
          <div class="sub">POP Oefenruimte Zevenaar • beheer & planning</div>
        </div>

        <div class="userbox">
          <div class="line1">Hallo <?= h($user['username'] ?? '') ?> · Jouw rol is <?= h($role) ?></div>
          <div class="line2">
            <a href="/admin/dashboard.php">Dashboard</a>
            &nbsp;•&nbsp;
            <a href="/logout.php">Uitloggen</a>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="pagehead">
          <div>
            <h2>Changelog beheer</h2>
            <div class="hint">Versies, verbeteringen, fixes, security-aanpassingen en roadmap in één overzicht.</div>
          </div>
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

        <div class="hero">
          <div class="versionhero">
            <div class="vlabel">Wijzigingen in deze versie</div>

            <?php if ($currentVersion): ?>
              <div class="vnumber">Versie <?= h($currentVersion['version']) ?></div>
              <div class="vmeta">
                Releasedatum:
                <?= !empty($currentVersion['release_date']) ? h((string)$currentVersion['release_date']) : 'nog niet ingevuld' ?>
              </div>

              <?php
                $countNew      = count($currentGroups['NEW']);
                $countImprove  = count($currentGroups['IMPROVE']);
                $countFix      = count($currentGroups['FIX']);
                $countSecurity = count($currentGroups['SECURITY']);
              ?>

              <div class="quickstats">
                <div class="stat">
                  <div class="n"><?= $countNew ?></div>
                  <div class="t">Nieuw</div>
                </div>
                <div class="stat">
                  <div class="n"><?= $countImprove ?></div>
                  <div class="t">Verbeteringen</div>
                </div>
                <div class="stat">
                  <div class="n"><?= $countFix ?></div>
                  <div class="t">Fixes</div>
                </div>
                <div class="stat">
                  <div class="n"><?= $countSecurity ?></div>
                  <div class="t">Security</div>
                </div>
              </div>
            <?php else: ?>
              <div class="vnumber">Nog geen versies</div>
              <div class="empty">Voeg eerst een versie toe om de changelog op te bouwen.</div>
            <?php endif; ?>
          </div>

          <div class="card">
            <h3 class="sectiontitle">Legenda</h3>
            <ul class="itemlist">
              <li class="item">
                <span class="badge new">NEW</span>
                <div class="itembody"><div class="itemtext">Nieuwe functionaliteit</div></div>
              </li>
              <li class="item">
                <span class="badge improve">IMPROVE</span>
                <div class="itembody"><div class="itemtext">Verbetering of aanpassing van bestaande werking</div></div>
              </li>
              <li class="item">
                <span class="badge fix">FIX</span>
                <div class="itembody"><div class="itemtext">Foutoplossing of bugfix</div></div>
              </li>
              <li class="item">
                <span class="badge security">SECURITY</span>
                <div class="itembody"><div class="itemtext">Beveiligingsaanpassing of hardening</div></div>
              </li>
              <li class="item">
                <span class="badge idea">IDEA</span>
                <div class="itembody"><div class="itemtext">Wens voor volgende versies / roadmap</div></div>
              </li>
            </ul>
          </div>
        </div>

        <div class="grid">
          <div>
            <div class="card" style="margin-bottom:16px;">
              <h3 class="sectiontitle">Nieuwe versie toevoegen</h3>

              <form method="post" action="/admin/changelog.php">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">

                <div class="formrow">
                  <label class="label" for="version">Versienummer</label>
                  <input type="text" id="version" name="version" placeholder="bijv. 1.3.0">
                </div>

                <div class="formrow">
                  <label class="label" for="release_date">Releasedatum</label>
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
                  <label class="label" for="version_id">Versie</label>
                  <select id="version_id" name="version_id">
                    <option value="">Roadmap / wenslijst</option>
                    <?php foreach ($versions as $v): ?>
                      <option value="<?= (int)$v['id'] ?>">
                        <?= h((string)$v['version']) ?>
                      </option>
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
                  <textarea id="description" name="description" placeholder="Omschrijf de wijziging, fix, security-aanpassing of wens..."></textarea>
                </div>

                <div class="formrow">
                  <label class="label" for="author_name">Auteur</label>
                  <input type="text" id="author_name" name="author_name" value="<?= h((string)($user['username'] ?? '')) ?>" placeholder="Naam van de invoerder">
                </div>

                <button class="btn" type="submit" name="new_item" value="1">Item opslaan</button>
              </form>
            </div>

            <div class="notice">
              Laat <code>Versie</code> leeg en kies <code>IDEA</code> om iets op de roadmap te zetten.
              Gebruik <code>SECURITY</code> voor bijvoorbeeld CSRF, rechtencontrole, logging, validatie of hardening.
            </div>
          </div>

          <div>
            <div class="card" style="margin-bottom:16px;">
              <h3 class="sectiontitle">Huidige versie</h3>

              <?php if ($currentVersion): ?>
                <?php foreach (['NEW' => 'Nieuw', 'IMPROVE' => 'Verbeteringen', 'FIX' => 'Fixes', 'SECURITY' => 'Security'] as $typeKey => $typeLabel): ?>
                  <div class="typeblock">
                    <h4 class="typetitle">
                      <span class="<?= h(changelogBadgeClass($typeKey)) ?>"><?= h($typeKey) ?></span>
                      <span><?= h($typeLabel) ?></span>
                    </h4>

                    <?php if (!empty($currentGroups[$typeKey])): ?>
                      <ul class="itemlist">
                        <?php foreach ($currentGroups[$typeKey] as $item): ?>
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
              <?php else: ?>
                <div class="empty">Er is nog geen huidige versie beschikbaar.</div>
              <?php endif; ?>
            </div>

            <div class="card" style="margin-bottom:16px;">
              <h3 class="sectiontitle">Alle versies</h3>

              <?php if ($versions): ?>
                <?php foreach ($versions as $version): ?>
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
                    <h4 class="versiontitle">Versie <?= h((string)$version['version']) ?></h4>
                    <div class="versionsub">
                      Releasedatum:
                      <?= !empty($version['release_date']) ? h((string)$version['release_date']) : 'nog niet ingevuld' ?>
                    </div>

                    <?php foreach (['NEW' => 'Nieuw', 'IMPROVE' => 'Verbeteringen', 'FIX' => 'Fixes', 'SECURITY' => 'Security'] as $typeKey => $typeLabel): ?>
                      <div class="typeblock">
                        <h5 class="typetitle">
                          <span class="<?= h(changelogBadgeClass($typeKey)) ?>"><?= h($typeKey) ?></span>
                          <span><?= h($typeLabel) ?></span>
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
                <div class="empty">Er zijn nog geen versies aangemaakt.</div>
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