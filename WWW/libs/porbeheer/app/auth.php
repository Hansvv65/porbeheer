<?php
declare(strict_types=1);

/* auth.php - sessie, login, audit en 2FA helpers voor Porbeheer */

if (function_exists('startSecureSession')) {
    return;
}

function sessionIdleTimeoutSeconds(): int
{
    $cfg  = $GLOBALS['config'] ?? [];
    $idle = (int)($cfg['security']['session_idle_timeout'] ?? 0);
    return $idle > 0 ? $idle : 1800;
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $env = defined('APP_ENV') ? APP_ENV : 'production';
    $sessionName = 'PORBEHEERSESSID_' . strtoupper($env);

    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (empty($_SESSION['_init'])) {
        session_regenerate_id(true);
        $_SESSION['_init'] = time();
    }

    $idle = sessionIdleTimeoutSeconds();
    if ($idle > 0) {
        $now  = time();
        $last = (int)($_SESSION['_last_activity'] ?? 0);

        if ($last > 0 && ($now - $last) > $idle) {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    $now - 42000,
                    $p['path'] ?? '/',
                    $p['domain'] ?? '',
                    (bool)($p['secure'] ?? false),
                    (bool)($p['httponly'] ?? true)
                );
            }

            session_destroy();
            header('Location: /login.php?timeout=1');
            exit;
        }

        $_SESSION['_last_activity'] = $now;
    }
}

/**
 * Haal alle rollen van de huidige gebruiker op
 * Gebruikt sessie caching voor performance
 */
function getUserRoles(PDO $pdo): array {
    if (!isLoggedIn()) {
        return [];
    }
    
    $userId = $_SESSION['user']['id'] ?? 0;
    if ($userId <= 0) {
        return [];
    }
    
    // Gebruik sessie cache als die bestaat
    if (isset($_SESSION['user']['all_roles']) && is_array($_SESSION['user']['all_roles'])) {
        return $_SESSION['user']['all_roles'];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Als er geen rollen zijn in user_roles, gebruik de primaire rol als fallback
        if (empty($roles)) {
            $roles = [$_SESSION['user']['role'] ?? 'GEBRUIKER'];
        }
        
        // Sla op in sessie voor caching
        $_SESSION['user']['all_roles'] = $roles;
        
        return $roles;
    } catch (Throwable $e) {
        return [$_SESSION['user']['role'] ?? 'GEBRUIKER'];
    }
}

/**
 * Check of de huidige gebruiker een specifieke rol heeft
 */
function hasRole(PDO $pdo, string $role): bool {
    $roles = getUserRoles($pdo);
    return in_array($role, $roles, true);
}

/**
 * Check of de huidige gebruiker één van de opgegeven rollen heeft
 */
function hasAnyRole(PDO $pdo, array $roles): bool {
    $userRoles = getUserRoles($pdo);
    foreach ($roles as $role) {
        if (in_array($role, $userRoles, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Check of de huidige gebruiker ALLE opgegeven rollen heeft
 */
function hasAllRoles(PDO $pdo, array $roles): bool {
    $userRoles = getUserRoles($pdo);
    foreach ($roles as $role) {
        if (!in_array($role, $userRoles, true)) {
            return false;
        }
    }
    return true;
}

/**
 * Vervang de bestaande requireRole functie
 * Checkt of gebruiker minimaal één van de opgegeven rollen heeft
 */
function requireRole(array $roles): void
{
    requireLogin();
    
    global $pdo;
    
    if (!hasAnyRole($pdo, $roles)) {
        renderNoAccessPage();
    }
}

/* =========================================================================
 * ONDERDEEL-RECHTEN (fase 3)
 * -------------------------------------------------------------------------
 * Vervangt op termijn requireRole()/hasAnyRole() als basis voor
 * paginatoegang. Rollen (uit user_roles, een gebruiker kan er meerdere
 * hebben) zijn herbruikbare sjablonen die vastleggen wat je op elk
 * onderdeel ('bands','planning','sleutels','financien','contacten','admin')
 * mag: niets, 'read' of 'write' (write impliceert read). De effectieve
 * rechten van een gebruiker zijn de optelsom van al zijn rollen: per
 * onderdeel geldt het hoogste niveau dat een van zijn rollen geeft.
 *
 * 'own_only' betekent: dit schrijfrecht geldt alleen voor eigen records
 * (via contact_id / band-koppeling), niet voor alles binnen dat onderdeel.
 * Dit wordt in de betreffende pagina's zelf verder afgedwongen (fase 4),
 * hasSectionAccess() geeft alleen aan dát het om een own-only recht gaat.
 *
 * 'planning' heeft voor iedere ingelogde gebruiker altijd minimaal 'read',
 * los van rollen (de "All"-basis) -- zie getSectionAccess().
 *
 * Bestuurslid-status (is_bestuurslid) blijft daarnaast een los vlaggetje
 * voor de bestuurs-mailinglijst; dat heeft geen invloed op deze rechten.
 *
 * Let op: net als getUserRoles() wordt dit in de sessie gecached. Een
 * rechtenwijziging door een admin wordt pas zichtbaar bij een nieuwe login
 * van de betreffende gebruiker (zelfde bestaande beperking als bij rollen).
 * ========================================================================= */

/**
 * Interne helper: rangorde van een toegangsniveau, voor vergelijking.
 */
function sectionLevelRank(string $level): int
{
    return $level === 'write' ? 2 : 1;
}

/**
 * Geeft de effectieve onderdeel-rechten van de ingelogde gebruiker terug,
 * opgebouwd uit al zijn rollen, bijv.
 * ['financien' => ['level'=>'write','own_only'=>false], 'sleutels' => ['level'=>'read','own_only'=>false]]
 * Onderdelen die nergens in voorkomen staan er niet in (= geen toegang),
 * behalve 'planning' die altijd minimaal 'read' krijgt (de "All"-basis).
 */
function getSectionAccess(PDO $pdo): array
{
    if (!isLoggedIn()) {
        return [];
    }

    if (isset($_SESSION['user']['section_access']) && is_array($_SESSION['user']['section_access'])) {
        return $_SESSION['user']['section_access'];
    }

    $roles = getUserRoles($pdo);
    $access = [];

    if ($roles) {
        try {
            $in = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $pdo->prepare("SELECT section, level, own_only FROM role_section_access WHERE role IN ($in)");
            $stmt->execute($roles);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $section = $row['section'];
                $level = $row['level'];
                $ownOnly = (bool)$row['own_only'];
                $existing = $access[$section] ?? null;
                if ($existing === null || sectionLevelRank($level) > sectionLevelRank($existing['level'])) {
                    $access[$section] = ['level' => $level, 'own_only' => $ownOnly];
                } elseif (sectionLevelRank($level) === sectionLevelRank($existing['level']) && !$ownOnly) {
                    // Bij gelijk niveau wint een niet-own_only recht (breder) over own_only.
                    $access[$section] = ['level' => $level, 'own_only' => false];
                }
            }
        } catch (Throwable $e) {
            $access = [];
        }
    }

    // "All"-basis: iedere ingelogde gebruiker mag de planning altijd minimaal lezen.
    if (!isset($access['planning'])) {
        $access['planning'] = ['level' => 'read', 'own_only' => false];
    }

    $_SESSION['user']['section_access'] = $access;
    return $access;
}

/**
 * Heeft de ingelogde gebruiker minimaal $minLevel op $section?
 */
function hasSectionAccess(PDO $pdo, string $section, string $minLevel = 'read'): bool
{
    $validSections = ['bands', 'planning', 'sleutels', 'financien', 'contacten', 'admin'];
    if (!in_array($section, $validSections, true)) {
        return false;
    }
    $access = getSectionAccess($pdo);
    $entry = $access[$section] ?? null;
    if ($entry === null) {
        return false;
    }
    return sectionLevelRank($entry['level']) >= sectionLevelRank($minLevel);
}

/**
 * Is het schrijfrecht van de ingelogde gebruiker op $section beperkt tot
 * eigen records (own_only)? Geeft false terug als er helemaal geen
 * write-toegang is.
 */
function hasOwnOnlySectionAccess(PDO $pdo, string $section): bool
{
    $access = getSectionAccess($pdo);
    $entry = $access[$section] ?? null;
    if ($entry === null) {
        return false;
    }
    return (bool)$entry['own_only'];
}

/**
 * Vereist minimaal $minLevel op $section, of stuurt een 403.
 * Gebruik: requireSectionAccess('financien', 'write');
 */
function requireSectionAccess(string $section, string $minLevel = 'read'): void
{
    requireLogin();

    global $pdo;

    if (!hasSectionAccess($pdo, $section, $minLevel)) {
        renderNoAccessPage($section);
    }
}

/**
 * Nette, gestylede 403-pagina i.p.v. een kale exit()-tekst. Sluit het
 * request af (exit) net als de oude versie deed.
 */
function renderNoAccessPage(?string $section = null): void
{
    http_response_code(403);

    $labels = [
        'bands'     => 'Bands',
        'planning'  => 'Planning',
        'sleutels'  => 'Sleutels & Kasten',
        'financien' => 'Financiën',
        'contacten' => 'Contacten',
        'admin'     => 'Admin',
    ];
    $sectionLabel = $section !== null ? ($labels[$section] ?? $section) : null;

    $sectionHomes = [
        'bands'     => '/admin/bands.php',
        'planning'  => '/admin/planning.php',
        'sleutels'  => '/admin/keys.php',
        'financien' => '/admin/finance.php',
        'contacten' => '/admin/contacts.php',
        'admin'     => '/admin/beheer.php',
    ];
    $backUrl = ($section !== null && isset($sectionHomes[$section])) ? $sectionHomes[$section] : '/admin/dashboard.php';
    $backLabel = ($section !== null && isset($sectionHomes[$section])) ? 'Terug naar ' . $sectionLabel : 'Terug naar dashboard';

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Geen toegang - Porbeheer</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
.noaccess-panel{max-width:440px;margin:0 auto;text-align:center;padding:34px 28px;}
.noaccess-icon{font-size:40px;line-height:1;margin-bottom:6px;}
.noaccess-panel h1{margin:0 0 8px 0;font-size:20px;}
.noaccess-panel p{color:var(--muted);margin:0 0 20px 0;font-size:14px;}
.noaccess-panel .btn{
  display:inline-block;padding:10px 18px;border-radius:12px;
  border:1px solid rgba(255,255,255,.22);
  background:linear-gradient(180deg, var(--glass), var(--glass2));
  color:#fff;text-decoration:none;font-weight:800;
}
.noaccess-panel .btn:hover{border-color:rgba(255,255,255,.38)}
</style>
</head>
<body>
<div class="backdrop">
  <div class="panel noaccess-panel">
    <div class="noaccess-icon">🔒</div>
    <h1>Geen toegang</h1>
    <p><?php if ($sectionLabel !== null): ?>
      Je hebt geen rechten voor het onderdeel <strong><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></strong>.
    <?php else: ?>
      Je hebt niet de juiste rechten voor deze pagina.
    <?php endif; ?>
      Neem contact op met een beheerder als je denkt dat dit niet klopt.</p>
    <a class="btn" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?></a>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

/**
 * Ververst de sessie-cache van de onderdeel-rechten (bijv. na een edit door
 * een admin binnenin dezelfde sessie, of bij login). Geen effect op andere,
 * al lopende sessies van diezelfde gebruiker -- die zien de wijziging pas
 * bij hun volgende login (zelfde bestaande beperking als all_roles).
 */
function refreshSectionAccessCache(): void
{
    unset($_SESSION['user']['section_access']);
}

/**
 * Is de ingelogde gebruiker gekoppeld aan een contact (dus een
 * "contactaccount" i.p.v. een personeelsaccount)? Personeelsleden die
 * toevallig ook contact zijn, hebben dit veld ook gevuld -- dat sluit
 * onderdeel-rechten niet uit.
 */
function currentContactId(): ?int
{
    $cid = $_SESSION['user']['contact_id'] ?? null;
    return $cid !== null ? (int)$cid : null;
}

/**
 * Synchroniseer de primaire rol in users tabel op basis van de hoogste prioriteit
 */
function syncPrimaryRole(PDO $pdo, int $userId): void {
    $rolePriority = ['GEBRUIKER' => 1, 'FINANCIEEL' => 2, 'BEHEER' => 3, 'BESTUURSLID' => 4, 'ADMIN' => 5, 'BANDLID' => 0];
    
    $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($roles)) {
        return;
    }
    
    // Bepaal de rol met de hoogste prioriteit
    $primaryRole = 'GEBRUIKER';
    $highestPriority = 0;
    foreach ($roles as $role) {
        $priority = $rolePriority[$role] ?? 0;
        if ($priority > $highestPriority) {
            $highestPriority = $priority;
            $primaryRole = $role;
        }
    }
    
    // Update de users tabel
    $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$primaryRole, $userId]);
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function requireCsrf(?string $token): void
{
    $ok = is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], $token);

    if (!$ok) {
        // Meestal is dit geen aanval maar een verlopen sessie (het formulier
        // stond te lang open, zie startSecureSession()'s idle-timeout) --
        // stuur terug naar dezelfde pagina met een vlag, zodat die een
        // vriendelijke melding kan tonen i.p.v. een kale foutpagina.
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path === '') $path = '/';
        header('Location: ' . $path . '?csrf_expired=1');
        exit;
    }
}

function currentUser(): array
{
    return $_SESSION['user'] ?? [];
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user']['id']);
}

function currentPath(): string
{
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return $path !== '' ? $path : '/';
}

function mustSetup2fa(): bool
{
    return isLoggedIn() && !empty($_SESSION['user']['must_setup_2fa']);
}

function enforce2faSetupIfNeeded(): void
{
    if (!mustSetup2fa()) {
        return;
    }

    $path = currentPath();
    $allowed = [
        '/admin/setup-2fa.php',
        '/logout.php',
    ];

    if (!in_array($path, $allowed, true)) {
        header('Location: /admin/setup-2fa.php');
        exit;
    }
}

/**
 * Houdt de oude 'active' kolom gelijk aan 'status' (en deleted_at). Gebruikt
 * door zowel users_detail.php als quick_approve.php -- centraal gehouden om
 * niet weer twee losse kopieën te krijgen die uit de pas kunnen lopen.
 */
function syncActiveWithStatus(PDO $pdo, int $id): void {
    $pdo->prepare("
        UPDATE users
        SET active = CASE
            WHEN deleted_at IS NOT NULL THEN 0
            WHEN status = 'ACTIVE' THEN 1
            ELSE 0
        END
        WHERE id = ?
    ")->execute([$id]);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }

    enforce2faSetupIfNeeded();
}

function clientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function auditLog(PDO $pdo, string $eventType, string $eventName, array $details = []): void
{
    try {
        $uid = (int)($_SESSION['user']['id'] ?? 0);
        if ($uid <= 0 && isset($details['user_id'])) {
            $uid = (int)$details['user_id'];
        }

        $username = (string)($_SESSION['user']['username'] ?? '');
        $role = (string)($_SESSION['user']['role'] ?? '');
        $method = substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10);
        $path   = substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 255);
        $ip     = clientIp();
        $ua     = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $details['app_env'] = defined('APP_ENV') ? APP_ENV : null;
        $details['script_version'] = defined('SCRIPT_VERSION') ? SCRIPT_VERSION : null;

        $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $st = $pdo->prepare(
            'INSERT INTO audit_log
              (user_id, username, role, event_type, event_name, method, path, details, ip, user_agent, created_at)
             VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $st->execute([
            $uid ?: null,
            $username !== '' ? $username : null,
            $role !== '' ? $role : null,
            $eventType,
            $eventName,
            $method !== '' ? $method : null,
            $path !== '' ? $path : null,
            $json,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // audit logging mag de app nooit breken
    }
}

function normalizeThemeVariant(?string $variant): string
{
    $variant = strtolower(trim((string)$variant));
    return in_array($variant, ['a', 'b', 'c'], true) ? $variant : 'a';
}

function currentThemeVariant(PDO $pdo): string
{
    if (!empty($_SESSION['user']['theme_variant'])) {
        return normalizeThemeVariant((string)$_SESSION['user']['theme_variant']);
    }

    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) {
        return 'a';
    }

    try {
        $st = $pdo->prepare("SELECT theme_variant FROM users WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $variant = normalizeThemeVariant((string)$st->fetchColumn());
        $_SESSION['user']['theme_variant'] = $variant;
        return $variant;
    } catch (Throwable $e) {
        return 'a';
    }
}

function themeImage(string $baseName, PDO $pdo): string
{
    $baseName = trim($baseName);
    if ($baseName === '') {
        $baseName = 'admin';
    }

    return '/assets/images/' . $baseName . '-' . currentThemeVariant($pdo) . '.png';
}

function refreshSessionUser(PDO $pdo, int $userId): void
{
    $st = $pdo->prepare("
        SELECT id, username, first_name, role, contact_id, is_bestuurslid, theme_variant, totp_enabled
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($u) {
        // Haal alle rollen op uit user_roles
        $rolesStmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
        $rolesStmt->execute([$userId]);
        $allRoles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($allRoles)) {
            $allRoles = [(string)($u['role'] ?? 'GEBRUIKER')];
        }
        
        $_SESSION['user'] = [
            'id'             => (int)$u['id'],
            'username'       => (string)$u['username'],
            'first_name'     => (string)($u['first_name'] ?? ''),
            'role'           => (string)($u['role'] ?? 'GEBRUIKER'),
            'all_roles'      => $allRoles,
            'contact_id'     => isset($u['contact_id']) && $u['contact_id'] !== null ? (int)$u['contact_id'] : null,
            'is_bestuurslid' => !empty($u['is_bestuurslid']),
            'theme_variant'  => normalizeThemeVariant((string)($u['theme_variant'] ?? 'a')),
            'must_setup_2fa' => empty($u['totp_enabled']),
        ];
    }
}

function loadAuthUserByUsername(PDO $pdo, string $username): ?array
{
    $st = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $st->execute([trim($username)]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

function loadAuthUserByEmail(PDO $pdo, string $email): ?array
{
    $email = strtolower(trim($email));
    $st = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

function loadAuthUserById(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

function completeLoginSession(PDO $pdo, array $u, bool $mustSetup2fa): void
{
    $uid = (int)$u['id'];
    
    // Haal alle rollen op uit user_roles
    $rolesStmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
    $rolesStmt->execute([$uid]);
    $allRoles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($allRoles)) {
        // Fallback: gebruik de primaire rol uit users tabel
        $allRoles = [(string)($u['role'] ?? 'GEBRUIKER')];
    }
    
    $pdo->prepare("
        UPDATE users
        SET failed_attempts = 0,
            locked_until = NULL,
            last_login_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$uid]);
    
    $_SESSION['user'] = [
        'id'             => $uid,
        'username'       => (string)$u['username'],
        'first_name'     => (string)($u['first_name'] ?? ''),
        'role'           => (string)($u['role'] ?? 'GEBRUIKER'),
        'all_roles'      => $allRoles,
        'contact_id'     => isset($u['contact_id']) && $u['contact_id'] !== null ? (int)$u['contact_id'] : null,
        'is_bestuurslid' => !empty($u['is_bestuurslid']),
        'theme_variant'  => normalizeThemeVariant((string)($u['theme_variant'] ?? 'a')),
        'must_setup_2fa' => $mustSetup2fa,
    ];
    
    unset($_SESSION['pending_2fa']);
    session_regenerate_id(true);
}

function clearPending2fa(): void
{
    unset($_SESSION['pending_2fa']);
}

function hasPending2fa(): bool
{
    return !empty($_SESSION['pending_2fa']['user_id']);
}

function beginPending2fa(array $u): void
{
    $_SESSION['pending_2fa'] = [
        'user_id'    => (int)$u['id'],
        'username'   => (string)$u['username'],
        'started_at' => time(),
    ];
}

function pending2faUserId(): int
{
    return (int)($_SESSION['pending_2fa']['user_id'] ?? 0);
}

function loadPending2faUser(PDO $pdo): ?array
{
    $uid = pending2faUserId();
    if ($uid <= 0) {
        return null;
    }

    return loadAuthUserById($pdo, $uid);
}

function buildTwoFactorAuth(): \RobThree\Auth\TwoFactorAuth
{
    static $tfa = null;

    if ($tfa instanceof \RobThree\Auth\TwoFactorAuth) {
        return $tfa;
    }

    // Anonieme SVG QR-provider die voldoet aan de IQRCodeProvider-interface
    $qrProvider = new class implements \RobThree\Auth\Providers\Qr\IQRCodeProvider {
        public function getQRCodeImage(string $qrtext, int $size = 200): string
        {
            if (!class_exists('BaconQrCode\Renderer\Image\SvgImageBackEnd')) {
                throw new \RuntimeException('BaconQrCode SVG backend ontbreekt');
            }

            $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size, 0),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            );

            $writer = new \BaconQrCode\Writer($renderer);
            return $writer->writeString($qrtext);
        }

        public function getMimeType(): string
        {
            return 'image/svg+xml';
        }
    };

    $tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, 'Porbeheer');
    return $tfa;
}

function attemptPrimaryLogin(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    $maxAttempts = 5;
    $lockMinutes = 15;

    if ($username === '' || $password === '') {
        return ['ok' => false, 'code' => 'INVALID_INPUT'];
    }
    $u = loadAuthUserByEmail($pdo, $username);
    
    if (!$u || empty($u['password_hash'])) {
        usleep(120000);
        return ['ok' => false, 'code' => 'BADCREDS'];
    }

    $uid = (int)$u['id'];

    if (!empty($u['deleted_at'])) {
        return ['ok' => false, 'code' => 'DELETED', 'user_id' => $uid];
    }

    if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) {
        return [
            'ok' => false,
            'code' => 'LOCKED',
            'user_id' => $uid,
            'locked_until' => (string)$u['locked_until'],
        ];
    }

    if (!password_verify($password, (string)$u['password_hash'])) {
        $attempts = (int)($u['failed_attempts'] ?? 0) + 1;

        if ($attempts >= $maxAttempts) {
            $lockedUntil = (new DateTime("+{$lockMinutes} minutes"))->format('Y-m-d H:i:s');

            $pdo->prepare("
                UPDATE users
                SET failed_attempts = ?, locked_until = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$attempts, $lockedUntil, $uid]);

            return [
                'ok' => false,
                'code' => 'LOCKED',
                'user_id' => $uid,
                'locked_until' => $lockedUntil,
            ];
        }

        $pdo->prepare("
            UPDATE users
            SET failed_attempts = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$attempts, $uid]);

        return ['ok' => false, 'code' => 'BADPWD', 'user_id' => $uid];
    }

    $status = (string)($u['status'] ?? 'PENDING');
    if ($status === 'BLOCKED') {
        return ['ok' => false, 'code' => 'BLOCKED', 'user_id' => $uid];
    }

    if ($status !== 'ACTIVE' || empty($u['approved_at'])) {
        return ['ok' => false, 'code' => 'PENDING_APPROVAL', 'user_id' => $uid];
    }

    $hasEmail = trim((string)($u['email'] ?? '')) !== '';
    if ($hasEmail && empty($u['email_verified_at'])) {
        return ['ok' => false, 'code' => 'PENDING_EMAIL', 'user_id' => $uid];
    }

    $totpEnabled = !empty($u['totp_enabled']) && !empty($u['totp_secret']);

    if ($totpEnabled) {
        beginPending2fa($u);
        return ['ok' => false, 'code' => 'NEEDS_2FA', 'user_id' => $uid];
    }

    completeLoginSession($pdo, $u, true);
    return ['ok' => true, 'code' => 'REQUIRE_2FA_SETUP', 'user_id' => $uid];
}

function verifyPending2faCode(PDO $pdo, string $code): array
{
    $code = preg_replace('/\D+/', '', $code ?? '');
    $u = loadPending2faUser($pdo);

    if (!$u) {
        clearPending2fa();
        return ['ok' => false, 'code' => 'NO_PENDING_2FA'];
    }

    $secret = (string)($u['totp_secret'] ?? '');
    if ($secret === '' || empty($u['totp_enabled'])) {
        clearPending2fa();
        return ['ok' => false, 'code' => 'NO_2FA_ON_ACCOUNT'];
    }

    if ($code === '' || strlen($code) !== 6) {
        return ['ok' => false, 'code' => 'BAD_2FA_FORMAT', 'user_id' => (int)$u['id']];
    }

    $tfa = buildTwoFactorAuth();
    $ok = $tfa->verifyCode($secret, $code, 2);

    if (!$ok) {
        return ['ok' => false, 'code' => 'BAD_2FA_CODE', 'user_id' => (int)$u['id']];
    }

    completeLoginSession($pdo, $u, false);
    return ['ok' => true, 'code' => 'LOGIN_OK', 'user_id' => (int)$u['id']];
}

function logout(PDO $pdo): void
{
    auditLog($pdo, 'LOGOUT', 'auth/logout');

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}