<?php
declare(strict_types=1);

require_once __DIR__ . '/../cgi-bin/app/bootstrap.php';
require_once __DIR__ . '/../cgi-bin/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('bands', $pdo);


if (!function_exists('h')) {
    function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

auditLog($pdo, 'PAGE_VIEW', 'admin/bands.php');

$err = null;
$msg = null;

$qmsg = (string)($_GET['msg'] ?? '');
if ($qmsg === 'saved') $msg = 'Band opgeslagen.';
if ($qmsg === 'deleted') $msg = 'Band verwijderd.';

try {
    $bands = $pdo->query("
      SELECT b.id, b.name, b.city
      FROM bands b
      WHERE b.deleted_at IS NULL
      ORDER BY b.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Vrije kasten (nog niet gekoppeld)
    $freeLockers = $pdo->query("
      SELECT id, locker_no, notes
      FROM lockers
      WHERE deleted_at IS NULL AND band_id IS NULL
      ORDER BY locker_no
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Zwevende sleutels: key_type LOCKER, key.locker_id bestaat, maar locker.band_id is NULL
    $floatingKeys = $pdo->query("
      SELECT
        k.id, k.key_code, k.description, k.key_slot, k.lost_at,
        l.id AS locker_id, l.locker_no
      FROM `keys` k
      JOIN lockers l ON l.id = k.locker_id AND l.deleted_at IS NULL
      WHERE k.deleted_at IS NULL
        AND k.key_type='LOCKER'
        AND l.band_id IS NULL
      ORDER BY l.locker_no, k.key_slot, k.key_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Band details (lockers+keys) via nieuwe model
    $bandIds = array_map(static fn($r) => (int)$r['id'], $bands);

    $byBand = [];
    foreach ($bands as $b) {
        $bid = (int)$b['id'];
        $byBand[$bid] = ['lockers'=>[]];
    }

    $in = static function(array $ids): string {
        return implode(',', array_fill(0, count($ids), '?'));
    };

    if ($bandIds) {
        $stL = $pdo->prepare("
          SELECT id, band_id, locker_no, notes
          FROM lockers
          WHERE deleted_at IS NULL
            AND band_id IN (" . $in($bandIds) . ")
          ORDER BY band_id, locker_no
        ");
        $stL->execute($bandIds);
        $lockers = $stL->fetchAll(PDO::FETCH_ASSOC);

        $lockerIds = [];
        $lockerToBand = [];

        foreach ($lockers as $l) {
            $bid = (int)$l['band_id'];
            $lid = (int)$l['id'];
            $lockerIds[] = $lid;
            $lockerToBand[$lid] = $bid;

            $byBand[$bid]['lockers'][$lid] = [
                'id'=>$lid,
                'locker_no'=>(string)$l['locker_no'],
                'notes'=>(string)($l['notes'] ?? ''),
                'keys'=>[]
            ];
        }

        if ($lockerIds) {
            $stK = $pdo->prepare("
              SELECT id, locker_id, key_code, description, key_slot, lost_at
              FROM `keys`
              WHERE deleted_at IS NULL
                AND key_type='LOCKER'
                AND locker_id IN (" . $in($lockerIds) . ")
              ORDER BY locker_id, key_slot, key_code
            ");
            $stK->execute($lockerIds);
            $keys = $stK->fetchAll(PDO::FETCH_ASSOC);

            foreach ($keys as $k) {
                $lid = (int)$k['locker_id'];
                $bid = $lockerToBand[$lid] ?? 0;
                if ($bid <= 0 || !isset($byBand[$bid]['lockers'][$lid])) continue;

                $byBand[$bid]['lockers'][$lid]['keys'][] = [
                    'id'=>(int)$k['id'],
                    'key_code'=>(string)$k['key_code'],
                    'description'=>(string)($k['description'] ?? ''),
                    'key_slot'=>$k['key_slot'] !== null ? (int)$k['key_slot'] : null,
                    'lost_at'=>$k['lost_at'],
                ];
            }
        }
    }

} catch (Throwable $e) {
    $bands = [];
    $byBand = [];
    $freeLockers = [];
    $floatingKeys = [];
    $err = $e->getMessage();
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Bands</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{margin:0;font-family:Arial,sans-serif;color:var(--text);
  background:url('<?= h($bg) ?>') no-repeat center center fixed;  background-size:cover;
.backdrop{min-height:100vh;background:
  radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
  linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
  padding:26px;box-sizing:border-box;display:flex;justify-content:center;}
.wrap{width:min(1200px,96vw);}
.topbar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.brand h1{margin:0;font-size:28px;letter-spacing:.5px}
.brand .sub{margin-top:6px;color:var(--muted);font-size:14px}
.userbox{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);
  backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);min-width:260px;}
.userbox .line1{font-weight:bold}
.userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);padding:18px;}
.card{border-radius:16px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));
  padding:14px;box-shadow:0 10px 22px rgba(0,0,0,.30);backdrop-filter:blur(10px);margin-bottom:14px;}
h2{margin:0 0 8px 0;font-size:18px}
.small{font-size:13px;color:var(--muted)}
.tablewrap{overflow:auto;border-radius:14px;border:1px solid rgba(255,255,255,.12)}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.12);text-align:left;font-size:14px;vertical-align:top}
th{background:rgba(255,255,255,.06)}
a{color:#fff;text-decoration:none} a:hover{color:#ffd9b3}
.msg{margin:10px 0;font-size:13px;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08)}
.err{color:#ffb3b3}
.btn{display:inline-block;padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));color:#fff;font-weight:800;cursor:pointer}
.btn:hover{border-color:rgba(255,255,255,.38);transform:translateY(-1px)}
.btn-sm{padding:6px 10px;font-weight:800;border-radius:10px}
.pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08)}
.pill-warn{border-color:rgba(255,220,140,.35)}
.pill-off{border-color:rgba(190,190,190,.35);color:rgba(255,255,255,.85)}
.detailbox{display:none;margin-top:10px;padding:12px;border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06)}
.klist{margin-top:8px;display:flex;flex-direction:column;gap:6px}
.kitem{padding:8px 10px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);font-size:13px}
.kitem .meta{margin-top:4px;font-size:12px;color:rgba(255,255,255,.75)}
hr{border:none;border-top:1px solid rgba(255,255,255,.12);margin:10px 0}
  a{color:#fff;text-decoration:none;transition:color .15s ease}
  a:hover{color:#ffd9b3}
  a:visited{color:#ffe0c2}

</style>
<script>
function toggleBandDetails(id){
  var el = document.getElementById(id);
  if (!el) return;
  el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
}
</script>
</head>
<body>
<div class="backdrop"><div class="wrap">

  <div class="topbar">
    <div class="brand">
      <h1>Bands</h1>
      <div class="sub">
        Overzicht • <a href="/admin/band_edit.php">Nieuwe Band</a>
      </div>
    </div>

    <div class="userbox">
      <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div class="line2"><a href="/admin/dashboard.php">Dashboard</a> •
      <a href="/logout.php">Uitloggen</a></div>
    </div>
  </div>

  <div class="panel">
    <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

    <?php if (count($freeLockers) > 0 || count($floatingKeys) > 0): ?>
      <div class="card">
        <h2>Status koppelingen</h2>
        
        <div class="tablewrap" style="margin-top:12px">
          <table>
            <thead>
              <tr>
                <th>Vrije kasten </th>
                <th>Zwevende sleutels </th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="small">
                  <?php if (!$freeLockers): ?>
                    —
                  <?php else: ?>
                    <?= count($freeLockers) ?> kasten <br>
                  <?php endif; ?>
                </td>
                <td class="small">
                  <?php if (!$floatingKeys): ?>
                    —
                  <?php else: ?>
                    <?= count($floatingKeys) ?> sleutel(s):<br>
                    <?php foreach (array_slice($floatingKeys, 0, 10) as $k): ?>
                      <?= h($k['locker_no'].' · sleutel '.($k['key_slot'] ?? '—').' '.$k['key_code']) ?>
                      <?= !empty($k['description']) ? h(' ('.$k['description'].')') : '' ?>
                      <?= !empty($k['lost_at']) ? ' <span class="pill pill-warn">VERLOREN</span>' : '' ?>
                      <br>
                    <?php endforeach; ?>
                    <?php if (count($floatingKeys) > 10): ?>
                      … en nog <?= (int)(count($floatingKeys)-10) ?>.
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Alle bands</h2>
      <div class="small"><?= count($bands) ?> bands</div>

      <div class="tablewrap" style="margin-top:12px">
        <table>
          <thead>
            <tr>
              <th>Band</th>
              <th>Stad</th>
              <th>Kasten</th>
              <th>Sleutels</th>
              <th>Acties</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$bands): ?>
            <tr><td colspan="5" class="small">Geen bands gevonden.</td></tr>
          <?php else: foreach ($bands as $b): ?>
            <?php
              $bid = (int)$b['id'];
              $pack = $byBand[$bid] ?? ['lockers'=>[]];
              $lockerCount = count($pack['lockers']);

              $keyCount = 0;
              foreach ($pack['lockers'] as $l) $keyCount += count($l['keys']);

              $lockerNames = [];
              foreach ($pack['lockers'] as $l) $lockerNames[] = $l['locker_no'];
              $lockerTxt = $lockerCount > 0 ? ($lockerCount.' · '.implode(', ', $lockerNames)) : '0';

              // preview max 3 keys
              $preview = [];
              foreach ($pack['lockers'] as $l) {
                foreach ($l['keys'] as $k) {
                  $line = $l['locker_no'].' · sleutel '.($k['key_slot'] ?? '—').' — '.$k['key_code'];
                  if ($k['description'] !== '') $line .= ' ('.$k['description'].')';
                  if (!empty($k['lost_at'])) $line .= ' [VERLOREN]';
                  $preview[] = $line;
                  if (count($preview) >= 3) break 2;
                }
              }

              $detailId = 'bandDetails_'.$bid;
            ?>
            <tr>
              <td><strong><?= h((string)$b['name']) ?></strong></td>
              <td class="small"><?= h((string)($b['city'] ?? '')) ?></td>

              <td class="small">
                <?= h($lockerTxt) ?>
                <?php if ($lockerCount === 0): ?>
                  <div class="small"><span class="pill pill-warn">Geen lockers gekoppeld</span></div>
                <?php endif; ?>
              </td>

              <td>
                <?php if ($keyCount <= 0): ?>
                  <span class="pill pill-off"></span>
                <?php else: ?>
                  <div class="small"><span class="pill"><?= (int)$keyCount ?> key(s)</span></div>
                  <?php if ($preview): ?>
                    <div class="small" style="margin-top:6px">
                      <?php foreach ($preview as $p): ?><div><?= h($p) ?></div><?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>

                <div class="detailbox" id="<?= h($detailId) ?>">
                  <div class="small"><strong>Volledig overzicht</strong></div>

                  <?php if ($lockerCount === 0): ?>
                    <div class="small" style="margin-top:8px">
                      Geen kasten gekoppeld. Gebruik <code>Sleutels en Kasten</code> om een kast toe te wijzen.
                    </div>
                  <?php else: ?>
                    <?php foreach ($pack['lockers'] as $l): ?>
                      <hr>
                      <div class="kitem">
                        <strong>Kast <?= h($l['locker_no']) ?></strong>
                        <?php if ($l['notes'] !== ''): ?>
                          <div class="meta"><?= h($l['notes']) ?></div>
                        <?php endif; ?>

                        <?php if (!$l['keys']): ?>
                          <div class="meta">Geen sleutels gekoppeld aan deze kast.</div>
                        <?php else: ?>
                          <div class="klist">
                            <?php foreach ($l['keys'] as $k): ?>
                              <div class="kitem">
                                <strong><?= h($k['key_code']) ?></strong>
                                <div class="meta">
                                  sleutel <?= h($k['key_slot'] !== null ? (string)$k['key_slot'] : '—') ?>
                                  <?php if ($k['description'] !== ''): ?> · <?= h($k['description']) ?><?php endif; ?>
                                  <?php if (!empty($k['lost_at'])): ?> · <span class="pill pill-warn">VERLOREN</span><?php endif; ?>
                                </div>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </td>

              <td>
                <a class="btn btn-sm" href="/admin/band_detail.php?id=<?= $bid ?>">Detail</a>
                <a class="btn btn-sm" style="margin-left:6px" href="/admin/band_keys.php?band_id=<?= $bid ?>">Sleutels en Kasten</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      
    </div>

  </div>

</div></div>
</body>
</html>