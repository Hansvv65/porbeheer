<?php
declare(strict_types=1);
require_once __DIR__ . '/cgi-bin/app/bootstrap.php';
include __DIR__ . '/assets/includes/header.php';


if (isLoggedIn()) {
    logout($pdo);
}

header('Location: /login.php');
exit;
