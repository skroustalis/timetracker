<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$admin = is_admin($user);
$teamView = is_lead($user); // admin ή manager: βλέπει χρόνο και άλλων

$tab = in_array($_GET['tab'] ?? '', ['summary', 'detailed', 'weekly'], true) ? $_GET['tab'] : 'summary';
[$from, $to, $preset, $rangeClamped] = read_range($tab === 'weekly' ? 'week' : 'month') + [3 => false];

/* ---------- Φίλτρα ---------- */
$fUser = get_int('user');
$fGroup = null; // οι ομάδες είναι χωριστές: ο admin αλλάζει ομάδα από την πάνω μπάρα
$fProject = (string)($_GET['project'] ?? '');
$fTag = get_int('tag');
$fBill = in_array($_GET['billable'] ?? '', ['0', '1'], true) ? $_GET['billable'] : '';
$fQ = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));

$f = ['from' => $from, 'to' => $to, 'billable' => $fBill, 'q' => $fQ, 'tag_id' => $fTag];
$f['user_ids'] = scope_user_ids($user, $fUser ? [$fUser] : []);
$f += scope_filter($user); // κάθε καταχώρηση ανήκει στην ομάδα του project της
if ($admin && $fGroup) $f['group_id'] = $fGroup;
if ($fProject === 'none') $f['no_project'] = true;
elseif ((int)$fProject > 0) $f['project_ids'] = [(int)$fProject];

$by1 = array_key_exists($_GET['by1'] ?? '', GROUP_BY_OPTIONS) ? $_GET['by1'] : 'project';
$by2 = array_key_exists($_GET['by2'] ?? '', GROUP_BY_OPTIONS) ? $_GET['by2'] : (isset($_GET['by2']) ? '' : ($by1 === 'user' ? 'project' : 'user'));
if ($by2 === $by1) $by2 = '';
$weeklyBy = in_array($_GET['wby'] ?? '', ['user', 'project'], true) ? $_GET['wby'] : ($teamView ? 'user' : 'project');

$export = ($_GET['export'] ?? '') === 'csv';
// Η εβδομαδιαία προβολή δείχνει έως 31 ημέρες
if ($tab === 'weekly' && $from->diff($to)->days > 31) { $to = $from->modify('+30 days'); $f['to'] = $to; }
// Σύνολα και ομαδοποιήσεις υπολογίζονται στη βάση· δεν φορτώνουμε όλες τις γραμμές στη μνήμη
$tot = report_totals($f);
$rangeLabel = $from->format('d/m/Y') . ' – ' . $to->format('d/m/Y');

/* ---------- CSV ---------- */
/** Κείμενο που το Excel θα έπαιρνε για τύπο (=, +, -, @) γράφεται ως απλό κείμενο */
function csv_safe(array $row): array
{
    return array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v, $row);
}
function csv_begin(string $filename, array $header): array
{
    @set_time_limit(300);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM για σωστά ελληνικά στο Excel
    $sep = lang() === 'en' ? ',' : ';'; // ό,τι περιμένει το Excel σε κάθε γλώσσα
    fputcsv($out, $header, $sep);
    return [$out, $sep];
}
function csv_out(string $filename, array $header, array $lines): never
{
    [$out, $sep] = csv_begin($filename, $header);
    foreach ($lines as $l) fputcsv($out, csv_safe($l), $sep);
    fclose($out);
    exit;
}
$num = fn(float $v, int $d = 2) => number_format($v, $d, num_seps()[0], '');

/* ---------- Weekly: δεδομένα (ομαδοποίηση στη βάση) ---------- */
$weekDays = [];
$weekly = [];
if ($tab === 'weekly') {
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) $weekDays[] = $d;
    [$wWhere, $wParams] = entries_where($f);
    $keyExpr = $weeklyBy === 'user' ? "CONCAT('u', e.user_id)" : "CONCAT('p', COALESCE(e.project_id, 0))";
    $wrows = q_all("SELECT {$keyExpr} AS k, DATE(e.start_at) AS d, SUM(" . DUR_SQL . ") AS dur,
                           MIN(u.name) AS uname, MIN(u.avatar) AS uavatar, MIN(p.name) AS pname, MIN(p.color) AS pcolor
                    FROM tt_entries e LEFT JOIN tt_users u ON u.id = e.user_id LEFT JOIN tt_projects p ON p.id = e.project_id
                    WHERE {$wWhere} GROUP BY k, d", array_merge([now_sql()], $wParams));
    foreach ($wrows as $r) {
        $k = $r['k'];
        $label = $weeklyBy === 'user' ? $r['uname'] : ($r['pname'] ?? t('(Χωρίς project)'));
        $weekly[$k] ??= ['label' => $label, 'color' => $weeklyBy === 'project' ? ($r['pcolor'] ?? '#b0bec5') : null, 'avatar' => $weeklyBy === 'user' ? $r['uavatar'] : null, 'days' => [], 'total' => 0];
        $weekly[$k]['days'][$r['d']] = ($weekly[$k]['days'][$r['d']] ?? 0) + (int)$r['dur'];
        $weekly[$k]['total'] += (int)$r['dur'];
    }
    uasort($weekly, fn($a, $b) => strcmp(mb_strtolower($a['label']), mb_strtolower($b['label'])));
}

if ($export) {
    $stamp = $from->format('Ymd') . '-' . $to->format('Ymd');
    if ($tab === 'detailed') {
        // Γραμμή-γραμμή, χωρίς να κρατάμε όλη την αναφορά στη μνήμη
        [$out, $sep] = csv_begin(t('anafora-analytiki') . "-{$stamp}.csv", array_map('t', ['Ημερομηνία', 'Χρήστης', 'Project', 'Περιγραφή', 'Tags', 'Χρεώσιμο', 'Έναρξη', 'Λήξη', 'Διάρκεια', 'Ώρες']));
        entries_stream($f, function (array $r) use ($out, $sep, $num) {
            fputcsv($out, csv_safe([
                (new DateTimeImmutable($r['start_at']))->format('d/m/Y'), $r['user_name'], $r['project_name'] ?? '',
                $r['description'], $r['tag_names'] ?? '', $r['billable'] ? t('Ναι') : t('Όχι'),
                fmt_time($r['start_at']), $r['end_at'] ? fmt_time($r['end_at']) : '', fmt_dur($r['dur']), $num($r['dur'] / 3600),
            ]), $sep);
        });
        fclose($out);
        exit;
    }
    if ($tab === 'weekly') {
        $lines = [];
        foreach ($weekly as $w) {
            $l = [$w['label']];
            foreach ($weekDays as $d) $l[] = $num(($w['days'][$d->format('Y-m-d')] ?? 0) / 3600);
            $l[] = $num($w['total'] / 3600);
            $lines[] = $l;
        }
        csv_out(t('anafora-ebdomadiaia') . "-{$stamp}.csv", array_merge([$weeklyBy === 'user' ? t('Χρήστης') : 'Project'], array_map(fn($d) => $d->format('d/m'), $weekDays), [t('Σύνολο (ώρες)')]), $lines);
    }
    $agg = report_aggregate($f, $by1, $by2 ?: null);
    $lines = [];
    foreach ($agg as $g) {
        $lines[] = [$g['label'], '', fmt_dur($g['dur']), $num($g['dur'] / 3600)];
        foreach ($g['children'] as $c) $lines[] = [$g['label'], $c['label'], fmt_dur($c['dur']), $num($c['dur'] / 3600)];
    }
    csv_out(t('anafora-synopsi') . "-{$stamp}.csv", [t(GROUP_BY_OPTIONS[$by1]), $by2 ? t(GROUP_BY_OPTIONS[$by2]) : '', t('Διάρκεια'), t('Ώρες')], $lines);
}

/* ---------- Σελίδα ---------- */
$projects = visible_projects($user, true);
$tags = all_tags();
$users = $teamView ? team_users($user, false) : [];
$groups = [];
$return = 'reports.php?' . qs();

render_header(t('Αναφορές'), 'reports');
$tabs = ['summary' => t('Σύνοψη'), 'detailed' => t('Αναλυτική'), 'weekly' => t('Εβδομαδιαία')];
ob_start(); ?>
  <ul class="nav nav-pills">
    <?php foreach ($tabs as $k => $label): ?>
      <li class="nav-item"><a href="reports.php?<?= h(qs(['tab' => $k, 'page' => null])) ?>" class="nav-link<?= $tab === $k ? ' active' : '' ?>"><?= h($label) ?></a></li>
    <?php endforeach; ?>
  </ul>
<?php page_head(h(t('Αναφορές')), ob_get_clean()); ?>
<?php if ($rangeClamped && $tab !== 'weekly'): ?>
<div class="alert alert-warning no-print"><?= icon('info-circle', 'me-1') ?><?= h(t('Οι αναφορές δείχνουν έως 5 χρόνια. Η περίοδος ξεκινά από {d}.', ['d' => $from->format('d/m/Y')])) ?></div>
<?php endif; ?>

<form method="get" class="card mb-3 no-print">
  <div class="card-body">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <?php if ($tab === 'summary'): ?><input type="hidden" name="by1" value="<?= h($by1) ?>"><input type="hidden" name="by2" value="<?= h($by2) ?>"><?php endif; ?>
    <?php if ($tab === 'weekly'): ?><input type="hidden" name="wby" value="<?= h($weeklyBy) ?>"><?php endif; ?>
    <div class="mb-3"><?php include __DIR__ . '/includes/range_picker.php'; ?></div>
    <div class="row g-2">
      <?php if ($teamView): ?>
        <div class="col-sm-6 col-lg-4 col-xxl-3"><select name="user" class="form-select" aria-label="<?= h(t('Χρήστης')) ?>"><option value=""><?= h(t('Όλοι οι χρήστες')) ?></option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?><?= $u['active'] ? '' : ' (' . h(t('ανενεργός')) . ')' ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <?php if ($groups): ?>
        <div class="col-sm-6 col-lg-4 col-xxl-3"><select name="group" class="form-select" aria-label="<?= h(t('Ομάδα')) ?>"><option value=""><?= h(t('Όλες οι ομάδες')) ?></option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $fGroup === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="col-sm-6 col-lg-4 col-xxl-3"><select name="project" class="form-select" aria-label="Project">
        <option value=""><?= h(t('Όλα τα projects')) ?></option>
        <option value="none" <?= $fProject === 'none' ? 'selected' : '' ?>><?= h(t('(Χωρίς project)')) ?></option>
        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $fProject === (string)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="col-sm-6 col-lg-4 col-xxl-3"><select name="tag" class="form-select" aria-label="Tag"><option value=""><?= h(t('Όλα τα tags')) ?></option><?php foreach ($tags as $tg): ?><option value="<?= (int)$tg['id'] ?>" <?= $fTag === (int)$tg['id'] ? 'selected' : '' ?>><?= h($tg['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-6 col-lg-4 col-xxl-3"><select name="billable" class="form-select" aria-label="<?= h(t('Χρεώσιμο')) ?>"><option value=""><?= h(t('Χρεώσιμα & μη')) ?></option><option value="1" <?= $fBill === '1' ? 'selected' : '' ?>><?= h(t('Μόνο χρεώσιμα')) ?></option><option value="0" <?= $fBill === '0' ? 'selected' : '' ?>><?= h(t('Μόνο μη χρεώσιμα')) ?></option></select></div>
      <div class="col-sm-6 col-lg-4 col-xxl-3"><input type="search" name="q" class="form-control" value="<?= h($fQ) ?>" placeholder="<?= h(t('Περιγραφή περιέχει…')) ?>"></div>
      <div class="col-sm-6 col-lg-4 col-xxl-3 d-flex gap-2">
        <button class="btn btn-primary flex-grow-1" type="submit"><?= icon('search', 'me-1') ?><?= h(t('Εφαρμογή')) ?></button>
        <a class="btn btn-light" href="reports.php?tab=<?= h($tab) ?>" title="<?= h(t('Καθαρισμός')) ?>"><?= icon('x') ?></a>
      </div>
    </div>
  </div>
</form>

<div class="card mb-3">
  <div class="card-body d-flex flex-wrap align-items-center gap-3 gap-md-4 py-2">
    <span><?= h(t('Σύνολο')) ?> <strong class="font-monospace fs-5 ms-1"><?= fmt_dur($tot['dur']) ?></strong></span>
    <span><?= h(t('Χρεώσιμα')) ?> <strong class="font-monospace fs-5 ms-1"><?= fmt_dur($tot['billable_dur']) ?></strong></span>
    <span class="small text-body-secondary"><?= h($rangeLabel) ?> · <?= h(t('{n} καταχωρήσεις', ['n' => $tot['count']])) ?></span>
    <span class="ms-auto d-flex gap-2 no-print">
      <a class="btn btn-outline-secondary btn-sm" href="reports.php?<?= h(qs(['export' => 'csv'])) ?>"><?= icon('download', 'me-1') ?>CSV</a>
      <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><?= icon('print', 'me-1') ?><?= h(t('Εκτύπωση')) ?></button>
    </span>
  </div>
</div>

<?php if ($tab === 'summary'):
    $showChart = $from->diff($to)->days < 62;
    $series = $showChart ? report_daily($f, $from, $to) : [];
    $maxDay = max(1, 1, ...array_values(array_column($series, 'dur')));
    $agg = report_aggregate($f, $by1, $by2 ?: null);
?>
  <?php if ($showChart && $tot['count']): ?><div class="card mb-3"><div class="card-body"><?php include __DIR__ . '/includes/bar_chart.php'; ?></div></div><?php endif; ?>
  <div class="card">
    <div class="card-header">
      <form method="get" class="d-flex flex-wrap align-items-center gap-2 no-print">
        <?php foreach ($_GET as $k => $v): if (in_array($k, ['by1', 'by2', 'export'], true) || is_array($v)) continue; ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
        <span class="text-body-secondary fw-normal"><?= h(t('Ομαδοποίηση ανά')) ?></span>
        <select name="by1" class="form-select form-select-sm w-auto" onchange="this.form.submit()"><?php foreach (GROUP_BY_OPTIONS as $k => $l): ?><option value="<?= h($k) ?>" <?= $by1 === $k ? 'selected' : '' ?>><?= h(t($l)) ?></option><?php endforeach; ?></select>
        <span class="text-body-secondary fw-normal"><?= h(t('και')) ?></span>
        <select name="by2" class="form-select form-select-sm w-auto" onchange="this.form.submit()"><option value=""><?= h(t('(τίποτα)')) ?></option><?php foreach (GROUP_BY_OPTIONS as $k => $l): if ($k === $by1) continue; ?><option value="<?= h($k) ?>" <?= $by2 === $k ? 'selected' : '' ?>><?= h(t($l)) ?></option><?php endforeach; ?></select>
        <?php if ($by2 && $agg): ?>
          <span class="ms-auto d-flex gap-1">
            <button type="button" class="btn btn-sm btn-light" data-grp-all="1"><?= icon('arrows-expand', 'me-1') ?><?= h(t('Άνοιγμα όλων')) ?></button>
            <button type="button" class="btn btn-sm btn-light" data-grp-all="0"><?= icon('arrows-collapse', 'me-1') ?><?= h(t('Κλείσιμο όλων')) ?></button>
          </span>
        <?php endif; ?>
      </form>
    </div>
    <?php if (!$agg): ?><div class="card-body text-body-secondary"><?= h(t('Δεν υπάρχουν δεδομένα με αυτά τα φίλτρα.')) ?></div><?php else: ?>
    <div class="table-responsive">
    <table class="table summary align-middle mb-0">
      <thead><tr><th><?= h(t(GROUP_BY_OPTIONS[$by1])) ?><?= $by2 ? ' / ' . h(t(GROUP_BY_OPTIONS[$by2])) : '' ?></th><th class="text-num"><?= h(t('Διάρκεια')) ?></th><th class="share-col"></th></tr></thead>
      <tbody>
        <?php $umap = users_map(); $uav = function (string $k) use ($umap) { return ($k[0] === 'u' && isset($umap[(int)substr($k, 1)])) ? avatar_html($umap[(int)substr($k, 1)], 'avatar-sm me-2') : ''; }; ?>
        <?php $gi = 0; foreach ($agg as $gk => $g): $pct = $tot['dur'] ? $g['dur'] / $tot['dur'] * 100 : 0; $gid = 'grp' . (++$gi); $kids = count($g['children']); ?>
          <tr class="lvl1<?= $kids ? ' grp-head' : '' ?>"<?= $kids ? ' data-grp="' . $gid . '"' : '' ?>>
            <td><?php if ($kids): ?><button type="button" class="grp-toggle" aria-expanded="false" aria-controls="<?= $gid ?>" title="<?= h(t('Εμφάνιση / απόκρυψη')) ?>"><?= icon('chevron-right') ?></button><?php elseif ($by2): ?><span class="grp-toggle-sp"></span><?php endif; ?><span class="d-inline-flex align-items-center"><?= $uav((string)$gk) ?><?php if ($g['color']): ?><span class="dot" style="background:<?= h($g['color']) ?>"></span><?php endif; ?><?= h($g['label']) ?></span> <span class="text-body-secondary small fw-normal">(<?= $g['count'] ?>)</span><?php if ($kids): ?> <span class="grp-count" title="<?= h(t(GROUP_BY_OPTIONS[$by2])) ?>"><?= icon(['user' => 'people', 'project' => 'folder2', 'tag' => 'tag', 'group' => 'diagram-3', 'date' => 'calendar3', 'description' => 'card-text'][$by2] ?? 'list', 'me-1') ?><?= $kids ?></span><?php endif; ?></td>
            <td class="text-num font-monospace"><?= fmt_dur($g['dur']) ?></td>
            <td class="share-col"><div class="progress"><div class="progress-bar" style="width:<?= round($pct, 1) ?>%;<?= $g['color'] ? 'background:' . h($g['color']) : '' ?>"></div></div><small class="text-body-secondary fw-normal"><?= fmt_pct($pct) ?></small></td>
          </tr>
          <?php foreach ($g['children'] as $ck => $c): ?>
            <tr class="lvl2 grp-kid" data-of="<?= $gid ?>" hidden>
              <td><span class="d-inline-flex align-items-center"><?= $uav((string)$ck) ?><?php if ($c['color']): ?><span class="dot" style="background:<?= h($c['color']) ?>"></span><?php endif; ?><?= h($c['label']) ?></span></td>
              <td class="text-num font-monospace"><?= fmt_dur($c['dur']) ?></td>
              <td></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th><?= h(t('Σύνολο')) ?></th><th class="text-num font-monospace"><?= fmt_dur($tot['dur']) ?></th><th></th></tr></tfoot>
    </table>
    </div>
    <?php if (in_array('tag', [$by1, $by2], true) || in_array('group', [$by1, $by2], true)): ?>
      <div class="card-footer bg-white small text-body-secondary"><?= h(t('Σημείωση: καταχωρήσεις με πολλά tags, ή χρήστες σε πολλές ομάδες, μετράνε σε κάθε γραμμή, οπότε το άθροισμα των γραμμών μπορεί να ξεπερνά το σύνολο.')) ?></div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'detailed'):
    $perPage = 100;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pages = max(1, (int)ceil($tot['count'] / $perPage));
    $page = min($page, $pages);
    // μόνο η τρέχουσα σελίδα φορτώνεται από τη βάση
    $pageRows = entries_query($f + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);
?>
  <div class="card">
    <?php if (!$tot['count']): ?><div class="card-body text-body-secondary"><?= h(t('Δεν υπάρχουν δεδομένα με αυτά τα φίλτρα.')) ?></div><?php else: ?>
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th><?= h(t('Ημερομηνία')) ?></th><?php if ($teamView): ?><th><?= h(t('Χρήστης')) ?></th><?php endif; ?><th><?= h(t('Περιγραφή')) ?></th><th>Project</th><th>Tags</th><th class="text-center">€</th><th><?= h(t('Ώρα')) ?></th><th class="text-num"><?= h(t('Διάρκεια')) ?></th><th class="no-print"></th></tr></thead>
      <tbody>
        <?php foreach ($pageRows as $r): $mine = (int)$r['user_id'] === (int)$user['id']; ?>
          <tr>
            <td class="text-nowrap"><?= h(fmt_date($r['start_at'])) ?></td>
            <?php if ($teamView): ?><td class="text-nowrap"><span class="d-inline-flex align-items-center gap-2"><?= avatar_html(['name' => $r['user_name'], 'avatar' => $r['user_avatar']], 'avatar-sm') ?><?= h($r['user_name']) ?></span></td><?php endif; ?>
            <td><?= $r['description'] !== '' ? h($r['description']) : '<span class="text-body-tertiary">' . h(t('(Χωρίς περιγραφή)')) . '</span>' ?></td>
            <td><?php if ($r['project_id']): ?><span class="dot" style="background:<?= h($r['project_color']) ?>"></span><?= h($r['project_name']) ?><?php else: ?><span class="text-body-tertiary">—</span><?php endif; ?></td>
            <td class="small"><?= h($r['tag_names'] ?? '') ?></td>
            <td class="text-center"><?= $r['billable'] ? '<span class="text-primary fw-semibold">€</span>' : '<span class="text-body-tertiary">–</span>' ?></td>
            <td class="text-nowrap font-monospace small"><?= fmt_time($r['start_at']) ?>–<?= $r['end_at'] ? fmt_time($r['end_at']) : h(t('τρέχει')) ?></td>
            <td class="text-num font-monospace"><?= fmt_dur($r['dur']) ?></td>
            <td class="no-print"><?php if ($teamView || $mine): ?><button type="button" class="btn btn-icon" title="<?= h(t('Επεξεργασία')) ?>" data-edit-entry='<?= entry_json($r) ?>'><?= icon('edit') ?></button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="card-footer bg-white no-print">
        <ul class="pagination pagination-sm flex-wrap mb-0">
          <?php
            // παράθυρο σελίδων: 1 … (τρέχουσα ± 3) … τελευταία
            $shown = array_unique(array_merge([1, $pages], range(max(1, $page - 3), min($pages, $page + 3))));
            sort($shown);
            $prevP = 0;
            foreach ($shown as $p):
              if ($p - $prevP > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="reports.php?<?= h(qs(['page' => $p])) ?>"><?= $p ?></a></li>
          <?php $prevP = $p; endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php render_entry_dialog($user, $return); ?>

<?php else: /* weekly */ ?>
  <div class="card">
    <div class="card-header">
      <form method="get" class="d-flex flex-wrap align-items-center gap-2 no-print">
        <?php foreach ($_GET as $k => $v): if (in_array($k, ['wby', 'export'], true) || is_array($v)) continue; ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
        <span class="text-body-secondary fw-normal"><?= h(t('Γραμμές ανά')) ?></span>
        <select name="wby" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
          <?php if ($teamView): ?><option value="user" <?= $weeklyBy === 'user' ? 'selected' : '' ?>><?= h(t('Χρήστη')) ?></option><?php endif; ?>
          <option value="project" <?= $weeklyBy === 'project' ? 'selected' : '' ?>>Project</option>
        </select>
        <?php if (count($weekDays) >= 31): ?><span class="small text-body-secondary fw-normal"><?= h(t('Δείχνω έως 31 ημέρες. Διάλεξε μικρότερη περίοδο για τα υπόλοιπα.')) ?></span><?php endif; ?>
      </form>
    </div>
    <?php if (!$weekly): ?><div class="card-body text-body-secondary"><?= h(t('Δεν υπάρχουν δεδομένα με αυτά τα φίλτρα.')) ?></div><?php else: ?>
    <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th><?= $weeklyBy === 'user' ? h(t('Χρήστης')) : 'Project' ?></th>
        <?php foreach ($weekDays as $d): ?><th class="text-num <?= (int)$d->format('N') >= 6 ? 'weekend' : '' ?>"><?= h(day_names_short()[(int)$d->format('N') - 1]) ?><br><small class="fw-normal"><?= $d->format('j/n') ?></small></th><?php endforeach; ?>
        <th class="text-num"><?= h(t('Σύνολο')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($weekly as $w): ?>
          <tr>
            <td class="text-nowrap"><span class="d-inline-flex align-items-center gap-2"><?php if ($weeklyBy === 'user'): ?><?= avatar_html(['name' => $w['label'], 'avatar' => $w['avatar'] ?? null], 'avatar-sm') ?><?php endif; ?><?php if ($w['color']): ?><span class="dot" style="background:<?= h($w['color']) ?>"></span><?php endif; ?><?= h($w['label']) ?></span></td>
            <?php foreach ($weekDays as $d): $v = $w['days'][$d->format('Y-m-d')] ?? 0; ?>
              <td class="text-num font-monospace <?= (int)$d->format('N') >= 6 ? 'weekend' : '' ?> <?= $v ? '' : 'text-body-tertiary' ?>"><?= $v ? fmt_dur($v) : '—' ?></td>
            <?php endforeach; ?>
            <td class="text-num font-monospace fw-semibold"><?= fmt_dur($w['total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th><?= h(t('Σύνολο')) ?></th>
        <?php foreach ($weekDays as $d): $k = $d->format('Y-m-d'); $sum = array_sum(array_map(fn($w) => $w['days'][$k] ?? 0, $weekly)); ?>
          <th class="text-num font-monospace <?= (int)$d->format('N') >= 6 ? 'weekend' : '' ?>"><?= $sum ? fmt_dur($sum) : '—' ?></th>
        <?php endforeach; ?>
        <th class="text-num font-monospace"><?= fmt_dur($tot['dur']) ?></th></tr></tfoot>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php render_footer();
