<?php
declare(strict_types=1);

/*
mail.php - wrapper voor PHPMailer   
$base = __DIR__ . '/lib/PHPMailer/src/';
if (!is_file($base . 'PHPMailer.php')) {
    throw new RuntimeException('PHPMailer ontbreekt in app/lib/PHPMailer/src/');
}
require_once $base . 'Exception.php';
require_once $base . 'PHPMailer.php';
require_once $base . 'SMTP.php';

*/

require_once __DIR__ . '/bootstrap.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * @param array<int, string|array{path:string,name?:string,type?:string}> $attachments
 */
function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): void
{
    $config = $GLOBALS['config'] ?? require __DIR__ . '/config.php';
    $mailConfig = $config['mail'] ?? [];

    $smtpHost  = trim((string)($mailConfig['smtp_host'] ?? ''));
    $smtpPort  = (int)($mailConfig['smtp_port'] ?? 587);
    $smtpUser  = trim((string)($mailConfig['smtp_user'] ?? ''));
    $smtpPass  = trim((string)($mailConfig['smtp_pass'] ?? ''));
    $fromEmail = trim((string)($mailConfig['from_email'] ?? $smtpUser));
    $fromName  = (string)($mailConfig['from_name'] ?? 'Administratie PorBeheer');
    $debug     = (int)($mailConfig['debug'] ?? 0);

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '' || $fromEmail === '') {
        throw new RuntimeException('SMTP config is incompleet voor omgeving: ' . (defined('APP_ENV') ? APP_ENV : 'unknown'));
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->Port       = $smtpPort;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = ($smtpPort === 465)
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        if ($debug > 0) {
            $mail->SMTPDebug   = $debug;
            $mail->Debugoutput = 'error_log';
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);

        // Bannerplaatje in de mailopmaak (zie mail.php::mailLayout()) wordt
        // ingesloten i.p.v. als externe URL geladen -- reist gewoon mee met
        // de mail, geen afhankelijkheid van een publiek bereikbare server.
        if (str_contains($htmlBody, 'cid:por-banner-header')) {
            $bannerPath = PUBLIC_ROOT . '/assets/images/POR-logo-email-header-donkere-banner.png';
            if (is_file($bannerPath) && is_readable($bannerPath)) {
                $mail->addEmbeddedImage($bannerPath, 'por-banner-header', 'banner.png', 'base64', 'image/png');
                if (function_exists('auditLog') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                    auditLog($GLOBALS['pdo'], 'MAIL', 'mail/banner_embedded', ['path' => $bannerPath]);
                }
            } elseif (function_exists('auditLog') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                auditLog($GLOBALS['pdo'], 'MAIL', 'mail/banner_not_found', ['path' => $bannerPath]);
            }
        } elseif (function_exists('auditLog') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            auditLog($GLOBALS['pdo'], 'MAIL', 'mail/banner_cid_not_in_body', ['subject' => mb_substr($subject, 0, 190)]);
        }

        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : trim(strip_tags($htmlBody));

        foreach ($attachments as $att) {
            $path = null;
            $name = null;
            $type = '';

            if (is_string($att)) {
                $path = $att;
            } elseif (is_array($att) && !empty($att['path'])) {
                $path = (string)$att['path'];
                $name = isset($att['name']) ? (string)$att['name'] : null;
                $type = isset($att['type']) ? (string)$att['type'] : '';
            }

            if ($path !== null && is_file($path) && is_readable($path)) {
                if ($name !== null && $name !== '') {
                    $mail->addAttachment($path, $name, 'base64', $type ?: '');
                } else {
                    $mail->addAttachment($path);
                }
            }
        }

        $mail->send();

        if (function_exists('auditLog') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            auditLog($GLOBALS['pdo'], 'MAIL', 'mail/send', [
                'to' => $to,
                'subject' => mb_substr($subject, 0, 190),
                'attachment_count' => count($attachments),
                'mailer' => 'PHPMailer',
                'status' => 'SUCCESS',
                'app_env' => defined('APP_ENV') ? APP_ENV : null,
                'script_version' => defined('SCRIPT_VERSION') ? SCRIPT_VERSION : null,
            ]);
        }
    } catch (Exception $e) {
        if (function_exists('auditLog') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            auditLog($GLOBALS['pdo'], 'MAIL', 'mail/send_failed', [
                'to' => $to,
                'subject' => mb_substr($subject, 0, 190),
                'attachment_count' => count($attachments),
                'mailer' => 'PHPMailer',
                'status' => 'FAILED',
                'error' => mb_substr((string)$mail->ErrorInfo, 0, 500),
                'app_env' => defined('APP_ENV') ? APP_ENV : null,
                'script_version' => defined('SCRIPT_VERSION') ? SCRIPT_VERSION : null,
            ]);
        }

        throw new RuntimeException('Mail verzenden mislukt: ' . $mail->ErrorInfo, 0, $e);
    }
}

/**
 * Backwards compatible alias.
 * @param array<int, string|array{path:string,name?:string,type?:string}> $attachments
 */
function sendMail(string $to, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): void
{
    sendEmail($to, $subject, $htmlBody, $textBody, $attachments);
}

/**
 * Gedeelde opmaak voor alle uitgaande Porbeheer-mails, met het donkere
 * bannerbeeld bovenaan. Vervangt de losse mailLayout()-kopieën die eerder
 * apart in register.php, users_detail.php en forgot.php stonden.
 */
function mailLayout(string $title, string $intro, string $contentHtml): string
{
    return '
    <div style="margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#243447;">
      <div style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #d9e2ec;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.08);">
        <div style="background:#0b0b0b;">
          <img src="cid:por-banner-header" alt="POR - Popoefenruimte Zevenaar" width="680" style="display:block;width:100%;max-width:680px;height:auto;">
        </div>
        <div style="padding:28px 24px;">
          <h2 style="margin:0 0 12px 0;font-size:22px;color:#1f3b57;">' . h($title) . '</h2>
          <p style="margin:0 0 18px 0;font-size:15px;line-height:1.6;color:#425466;">' . h($intro) . '</p>
          <div style="font-size:15px;line-height:1.7;color:#243447;">' . $contentHtml . '</div>
        </div>
        <div style="padding:16px 24px;background:#f8fbff;border-top:1px solid #d9e2ec;font-size:12px;color:#6b7c93;">
          Dit is een automatisch bericht van Porbeheer.
        </div>
      </div>
    </div>';
}

