<?php
declare(strict_types=1);

/**
 * Ειδοποιήσεις μέσα στην εφαρμογή (καμπανάκι στην πάνω μπάρα) και αυτόματο σταμάτημα χρονομέτρων.
 * Δεν χρειάζεται cron: όλα γίνονται «τεμπέλικα» όταν κάποιος ανοίγει μια σελίδα.
 *
 * Στη βάση κρατάμε τύπο + παραμέτρους (JSON), όχι έτοιμο κείμενο,
 * ώστε κάθε χρήστης να βλέπει την ειδοποίηση στη δική του γλώσσα.
 */

const NOTIFY_KEEP_DAYS = 60;       // πόσο κρατάμε τις ειδοποιήσεις
const NOTIFY_LIST_LIMIT = 15;      // πόσες φαίνονται στο καμπανάκι

const AUTO_STOP_MAX_HOURS = 24;

/** Μετά από πόσες ώρες σταματά αυτόματα ένα χρονόμετρο (0 = ποτέ). Το ορίζει ο admin στις Ρυθμίσεις. */
function auto_stop_hours(): int
{
    global $CONFIG;
    $v = setting('auto_stop_hours');
    $h = $v !== null ? (int)$v : (int)($CONFIG['auto_stop_hours'] ?? 8);
    return max(0, min(AUTO_STOP_MAX_HOURS, $h));
}

function notify(int $uid, string $type, array $params = [], ?string $link = null): void
{
    q('INSERT INTO tt_notifications (user_id, type, data, link, created_at) VALUES (?, ?, ?, ?, ?)',
        [$uid, $type, json_encode($params, JSON_UNESCAPED_UNICODE), $link, now_sql()]);
    // καθάρισμα παλιών, περιστασιακά
    if (random_int(1, 50) === 1) {
        q('DELETE FROM tt_notifications WHERE created_at < ?', [now()->modify('-' . NOTIFY_KEEP_DAYS . ' days')->format('Y-m-d H:i:s')]);
    }
}

function unread_notifications(int $uid): int
{
    return (int)q_val('SELECT COUNT(*) FROM tt_notifications WHERE user_id = ? AND read_at IS NULL', [$uid]);
}

function latest_notifications(int $uid, int $limit = NOTIFY_LIST_LIMIT): array
{
    return q_all('SELECT * FROM tt_notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$uid]);
}

/** [εικονίδιο, χρώμα, κείμενο] στη γλώσσα του τρέχοντος χρήστη */
function notification_view(array $n): array
{
    $p = json_decode((string)$n['data'], true) ?: [];
    $d = ($p['desc'] ?? '') !== '' ? $p['desc'] : t('(Χωρίς περιγραφή)');
    return match ($n['type']) {
        'autostop' => ['stopwatch', 'warning', t('Το χρονόμετρο «{d}» σταμάτησε αυτόματα μετά από {h}. Έλεγξε αν η ώρα λήξης είναι σωστή.', ['d' => $d, 'h' => hours_word($p['hours'] ?? 8)])],
        'budget' => ((int)($p['level'] ?? 0)) > 100 && isset($p['extra'])
            ? ['exclamation-triangle-fill', 'danger', t('Το «{p}» είναι πάνω από το όριο ωρών: όριο {b}, καταγράφηκαν {u}, δηλαδή {x} παραπάνω.', [
                'p' => $p['project'] ?? '?', 'b' => hours_word($p['hours'] ?? '?'), 'x' => hours_word($p['extra']),
                'u' => isset($p['used']) ? (string)$p['used'] // νέες ειδοποιήσεις: έτοιμο «06:00»
                    : hours_word(budget_hours_label((float)str_replace(',', '.', (string)($p['hours'] ?? 0)) + (float)str_replace(',', '.', (string)$p['extra']))),
            ])]
            : (((int)($p['level'] ?? 0)) >= 100
            ? ['exclamation-triangle-fill', 'danger', t('Το «{p}» ξεπέρασε το όριο ωρών ({b}).', ['p' => $p['project'] ?? '?', 'b' => hours_word($p['hours'] ?? '?')])]
            : ['hourglass-split', 'warning', t('Το «{p}» έφτασε στο 80% του ορίου ωρών ({b}).', ['p' => $p['project'] ?? '?', 'b' => hours_word($p['hours'] ?? '?')])]),
        'entry_edited' => ['pencil', 'info', t('{n} διόρθωσε την καταχώρησή σου «{d}» της {date}.', ['n' => $p['by'] ?? '?', 'd' => $d, 'date' => $p['date'] ?? ''])],
        'entry_deleted' => ['trash3', 'danger', t('{n} διέγραψε την καταχώρησή σου «{d}» της {date}.', ['n' => $p['by'] ?? '?', 'd' => $d, 'date' => $p['date'] ?? ''])],
        default => ['bell', 'info', (string)($p['text'] ?? '')],
    };
}

/** «1 ώρα» / «8 ώρες» / «1,5 ώρες». Χρόνος σε μορφή «05:00» μένει όπως είναι. */
function hours_word($h): string
{
    $n = (string)$h;
    if (str_contains($n, ':')) return $n;
    return (float)str_replace(',', '.', $n) == 1.0 ? t('{n} ώρα', ['n' => $n]) : t('{n} ώρες', ['n' => $n]);
}

/** «πριν από 5΄», «χθες 14:20», «3 Οκτ» */
function notification_when(string $sql): string
{
    $t = new DateTimeImmutable($sql);
    $diff = now()->getTimestamp() - $t->getTimestamp();
    if ($diff < 60) return t('μόλις τώρα');
    if ($diff < 3600) return t('πριν από {n}΄', ['n' => intdiv($diff, 60)]);
    if ($t->format('Y-m-d') === now()->format('Y-m-d')) return $t->format('H:i');
    if ($t->format('Y-m-d') === now()->modify('-1 day')->format('Y-m-d')) return t('χθες') . ' ' . $t->format('H:i');
    return fmt_short($t);
}

/**
 * Σταματά όσα χρονόμετρα τρέχουν πάνω από X ώρες (σε όλους τους χρήστες).
 * Η λήξη μπαίνει στο έναρξη + X ώρες, άρα το αποτέλεσμα είναι ίδιο όποτε κι αν τρέξει.
 */
function auto_stop_long_timers(): void
{
    $hours = auto_stop_hours();
    if ($hours <= 0) return;
    $limit = now()->modify("-{$hours} hours")->format('Y-m-d H:i:s');
    $rows = q_all('SELECT e.id, e.user_id, e.project_id, e.description, e.start_at FROM tt_entries e
                   WHERE e.end_at IS NULL AND e.start_at <= ?', [$limit]);
    foreach ($rows as $r) {
        $start = new DateTimeImmutable($r['start_at']);
        // ακριβώς N πραγματικές ώρες μετά την έναρξη (και τη νύχτα που αλλάζει η ώρα)
        $end = $start->setTimestamp($start->getTimestamp() + $hours * 3600);
        // μόνο αν τρέχει ακόμη (αποφυγή διπλού σταματήματος από δύο ταυτόχρονα αιτήματα)
        $st = q('UPDATE tt_entries SET end_at = ?, duration = ?, updated_at = ? WHERE id = ? AND end_at IS NULL',
            // διάρκεια από τις πραγματικές ώρες (σωστή και τη νύχτα που αλλάζει η ώρα)
            [$end->format('Y-m-d H:i:s'), $end->getTimestamp() - $start->getTimestamp(), now_sql(), $r['id']]);
        if ($st->rowCount() !== 1) continue;
        notify((int)$r['user_id'], 'autostop', ['desc' => $r['description'], 'hours' => $hours], 'calendar.php?date=' . $start->format('Y-m-d'));
        check_budget_alerts($r['project_id'] ? (int)$r['project_id'] : null, (int)$r['user_id']);
    }
}

/**
 * Όριο ωρών με χρονόμετρο που ΤΡΕΧΕΙ: ο χρόνος μετράει ήδη στη λίστα των projects,
 * οπότε ελέγχουμε και αυτά (το πολύ μία φορά το λεπτό για όλη την εφαρμογή).
 */
function check_running_budgets(): void
{
    try {
        $now = now();
        q("INSERT IGNORE INTO tt_settings (name, value) VALUES ('budget_run_chk', '')");
        // «κλείδωμα» ενός λεπτού: μόνο ένα αίτημα κάνει τον έλεγχο
        $st = q("UPDATE tt_settings SET value = ? WHERE name = 'budget_run_chk' AND (value IS NULL OR value < ?)",
            [$now->format('Y-m-d H:i:s'), $now->modify('-60 seconds')->format('Y-m-d H:i:s')]);
        if ($st->rowCount() !== 1) return;
        $rows = q_all('SELECT e.project_id, e.user_id FROM tt_entries e JOIN tt_projects p ON p.id = e.project_id
                       WHERE e.end_at IS NULL AND p.budget_hours > 0 ORDER BY e.start_at');
        foreach ($rows as $r) check_budget_alerts((int)$r['project_id'], (int)$r['user_id']);
    } catch (Throwable $e) {
        error_log('running budgets: ' . $e->getMessage());
    }
}

/** Ειδοποίηση στον κάτοχο όταν κάποιος άλλος (admin/manager) πειράζει την καταχώρησή του */
function notify_entry_change(array $actor, array $entry, string $what): void
{
    if ((int)$entry['user_id'] === (int)$actor['id']) return;
    $date = (new DateTimeImmutable($entry['start_at']))->format('d/m/Y');
    notify((int)$entry['user_id'], $what === 'delete' ? 'entry_deleted' : 'entry_edited',
        ['by' => $actor['name'], 'desc' => $entry['description'], 'date' => $date],
        $what === 'delete' ? null : 'calendar.php?date=' . substr((string)$entry['start_at'], 0, 10));
}

/** Κλειδί ορίου: περίοδος + ώρες ορίου (αν αλλάξει το όριο, ξαναειδοποιεί) */
function budget_seen_key(array $p): string
{
    $period = ($p['budget_period'] ?? 'total') === 'month' ? now()->format('Y-m') : 'total';
    if (!empty($p['tag_id'])) {
        // όριο ανά tag. Κάθε πακέτο (νέα ημερομηνία «Μετράει από») έχει δικό του κλειδί, οπότε ξαναειδοποιεί από την αρχή.
        $period = 't' . (int)$p['tag_id'] . ':' . (!empty($p['tag_start_at']) ? 's' . date('ymdHi', strtotime((string)$p['tag_start_at'])) : $period);
    }
    return $period . ':' . rtrim(rtrim(number_format((float)$p['budget_hours'], 2, '.', ''), '0'), '.');
}

/** Ειδοποίηση ορίου ωρών σε έναν χρήστη, μία φορά ανά project / όριο / επίπεδο (80, 100, 101, 102…) */
function notify_budget_once(int $uid, array $p, array $st, int $level): void
{
    if ($level <= 0) return;
    $key = budget_seen_key($p);
    // αν έχει ήδη ειδοποιηθεί για αυτό ή μεγαλύτερο επίπεδο, τίποτα (π.χ. αν μειώθηκαν οι ώρες)
    if (q_val('SELECT 1 FROM tt_budget_seen WHERE user_id = ? AND project_id = ? AND alert_key = ? AND level >= ? LIMIT 1',
        [$uid, (int)$p['id'], $key, $level])) return;
    $ins = q('INSERT IGNORE INTO tt_budget_seen (user_id, project_id, alert_key, level, created_at) VALUES (?, ?, ?, ?, ?)',
        [$uid, (int)$p['id'], $key, $level, now_sql()]);
    if ($ins->rowCount() !== 1) return;
    $data = ['project' => $p['name'], 'level' => $level, 'hours' => budget_hours_label($st['budget'] / 3600)];
    if ($level > 100) {
        $extra = budget_level_extra_hours($level, isset($p['group_id']) ? (int)$p['group_id'] : null);
        $data['extra'] = budget_hours_label($extra);
        $data['used'] = fmt_hm($st['budget'] + $extra * 3600);
    }
    notify($uid, 'budget', $data, 'project_edit.php?id=' . (int)$p['id']);
}

/** Τη στιγμή που περνιέται το όριο: admins + όλοι οι managers της υπεύθυνης ομάδας + ο χρήστης που το πέρασε */
function notify_budget(array $p, array $st, int $level, ?int $byUid = null): void
{
    $to = array_map('intval', array_column(q_all("SELECT id FROM tt_users WHERE role = 'admin' AND active = 1"), 'id'));
    if (!empty($p['group_id'])) foreach (group_managers((int)$p['group_id']) as $m) $to[] = (int)$m['id'];
    if ($byUid && q_val('SELECT 1 FROM tt_users WHERE id = ? AND active = 1', [$byUid])) $to[] = $byUid;
    foreach (array_unique($to) as $uid) notify_budget_once($uid, $p, $st, $level);
}

/**
 * Ό,τι φαίνεται κόκκινο/πορτοκαλί στη λίστα Projects του admin/manager, υπάρχει και στο καμπανάκι του.
 * (π.χ. project που ήταν ήδη πάνω από το όριο, ή που ο manager το βλέπει μέσω πρόσβασης ομάδας)
 */
function sync_budget_notifications(array $u): void
{
    if (!is_lead($u)) return;
    $t = time();
    if (($_SESSION['bsync'] ?? 0) > $t - 60) return; // το πολύ μία φορά το λεπτό ανά χρήστη
    $_SESSION['bsync'] = $t;
    try {
        foreach (budget_alerts($u) as $ba) {
            notify_budget_once((int)$u['id'], $ba['project'], $ba['status'], budget_level($ba['status'], isset($ba['project']['group_id']) ? (int)$ba['project']['group_id'] : null));
        }
    } catch (Throwable $e) {
        error_log('budget sync: ' . $e->getMessage());
    }
}

/** Νέες ειδοποιήσεις μετά από ένα id, για τις ειδοποιήσεις του υπολογιστή/κινητού */
function notifications_after(int $uid, int $afterId): array
{
    $out = [];
    $rows = q_all('SELECT * FROM tt_notifications WHERE user_id = ? AND id > ? AND read_at IS NULL ORDER BY id LIMIT 5', [$uid, $afterId]);
    foreach ($rows as $n) {
        [, , $text] = notification_view($n);
        $out[] = ['id' => (int)$n['id'], 'text' => $text, 'url' => 'notifications.php?open=' . (int)$n['id']];
    }
    return $out;
}

function last_notification_id(int $uid): int
{
    return (int)q_val('SELECT MAX(id) FROM tt_notifications WHERE user_id = ?', [$uid]);
}

/* ---------- Πίνακας ορίων στο dashboard: «καθαρισμός» ανά χρήστη ---------- */

/** Όρια που βλέπει ο χρήστης, χωρίς όσα έχει καθαρίσει (ξαναεμφανίζονται στο επόμενο βήμα / νέο όριο / νέο μήνα) */
function budget_alerts_for_dashboard(array $u): array
{
    $all = budget_alerts($u);
    if (!$all) return [];
    $hidden = [];
    foreach (q_all('SELECT project_id, tag_id, alert_key, level FROM tt_budget_dismiss WHERE user_id = ?', [(int)$u['id']]) as $r) {
        $hidden[(int)$r['project_id'] . ':' . (int)$r['tag_id']] = $r;
    }
    return array_filter($all, function ($ba) use ($hidden) {
        $h = $hidden[(int)$ba['project']['id'] . ':' . (int)($ba['project']['tag_id'] ?? 0)] ?? null;
        return !$h || $h['alert_key'] !== budget_seen_key($ba['project']) || (int)$h['level'] < budget_level($ba['status'], isset($ba['project']['group_id']) ? (int)$ba['project']['group_id'] : null);
    });
}

/** Καθαρισμός ενός ορίου από τον πίνακα του χρήστη, μέχρι το επόμενο βήμα */
function dismiss_budget_alert(array $u, int $projectId, int $tagId = 0): bool
{
    $ba = budget_alerts($u)[$tagId ? $projectId . ':t' . $tagId : $projectId] ?? null;
    if (!$ba) return false;
    q('INSERT INTO tt_budget_dismiss (user_id, project_id, tag_id, alert_key, level, created_at) VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE alert_key = VALUES(alert_key), level = VALUES(level), created_at = VALUES(created_at)',
        [(int)$u['id'], $projectId, $tagId, budget_seen_key($ba['project']), budget_level($ba['status'], isset($ba['project']['group_id']) ? (int)$ba['project']['group_id'] : null), now_sql()]);
    return true;
}
