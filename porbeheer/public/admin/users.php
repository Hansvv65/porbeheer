<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('contacts', $pdo);


function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$show = (string)($_GET['show'] ?? 'active'); // active|all|deleted
$show = in_array($show, ['active','all','deleted'], true) ? $show : 'active';

$where = "1=1";
if ($show === 'active')  $where .= " AND u.deleted_at IS NULL";
if ($show === 'deleted') $where .= " AND u.deleted_at IS NOT NULL";

$users = $pdo->query("
  SELECT
    u.id, u.username, u.email,
    u.role, u.status, u.active,
    u.created_at, u.approved_at,
    u.last_login_at,
    u.failed_attempts, u.locked_until,
    u.deleted_at, u.deleted_reason,
    r.roles_csv
  FROM users u
  LEFT JOIN (
    SELECT user_id,
           GROUP_CONCAT(role ORDER BY FIELD(role,'ADMIN','BEHEER','FINANCIEEL','GEBRUIKER') SEPARATOR ',') AS roles_csv
    FROM user_roles
    GROUP BY user_id
  ) r ON r.user_id = u.id
  WHERE {$where}
  ORDER BY (u.deleted_at IS NOT NULL) ASC, u.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

auditLog($pdo, 'PAGE_VIEW', 'admin/users.php', ['show'=>$show]);

?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Gebruikers</title>
<style>
  :root{
    --text:#fff; --muted:rgba(255,255,255,.78);
    --border:rgba(255,255,255,.22);
    --glass:rgba(255,255,255,.12);
    --shadow:0 14px 40px rgba(0,0,0,.45);
    --ok:#7CFFB2; --err:#FF8DA1; --accent:#ffd86b;
  }
  body{
    margin:0; font-family:Arial,sans-serif; color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;
    background-size:cover;
  }
  .backdrop{
    min-height:100vh;
    background:
      radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
      linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
    padding:26px; box-sizing:border-box;
    display:flex; justify-content:center;
  }
  .wrap{ width:min(1100px, 96vw); }

  .topbar{
    display:flex; align-items:flex-end; justify-content:space-between;
    gap:16px; flex-wrap:wrap; margin-bottom:14px;
  }
  .brand h1{ margin:0; font-size:28px; letter-spacing:.5px; }
  .brand .sub{ margin-top:6px; color:var(--muted); font-size:14px; }

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
  .userbox .line1{font-weight:bold}
  .userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
  a{ color:#fff; text-decoration:none; }
  a:visited{ color:var(--accent); }
  a:hover{ opacity:.95; }

  .panel{
    margin-top:10px;
    border-radius:20px;
    border:1px solid rgba(255,255,255,.18);
    background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
    box-shadow:var(--shadow);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    padding:18px;
  }

  .headerrow{
    display:flex; align-items:center; justify-content:space-between;
    gap:12px; flex-wrap:wrap; margin-bottom:10px;
  }
  .filters{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .chip{
    display:inline-block;
    padding:8px 10px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(0,0,0,.14);
    font-weight:800;
    font-size:13px;
  }
  .chip.on{
    border-color: rgba(124,255,178,.35);
    background: rgba(124,255,178,.10);
    color: var(--ok);
  }

  .list{
    display:flex;
    flex-direction:column;
    gap:12px;
    margin-top:10px;
  }
  .card{
    display:block;
    border-radius:18px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(0,0,0,.18);
    box-shadow:0 12px 28px rgba(0,0,0,.35);
    padding:14px 14px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: transform .12s ease, background .12s ease, border-color .12s ease;
  }
  .card:hover{ transform: translateY(-1px); background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.30); }
  .row1{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; }
  .who{ font-weight:900; letter-spacing:.2px; }
  .meta{ color:var(--muted); font-size:13px; }
  .badges{ display:flex; flex-wrap:wrap; gap:6px; align-items:center; }

  .badge{
    display:inline-block;
    padding:3px 10px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(255,255,255,.08);
    font-weight:900;
    letter-spacing:.2px;
    font-size:12px;
  }
  .badge.ok{ border-color:rgba(124,255,178,.35); color:var(--ok); background:rgba(124,255,178,.10); }
  .badge.off{ border-color:rgba(255,141,161,.35); color:var(--err); background:rgba(255,141,161,.10); }
  .badge.warn{ border-color:rgba(255,216,107,.35); color:var(--accent); background:rgba(255,216,107,.10); }

  .grid{
    margin-top:10px;
    display:grid;
    grid-template-columns: 1.2fr 1fr 1fr;
    gap:10px;
  }
  @media (max-width: 900px){
    .grid{ grid-template-columns: 1fr; }
  }
  .box{
    border-radius:14px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(0,0,0,.12);
    padding:10px 12px;
  }
  .label{ color:var(--muted); font-size:12px; font-weight:800; }
  .value{ margin-top:4px; font-weight:900; }
  .small{ margin-top:6px; color:var(--muted); font-size:12px; overflow-wrap:anywhere; }
    a{color:#fff;text-decoration:none;transition:color .15s ease}
  a:hover{color:#ffd9b3}
  a:visited{color:#ffe0c2}

</style>
</head>
<body>
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div class="brand">
        <h1>Porbeheer</h1>
        <div class="sub">POP Oefenruimte Zevenaar • admin</div>
      </div>

      <div class="userbox">
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a> •
          <a href="/admin/beheer.php">Beheer</a> • 
          <a href="/logout.php">Uitloggen</a>

        </div>
      </div>
    </div>

    <div class="panel">
      <div class="headerrow">
        <div>
          <h2 style="margin:0">Gebruikers</h2>
          <div class="meta">Status is leidend. “Actief” = status ACTIVE en niet verwijderd.</div>
        </div>

        <div class="filters">
          <a class="chip <?= $show==='active' ? 'on' : '' ?>" href="/admin/users.php?show=active">Actief</a>
          <a class="chip <?= $show==='all' ? 'on' : '' ?>" href="/admin/users.php?show=all">Alles</a>
          <a class="chip <?= $show==='deleted' ? 'on' : '' ?>" href="/admin/users.php?show=deleted">Verwijderd</a>
          <a class="chip" href="/admin/user_create.php">+ Nieuwe gebruiker</a>
        </div>
      </div>

      <div class="list">
        <?php foreach ($users as $u): ?>
          <?php
            $uid = (int)$u['id'];
            $rolesCsv = (string)($u['roles_csv'] ?? '');
            $rolesArr = $rolesCsv !== '' ? explode(',', $rolesCsv) : [ (string)$u['role'] ];
            $rolesArr = array_values(array_unique(array_filter($rolesArr)));

            $isAdmin   = in_array('ADMIN', $rolesArr, true) || ((string)$u['role'] === 'ADMIN');
            $isDeleted = !empty($u['deleted_at']);

            $status = (string)($u['status'] ?? 'PENDING');

            // CONSISTENT: status is leidend voor actief/inactief
            $effectiveActive = ($status === 'ACTIVE') && !$isDeleted;

            // locked: status BLOCKED of tijdelijke lock
            $lockedUntil = $u['locked_until'] ?? null;
            $isTempLocked = $lockedUntil && strtotime((string)$lockedUntil) > time();
            $showLocked = ($status === 'BLOCKED') || $isTempLocked;

            $attempts = (int)($u['failed_attempts'] ?? 0);
            $lastLogin = (string)($u['last_login_at'] ?? '—');

            $detailHref = "/admin/users_detail.php?id=" . $uid;
          ?>
          <a class="card" href="<?= h($detailHref) ?>">
            <div class="row1">
              <div>
                <div class="who">#<?= $uid ?> • <?= h((string)$u['username']) ?></div>
                <div class="small"><?= h((string)($u['email'] ?? '')) ?></div>
              </div>
                  <div class="badges">
                    <?php if ($isAdmin): ?><span class="badge ok">ADMIN</span><?php endif; ?>

                    <?php
                      // Eén duidelijke statusbadge
                      $statusLabel = $status;
                      $statusClass = 'badge';

                      if ($isDeleted) {
                          $statusLabel = 'DELETED';
                          $statusClass = 'badge off';
                      } elseif ($showLocked) {
                          if ($isTempLocked) {
                              $statusLabel = 'LOCKED (tot ' . date('Y-m-d H:i', strtotime((string)$lockedUntil)) . ')';
                          } else {
                              $statusLabel = 'BLOCKED';
                          }
                          $statusClass = 'badge off';
                      } else {
                          if ($status === 'ACTIVE') {
                              $statusLabel = 'ACTIVE';
                              $statusClass = 'badge ok';
                          } elseif ($status === 'PENDING') {
                              $statusLabel = 'PENDING';
                              $statusClass = 'badge warn';
                          } elseif ($status === 'BLOCKED') {
                              $statusLabel = 'BLOCKED';
                              $statusClass = 'badge off';
                          } else {
                              $statusLabel = $status; // fallback
                              $statusClass = 'badge';
                          }
                      }
                    ?>

                    <span class="<?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                </div>
            </div>

            <div class="grid">
              <div class="box">
                <div class="label">Last login</div>
                <div class="value"><?= h($lastLogin) ?></div>
                <div class="small">Aangemaakt: <?= h((string)$u['created_at']) ?></div>
              </div>
              <div class="box">
                <div class="label">Lock</div>
                <div class="value">attempts: <?= $attempts ?></div>
                <div class="small">locked_until: <?= h((string)($lockedUntil ?? '—')) ?></div>
              </div>
              <div class="box">
                <div class="label">Rollen</div>
                <div class="value">
                  <?php foreach ($rolesArr as $rr): ?>
                    <span class="badge"><?= h($rr) ?></span>
                  <?php endforeach; ?>
                </div>
                <?php if ($isDeleted && !empty($u['deleted_reason'])): ?>
                  <div class="small">Reden: <?= h((string)$u['deleted_reason']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

    </div>

  </div>
</div>
</body>
</html>