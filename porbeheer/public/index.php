<?php
declare(strict_types=1);
require_once __DIR__ . '/cgi-bin/app/bootstrap.php';
include __DIR__ . '/assets/includes/header.php';


if (isLoggedIn()) {
    header('Location: /admin/dashboard.php');
} else {
    header('Location: /login.php');
}
exit;