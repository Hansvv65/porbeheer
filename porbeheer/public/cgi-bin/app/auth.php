<?php
declare(strict_types=1);

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

            header('Location: ' . (function_exists('appUrl') ? appUrl('/login.php?timeout=1') : '/login.php?timeout=1'));
            exit;
        }

        $_SESSION['_last_activity'] = $now;
    }
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
    $ok = is_string($token) && !empty($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $token);
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

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . (function_exists('appUrl') ? appUrl('/login.php') : '/login.php'));
        exit;
    }
}

function requireRole(array $roles): void
{
    requireLogin();
    $role = $_SESSION['user']['role'] ?? 'GEBRUIKER';
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        exit('Geen toegang.');
    }
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

function attemptLogin(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    $maxAttempts = 5;
    $lockMinutes = 15;

    if ($username === '' || $password === '') {
        return ['ok' => false, 'msg' => 'Onjuiste inloggegevens.'];
    }

    $st = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u || empty($u['password_hash'])) {
        usleep(120000);
        return ['ok' => false, 'msg' => 'Onjuiste inloggegevens.'];
    }

    $uid = (int)$u['id'];

    if (!empty($u['deleted_at'])) {
        return ['ok' => false, 'msg' => 'Onjuiste inloggegevens.', 'code' => 'DELETED', 'user_id' => $uid];
    }

    $status = (string)($u['status'] ?? 'PENDING');

    if ($status === 'BLOCKED') {
        return ['ok' => false, 'msg' => 'Onjuiste inloggegevens.', 'code' => 'BLOCKED', 'user_id' => $uid];
    }
    if ($status === 'PENDING') {
        return ['ok' => false, 'msg' => 'Onjuiste inloggegevens.', 'code' => 'PENDING', 'user_id' => $uid];
    }
    if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) {
        return [
            'ok' => false,
            'msg' => 'Account tijdelijk vergrendeld.',
            'code' => 'LOCKED',
            'user_id' => $uid,
            'locked_until' => (string)$u['locked_until'],
        ];
    }

    if (!password_verify($password, (string)$u['password_hash'])) {
        $attempts = (int)($u['failed_attempts'] ?? 0) + 1;

        if ($attempts >= $maxAttempts) {
            $lockedUntil = (new DateTime("+{$lockMinutes} minutes"))->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$attempts, $lockedUntil, $uid]);

            return [
                'ok' => false,
                'msg' => 'Account tijdelijk vergrendeld.',
                'code' => 'LOCKED',
                'user_id' => $uid,
                'locked_until' => $lockedUntil,
            ];
        }

        $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')
            ->execute([$attempts, $uid]);

        return ['ok' => false, 'msg' => 'Onjuiste inloggegevens.', 'code' => 'BADPWD', 'user_id' => $uid];
    }

    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')
        ->execute([$uid]);

    $_SESSION['user'] = [
        'id' => $uid,
        'username' => (string)$u['username'],
        'role' => (string)($u['role'] ?? 'GEBRUIKER'),
    ];

    session_regenerate_id(true);

    return ['ok' => true, 'msg' => 'OK', 'user_id' => $uid];
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
