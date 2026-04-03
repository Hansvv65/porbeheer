<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';

$config = $GLOBALS['config'] ?? [];

function yesNo(bool $v): string
{
    return $v ? 'Ja' : 'Nee';
}

function maskValue(?string $value, int $show = 3): string
{
    $value = (string)$value;
    if ($value === '') {
        return '';
    }
    $len = strlen($value);
    if ($len <= $show) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, $show) . str_repeat('*', max(0, $len - $show));
}

$phpVersion   = PHP_VERSION;
$sapi         = PHP_SAPI;
$serverSoft   = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
$docRoot      = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$scriptName   = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$https        = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$host         = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
$appEnv       = defined('APP_ENV') ? APP_ENV : 'onbekend';
$appUrl       = defined('APP_URL') ? APP_URL : '';
$appVersion   = defined('APP_VERSION') ? APP_VERSION : '';
$sessionName  = session_name();

$vendorRoot   = realpath(__DIR__ . '/../cgi-bin/vendor') ?: (__DIR__ . '/../cgi-bin/vendor');
$appRoot      = realpath(__DIR__ . '/../cgi-bin/app') ?: (__DIR__ . '/../cgi-bin/app');
$publicRoot   = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

$robthreeOk   = class_exists(\RobThree\Auth\TwoFactorAuth::class);
$baconOk      = class_exists(\BaconQrCode\Writer::class);
$daspridOk    = class_exists(\DASPRiD\Enum\AbstractEnum::class);
$imagickOk    = extension_loaded('imagick');
$pdoOk        = isset($pdo) && $pdo instanceof PDO;
$mailHost     = (string)($config['mail']['smtp_host'] ?? '');
$mailUser     = (string)($config['mail']['smtp_user'] ?? '');
$mailPort     = (string)($config['mail']['smtp_port'] ?? '');
$mailFrom     = (string)($config['mail']['from_email'] ?? '');

$dbHost       = (string)($config['db']['host'] ?? '');
$dbName       = (string)($config['db']['name'] ?? '');

$dbCheckMsg = 'Niet getest';
try {
    if ($pdoOk) {
        $st = $pdo->query('SELECT NOW() AS now_time');
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $dbCheckMsg = 'OK - ' . (string)($row['now_time'] ?? '');
    }
} catch (Throwable $e) {
    $dbCheckMsg = 'Fout';
}

$tests = [
    [
        'title' => 'E-mail test',
        'url'   => '/admin/test-email.php',
        'desc'  => 'Controle van SMTP / verzenden van testmail.',
    ],
    [
        'title' => '2FA test (basis)',
        'url'   => '/admin/test-2fa.php',
        'desc'  => 'Basis test voor secret en QR via huidige 2FA-opzet.',
    ],
    [
        'title' => '2FA test (offline QR)',
        'url'   => '/admin/test-2fa-all.php',
        'desc'  => 'Offline QR-generatie met RobThree + Bacon QR.',
    ],
];
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Tech test</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{
      font-family: Arial, Helvetica, sans-serif;
      background:#f5f7fb;
      color:#1f2937;
      margin:0;
    }
    .wrap{
      max-width:1200px;
      margin:24px auto;
      padding:0 20px 40px;
    }
    .topbar{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:16px;
      margin-bottom:20px;
      flex-wrap:wrap;
    }
    .brand h1{
      margin:0;
      font-size:30px;
    }
    .sub{
      margin-top:6px;
      color:#6b7280;
      font-size:14px;
    }
    .userbox{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:14px;
      padding:12px 16px;
      box-shadow:0 8px 24px rgba(0,0,0,.05);
    }
    .line1{
      font-weight:700;
      margin-bottom:4px;
    }
    .line2 a{
      color:#2563eb;
      text-decoration:none;
    }
    .grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
      gap:18px;
      margin-bottom:24px;
    }
    .card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:18px;
      padding:18px 18px 16px;
      box-shadow:0 10px 24px rgba(0,0,0,.05);
    }
    .card h2, .card h3{
      margin:0 0 12px 0;
    }
    .testlink{
      display:block;
      padding:12px 14px;
      margin:10px 0;
      border:1px solid #dbe3f0;
      border-radius:12px;
      text-decoration:none;
      color:#111827;
      background:#f8fafc;
    }
    .testlink:hover{
      background:#eef4ff;
      border-color:#93c5fd;
    }
    .muted{
      color:#6b7280;
      font-size:14px;
    }
    .kv{
      display:grid;
      grid-template-columns:200px 1fr;
      gap:8px 14px;
      font-size:14px;
    }
    .kv div:nth-child(odd){
      font-weight:700;
      color:#374151;
    }
    .ok{ color:#166534; font-weight:700; }
    .no{ color:#991b1b; font-weight:700; }
    .pill{
      display:inline-block;
      padding:4px 10px;
      border-radius:999px;
      background:#eef2ff;
      color:#3730a3;
      font-size:12px;
      font-weight:700;
    }
    .small{
      font-size:13px;
    }
  </style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div class="brand">
      <h1>Tech test</h1>
      <div class="sub">Technische controles en testpagina’s</div>
    </div>

    <div class="userbox">
      <div class="line1"><?= h($user['username'] ?? '') ?> · <?= h($role) ?></div>
      <div class="line2">
        <a href="/admin/settings.php">Configuratie</a> •
        <a href="/admin/dashboard.php">Dashboard</a> •
        <a href="/logout.php">Uitloggen</a>
      </div>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Tests</h2>
      <?php foreach ($tests as $t): ?>
        <a class="testlink" href="<?= h($t['url']) ?>">
          <strong><?= h($t['title']) ?></strong><br>
          <span class="muted"><?= h($t['desc']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h2>Applicatie</h2>
      <div class="kv">
        <div>Omgeving</div><div><?= h($appEnv) ?></div>
        <div>App URL</div><div><?= h($appUrl) ?></div>
        <div>Versie</div><div><?= h($appVersion) ?></div>
        <div>Host</div><div><?= h($host) ?></div>
        <div>HTTPS</div><div class="<?= $https ? 'ok' : 'no' ?>"><?= yesNo($https) ?></div>
        <div>Sessie naam</div><div><?= h($sessionName) ?></div>
        <div>Script</div><div><?= h($scriptName) ?></div>
      </div>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h3>Server / PHP</h3>
      <div class="kv">
        <div>PHP versie</div><div><?= h($phpVersion) ?></div>
        <div>SAPI</div><div><?= h($sapi) ?></div>
        <div>Server software</div><div><?= h($serverSoft) ?></div>
        <div>Document root</div><div><?= h($docRoot) ?></div>
        <div>Public root</div><div class="small"><?= h($publicRoot) ?></div>
        <div>App root</div><div class="small"><?= h($appRoot) ?></div>
        <div>Vendor root</div><div class="small"><?= h($vendorRoot) ?></div>
      </div>
    </div>

    <div class="card">
      <h3>Database / mail</h3>
      <div class="kv">
        <div>PDO</div><div class="<?= $pdoOk ? 'ok' : 'no' ?>"><?= yesNo($pdoOk) ?></div>
        <div>DB host</div><div><?= h($dbHost) ?></div>
        <div>DB naam</div><div><?= h($dbName) ?></div>
        <div>DB check</div><div><?= h($dbCheckMsg) ?></div>
        <div>SMTP host</div><div><?= h($mailHost) ?></div>
        <div>SMTP poort</div><div><?= h($mailPort) ?></div>
        <div>SMTP user</div><div><?= h(maskValue($mailUser, 4)) ?></div>
        <div>From e-mail</div><div><?= h($mailFrom) ?></div>
      </div>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h3>2FA / libraries</h3>
      <div class="kv">
        <div>RobThree</div><div class="<?= $robthreeOk ? 'ok' : 'no' ?>"><?= yesNo($robthreeOk) ?></div>
        <div>BaconQrCode</div><div class="<?= $baconOk ? 'ok' : 'no' ?>"><?= yesNo($baconOk) ?></div>
        <div>DASPRiD Enum</div><div class="<?= $daspridOk ? 'ok' : 'no' ?>"><?= yesNo($daspridOk) ?></div>
        <div>Imagick extensie</div><div class="<?= $imagickOk ? 'ok' : 'no' ?>"><?= yesNo($imagickOk) ?></div>
        <div>Offline QR</div>
        <div>
          <?php if ($robthreeOk && $baconOk && $daspridOk): ?>
            <span class="pill">Beschikbaar</span>
          <?php else: ?>
            <span class="pill" style="background:#fef2f2;color:#991b1b;">Niet compleet</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Opmerking</h3>
      <p class="muted">
        Deze pagina is bedoeld voor technische controles. Gebruik de testlinks hierboven om mail,
        2FA-secret, QR-generatie en verificatie los van de normale workflow te testen.
      </p>
      <p class="muted">
        Voor productie is offline QR zonder externe QR-service de veiligste keuze.
      </p>
    </div>
  </div>

</div>
</body>
</html>