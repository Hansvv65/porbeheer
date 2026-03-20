<?php
// /public/admin/keys.php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('keys', $pdo);

auditLog($pdo, 'PAGE_VIEW', 'admin/keys.php');

if (!function_exists('h')) {
  function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$msg = null;
$err = null;

$qmsg = (string)($_GET['msg'] ?? '');
if ($qmsg === 'deleted') $msg = 'Item verwijderd.';
if ($qmsg === 'saved')   $msg = 'Opgeslagen.';

$lockers = [];
$keys    = [];

try {
    /**
     * Latest tx per key (MAX(id)) => ISSUE = uitgegeven, RETURN = in voorraad.
     * We gebruiken dit in beide overzichten.
     */

    // 1) POR-kasten overzicht
    $sqlLockers = "
      SELECT
        l.id,
        l.locker_no,
        l.band_id,
        b.name AS band_name,

        COUNT(k.id) AS total_keys,
        SUM(CASE WHEN kt.action = 'ISSUE' THEN 1 ELSE 0 END) AS issued_keys,
        SUM(CASE WHEN kt.action = 'ISSUE' THEN 0 ELSE 1 END) AS stock_keys

      FROM lockers l
      LEFT JOIN bands b
        ON b.id = l.band_id AND b.deleted_at IS NULL

      LEFT JOIN `keys` k
        ON k.locker_id = l.id
       AND k.deleted_at IS NULL
       AND k.key_type = 'LOCKER'

      LEFT JOIN (
        SELECT t1.*
        FROM key_transactions t1
        INNER JOIN (
          SELECT key_id, MAX(id) AS max_id
          FROM key_transactions
          GROUP BY key_id
        ) x ON x.key_id = t1.key_id AND x.max_id = t1.id
      ) kt ON kt.key_id = k.id

      WHERE l.deleted_at IS NULL
      GROUP BY l.id
      ORDER BY
        CASE WHEN l.locker_no REGEXP '^[0-9]+$' THEN 0 ELSE 1 END ASC,
        CASE WHEN l.locker_no REGEXP '^[0-9]+$' THEN CAST(l.locker_no AS UNSIGNED) ELSE 999999 END ASC,
        l.locker_no ASC
    ";
    $lockers = $pdo->query($sqlLockers)->fetchAll(PDO::FETCH_ASSOC);

    // 2) Sleutels overzicht (uitgebreid met key_type)
    $sqlKeys = "
      SELECT
        k.id,
        k.key_code,
        k.description,
        k.key_type,
        k.key_slot,
        k.active,

        l.id AS locker_id,
        l.locker_no,
        b.id AS band_id,
        b.name AS band_name,

        kt.id AS tx_id,
        kt.action AS tx_action,
        kt.action_at AS tx_action_at,
        kt.contact_id AS tx_contact_id,
        c.name AS contact_name,

        ktd.id AS doc_id,
        ktd.original_name AS doc_name

      FROM `keys` k
      LEFT JOIN lockers l ON l.id = k.locker_id AND l.deleted_at IS NULL
      LEFT JOIN bands b ON b.id = l.band_id AND b.deleted_at IS NULL

      LEFT JOIN (
          SELECT t1.*
          FROM key_transactions t1
          INNER JOIN (
              SELECT key_id, MAX(id) AS max_id
              FROM key_transactions
              GROUP BY key_id
          ) x ON x.key_id = t1.key_id AND x.max_id = t1.id
      ) kt ON kt.key_id = k.id

      LEFT JOIN contacts c ON c.id = kt.contact_id AND c.deleted_at IS NULL
      LEFT JOIN key_transaction_docs ktd ON ktd.transaction_id = kt.id

      WHERE k.deleted_at IS NULL
      ORDER BY
        (k.key_type <> 'LOCKER') ASC,
        CASE WHEN l.locker_no REGEXP '^[0-9]+$' THEN 0 ELSE 1 END ASC,
        CASE WHEN l.locker_no REGEXP '^[0-9]+$' THEN CAST(l.locker_no AS UNSIGNED) ELSE 999999 END ASC,
        l.locker_no ASC,
        CASE WHEN k.key_slot IS NULL THEN 999 ELSE k.key_slot END ASC,
        k.key_code ASC
    ";
    $keys = $pdo->query($sqlKeys)->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $err = $e->getMessage();
}

$lockerCount = count($lockers);
$freeLockers = 0;
foreach ($lockers as $lr) {
  if (empty($lr['band_id'])) $freeLockers++;
}

$keyCount = count($keys);
$masterCount = 0;
$externalCount = 0;
foreach ($keys as $kr) {
  if (($kr['key_type'] ?? 'LOCKER') === 'MASTER') $masterCount++;
  if (($kr['key_type'] ?? 'LOCKER') === 'EXTERNAL') $externalCount++;
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porbeheer - Kasten & Sleutels</title>
<style>
:root{--text:#fff;--muted:rgba(255,255,255,.78);--border:rgba(255,255,255,.22);--glass:rgba(255,255,255,.12);--glass2:rgba(255,255,255,.06);--shadow:0 14px 40px rgba(0,0,0,.45);}
body{
  margin:0;
  font-family:Arial,sans-serif;
  color:var(--text);
  background:url('<?= h($bg) ?>') no-repeat center center fixed;
  background-size:cover;
}
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
.userbox .line2{color:var(--muted);margin-top:6px;font-size:13px;display:flex;flex-wrap:wrap;gap:10px}
.panel{margin-top:10px;border-radius:20px;border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.06));
  box-shadow:var(--shadow);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);padding:18px;}
.grid{display:grid;grid-template-columns:1fr;gap:14px}
.card{border-radius:16px;border:1px solid rgba(255,255,255,.18);background:linear-gradient(180deg, rgba(255,255,255,.12), rgba(255,255,255,.05));
  padding:14px;box-shadow:0 10px 22px rgba(0,0,0,.30);backdrop-filter:blur(10px);}
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
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08)}
.badge-ok{border-color:rgba(140,255,170,.35)}
.badge-warn{border-color:rgba(255,220,140,.35)}
.badge-danger{border-color:rgba(255,140,140,.35)}
.badge-off{border-color:rgba(190,190,190,.35);color:rgba(255,255,255,.9)}
code{background:rgba(0,0,0,.25);padding:2px 6px;border-radius:8px;border:1px solid rgba(255,255,255,.12)}
.kpi{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}
.kpi .pill{border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);border-radius:999px;padding:6px 10px;font-size:13px;color:var(--muted)}
hr.sep{border:none;border-top:1px solid rgba(255,255,255,.12);margin:14px 0}
a{color:#fff;text-decoration:none;transition:color .15s ease}
a:hover{color:#ffd9b3}
a:visited{color:#ffe0c2}
</style>
</head>
<body>
<div class="backdrop"><div class="wrap">

  <div class="topbar">
    <div class="brand">
      <h1>Kasten & Sleutels</h1>
      <div class="sub">POR-kasten + sleutels (voorraad / uitgegeven) in één overzicht</div>
      <div class="kpi">
        <div class="pill">Kasten: <strong style="color:#fff"><?= (int)$lockerCount ?></strong> (vrij: <strong style="color:#fff"><?= (int)$freeLockers ?></strong>)</div>
        <div class="pill">Sleutels: <strong style="color:#fff"><?= (int)$keyCount ?></strong> (master: <strong style="color:#fff"><?= (int)$masterCount ?></strong>, extern: <strong style="color:#fff"><?= (int)$externalCount ?></strong>)</div>
        <a class="pill" href="/admin/keys_edit.php">Nieuwe sleutel</a>
      </div>
    </div>

    <div class="userbox">
      <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div class="line2">
        <a href="/admin/dashboard.php">Dashboard</a>
        <a href="#kasten">POR-kasten</a>
        <a href="#sleutels">Sleutels</a>
      </div>
    </div>
  </div>

  <div class="panel">
    <?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

    <div class="grid">

      <div class="card" id="kasten">
        <h2>Overzicht POR-kasten</h2>
        <div class="small"><?= (int)$lockerCount ?> kasten · vrije kasten: <?= (int)$freeLockers ?></div>

        <div class="tablewrap" style="margin-top:12px">
          <table>
            <thead>
              <tr>
                <th>Kast</th>
                <th>Band</th>
                <th>Sleutels</th>
                <th>Status (voorraad / uitgegeven)</th>
                <th>Opmerking</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$lockers): ?>
              <tr><td colspan="5" class="small">Geen kasten gevonden.</td></tr>
            <?php else: foreach ($lockers as $l): ?>
              <?php
                $lid = (int)$l['id'];
                $lockerNo = (string)$l['locker_no'];
                $bandId = $l['band_id'] ? (int)$l['band_id'] : 0;
                $bandName = trim((string)($l['band_name'] ?? ''));
                $totalKeys = (int)($l['total_keys'] ?? 0);
                $issuedKeys = (int)($l['issued_keys'] ?? 0);
                $stockKeys  = (int)($l['stock_keys'] ?? 0);

                $bandTxt = $bandId > 0 ? $bandName : 'Vrij';
                $warn = '';
                if ($totalKeys < 2) $warn = 'Minder dan 2 sleutels (bijbestellen?)';
              ?>
              <tr>
                <td>
                  <a href="/admin/locker_edit.php?id=<?= (int)$lid ?>">
                    <strong><?= h($lockerNo) ?></strong>
                  </a>
                </td>
                <td>
                  <?php if ($bandId > 0): ?>
                    <a href="/admin/band_detail.php?id=<?= $bandId ?>"><?= h($bandTxt) ?></a>
                  <?php else: ?>
                    <span class="badge badge-ok">Vrij</span>
                  <?php endif; ?>
                </td>
                <td><?= (int)$totalKeys ?></td>
                <td>
                  <span class="badge badge-ok">Voorraad: <?= (int)$stockKeys ?></span>
                  <span class="badge badge-warn" style="margin-left:6px">Uitgegeven: <?= (int)$issuedKeys ?></span>
                </td>
                <td class="small">
                  <?php if ($warn !== ''): ?>
                    <span class="badge badge-danger"><?= h($warn) ?></span>
                  <?php else: ?>
                    <span class="small">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="small" style="margin-top:10px">
          Regels: per kast normaal <code>2</code> sleutels, extra bij te bestellen. Status per sleutel komt uit de laatste transactie (ISSUE/RETURN).
        </div>
      </div>

      <div class="card" id="sleutels">
        <h2>Overzicht sleutels</h2>
        <div class="small"><?= (int)$keyCount ?> sleutels</div>

        <div class="tablewrap" style="margin-top:12px">
          <table>
            <thead>
              <tr>
                <th>Sleutelnummer</th>
                <th>Type</th>
                <th>Passend op</th>
                <th>Status / Uitgegeven aan</th>
                <th>Sleutelnaam</th>
                <th>Acties</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$keys): ?>
              <tr><td colspan="6" class="small">Geen sleutels gevonden.</td></tr>
            <?php else: foreach ($keys as $r): ?>
              <?php
                $keyId   = (int)$r['id'];
                $code    = (string)$r['key_code'];
                $name    = trim((string)($r['description'] ?? ''));
                $slot    = $r['key_slot'] !== null ? (int)$r['key_slot'] : null;
                $active  = (int)($r['active'] ?? 1) === 1;

                $keyType = (string)($r['key_type'] ?? 'LOCKER');

                $band    = trim((string)($r['band_name'] ?? ''));
                $locker  = trim((string)($r['locker_no'] ?? ''));

                if ($keyType === 'MASTER') {
                  $kastTxt = 'Alle POR-kasten (Master)';
                } elseif ($keyType === 'EXTERNAL') {
                  $kastTxt = 'Geen POR-kast (Extern)';
                } else {
                  $kastTxt = '—';
                  if ($band !== '' && $locker !== '') $kastTxt = $band . ' · ' . $locker;
                  elseif ($locker !== '') $kastTxt = $locker;
                  elseif ($band !== '') $kastTxt = $band;
                  if ($slot !== null) $kastTxt .= ' · sleutel ' . $slot;
                }

                $txId     = $r['tx_id'] ? (int)$r['tx_id'] : 0;
                $txAction = (string)($r['tx_action'] ?? '');
                $isIssued = ($txId > 0 && $txAction === 'ISSUE');

                $contactId   = $r['tx_contact_id'] ? (int)$r['tx_contact_id'] : 0;
                $contactName = (string)($r['contact_name'] ?? 'Onbekend');
                $docId       = $r['doc_id'] ? (int)$r['doc_id'] : 0;

                $label = $name !== '' ? $name : ('Sleutel ' . $code);
              ?>
              <tr>
                <td><strong><?= h($code) ?></strong></td>
                <td>
                  <?php if ($keyType === 'MASTER'): ?>
                    <span class="badge badge-ok">Master</span>
                  <?php elseif ($keyType === 'EXTERNAL'): ?>
                    <span class="badge badge-off">Extern</span>
                  <?php else: ?>
                    <span class="badge badge-warn">Kast</span>
                  <?php endif; ?>
                </td>
                <td><?= h($kastTxt) ?></td>
                <td>
                  <?php if (!$isIssued): ?>
                    <span class="badge badge-ok">In voorraad</span>
                  <?php else: ?>
                    <span class="badge badge-warn">Uitgegeven</span>
                    <div class="small" style="margin-top:6px">
                      Aan:
                      <?php if ($contactId > 0): ?>
                        <a href="/admin/contacts_view.php?id=<?= $contactId ?>"><?= h($contactName) ?></a>
                      <?php else: ?>
                        <span class="small">Onbekend contact</span>
                      <?php endif; ?>

                      <?php if ($docId > 0): ?>
                        · <a href="/admin/key_doc_view.php?id=<?= $docId ?>">Contract</a>
                      <?php else: ?>
                        · <span class="small">Geen contract</span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="/admin/keys_view.php?id=<?= $keyId ?>"><?= h($label) ?></a>
                  <?php if (!$active): ?>
                    <span class="badge badge-off">Inactief</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="btn btn-sm" href="/admin/keys_edit.php?id=<?= $keyId ?>">Bewerken</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="small" style="margin-top:10px">
          Uitgifte/retour registreer je via <code>Band → Sleutelbeheer</code> (band_keys.php). Hier beheer je de sleutel-items + koppeling (kast/master/extern).
        </div>
      </div>

    </div>
  </div>

</div></div>
</body>
</html>