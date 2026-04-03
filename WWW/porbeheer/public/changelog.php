<?php
declare(strict_types=1);

require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../libs/porbeheer/app/auth.php';
include __DIR__ . '/assets/includes/header.php';

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

$bg = function_exists('themeImage')
    ? themeImage('settings', $pdo)
    : '/assets/images/admin-a.png';

$appVersion = (string)($config['app']['version'] ?? '0.0.0');

auditLog($pdo, 'PAGE_VIEW', 'public/changelog.php', [
    'app_version' => $appVersion,
]);

$versions = $pdo->query("
    SELECT id, version, release_date, created_at
    FROM changelog_versions
")->fetchAll(PDO::FETCH_ASSOC);

usort($versions, function (array $a, array $b): int {
    return version_compare((string)$b['version'], (string)$a['version']);
});

$items = $pdo->query("
    SELECT
        i.id,
        i.version_id,
        i.type,
        i.description,
        i.author_name,
        i.created_at
    FROM changelog_items i
    ORDER BY i.created_at DESC, i.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$itemsByVersion = [];
$roadmap = [];
$orphans = [];

foreach ($items as $i) {
    $type = (string)($i['type'] ?? '');
    $versionId = $i['version_id'] !== null ? (int)$i['version_id'] : null;

    if ($type === 'IDEA') {
        $roadmap[] = $i;
        continue;
    }

    if ($versionId === null) {
        $orphans[] = $i;
        continue;
    }

    if (!isset($itemsByVersion[$versionId])) {
        $itemsByVersion[$versionId] = [];
    }

    $itemsByVersion[$versionId][] = $i;
}

function tagClass(string $type): string
{
    return match ($type) {
        'NEW'      => 'new',
        'IMPROVE'  => 'improve',
        'FIX'      => 'fix',
        'SECURITY' => 'security',
        'IDEA'     => 'idea',
        default    => ''
    };
}

function formatTypeLabel(string $type): string
{
    return strtolower($type);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer changelog</title>

<style>
:root{
  --text:#fff;
  --muted:rgba(255,255,255,.78);
  --border:rgba(255,255,255,.22);
  --glass:rgba(255,255,255,.12);
  --glass2:rgba(255,255,255,.06);
  --shadow:0 14px 40px rgba(0,0,0,.45);
}

*{box-sizing:border-box}

body{
  margin:0;
  font-family:Arial,sans-serif;
  color:var(--text);
  background:url('<?= h($bg) ?>') no-repeat center center fixed;
  background-size:cover;
}

a{color:#fff;text-decoration:none}
a:hover{color:#ffd9b3}
a:visited{color:#ffe0c2}

.backdrop{
  min-height:100vh;
  background:
    radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
    linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
  padding:26px;
  display:flex;
  justify-content:center;
}

.wrap{
  width:min(1100px,96vw);
}

.topbar{
  display:flex;
  justify-content:space-between;
  align-items:flex-end;
  margin-bottom:14px;
  flex-wrap:wrap;
  gap:16px;
}

.brand h1{
  margin:0;
  font-size:28px;
}

.brand .sub{
  margin-top:6px;
  color:var(--muted);
  font-size:14px;
}

.userbox{
  background:var(--glass);
  border:1px solid var(--border);
  border-radius:14px;
  padding:12px 14px;
  box-shadow:var(--shadow);
  backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);
  min-width:260px;
}

.line1{
  font-weight:bold;
}

.line2{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  font-size:13px;
  margin-top:4px;
  color:var(--muted);
}

.panel{
  border-radius:20px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  padding:22px;
}

.changelog{
  max-width:860px;
  margin:auto;
  line-height:1.55;
}

.page-title{
  margin:0 0 10px 0;
  font-size:30px;
}

.meta{
  color:var(--muted);
  font-size:13px;
  margin-bottom:18px;
}

.year{
  font-size:28px;
  margin-top:34px;
  border-bottom:1px solid rgba(255,255,255,.20);
  padding-bottom:6px;
}

.release{
  margin-top:18px;
}

.release h3{
  margin:0;
  font-size:18px;
  font-weight:700;
}

.release small{
  color:var(--muted);
}

.release ul,
.section ul{
  margin-top:8px;
  padding-left:22px;
}

.release li,
.section li{
  margin-bottom:6px;
}

.tag{
  font-weight:bold;
  margin-right:6px;
}

.tag.new{color:#4ade80}
.tag.improve{color:#60a5fa}
.tag.fix{color:#f59e0b}
.tag.security{color:#f87171}
.tag.idea{color:#a78bfa}

.section{
  margin-top:40px;
}

.section h2{
  margin:0 0 10px 0;
  font-size:22px;
}

.note{
  color:var(--muted);
  font-size:13px;
}

@media (max-width: 720px){
  .userbox{
    width:100%;
    min-width:unset;
  }

  .line2{
    flex-wrap:wrap;
  }
}
</style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div class="brand">
        <h1>Porbeheer</h1>
        <div class="sub">POP Oefenruimte Zevenaar • changelog</div>
      </div>

      <div class="userbox">
        <div class="line1">
          Hallo <a href="/admin/account.php"><?= h($user['username'] ?? '') ?></a> · Jouw rol is <?= h($role) ?>
        </div>

        <div class="line2">
          <span>
            <a href="/admin/dashboard.php">Dashboard</a> •
            <a href="/logout.php">Uitloggen</a>
          </span>

          <a href="/changelog.php">v<?= h($appVersion) ?></a>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="changelog">

        <h1 class="page-title">Changelog</h1>
        <div class="meta">Huidige versie: <?= h($appVersion) ?></div>

        <?php
        $lastYear = null;

        foreach ($versions as $version):
            $dateValue = !empty($version['release_date']) ? (string)$version['release_date'] : (string)$version['created_at'];
            $year = date('Y', strtotime($dateValue));

            if ($year !== $lastYear):
        ?>
          <div class="year"><?= h($year) ?></div>
        <?php
              $lastYear = $year;
            endif;

            $vid = (int)$version['id'];
            $list = $itemsByVersion[$vid] ?? [];
        ?>
          <div class="release">
            <h3>
              Version <?= h((string)$version['version']) ?>
              <?php if (!empty($version['release_date'])): ?>
                <small>(<?= h((string)$version['release_date']) ?>)</small>
              <?php endif; ?>
            </h3>

            <?php if ($list): ?>
              <ul>
                <?php foreach ($list as $item): ?>
                  <li>
                    <span class="tag <?= h(tagClass((string)$item['type'])) ?>">
                      (<?= h(formatTypeLabel((string)$item['type'])) ?>)
                    </span>
                    <?= h((string)$item['description']) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="note">Geen items voor deze versie.</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($roadmap): ?>
          <div class="section">
            <h2>Roadmap / wensenlijst</h2>
            <ul>
              <?php foreach ($roadmap as $item): ?>
                <li>
                  <span class="tag idea">(idea)</span>
                  <?= h((string)$item['description']) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($orphans): ?>
          <div class="section">
            <h2>Controle nodig</h2>
            <div class="note">
              Deze items hebben geen versie-id en zijn geen IDEA. Ze horen dus waarschijnlijk niet in de roadmap.
            </div>
            <ul>
              <?php foreach ($orphans as $item): ?>
                <li>
                  <span class="tag <?= h(tagClass((string)$item['type'])) ?>">
                    (<?= h(formatTypeLabel((string)$item['type'])) ?>)
                  </span>
                  <?= h((string)$item['description']) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>
</body>
</html>