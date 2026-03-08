<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('audit', $pdo);

/** fallback als h() niet globally bestaat */
if (!function_exists('h')) {
  function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* Audit page view (faalt nooit hard als auditLog intern al try/catch doet) */
auditLog($pdo, 'PAGE_VIEW', 'admin/audit_logging.php');

/* --------------------------
   Filters & paging
-------------------------- */
$q         = trim((string)($_GET['q'] ?? ''));
$fUser     = trim((string)($_GET['username'] ?? ''));
$fRole     = trim((string)($_GET['role'] ?? ''));
$fType     = trim((string)($_GET['event_type'] ?? ''));
$fMethod   = trim((string)($_GET['method'] ?? ''));
$fIp       = trim((string)($_GET['ip'] ?? ''));
$fPath     = trim((string)($_GET['path'] ?? ''));
$dateFrom  = trim((string)($_GET['from'] ?? '')); // yyyy-mm-dd
$dateTo    = trim((string)($_GET['to'] ?? ''));   // yyyy-mm-dd

$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = (int)($_GET['per'] ?? 50);
if ($perPage < 10)  $perPage = 10;
if ($perPage > 200) $perPage = 200;

$offset = ($page - 1) * $perPage;

$where = [];
$args  = [];

/* Vrij zoeken over een paar velden */
if ($q !== '') {
  $where[] = "(username LIKE ? OR role LIKE ? OR event_type LIKE ? OR event_name LIKE ? OR path LIKE ? OR ip LIKE ?)";
  $like = '%' . $q . '%';
  array_push($args, $like, $like, $like, $like, $like, $like);
}

if ($fUser !== '')   { $where[] = "username LIKE ?";     $args[] = '%' . $fUser . '%'; }
if ($fRole !== '')   { $where[] = "role = ?";            $args[] = $fRole; }
if ($fType !== '')   { $where[] = "event_type = ?";      $args[] = $fType; }
if ($fMethod !== '') { $where[] = "method = ?";          $args[] = $fMethod; }
if ($fIp !== '')     { $where[] = "ip LIKE ?";           $args[] = '%' . $fIp . '%'; }
if ($fPath !== '')   { $where[] = "path LIKE ?";         $args[] = '%' . $fPath . '%'; }

/* Datums: inclusief vanaf 00:00:00, tot exclusief volgende dag 00:00:00 */
if ($dateFrom !== '') {
  $where[] = "created_at >= ?";
  $args[] = $dateFrom . " 00:00:00";
}
if ($dateTo !== '') {
  $where[] = "created_at < DATE_ADD(?, INTERVAL 1 DAY)";
  $args[] = $dateTo . " 00:00:00";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* Dropdown opties (distinct) */
$types = $pdo->query("SELECT DISTINCT event_type FROM audit_log ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);
$roles = $pdo->query("SELECT DISTINCT role FROM audit_log WHERE role IS NOT NULL AND role <> '' ORDER BY role")->fetchAll(PDO::FETCH_COLUMN);
$methods = $pdo->query("SELECT DISTINCT method FROM audit_log WHERE method IS NOT NULL AND method <> '' ORDER BY method")->fetchAll(PDO::FETCH_COLUMN);

/* Total count */
$stCount = $pdo->prepare("SELECT COUNT(*) FROM audit_log $whereSql");
$stCount->execute($args);
$total = (int)$stCount->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

if ($page > $pages) { $page = $pages; $offset = ($page - 1) * $perPage; }

/* Rows */
$st = $pdo->prepare("
  SELECT id, user_id, username, role, event_type, event_name, method, path, details, ip, user_agent, created_at
  FROM audit_log
  $whereSql
  ORDER BY created_at DESC, id DESC
  LIMIT $perPage OFFSET $offset
");
$st->execute($args);
$rows = $st->fetchAll();

/* Helpers */
function qs(array $overrides = []): string {
  $p = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($p[$k]);
    else $p[$k] = (string)$v;
  }
  return '?' . http_build_query($p);
}

function prettyJson($v): string {
  if ($v === null || $v === '') return '';
  if (is_array($v)) {
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';
  }
  $s = (string)$v;
  $decoded = json_decode($s, true);
  if (json_last_error() === JSON_ERROR_NONE) {
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $s;
  }
  return $s;
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Porbeheer - Audit logging</title>
  <style>
    :root{
      --text: #fff;
      --muted: rgba(255,255,255,.78);
      --border: rgba(255,255,255,.22);
      --glass: rgba(255,255,255,.12);
      --glass2: rgba(255,255,255,.06);
      --shadow: 0 14px 40px rgba(0,0,0,.45);
      --ok: rgba(120, 255, 170, .22);
      --warn: rgba(255, 216, 107, .22);
      --danger: rgba(255, 120, 120, .22);
    }

    body{
      margin:0;
      font-family: Arial, sans-serif;
      color: var(--text);
      ound:url('<backgr?= h($bg) ?>') no-repeat center center fixed;      background-size: cover;
    }

    a{ color: #fff; }
    a:hover{ color: #ffd86b; }

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

    .wrap{ width: min(1400px, 96vw); }

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

    .filters{
      display:grid;
      grid-template-columns: repeat(12, 1fr);
      gap: 10px;
      margin-bottom: 14px;
      align-items:end;
    }
    .f{ grid-column: span 3; }
    .f2{ grid-column: span 2; }
    .f4{ grid-column: span 4; }
    .f6{ grid-column: span 6; }
    .f12{ grid-column: span 12; }

    @media (max-width: 1100px){
      .f, .f4, .f6 { grid-column: span 6; }
      .f2 { grid-column: span 3; }
    }
    @media (max-width: 640px){
      .filters{ grid-template-columns: repeat(6, 1fr); }
      .f, .f2, .f4, .f6 { grid-column: span 6; }
    }

    label{ display:block; font-size: 12px; color: var(--muted); margin-bottom: 6px; }
    input, select{
      width:100%;
      box-sizing:border-box;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.22);
      background: rgba(0,0,0,.25);
      color: #fff;
      padding: 10px 10px;
      outline:none;
    }
    input::placeholder{ color: rgba(255,255,255,.55); }

    .btns{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      justify-content:flex-end;
    }
    .btn{
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.22);
      background: linear-gradient(180deg, rgba(255,255,255,.18), rgba(255,255,255,.08));
      color:#fff;
      padding: 10px 12px;
      font-weight: 700;
      cursor:pointer;
      text-decoration:none;
      display:inline-block;
    }
    .btn:hover{
      border-color: rgba(255,255,255,.38);
      background: linear-gradient(180deg, rgba(255,255,255,.24), rgba(255,255,255,.10));
    }

    .meta{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
      justify-content:space-between;
      margin: 10px 0 12px;
      color: var(--muted);
      font-size: 13px;
    }

    .tablewrap{
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.18);
      overflow:hidden;
      background: rgba(0,0,0,.18);
    }

    table{
      width:100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    th, td{
      padding: 10px 10px;
      border-bottom: 1px solid rgba(255,255,255,.10);
      vertical-align: top;
    }

    th{
      text-align:left;
      font-size: 12px;
      letter-spacing: .3px;
      color: rgba(255,255,255,.85);
      background: rgba(255,255,255,.06);
      position: sticky;
      top: 0;
      z-index: 2;
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
    }

    tr.data:hover td{
      background: rgba(255,255,255,.06);
    }

    .pill{
      display:inline-block;
      padding: 4px 8px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.22);
      background: rgba(255,255,255,.08);
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }
    .pill.ok{ background: var(--ok); }
    .pill.warn{ background: var(--warn); }
    .pill.danger{ background: var(--danger); }

    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .muted{ color: var(--muted); }

    .detailsRow{ display:none; }
    .detailsBox{
      padding: 12px;
      background: rgba(0,0,0,.25);
      border-top: 1px dashed rgba(255,255,255,.18);
    }
    pre{
      margin:0;
      white-space: pre-wrap;
      word-break: break-word;
      font-size: 12px;
      line-height: 1.35;
      color: rgba(255,255,255,.92);
    }

    .pager{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
      justify-content:space-between;
      margin-top: 12px;
    }
    .pager .left, .pager .right{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .small{ font-size: 12px; color: var(--muted); }

    .click{
      cursor:pointer;
      user-select:none;
    }
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
          <div class="sub">Audit logging • beheer & planning</div>
        </div>

        <div class="userbox">
          <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h((string)$role) ?></div>
          <div class="line2">
            <a href="/admin/beheer.php">Beheer</a> •
            <a href="/admin/dashboard.php">Dashboard</a> •
            <a href="/logout.php">Uitloggen</a>
          </div>
        </div>
      </div>

      <div class="panel">
        <form method="get" class="filters">
          <div class="f6">
            <label>Zoeken (username/role/type/name/path/ip)</label>
            <input name="q" value="<?= h($q) ?>" placeholder="bijv. LOGIN, admin/, 192.168..." />
          </div>

          <div class="f2">
            <label>Event type</label>
            <select name="event_type">
              <option value="">(alles)</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= h((string)$t) ?>" <?= $fType === (string)$t ? 'selected' : '' ?>><?= h((string)$t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="f2">
            <label>Rol</label>
            <select name="role">
              <option value="">(alles)</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= h((string)$r) ?>" <?= $fRole === (string)$r ? 'selected' : '' ?>><?= h((string)$r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="f2">
            <label>Methode</label>
            <select name="method">
              <option value="">(alles)</option>
              <?php foreach ($methods as $m): ?>
                <option value="<?= h((string)$m) ?>" <?= $fMethod === (string)$m ? 'selected' : '' ?>><?= h((string)$m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="f3 f">
            <label>Username (filter)</label>
            <input name="username" value="<?= h($fUser) ?>" placeholder="bijv. hans" />
          </div>

          <div class="f3 f">
            <label>Path (filter)</label>
            <input name="path" value="<?= h($fPath) ?>" placeholder="bijv. /admin/" />
          </div>

          <div class="f3 f">
            <label>IP (filter)</label>
            <input name="ip" value="<?= h($fIp) ?>" placeholder="bijv. 192.168" />
          </div>

          <div class="f2">
            <label>Van (datum)</label>
            <input type="date" name="from" value="<?= h($dateFrom) ?>" />
          </div>

          <div class="f2">
            <label>Tot (datum)</label>
            <input type="date" name="to" value="<?= h($dateTo) ?>" />
          </div>

          <div class="f2">
            <label>Per pagina</label>
            <select name="per">
              <?php foreach ([10, 25, 50, 100, 200] as $n): ?>
                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="f12">
            <div class="btns">
              <button class="btn" type="submit">Filter</button>
              <a class="btn" href="/admin/audit_logging.php">Reset</a>
            </div>
          </div>
        </form>

        <div class="meta">
          <div>
            Totaal: <b><?= (int)$total ?></b> • Pagina <b><?= (int)$page ?></b> / <?= (int)$pages ?>
            <span class="small">• klik op een regel om details te tonen</span>
          </div>
          <div class="small">Gesorteerd: nieuwste eerst</div>
        </div>

        <div class="tablewrap">
          <table>
            <thead>
              <tr>
                <th style="width:160px;">Tijd</th>
                <th style="width:110px;">User</th>
                <th style="width:90px;">Rol</th>
                <th style="width:140px;">Type</th>
                <th>Event</th>
                <th style="width:90px;">Method</th>
                <th style="width:280px;">Path</th>
                <th style="width:140px;">IP</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="8" class="muted">Geen audit events gevonden met deze filters.</td></tr>
              <?php endif; ?>

              <?php foreach ($rows as $r): ?>
                <?php
                  $id = (int)$r['id'];
                  $etype = (string)$r['event_type'];
                  $pillClass = 'pill';
                  if (stripos($etype, 'ERROR') !== false || stripos($etype, 'DENY') !== false) $pillClass .= ' danger';
                  elseif (stripos($etype, 'LOGIN') !== false || stripos($etype, 'LOGOUT') !== false) $pillClass .= ' warn';
                  else $pillClass .= ' ok';

                  $details = prettyJson($r['details'] ?? null);
                  $ua = (string)($r['user_agent'] ?? '');
                ?>
                <tr class="data click" data-id="<?= $id ?>" title="Klik voor details">
                  <td class="mono"><?= h((string)$r['created_at']) ?></td>
                  <td><?= h((string)($r['username'] ?? '')) ?></td>
                  <td><?= h((string)($r['role'] ?? '')) ?></td>
                  <td><span class="<?= $pillClass ?>"><?= h($etype) ?></span></td>
                  <td><?= h((string)$r['event_name']) ?></td>
                  <td class="mono"><?= h((string)($r['method'] ?? '')) ?></td>
                  <td class="mono"><?= h((string)($r['path'] ?? '')) ?></td>
                  <td class="mono"><?= h((string)($r['ip'] ?? '')) ?></td>
                </tr>
                <tr class="detailsRow" id="details-<?= $id ?>">
                  <td colspan="8">
                    <div class="detailsBox">
                      <div class="small" style="margin-bottom:8px;">
                        <b>ID:</b> <?= $id ?>
                        <?php if (!empty($r['user_id'])): ?> • <b>User ID:</b> <?= (int)$r['user_id'] ?><?php endif; ?>
                      </div>

                      <?php if ($details !== ''): ?>
                        <div class="small muted" style="margin-bottom:6px;"><b>Details (JSON)</b></div>
                        <pre class="mono"><?= h($details) ?></pre>
                      <?php else: ?>
                        <div class="small muted" style="margin-bottom:10px;">Geen details (details is leeg).</div>
                      <?php endif; ?>

                      <?php if ($ua !== ''): ?>
                        <div class="small muted" style="margin-top:10px;"><b>User-Agent</b></div>
                        <pre class="mono"><?= h($ua) ?></pre>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="pager">
          <div class="left">
            <?php
              $prev = max(1, $page - 1);
              $next = min($pages, $page + 1);
            ?>
            <a class="btn" href="<?= h(qs(['page' => 1])) ?>">« Eerste</a>
            <a class="btn" href="<?= h(qs(['page' => $prev])) ?>">‹ Vorige</a>
            <a class="btn" href="<?= h(qs(['page' => $next])) ?>">Volgende ›</a>
            <a class="btn" href="<?= h(qs(['page' => $pages])) ?>">Laatste »</a>
          </div>
          <div class="right">
            <span class="small">Ga naar pagina</span>
            <form method="get" style="display:flex; gap:8px; align-items:center;">
              <?php foreach ($_GET as $k => $v): ?>
                <?php if ($k === 'page') continue; ?>
                <input type="hidden" name="<?= h((string)$k) ?>" value="<?= h((string)$v) ?>">
              <?php endforeach; ?>
              <input type="number" name="page" min="1" max="<?= (int)$pages ?>" value="<?= (int)$page ?>" style="width:110px;">
              <button class="btn" type="submit">Ga</button>
            </form>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    (function(){
      function toggle(id){
        const row = document.getElementById('details-' + id);
        if (!row) return;
        row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
      }

      document.querySelectorAll('tr.data.click').forEach(function(tr){
        tr.addEventListener('click', function(){
          toggle(tr.getAttribute('data-id'));
        });
      });
    })();
  </script>
</body>
</html>