<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('admin', $pdo);


if (!function_exists('h')) {
    function h(?string $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$dbName = (string)($config['db']['name'] ?? '');
if ($dbName === '') {
    http_response_code(500);
    exit('Database naam ontbreekt in config.');
}

auditLog($pdo, 'PAGE_VIEW', 'admin/db_schema.php', ['db' => $dbName]);

function parseEnumValues(string $columnType): array
{
    $columnType = trim($columnType);
    if (!preg_match("/^enum\\((.*)\\)$/i", $columnType, $m)) {
        return [];
    }

    $inside = $m[1];
    preg_match_all("/'((?:\\\\'|[^'])*)'/", $inside, $matches);
    $values = [];
    foreach (($matches[1] ?? []) as $v) {
        $values[] = str_replace("\\'", "'", $v);
    }
    return $values;
}

function stringifyDefault(mixed $value): string
{
    if ($value === null) return 'NULL';
    if ($value === '') return "''";
    return (string)$value;
}

function detectPossibleMissingFkSuggestions(array $columnsByTable, array $fkMap): array
{
    $suggestions = [];

    foreach ($columnsByTable as $tableName => $columns) {
        foreach ($columns as $col) {
            $colName = (string)$col['COLUMN_NAME'];

            if ($colName === 'id') {
                continue;
            }

            if (!preg_match('/_id$/', $colName)) {
                continue;
            }

            $fkKey = $tableName . '.' . $colName;
            if (isset($fkMap[$fkKey])) {
                continue;
            }

            $base = substr($colName, 0, -3); // zonder _id
            $candidateTables = [
                $base,
                $base . 's',
                $base . 'es',
            ];

            // simpele Engelse/Yachtige normalisaties
            if (str_ends_with($base, 'y')) {
                $candidateTables[] = substr($base, 0, -1) . 'ies';
            }

            $candidateTables = array_values(array_unique($candidateTables));

            $matches = [];
            foreach (array_keys($columnsByTable) as $otherTable) {
                if (in_array($otherTable, $candidateTables, true)) {
                    $matches[] = $otherTable;
                }
            }

            if (!empty($matches)) {
                $suggestions[] = [
                    'table' => $tableName,
                    'column' => $colName,
                    'candidate_tables' => $matches,
                    'reason' => 'kolom eindigt op _id maar heeft geen foreign key'
                ];
            }
        }
    }

    return $suggestions;
}

$schemaText = '';
$error = null;

try {
    // Tabellen
    $stTables = $pdo->prepare("
        SELECT
            t.TABLE_NAME,
            t.TABLE_TYPE,
            t.ENGINE,
            t.TABLE_COLLATION,
            t.TABLE_ROWS,
            t.CREATE_TIME,
            t.UPDATE_TIME
        FROM information_schema.TABLES t
        WHERE t.TABLE_SCHEMA = ?
        ORDER BY t.TABLE_NAME
    ");
    $stTables->execute([$dbName]);
    $tables = $stTables->fetchAll(PDO::FETCH_ASSOC);

    // Kolommen
    $stCols = $pdo->prepare("
        SELECT
            c.TABLE_NAME,
            c.ORDINAL_POSITION,
            c.COLUMN_NAME,
            c.COLUMN_TYPE,
            c.DATA_TYPE,
            c.IS_NULLABLE,
            c.COLUMN_DEFAULT,
            c.COLUMN_KEY,
            c.EXTRA
        FROM information_schema.COLUMNS c
        WHERE c.TABLE_SCHEMA = ?
        ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
    ");
    $stCols->execute([$dbName]);
    $columnsRaw = $stCols->fetchAll(PDO::FETCH_ASSOC);

    // Indexen
    $stIdx = $pdo->prepare("
        SELECT
            s.TABLE_NAME,
            s.INDEX_NAME,
            s.NON_UNIQUE,
            s.SEQ_IN_INDEX,
            s.COLUMN_NAME
        FROM information_schema.STATISTICS s
        WHERE s.TABLE_SCHEMA = ?
        ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX
    ");
    $stIdx->execute([$dbName]);
    $indexesRaw = $stIdx->fetchAll(PDO::FETCH_ASSOC);

    // Foreign keys
    $stFk = $pdo->prepare("
        SELECT
            kcu.TABLE_NAME,
            kcu.CONSTRAINT_NAME,
            kcu.COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME,
            kcu.REFERENCED_COLUMN_NAME,
            rc.UPDATE_RULE,
            rc.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE kcu
        LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
         AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        WHERE kcu.TABLE_SCHEMA = ?
          AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
    ");
    $stFk->execute([$dbName]);
    $fksRaw = $stFk->fetchAll(PDO::FETCH_ASSOC);

    // Views
    $stViews = $pdo->prepare("
        SELECT
            v.TABLE_NAME,
            v.CHECK_OPTION,
            v.IS_UPDATABLE,
            v.SECURITY_TYPE,
            v.VIEW_DEFINITION
        FROM information_schema.VIEWS v
        WHERE v.TABLE_SCHEMA = ?
        ORDER BY v.TABLE_NAME
    ");
    $stViews->execute([$dbName]);
    $views = $stViews->fetchAll(PDO::FETCH_ASSOC);

    // Triggers
    $stTriggers = $pdo->prepare("
        SELECT
            TRIGGER_NAME,
            EVENT_OBJECT_TABLE,
            ACTION_TIMING,
            EVENT_MANIPULATION,
            ACTION_STATEMENT
        FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = ?
        ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME
    ");
    $stTriggers->execute([$dbName]);
    $triggers = $stTriggers->fetchAll(PDO::FETCH_ASSOC);

    // Routines
    $stRoutines = $pdo->prepare("
        SELECT
            ROUTINE_TYPE,
            ROUTINE_NAME,
            DTD_IDENTIFIER,
            ROUTINE_DEFINITION
        FROM information_schema.ROUTINES
        WHERE ROUTINE_SCHEMA = ?
        ORDER BY ROUTINE_TYPE, ROUTINE_NAME
    ");
    $stRoutines->execute([$dbName]);
    $routines = $stRoutines->fetchAll(PDO::FETCH_ASSOC);

    // CREATE TABLE statements
    $createStatements = [];
    foreach ($tables as $t) {
        $tableName = (string)$t['TABLE_NAME'];
        $q = $pdo->query("SHOW CREATE TABLE `{$tableName}`");
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $createStatements[$tableName] = (string)($row['Create Table'] ?? '');
    }

    // Groeperingen
    $columnsByTable = [];
    $enumColumnsByTable = [];

    foreach ($columnsRaw as $r) {
        $tableName = (string)$r['TABLE_NAME'];
        $columnsByTable[$tableName][] = $r;

        $enumValues = parseEnumValues((string)$r['COLUMN_TYPE']);
        if (!empty($enumValues)) {
            $enumColumnsByTable[$tableName][] = [
                'column' => $r['COLUMN_NAME'],
                'values' => $enumValues
            ];
        }
    }

    $indexesByTable = [];
    foreach ($indexesRaw as $r) {
        $table = (string)$r['TABLE_NAME'];
        $index = (string)$r['INDEX_NAME'];

        if (!isset($indexesByTable[$table][$index])) {
            $indexesByTable[$table][$index] = [
                'non_unique' => (int)$r['NON_UNIQUE'],
                'columns' => []
            ];
        }
        $indexesByTable[$table][$index]['columns'][] = (string)$r['COLUMN_NAME'];
    }

    $fksByTable = [];
    $fkMap = [];
    foreach ($fksRaw as $r) {
        $table = (string)$r['TABLE_NAME'];
        $column = (string)$r['COLUMN_NAME'];
        $fksByTable[$table][] = $r;
        $fkMap[$table . '.' . $column] = true;
    }

    $missingFkSuggestions = detectPossibleMissingFkSuggestions($columnsByTable, $fkMap);

    // Statistieken en ontwerpnotities
    $notes = [];
    $tableCount = count($tables);
    $viewCount = count($views);
    $triggerCount = count($triggers);
    $routineCount = count($routines);
    $fkCount = count($fksRaw);

    $notes[] = "Aantal tabellen: {$tableCount}";
    $notes[] = "Aantal views: {$viewCount}";
    $notes[] = "Aantal triggers: {$triggerCount}";
    $notes[] = "Aantal routines: {$routineCount}";
    $notes[] = "Aantal foreign keys: {$fkCount}";

    if ($fkCount === 0) {
        $notes[] = "Let op: er zijn geen foreign keys gedefinieerd. Relaties lijken dan mogelijk alleen in applicatielogica bewaakt te worden.";
    }

    if (!empty($missingFkSuggestions)) {
        $notes[] = "Er zijn " . count($missingFkSuggestions) . " kolommen gevonden die op _id eindigen zonder foreign key. Mogelijk ontbreken daar relationele constraints.";
    }

    foreach ($columnsByTable as $tableName => $columns) {
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        $hasDeletedAt = false;

        foreach ($columns as $c) {
            $cn = (string)$c['COLUMN_NAME'];
            if ($cn === 'created_at') $hasCreatedAt = true;
            if ($cn === 'updated_at') $hasUpdatedAt = true;
            if ($cn === 'deleted_at') $hasDeletedAt = true;
        }

        if ($hasCreatedAt && !$hasUpdatedAt) {
            $notes[] = "Tabel {$tableName}: wel created_at maar geen updated_at.";
        }
        if ($hasDeletedAt) {
            $notes[] = "Tabel {$tableName}: gebruikt soft delete via deleted_at.";
        }
    }

    $lines = [];
    $lines[] = "============================================================";
    $lines[] = "DATABASE SCHEMA OVERZICHT";
    $lines[] = "============================================================";
    $lines[] = "Database        : " . $dbName;
    $lines[] = "Gegenereerd op  : " . date('Y-m-d H:i:s');
    $lines[] = "Aantal tabellen : " . count($tables);
    $lines[] = "";

    $lines[] = "============================================================";
    $lines[] = "1. SAMENVATTING";
    $lines[] = "============================================================";
    foreach ($notes as $n) {
        $lines[] = "- " . $n;
    }
    $lines[] = "";

    $lines[] = "============================================================";
    $lines[] = "2. TABELLEN";
    $lines[] = "============================================================";
    foreach ($tables as $t) {
        $tableName  = (string)$t['TABLE_NAME'];
        $tableType  = (string)($t['TABLE_TYPE'] ?? '');
        $engine     = (string)($t['ENGINE'] ?? '');
        $collation  = (string)($t['TABLE_COLLATION'] ?? '');
        $tableRows  = (string)($t['TABLE_ROWS'] ?? '');
        $createTime = (string)($t['CREATE_TIME'] ?? '');
        $updateTime = (string)($t['UPDATE_TIME'] ?? '');

        $lines[] = "------------------------------------------------------------";
        $lines[] = "TABEL: " . $tableName;
        $lines[] = "------------------------------------------------------------";
        $lines[] = "Type      : " . $tableType;
        $lines[] = "Engine    : " . $engine;
        $lines[] = "Collation : " . $collation;
        $lines[] = "Rows(est) : " . $tableRows;
        $lines[] = "Created   : " . $createTime;
        $lines[] = "Updated   : " . $updateTime;
        $lines[] = "";

        $lines[] = "KOLOMMEN";
        $lines[] = str_pad('#', 4)
                 . str_pad('Kolom', 28)
                 . str_pad('Type', 28)
                 . str_pad('Null', 8)
                 . str_pad('Key', 8)
                 . str_pad('Default', 20)
                 . "Extra";

        foreach (($columnsByTable[$tableName] ?? []) as $c) {
            $lines[] = str_pad((string)$c['ORDINAL_POSITION'], 4)
                     . str_pad((string)$c['COLUMN_NAME'], 28)
                     . str_pad((string)$c['COLUMN_TYPE'], 28)
                     . str_pad((string)$c['IS_NULLABLE'], 8)
                     . str_pad((string)$c['COLUMN_KEY'], 8)
                     . str_pad(stringifyDefault($c['COLUMN_DEFAULT']), 20)
                     . (string)$c['EXTRA'];
        }

        $lines[] = "";
        $lines[] = "INDEXEN";
        if (!empty($indexesByTable[$tableName])) {
            foreach ($indexesByTable[$tableName] as $indexName => $idx) {
                $kind = ((int)$idx['non_unique'] === 0) ? 'UNIQUE' : 'NON_UNIQUE';
                $cols = implode(', ', $idx['columns']);
                $lines[] = "- {$indexName} [{$kind}] : {$cols}";
            }
        } else {
            $lines[] = "- geen";
        }

        $lines[] = "";
        $lines[] = "FOREIGN KEYS";
        if (!empty($fksByTable[$tableName])) {
            foreach ($fksByTable[$tableName] as $fk) {
                $lines[] = "- {$fk['CONSTRAINT_NAME']} : {$fk['COLUMN_NAME']} -> "
                         . "{$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}"
                         . " (ON UPDATE {$fk['UPDATE_RULE']}, ON DELETE {$fk['DELETE_RULE']})";
            }
        } else {
            $lines[] = "- geen";
        }

        $lines[] = "";
        $lines[] = "ENUM WAARDEN";
        if (!empty($enumColumnsByTable[$tableName])) {
            foreach ($enumColumnsByTable[$tableName] as $enumInfo) {
                $lines[] = "- " . $enumInfo['column'] . " : " . implode(', ', $enumInfo['values']);
            }
        } else {
            $lines[] = "- geen";
        }

        $lines[] = "";
        $lines[] = "CREATE TABLE";
        $lines[] = $createStatements[$tableName] !== ''
            ? $createStatements[$tableName] . ';'
            : '-- niet beschikbaar --';

        $lines[] = "";
        $lines[] = "";
    }

    $lines[] = "============================================================";
    $lines[] = "3. VIEWS";
    $lines[] = "============================================================";
    if (!empty($views)) {
        foreach ($views as $v) {
            $lines[] = "------------------------------------------------------------";
            $lines[] = "VIEW: " . $v['TABLE_NAME'];
            $lines[] = "------------------------------------------------------------";
            $lines[] = "Check option : " . (string)$v['CHECK_OPTION'];
            $lines[] = "Updatable    : " . (string)$v['IS_UPDATABLE'];
            $lines[] = "Security     : " . (string)$v['SECURITY_TYPE'];
            $lines[] = "Definition   : ";
            $lines[] = (string)$v['VIEW_DEFINITION'];
            $lines[] = "";
        }
    } else {
        $lines[] = "- geen views gevonden";
        $lines[] = "";
    }

    $lines[] = "============================================================";
    $lines[] = "4. TRIGGERS";
    $lines[] = "============================================================";
    if (!empty($triggers)) {
        foreach ($triggers as $tr) {
            $lines[] = "------------------------------------------------------------";
            $lines[] = "TRIGGER: " . $tr['TRIGGER_NAME'];
            $lines[] = "------------------------------------------------------------";
            $lines[] = "Tabel   : " . $tr['EVENT_OBJECT_TABLE'];
            $lines[] = "Timing  : " . $tr['ACTION_TIMING'];
            $lines[] = "Event   : " . $tr['EVENT_MANIPULATION'];
            $lines[] = "Actie   : ";
            $lines[] = (string)$tr['ACTION_STATEMENT'];
            $lines[] = "";
        }
    } else {
        $lines[] = "- geen triggers gevonden";
        $lines[] = "";
    }

    $lines[] = "============================================================";
    $lines[] = "5. ROUTINES (PROCEDURES / FUNCTIONS)";
    $lines[] = "============================================================";
    if (!empty($routines)) {
        foreach ($routines as $r) {
            $lines[] = "------------------------------------------------------------";
            $lines[] = $r['ROUTINE_TYPE'] . ": " . $r['ROUTINE_NAME'];
            $lines[] = "------------------------------------------------------------";
            $lines[] = "Type / return : " . (string)$r['DTD_IDENTIFIER'];
            $lines[] = "Definition    : ";
            $lines[] = (string)$r['ROUTINE_DEFINITION'];
            $lines[] = "";
        }
    } else {
        $lines[] = "- geen stored procedures of functions gevonden";
        $lines[] = "";
    }

    $lines[] = "============================================================";
    $lines[] = "6. MOGELIJK ONTBREKENDE FOREIGN KEYS";
    $lines[] = "============================================================";
    if (!empty($missingFkSuggestions)) {
        foreach ($missingFkSuggestions as $m) {
            $lines[] = "- {$m['table']}.{$m['column']} : "
                     . $m['reason']
                     . " | mogelijke verwijzing naar tabel(len): "
                     . implode(', ', $m['candidate_tables']);
        }
    } else {
        $lines[] = "- geen duidelijke kandidaten gevonden";
    }
    $lines[] = "";

    $lines[] = "============================================================";
    $lines[] = "7. ONTWERPNOTITIES / SUGGESTIES";
    $lines[] = "============================================================";
    $lines[] = "- Controleer of alle *_id-kolommen die logisch naar een andere tabel wijzen ook echt een foreign key hebben.";
    $lines[] = "- Controleer of audit-tabellen voldoende context opslaan: actor, action, target_type, target_id, metadata, ip, user_agent, created_at.";
    $lines[] = "- Controleer of tabellen met statusvelden een consistente set enum-waarden of referentietabel gebruiken.";
    $lines[] = "- Controleer of unieke velden zoals email, username, sleutelnummer of bandnaam ook echt unieke indexen hebben waar nodig.";
    $lines[] = "- Controleer of soft delete-tabellen filters nodig hebben op deleted_at IS NULL in alle beheerpagina's.";
    $lines[] = "- Controleer of belangrijke zoekvelden indexen hebben, zoals bandnaam, datumvelden, foreign keys en loginvelden.";
    $lines[] = "";

    $schemaText = implode("\n", $lines);

} catch (Throwable $e) {
    $error = 'Schema ophalen mislukt: ' . $e->getMessage();
    auditLog($pdo, 'DB_SCHEMA_FAIL', 'admin/db_schema.php', [
        'db' => $dbName,
        'error' => $e->getMessage()
    ]);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - Database schema</title>
<style>
  :root{
    --text:#fff;
    --muted:rgba(255,255,255,.78);
    --border:rgba(255,255,255,.22);
    --glass:rgba(255,255,255,.12);
    --shadow:0 14px 40px rgba(0,0,0,.45);
    --ok:#7CFFB2;
    --err:#FF8DA1;
    --accent:#ffd86b;
  }
  body{
    margin:0;
    font-family:Arial,sans-serif;
    color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;
    background-size:cover;
  }
  .backdrop{
    min-height:100vh;
    background:
      radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
      linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
    padding:26px;
    box-sizing:border-box;
    display:flex;
    justify-content:center;
  }
  .wrap{ width:min(1280px, 96vw); }

  .topbar{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:14px;
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
  .userbox .line1{ font-weight:bold; }
  .userbox .line2{ color:var(--muted); margin-top:4px; font-size:13px; }

  a{ color:#fff; }
  a:visited{ color:var(--accent); }

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

  .toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    justify-content:space-between;
    margin-bottom:14px;
  }
  .toolbar-left{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
  }
  .meta{
    color:var(--muted);
    font-size:13px;
  }

  .btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(255,255,255,.14);
    color:#fff;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 10px 22px rgba(0,0,0,.20);
    transition:transform .12s ease, background .12s ease, border-color .12s ease;
    text-decoration:none;
  }
  .btn:hover{
    transform:translateY(-1px);
    background:rgba(255,255,255,.18);
    border-color:rgba(255,255,255,.35);
  }
  .btn.ok{
    border-color:rgba(124,255,178,.35);
    background:rgba(124,255,178,.10);
  }

  .msg-err{
    margin:0 0 12px 0;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid rgba(255,141,161,.35);
    background:rgba(255,141,161,.12);
    color:var(--err);
    font-weight:800;
  }

  textarea.schema{
    width:100%;
    min-height:74vh;
    resize:vertical;
    box-sizing:border-box;
    border-radius:16px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(0,0,0,.40);
    color:#fff;
    padding:16px;
    font:13px/1.45 Consolas, Monaco, monospace;
    outline:none;
    white-space:pre;
  }
  textarea.schema:focus{
    border-color:rgba(255,255,255,.34);
    box-shadow:0 0 0 3px rgba(255,255,255,.08);
  }

  .hint{
    margin-top:10px;
    color:var(--muted);
    font-size:13px;
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
        <div class="sub">POP Oefenruimte Zevenaar • admin</div>
      </div>

      <div class="userbox">
        <div class="line1">Ingelogd: <?= h($user['username'] ?? '') ?> • Rol: <?= h($role) ?></div>
        <div class="line2">
          <a href="/admin/dashboard.php">Dashboard</a> •
          <a href="/admin/beheer.php">Beheer</a> •
          <a href="/admin/users.php">Users</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2 style="margin:0 0 8px 0;">Database schema</h2>
      <div class="meta">Database: <strong><?= h($dbName) ?></strong> • uitgebreid overzicht inclusief views, triggers, routines, enums en relationele signalen</div>

      <?php if ($error): ?>
        <div class="msg-err" style="margin-top:12px;"><?= h($error) ?></div>
      <?php else: ?>
        <div class="toolbar" style="margin-top:14px;">
          <div class="toolbar-left">
            <button type="button" class="btn ok" onclick="copySchema()">Kopieer schema</button>
            <button type="button" class="btn" onclick="selectSchema()">Selecteer alles</button>
            <a class="btn" href="/admin/db_schema.php">Ververs</a>
          </div>
          <div class="meta">Plak de uitvoer hier in de chat voor analyse of nieuwe functionaliteit.</div>
        </div>

        <textarea id="schemaBox" class="schema" spellcheck="false" readonly><?= h($schemaText) ?></textarea>
        <div class="hint">Tip: deze versie is uitgebreider en daardoor heel geschikt om relaties, ontbrekende constraints en uitbreidingen samen te beoordelen.</div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
function selectSchema() {
  const box = document.getElementById('schemaBox');
  if (!box) return;
  box.focus();
  box.select();
  box.setSelectionRange(0, box.value.length);
}

async function copySchema() {
  const box = document.getElementById('schemaBox');
  if (!box) return;

  try {
    await navigator.clipboard.writeText(box.value);
    alert('Schema gekopieerd.');
  } catch (e) {
    selectSchema();
    alert('Automatisch kopiëren lukte niet. De tekst is geselecteerd.');
  }
}
</script>
</body>
</html>