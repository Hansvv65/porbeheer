<?php
declare(strict_types=1);

require_once __DIR__ . '/../../libs/porbeheer/app/bootstrap.php';


if (isLoggedIn()) {
    logout($pdo);
}

header('Location: /login.php');
exit;
