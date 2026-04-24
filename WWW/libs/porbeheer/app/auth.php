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
        http_response_code(403);
        exit('Geen toegang. Je hebt niet de juiste rechten.');
    }
}

/**
 * Synchroniseer de primaire rol in users tabel op basis van de hoogste prioriteit
 */
function syncPrimaryRole(PDO $pdo, int $userId): void {
    $rolePriority = ['GEBRUIKER' => 1, 'FINANCIEEL' => 2, 'BEHEER' => 3, 'BESTUURSLID' => 4, 'ADMIN' => 5];
    
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
        http_response_code(400);
        exit('CSRF fout.');
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
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
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
        SELECT id, username, role, theme_variant, totp_enabled
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
            'role'           => (string)($u['role'] ?? 'GEBRUIKER'),
            'all_roles'      => $allRoles,
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
        'role'           => (string)($u['role'] ?? 'GEBRUIKER'),
        'all_roles'      => $allRoles,
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

    $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__);
    $vendorAutoload = $projectRoot . '/vendor/autoload.php';
    $qrProvider = $projectRoot . '/vendor/Qr/QrSvgProvider.php';

    if (!is_file($vendorAutoload)) {
        throw new RuntimeException('Composer autoload ontbreekt op: ' . $vendorAutoload);
    }
    
    require_once $vendorAutoload;
    
    if (!class_exists('\\App\\Qr\\QrSvgProvider') && is_file($qrProvider)) {
        require_once $qrProvider;
    }
    
    if (!class_exists('\\App\\Qr\\QrSvgProvider')) {
        throw new RuntimeException('QrSvgProvider niet gevonden op: ' . $qrProvider);
    }

    // Bepaal de juiste manier om de library te instantieren
    try {
        // Probeer de nieuwe manier met enum (PHP 8.1+)
        if (enum_exists('RobThree\Auth\Algorithm')) {
            $tfa = new \RobThree\Auth\TwoFactorAuth(
                issuer: 'Porbeheer',
                qrcodeprovider: new \App\Qr\QrSvgProvider()
            );
        } 
        // Probeer de oude manier met string
        else {
            $tfa = new \RobThree\Auth\TwoFactorAuth(
                'Porbeheer',
                new \App\Qr\QrSvgProvider()
            );
        }
    } catch (Throwable $e) {
        // Fallback: probeer met alleen issuer
        $tfa = new \RobThree\Auth\TwoFactorAuth('Porbeheer');
    }
    
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

    $u = loadAuthUserByUsername($pdo, $username);

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