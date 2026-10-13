<?php
declare(strict_types=1);

/* ---------- Ομάδες / χρήστες ---------- */

function all_groups(): array
{
    return q_all('SELECT g.*, (SELECT COUNT(*) FROM tt_group_members gm JOIN tt_users u ON u.id = gm.user_id AND u.active = 1 WHERE gm.group_id = g.id) AS members
                  FROM tt_groups g ORDER BY g.name');
}

function user_group_ids(int $uid): array
{
    return array_map('intval', array_column(q_all('SELECT group_id FROM tt_group_members WHERE user_id = ?', [$uid]), 'group_id'));
}

/** Ομάδες του χρήστη (id, name, color), αλφαβητικά. Cache ανά request. */
function user_groups(int $uid): array
{
    static $cache = [];
    if (!isset($cache[$uid])) {
        $cache[$uid] = q_all('SELECT g.id, g.name, g.color FROM tt_group_members gm JOIN tt_groups g ON g.id = gm.group_id
                              WHERE gm.user_id = ? ORDER BY g.name', [$uid]);
    }
    return $cache[$uid];
}

function all_users(bool $activeOnly = true): array
{
    return q_all('SELECT id, name, email, role, active, hourly_rate FROM tt_users ' . ($activeOnly ? 'WHERE active = 1 ' : '') . 'ORDER BY name');
}

/* ---------- Projects / tasks / tags ---------- */

/** Projects που βλέπει ο χρήστης (admin: όλα) */
function visible_projects(array $user, bool $includeArchived = false): array
{
    $sql = 'SELECT p.*, c.name AS client_name FROM tt_projects p LEFT JOIN tt_clients c ON c.id = p.client_id WHERE 1=1';
    $params = [];
    if (!$includeArchived) $sql .= ' AND p.archived = 0';
    // κάθε project ανήκει σε μία ομάδα και το βλέπουν μόνο όσοι είναι σε αυτή (ο admin: η ομάδα που έχει επιλέξει)
    $dept = current_dept($user);
    if ($dept !== null) {
        $sql .= ' AND p.group_id = ?';
        $params[] = $dept;
    } elseif (!is_admin($user)) {
        $sql .= ' AND p.group_id IS NULL';
    }
    $sql .= ' ORDER BY c.name IS NULL, c.name, p.name';
    return q_all($sql, $params);
}

function can_use_project(array $user, int $projectId): bool
{
    foreach (visible_projects($user, true) as $p) {
        if ((int)$p['id'] === $projectId) return true;
    }
    return false;
}

/** [project_id => [[id,name], ...]] μόνο ανοιχτά tasks */
function tasks_map(): array
{
    $map = [];
    foreach (q_all('SELECT id, project_id, name FROM tt_tasks WHERE done = 0 ORDER BY name') as $t) {
        $map[(int)$t['project_id']][] = ['id' => (int)$t['id'], 'name' => $t['name']];
    }
    return $map;
}

/** Tags της ομάδας (ο admin: της ομάδας που έχει επιλέξει). $gid: συγκεκριμένη ομάδα. */
function all_tags(?int $gid = null): array
{
    $gid ??= current_dept();
    if ($gid === null) return q_all('SELECT id, name FROM tt_tags ORDER BY name');
    return q_all('SELECT id, name FROM tt_tags WHERE group_id = ? OR group_id IS NULL ORDER BY name', [$gid]);
}

function all_clients(bool $includeArchived = false): array
{
    return q_all('SELECT * FROM tt_clients ' . ($includeArchived ? '' : 'WHERE archived = 0 ') . 'ORDER BY name');
}

/* ---------- Καταχωρήσεις χρόνου ---------- */

function running_entry(int $uid): ?array
{
    return q_one('SELECT e.*, p.name AS project_name, p.color AS project_color
                  FROM tt_entries e LEFT JOIN tt_projects p ON p.id = e.project_id
                  WHERE e.user_id = ? AND e.end_at IS NULL ORDER BY e.start_at DESC LIMIT 1', [$uid]);
}

function entry_tag_ids(int $entryId): array
{
    return array_map('intval', array_column(q_all('SELECT tag_id FROM tt_entry_tags WHERE entry_id = ?', [$entryId]), 'tag_id'));
}

function set_entry_tags(int $entryId, array $tagIds): void
{
    q('DELETE FROM tt_entry_tags WHERE entry_id = ?', [$entryId]);
    if (!$tagIds) return;
    // μόνο tags της ομάδας του project (χωρίς project: της ομάδας του χρήστη της καταχώρησης)
    $row = q_one('SELECT e.user_id, p.group_id FROM tt_entries e LEFT JOIN tt_projects p ON p.id = e.project_id WHERE e.id = ?', [$entryId]);
    $gid = $row ? ($row['group_id'] !== null ? (int)$row['group_id'] : user_group_id((int)$row['user_id'])) : null;
    $allowed = array_map('intval', array_column($gid !== null ? all_tags($gid) : all_tags(), 'id'));
    $valid = array_values(array_intersect(array_map('intval', $tagIds), $allowed));
    foreach ($valid as $tid) {
        q('INSERT IGNORE INTO tt_entry_tags (entry_id, tag_id) VALUES (?, ?)', [$entryId, $tid]);
    }
}

/** Σταματάει ό,τι τρέχει για τον χρήστη */
function stop_running(int $uid, ?DateTimeImmutable $at = null): void
{
    $at ??= now();
    foreach (q_all('SELECT id, start_at FROM tt_entries WHERE user_id = ? AND end_at IS NULL', [$uid]) as $r) {
        $start = new DateTimeImmutable($r['start_at']);
        $end = $at < $start ? $start : $at;
        q('UPDATE tt_entries SET end_at = ?, duration = ?, updated_at = ? WHERE id = ?',
            [$end->format('Y-m-d H:i:s'), $end->getTimestamp() - $start->getTimestamp(), now_sql(), $r['id']]);
    }
}

/**
 * Κοινά πεδία καταχώρησης από POST (description, project, task, billable, tags).
 * Επιστρέφει [fields, error]
 */
function entry_fields_from_post(array $user): array
{
    $desc = post_str('description', 500);
    $projectId = post_int('project_id');
    $taskId = null; // τα tasks αφαιρέθηκαν από την εφαρμογή
    $billable = !empty($_POST['billable']) ? 1 : 0;
    $tags = post_ids('tags');

    if ($projectId) {
        if (!can_use_project($user, $projectId)) return [null, t('Δεν έχεις πρόσβαση σε αυτό το project.')];
    } else {
        $projectId = null;
        $taskId = null;
    }
    return [[
        'description' => $desc,
        'project_id' => $projectId,
        'task_id' => $taskId,
        'billable' => $billable,
        'tags' => $tags,
    ], null];
}

/**
 * Διαβάζει date/start/end από POST. Αν end < start => επόμενη μέρα.
 * $allowOpen: αν true και το end είναι κενό, επιστρέφει end = null (τρέχει ακόμη).
 */
function entry_times_from_post(bool $allowOpen = false): array
{
    $date = parse_date(post_str('date', 20));
    if (!$date && post_str('date', 20) !== '') return [null, null, t('Η ημερομηνία δεν υπάρχει.')];
    $s = parse_hm(post_str('start', 10));
    $e = parse_hm(post_str('end', 10));
    if (!$date || !$s) return [null, null, t('Συμπλήρωσε ημερομηνία και ώρα έναρξης (π.χ. 09:30).')];
    $start = $date->setTime($s[0], $s[1]);
    if (!$e) {
        if ($allowOpen) return [$start, null, null];
        // Δεκτή και διάρκεια αντί για ώρα λήξης, π.χ. "1:30"
        $d = parse_hm(post_str('duration', 10));
        if ($d) return [$start, $start->modify('+' . ($d[0] * 60 + $d[1]) . ' minutes'), null];
        return [null, null, t('Συμπλήρωσε ώρα λήξης (π.χ. 11:00).')];
    }
    $end = $date->setTime($e[0], $e[1]);
    if ($end <= $start) $end = $end->modify('+1 day');
    if ($start > now()->modify('+1 day')) return [null, null, t('Η ώρα έναρξης είναι στο μέλλον.')];
    return [$start, $end, null];
}

/**
 * Φίλτρα:
 *  from, to (DateTimeImmutable, inclusive), user_ids[], group_id, project_ids[], client_id,
 *  tag_id, billable ('1'|'0'|''), q (κείμενο), no_project (bool), include_running (bool)
 * Επιστρέφει [where SQL, params]. Υποθέτει aliases e (entries) και p (projects).
 */
function entries_where(array $f): array
{
    $where = ['1=1'];
    $params = [];
    if (!empty($f['from'])) { $where[] = 'e.start_at >= ?'; $params[] = $f['from']->format('Y-m-d 00:00:00'); }
    if (!empty($f['to']))   { $where[] = 'e.start_at < ?';  $params[] = $f['to']->modify('+1 day')->format('Y-m-d 00:00:00'); }
    if (!empty($f['user_ids'])) { $where[] = 'e.user_id IN (' . in_ph($f['user_ids']) . ')'; array_push($params, ...$f['user_ids']); }
    if (!empty($f['group_id'])) { $where[] = 'e.user_id IN (SELECT user_id FROM tt_group_members WHERE group_id = ?)'; $params[] = (int)$f['group_id']; }
    // Καταχωρήσεις μιας ομάδας: όσες είναι σε projects της ομάδας, και όσες δεν έχουν project από μέλη της
    if (!empty($f['dept_id'])) {
        $where[] = '(p.group_id = ? OR (e.project_id IS NULL AND (e.group_id = ? OR (e.group_id IS NULL AND e.user_id IN (SELECT user_id FROM tt_group_members WHERE group_id = ?)))))';
        array_push($params, (int)$f['dept_id'], (int)$f['dept_id'], (int)$f['dept_id']);
    }
    // Ο χρόνος του admin δεν φαίνεται στους managers
    if (!empty($f['exclude_admins'])) $where[] = "e.user_id NOT IN (SELECT id FROM tt_users WHERE role = 'admin')";
    if (!empty($f['project_ids'])) { $where[] = 'e.project_id IN (' . in_ph($f['project_ids']) . ')'; array_push($params, ...$f['project_ids']); }
    if (!empty($f['no_project'])) { $where[] = 'e.project_id IS NULL'; }
    if (!empty($f['client_id'])) { $where[] = 'p.client_id = ?'; $params[] = (int)$f['client_id']; }
    if (!empty($f['tag_id'])) { $where[] = 'EXISTS (SELECT 1 FROM tt_entry_tags x WHERE x.entry_id = e.id AND x.tag_id = ?)'; $params[] = (int)$f['tag_id']; }
    if (isset($f['billable']) && $f['billable'] !== '' && $f['billable'] !== null) { $where[] = 'e.billable = ?'; $params[] = (int)$f['billable']; }
    if (!empty($f['q'])) { $where[] = 'e.description LIKE ?'; $params[] = '%' . addcslashes((string)$f['q'], '%_\\') . '%'; }
    // Από προεπιλογή μετράνε και οι καταχωρήσεις που τρέχουν (με διάρκεια έως τώρα)
    if (($f['include_running'] ?? true) === false) $where[] = 'e.end_at IS NOT NULL';
    return [implode(' AND ', $where), $params];
}

/** SQL διάρκειας: για όσα τρέχουν, μέχρι τώρα (χρειάζεται ένα ? με now_sql()) */
const DUR_SQL = 'COALESCE(e.duration, GREATEST(0, TIMESTAMPDIFF(SECOND, e.start_at, ?)))';

function entries_query(array $f): array
{
    [$where, $params] = entries_where($f);
    $order = $f['order'] ?? 'e.start_at DESC, e.id DESC';
    $limit = isset($f['limit']) ? ' LIMIT ' . (int)$f['limit'] . ' OFFSET ' . (int)($f['offset'] ?? 0) : '';
    $sql = "SELECT e.id, e.user_id, e.project_id, e.description, e.billable, e.start_at, e.end_at,
                   " . DUR_SQL . " AS dur,
                   (e.end_at IS NULL) AS running,
                   u.name AS user_name, u.avatar AS user_avatar,
                   p.name AS project_name, p.color AS project_color, p.client_id,
                   c.name AS client_name,
                   COALESCE(p.hourly_rate, 0) AS rate
            FROM tt_entries e
            LEFT JOIN tt_users u ON u.id = e.user_id
            LEFT JOIN tt_projects p ON p.id = e.project_id
            LEFT JOIN tt_clients c ON c.id = p.client_id
            WHERE {$where}
            ORDER BY {$order}{$limit}";
    $rows = q_all($sql, array_merge([now_sql()], $params));
    foreach ($rows as &$r) {
        $r['dur'] = (int)$r['dur'];
        $r['running'] = (int)$r['running'];
        $r['amount'] = $r['billable'] ? round($r['dur'] / 3600 * (float)$r['rate'], 2) : 0.0;
        $r['task_name'] = null;
    }
    unset($r);
    attach_tags($rows);
    return $rows;
}

/** Προσθέτει tag_names / tag_id_list με ένα query για όλες τις γραμμές */
function attach_tags(array &$rows): void
{
    $byId = [];
    foreach ($rows as $i => $r) { $byId[(int)$r['id']] = $i; $rows[$i]['tag_names'] = null; $rows[$i]['tag_id_list'] = []; }
    if (!$byId) return;
    foreach (array_chunk(array_keys($byId), 1000) as $chunk) {
        foreach (q_all('SELECT et.entry_id, et.tag_id, tg.name FROM tt_entry_tags et JOIN tt_tags tg ON tg.id = et.tag_id WHERE et.entry_id IN (' . in_ph($chunk) . ') ORDER BY tg.name', $chunk) as $t) {
            $i = $byId[(int)$t['entry_id']];
            $rows[$i]['tag_id_list'][] = (int)$t['tag_id'];
            $rows[$i]['tag_names'] = ($rows[$i]['tag_names'] === null ? '' : $rows[$i]['tag_names'] . ', ') . $t['name'];
        }
    }
}

/** Σύνολα στη βάση (χωρίς να φορτωθούν οι γραμμές) */
function report_totals(array $f): array
{
    [$where, $params] = entries_where($f);
    $r = q_one("SELECT COUNT(*) AS cnt,
                       COALESCE(SUM(d.dur), 0) AS dur,
                       COALESCE(SUM(CASE WHEN d.billable = 1 THEN d.dur ELSE 0 END), 0) AS bdur,
                       COALESCE(SUM(CASE WHEN d.billable = 1 THEN d.dur / 3600 * d.rate ELSE 0 END), 0) AS amount
                FROM (SELECT e.billable, " . DUR_SQL . " AS dur, COALESCE(p.hourly_rate, 0) AS rate
                      FROM tt_entries e LEFT JOIN tt_projects p ON p.id = e.project_id WHERE {$where}) d",
        array_merge([now_sql()], $params));
    return ['dur' => (int)$r['dur'], 'billable_dur' => (int)$r['bdur'], 'amount' => round((float)$r['amount'], 2), 'count' => (int)$r['cnt']];
}

/** [key, label, color, join] για μια διάσταση ομαδοποίησης */
function dim_sql(string $by, string $a): array
{
    return match ($by) {
        'project' => ["CONCAT('p', COALESCE(e.project_id, 0))", 'MIN(p.name)', 'MIN(p.color)', ''],
        'client' => ["CONCAT('c', COALESCE(p.client_id, 0))", 'MIN(c.name)', 'NULL', ''],
        'user' => ["CONCAT('u', e.user_id)", 'MIN(u.name)', 'NULL', ''],
        'group' => ["CONCAT('g', COALESCE(gm{$a}.group_id, 0))", "MIN(g{$a}.name)", "MIN(g{$a}.color)",
                    "LEFT JOIN tt_group_members gm{$a} ON gm{$a}.user_id = e.user_id LEFT JOIN tt_groups g{$a} ON g{$a}.id = gm{$a}.group_id"],
        'tag' => ["CONCAT('t', COALESCE(et{$a}.tag_id, 0))", "MIN(tg{$a}.name)", 'NULL',
                  "LEFT JOIN tt_entry_tags et{$a} ON et{$a}.entry_id = e.id LEFT JOIN tt_tags tg{$a} ON tg{$a}.id = et{$a}.tag_id"],
        'date' => ["CONCAT('d', DATE(e.start_at))", 'MIN(DATE(e.start_at))', 'NULL', ''],
        default => ["CONCAT('s', LOWER(TRIM(e.description)))", 'MIN(e.description)', 'NULL', ''],
    };
}

function dim_label(string $by, string $key, ?string $label): string
{
    $empty = in_array(substr($key, 1), ['0', ''], true);
    return match ($by) {
        'project' => $empty ? t('(Χωρίς project)') : (string)$label,
        'client' => $empty ? t('(Χωρίς πελάτη)') : (string)$label,
        'group' => $empty ? t('(Χωρίς ομάδα)') : (string)$label,
        'tag' => $empty ? t('(Χωρίς tag)') : (string)$label,
        'date' => fmt_day(new DateTimeImmutable((string)$label), true),
        'description' => trim((string)$label) === '' ? t('(Χωρίς περιγραφή)') : (string)$label,
        default => (string)$label,
    };
}

/**
 * Συγκεντρωτικά δύο επιπέδων υπολογισμένα στη βάση.
 * Ίδια μορφή με aggregate(): [key => [label, color, dur, amount, billable_dur, count, children => [...]]]
 */
function report_aggregate(array $f, string $by1, ?string $by2 = null): array
{
    [$where, $params] = entries_where($f);
    $run = function (array $dims) use ($where, $params): array {
        $sel = []; $joins = []; $grp = [];
        foreach ($dims as $i => $by) {
            [$k, $l, $c, $j] = dim_sql($by, (string)$i);
            $sel[] = "{$k} AS k{$i}, {$l} AS l{$i}, {$c} AS c{$i}";
            if ($j !== '') $joins[] = $j;
            $grp[] = "k{$i}";
        }
        // COUNT(DISTINCT) χρειάζεται μόνο όταν οι joins πολλαπλασιάζουν γραμμές (tags / ομάδες)
        $cnt = array_intersect($dims, ['tag', 'group']) ? 'COUNT(DISTINCT e.id)' : 'COUNT(*)';
        $sql = 'SELECT ' . implode(', ', $sel) . ',
                       ' . $cnt . ' AS cnt,
                       SUM(' . DUR_SQL . ') AS dur,
                       SUM(CASE WHEN e.billable = 1 THEN ' . DUR_SQL . ' ELSE 0 END) AS bdur,
                       SUM(CASE WHEN e.billable = 1 THEN ' . DUR_SQL . ' / 3600 * COALESCE(p.hourly_rate, 0) ELSE 0 END) AS amount
                FROM tt_entries e
                LEFT JOIN tt_users u ON u.id = e.user_id
                LEFT JOIN tt_projects p ON p.id = e.project_id
                LEFT JOIN tt_clients c ON c.id = p.client_id
                ' . implode(' ', $joins) . '
                WHERE ' . $where . '
                GROUP BY ' . implode(', ', $grp);
        $n = now_sql();
        return q_all($sql, array_merge([$n, $n, $n], $params));
    };
    $row = fn(array $r, string $by, int $i) => [
        'label' => dim_label($by, (string)$r["k{$i}"], $r["l{$i}"]),
        'color' => $r["c{$i}"] ?: ($by === 'project' || $by === 'group' ? '#b0bec5' : null),
        'dur' => (int)$r['dur'], 'amount' => round((float)$r['amount'], 2), 'billable_dur' => (int)$r['bdur'], 'count' => (int)$r['cnt'],
    ];
    $out = [];
    foreach ($run([$by1]) as $r) $out[(string)$r['k0']] = $row($r, $by1, 0) + ['children' => []];
    if ($by2) {
        foreach ($run([$by1, $by2]) as $r) {
            $k1 = (string)$r['k0'];
            if (isset($out[$k1])) $out[$k1]['children'][(string)$r['k1']] = $row($r, $by2, 1);
        }
    }
    $cmp = fn($a, $b) => $b['dur'] <=> $a['dur'];
    if ($by1 === 'date') ksort($out); else uasort($out, $cmp);
    foreach ($out as &$o) {
        if ($by2 === 'date') ksort($o['children']); else uasort($o['children'], $cmp);
    }
    return $out;
}

/** Σύνολα ανά ημέρα και project για το γράφημα, από τη βάση */
function report_daily(array $f, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $days = [];
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) $days[$d->format('Y-m-d')] = ['date' => $d, 'dur' => 0, 'parts' => []];
    [$where, $params] = entries_where($f);
    $rows = q_all('SELECT DATE(e.start_at) AS d, COALESCE(e.project_id, 0) AS pid, MIN(p.name) AS pname, MIN(p.color) AS pcolor, SUM(' . DUR_SQL . ') AS dur
                   FROM tt_entries e LEFT JOIN tt_projects p ON p.id = e.project_id
                   WHERE ' . $where . ' GROUP BY DATE(e.start_at), COALESCE(e.project_id, 0)', array_merge([now_sql()], $params));
    foreach ($rows as $r) {
        if (!isset($days[$r['d']])) continue;
        $days[$r['d']]['dur'] += (int)$r['dur'];
        $days[$r['d']]['parts'][(int)$r['pid']] = ['dur' => (int)$r['dur'], 'color' => $r['pcolor'] ?? '#b0bec5', 'label' => $r['pname'] ?? t('(Χωρίς project)')];
    }
    return $days;
}

/**
 * Διαβάζει καταχωρήσεις μία-μία χωρίς να τις κρατά όλες στη μνήμη (για CSV).
 * Καλεί $fn($row) για κάθε γραμμή.
 */
function entries_stream(array $f, callable $fn): void
{
    [$where, $params] = entries_where($f);
    $sql = "SELECT e.id, e.description, e.billable, e.start_at, e.end_at, " . DUR_SQL . " AS dur,
                   u.name AS user_name, p.name AS project_name, c.name AS client_name, COALESCE(p.hourly_rate, 0) AS rate,
                   (SELECT GROUP_CONCAT(tg.name ORDER BY tg.name SEPARATOR ', ') FROM tt_entry_tags et JOIN tt_tags tg ON tg.id = et.tag_id WHERE et.entry_id = e.id) AS tag_names
            FROM tt_entries e
            LEFT JOIN tt_users u ON u.id = e.user_id
            LEFT JOIN tt_projects p ON p.id = e.project_id
            LEFT JOIN tt_clients c ON c.id = p.client_id
            WHERE {$where}
            ORDER BY e.start_at, e.id";
    $pdo = db();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([now_sql()], $params));
        while ($r = $st->fetch()) {
            $r['dur'] = (int)$r['dur'];
            $r['amount'] = $r['billable'] ? round($r['dur'] / 3600 * (float)$r['rate'], 2) : 0.0;
            $fn($r);
        }
        $st->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
}

/** Δεδομένα που χρειάζεται το JS για τη φόρμα καταχώρησης */
function entry_json(array $r): string
{
    $s = new DateTimeImmutable($r['start_at']);
    $e = $r['end_at'] ? new DateTimeImmutable($r['end_at']) : null;
    return json_encode([
        'id' => (int)$r['id'],
        'description' => $r['description'],
        'project_id' => $r['project_id'] ? (int)$r['project_id'] : '',
        'project_name' => (string)($r['project_name'] ?? ''), // για projects που δεν είναι στη λίστα (π.χ. αρχειοθετημένα)
        'billable' => (int)$r['billable'],
        'tags' => $r['tag_id_list'] ?? [],
        'date' => $s->format('Y-m-d'),
        'start' => $s->format('H:i'),
        'end' => $e ? $e->format('H:i') : '',
        'running' => $e ? 0 : 1,
        'user' => $r['user_name'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * Φίλτρο "εμβέλειας": admin ό,τι ζητήσει, manager μόνο την ομάδα του, μέλος μόνο τον εαυτό του.
 * Κενό αποτέλεσμα για admin σημαίνει "όλοι". [0] σημαίνει "κανείς".
 */
function scope_user_ids(array $user, array $requested): array
{
    $team = team_user_ids($user);
    if ($team === null) return $requested;
    if (!$requested || count($team) === 1) return $team; // το μέλος βλέπει πάντα μόνο τον εαυτό του
    $ok = array_values(array_intersect(array_map('intval', $requested), $team));
    return $ok ?: [0];
}

/**
 * Ποιες καταχωρήσεις φαίνονται σε προβολές ομάδας (αναφορές, dashboard, ημερολόγιο άλλου).
 * Κάθε καταχώρηση ανήκει στην ομάδα του project της. Admin: η ομάδα που έχει επιλέξει. Manager: η ομάδα του, χωρίς τον admin.
 */
function scope_filter(array $user): array
{
    if (is_admin($user)) {
        $d = current_dept($user);
        return $d ? ['dept_id' => $d] : [];
    }
    $gid = managed_group_id($user);
    return $gid ? ['dept_id' => $gid, 'exclude_admins' => true] : [];
}

/** ids των admin (ο χρόνος τους δεν φαίνεται στους managers) */
function admin_ids(): array
{
    static $ids = null;
    return $ids ??= array_map('intval', array_column(q_all("SELECT id FROM tt_users WHERE role = 'admin'"), 'id'));
}

/** Αφαιρεί τους admin από λίστα ατόμων [pid => [['user_id'=>..], ...]] */
function contributors_without_admins(array $byProject): array
{
    $adm = admin_ids();
    foreach ($byProject as $pid => $list) {
        $byProject[$pid] = array_values(array_filter($list, fn($x) => !in_array((int)$x['user_id'], $adm, true)));
    }
    return $byProject;
}

/** Η ομάδα μιας καταχώρησης: του project της, αλλιώς του χρήστη της */
function entry_group_id(array $e): ?int
{
    if (!empty($e['project_id'])) {
        $g = q_val('SELECT group_id FROM tt_projects WHERE id = ?', [(int)$e['project_id']]);
        if ($g !== null) return (int)$g;
    }
    if (!empty($e['group_id'])) return (int)$e['group_id'];
    return user_group_id((int)$e['user_id']);
}

/** Μπορεί ο χρήστης να δει/αλλάξει αυτή την καταχώρηση; Δική του· ο admin όλες· ο manager της ομάδας του (όχι του admin) */
function can_manage_entry(array $user, array $e): bool
{
    if ((int)$e['user_id'] === (int)$user['id'] || is_admin($user)) return true;
    $gid = managed_group_id($user);
    if ($gid === null) return false;
    if (q_val("SELECT 1 FROM tt_users WHERE id = ? AND role = 'admin'", [(int)$e['user_id']])) return false;
    return entry_group_id($e) === $gid;
}

/** Χρήστες που βλέπει ο χρήστης (για λίστες επιλογής): admin όλους, manager την ομάδα του */
function team_users(array $user, bool $activeOnly = true): array
{
    $ids = team_user_ids($user);
    if ($ids === null) return all_users($activeOnly);
    return q_all('SELECT id, name, email, role, active, hourly_rate FROM tt_users WHERE id IN (' . in_ph($ids) . ')'
        . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY name', $ids);
}

/** Admin: όλα τα projects. Manager: όσα ανήκουν στην ομάδα του. */
function can_edit_project(array $user, ?array $p): bool
{
    if (is_admin($user)) return true;
    $gid = managed_group_id($user);
    if ($gid === null) return false;
    return $p === null || (int)($p['group_id'] ?? 0) === $gid;
}

/* ---------- Ομαδοποίηση για αναφορές ---------- */

const GROUP_BY_OPTIONS = [
    'project' => 'Project',
    'user' => 'Χρήστης',
    'group' => 'Ομάδα',
    'tag' => 'Tag',
    'date' => 'Ημερομηνία',
    'description' => 'Περιγραφή',
];

/**
 * Επιστρέφει λίστα [key, label, color] για μια καταχώρηση και ένα κριτήριο.
 * (Μπορεί να είναι πολλά για tags / ομάδες.)
 */
function group_keys(array $r, string $by, array $userGroups, array $groupsById): array
{
    switch ($by) {
        case 'project':
            return [[$r['project_id'] ? 'p' . $r['project_id'] : 'p0', $r['project_name'] ?? t('(Χωρίς project)'), $r['project_color'] ?? '#b0bec5']];
        case 'client':
            return [[$r['client_id'] ? 'c' . $r['client_id'] : 'c0', $r['client_name'] ?? t('(Χωρίς πελάτη)'), null]];
        case 'user':
            return [['u' . $r['user_id'], $r['user_name'], null]];
        case 'group':
            $gs = $userGroups[(int)$r['user_id']] ?? [];
            if (!$gs) return [['g0', t('(Χωρίς ομάδα)'), '#b0bec5']];
            return array_map(fn($gid) => ['g' . $gid, $groupsById[$gid]['name'] ?? '?', $groupsById[$gid]['color'] ?? null], $gs);
        case 'tag':
            if (!$r['tag_names']) return [['t0', t('(Χωρίς tag)'), null]];
            $names = explode(', ', $r['tag_names']);
            return array_map(fn($n) => ['t' . mb_strtolower($n), $n, null], $names);
        case 'task':
            return [[$r['task_id'] ? 'k' . $r['task_id'] : 'k0', $r['task_name'] ?? t('(Χωρίς task)'), null]];
        case 'date':
            $d = new DateTimeImmutable($r['start_at']);
            return [['d' . $d->format('Y-m-d'), fmt_day($d, true), null]];
        case 'description':
            $desc = trim((string)$r['description']);
            return [['s' . mb_strtolower($desc), $desc === '' ? t('(Χωρίς περιγραφή)') : $desc, null]];
    }
    return [['all', t('Σύνολο'), null]];
}

/** Χάρτες για ομαδοποίηση ανά ομάδα */
function group_lookup(): array
{
    $groupsById = [];
    foreach (q_all('SELECT id, name, color FROM tt_groups') as $g) $groupsById[(int)$g['id']] = $g;
    $userGroups = [];
    foreach (q_all('SELECT user_id, group_id FROM tt_group_members') as $m) $userGroups[(int)$m['user_id']][] = (int)$m['group_id'];
    return [$userGroups, $groupsById];
}

/**
 * Συγκεντρωτικά δύο επιπέδων.
 * Επιστρέφει [ key => [label, color, dur, amount, billable_dur, children => [...]] ] ταξινομημένα κατά διάρκεια.
 */
function aggregate(array $rows, string $by1, ?string $by2 = null): array
{
    [$userGroups, $groupsById] = group_lookup();
    $out = [];
    foreach ($rows as $r) {
        foreach (group_keys($r, $by1, $userGroups, $groupsById) as [$k1, $l1, $c1]) {
            $out[$k1] ??= ['label' => $l1, 'color' => $c1, 'dur' => 0, 'amount' => 0.0, 'billable_dur' => 0, 'count' => 0, 'children' => []];
            $out[$k1]['dur'] += $r['dur'];
            $out[$k1]['amount'] += $r['amount'];
            $out[$k1]['count']++;
            if ($r['billable']) $out[$k1]['billable_dur'] += $r['dur'];
            if ($by2) {
                foreach (group_keys($r, $by2, $userGroups, $groupsById) as [$k2, $l2, $c2]) {
                    $out[$k1]['children'][$k2] ??= ['label' => $l2, 'color' => $c2, 'dur' => 0, 'amount' => 0.0, 'billable_dur' => 0, 'count' => 0];
                    $out[$k1]['children'][$k2]['dur'] += $r['dur'];
                    $out[$k1]['children'][$k2]['amount'] += $r['amount'];
                    $out[$k1]['children'][$k2]['count']++;
                    if ($r['billable']) $out[$k1]['children'][$k2]['billable_dur'] += $r['dur'];
                }
            }
        }
    }
    $sort = function (array &$arr) use ($by1) {
        if ($by1 === 'date') { ksort($arr); return; }
        uasort($arr, fn($a, $b) => $b['dur'] <=> $a['dur']);
    };
    $sort($out);
    foreach ($out as &$o) {
        if ($o['children']) {
            if ($by2 === 'date') ksort($o['children']);
            else uasort($o['children'], fn($a, $b) => $b['dur'] <=> $a['dur']);
        }
    }
    return $out;
}

/** Σύνολα ανά ημέρα για το γράφημα, με στοίβαξη ανά project */
function daily_series(array $rows, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $days = [];
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
        $days[$d->format('Y-m-d')] = ['date' => $d, 'dur' => 0, 'parts' => []];
    }
    foreach ($rows as $r) {
        $k = substr($r['start_at'], 0, 10);
        if (!isset($days[$k])) continue;
        $days[$k]['dur'] += $r['dur'];
        $pk = $r['project_id'] ?: 0;
        $days[$k]['parts'][$pk] ??= ['dur' => 0, 'color' => $r['project_color'] ?? '#b0bec5', 'label' => $r['project_name'] ?? t('(Χωρίς project)')];
        $days[$k]['parts'][$pk]['dur'] += $r['dur'];
    }
    return $days;
}

function totals(array $rows): array
{
    $t = ['dur' => 0, 'billable_dur' => 0, 'amount' => 0.0, 'count' => count($rows)];
    foreach ($rows as $r) {
        $t['dur'] += $r['dur'];
        if ($r['billable']) $t['billable_dur'] += $r['dur'];
        $t['amount'] += $r['amount'];
    }
    return $t;
}

/* ---------- Budget ---------- */

/**
 * Χρόνος ανά project: [pid => ['total' => sec, 'month' => sec]] (μετράει και ό,τι τρέχει).
 * $onlyBudget: μόνο για projects με budget (αρκεί για ειδοποιήσεις και είναι πολύ πιο γρήγορο).
 * Κρατιέται στη μνήμη για όλο το αίτημα.
 */
function project_usage(bool $onlyBudget = false): array
{
    static $cache = [];
    $ck = $onlyBudget ? 'b' : 'all';
    if (isset($cache[$ck])) return $cache[$ck];
    if ($onlyBudget && isset($cache['all'])) return $cache['all'];
    $now = now_sql();
    $monthStart = now()->modify('first day of this month')->format('Y-m-d 00:00:00');
    // Ολοκληρωμένες: SUM(duration) από το covering index· όσα τρέχουν: ξεχωριστά (είναι ελάχιστα)
    $filter = $onlyBudget ? ' AND e.project_id IN (SELECT id FROM tt_projects WHERE budget_hours > 0)' : '';
    $out = [];
    foreach (q_all("SELECT e.project_id, SUM(e.duration) AS total,
                           SUM(CASE WHEN e.start_at >= ? THEN e.duration ELSE 0 END) AS month
                    FROM tt_entries e WHERE e.project_id IS NOT NULL AND e.duration IS NOT NULL{$filter}
                    GROUP BY e.project_id", [$monthStart]) as $r) {
        $out[(int)$r['project_id']] = ['total' => (int)$r['total'], 'month' => (int)$r['month']];
    }
    foreach (q_all("SELECT e.project_id, e.start_at, GREATEST(0, TIMESTAMPDIFF(SECOND, e.start_at, ?)) AS secs
                    FROM tt_entries e WHERE e.end_at IS NULL AND e.project_id IS NOT NULL{$filter}", [$now]) as $r) {
        $pid = (int)$r['project_id'];
        $out[$pid] ??= ['total' => 0, 'month' => 0];
        $out[$pid]['total'] += (int)$r['secs'];
        if ($r['start_at'] >= $monthStart) $out[$pid]['month'] += (int)$r['secs'];
    }
    return $cache[$ck] = $out;
}

/**
 * Κατάσταση budget ενός project ή null αν δεν έχει budget.
 * state: ok (<80%), warn (80–99%), over (>=100%)
 */
/** Χρήση ενός μόνο project (για έλεγχο ειδοποιήσεων μετά από αλλαγή) */
function project_usage_for(int $pid): array
{
    $monthStart = now()->modify('first day of this month')->format('Y-m-d 00:00:00');
    $r = q_one('SELECT COALESCE(SUM(' . DUR_SQL . '), 0) AS total,
                       COALESCE(SUM(CASE WHEN e.start_at >= ? THEN ' . DUR_SQL . ' ELSE 0 END), 0) AS month
                FROM tt_entries e WHERE e.project_id = ?', [now_sql(), $monthStart, now_sql(), $pid]);
    return [$pid => ['total' => (int)$r['total'], 'month' => (int)$r['month']]];
}

function budget_status(array $p, array $usage): ?array
{
    $hours = $p['budget_hours'] !== null ? (float)$p['budget_hours'] : 0.0;
    if ($hours <= 0) return null;
    $period = ($p['budget_period'] ?? 'total') === 'month' ? 'month' : 'total';
    $used = $usage[(int)$p['id']][$period] ?? 0;
    $budget = (int)round($hours * 3600);
    $pct = $used / $budget * 100;
    return [
        'used' => $used,
        'budget' => $budget,
        'pct' => $pct,
        'over' => max(0, $used - $budget),
        'left' => max(0, $budget - $used),
        'period' => $period,
        'state' => $pct >= 100 ? 'over' : ($pct >= 80 ? 'warn' : 'ok'),
    ];
}

/* ---------- Όρια ανά tag μέσα σε project ---------- */

/** Γραμμές ορίου ανά tag (όλες ή ενός project), με το όνομα του tag. budget_hours NULL = μόνο μέτρηση ωρών. */
function tag_budgets(?int $projectId = null): array
{
    static $cache = [];
    $ck = $projectId ?? 0;
    if (isset($cache[$ck])) return $cache[$ck];
    $rows = q_all('SELECT b.*, t.name AS tag_name FROM tt_project_tag_budgets b JOIN tt_tags t ON t.id = b.tag_id'
        . ($projectId ? ' WHERE b.project_id = ?' : '') . ' ORDER BY t.name', $projectId ? [$projectId] : []);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['project_id']][] = $r;
    return $cache[$ck] = $projectId ? ($out[$projectId] ?? []) : $out;
}

/**
 * Χρόνος του τρέχοντος «πακέτου» μιας γραμμής tag, σε SQL.
 * Μετράει μόνο από το b.start_at και μετά (NULL = από την αρχή). Μια καταχώρηση που ξεκίνησε πριν
 * και τελειώνει (ή τρέχει) μετά, μετράει μόνο το κομμάτι μετά το start_at.
 * Θέλει 3 παραμέτρους «τώρα»: SELECT (2) + WHERE (1).
 */
const TAG_PKG_SECS_SQL = "SUM(CASE WHEN b.start_at IS NULL OR e.start_at >= b.start_at
        THEN (CASE WHEN e.end_at IS NULL THEN GREATEST(0, TIMESTAMPDIFF(SECOND, e.start_at, ?)) ELSE e.duration END)
        ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, b.start_at, COALESCE(e.end_at, ?))) END)";
const TAG_PKG_WHERE_SQL = "(b.start_at IS NULL OR COALESCE(e.end_at, ?) > b.start_at)";

/**
 * Χρόνος ανά project + tag, μόνο για τα ζεύγη που έχουν γραμμή ορίου: [project_id][tag_id] => δευτερόλεπτα.
 * Είναι ο χρόνος του τρέχοντος πακέτου (από το «Μετράει από») + ό,τι μεταφέρθηκε από το προηγούμενο.
 */
function tag_budget_usage(?int $projectId = null): array
{
    static $cache = [];
    $ck = $projectId ?? 0;
    if (isset($cache[$ck])) return $cache[$ck];
    $pf = $projectId ? ' AND e.project_id = ?' : '';
    $pp = $projectId ? [$projectId] : [];
    $now = now_sql();
    $out = [];
    foreach (q_all("SELECT e.project_id, et.tag_id, " . TAG_PKG_SECS_SQL . " AS secs
                    FROM tt_entries e
                    JOIN tt_entry_tags et ON et.entry_id = e.id
                    JOIN tt_project_tag_budgets b ON b.project_id = e.project_id AND b.tag_id = et.tag_id
                    WHERE e.project_id IS NOT NULL AND " . TAG_PKG_WHERE_SQL . "{$pf}
                    GROUP BY e.project_id, et.tag_id", array_merge([$now, $now, $now], $pp)) as $r) {
        $out[(int)$r['project_id']][(int)$r['tag_id']] = (int)$r['secs'];
    }
    // ό,τι πέρασε πάνω από το όριο στο προηγούμενο πακέτο
    $lines = $projectId ? [$projectId => tag_budgets($projectId)] : tag_budgets();
    foreach ($lines as $pid => $ls) {
        foreach ($ls as $l) {
            $carry = (int)($l['carry_secs'] ?? 0);
            if ($carry > 0) $out[(int)$pid][(int)$l['tag_id']] = ($out[(int)$pid][(int)$l['tag_id']] ?? 0) + $carry;
        }
    }
    return $cache[$ck] = $out;
}

/** Χρόνος του τρέχοντος πακέτου μίας γραμμής, φρέσκος από τη βάση (χωρίς cache), μαζί με τη μεταφορά */
function tag_line_usage_secs(array $line, string $now): int
{
    $secs = (int)q_val("SELECT COALESCE(" . TAG_PKG_SECS_SQL . ", 0)
                        FROM tt_entries e
                        JOIN tt_entry_tags et ON et.entry_id = e.id
                        JOIN tt_project_tag_budgets b ON b.project_id = e.project_id AND b.tag_id = et.tag_id
                        WHERE b.id = ? AND " . TAG_PKG_WHERE_SQL, [$now, $now, (int)$line['id'], $now]);
    return $secs + (int)($line['carry_secs'] ?? 0);
}

/**
 * «Νέο πακέτο»: κλείνει το τρέχον πακέτο μιας γραμμής tag και ξεκινά καινούργιο από τώρα.
 * Ό,τι πέρασε πάνω από το όριο μεταφέρεται στο νέο πακέτο (π.χ. 05:30 από 05:00 -> ξεκινά από 00:30).
 * Οι καταχωρήσεις χρόνου δεν αλλάζουν. Επιστρέφει [used, carry] σε δευτερόλεπτα.
 */
function reset_tag_budget(int $lineId, int $byUid): ?array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $line = q_one('SELECT * FROM tt_project_tag_budgets WHERE id = ? FOR UPDATE', [$lineId]);
        if (!$line) { $pdo->rollBack(); return null; }
        $now = now_sql();
        $used = tag_line_usage_secs($line, $now);
        $budget = $line['budget_hours'] !== null ? (int)round((float)$line['budget_hours'] * 3600) : 0;
        // Μεταφορά υπέρβασης μόνο όταν το πακέτο που κλείνει είχε αρχή (ημερομηνία ή προηγούμενο «Νέο πακέτο»).
        // Πακέτο που μετρούσε «από την αρχή» (όλο το παλιό ιστορικό) ξεκινά καθαρά από 00:00.
        $carry = ($budget > 0 && $line['start_at'] !== null) ? max(0, $used - $budget) : 0;
        q('INSERT INTO tt_tag_budget_history (project_id, tag_id, package_no, budget_hours, start_at, end_at, carry_in_secs, used_secs, carry_out_secs, reset_by, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int)$line['project_id'], (int)$line['tag_id'], (int)$line['package_no'], $line['budget_hours'], $line['start_at'], $now,
             (int)$line['carry_secs'], $used, $carry, $byUid, $now]);
        q('UPDATE tt_project_tag_budgets SET start_at = ?, carry_secs = ?, package_no = package_no + 1, alert_key = NULL, alert_level = 0 WHERE id = ?',
            [$now, $carry, $lineId]);
        $pdo->commit();
        return ['used' => $used, 'carry' => $carry];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Ιστορικό πακέτων ενός project: [tag_id => [γραμμές, νεότερη πρώτη]] */
function tag_budget_history(int $projectId): array
{
    $out = [];
    foreach (q_all('SELECT h.*, u.name AS by_name FROM tt_tag_budget_history h LEFT JOIN tt_users u ON u.id = h.reset_by
                    WHERE h.project_id = ? ORDER BY h.id DESC', [$projectId]) as $r) {
        $out[(int)$r['tag_id']][] = $r;
    }
    return $out;
}

/** Μια γραμμή tag σαν «project» για τα μηνύματα/ειδοποιήσεις: «Northwind · support», πάντα συνολικό όριο */
function tag_budget_item(array $p, array $line): array
{
    return array_merge($p, [
        'name' => $p['name'] . ' · ' . $line['tag_name'],
        'project_name' => $p['name'],
        'tag_id' => (int)$line['tag_id'],
        'tag_name' => $line['tag_name'],
        'line_id' => (int)$line['id'],
        'tag_start_at' => $line['start_at'] ?? null,   // νέο πακέτο = νέες ειδοποιήσεις
        'budget_hours' => $line['budget_hours'],
        'budget_period' => 'total',
        'budget_alert_key' => $line['alert_key'],
        'budget_alert_level' => (int)$line['alert_level'],
    ]);
}

/** Κατάσταση ορίου μιας γραμμής tag (null αν δεν έχει όριο, μόνο μέτρηση) */
function tag_budget_status(array $line, array $tusage): ?array
{
    $pid = (int)$line['project_id'];
    $secs = $tusage[$pid][(int)$line['tag_id']] ?? 0;
    return budget_status(['id' => $pid, 'budget_hours' => $line['budget_hours'], 'budget_period' => 'total'], [$pid => ['total' => $secs, 'month' => $secs]]);
}

function budget_color(string $state): string
{
    return ['ok' => 'success', 'warn' => 'warning', 'over' => 'danger'][$state] ?? 'secondary';
}

/** Ώρες ορίου / χρόνου σε μορφή ώρες:λεπτά, π.χ. 5 -> "05:00", 54.66 -> "54:40" */
function budget_hours_label(float $hours): string
{
    return fmt_hm($hours * 3600);
}

/** Κείμενο για projects κοντά/πάνω από το budget (για προειδοποιήσεις στο UI και email) */
function budget_message(array $p, array $st): string
{
    $b = hours_word(budget_hours_label($st['budget'] / 3600));
    $u = hours_word(budget_hours_label($st['used'] / 3600));
    $month = $st['period'] === 'month';
    if ($st['state'] === 'over') {
        $x = hours_word(budget_hours_label($st['over'] / 3600));
        return $month
            ? t('{p}: όριο {b} τον μήνα. Αυτόν τον μήνα καταγράφηκαν {u}, δηλαδή {x} πάνω από το όριο.', ['p' => $p['name'], 'b' => $b, 'u' => $u, 'x' => $x])
            : t('{p}: όριο {b} συνολικά. Καταγράφηκαν {u}, δηλαδή {x} πάνω από το όριο.', ['p' => $p['name'], 'b' => $b, 'u' => $u, 'x' => $x]);
    }
    $x = hours_word(budget_hours_label($st['left'] / 3600));
    return $month
        ? t('{p}: όριο {b} τον μήνα. Αυτόν τον μήνα καταγράφηκαν {u} ({pct}) και απομένουν {x}.', ['p' => $p['name'], 'b' => $b, 'u' => $u, 'pct' => fmt_pct($st['pct']), 'x' => $x])
        : t('{p}: όριο {b} συνολικά. Καταγράφηκαν {u} ({pct}) και απομένουν {x}.', ['p' => $p['name'], 'b' => $b, 'u' => $u, 'pct' => fmt_pct($st['pct']), 'x' => $x]);
}

/** Report ενός project για μια περίοδο, ομαδοποιημένο ανά άτομο (για admin/manager) */
function project_report_url(int $projectId, DateTimeInterface $from, DateTimeInterface $to): string
{
    return 'reports.php?' . http_build_query(['tab' => 'summary', 'project' => $projectId, 'by1' => 'user', 'by2' => 'date',
        'preset' => 'custom', 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
}

/** Όριο / καταγράφηκαν / παραπάνω ή απομένουν, με μπάρα που δείχνει πού είναι το όριο */
function budget_meter(array $st): string
{
    $over = $st['state'] === 'over';
    $hw = fn(int $secs) => hours_word(budget_hours_label($secs / 3600));
    $scale = max(1, $over ? $st['used'] : $st['budget']);          // πάνω από το όριο: η μπάρα είναι όσο οι ώρες που γράφτηκαν
    $limitPos = round($st['budget'] / $scale * 100, 2);
    $fill = round(min($st['used'], $st['budget']) / $scale * 100, 2);
    $per = $st['period'] === 'month' ? t('τον μήνα') : t('συνολικά');
    $html = '<div class="limit-figures">'
        . '<div><span>' . h(t('Όριο')) . '</span><strong>' . h($hw($st['budget'])) . '</strong><small>' . h($per) . '</small></div>'
        . '<div><span>' . h(t('Καταγράφηκαν')) . '</span><strong>' . h($hw($st['used'])) . '</strong>' . ($over ? '' : '<small>' . h(fmt_pct($st['pct'])) . '</small>') . '</div>'
        . ($over
            ? '<div class="is-over"><span>' . h(t('Παραπάνω')) . '</span><strong>+' . h($hw($st['over'])) . '</strong></div>'
            : '<div><span>' . h(t('Απομένουν')) . '</span><strong>' . h($hw($st['left'])) . '</strong></div>')
        . '</div>';
    $html .= '<div class="limit-meter is-' . h($st['state']) . '" role="img" aria-label="' . h(t('Όριο {b}, καταγράφηκαν {u}', ['b' => $hw($st['budget']), 'u' => $hw($st['used'])])) . '">'
        . '<span class="lm-in" style="width:' . $fill . '%"></span>'
        . ($over ? '<span class="lm-over" style="left:' . $limitPos . '%;width:' . round(100 - $limitPos, 2) . '%"></span>' : '')
        . '<span class="lm-mark" style="left:' . $limitPos . '%"></span></div>';
    $html .= '<div class="limit-scale"><span>0</span>'
        . '<span class="lm-label" style="left:' . $limitPos . '%">' . h(t('όριο')) . '</span>'
        . ($over ? '<span class="ms-auto">' . h($hw($st['used'])) . '</span>' : '')
        . '</div>';
    return $html;
}

/**
 * Όρια ανά tag (μόνο όσα έχουν όριο ωρών) για τα projects που βλέπει ο χρήστης:
 * [project_id => [tag_id => ['tag_name', 'start_at', 'status']]]. Για τη χρονομέτρηση.
 */
function tag_limits_for(array $user): array
{
    $lines = tag_budgets();
    if (!$lines) return [];
    $tusage = tag_budget_usage();
    $out = [];
    foreach (visible_projects($user) as $p) {
        foreach ($lines[(int)$p['id']] ?? [] as $line) {
            $st = tag_budget_status($line, $tusage);
            if ($st) $out[(int)$p['id']][(int)$line['tag_id']] = ['tag_name' => $line['tag_name'], 'start_at' => $line['start_at'], 'status' => $st];
        }
    }
    return $out;
}

/** Projects σε warn/over, για όσα βλέπει ο χρήστης */
function budget_alerts(array $user): array
{
    static $memo = [];
    if (isset($memo[(int)$user['id']])) return $memo[(int)$user['id']];
    $out = [];
    // όρια ανά tag (το όριο όλου του project καταργήθηκε): κλειδί «project:ttag», π.χ. «12:t3»
    $lines = tag_budgets();
    if ($lines) {
        $tusage = tag_budget_usage();
        foreach (visible_projects($user) as $p) {
            foreach ($lines[(int)$p['id']] ?? [] as $line) {
                $st = tag_budget_status($line, $tusage);
                if ($st && $st['state'] !== 'ok') $out[(int)$p['id'] . ':t' . (int)$line['tag_id']] = ['project' => tag_budget_item($p, $line), 'status' => $st];
            }
        }
    }
    uasort($out, fn($a, $b) => $b['status']['pct'] <=> $a['status']['pct']);
    return $memo[(int)$user['id']] = $out;
}

/** Κάθε πόσα δευτερόλεπτα πάνω από το όριο έρχεται νέα ειδοποίηση (0 = μόνο μία φορά). Ρύθμιση ανά ομάδα (admin/manager). */
function budget_repeat_secs(?int $groupId = null): int
{
    $v = $groupId ? setting('budget_repeat_hours_g' . $groupId) : null;
    $h = (float)str_replace(',', '.', (string)($v ?? setting('budget_repeat_hours', '1')));
    return max(0, (int)round($h * 3600));
}

/**
 * Επίπεδο ορίου: 0 = εντάξει, 80 = κοντά, 100 = ξεπέρασε,
 * 101, 102… = ξεπέρασε κατά 1, 2… «βήματα» (π.χ. κάθε 1 ώρα επιπλέον).
 */
function budget_level(?array $st, ?int $groupId = null): int
{
    if (!$st || $st['state'] === 'ok') return 0;
    if ($st['state'] === 'warn') return 80;
    $step = budget_repeat_secs($groupId);
    return 100 + ($step > 0 ? min(60000, intdiv((int)$st['over'], $step)) : 0);
}

/** Οι επιπλέον ώρες που αντιστοιχούν σε ένα επίπεδο (π.χ. 102 με βήμα 1 ώρα = 2) */
function budget_level_extra_hours(int $level, ?int $groupId = null): float
{
    return $level > 100 ? ($level - 100) * budget_repeat_secs($groupId) / 3600 : 0.0;
}

/**
 * Μετά από αλλαγή χρόνου: ειδοποίηση στο 80%, στο 100% και μετά σε κάθε «βήμα» πάνω από το όριο.
 * Email (αν είναι ενεργό στο project) μόνο στο 80% και στο 100%.
 */
function check_budget_alerts(?int $projectId, ?int $byUid = null): void
{
    if (!$projectId) return;
    // ποιος «το έκανε»: ο χρήστης που ο χρόνος του πέρασε το όριο (προεπιλογή: όποιος κάνει την ενέργεια)
    $byUid ??= current_user() ? (int)current_user()['id'] : null;
    try {
        $p = q_one('SELECT * FROM tt_projects WHERE id = ?', [$projectId]);
        if (!$p) return;
        // όρια ανά tag του project (πάντα συνολικά). Καμπανάκι πάντα, email μόνο στο 80% / 100% αν είναι ενεργό.
        $lines = tag_budgets($projectId);
        if ($lines) {
            $tusage = tag_budget_usage($projectId);
            foreach ($lines as $line) {
                $tst = tag_budget_status($line, $tusage);
                $tprev = $line['alert_key'] === 'total' ? (int)$line['alert_level'] : 0;
                $tlevel = budget_level($tst, isset($p['group_id']) ? (int)$p['group_id'] : null);
                if ($tlevel === $tprev && $line['alert_key'] === 'total') continue;
                q('UPDATE tt_project_tag_budgets SET alert_key = ?, alert_level = ? WHERE id = ?', ['total', $tlevel, (int)$line['id']]);
                if ($tlevel > $tprev) {
                    $item = tag_budget_item($p, $line);
                    notify_budget($item, $tst, $tlevel, $byUid);
                    // Ειδοποίηση μόνο στο καμπανάκι της εφαρμογής· τα email ορίων καταργήθηκαν (v1.9.24)
                }
            }
        }
    } catch (Throwable $e) {
        error_log('budget alert: ' . $e->getMessage());
    }
}

function send_budget_email(array $p, array $st): void
{
    global $CONFIG;
    $url = app_url('projects.php');
    $curLang = lang();
    // admins + οι managers της ομάδας που έχει το project
    $to = q_all("SELECT name, email, lang FROM tt_users WHERE role = 'admin' AND active = 1");
    if (!empty($p['group_id'])) foreach (group_managers((int)$p['group_id']) as $m) $to[] = $m;
    foreach ($to as $a) {
        set_lang($a['lang'] ?: ($CONFIG['default_lang'] ?? 'el'));
        $subject = ($st['state'] === 'over' ? '⚠ ' : '') . t('Όριο ωρών: {p}', ['p' => $p['name']]);
        $body = t('Γεια σου {n},', ['n' => $a['name']]) . "\n\n" . budget_message($p, $st) . "\n\n"
            . $url . "\n\n— " . app_name();
        send_mail((string)$a['email'], (string)$a['name'], $subject, $body, budget_email_html($p, $st, $a, $url));
    }
    set_lang($curLang);
}

/** Το σχεδιασμένο email ορίου ωρών: κατάσταση, τρία νούμερα και μπάρα */
function budget_email_html(array $p, array $st, array $to, string $url): string
{
    $over = $st['state'] === 'over';
    $hw = fn(int $secs) => hours_word(budget_hours_label($secs / 3600));
    $f = MAIL_FONT;
    $red = '#dc2626'; $amber = '#d97706';
    $badge = $over
        ? '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fee2e2;color:#b91c1c;font-family:' . $f . ';font-size:12px;font-weight:700">' . h(t('Πάνω από το όριο')) . '</span>'
        : '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-family:' . $f . ';font-size:12px;font-weight:700">' . h(t('Κοντά στο όριο')) . '</span>';
    $cell = fn(string $label, string $value, string $sub, string $color = MAIL_INK) =>
        '<td valign="top" style="padding:14px 16px;width:33%">'
        . '<div style="font-family:' . $f . ';font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . MAIL_DIM . '">' . h($label) . '</div>'
        . '<div style="font-family:' . $f . ';font-size:20px;font-weight:700;color:' . $color . ';padding-top:4px;white-space:nowrap">' . h($value) . '</div>'
        . '<div style="font-family:' . $f . ';font-size:12px;color:' . MAIL_DIM . ';padding-top:2px">' . h($sub) . '</div></td>';
    $per = $st['period'] === 'month' ? t('τον μήνα') : t('συνολικά');
    $figures = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid ' . MAIL_LINE . ';border-radius:8px;background:#f9fafb"><tr>'
        . $cell(t('Όριο'), $hw($st['budget']), $per)
        . $cell(t('Καταγράφηκαν'), $hw($st['used']), $over ? '' : fmt_pct($st['pct']))
        . ($over ? $cell(t('Παραπάνω'), '+' . $hw($st['over']), '', $red) : $cell(t('Απομένουν'), $hw($st['left']), ''))
        . '</tr></table>';
    // Μπάρα: εντός ορίου / πάνω από το όριο (πάνω από το όριο: όλη η μπάρα είναι οι ώρες που γράφτηκαν)
    $scale = max(1, $over ? $st['used'] : $st['budget']);
    $a = max(1, min(100, (int)round(min($st['used'], $st['budget']) / $scale * 100)));
    $b = 100 - $a;
    $bar = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:16px"><tr>'
        . '<td width="' . $a . '%" bgcolor="' . ($over ? '#9ca3af' : $amber) . '" style="height:10px;line-height:10px;font-size:0;background:' . ($over ? '#9ca3af' : $amber) . ';border-radius:5px 0 0 5px' . ($b ? '' : ';border-radius:5px') . '">&nbsp;</td>'
        . ($b ? '<td width="' . $b . '%" bgcolor="' . ($over ? $red : '#e5e7eb') . '" style="height:10px;line-height:10px;font-size:0;background:' . ($over ? $red : '#e5e7eb') . ';border-radius:0 5px 5px 0">&nbsp;</td>' : '')
        . '</tr></table>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
        . '<td width="50%" style="padding-top:6px;font-family:' . $f . ';font-size:12px;color:' . MAIL_DIM . '">' . ($over ? h(t('εντός ορίου')) . ' · ' . h($hw($st['budget'])) : h(t('καταγράφηκαν')) . ' · ' . h($hw($st['used']))) . '</td>'
        . ($b ? '<td width="50%" align="right" style="padding-top:6px;font-family:' . $f . ';font-size:12px;color:' . ($over ? $red : MAIL_DIM) . ';font-weight:' . ($over ? '700' : '400') . '">' . ($over ? h(t('πάνω από το όριο')) . ' · +' . h($hw($st['over'])) : h(t('απομένουν')) . ' · ' . h($hw($st['left']))) . '</td>' : '')
        . '</tr></table>';
    $head = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:4px 0 14px"><tr>'
        . '<td style="font-family:' . $f . ';font-size:17px;font-weight:700;color:' . MAIL_INK . '"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . h($p['color'] ?? '#9ca3af') . ';margin-right:8px"></span>' . h($p['name'])
 . '</td>'
        . '<td align="right">' . $badge . '</td></tr></table>';
    return mail_html([
        'preheader' => budget_message($p, $st),
        'title' => $over ? t('Ένα project ξεπέρασε το όριο ωρών') : t('Ένα project πλησιάζει το όριο ωρών'),
        'greeting' => t('Γεια σου {n},', ['n' => $to['name']]),
        'block' => $head . $figures . $bar . '<div style="height:22px;line-height:22px;font-size:0">&nbsp;</div>',
        'button' => [t('Άνοιγμα των projects'), $url],
        'after' => [t('Λαμβάνεις αυτό το email επειδή το project έχει ενεργές ειδοποιήσεις ορίου ωρών.')],
    ]);
}

/* ---------- Ποιος δούλεψε σε κάθε project ---------- */

/** [user_id => ['id','name','avatar']] για όλους τους χρήστες */
function users_map(): array
{
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (q_all('SELECT id, name, avatar, active FROM tt_users') as $u) $m[(int)$u['id']] = $u;
    }
    return $m;
}

/**
 * Χρήστες που έχουν καταγράψει χρόνο ανά project, ταξινομημένοι κατά ώρες.
 * [project_id => [['user_id','name','avatar','secs','month_secs','entries','last_at'], ...]]
 */
function project_contributors(?int $projectId = null): array
{
    $now = now_sql();
    $monthStart = now()->modify('first day of this month')->format('Y-m-d 00:00:00');
    $pf = $projectId ? ' AND e.project_id = ?' : ' AND e.project_id IS NOT NULL';
    $pp = $projectId ? [$projectId] : [];
    $acc = [];
    // ολοκληρωμένες: μόνο από το covering index (project_id, user_id, start_at, duration, end_at)
    foreach (q_all("SELECT e.project_id, e.user_id, SUM(e.duration) AS secs,
                           SUM(CASE WHEN e.start_at >= ? THEN e.duration ELSE 0 END) AS month_secs,
                           COUNT(*) AS entries, MAX(e.start_at) AS last_at
                    FROM tt_entries e WHERE e.duration IS NOT NULL{$pf}
                    GROUP BY e.project_id, e.user_id", array_merge([$monthStart], $pp)) as $r) {
        $acc[(int)$r['project_id']][(int)$r['user_id']] = ['secs' => (int)$r['secs'], 'month_secs' => (int)$r['month_secs'], 'entries' => (int)$r['entries'], 'last_at' => $r['last_at']];
    }
    // όσα τρέχουν τώρα (λίγα)
    foreach (q_all("SELECT e.project_id, e.user_id, e.start_at, GREATEST(0, TIMESTAMPDIFF(SECOND, e.start_at, ?)) AS secs
                    FROM tt_entries e WHERE e.end_at IS NULL{$pf}", array_merge([$now], $pp)) as $r) {
        $a = &$acc[(int)$r['project_id']][(int)$r['user_id']];
        $a ??= ['secs' => 0, 'month_secs' => 0, 'entries' => 0, 'last_at' => $r['start_at']];
        $a['secs'] += (int)$r['secs'];
        if ($r['start_at'] >= $monthStart) $a['month_secs'] += (int)$r['secs'];
        $a['entries']++;
        if ($r['start_at'] > $a['last_at']) $a['last_at'] = $r['start_at'];
        unset($a);
    }
    $users = users_map();
    $out = [];
    foreach ($acc as $pid => $list) {
        foreach ($list as $uid => $v) {
            $u = $users[$uid] ?? ['name' => '?', 'avatar' => null, 'active' => 0];
            $out[$pid][] = ['project_id' => $pid, 'user_id' => $uid, 'name' => $u['name'], 'avatar' => $u['avatar'], 'active' => $u['active']] + $v;
        }
        usort($out[$pid], fn($x, $y) => $y['secs'] <=> $x['secs']);
    }
    return $out;
}

/** Στοίβα από avatars (έως $max) με tooltip ονόματος και ωρών */
function avatar_stack(array $people, bool $showHours, int $max = 5): string
{
    if (!$people) return '<span class="text-body-tertiary">—</span>';
    $html = '<div class="avatar-stack">';
    foreach (array_slice($people, 0, $max) as $p) {
        $title = $p['name'] . ($showHours ? ' · ' . fmt_hm($p['secs']) : '');
        $html .= '<span title="' . h($title) . '" data-bs-toggle="tooltip">' . avatar_html(['name' => $p['name'], 'avatar' => $p['avatar']], 'avatar-sm') . '</span>';
    }
    if (count($people) > $max) $html .= '<span class="avatar avatar-sm avatar-more" title="' . h(implode(', ', array_map(fn($p) => $p['name'], array_slice($people, $max)))) . '">+' . (count($people) - $max) . '</span>';
    return $html . '</div>';
}
