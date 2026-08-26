<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireSectionAccess('admin', 'read');

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('admin', $pdo);

$sections = [
    'bands'     => 'Bands',
    'planning'  => 'Planning',
    'sleutels'  => 'Sleutels & Kasten',
    'financien' => 'Financiën',
    'contacten' => 'Contacten',
    'admin'     => 'Admin',
];
$roles = ['ADMIN', 'BEHEER', 'FINANCIEEL', 'BANDLID', 'BESTUURSLID'];

$csrf = csrfToken();
$errors = [];
$success = null;
$canEdit = hasSectionAccess($pdo, 'admin', 'write');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSectionAccess('admin', 'write'); // ook bij een geknoeide request: alleen write mag opslaan
    requireCsrf($_POST['csrf'] ?? '');

    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM role_section_access");
        $ins = $pdo->prepare("INSERT INTO role_section_access (role, section, level, own_only) VALUES (?, ?, ?, ?)");

        foreach ($roles as $r) {
            foreach ($sections as $s => $label) {
                $field = 'cell_' . $r . '_' . $s;
                $value = (string)($_POST[$field] ?? 'none');
                if ($value === 'none') {
                    continue;
                }
                if ($value === 'read') {
                    $ins->execute([$r, $s, 'read', 0]);
                } elseif ($value === 'write') {
                    $ins->execute([$r, $s, 'write', 0]);
                } elseif ($value === 'write_own') {
                    $ins->execute([$r, $s, 'write', 1]);
                }
            }
        }

        $pdo->commit();
        auditLog($pdo, 'ROLE_SECTION_ACCESS_SET', 'admin/rechtenmatrix.php', []);
        $success = 'Rechtenmatrix opgeslagen. Wijzigingen zijn zichtbaar zodra betrokken gebruikers opnieuw inloggen.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Opslaan mislukt: ' . $e->getMessage();
        auditLog($pdo, 'ROLE_SECTION_ACCESS_SET_FAIL', 'admin/rechtenmatrix.php', ['err' => $e->getMessage()]);
    }
}

// Huidige matrix ophalen
$current = []; // [role][section] = ['level'=>..,'own_only'=>bool]
$rows = $pdo->query("SELECT role, section, level, own_only FROM role_section_access")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $current[$row['role']][$row['section']] = [
        'level' => $row['level'],
        'own_only' => (bool)$row['own_only'],
    ];
}

function cellValue(array $current, string $role, string $section): string {
    $entry = $current[$role][$section] ?? null;
    if ($entry === null) return 'none';
    if ($entry['level'] === 'read') return 'read';
    return $entry['own_only'] ? 'write_own' : 'write';
}

auditLog($pdo, 'PAGE_VIEW', 'admin/rechtenmatrix.php');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Rechtenmatrix</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
.matrixwrap{overflow-x:auto;margin-top:10px;}
table.matrix{border-collapse:collapse;width:100%;min-width:760px;}
table.matrix th, table.matrix td{padding:8px 10px;text-align:left;border-bottom:1px solid rgba(255,255,255,.12);}
table.matrix th{color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;}
table.matrix td.section-name{font-weight:800;white-space:nowrap;}
table.matrix select{
  background:rgba(0,0,0,.25);color:#fff;border:1px solid rgba(255,255,255,.22);
  border-radius:8px;padding:5px 8px;font-size:13px;min-width:150px;
}
table.matrix select:disabled{opacity:.7;}
.legend{font-size:12px;color:var(--muted);margin-top:14px;line-height:1.6;}
.ok{color:#b8ffb8}
</style>
</head>
<body style="background-image:url('<?= h($bg) ?>')">
<div class="backdrop">
  <div class="wrap">

    <div class="topbar">
      <div class="brand">
        <h1><a href="/admin/dashboard.php">Porbeheer</a></h1>
        <div class="sub">POP Oefenruimte Zevenaar • rechtenmatrix</div>
      </div>

      <div class="userbox">
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a>
          <a href="/admin/beheer.php">Beheer</a>
          <a href="/logout.php">Uitloggen</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2>Rechtenmatrix</h2>
      <p class="small">Wat elke rol per onderdeel mag. Een gebruiker kan meerdere rollen tegelijk hebben; zijn rechten zijn dan de optelsom (het hoogste niveau per onderdeel). Wijzigingen worden pas zichtbaar bij de eerstvolgende login van betrokken gebruikers.</p>

      <?php if ($errors): ?>
        <div class="msg err"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="msg ok"><?= h($success) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="matrixwrap">
          <table class="matrix">
            <thead>
              <tr>
                <th>Onderdeel</th>
                <?php foreach ($roles as $r): ?>
                  <th><?= h($r) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sections as $sKey => $sLabel): ?>
                <tr>
                  <td class="section-name"><?= h($sLabel) ?></td>
                  <?php foreach ($roles as $r): ?>
                    <?php $val = cellValue($current, $r, $sKey); ?>
                    <td>
                      <select name="cell_<?= h($r) ?>_<?= h($sKey) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                        <option value="none" <?= $val === 'none' ? 'selected' : '' ?>>Geen</option>
                        <option value="read" <?= $val === 'read' ? 'selected' : '' ?>>Lezen</option>
                        <option value="write" <?= $val === 'write' ? 'selected' : '' ?>>Bewerken</option>
                        <option value="write_own" <?= $val === 'write_own' ? 'selected' : '' ?>>Bewerken (alleen eigen)</option>
                      </select>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($canEdit): ?>
          <div class="row" style="margin-top:16px;">
            <button class="btn" type="submit">Matrix opslaan</button>
          </div>
        <?php else: ?>
          <p class="small" style="margin-top:12px;">Je hebt hier alleen leestoegang.</p>
        <?php endif; ?>
      </form>

      <div class="legend">
        <strong>Let op:</strong> "Bewerken (alleen eigen)" is bedoeld voor de rol BANDLID -- dat beperkt schrijfrechten tot eigen band/contactgegevens/boekingen, niet tot alles binnen dat onderdeel. Deze koppeling wordt in de betreffende pagina's zelf afgedwongen.<br>
        Bestuurslid-status voor de mailinglijst stel je per gebruiker in op <a href="/admin/users_detail.php">de gebruikerspagina</a>, niet hier -- dat is los van paginarechten.
      </div>
    </div>
  </div>
</div>
</body>
</html>
