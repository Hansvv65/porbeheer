<?php
declare(strict_types=1);
require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';


if (isLoggedIn()) {
    header('Location: /admin/dashboard.php');
} else {
    header('Location: /login.php');
}
exit;