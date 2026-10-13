<?php
declare(strict_types=1);
// Οι πελάτες καταργήθηκαν (v1.9.14): όλα είναι projects. Η σελίδα μένει μόνο για παλιά links.
require __DIR__ . '/includes/bootstrap.php';
require_login();
redirect('projects.php');
