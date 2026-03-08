<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('dashboard', $pdo);


$tiles = [
  ['title'=>'Bands',       'href'=>'/admin/bands.php',      'roles'=>['ADMIN','BEHEER']],
  ['title'=>'Planning',    'href'=>'/admin/planning.php',   'roles'=>['ADMIN','BEHEER']],
  ['title'=>'Sleutels en Kasten',    'href'=>'/admin/keys.php',       'roles'=>['ADMIN','BEHEER']],
  ['title'=>'Financiën',   'href'=>'/admin/finance.php',    'roles'=>['ADMIN','FINANCIEEL']],
  ['title'=>'Contacten',   'href'=>'/admin/contacts.php',   'roles'=>['ADMIN','BEHEER','FINANCIEEL']],
  ['title'=>'Beheer',      'href'=>'/admin/beheer.php',     'roles'=>['ADMIN']],
  ['title'=>'Help',        'href'=>'/admin/help.php',       'roles'=>['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER']],
  ['title'=>'Uitloggen',   'href'=>'/logout.php',           'roles'=>['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER']],
];

function allowedTile(array $tile, string $role): bool {
  return in_array($role, $tile['roles'], true);
}

auditLog($pdo, 'PAGE_VIEW', 'admin/dashboard.php');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Dashboard</title>
  

<style>
    :root{
      --text: #fff;
      --muted: rgba(255,255,255,.78);
      --border: rgba(255,255,255,.22);
      --glass: rgba(255,255,255,.12);
      --glass2: rgba(255,255,255,.06);
      --shadow: 0 14px 40px rgba(0,0,0,.45);
    }

    body{
      margin:0;
      font-family: Arial, sans-serif;
      color: var(--text);
      background:url('<?= h($bg) ?>') no-repeat center center fixed;  background-size:cover;
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

    .wrap{ width: min(1200px, 96vw); }

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
    }
  a{color:#fff;text-decoration:none;transition:color .15s ease}
  a:hover{color:#ffd9b3}
  a:visited{color:#ffe0c2}
  .userbox a{
  color:#fff;
  font-weight:bold;
  text-decoration:none;
  }
  .userbox a:hover{
  text-decoration:underline;
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
          <div class="line1"> Hallo <a href="/admin/account.php"><?= h($user['username'] ?? '') ?></a> · Jouw rol is <?= h($role) ?></div>
          <div class="line2"> <a href="/admin/dashboard.php">Dashboard</a> • <a href="/logout.php">Uitloggen</a></div>
        </div>
      </div>

      <div class="panel">
        <div class="grid">
          <?php foreach ($tiles as $t): ?>
            <?php
              $ok = allowedTile($t, $role);
              $cls = $ok ? 'tile' : 'tile disabled';
              $href = $ok ? $t['href'] : '#';
            ?>
            <a class="<?= $cls ?>" href="<?= h($href) ?>">
              <?= h($t['title']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>