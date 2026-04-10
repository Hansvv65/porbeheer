<?php
declare(strict_types=1);

/*
 * Porbeheer - User detail page users_detail.php
 */

require_once __DIR__ . '/../../../libs/porbeheer/app/bootstrap.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/auth.php';
require_once __DIR__ . '/../../../libs/porbeheer/app/mail.php';
include __DIR__ . '/../assets/includes/header.php';

requireRole(['ADMIN']);

$user = currentUser();
$role = $user['role'] ?? 'GEBRUIKER';
$bg = themeImage('contacts', $pdo);

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function mailLayout(string $title, string $intro, string $contentHtml): string {
    return '
    <div style="margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#243447;">
      <div style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #d9e2ec;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.08);">
        
        <div style="padding:18px 24px;background:linear-gradient(180deg,#eef6ff,#e6f0fb);border-bottom:1px solid #d9e2ec;">
          <div style="font-size:22px;font-weight:700;color:#1f3b57;">Porbeheer</div>
          <div style="margin-top:4px;font-size:13px;color:#5b7083;">POP Oefenruimte Zevenaar</div>
        </div>

        <div style="padding:28px 24px;">
          <h2 style="margin:0 0 12px 0;font-size:22px;color:#1f3b57;">' . h($title) . '</h2>
          <p style="margin:0 0 18px 0;font-size:15px;line-height:1.6;color:#425466;">' . h($intro) . '</p>

          <div style="font-size:15px;line-height:1.7;color:#243447;">
            ' . $contentHtml . '
          </div>
        </div>

        <div style="padding:16px 24px;background:#f8fbff;border-top:1px solid #d9e2ec;font-size:12px;color:#6b7c93;">
          Dit is een automatisch bericht van Porbeheer.
        </div>
      </div>
    </div>';
}

$allowedRoles  = ['ADMIN','BEHEER','FINANCIEEL','GEBRUIKER','BESTUURSLID'];
$rolePriority  = ['GEBRUIKER'=>1,'FINANCIEEL'=>2,'BEHEER'=>3,'BESTUURSLID'=>4,'ADMIN'=>5];

function computePrimaryRole(array $roles, array $priority): string {
    $primary = 'GEBRUIKER';
    foreach ($roles as $r) {
        if (($priority[$r] ?? 0) > ($priority[$primary] ?? 0)) $primary = $r;
    }
    return $primary;
}

function loadUser(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: [];
}

function loadRoles(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT role FROM user_roles WHERE user_id=? ORDER BY FIELD(role,'ADMIN','BEHEER','FINANCIEEL','GEBRUIKER')");
    $st->execute([$id]);
    return array_map(fn($r) => (string)$r['role'], $st->fetchAll(PDO::FETCH_ASSOC));
}

function countAdmins(PDO $pdo): int {
    return (int)$pdo->query("
        SELECT COUNT(DISTINCT ur.user_id)
        FROM user_roles ur
        JOIN users u ON u.id = ur.user_id
        WHERE ur.role='ADMIN'
          AND u.deleted_at IS NULL
    ")->fetchColumn();
}

function syncActiveWithStatus(PDO $pdo, int $id): void {
    $pdo->prepare("
        UPDATE users
        SET active =
          CASE
            WHEN deleted_at IS NOT NULL THEN 0
            WHEN status='ACTIVE' THEN 1
            ELSE 0
          END
        WHERE id=?
    ")->execute([$id]);
}

$errors  = [];
$success = null;

$csrf = csrfToken();

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
    header('Location: /admin/users.php');
    exit;
}

$target = loadUser($pdo, $id);
if (!$target) {
    header('Location: /admin/users.php');
    exit;
}

$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$isSelf = ($id === $currentUserId);

if (($_GET['created'] ?? '') === '1') {
    $success = 'Gebruiker aangemaakt. Je kunt nu details aanpassen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($_POST['csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    $target = loadUser($pdo, $id);
    if (!$target) {
        header('Location: /admin/users.php');
        exit;
    }

    $isDeleted = !empty($target['deleted_at']);
    $rolesNow  = loadRoles($pdo, $id);
    $isTargetAdmin = in_array('ADMIN', $rolesNow, true) || ((string)$target['role'] === 'ADMIN');

    if (in_array($action, ['block','soft_delete','hard_delete'], true) && $isSelf) {
        $errors[] = 'Je kunt deze actie niet op jezelf uitvoeren.';
    } else {

        if ($action === 'save_profile') {
            if ($isDeleted) {
                $errors[] = 'User is verwijderd (soft delete). Herstel eerst.';
            } else {
                $username = trim((string)($_POST['username'] ?? ''));
                $email    = trim((string)($_POST['email'] ?? ''));

                if ($username === '' || strlen($username) < 3) $errors[] = 'Gebruikersnaam minimaal 3 tekens.';
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ongeldig e-mailadres.';

                if (!$errors) {
                    try {
                        $pdo->prepare("UPDATE users SET username=?, email=? WHERE id=?")
                            ->execute([$username, $email !== '' ? $email : null, $id]);
                        $success = 'Profiel opgeslagen.';
                        auditLog($pdo, 'USER_UPDATE_PROFILE', 'admin/users_detail.php', ['id'=>$id]);
                    } catch (Throwable $e) {
                        $errors[] = 'Opslaan mislukt (username/email mogelijk al in gebruik).';
                        auditLog($pdo, 'USER_UPDATE_PROFILE_FAIL', 'admin/users_detail.php', ['id'=>$id, 'err'=>$e->getMessage()]);
                    }
                }
            }
        }

        if ($action === 'approve') {
            if ($isDeleted) {
                $errors[] = 'User is verwijderd. Herstel eerst.';
            } else {
                $pdo->prepare("UPDATE users SET status='ACTIVE', approved_at=COALESCE(approved_at, NOW()) WHERE id=?")
                    ->execute([$id]);
                syncActiveWithStatus($pdo, $id);

                $success = 'User goedgekeurd en geactiveerd.';
                auditLog($pdo, 'USER_APPROVE', 'admin/users_detail.php', ['id'=>$id]);

                $target = loadUser($pdo, $id);

                if (!empty($target['email'])) {
                    $loginLink = appUrl('/login.php');
                    $isEmailVerified = !empty($target['email_verified_at']);

                    if ($isEmailVerified) {
                        $html = mailLayout(
                            'Aanmelding goedgekeurd',
                            'Je account is goedgekeurd en staat nu actief.',
                            '
                            <p style="margin:0 0 14px 0;">Hoi ' . h((string)$target['username']) . ',</p>

                            <p style="margin:0 0 14px 0;">
                              Je aanmelding voor Porbeheer is goedgekeurd.
                            </p>

                            <p style="margin:0 0 14px 0;">
                              Je kunt nu inloggen met je gebruikersnaam en wachtwoord.
                            </p>

                            <p style="margin:18px 0;">
                              <a href="' . h($loginLink) . '" style="display:inline-block;padding:12px 18px;background:#dfefff;border:1px solid #bdd3ea;border-radius:10px;color:#1f3b57;text-decoration:none;font-weight:700;">
                                Naar inloggen
                              </a>
                            </p>

                            <p style="margin:0;">
                              Veel succes met Porbeheer.
                            </p>
                            '
                        );
                    } else {
                        $html = mailLayout(
                            'Aanmelding goedgekeurd',
                            'Je account is door de beheerder goedgekeurd.',
                            '
                            <p style="margin:0 0 14px 0;">Hoi ' . h((string)$target['username']) . ',</p>

                            <p style="margin:0 0 14px 0;">
                              Je aanmelding voor Porbeheer is goedgekeurd.
                            </p>

                            <p style="margin:0 0 14px 0;">
                              Je kunt inloggen zodra je ook je e-mailadres hebt bevestigd via de verificatiemail.
                            </p>

                            <p style="margin:0;">
                              Heb je die e-mail niet meer? Laat het dan aan een beheerder weten.
                            </p>
                            '
                        );
                    }

                    try {
                        sendEmail((string)$target['email'], 'Je aanmelding is goedgekeurd (Porbeheer)', $html);
                        auditLog($pdo, 'USER_APPROVE_MAIL_SENT', 'admin/users_detail.php', ['id'=>$id]);
                    } catch (Throwable $e) {
                        auditLog($pdo, 'USER_APPROVE_MAIL_FAIL', 'admin/users_detail.php', [
                            'id'    => $id,
                            'error' => substr($e->getMessage(), 0, 200),
                        ]);
                    }
                }
            }
        }

        if ($action === 'block') {
            if ($isDeleted) $errors[] = 'User is verwijderd.';
            elseif ($isTargetAdmin) $errors[] = 'ADMIN accounts mogen niet geblokkeerd worden.';
            else {
                $pdo->prepare("UPDATE users SET status='BLOCKED' WHERE id=?")->execute([$id]);
                syncActiveWithStatus($pdo, $id);

                $success = 'User geblokkeerd.';
                auditLog($pdo, 'USER_BLOCK', 'admin/users_detail.php', ['id'=>$id]);
            }
        }

        if ($action === 'unblock') {
            if ($isDeleted) $errors[] = 'User is verwijderd. Herstel eerst.';
            else {
                $pdo->prepare("UPDATE users SET status='ACTIVE', approved_at=COALESCE(approved_at, NOW()) WHERE id=?")
                    ->execute([$id]);
                syncActiveWithStatus($pdo, $id);

                $success = 'User gedeblokkeerd en geactiveerd.';
                auditLog($pdo, 'USER_UNBLOCK', 'admin/users_detail.php', ['id'=>$id]);
            }
        }

        if ($action === 'clear_lock') {
            if ($isDeleted) $errors[] = 'User is verwijderd.';
            else {
                $pdo->prepare("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE id=?")->execute([$id]);
                $success = 'Failed attempts en locked_until gewist.';
                auditLog($pdo, 'USER_LOCK_CLEAR', 'admin/users_detail.php', ['id'=>$id]);
            }
        }

        if ($action === 'set_roles') {
            if ($isDeleted) $errors[] = 'User is verwijderd. Herstel eerst.';
            else {
                $roles = $_POST['roles'] ?? [];
                if (!is_array($roles)) $roles = [];
                $roles = array_values(array_unique(array_filter($roles, fn($r) => in_array($r, $allowedRoles, true))));

                if (!$roles) $errors[] = 'Kies minimaal 1 rol.';
                else {
                    if ($isTargetAdmin && !in_array('ADMIN', $roles, true)) {
                        $errors[] = 'ADMIN rol kan niet verwijderd worden van een ADMIN account.';
                    }

                    $adminCount    = countAdmins($pdo);
                    $willHaveAdmin  = in_array('ADMIN', $roles, true);
                    if ($isTargetAdmin && !$willHaveAdmin && $adminCount <= 1) {
                        $errors[] = 'Je kunt de laatste ADMIN niet downgraden.';
                    }

                    if (!$errors) {
                        try {
                            $pdo->beginTransaction();

                            $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$id]);
                            $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, ?)");
                            foreach ($roles as $r) $ins->execute([$id, $r]);

                            $primary = computePrimaryRole($roles, $rolePriority);
                            $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$primary, $id]);

                            $pdo->commit();

                            $success = 'Rollen opgeslagen.';
                            auditLog($pdo, 'USER_ROLES_SET', 'admin/users_detail.php', [
                                'id'=>$id, 'roles'=>implode(',', $roles), 'primary'=>$primary
                            ]);
/*                        } catch (Throwable $e) {
                          if ($pdo->inTransaction()) $pdo->rollBack();
                          $errors[] = 'Opslaan rollen mislukt: ' . $e->getMessage();  // Voeg de echte fout toe
                          auditLog($pdo, 'USER_ROLES_SET_FAIL', 'admin/users_detail.php', ['id'=>$id, 'err'=>$e->getMessage()]);
                        }
*/                        
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $errors[] = 'Opslaan rollen mislukt.';
                            auditLog($pdo, 'USER_ROLES_SET_FAIL', 'admin/users_detail.php', ['id'=>$id, 'err'=>$e->getMessage()]);
                        }
                            
                    }
                }
            }
        }

        if ($action === 'soft_delete') {
            $reason = trim((string)($_POST['delete_reason'] ?? ''));

            $rolesNow = loadRoles($pdo, $id);
            $isTargetAdmin = in_array('ADMIN', $rolesNow, true) || ((string)$target['role'] === 'ADMIN');

            if ($isDeleted) $errors[] = 'User is al verwijderd.';
            elseif ($isTargetAdmin) $errors[] = 'ADMIN accounts mogen niet verwijderd worden.';
            else {
                try {
                    $pdo->prepare("
                        UPDATE users
                        SET deleted_at=NOW(),
                            deleted_by=?,
                            deleted_reason=?,
                            status='BLOCKED'
                        WHERE id=?
                    ")->execute([
                        $currentUserId ?: null,
                        $reason !== '' ? mb_substr($reason, 0, 255) : null,
                        $id
                    ]);
                    syncActiveWithStatus($pdo, $id);

                    $success = 'User verwijderd (soft delete).';
                    auditLog($pdo, 'USER_SOFT_DELETE', 'admin/users_detail.php', ['id'=>$id, 'reason'=>$reason ?: null]);
                } catch (Throwable $e) {
                    $errors[] = 'Soft delete mislukt.';
                    auditLog($pdo, 'USER_SOFT_DELETE_FAIL', 'admin/users_detail.php', ['id'=>$id, 'err'=>$e->getMessage()]);
                }
            }
        }

        if ($action === 'restore') {
            if (!$isDeleted) $errors[] = 'User is niet verwijderd.';
            else {
                $pdo->prepare("
                    UPDATE users
                    SET deleted_at=NULL,
                        deleted_by=NULL,
                        deleted_reason=NULL,
                        status='ACTIVE',
                        approved_at=COALESCE(approved_at, NOW())
                    WHERE id=?
                ")->execute([$id]);
                syncActiveWithStatus($pdo, $id);

                $success = 'User hersteld en geactiveerd.';
                auditLog($pdo, 'USER_RESTORE', 'admin/users_detail.php', ['id'=>$id]);
            }
        }

        if ($action === 'hard_delete') {
            $target = loadUser($pdo, $id);
            $isDeleted = !empty($target['deleted_at']);
            $rolesNow  = loadRoles($pdo, $id);
            $isTargetAdmin = in_array('ADMIN', $rolesNow, true) || ((string)$target['role'] === 'ADMIN');

            if (!$isDeleted) $errors[] = 'User is niet verwijderd (soft delete). Verwijder eerst.';
            elseif ($isTargetAdmin) $errors[] = 'ADMIN accounts mogen niet definitief verwijderd worden.';
            else {
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$id]);
                    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);

                    $pdo->commit();

                    auditLog($pdo, 'USER_HARD_DELETE', 'admin/users_detail.php', ['id'=>$id]);

                    header('Location: /admin/users.php?deleted=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = 'Definitief verwijderen mislukt. Mogelijk zijn er nog gekoppelde records (FK).';
                    auditLog($pdo, 'USER_HARD_DELETE_FAIL', 'admin/users_detail.php', ['id'=>$id, 'err'=>$e->getMessage()]);
                }
            }
        }

        if ($action === 'send_reset') {
            $target = loadUser($pdo, $id);
            $isDeleted = !empty($target['deleted_at']);

            if ($isDeleted) $errors[] = 'User is verwijderd. Herstel eerst.';
            else {
                if (empty($target['email'])) $errors[] = 'Geen e-mail bekend bij deze user.';
                elseif (($target['status'] ?? 'ACTIVE') === 'BLOCKED') $errors[] = 'User is geblokkeerd.';
                else {
                    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $hash  = password_hash($token, PASSWORD_DEFAULT);
                    $exp   = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');

                    $pdo->prepare("
                        UPDATE users
                        SET reset_token_hash=?,
                            reset_expires_at=?,
                            reset_requested_at=NOW()
                        WHERE id=?
                    ")->execute([$hash, $exp, $id]);

                    $link = appUrl('/reset.php?' . http_build_query([
                        'token' => $token,
                        'email' => (string)$target['email'],
                    ]));

                    $html = mailLayout(
                        'Wachtwoord reset',
                        'Er is een resetlink voor je account aangemaakt.',
                        '
                        <p style="margin:0 0 14px 0;">Hoi ' . h((string)$target['username']) . ',</p>

                        <p style="margin:0 0 14px 0;">
                          Een beheerder heeft een wachtwoord-reset gestart voor je account.
                        </p>

                        <p style="margin:0 0 14px 0;">
                          Via onderstaande knop kun je een nieuw wachtwoord instellen. Deze link is
                          <strong>30 minuten geldig</strong>.
                        </p>

                        <p style="margin:18px 0;">
                          <a href="' . h($link) . '" style="display:inline-block;padding:12px 18px;background:#dfefff;border:1px solid #bdd3ea;border-radius:10px;color:#1f3b57;text-decoration:none;font-weight:700;">
                            Wachtwoord resetten
                          </a>
                        </p>

                        <p style="margin:0;">
                          Heb jij dit niet verwacht? Neem dan contact op met een beheerder.
                        </p>
                        '
                    );

                    try {
                        sendEmail((string)$target['email'], 'Porbeheer wachtwoord reset', $html);
                        $success = 'Resetlink verstuurd.';
                        auditLog($pdo, 'ADMIN_PWD_RESET_SEND', 'admin/users_detail.php', ['id'=>$id]);
                    } catch (Throwable $e) {
                        $errors[] = 'Resetmail versturen mislukt (SMTP/config/log checken).';
                        auditLog($pdo, 'ADMIN_PWD_RESET_SEND_FAIL', 'admin/users_detail.php', ['id'=>$id, 'err'=>$e->getMessage()]);
                    }
                }
            }
        }

        if ($action === 'set_password') {
            $target = loadUser($pdo, $id);
            $isDeleted = !empty($target['deleted_at']);

            if ($isDeleted) $errors[] = 'User is verwijderd. Herstel eerst.';
            else {
                $p1 = (string)($_POST['new_password'] ?? '');
                $p2 = (string)($_POST['new_password2'] ?? '');

                if (strlen($p1) < 10) $errors[] = 'Wachtwoord minimaal 10 tekens.';
                if ($p1 !== $p2) $errors[] = 'Wachtwoorden komen niet overeen.';

                if (!$errors) {
                    $hash = password_hash($p1, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $id]);
                    $success = 'Wachtwoord aangepast.';
                    auditLog($pdo, 'ADMIN_SET_PASSWORD', 'admin/users_detail.php', ['id'=>$id]);
                }
            }
        }
    }

    $target = loadUser($pdo, $id);
}

$rolesNow = loadRoles($pdo, $id);

$isDeleted = !empty($target['deleted_at']);
$status    = (string)($target['status'] ?? 'PENDING');
$isActive  = ($status === 'ACTIVE') && !$isDeleted;

$lockedUntil   = $target['locked_until'] ?? null;
$isTempLocked  = $lockedUntil && strtotime((string)$lockedUntil) > time();
$showLocked    = ($status === 'BLOCKED') || $isTempLocked;

$isTargetAdmin = in_array('ADMIN', $rolesNow, true) || ((string)$target['role'] === 'ADMIN');

$statusLabel = $status;
$statusClass = 'badge';

if ($isDeleted) {
    $statusLabel = 'DELETED';
    $statusClass = 'badge off';
} elseif ($showLocked) {
    if ($isTempLocked) {
        $statusLabel = 'LOCKED (tot ' . date('Y-m-d H:i', strtotime((string)$lockedUntil)) . ')';
    } else {
        $statusLabel = 'BLOCKED';
    }
    $statusClass = 'badge off';
} else {
    if ($status === 'ACTIVE') {
        $statusLabel = 'ACTIVE';
        $statusClass = 'badge ok';
    } elseif ($status === 'PENDING') {
        $statusLabel = 'PENDING';
        $statusClass = 'badge warn';
    } elseif ($status === 'BLOCKED') {
        $statusLabel = 'BLOCKED';
        $statusClass = 'badge off';
    } else {
        $statusLabel = $status;
        $statusClass = 'badge';
    }
}

auditLog($pdo, 'PAGE_VIEW', 'admin/users_detail.php', ['id'=>$id]);

?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porbeheer - User detail</title>
<style>
  :root{
    --text:#fff; --muted:rgba(255,255,255,.78);
    --border:rgba(255,255,255,.22);
    --glass:rgba(255,255,255,.12);
    --shadow:0 14px 40px rgba(0,0,0,.45);
    --ok:#7CFFB2; --err:#FF8DA1; --accent:#ffd86b;
  }
  body{
    margin:0; font-family:Arial,sans-serif; color:var(--text);
    background:url('<?= h($bg) ?>') no-repeat center center fixed;
    background-size:cover;
  }
  .backdrop{
    min-height:100vh;
    background:
      radial-gradient(circle at 25% 15%, rgba(0,0,0,.35), rgba(0,0,0,.75) 55%, rgba(0,0,0,.88)),
      linear-gradient(0deg, rgba(0,0,0,.35), rgba(0,0,0,.35));
    padding:26px; box-sizing:border-box;
    display:flex; justify-content:center;
  }
  .wrap{ width:min(1100px, 96vw); }

  .topbar{
    display:flex; align-items:flex-end; justify-content:space-between;
    gap:16px; flex-wrap:wrap; margin-bottom:14px;
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
  .userbox .line1{font-weight:bold}
  .userbox .line2{color:var(--muted);margin-top:4px;font-size:13px}
  a{ color:#fff; text-decoration:none; }
  a:visited{ color:var(--accent); }
  a:hover{ opacity:.95; }

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

  .btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(255,255,255,.14);
    color:#fff;
    font-weight:900;
    letter-spacing:.2px;
    cursor:pointer;
    box-shadow:0 10px 22px rgba(0,0,0,.20);
    transition:transform .12s ease, background .12s ease, border-color .12s ease;
  }
  .btn:hover{ transform:translateY(-1px); background:rgba(255,255,255,.18); border-color:rgba(255,255,255,.35); }
  .btn.ok{ border-color:rgba(124,255,178,.35); background:rgba(124,255,178,.10); }
  .btn.danger{ border-color:rgba(255,141,161,.35); background:rgba(255,141,161,.10); }
  .btn.small{ padding:8px 10px; font-size:13px; font-weight:900; }
  .btn:disabled{ opacity:.5; cursor:not-allowed; transform:none; }

  .msg-ok{
    margin:0 0 10px 0; padding:10px 12px; border-radius:12px;
    border:1px solid rgba(124,255,178,.35);
    background:rgba(124,255,178,.12);
    color:var(--ok); font-weight:900;
  }
  .msg-err{
    margin:0 0 10px 0; padding:10px 12px; border-radius:12px;
    border:1px solid rgba(255,141,161,.35);
    background:rgba(255,141,161,.12);
    color:var(--err); font-weight:900;
  }

  .badge{
    display:inline-block;
    padding:3px 10px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(255,255,255,.08);
    font-weight:900;
    letter-spacing:.2px;
    font-size:12px;
    margin-right:6px;
    margin-top:4px;
  }
  .badge.ok{ border-color:rgba(124,255,178,.35); color:var(--ok); background:rgba(124,255,178,.10); }
  .badge.off{ border-color:rgba(255,141,161,.35); color:var(--err); background:rgba(255,141,161,.10); }
  .badge.warn{ border-color:rgba(255,216,107,.40); color:var(--accent); background:rgba(255,216,107,.10); }

  .grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:14px;
    margin-top:12px;
  }
  @media (max-width: 900px){ .grid{ grid-template-columns: 1fr; } }

  .box{
    border-radius:16px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(0,0,0,.14);
    padding:14px 14px;
  }
  .label{ color:var(--muted); font-size:12px; font-weight:900; }
  .inp{
    width:100%;
    margin-top:6px;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(0,0,0,.25);
    color:#fff;
    outline:none;
    box-sizing:border-box;
  }
  .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .small{ margin-top:6px; color:var(--muted); font-size:12px; overflow-wrap:anywhere; }
  .hr{ height:1px; background:rgba(255,255,255,.12); margin:12px 0; }

  .rolegrid{
    display:flex; flex-wrap:wrap; gap:10px;
    margin-top:10px;
    padding:10px 12px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(0,0,0,.12);
    border-radius:14px;
  }
  .roleitem{ display:flex; align-items:center; gap:8px; font-weight:900; font-size:13px; }
  .roleitem input{ transform:scale(1.12); }
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
          <a href="/admin/users.php">Gebruikers</a>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="row" style="justify-content:space-between;">
        <div>
          <h2 style="margin:0">User detail</h2>
          <div class="small">
            #<?= (int)$target['id'] ?> • <?= h((string)$target['username']) ?>
            <?php if ($isTargetAdmin): ?><span class="badge ok">ADMIN</span><?php endif; ?>
            <span class="<?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
          </div>
        </div>
        <div class="row">
          <a class="btn small" href="/admin/users.php">← Terug</a>
        </div>
      </div>

      <?php if ($success): ?><div class="msg-ok"><?= h($success) ?></div><?php endif; ?>
      <?php foreach ($errors as $e): ?><div class="msg-err"><?= h($e) ?></div><?php endforeach; ?>

      <div class="grid">

        <div class="box">
          <div class="label">Profiel</div>

          <form method="post" style="margin-top:10px">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$target['id'] ?>">

            <label class="label">Username</label>
            <input class="inp" type="text" name="username" value="<?= h((string)$target['username']) ?>" <?= $isDeleted ? 'disabled' : '' ?>>

            <label class="label" style="margin-top:10px; display:block;">E-mail</label>
            <input class="inp" type="text" name="email" value="<?= h((string)($target['email'] ?? '')) ?>" <?= $isDeleted ? 'disabled' : '' ?>>

            <div class="row" style="margin-top:12px;">
              <button class="btn ok small" type="submit" name="action" value="save_profile" <?= $isDeleted ? 'disabled' : '' ?>>Opslaan</button>
            </div>
          </form>

          <div class="hr"></div>

          <div class="label">Systeemvelden</div>
          <div class="small">created_at: <?= h((string)$target['created_at']) ?></div>
          <div class="small">email_verified_at: <?= h((string)($target['email_verified_at'] ?? '—')) ?></div>
          <div class="small">approved_at: <?= h((string)($target['approved_at'] ?? '—')) ?></div>
          <div class="small">last_login_at: <?= h((string)($target['last_login_at'] ?? '—')) ?></div>
          <div class="small">reset_requested_at: <?= h((string)($target['reset_requested_at'] ?? '—')) ?></div>
          <div class="small">reset_expires_at: <?= h((string)($target['reset_expires_at'] ?? '—')) ?></div>
        </div>

        <div class="box">
          <div class="label">Status & acties</div>

          <form method="post" style="margin-top:10px">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$target['id'] ?>">

            <div class="row">
              <?php if (!$isDeleted && ($target['status'] ?? '') === 'PENDING'): ?>
                <button class="btn ok small" type="submit" name="action" value="approve">Goedkeuren</button>
              <?php endif; ?>

              <?php if (!$isDeleted): ?>
                <?php if (($target['status'] ?? '') !== 'BLOCKED'): ?>
                  <?php if ($isTargetAdmin || $isSelf): ?>
                    <button class="btn danger small" type="button" disabled title="ADMIN/eigen account mag niet geblokkeerd worden">Blokkeren</button>
                  <?php else: ?>
                    <button class="btn danger small" type="submit" name="action" value="block" onclick="return confirm('User blokkeren?')">Blokkeren</button>
                  <?php endif; ?>
                <?php else: ?>
                  <button class="btn ok small" type="submit" name="action" value="unblock">Deblokkeren</button>
                <?php endif; ?>

                <button class="btn small" type="submit" name="action" value="clear_lock" onclick="return confirm('Failed attempts + lock wissen?')">Clear lock</button>
                <button class="btn small" type="submit" name="action" value="send_reset" onclick="return confirm('Resetlink mailen?')">Reset mail</button>
              <?php endif; ?>

              <?php if ($isDeleted): ?>
                <button class="btn ok small" type="submit" name="action" value="restore" onclick="return confirm('User herstellen?')">Herstellen</button>

                <?php if ($isTargetAdmin || $isSelf): ?>
                  <button class="btn danger small" type="button" disabled title="ADMIN/eigen account kan niet definitief verwijderd worden">Definitief verwijderen</button>
                <?php else: ?>
                  <button class="btn danger small" type="submit" name="action" value="hard_delete"
                    onclick="return confirm('LET OP: Definitief verwijderen kan niet ongedaan gemaakt worden. Doorgaan?')">Definitief verwijderen</button>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <div class="hr"></div>

            <div class="label">Lock info</div>
            <div class="small">failed_attempts: <?= (int)($target['failed_attempts'] ?? 0) ?></div>
            <div class="small">locked_until: <?= h((string)($target['locked_until'] ?? '—')) ?></div>

            <div class="hr"></div>

            <div class="label">Soft delete</div>
            <div class="small">deleted_at: <?= h((string)($target['deleted_at'] ?? '—')) ?></div>
            <div class="small">deleted_by: <?= h((string)($target['deleted_by'] ?? '—')) ?></div>
            <div class="small">deleted_reason: <?= h((string)($target['deleted_reason'] ?? '—')) ?></div>

            <?php if (!$isDeleted): ?>
              <input class="inp" type="text" name="delete_reason" placeholder="Reden (optioneel)" maxlength="255">
              <?php if ($isTargetAdmin || $isSelf): ?>
                <button class="btn danger small" type="button" disabled title="ADMIN/eigen account kan niet verwijderd worden">Verwijderen</button>
              <?php else: ?>
                <button class="btn danger small" type="submit" name="action" value="soft_delete"
                  onclick="return confirm('Weet je zeker dat je deze gebruiker wilt verwijderen (soft delete)?')">Verwijderen</button>
              <?php endif; ?>
            <?php endif; ?>

          </form>

          <div class="hr"></div>

          <div class="label">Wachtwoord (admin)</div>
          <form method="post" style="margin-top:10px">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$target['id'] ?>">

            <input class="inp" type="password" name="new_password" placeholder="Nieuw wachtwoord (min 10)" <?= $isDeleted ? 'disabled' : '' ?>>
            <input class="inp" type="password" name="new_password2" placeholder="Herhaal wachtwoord" <?= $isDeleted ? 'disabled' : '' ?>>

            <div class="row" style="margin-top:10px;">
              <button class="btn ok small" type="submit" name="action" value="set_password" <?= $isDeleted ? 'disabled' : '' ?>
                onclick="return confirm('Wachtwoord direct aanpassen voor deze user?')">Wachtwoord opslaan</button>
            </div>
            <div class="small">Tip: voor normale flow liever “Reset mail” gebruiken.</div>
          </form>
        </div>

        <div class="box">
          <div class="label">Rollen</div>

          <form method="post" style="margin-top:10px">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$target['id'] ?>">

            <div class="rolegrid">
              <?php foreach ($allowedRoles as $r): ?>
                <?php
                  $checked  = in_array($r, $rolesNow, true);
                  $disabled = ($isTargetAdmin && $r === 'ADMIN') ? 'disabled' : '';
                ?>
                <label class="roleitem">
                  <input type="checkbox" name="roles[]" value="<?= h($r) ?>"
                    <?= $checked ? 'checked' : '' ?>
                    <?= $isDeleted ? 'disabled' : '' ?>
                    <?= $disabled ?>>
                  <?= h($r) ?>
                </label>
              <?php endforeach; ?>
            </div>

            <div class="row" style="margin-top:12px;">
              <button class="btn small" type="submit" name="action" value="set_roles" <?= $isDeleted ? 'disabled' : '' ?>>Rollen opslaan</button>
              <span class="small">Primair (users.role): <span class="badge"><?= h((string)$target['role']) ?></span></span>
            </div>

            <div class="small">
              Beveiliging: ADMIN rol kan niet worden verwijderd van een ADMIN-account. Laatste ADMIN kan niet gedowngraded worden.
            </div>
          </form>
        </div>

        <div class="box">
          <div class="label">Debug / velden</div>
          <div class="small">status: <?= h((string)$target['status']) ?></div>
          <div class="small">active (DB): <?= (int)$target['active'] ?> (wordt door status gesynchroniseerd)</div>
          <div class="small">email_verified_at: <?= h((string)($target['email_verified_at'] ?? '—')) ?></div>
          <div class="small">failed_attempts: <?= (int)$target['failed_attempts'] ?></div>
          <div class="small">locked_until: <?= h((string)($target['locked_until'] ?? '—')) ?></div>
          <div class="small">reset_token_hash: <?= h((string)($target['reset_token_hash'] ? 'SET' : '—')) ?></div>
        </div>

      </div>

    </div>

  </div>
</div>
</body>
</html>