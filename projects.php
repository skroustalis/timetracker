<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$admin = is_admin($user);
$canCreate = can_edit_project($user, null); // admin ή manager

$show = in_array($_GET['show'] ?? '', ['active', 'archived', 'all'], true) ? $_GET['show'] : 'active';
$search = trim((string)($_GET['q'] ?? ''));
$sort = in_array($_GET['sort'] ?? '', ['tags', 'recent'], true) ? $_GET['sort'] : '';

$projects = visible_projects($user, true);
$projects = array_values(array_filter($projects, function ($p) use ($show, $search) {
    if ($show === 'active' && $p['archived']) return false;
    if ($show === 'archived' && !$p['archived']) return false;
    if ($search !== '' && mb_stripos($p['name'], $search) === false) return false;
    return true;
}));

$tagLines = tag_budgets();
$tagUsage = $tagLines ? tag_budget_usage() : [];

// Τελευταία καταχώρηση ανά project (για όσους δεν είναι admin, χωρίς τον χρόνο του admin)
$lastAt = array_column(q_all('SELECT project_id, MAX(start_at) AS last_at FROM tt_entries WHERE project_id IS NOT NULL'
    . (!$admin && admin_ids() ? ' AND user_id NOT IN (' . in_ph(admin_ids()) . ')' : '') . ' GROUP BY project_id', !$admin ? admin_ids() : []), 'last_at', 'project_id');

// Ταξινόμηση «Τελευταία καταχώρηση»: όσα δουλεύτηκαν πιο πρόσφατα πρώτα, όσα δεν έχουν καμία στο τέλος
if ($sort === 'recent') {
    usort($projects, fn($a, $b) => strcmp((string)($lastAt[$b['id']] ?? ''), (string)($lastAt[$a['id']] ?? '')) ?: strcasecmp($a['name'], $b['name']));
}

// Ταξινόμηση «Ώρες ανά tag»: πρώτα όσα έχουν όριο (το πιο γεμάτο πρώτο), μετά όσα μόνο μετράνε, μετά τα υπόλοιπα
if ($sort === 'tags') {
    $rank = [];
    foreach ($projects as $p) {
        $best = null;
        foreach ($tagLines[(int)$p['id']] ?? [] as $line) {
            $st = tag_budget_status($line, $tagUsage);
            if ($st) $best = max($best ?? 0, $st['pct']);
        }
        $rank[(int)$p['id']] = $best !== null ? [0, -$best] : (!empty($tagLines[(int)$p['id']]) ? [1, 0] : [2, 0]);
    }
    usort($projects, fn($a, $b) => $rank[(int)$a['id']] <=> $rank[(int)$b['id']] ?: strcasecmp($a['name'], $b['name']));
}

// Σελιδοποίηση: 50 projects ανά σελίδα
$perPage = 50;
$total = count($projects);
$pages = max(1, (int)ceil($total / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$projects = array_slice($projects, ($page - 1) * $perPage, $perPage);

$usage = project_usage();
$contrib = project_contributors();
if (!$admin) $contrib = contributors_without_admins($contrib); // μόνο ο admin βλέπει τον χρόνο του admin
$firstDay = array_column(q_all('SELECT project_id, MIN(start_at) AS first_at FROM tt_entries WHERE project_id IS NOT NULL GROUP BY project_id'), 'first_at', 'project_id');

render_header('Projects', 'projects');
page_head('Projects', $canCreate ? '<a class="btn btn-primary" href="project_edit.php">' . icon('plus', 'me-1') . h(t('Νέο project')) . '</a>' : '');
?>
<form method="get" class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2">
    <?php if ($sort): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><?php endif; ?>
    <select name="show" class="form-select w-auto" onchange="this.form.submit()" aria-label="<?= h(t('Εμφάνιση')) ?>">
      <option value="active" <?= $show === 'active' ? 'selected' : '' ?>><?= h(t('Ενεργά')) ?></option>
      <option value="archived" <?= $show === 'archived' ? 'selected' : '' ?>><?= h(t('Αρχειοθετημένα')) ?></option>
      <option value="all" <?= $show === 'all' ? 'selected' : '' ?>><?= h(t('Όλα')) ?></option>
    </select>
    <div class="input-group w-auto">
      <input type="search" name="q" class="form-control" value="<?= h($search) ?>" placeholder="<?= h(t('Αναζήτηση…')) ?>">
      <button class="btn btn-outline-secondary" type="submit" title="<?= h(t('Αναζήτηση')) ?>"><?= icon('search') ?></button>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0 proj-table">
    <thead><tr><th><?= h(t('Όνομα')) ?></th><th class="text-num"><?= h(t('Καταγεγραμμένο')) ?></th><th class="text-num col-last"><a class="th-sort" href="projects.php?<?= h(qs(['sort' => $sort === 'recent' ? null : 'recent', 'page' => null])) ?>" title="<?= h($sort === 'recent' ? t('Αλφαβητική σειρά') : t('Ταξινόμηση: όσα δουλεύτηκαν πιο πρόσφατα πρώτα')) ?>"><?= h(t('Τελευταία καταχώρηση')) ?> <?= icon($sort === 'recent' ? 'sort-down' : 'arrow-down-up', 'small') ?></a></th><th class="col-contrib"><?= h(t('Καταγραφές από')) ?></th><th class="col-tags" style="min-width:220px"><a class="th-sort" href="projects.php?<?= h(qs(['sort' => $sort === 'tags' ? null : 'tags', 'page' => null])) ?>" title="<?= h($sort === 'tags' ? t('Αλφαβητική σειρά') : t('Ταξινόμηση: πρώτα όσα έχουν όριο, το πιο γεμάτο πρώτο')) ?>"><?= h(t('Ώρες ανά tag')) ?> <?= icon($sort === 'tags' ? 'sort-down' : 'arrow-down-up', 'small') ?></a></th><th class="col-bill"><?= h(t('Χρέωση')) ?></th><?php if ($canCreate): ?><th></th><?php endif; ?></tr></thead>
      <?php foreach ($projects as $p):
          $sec = $usage[(int)$p['id']]['total'] ?? 0;
          $editable = can_edit_project($user, $p);
          $note = trim((string)($p['note'] ?? ''));
      ?>
      <tbody class="proj-group<?= $note !== '' ? ' has-note' : '' ?>">
        <tr class="<?= $p['archived'] ? 'row-muted' : '' ?>">
          <td>
            <span class="dot" style="background:<?= h($p['color']) ?>"></span><?php if ($editable): ?><a class="fw-medium text-decoration-none" href="project_edit.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a><?php else: ?><span class="fw-medium"><?= h($p['name']) ?></span><?php endif; ?>
            <?= $p['archived'] ? ' <span class="badge text-bg-light border">' . h(t('αρχείο')) . '</span>' : '' ?>
          </td>
          <td class="text-num"><?= fmt_hm($sec) ?></td>
          <td class="text-num col-last text-nowrap"><?= !empty($lastAt[$p['id']]) ? h(date('d/m/y', strtotime((string)$lastAt[$p['id']]))) : '<span class="text-body-tertiary">—</span>' ?></td>
          <td class="col-contrib"><?= avatar_stack($contrib[(int)$p['id']] ?? [], $editable) ?></td>
          <td class="col-tags">
            <?php if (empty($tagLines[(int)$p['id']])): ?><span class="text-body-tertiary">—</span><?php endif; ?>
            <?php foreach ($tagLines[(int)$p['id']] ?? [] as $line):
                $tsec = $tagUsage[(int)$p['id']][(int)$line['tag_id']] ?? 0;
                $tst = tag_budget_status($line, $tagUsage); ?>
              <div class="tag-line">
                <div class="d-flex justify-content-between gap-2 small">
                  <span class="text-truncate"><?= icon('tag', 'me-1 text-body-secondary') ?><?= h($line['tag_name']) ?></span>
                  <?php if ($tst): $tcol = budget_color($tst['state']); ?>
                    <span class="text-nowrap"><?= fmt_hm($tsec) ?> / <?= fmt_hm($tst['budget']) ?> · <span class="fw-semibold text-<?= $tcol ?>-emphasis"><?= fmt_pct($tst['pct']) ?></span></span>
                  <?php else: ?>
                    <span class="text-nowrap"><?= fmt_hm($tsec) ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($tst): ?><div class="progress tb-progress"><div class="progress-bar bg-<?= $tcol ?>" style="width:<?= min(100, round($tst['pct'], 1)) ?>%"></div></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </td>
          <td class="col-bill"><?= $p['billable'] ? h(t('Χρεώσιμο')) : '<span class="text-body-secondary">' . h(t('Μη χρεώσιμο')) . '</span>' ?></td>
          <?php if ($canCreate): ?><td class="text-end text-nowrap"><?php if ($editable): ?><?php if (!empty($firstDay[$p['id']])): ?><a class="btn btn-icon" href="<?= h(project_report_url((int)$p['id'], new DateTimeImmutable($firstDay[$p['id']]), now())) ?>" title="<?= h(t('Report του project')) ?>" aria-label="<?= h(t('Report του project')) ?>"><?= icon('chart') ?></a><?php endif; ?><a class="btn btn-icon" href="project_edit.php?id=<?= (int)$p['id'] ?>" title="<?= h(t('Επεξεργασία')) ?>"><?= icon('edit') ?></a><?php endif; ?></td><?php endif; ?>
        </tr>
        <?php if ($note !== ''): ?>
          <tr class="proj-note-row<?= $p['archived'] ? ' row-muted' : '' ?>"><td colspan="<?= $canCreate ? 8 : 7 ?>"><div class="proj-note" title="<?= h($note) ?>"><?= icon('chat-left-text') ?><span><?= h(mb_strlen($note) > 200 ? mb_substr($note, 0, 200) . '…' : $note) ?></span></div></td></tr>
        <?php endif; ?>
      </tbody>
      <?php endforeach; ?>
      <?php if (!$projects): ?><tbody><tr><td colspan="<?= $canCreate ? 8 : 7 ?>" class="text-body-secondary"><?= h(t('Δεν βρέθηκαν projects.')) ?><?php if ($canCreate): ?> <a href="project_edit.php"><?= h(t('Φτιάξε το πρώτο.')) ?></a><?php endif; ?></td></tr></tbody><?php endif; ?>
  </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
      <span class="small text-body-secondary"><?= h(t('{a}–{b} από {n} projects', ['a' => ($page - 1) * $perPage + 1, 'b' => min($total, $page * $perPage), 'n' => $total])) ?></span>
      <?= pagination_html('projects.php', $page, $pages) ?>
    </div>
  <?php endif; ?>
</div>
<?php render_footer();
