<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireSectionAccess('admin', 'read');

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('admin', $pdo);


$tiles = [
  ['title'=>'Gebruikers',       'href'=>'/admin/users.php',          'section'=>'admin'],
  ['title'=>'Rechtenmatrix',    'href'=>'/admin/rechtenmatrix.php',  'section'=>'admin'],
  ['title'=>'Kasten & Sleutels','href'=>'/admin/lockers_keys.php',   'section'=>'admin'],
  ['title'=>'Configuratie',     'href'=>'/admin/settings.php',       'section'=>'admin'],
  ['title'=>'Audit logging',    'href'=>'/admin/audit_logging.php',  'section'=>'admin'],
  ['title'=>'Database schema',  'href'=>'/admin/db_schema.php',      'section'=>'admin'],
  ['title'=>'Uitloggen',        'href'=>'/logout.php',               'section'=>null],
  ['title'=>'Changelog',        'href'=>'/admin/changelog.php',      'section'=>'admin'],
];

function allowedTile(PDO $pdo, array $tile): bool {
  if ($tile['section'] === null) {
    return true;
  }
  return hasSectionAccess($pdo, $tile['section'], 'read');
}

auditLog($pdo, 'PAGE_VIEW', 'admin/beheer.php');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Beheer</title>
  <link rel="stylesheet" href="/assets/css/main.css">
<style>
.grid{
      display:grid;
      grid-template-columns: repeat(4, minmax(160px, 1fr));
      gap: 14px;
    }
@media (max-width: 960px){
      .grid{grid-template-columns: repeat(2, minmax(160px, 1fr));}
      .userbox{min-width: unset; width: 100%;}
    }
@media (max-width: 520px){
      .grid{grid-template-columns: 1fr;}
    }
.tile{
      position: relative;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, var(--glass), var(--glass2));
      box-shadow: 0 10px 22px rgba(0,0,0,.30);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      overflow:hidden;
      min-height: 92px;

      display:flex;
      align-items:center;
      justify-content:center;

      text-decoration:none;
      color: var(--text);
      font-weight: 800;
      letter-spacing: .2px;

      transition: transform .12s ease, border-color .12s ease, background .12s ease;
      text-align:center;
      padding: 10px 12px;
    }
.tile:hover{
      transform: translateY(-2px);
      border-color: rgba(255,255,255,.38);
      background: linear-gradient(180deg, rgba(255,255,255,.20), rgba(255,255,255,.08));
    }
.tile.disabled{
      opacity:.45;
      pointer-events:none;
      filter: grayscale(35%);
    }
.tile::before{
      content:"";
      position:absolute;
      inset:-40%;
      background: radial-gradient(circle at 20% 30%, rgba(255,255,255,.22), transparent 45%);
      transform: rotate(12deg);

      /* Standaard kleur */
    .tile {
      color: #ffffff;
    }

    /* Bezochte links */
    .tile:visited {
        color: #ffd86b;   /* warme goud/amber tint, goed zichtbaar op donkere achtergrond */
        text-shadow: 0 0 6px rgba(255, 216, 107, 0.6);
    }

    /* Hover blijft dominant */
    .tile:hover {
      color: #ffffff;
    }

    }
</style>
</head>
<body style="background-image:url('<?= h($bg) ?>')">
  <div class="backdrop">
    <div class="wrap">
      <div class="topbar">
        <div class="brand">
          <h1><a href="/admin/dashboard.php">Porbeheer</a></h1>
          <div class="sub">POP Oefenruimte Zevenaar • beheer & planning</div>
        </div>

        <div class="userbox">
          <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?>  </div>
          <div class="line2"><a href="/admin/dashboard.php">Dashboard</a></div>
        </div>
      </div>

      <div class="panel">
        <div class="grid">
          <?php foreach ($tiles as $t): ?>
            <?php
              $ok = allowedTile($pdo, $t);
              $cls = $ok ? 'tile' : 'tile disabled';
              $tip = $ok ? '' : ' title="Je hebt hier geen toegang toe"';
              $href = $ok ? $t['href'] : '#';
            ?>
            <a class="<?= $cls ?>" href="<?= h($href) ?>"<?= $tip ?>>
              <?= h($t['title']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>