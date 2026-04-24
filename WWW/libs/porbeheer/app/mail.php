<?php
declare(strict_types=1);

/*
$base = __DIR__ . '/lib/PHPMailer/src/';
if (!is_file($base . 'PHPMailer.php')) {
    throw new RuntimeException('PHPMailer ontbreekt in app/lib/PHPMailer/src/');
}
require_once $base . 'Exception.php';
require_once $base . 'PHPMailer.php';
require_once $base . 'SMTP.php';

*/

require_once '/var/www/libs/porbeheer/app/bootstrap.php';
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
