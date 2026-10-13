<?php
declare(strict_types=1);
if (!is_file(__DIR__ . '/includes/config.php')) {
    header('Location: install.php');
    exit;
}
header('Location: tracker.php');
