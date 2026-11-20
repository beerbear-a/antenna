<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_common.php';

logout();
header('Location: /login.php');
exit;
