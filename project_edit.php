<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// Admin: όλα τα projects. Manager: φτιάχνει και διορθώνει τα projects της ομάδας του.
$user = require_lead();
$admin = is_admin($user);
$myGroup = managed_group_id($user);

$id = get_int('id') ?? post_int('id');
$p = $id ? q_one('SELECT * FROM tt_projects WHERE id = ?', [$id]) : null;
if (($id && !$p) || !can_edit_project($user, $p)) { flash('error', t('Το project δεν βρέθηκε.')); redirect('projects.php'); }
$pGroups = $p ? array_map('intval', array_column(q_all('SELECT group_id FROM tt_project_groups WHERE project_id = ?', [$p['id']]), 'group_id')) : [];

if (is_post()) {
    csrf_check();
    $act = (string)($_POST['act'] ?? 'save');

    // «Νέο πακέτο» σε μια γραμμή tag (admin ή manager της ομάδας: μόνο αυτοί ανοίγουν αυτή τη σελίδα)
    $resetId = post_int('tb_reset');
    if ($p && $resetId) {
        $line = q_one('SELECT b.id, t.name AS tag_name FROM tt_project_tag_budgets b JOIN tt_tags t ON t.id = b.tag_id WHERE b.id = ? AND b.project_id = ?', [$resetId, $p['id']]);
        $res = $line ? reset_tag_budget((int)$line['id'], (int)$user['id']) : null;
        if ($res) {
            flash('success', $res['carry'] > 0
                ? t('Νέο πακέτο για «{t}». Το προηγούμενο έκλεισε στο {u} και τα {c} που πέρασαν το όριο μεταφέρθηκαν στο νέο.', ['t' => $line['tag_name'], 'u' => fmt_hm($res['used']), 'c' => fmt_hm($res['carry'])])
                : t('Νέο πακέτο για «{t}». Το προηγούμενο έκλεισε στο {u} και το νέο ξεκινά από 00:00.', ['t' => $line['tag_name'], 'u' => fmt_hm($res['used'])]));
        }
        redirect('project_edit.php?id=' . (int)$p['id']);
    }

    if ($act === 'save') {
        $name = post_str('name', 150);
        $clientId = null; // η εφαρμογή δεν έχει πια πελάτες
        $color = valid_color(post_str('color', 7));
        $billable = !empty($_POST['billable']) ? 1 : 0;
        // το όριο ωρών όλου του project καταργήθηκε: τα όρια μπαίνουν μόνο ανά tag
        $budget = null;
        $budgetPeriod = 'total';
        $budgetNotify = 0; // τα email ορίων καταργήθηκαν
        // κάθε project ανήκει σε μία ομάδα και το βλέπουν μόνο τα μέλη της
        $visibility = 'groups';
        if ($admin) {
            $ownerGroup = post_int('owner_group_id');
            if (!$ownerGroup || !q_val('SELECT 1 FROM tt_groups WHERE id = ?', [$ownerGroup])) $ownerGroup = current_dept();
        } else {
            $ownerGroup = $myGroup; // ο manager: πάντα η ομάδα του
        }
        $groups = $ownerGroup ? [$ownerGroup] : [];
        $note = post_str('note', 2000);
        $archived = !empty($_POST['archived']) ? 1 : 0;

        if ($name === '') {
            flash('error', t('Το όνομα είναι υποχρεωτικό.'));
        } else {
            if ($p) {
                q('UPDATE tt_projects SET name=?, client_id=?, group_id=?, color=?, billable=?, budget_hours=?, budget_period=?, budget_notify=?, visibility=?, archived=?, note=? WHERE id=?',
                    [$name, $clientId, $ownerGroup, $color, $billable, $budget, $budgetPeriod, $budgetNotify, $visibility, $archived, $note, $p['id']]);
                $pid = (int)$p['id'];
            } else {
                $pid = q_insert('INSERT INTO tt_projects (name, client_id, group_id, color, billable, budget_hours, budget_period, budget_notify, visibility, archived, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$name, $clientId, $ownerGroup, $color, $billable, $budget, $budgetPeriod, $budgetNotify, $visibility, $archived, $note, now_sql()]);
            }
            q('DELETE FROM tt_project_groups WHERE project_id = ?', [$pid]);
            foreach ($groups as $gid) q('INSERT IGNORE INTO tt_project_groups (project_id, group_id) SELECT ?, id FROM tt_groups WHERE id = ?', [$pid, $gid]);
            // όρια ανά tag: tag => [ώρες (κενό = μόνο μέτρηση ωρών), «Μετράει από» (κενό = από την αρχή)]
            $want = [];
            foreach ((array)($_POST['tb_tag'] ?? []) as $i => $tid) {
                $tid = (int)$tid;
                if ($tid <= 0) continue;
                $from = trim((string)($_POST['tb_from'][$i] ?? ''));
                $fromD = $from !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $from) : false;
                $want[$tid] = [
                    'hours' => parse_limit_hours((string)($_POST['tb_hours'][$i] ?? '')),
                    'from' => $fromD ? $fromD->format('Y-m-d') : null,
                ];
            }
            if ($want) $want = array_intersect_key($want, array_flip(array_map('intval', array_column(all_tags($ownerGroup), 'id'))));
            $have = [];
            foreach (q_all('SELECT * FROM tt_project_tag_budgets WHERE project_id = ?', [$pid]) as $r) $have[(int)$r['tag_id']] = $r;
            foreach ($have as $tid => $r) {
                if (!array_key_exists($tid, $want)) {
                    q('DELETE FROM tt_project_tag_budgets WHERE id = ?', [(int)$r['id']]);
                    continue;
                }
                $w = $want[$tid];
                // ίδια ημερομηνία: κρατάμε την ακριβή ώρα (π.χ. του «Νέο πακέτο»)
                $oldFrom = $r['start_at'] !== null ? substr((string)$r['start_at'], 0, 10) : null;
                $start = $w['from'] === $oldFrom ? $r['start_at'] : ($w['from'] !== null ? $w['from'] . ' 00:00:00' : null);
                $oldH = $r['budget_hours'] !== null ? round((float)$r['budget_hours'], 4) : null;
                if ($oldH !== $w['hours'] || $start !== $r['start_at']) {
                    // άλλαξε το όριο ή η ημερομηνία: ξαναμετράμε τα επίπεδα ειδοποίησης από την αρχή.
                    // Νέα ημερομηνία με το χέρι = μετράει μόνο από εκεί, χωρίς μεταφορά από το προηγούμενο πακέτο.
                    $carry = $start !== $r['start_at'] ? 0 : (int)$r['carry_secs'];
                    q('UPDATE tt_project_tag_budgets SET budget_hours = ?, start_at = ?, carry_secs = ?, alert_key = NULL, alert_level = 0 WHERE id = ?', [$w['hours'], $start, $carry, (int)$r['id']]);
                }
            }
            foreach ($want as $tid => $w) {
                if (isset($have[$tid])) continue;
                // αν το tag είχε παλιότερα πακέτα σε αυτό το project, η αρίθμηση συνεχίζει
                $next = (int)q_val('SELECT COALESCE(MAX(package_no), 0) + 1 FROM tt_tag_budget_history WHERE project_id = ? AND tag_id = ?', [$pid, $tid]);
                q('INSERT INTO tt_project_tag_budgets (project_id, tag_id, budget_hours, start_at, package_no, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$pid, $tid, $w['hours'], $w['from'] !== null ? $w['from'] . ' 00:00:00' : null, $next, now_sql()]);
            }
            check_budget_alerts($pid);
            flash('success', t('Το project αποθηκεύτηκε.'));
            redirect('projects.php');
        }
    } elseif ($p && $act === 'delete') {
        q('DELETE FROM tt_projects WHERE id = ?', [$p['id']]);
        flash('success', t('Το project διαγράφηκε. Οι καταχωρήσεις του έμειναν χωρίς project.'));
        redirect('projects.php');
    }
}

$groups = $admin ? all_groups() : q_all('SELECT id, name, color FROM tt_groups WHERE id = ?', [$myGroup]);
$entryCount = $p ? (int)q_val('SELECT COUNT(*) FROM tt_entries WHERE project_id = ?', [$p['id']]) : 0;
$defaultColor = PALETTE[(int)q_val('SELECT COUNT(*) FROM tt_projects') % count(PALETTE)];
$tagLines = $p ? tag_budgets((int)$p['id']) : [];
$tagUsage = $p ? tag_budget_usage((int)$p['id']) : [];
$tagHistory = $p ? tag_budget_history((int)$p['id']) : [];
$allTags = all_tags($p && $p['group_id'] !== null ? (int)$p['group_id'] : current_dept());

render_header($p ? $p['name'] : t('Νέο project'), 'projects');
page_head('<a href="projects.php" class="text-body-secondary text-decoration-none">Projects</a> <span class="text-body-tertiary">/</span> ' . ($p ? h($p['name']) : h(t('Νέο project'))));
?>
<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" class="card">
      <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save">
        <?php /* Enter μέσα στη φόρμα = Αποθήκευση (όχι το πρώτο «Νέο πακέτο») */ ?>
        <button type="submit" class="tb-default-submit" tabindex="-1" aria-hidden="true"></button>
        <?php if ($p): ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><?php endif; ?>
        <div class="row g-3 mb-3">
          <div class="col-sm-9">
            <label class="form-label" for="pName"><?= h(t('Όνομα')) ?> *</label>
            <input type="text" class="form-control" id="pName" name="name" value="<?= h($p['name'] ?? '') ?>" required maxlength="150" autofocus>
          </div>
          <div class="col-sm-3">
            <label class="form-label" for="pColor"><?= h(t('Χρώμα')) ?></label>
            <input type="color" class="form-control form-control-color w-100" id="pColor" name="color" value="<?= h($p['color'] ?? $defaultColor) ?>">
          </div>
        </div>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="pBill" name="billable" value="1" <?= ($p['billable'] ?? 1) ? 'checked' : '' ?>>
          <label class="form-check-label" for="pBill"><?= h(t('Χρεώσιμο από προεπιλογή')) ?></label>
        </div>


        <?php
        // μία γραμμή «tag + ώρες + μετράει από» (και για το template των νέων γραμμών)
        $tbRow = function (?array $line) use ($allTags, $tagUsage, $tagHistory, $p): string {
            $sel = $line ? (int)$line['tag_id'] : 0;
            $opts = '<option value="">— ' . h(t('Tag')) . ' —</option>';
            foreach ($allTags as $tg) $opts .= '<option value="' . (int)$tg['id'] . '"' . ($sel === (int)$tg['id'] ? ' selected' : '') . '>' . h($tg['name']) . '</option>';
            $info = '';
            $extra = '';
            if ($line) {
                $used = $tagUsage[(int)$line['project_id']][(int)$line['tag_id']] ?? 0;
                $carry = (int)$line['carry_secs'];
                $tst = tag_budget_status($line, $tagUsage);
                if ($tst) {
                    $col = budget_color($tst['state']);
                    $info = '<div class="d-flex justify-content-between small mb-1"><span>' . fmt_hm($used) . ' / ' . fmt_hm($tst['budget']) . '</span><span class="fw-semibold text-' . $col . '-emphasis">' . fmt_pct($tst['pct']) . '</span></div>'
                        . '<div class="progress tb-progress"><div class="progress-bar bg-' . $col . '" style="width:' . min(100, round($tst['pct'], 1)) . '%"></div></div>';
                } else {
                    $info = '<div class="small"><span class="fw-semibold">' . fmt_hm($used) . '</span> <span class="text-body-secondary">' . h(t('καταγράφηκαν')) . '</span></div>';
                }
                if ($carry > 0) $info .= '<div class="small text-body-secondary mt-1">' . h(t('μαζί με {c} από το προηγούμενο πακέτο', ['c' => fmt_hm($carry)])) . '</div>';

                // «Νέο πακέτο» + ιστορικό
                $hist = $tagHistory[(int)$line['tag_id']] ?? [];
                $confirm = $tst && $tst['over'] > 0 && $line['start_at'] !== null
                    ? t('Νέο πακέτο για «{t}»; Το τρέχον κλείνει στο {u} και τα {c} πάνω από το όριο περνούν στο νέο πακέτο.', ['t' => $line['tag_name'], 'u' => fmt_hm($used), 'c' => fmt_hm($tst['over'])])
                    : t('Νέο πακέτο για «{t}»; Το τρέχον κλείνει στο {u} και το νέο ξεκινά από 00:00.', ['t' => $line['tag_name'], 'u' => fmt_hm($used)]);
                $extra = '<div class="d-flex flex-wrap align-items-center gap-2 mt-1 tb-pkg">'
                    . '<span class="small text-body-secondary">' . h(t('Πακέτο {n}', ['n' => (int)$line['package_no']])) . ($line['start_at'] ? ' · ' . h(t('από {d}', ['d' => fmt_date($line['start_at']) . ' ' . fmt_time($line['start_at'])])) : '') . '</span>'
                    . '<button type="submit" class="btn btn-light btn-sm" name="tb_reset" value="' . (int)$line['id'] . '" formnovalidate data-confirm="' . h($confirm) . '">' . icon('arrow-counterclockwise', 'me-1') . h(t('Νέο πακέτο')) . '</button>'
                    . '</div>';
                if ($hist) {
                    $rows = '';
                    foreach ($hist as $hr) {
                        $hb = $hr['budget_hours'] !== null ? (int)round((float)$hr['budget_hours'] * 3600) : 0;
                        $rows .= '<tr>'
                            . '<td>' . (int)$hr['package_no'] . '</td>'
                            . '<td class="text-nowrap">' . h($hr['start_at'] ? fmt_date($hr['start_at']) : t('αρχή')) . ' – ' . h(fmt_date($hr['end_at'])) . '</td>'
                            . '<td class="text-num text-nowrap">' . fmt_hm($hr['used_secs']) . ($hb ? ' / ' . fmt_hm($hb) : '') . '</td>'
                            . '<td class="text-num text-nowrap">' . ((int)$hr['carry_out_secs'] > 0 ? '+' . fmt_hm($hr['carry_out_secs']) : '—') . '</td>'
                            . '<td>' . h($hr['by_name'] ?? '—') . '</td>'
                            . '</tr>';
                    }
                    $extra .= '<details class="mt-2 tb-history"><summary class="small">' . icon('clock-history', 'me-1') . h(t('Ιστορικό πακέτων ({n})', ['n' => count($hist)])) . '</summary>'
                        . '<div class="table-responsive mt-2"><table class="table table-sm small align-middle mb-0">'
                        . '<thead><tr><th>#</th><th>' . h(t('Περίοδος')) . '</th><th class="text-num">' . h(t('Καταγράφηκαν')) . '</th><th class="text-num">' . h(t('Μεταφέρθηκαν')) . '</th><th>' . h(t('Μηδενισμός από')) . '</th></tr></thead>'
                        . '<tbody>' . $rows . '</tbody></table></div></details>';
                }
            }
            $from = $line ? ($line['start_at'] ? substr((string)$line['start_at'], 0, 10) : '') : now()->format('Y-m-d');
            $hoursVal = isset($line['budget_hours']) && $line['budget_hours'] !== null ? fmt_hm((float)$line['budget_hours'] * 3600) : '';
            return '<div class="tb-row" data-tb-row><div class="row g-2 align-items-center">'
                . '<div class="col-sm-3"><select class="form-select" name="tb_tag[]" aria-label="Tag">' . $opts . '</select></div>'
                . '<div class="col-sm-2"><input type="text" class="form-control" name="tb_hours[]" inputmode="numeric" value="' . h($hoursVal) . '" placeholder="' . h(t('χωρίς όριο')) . '" title="' . h(t('Ώρες:λεπτά, π.χ. 05:00')) . '" aria-label="' . h(t('Όριο (ώρες:λεπτά)')) . '"></div>'
                . '<div class="col-sm-3"><input type="date" class="form-control" name="tb_from[]" value="' . h($from) . '" placeholder="' . h(t('από την αρχή')) . '" title="' . h(t('Μετράει από (κενό = από την αρχή)')) . '" aria-label="' . h(t('Μετράει από')) . '"></div>'
                . '<div class="col">' . $info . '</div>'
                . '<div class="col-auto"><button type="button" class="btn btn-icon" data-tb-remove title="' . h(t('Αφαίρεση')) . '" aria-label="' . h(t('Αφαίρεση')) . '">' . icon('x') . '</button></div>'
                . '</div>' . $extra . '</div>';
        };
        ?>
        <fieldset class="border rounded-3 p-3 mb-3" data-tb>
          <legend class="float-none w-auto px-2 fs-6 mb-0"><?= icon('hourglass-split', 'me-1') ?><?= h(t('Όρια ωρών ανά tag')) ?></legend>
          <div class="form-text mt-0 mb-2"><?= h(t('Μετράει τον χρόνο αυτού του project που έχει το tag, π.χ. «Support» με όριο 05:00. Μετράει από την ημερομηνία «Μετράει από» και μετά (κενή = από την αρχή). Αν αφήσεις το όριο κενό, απλώς μετράει πόσος χρόνος γράφτηκε (π.χ. για εργασία με σταθερή τιμή).')) ?></div>
          <div class="row g-2 small text-body-secondary d-none d-sm-flex mb-1"><div class="col-sm-3">Tag</div><div class="col-sm-2"><?= h(t('Όριο (ώρες:λεπτά)')) ?></div><div class="col-sm-3"><?= h(t('Μετράει από')) ?></div></div>
          <div class="d-flex flex-column gap-2" data-tb-list>
            <?php foreach ($tagLines as $line) echo $tbRow($line); ?>
          </div>
          <div class="small text-body-secondary" data-tb-empty<?= $tagLines ? ' hidden' : '' ?>><?= h(t('Δεν υπάρχουν ακόμη ώρες ανά tag.')) ?></div>
          <template><?= $tbRow(null) ?></template>
          <button type="button" class="btn btn-light btn-sm mt-2" data-tb-add><?= icon('plus', 'me-1') ?><?= h(t('Προσθήκη tag')) ?></button>
          <div class="form-text mt-3"><?= h(t('Η μπάρα γίνεται κίτρινη στο 80% και κόκκινη όταν ξεπεραστεί. Οι admin και ο manager της ομάδας βλέπουν ειδοποίηση στο καμπανάκι.')) ?> <?= h(t('Με το «Νέο πακέτο» η μέτρηση ξεκινά από την αρχή και ό,τι πέρασε πάνω από το όριο μεταφέρεται στο νέο πακέτο. Οι καταχωρήσεις δεν σβήνονται.')) ?></div>
        </fieldset>

        <?php if ($admin): $pg = (int)($p['group_id'] ?? current_dept()); ?>
        <div class="mb-3">
          <label class="form-label" for="pOwner"><?= h(t('Ομάδα')) ?></label>
          <select class="form-select" id="pOwner" name="owner_group_id" required>
            <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" data-color="<?= h($g['color']) ?>" <?= $pg === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text"><?= h(t('Το project το βλέπουν μόνο τα μέλη αυτής της ομάδας. Ο manager της το διορθώνει και παίρνει τις ειδοποιήσεις για τα όρια ωρών.')) ?></div>
        </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label" for="pNote"><?= h(t('Σημειώσεις')) ?></label>
          <textarea class="form-control" id="pNote" name="note" rows="3" maxlength="2000"><?= h($p['note'] ?? '') ?></textarea>
        </div>
        <?php if ($p): ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="pArch" name="archived" value="1" <?= $p['archived'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="pArch"><?= h(t('Αρχειοθετημένο (δεν εμφανίζεται για νέες καταχωρήσεις)')) ?></label>
          </div>
        <?php endif; ?>
        <div class="d-flex gap-2">
          <button class="btn btn-primary" type="submit"><?= h(t('Αποθήκευση')) ?></button>
          <a class="btn btn-light" href="projects.php"><?= h(t('Πίσω')) ?></a>
        </div>
      </div>
    </form>
  </div>

  <?php if ($p): ?>
  <div class="col-xl-5">
    <?php $people = project_contributors((int)$p['id']); if (!$admin) $people = contributors_without_admins($people); $people = $people[(int)$p['id']] ?? []; $ptotal = array_sum(array_column($people, 'secs')); ?>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= icon('people', 'me-2') ?><?= h(t('Ποιοι δούλεψαν εδώ')) ?></span>
        <a class="small fw-normal" href="reports.php?tab=summary&amp;project=<?= (int)$p['id'] ?>&amp;by1=user&amp;by2=description&amp;preset=custom&amp;from=2000-01-01&amp;to=<?= h(now()->format('Y-m-d')) ?>"><?= h(t('Αναφορά')) ?> <?= icon('chev-r') ?></a>
      </div>
      <?php if (!$people): ?>
        <div class="card-body text-body-secondary small"><?= h(t('Δεν έχει καταγραφεί χρόνος ακόμη.')) ?></div>
      <?php else: ?>
      <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr><th><?= h(t('Χρήστης')) ?></th><th class="text-num"><?= h(t('Σύνολο')) ?></th><th class="text-num"><?= h(t('Αυτόν τον μήνα')) ?></th><th class="text-num"><?= h(t('Τελευταία')) ?></th></tr></thead>
        <?php foreach ($people as $pp): $pct = $ptotal ? $pp['secs'] / $ptotal * 100 : 0; ?>
          <tr>
            <td>
              <a class="d-flex align-items-center gap-2 text-decoration-none text-body" href="reports.php?tab=detailed&amp;project=<?= (int)$p['id'] ?>&amp;user=<?= (int)$pp['user_id'] ?>&amp;preset=custom&amp;from=2000-01-01&amp;to=<?= h(now()->format('Y-m-d')) ?>">
                <?= avatar_html(['name' => $pp['name'], 'avatar' => $pp['avatar']], 'avatar-sm') ?>
                <span><?= h($pp['name']) ?><div class="progress mt-1" style="width:110px;height:4px"><div class="progress-bar" style="width:<?= round($pct, 1) ?>%;background:<?= h($p['color']) ?>"></div></div></span>
              </a>
            </td>
            <td class="text-num"><?= fmt_hm($pp['secs']) ?><div class="small text-body-secondary"><?= fmt_pct($pct) ?></div></td>
            <td class="text-num"><?= fmt_hm($pp['month_secs']) ?></td>
            <td class="text-num small text-body-secondary"><?= h(fmt_date($pp['last_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <?php endif; ?>
    </div>
    <div class="card border-danger-subtle">
      <div class="card-body">
        <h2 class="h6"><?= h(t('Διαγραφή project')) ?></h2>
        <p class="small text-body-secondary"><?= h(t('{n} καταχωρήσεις χρόνου είναι σε αυτό το project. Αν το διαγράψεις, θα μείνουν χωρίς project. Καλύτερα να το αρχειοθετήσεις.', ['n' => $entryCount])) ?></p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn btn-outline-danger" name="act" value="delete" data-confirm="<?= h(t('Οριστική διαγραφή του project;')) ?>"><?= icon('trash', 'me-1') ?><?= h(t('Διαγραφή')) ?></button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php render_footer();
