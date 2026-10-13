<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$admin = is_admin($user);

[$from, $to, $preset] = read_range('week');
// Το γράφημα είναι ανά ημέρα: κρατάμε έως ~2 μήνες στο dashboard, μέχρι σήμερα (π.χ. «Φέτος» = οι τελευταίες 62 μέρες)
if ($from->diff($to)->days > 62) {
    $today = now()->setTime(0, 0);
    if ($to > $today && $from <= $today) $to = $today;
    $from = max($from, $to->modify('-62 days'));
}

$lead = is_lead($user);          // admin ή manager
$myGroup = managed_group_id($user);
$scope = (string)($_GET['scope'] ?? ($lead ? 'all' : 'me'));
$scopeAllLabel = $admin ? (string)(q_val('SELECT name FROM tt_groups WHERE id = ?', [(int)current_dept($user)]) ?? t('Όλοι')) : '';
$f = ['from' => $from, 'to' => $to];
$scopeLabel = t('Μόνο εγώ');
if (!$lead || $scope === 'me') {
    $scope = 'me';
    $f['user_ids'] = [(int)$user['id']];
} elseif ($admin && str_starts_with($scope, 'g') && ($gid = (int)substr($scope, 1))) {
    $f['dept_id'] = $gid;
    $scopeLabel = t('Ομάδα') . ': ' . (q_val('SELECT name FROM tt_groups WHERE id = ?', [$gid]) ?? '?');
} else {
    // Χωρίς επιλογή μεμονωμένου χρήστη (όχι στοχοποίηση): ο χρόνος ανά άτομο φαίνεται μόνο μέσα σε ένα project
    $scope = 'all';
    // κάθε καταχώρηση ανήκει στην ομάδα του project της (ο manager δεν βλέπει τον χρόνο του admin)
    $f += scope_filter($user);
    if ($admin) {
        // admin: όλη η ομάδα που έχει επιλέξει στην πάνω μπάρα
        $scopeLabel = (string)(q_val('SELECT name FROM tt_groups WHERE id = ?', [(int)current_dept($user)]) ?? t('Όλοι'));
    } else {
        // manager: «όλοι» = η ομάδα του
        $scopeLabel = t('Η ομάδα μου');
    }
}

// Όλα τα σύνολα υπολογίζονται στη βάση (γρήγορο και με πολλές καταχωρήσεις)
$tot = report_totals($f);
// Ανά project και, μέσα σε κάθε project, πόσο χρόνο έβαλε ο καθένας (όχι σύνολα ανά άτομο)
$byProject = report_aggregate($f, 'project', 'user');
// Top 5 πελάτες της περιόδου (χωρίς τις ώρες χωρίς πελάτη)
$series = report_daily($f, $from, $to);
$maxDay = max(1, 1, ...array_values(array_column($series, 'dur')));

// Πιο συχνές δραστηριότητες: περιγραφή + project
[$aWhere, $aParams] = entries_where($f);
$acts = array_map(fn($r) => ['desc' => $r['descr'], 'project' => $r['pname'], 'color' => $r['pcolor'], 'dur' => (int)$r['dur']],
    q_all("SELECT MIN(e.description) AS descr, MIN(p.name) AS pname, MIN(p.color) AS pcolor, SUM(" . DUR_SQL . ") AS dur
           FROM tt_entries e LEFT JOIN tt_projects p ON p.id = e.project_id
           WHERE {$aWhere}
           GROUP BY LOWER(TRIM(e.description)), COALESCE(e.project_id, 0)
           ORDER BY dur DESC LIMIT 10", array_merge([now_sql()], $aParams)));

// Top 3 projects της περιόδου (χωρίς τις ώρες χωρίς project)
$topProjects = array_slice(array_filter($byProject, fn($k) => $k !== 'p0', ARRAY_FILTER_USE_KEY), 0, 3, true);
$topMax = max(1, ...array_values(array_map(fn($g) => (int)$g['dur'], $topProjects ?: [['dur' => 1]])));
// Νέα projects: η πρώτη τους καταγραφή (από οποιονδήποτε) είναι μέσα στην περίοδο
$visibleIds = array_flip(array_map(fn($p) => (int)$p['id'], visible_projects($user)));
$newProjects = [];
foreach (q_all('SELECT e.project_id, MIN(e.start_at) AS first_at, MIN(p.name) AS name FROM tt_entries e JOIN tt_projects p ON p.id = e.project_id
                GROUP BY e.project_id HAVING first_at BETWEEN ? AND ? ORDER BY first_at DESC',
               [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 23:59:59')]) as $r) {
    if (isset($visibleIds[(int)$r['project_id']])) $newProjects[] = $r['name'];
}
$activeProjects = count(array_filter(array_keys($byProject), fn($k) => $k !== 'p0'));
$today = now()->setTime(0, 0);
$todayCount = report_totals(['from' => $today, 'to' => $today] + array_diff_key($f, ['from' => 1, 'to' => 1]))['count'];
$alerts = budget_alerts_for_dashboard($user);
$umap = users_map();

render_header('Dashboard', 'dashboard');
ob_start(); ?>
  <form method="get" class="d-flex flex-wrap align-items-center gap-2">
    <?php if ($lead): ?>
      <select name="scope" class="form-select w-auto" onchange="this.form.submit()" aria-label="<?= h(t('Εμβέλεια')) ?>">
        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>><?= h($admin ? $scopeAllLabel : t('Η ομάδα μου')) ?></option>
        <option value="me" <?= $scope === 'me' ? 'selected' : '' ?>><?= h(t('Μόνο εγώ')) ?></option>
        <?php $gs = []; if ($gs): ?><optgroup label="<?= h(t('Ομάδες')) ?>"><?php foreach ($gs as $g): ?><option value="g<?= (int)$g['id'] ?>" <?= $scope === 'g' . $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
      </select>
    <?php endif; ?>
    <?php include __DIR__ . '/includes/range_picker.php'; ?>
  </form>
<?php page_head('Dashboard', ob_get_clean()); ?>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body">
    <span class="stat-icon i-yellow float-end"><?= icon('trophy') ?></span>
    <div class="stat-label"><?= h(t('Top projects')) ?></div>
    <?php if ($topProjects): ?>
      <div class="tp-rank">
        <?php foreach ($topProjects as $g): $c = $g['color'] ?: '#adb5bd'; ?>
          <div class="tp-rank-row" title="<?= h($g['label'] . ' · ' . fmt_dur($g['dur'])) ?>">
            <span class="tp-rank-name"><span class="dot" style="background:<?= h($c) ?>"></span><?= h($g['label']) ?></span>
            <span class="tp-rank-num"><?= h(fmt_hm($g['dur'])) ?></span>
            <span class="tp-rank-bar"><i style="width:<?= round($g['dur'] / $topMax * 100, 1) ?>%;background:<?= h($c) ?>"></i></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="stat-value">—</div><div class="small text-body-secondary"><?= h(t('Δεν υπάρχουν δεδομένα για την περίοδο.')) ?></div>
    <?php endif; ?>
  </div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body">
    <span class="stat-icon i-blue float-end"><?= icon('collection') ?></span>
    <div class="stat-label"><?= h(t('Ενεργά projects')) ?></div>
    <div class="stat-value font-monospace"><?= $activeProjects ?></div>
    <div class="small text-body-secondary"><?= h(t('με καταγραφές στην περίοδο')) ?></div>
  </div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body">
    <span class="stat-icon i-purple float-end"><?= icon('stars') ?></span>
    <div class="stat-label"><?= h(t('Νέα projects')) ?></div>
    <div class="stat-value font-monospace"><?= count($newProjects) ?></div>
    <div class="small text-body-secondary text-truncate" title="<?= h(implode(', ', $newProjects)) ?>"><?= $newProjects
        ? h(implode(', ', array_slice($newProjects, 0, 3)) . (count($newProjects) > 3 ? ' +' . (count($newProjects) - 3) : ''))
        : h(t('κανένα νέο αυτή την περίοδο')) ?></div>
  </div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body">
    <span class="stat-icon i-purple float-end"><?= icon('calendar') ?></span>
    <div class="stat-label"><?= h(t('Καταχωρήσεις σήμερα')) ?></div>
    <div class="stat-value font-monospace"><?= (int)$todayCount ?></div>
    <div class="small text-body-secondary"><?= h(t('{n} στην περίοδο', ['n' => (int)$tot['count']])) ?></div>
  </div></div></div>
</div>



<div class="row g-3 mb-3 align-items-start">
  <div class="col-xl-8">
    <div class="card"><div class="card-body">
      <h2 class="h6 text-body-secondary mb-0"><?= h($scopeLabel) ?> · <?= h(t('ανά ημέρα')) ?></h2>
      <?php include __DIR__ . '/includes/bar_chart.php'; ?>
    </div></div>
    <div class="card mt-3">
      <div class="card-header"><?= h(t('Πιο συχνές δραστηριότητες')) ?></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <?php foreach ($acts as $a): ?>
            <tr>
              <td><?= $a['desc'] !== '' ? h($a['desc']) : '<span class="text-body-tertiary">' . h(t('(Χωρίς περιγραφή)')) . '</span>' ?><div class="small" style="color:<?= h($a['color'] ?? '#8a94a6') ?>"><?= h($a['project'] ?? t('Χωρίς project')) ?></div></td>
              <td class="text-num font-monospace"><?= fmt_dur($a['dur']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$acts): ?><tr><td class="text-body-secondary"><?= h(t('Δεν υπάρχουν δεδομένα για την περίοδο.')) ?></td></tr><?php endif; ?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4 d-flex flex-column gap-3">
    <?php if ($alerts): ?>
    <div class="card limits-card" data-limits>
      <div class="card-body">
        <h2 class="h6 text-body-secondary mb-3 d-flex align-items-center gap-2"><?= icon('exclamation-triangle-fill', 'text-warning') ?><?= h(t('Όρια ωρών')) ?></h2>
        <?php foreach ($alerts as $ba): $p = $ba['project']; $st = $ba['status']; ?>
          <div class="limit-row" data-limit-row>
            <div class="d-flex align-items-center gap-2 mb-2">
              <a class="limit-name text-truncate text-decoration-none" href="<?= can_edit_project($user, $p) ? 'project_edit.php?id=' . (int)$p['id'] : 'projects.php' ?>"><span class="dot" style="background:<?= h($p['color']) ?>"></span><span class="fw-medium"><?= h($p['name']) ?></span></a>
              <span class="ms-auto limit-badge is-<?= h($st['state']) ?>"><?= h($st['state'] === 'over' ? t('Πάνω από το όριο') : t('Κοντά στο όριο')) ?></span>
              <button type="button" class="btn-x" data-limit-dismiss="<?= (int)$p['id'] ?>" data-limit-tag="<?= (int)($p['tag_id'] ?? 0) ?>" title="<?= h(t('Καθαρισμός')) ?>" aria-label="<?= h(t('Καθαρισμός')) ?>"><?= icon('x') ?></button>
            </div>
            <?= budget_meter($st) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card flex-grow-1"><div class="card-body">
      <h2 class="h6 text-body-secondary mb-3">Projects</h2>
      <?php if (!$byProject): ?><p class="text-body-secondary mb-0"><?= h(t('Δεν υπάρχουν δεδομένα για την περίοδο.')) ?></p><?php endif; ?>
      <?php foreach (array_slice($byProject, 0, 10, true) as $pk => $p): $pct = $tot['dur'] ? $p['dur'] / $tot['dur'] * 100 : 0;
          $people = [];
          foreach ($p['children'] as $uk => $c) { $uu = $umap[(int)substr((string)$uk, 1)] ?? null; if ($uu) $people[] = ['name' => $uu['name'], 'avatar' => $uu['avatar'] ?? null, 'secs' => $c['dur']]; } ?>
        <div class="mb-3">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
            <span class="text-truncate"><span class="dot" style="background:<?= h($p['color'] ?? '#adb5bd') ?>"></span><?= h($p['label']) ?></span>
            <span class="d-inline-flex align-items-center gap-2 flex-none"><?= $people ? avatar_stack($people, true, 4) : '' ?><span class="font-monospace"><?= fmt_dur($p['dur']) ?></span><?php if ($lead && ($pid = (int)substr((string)$pk, 1)) > 0): ?><a class="btn btn-icon btn-sm" href="<?= h(project_report_url($pid, $from, $to)) ?>" title="<?= h(t('Report του project')) ?>" aria-label="<?= h(t('Report του project')) ?>"><?= icon('chart') ?></a><?php endif; ?></span>
          </div>
          <div class="progress" role="progressbar" aria-valuenow="<?= round($pct) ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= round($pct, 1) ?>%;background:<?= h($p['color'] ?? '#adb5bd') ?>"></div></div>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>

<?php render_footer();
