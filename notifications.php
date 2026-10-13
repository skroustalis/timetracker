<?php
declare(strict_types=1);
// Καμπανάκι: πλήθος αδιάβαστων, «διαβάστηκαν όλες», άνοιγμα ειδοποίησης
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if (!$user) { http_response_code(401); exit; }
$uid = (int)$user['id'];

// GET ?count=1[&after=ID] -> {"unread": N, "last": ID, "items": [...]}
// (ανανέωση του αριθμού χωρίς reload + οι νέες για ειδοποίηση στον υπολογιστή)
if (!is_post() && isset($_GET['count'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $after = get_int('after');
    echo json_encode([
        'unread' => unread_notifications($uid),
        'last' => last_notification_id($uid),
        'items' => $after !== null ? notifications_after($uid, $after) : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// GET ?open=ID -> σημειώνεται ως διαβασμένη και πάει στο link της
if (!is_post() && ($id = get_int('open'))) {
    $n = q_one('SELECT * FROM tt_notifications WHERE id = ? AND user_id = ?', [$id, $uid]);
    if ($n) q('UPDATE tt_notifications SET read_at = COALESCE(read_at, ?) WHERE id = ?', [now_sql(), $n['id']]);
    redirect(safe_return($n['link'] ?? null));
}

// POST act=read_all -> όλες διαβασμένες
if (is_post()) {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'read_all') {
        q('UPDATE tt_notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now_sql(), $uid]);
    } elseif ($act === 'budget_dismiss') {
        // πίνακας ορίων στο dashboard: κρύβει το project για τον χρήστη μέχρι το επόμενο βήμα
        $ok = dismiss_budget_alert($user, (int)($_POST['project_id'] ?? 0), (int)($_POST['tag_id'] ?? 0));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok]);
        exit;
    } elseif ($act === 'clear') {
        // καθαρισμός: σβήνει μόνο τις δικές του ειδοποιήσεις
        q('DELETE FROM tt_notifications WHERE user_id = ?', [$uid]);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'unread' => unread_notifications($uid)]);
    exit;
}

redirect('tracker.php');
