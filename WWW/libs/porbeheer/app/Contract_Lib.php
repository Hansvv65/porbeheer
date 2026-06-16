<?php
declare(strict_types=1);

/*
 * app/contract_lib.php
 *
 * Gedeelde helpers voor het beheer van (getekende) sleutelcontracten:
 *   - veilige opslag van bestanden BUITEN de webroot
 *   - eenmalige upload-tokens (QR / code) met vervaltijd
 *   - telefoonfoto's verkleinen en samenvoegen tot één PDF
 *   - QR-codes genereren (BaconQrCode, al aanwezig via vendor)
 *   - registratie van documenten in key_contract_documents
 *
 * Vereist dat bootstrap.php al geladen is (PROJECT_ROOT, $pdo, vendor autoload).
 * Plaats dit bestand in: /var/www/libs/porbeheer/app/contract_lib.php
 */

if (defined('CONTRACT_LIB_LOADED')) {
    return;
}
define('CONTRACT_LIB_LOADED', true);

// =====================================================================
// OPSLAG
// =====================================================================

/**
 * Basismap voor contractopslag. Standaard buiten de webroot.
 * Te overschrijven via config['storage']['contracts_dir'] of env POR_STORAGE_DIR.
 */
function contractStorageBaseDir(): string
{
    $cfg  = $GLOBALS['config'] ?? [];
    $base = trim((string)($cfg['storage']['contracts_dir'] ?? ''));

    if ($base === '') {
        $env = getenv('POR_STORAGE_DIR');
        if (is_string($env) && $env !== '') {
            $base = rtrim($env, '/') . '/contracts';
        }
    }
    if ($base === '') {
        // PROJECT_ROOT = /var/www/libs/porbeheer  -> staat al buiten de webroot
        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__);
        $base = $root . '/storage/contracts';
    }
    return rtrim($base, '/');
}

/** Maak een map aan (recursief) met deny-vangnet, mocht hij ooit in de webroot belanden. */
function contractEnsureDir(string $dir): void
{
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Kan opslagmap niet aanmaken: ' . $dir);
        }
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    $idx = $dir . '/index.html';
    if (!is_file($idx)) {
        @file_put_contents($idx, '');
    }
}

/** Map voor één contract; wordt aangemaakt als die nog niet bestaat. */
function contractDir(int $keyContractId): string
{
    $d = contractStorageBaseDir() . '/' . $keyContractId;
    contractEnsureDir($d);
    return $d;
}

/**
 * Schrijf bytes weg onder een willekeurige bestandsnaam.
 * @return array{relative_path:string,absolute_path:string,size:int,sha256:string,mime:string}
 */
function contractStoreBytes(int $keyContractId, string $bytes, string $ext, string $mime): array
{
    contractEnsureDir(contractStorageBaseDir());
    $dir  = contractDir($keyContractId);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . ltrim($ext, '.');
    $abs  = $dir . '/' . $name;

    if (file_put_contents($abs, $bytes) === false) {
        throw new RuntimeException('Wegschrijven van bestand mislukt.');
    }
    @chmod($abs, 0640);

    return [
        'relative_path' => $keyContractId . '/' . $name,
        'absolute_path' => $abs,
        'size'          => strlen($bytes),
        'sha256'        => hash('sha256', $bytes),
        'mime'          => $mime,
    ];
}

/** Absoluut pad uit een opgeslagen relatief pad, met bescherming tegen path traversal. */
function contractAbsolutePath(string $relativePath): string
{
    $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($rel === '' || str_contains($rel, '..')) {
        throw new RuntimeException('Ongeldig bestandspad.');
    }
    return contractStorageBaseDir() . '/' . $rel;
}

// =====================================================================
// UPLOAD-TOKENS (QR / code)
// =====================================================================

/**
 * Maak een nieuw eenmalig token. Bestaande ongebruikte, niet-verlopen tokens
 * voor hetzelfde contract worden hierbij ongeldig gemaakt.
 * @return array{id:int,token:string,short_code:string,expires_at:string,key_contract_id:int}
 */
function contractCreateUploadToken(PDO $pdo, int $keyContractId, int $ttlMinutes = 30, ?int $userId = null): array
{
    $pdo->prepare(
        "UPDATE key_contract_upload_tokens
            SET expires_at = NOW()
          WHERE key_contract_id = ? AND used_at IS NULL AND expires_at > NOW()"
    )->execute([$keyContractId]);

    $token   = bin2hex(random_bytes(32)); // 64 hex tekens
    $short   = contractGenerateShortCode();
    $expires = (new DateTime("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');
    $ip      = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $pdo->prepare(
        "INSERT INTO key_contract_upload_tokens
            (key_contract_id, token, short_code, expires_at, created_at, created_by_user_id, ip)
         VALUES (?, ?, ?, ?, NOW(), ?, ?)"
    )->execute([$keyContractId, $token, $short, $expires, $userId, $ip !== '' ? $ip : null]);

    return [
        'id'              => (int)$pdo->lastInsertId(),
        'token'           => $token,
        'short_code'      => $short,
        'expires_at'      => $expires,
        'key_contract_id' => $keyContractId,
    ];
}

/** Korte, leesbare code zonder makkelijk te verwarren tekens (geen 0/O/1/I). */
function contractGenerateShortCode(int $len = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** Het huidige geldige token voor een contract, of null. */
function contractActiveUploadToken(PDO $pdo, int $keyContractId): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM key_contract_upload_tokens
          WHERE key_contract_id = ? AND used_at IS NULL AND expires_at > NOW()
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$keyContractId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Geef het actieve token terug, of maak er een nieuwe als die er niet is. */
function contractEnsureUploadToken(PDO $pdo, int $keyContractId, int $ttlMinutes = 30, ?int $userId = null): array
{
    return contractActiveUploadToken($pdo, $keyContractId)
        ?? contractCreateUploadToken($pdo, $keyContractId, $ttlMinutes, $userId);
}

/**
 * Valideer een token uit de URL. Geeft contractgegevens terug of null
 * als het token onbekend, verlopen of al gebruikt is.
 */
function contractValidateUploadToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare(
        "SELECT t.*, kc.key_id, kc.contract_number, k.key_code
           FROM key_contract_upload_tokens t
           JOIN key_contracts kc ON kc.id = t.key_contract_id
           JOIN `keys` k         ON k.id  = kc.key_id
          WHERE t.token = ? AND t.used_at IS NULL AND t.expires_at > NOW()
          LIMIT 1"
    );
    $st->execute([$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Hetzelfde maar op basis van de korte code (typvariant). */
function contractValidateShortCode(PDO $pdo, string $code): ?array
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9]{6,12}$/', $code)) {
        return null;
    }
    $st = $pdo->prepare(
        "SELECT t.*, kc.key_id, kc.contract_number, k.key_code
           FROM key_contract_upload_tokens t
           JOIN key_contracts kc ON kc.id = t.key_contract_id
           JOIN `keys` k         ON k.id  = kc.key_id
          WHERE t.short_code = ? AND t.used_at IS NULL AND t.expires_at > NOW()
          ORDER BY t.id DESC LIMIT 1"
    );
    $st->execute([$code]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Markeer een token als gebruikt (eenmalig). */
function contractConsumeUploadToken(PDO $pdo, int $tokenId): void
{
    $pdo->prepare("UPDATE key_contract_upload_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL")
        ->execute([$tokenId]);
}

/** Volledige upload-URL die in de QR-code komt. */
function contractUploadUrl(string $token): string
{
    $base = defined('APP_URL') ? APP_URL : '';
    return $base . '/contract_upload.php?token=' . $token;
}

// =====================================================================
// AFBEELDINGEN -> PDF
// =====================================================================

/**
 * Verklein een afbeelding (Imagick > GD) en geef JPEG-bytes terug.
 * Roteert volgens EXIF wanneer Imagick beschikbaar is.
 */
function contractDownscaleImage(string $bytes, int $maxDim = 2000, int $jpegQuality = 82): string
{
    if (extension_loaded('imagick')) {
        try {
            $im = new \Imagick();
            $im->readImageBlob($bytes);
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if (max($w, $h) > $maxDim) {
                if ($w >= $h) {
                    $im->resizeImage($maxDim, 0, \Imagick::FILTER_LANCZOS, 1);
                } else {
                    $im->resizeImage(0, $maxDim, \Imagick::FILTER_LANCZOS, 1);
                }
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($jpegQuality);
            $im->stripImage();
            $out = $im->getImageBlob();
            $im->clear();
            $im->destroy();
            return $out;
        } catch (Throwable $e) {
            // val terug op GD
        }
    }

    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring($bytes);
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1.0, $maxDim / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagejpeg($dst, null, $jpegQuality);
            $out = (string)ob_get_clean();
            imagedestroy($src);
            imagedestroy($dst);
            return $out;
        }
    }

    // geen beeldbibliotheek beschikbaar -> ongewijzigd teruggeven
    return $bytes;
}

/** Of we afbeeldingen überhaupt kunnen verwerken op deze server. */
function contractCanProcessImages(): bool
{
    return extension_loaded('imagick') || function_exists('imagecreatefromstring');
}

/**
 * Voeg meerdere afbeeldingen (bytes) samen tot één A4-PDF (1 foto per pagina).
 * Geeft de PDF-bytes terug.
 */
function contractImagesToPdf(array $imageBlobs): string
{
    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('DomPDF is niet beschikbaar.');
    }

    $pages = '';
    $i = 0;
    foreach ($imageBlobs as $blob) {
        if (!is_string($blob) || $blob === '') {
            continue;
        }
        $jpeg = contractDownscaleImage($blob);
        $b64  = base64_encode($jpeg);
        $brk  = $i > 0 ? 'page-break-before:always;' : '';
        $pages .= '<div style="' . $brk . 'text-align:center;">'
                . '<img src="data:image/jpeg;base64,' . $b64 . '" style="max-width:100%;max-height:26cm;">'
                . '</div>';
        $i++;
    }
    if ($i === 0) {
        throw new RuntimeException('Geen geldige afbeeldingen om te verwerken.');
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
          . '<style>@page{margin:1cm;}body{margin:0;}</style></head><body>'
          . $pages . '</body></html>';

    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'Helvetica');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

// =====================================================================
// QR-CODE (BaconQrCode)
// =====================================================================

/** QR als inline SVG-string (werkt altijd, geen Imagick nodig). Ideaal in de browser. */
function contractQrSvg(string $text, int $size = 220): string
{
    $renderer = new \BaconQrCode\Renderer\ImageRenderer(
        new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size, 1),
        new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
    );
    return (new \BaconQrCode\Writer($renderer))->writeString($text);
}

/** QR als PNG data-URI; null als Imagick ontbreekt (gebruik dan SVG). Handig in DomPDF. */
function contractQrPngDataUri(string $text, int $size = 220): ?string
{
    if (!extension_loaded('imagick')) {
        return null;
    }
    try {
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size, 1),
            new \BaconQrCode\Renderer\Image\ImagickImageBackEnd()
        );
        $png = (new \BaconQrCode\Writer($renderer))->writeString($text);
        return 'data:image/png;base64,' . base64_encode($png);
    } catch (Throwable $e) {
        return null;
    }
}

// =====================================================================
// DOCUMENTEN (key_contract_documents)
// =====================================================================

/**
 * Registreer een opgeslagen bestand bij een contract.
 * Zet eerdere documenten van dezelfde soort op is_current = 0.
 *
 * @param array $stored  Resultaat van contractStoreBytes()
 */
function contractAddDocument(
    PDO $pdo,
    int $keyContractId,
    array $stored,
    string $kind,            // 'GENERATED' | 'SIGNED'
    string $source,          // 'ADMIN_UPLOAD' | 'QR_UPLOAD' | 'GENERATED'
    ?int $userId = null,
    ?int $tokenId = null,
    ?string $originalName = null,
    ?int $pageCount = null
): int {
    $pdo->prepare(
        "UPDATE key_contract_documents
            SET is_current = 0
          WHERE key_contract_id = ? AND kind = ? AND deleted_at IS NULL"
    )->execute([$keyContractId, $kind]);

    $st = $pdo->prepare(
        "INSERT INTO key_contract_documents
            (key_contract_id, kind, source, original_name, stored_path, mime_type,
             size_bytes, sha256, page_count, uploaded_at, uploaded_by_user_id,
             upload_token_id, is_current)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 1)"
    );
    $st->execute([
        $keyContractId, $kind, $source, $originalName,
        $stored['relative_path'], $stored['mime'], $stored['size'],
        $stored['sha256'] ?? null, $pageCount, $userId, $tokenId,
    ]);
    $docId = (int)$pdo->lastInsertId();

    // Backwards-compat pointer in key_contracts
    if ($kind === 'SIGNED') {
        $pdo->prepare("UPDATE key_contracts SET signed_contract_path = ? WHERE id = ?")
            ->execute([$stored['relative_path'], $keyContractId]);
    }
    return $docId;
}

/** Huidig getekend document voor een contract, of null. */
function contractCurrentSignedDocument(PDO $pdo, int $keyContractId): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM key_contract_documents
          WHERE key_contract_id = ? AND kind = 'SIGNED' AND deleted_at IS NULL AND is_current = 1
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$keyContractId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Stuur een opgeslagen documentbestand naar de browser (met auth aan de aanroepkant!).
 * Wist eventuele outputbuffers en beëindigt het script.
 */
function contractStreamDocument(array $doc, bool $inline = true): void
{
    $abs = contractAbsolutePath((string)$doc['stored_path']);
    if (!is_file($abs)) {
        http_response_code(404);
        exit('Bestand niet gevonden.');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $mime = (string)($doc['mime_type'] ?? 'application/octet-stream');
    $name = (string)($doc['original_name'] ?? basename($abs));
    $disp = $inline ? 'inline' : 'attachment';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disp . '; filename="' . addslashes($name) . '"');
    header('Content-Length: ' . (string)filesize($abs));
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}
