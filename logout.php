<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    csrf_check();
    logout();
}
redirect('login.php');
