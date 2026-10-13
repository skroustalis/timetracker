<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if (!is_post()) redirect('tracker.php');
csrf_check();

$uid = (int)$user['id'];
$action = (string)($_POST['action'] ?? '');
$return = safe_return($_POST['return'] ?? null);
$ajax = !empty($_POST['ajax']);

function respond(bool $ok, string $msg, string $return, bool $ajax, array $extra = []): never
{
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($msg !== '') flash($ok ? 'success' : 'error', $msg);
    redirect($return);
}

/** Φορτώνει καταχώρηση που μπορεί να πειράξει ο χρήστης (δική του· της ομάδας του για manager· οποιαδήποτε για admin) */
function load_entry(array $user, int $id): ?array
{
    $e = q_one('SELECT * FROM tt_entries WHERE id = ?', [$id]);
    if (!$e) return null;
    if (!can_manage_entry($user, $e)) return null;
    return $e;
}

switch ($action) {

    case 'start': {
        [$f, $err] = entry_fields_from_post($user);
        if ($err) respond(false, $err, $return, $ajax);
        db()->beginTransaction();
        stop_running($uid);
        $t = now_sql();
        $id = q_insert('INSERT INTO tt_entries (user_id, project_id, task_id, description, billable, start_at, end_at, duration, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)',
            [$uid, $f['project_id'], $f['task_id'], $f['description'], $f['billable'], $t, $t, $t]);
        set_entry_tags($id, $f['tags']);
        db()->commit();
        respond(true, '', $return, $ajax, ['id' => $id]);
    }

    case 'update_running': {
        // αυτόματη αποθήκευση πεδίων όσο τρέχει το χρονόμετρο
        $run = running_entry($uid);
        if (!$run) respond(false, t('Δεν τρέχει χρονόμετρο.'), $return, $ajax);
        [$f, $err] = entry_fields_from_post($user);
        if ($err) respond(false, $err, $return, $ajax);
        q('UPDATE tt_entries SET project_id = ?, task_id = ?, description = ?, billable = ?, updated_at = ? WHERE id = ?',
            [$f['project_id'], $f['task_id'], $f['description'], $f['billable'], now_sql(), $run['id']]);
        set_entry_tags((int)$run['id'], $f['tags']);
        respond(true, '', $return, $ajax);
    }

    case 'stop': {
        $run = running_entry($uid);
        if ($run) {
            [$f, $err] = entry_fields_from_post($user);
            if (!$err) {
                q('UPDATE tt_entries SET project_id = ?, task_id = ?, description = ?, billable = ? WHERE id = ?',
                    [$f['project_id'], $f['task_id'], $f['description'], $f['billable'], $run['id']]);
                set_entry_tags((int)$run['id'], $f['tags']);
            }
            stop_running($uid);
            check_budget_alerts(isset($f) && !$err ? $f['project_id'] : ($run['project_id'] ? (int)$run['project_id'] : null));
        }
        respond(true, '', $return, $ajax);
    }

    case 'stop_simple': {
        $run = running_entry($uid);
        stop_running($uid);
        if ($run) check_budget_alerts($run['project_id'] ? (int)$run['project_id'] : null);
        respond(true, t('Το χρονόμετρο σταμάτησε.'), $return, $ajax);
    }

    case 'discard': {
        q('DELETE FROM tt_entries WHERE user_id = ? AND end_at IS NULL', [$uid]);
        respond(true, t('Το χρονόμετρο ακυρώθηκε.'), $return, $ajax);
    }

    case 'add': {
        [$f, $err] = entry_fields_from_post($user);
        if ($err) respond(false, $err, $return, $ajax);
        [$start, $end, $err] = entry_times_from_post(false);
        if ($err) respond(false, $err, $return, $ajax);
        $t = now_sql();
        $id = q_insert('INSERT INTO tt_entries (user_id, project_id, task_id, description, billable, start_at, end_at, duration, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, $f['project_id'], $f['task_id'], $f['description'], $f['billable'],
             $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $end->getTimestamp() - $start->getTimestamp(), $t, $t]);
        set_entry_tags($id, $f['tags']);
        check_budget_alerts($f['project_id']);
        respond(true, t('Η καταχώρηση προστέθηκε.'), $return, $ajax);
    }

    case 'update': {
        $e = load_entry($user, (int)($_POST['id'] ?? 0));
        if (!$e) respond(false, t('Η καταχώρηση δεν βρέθηκε.'), $return, $ajax);
        $owner = (int)$e['user_id'] === $uid ? $user : (q_one('SELECT * FROM tt_users WHERE id = ?', [$e['user_id']]) ?? $user);
        // Admin/manager: projects που βλέπει ο ίδιος (π.χ. παλιές ώρες χρήστη που άλλαξε ομάδα)
        [$f, $err] = entry_fields_from_post(is_lead($user) ? $user : $owner);
        if ($err) respond(false, $err, $return, $ajax);
        $isRunning = $e['end_at'] === null;
        [$start, $end, $err] = entry_times_from_post($isRunning);
        if ($err) respond(false, $err, $return, $ajax);
        if ($isRunning && $end === null && $start > now()) $start = now();
        q('UPDATE tt_entries SET project_id = ?, task_id = ?, description = ?, billable = ?, start_at = ?, end_at = ?, duration = ?, updated_at = ? WHERE id = ?', [
            $f['project_id'], $f['task_id'], $f['description'], $f['billable'],
            $start->format('Y-m-d H:i:s'),
            $end?->format('Y-m-d H:i:s'),
            $end ? $end->getTimestamp() - $start->getTimestamp() : null,
            now_sql(), $e['id'],
        ]);
        set_entry_tags((int)$e['id'], $f['tags']);
        notify_entry_change($user, $e, 'edit');
        check_budget_alerts($f['project_id'], (int)$e['user_id']);
        if ($e['project_id'] && (int)$e['project_id'] !== (int)$f['project_id']) check_budget_alerts((int)$e['project_id'], (int)$e['user_id']);
        respond(true, t('Η καταχώρηση αποθηκεύτηκε.'), $return, $ajax);
    }

    case 'delete': {
        $e = load_entry($user, (int)($_POST['id'] ?? 0));
        if (!$e) respond(false, t('Η καταχώρηση δεν βρέθηκε.'), $return, $ajax);
        q('DELETE FROM tt_entries WHERE id = ?', [$e['id']]);
        notify_entry_change($user, $e, 'delete');
        check_budget_alerts($e['project_id'] ? (int)$e['project_id'] : null);
        respond(true, t('Η καταχώρηση διαγράφηκε.'), $return, $ajax);
    }

    case 'continue': {
        // ξεκινά νέο χρονόμετρο με τα ίδια στοιχεία
        $e = load_entry($user, (int)($_POST['id'] ?? 0));
        if (!$e || (int)$e['user_id'] !== $uid) respond(false, t('Η καταχώρηση δεν βρέθηκε.'), $return, $ajax);
        if ($e['project_id'] && !can_use_project($user, (int)$e['project_id'])) $e['project_id'] = $e['task_id'] = null;
        db()->beginTransaction();
        stop_running($uid);
        $t = now_sql();
        $id = q_insert('INSERT INTO tt_entries (user_id, project_id, task_id, description, billable, start_at, end_at, duration, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)',
            [$uid, $e['project_id'], $e['task_id'], $e['description'], $e['billable'], $t, $t, $t]);
        set_entry_tags($id, entry_tag_ids((int)$e['id']));
        db()->commit();
        respond(true, '', $return, $ajax);
    }

    case 'duplicate': {
        $e = load_entry($user, (int)($_POST['id'] ?? 0));
        if (!$e || $e['end_at'] === null) respond(false, t('Η καταχώρηση δεν βρέθηκε.'), $return, $ajax);
        $t = now_sql();
        $id = q_insert('INSERT INTO tt_entries (user_id, project_id, task_id, description, billable, start_at, end_at, duration, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$e['user_id'], $e['project_id'], $e['task_id'], $e['description'], $e['billable'], $e['start_at'], $e['end_at'], $e['duration'], $t, $t]);
        set_entry_tags($id, entry_tag_ids((int)$e['id']));
        check_budget_alerts($e['project_id'] ? (int)$e['project_id'] : null, (int)$e['user_id']);
        respond(true, t('Η καταχώρηση αντιγράφηκε.'), $return, $ajax);
    }
}

respond(false, t('Άγνωστη ενέργεια.'), $return, $ajax);
