<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN','BEHEER']);

require_once __DIR__ . '/../../../libs/porbeheer/app/config.php'; // retourneert array
require_once __DIR__ . '/../../../libs/porbeheer/app/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$config = require __DIR__ . '/../cgi-bin/app/config.php';
$mailCfg = $config['mail'] ?? [];

$smtpHost  = (string)($mailCfg['smtp_host'] ?? 'smtp.internl.net');
$smtpPort  = (int)($mailCfg['smtp_port'] ?? 587);
$smtpUser  = (string)($mailCfg['smtp_user'] ?? '');
$smtpPass  = (string)($mailCfg['smtp_pass'] ?? '');
$fromEmail = (string)($mailCfg['from_email'] ?? $smtpUser);
$fromName  = (string)($mailCfg['from_name'] ?? 'Administratie Porzbeheer');

$errors = [];
$success = null;

$to      = (string)($_POST['to'] ?? '');
$subject = (string)($_POST['subject'] ?? 'Testmail porbeheer');
$message = (string)($_POST['message'] ?? "Dit is een testmail vanaf administratie.porzbeheer.nl\n\nGroet,\nporbeheer");

$showDebug = isset($_POST['show_debug']); // checkbox
$debugLog = '';

function maskSecret(string $s): string {
    if ($s === '') return '(leeg)';
    $len = strlen($s);
    if ($len <= 4) return str_repeat('*', $len) . " (len={$len})";
    return substr($s, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($s, -1) . " (len={$len})";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');

    $to = trim($to);
    $subject = trim($subject);
    $message = trim($message);

    if ($smtpUser === '' || $smtpPass === '') {
        $errors[] = 'SMTP config ontbreekt in app/config.php (smtp_user/smtp_pass).';
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ongeldig e-mailadres bij ontvanger.';
    }
    if ($subject === '') $errors[] = 'Onderwerp is verplicht.';
    if ($message === '') $errors[] = 'Bericht is verplicht.';

    if (!$errors) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host     = $smtpHost;
            $mail->Port     = $smtpPort;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;

            // Encryptie: 587 STARTTLS / 465 SMTPS
            $mail->SMTPSecure = ($smtpPort === 465)
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $mail->CharSet = 'UTF-8';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;

            $mail->isHTML(true);
            $mail->Body    = '<p>' . nl2br(h($message)) . '</p>';
            $mail->AltBody = $message;

            if ($showDebug) {
                // 2 = client/server dialog (voldoende); 3/4 = nog meer
                $mail->SMTPDebug = 2;

                // Capture debug output in a string (ipv echo)
                $mail->Debugoutput = function ($str, $level) use (&$debugLog) {
                    $debugLog .= rtrim((string)$str) . "\n";
                };
            }

            $mail->send();

            auditLog($pdo, 'EMAIL_TEST_SENT', 'to=' . $to . ' subject=' . $subject);
            $success = 'Testmail verzonden naar ' . $to;
        } catch (Throwable $e) {
            // PHPMailer vult vaak ErrorInfo met het “exacte” SMTP antwoord
            $errors[] = 'Exception: ' . $e->getMessage();
            if (isset($mail) && $mail instanceof PHPMailer) {
                $errors[] = 'PHPMailer ErrorInfo: ' . $mail->ErrorInfo;
            }
        }
    }
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<title>Test e-mail (debug)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px}
.wrap{max-width:860px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,.08);padding:22px}
h1{margin:0 0 10px}
label{display:block;margin-top:12px;font-weight:700}
input,textarea{width:100%;padding:10px;margin-top:6px;border:1px solid #cfd6dd;border-radius:8px}
textarea{min-height:140px;resize:vertical}
.row{display:flex;gap:12px}
.row>div{flex:1}
.btn{margin-top:16px;padding:10px 14px;border:0;border-radius:8px;background:#2c7be5;color:#fff;cursor:pointer}
.alert{margin-top:14px;padding:10px 12px;border-radius:8px}
.err{background:#ffe7e7;border:1px solid #ffc1c1}
.ok{background:#e8fff0;border:1px solid #b9f2cd}
.small{color:#556;font-size:13px;margin-top:8px}
pre{background:#0b1020;color:#e8eefc;padding:12px;border-radius:10px;overflow:auto;max-height:420px}
code{background:#f0f2f4;padding:2px 6px;border-radius:6px}
.hr{height:1px;background:#e7ebef;margin:16px 0}
.inline{display:flex;gap:10px;align-items:center;margin-top:12px}
.inline input{width:auto;margin:0}
.kv{background:#f7f9fb;border:1px solid #e7ebef;padding:10px;border-radius:10px}
.kv div{margin:4px 0}
  a{color:#fff;text-decoration:none;transition:color .15s ease}
  a:hover{color:#ffd9b3}
  a:visited{color:#ffe0c2}

</style>
</head>
<body>
<div class="wrap">
  <h1>Test e-mail (SMTP debug)</h1>
  <div class="small">
    Hiermee zie je de <b>gebruikersnaam</b> en <b>gemaskeerd wachtwoord</b>, plus het exacte SMTP antwoord (bijv. 535 5.7.8).
    Gebruik dit tijdelijk; laat debug niet permanent aan staan.
  </div>

  <div class="hr"></div>

  <div class="kv">
    <div><b>SMTP host:</b> <code><?= h($smtpHost) ?></code></div>
    <div><b>SMTP port:</b> <code><?= h((string)$smtpPort) ?></code></div>
    <div><b>SMTP user:</b> <code><?= h($smtpUser !== '' ? $smtpUser : '(leeg)') ?></code></div>
    <div><b>SMTP pass:</b> <code><?= h(maskSecret($smtpPass)) ?></code></div>
    <div><b>From:</b> <code><?= h($fromEmail) ?></code> (<?= h($fromName) ?>)</div>
  </div>

  <?php if ($errors): ?>
    <div class="alert err">
      <?php foreach ($errors as $e): ?>
        <div><?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert ok"><?= h($success) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">

    <div class="row">
      <div>
        <label>Ontvanger</label>
        <input type="email" name="to" value="<?= h($to) ?>" required>
      </div>
      <div>
        <label>Onderwerp</label>
        <input type="text" name="subject" value="<?= h($subject) ?>" required>
      </div>
    </div>

    <label>Bericht</label>
    <textarea name="message" required><?= h($message) ?></textarea>

    <div class="inline">
      <input type="checkbox" id="show_debug" name="show_debug" <?= $showDebug ? 'checked' : '' ?>>
      <label for="show_debug" style="margin:0;font-weight:600;">Toon SMTP debug (PHPMailer SMTPDebug=2)</label>
    </div>

    <button class="btn" type="submit">Verstuur testmail</button>
  </form>

  <?php if ($showDebug && $debugLog !== ''): ?>
    <div class="hr"></div>
    <h3>SMTP debug output</h3>
    <pre><?= h($debugLog) ?></pre>
  <?php endif; ?>
</div>
</body>
</html>