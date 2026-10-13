<?php
declare(strict_types=1);
// Admin: αλλαγή της ομάδας (Developers, Marketing…) που βλέπει στην εφαρμογή
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$ret = safe_return($_GET['return'] ?? null);
$g = get_int('g');
if (is_admin($user) && $g && q_val('SELECT 1 FROM tt_groups WHERE id = ?', [$g])) {
    $_SESSION['dept'] = $g;
    setcookie('tt_dept', (string)$g, [
        'expires' => time() + 86400 * 365,
        'path' => app_base_path(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // σελίδες που δείχνουν κάτι συγκεκριμένο της άλλης ομάδας: πίσω στη λίστα
    if (preg_match('/^(project_edit|user_edit)\.php/', $ret)) $ret = str_starts_with($ret, 'project_edit') ? 'projects.php' : 'team.php';
    $ret = preg_replace('/([?&])page=\d+&?/', '$1', $ret);
}
redirect(rtrim($ret, '?&'));
