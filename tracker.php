<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$uid = (int)$user['id'];

$weeks = max(2, min(52, (int)($_GET['weeks'] ?? 3)));
$from = week_start(now())->modify('-' . ($weeks - 1) . ' weeks');
$rows = entries_query(['user_ids' => [$uid], 'from' => $from, 'to' => now()->setTime(0, 0)->modify('+1 day')]);
$olderExists = (bool)q_val('SELECT 1 FROM tt_entries WHERE user_id = ? AND start_at < ? LIMIT 1', [$uid, $from->format('Y-m-d H:i:s')]);

$running = running_entry($uid);
$runningTags = $running ? entry_tag_ids((int)$running['id']) : [];
$projects = visible_projects($user);
$tags = all_tags();
$return = 'tracker.php' . ($weeks !== 3 ? '?weeks=' . $weeks : '');

// Ομαδοποίηση: εβδομάδα -> ημέρα
$weeksData = [];
foreach ($rows as $r) {
    if ($r['running']) continue; // το τρέχον φαίνεται στη μπάρα
    $d = new DateTimeImmutable($r['start_at']);
    $wk = week_start($d)->format('Y-m-d');
    $dk = $d->format('Y-m-d');
    $weeksData[$wk]['total'] = ($weeksData[$wk]['total'] ?? 0) + $r['dur'];
    $weeksData[$wk]['days'][$dk]['total'] = ($weeksData[$wk]['days'][$dk]['total'] ?? 0) + $r['dur'];
    $weeksData[$wk]['days'][$dk]['rows'][] = $r;
}
krsort($weeksData);
foreach ($weeksData as &$w) krsort($w['days']);
unset($w);

$todayKey = now()->format('Y-m-d');
$yesterdayKey = now()->modify('-1 day')->format('Y-m-d');
$thisWeekKey = week_start(now())->format('Y-m-d');

render_header(t('Χρονομέτρηση'), 'tracker');
?>
<form method="post" action="entry_action.php" class="card trackbar mb-2 <?= $running ? 'is-running' : '' ?>" id="trackbar" data-mode="timer">
  <div class="card-body">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= h($return) ?>">
    <input type="hidden" name="action" value="<?= $running ? 'stop' : 'start' ?>">
    <input class="form-control tb-desc" type="text" name="description" maxlength="500" placeholder="<?= h(t('Σε τι δουλεύεις;')) ?>" value="<?= h($running['description'] ?? '') ?>" autocomplete="off" list="recentDescriptions" aria-label="<?= h(t('Περιγραφή')) ?>">
    <select name="project_id" class="form-select tb-select" data-project-select aria-label="Project"><?= project_options($projects, $running['project_id'] ?? null, '+ Project') ?></select>
    <?= tag_picker($tags, $runningTags) ?>
    <span class="billable-toggle">
      <input type="checkbox" class="btn-check" id="tbBill" name="billable" value="1" autocomplete="off" <?= !empty($running['billable']) ? 'checked' : '' ?>>
      <label class="btn btn-icon btn-bill" for="tbBill" title="<?= h(t('Χρεώσιμο')) ?>"><?= icon('euro') ?></label>
    </span>

    <?php if ($running): ?>
      <span class="tb-timer font-monospace" data-since="<?= (new DateTimeImmutable($running['start_at']))->getTimestamp() ?>">00:00:00</span>
      <button class="btn btn-danger tb-btn" type="submit">STOP</button>
      <button class="btn btn-icon" type="submit" name="action" value="discard" title="<?= h(t('Ακύρωση χρονομέτρου')) ?>" data-confirm="<?= h(t('Ακύρωση του χρονομέτρου; Ο χρόνος δεν θα αποθηκευτεί.')) ?>"><?= icon('x') ?></button>
    <?php else: ?>
      <span class="tb-manual">
        <input type="date" class="form-control" name="date" value="<?= h($todayKey) ?>" aria-label="<?= h(t('Ημερομηνία')) ?>">
        <input type="text" class="form-control tb-time" name="start" placeholder="09:00" inputmode="numeric" aria-label="<?= h(t('Έναρξη')) ?>">
        <span>–</span>
        <input type="text" class="form-control tb-time" name="end" placeholder="10:30" inputmode="numeric" aria-label="<?= h(t('Λήξη')) ?>">
      </span>
      <span class="tb-timer tb-timer-idle font-monospace">00:00:00</span>
      <button class="btn btn-primary tb-btn" type="submit" data-label-timer="START" data-label-manual="<?= h(t('ΠΡΟΣΘΗΚΗ')) ?>">START</button>
      <span class="tb-modes d-flex flex-column">
        <button type="button" class="btn btn-icon active py-0" data-mode-btn="timer" title="<?= h(t('Χρονόμετρο')) ?>"><?= icon('clock') ?></button>
        <button type="button" class="btn btn-icon py-0" data-mode-btn="manual" title="<?= h(t('Χειροκίνητα')) ?>"><?= icon('list') ?></button>
      </span>
    <?php endif; ?>
  </div>
</form>

<?php
$budgetMap = [];
// Όρια ανά tag: φαίνονται πάντα όταν διαλέγεις το project (π.χ. «Northwind · Support: 01:30 / 05:00 (30%)»).
// Από το 80% και πάνω μπαίνει το πλήρες μήνυμα προειδοποίησης.
$tagLimits = tag_limits_for($user);
$alerts = budget_alerts($user);
$pNames = array_column($projects, 'name', 'id');
foreach ($tagLimits as $pid => $lim) {
    foreach ($lim as $tid => $tl) {
        if (isset($alerts[$pid . ':t' . $tid])) continue;
        $msg = t('{p} · {t}: {u} / {b} ({pct})', ['p' => $pNames[$pid] ?? '', 't' => $tl['tag_name'], 'u' => fmt_hm($tl['status']['used']), 'b' => fmt_hm($tl['status']['budget']), 'pct' => fmt_pct($tl['status']['pct'])]);
        if (isset($budgetMap[$pid])) $budgetMap[$pid]['msg'] .= '  ·  ' . $msg;
        else $budgetMap[$pid] = ['state' => 'ok', 'msg' => $msg];
    }
}
// ανά project: αν έχει και όρια ανά tag, τα μηνύματα μπαίνουν μαζί και κρατάμε τη χειρότερη κατάσταση
foreach ($alerts as $ba) {
    $pid = (int)$ba['project']['id'];
    $msg = budget_message($ba['project'], $ba['status']);
    if (isset($budgetMap[$pid])) {
        $budgetMap[$pid]['msg'] = $msg . '  ·  ' . $budgetMap[$pid]['msg'];
        if ($ba['status']['state'] === 'over' || $budgetMap[$pid]['state'] === 'ok') $budgetMap[$pid]['state'] = $ba['status']['state'];
    } else {
        $budgetMap[$pid] = ['state' => $ba['status']['state'], 'msg' => $msg];
    }
}
?>
<div id="budgetWarn" class="alert py-2 mb-2 d-none" role="status"></div>
<script>window.TT_BUDGET = <?= json_encode((object)$budgetMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>

<datalist id="recentDescriptions">
  <?php foreach (q_all("SELECT description FROM tt_entries WHERE user_id = ? AND start_at >= ? AND description <> '' GROUP BY description ORDER BY MAX(start_at) DESC LIMIT 30", [$uid, now()->modify('-90 days')->format('Y-m-d H:i:s')]) as $d): ?>
    <option value="<?= h($d['description']) ?>">
  <?php endforeach; ?>
</datalist>

<?php if (!$weeksData): ?>
  <div class="card mt-4">
    <div class="card-body text-center py-5">
      <div class="display-5 text-body-tertiary mb-3"><?= icon('stopwatch') ?></div>
      <h2 class="h5"><?= h(t('Ξεκίνα να μετράς χρόνο')) ?></h2>
      <p class="text-body-secondary mb-0"><?= h(t('Γράψε σε τι δουλεύεις, διάλεξε project και πάτα START. Ή πάτα το εικονίδιο λίστας για να βάλεις ώρες χειροκίνητα.')) ?></p>
    </div>
  </div>
<?php endif; ?>

<?php foreach ($weeksData as $wk => $w):
    $ws = new DateTimeImmutable($wk);
    $we = $ws->modify('+6 days');
    $wLabel = $wk === $thisWeekKey ? t('Αυτή την εβδομάδα') : fmt_short($ws) . ' – ' . fmt_short($we);
?>
  <div class="week-head"><span><?= h($wLabel) ?></span><span><?= h(t('Σύνολο εβδομάδας')) ?> <strong class="font-monospace ms-1"><?= fmt_dur($w['total']) ?></strong></span></div>
  <?php foreach ($w['days'] as $dk => $day):
      $dd = new DateTimeImmutable($dk);
      $dLabel = $dk === $todayKey ? t('Σήμερα') : ($dk === $yesterdayKey ? t('Χθες') : fmt_day($dd));
  ?>
    <div class="card day-card mb-3">
      <div class="card-header"><span><?= h($dLabel) ?></span><span><?= h(t('Σύνολο')) ?> <strong class="font-monospace ms-1"><?= fmt_dur($day['total']) ?></strong></span></div>
      <div>
      <?php foreach ($day['rows'] as $r): ?>
        <div class="entry" data-edit-entry='<?= entry_json($r) ?>'>
          <button type="button" class="entry-desc" data-edit-trigger>
            <?= $r['description'] !== '' ? h($r['description']) : '<span class="text-body-tertiary">' . h(t('Προσθήκη περιγραφής')) . '</span>' ?>
          </button>
          <span class="entry-project">
            <?php if ($r['project_id']): ?>
              <span class="dot" style="background:<?= h($r['project_color']) ?>"></span><span class="fw-medium" style="color:<?= h($r['project_color']) ?>"><?= h($r['project_name']) ?></span>
            <?php else: ?>
              <span class="text-body-tertiary"><?= h(t('Χωρίς project')) ?></span>
            <?php endif; ?>
          </span>
          <span class="entry-tags"><?php if ($r['tag_names']): ?><?= icon('tag', 'me-1') ?><?= h($r['tag_names']) ?><?php endif; ?></span>
          <?php // όριο του tag στο project (π.χ. Support 01:30 / 05:00), μόνο για καταχωρήσεις του τρέχοντος πακέτου
          foreach ($r['tag_id_list'] ?? [] as $tid):
              $tl = $tagLimits[(int)$r['project_id']][$tid] ?? null;
              if (!$tl || ($tl['start_at'] !== null && (string)($r['end_at'] ?? $r['start_at']) <= (string)$tl['start_at'])) continue;
              $tst = $tl['status']; ?>
            <span class="entry-limit is-<?= h($tst['state']) ?>" title="<?= h(t('{t}: όριο {b} · {pct}', ['t' => $tl['tag_name'], 'b' => fmt_hm($tst['budget']), 'pct' => fmt_pct($tst['pct'])])) ?>"><?= h($tl['tag_name']) ?> <?= fmt_hm($tst['used']) ?> / <?= fmt_hm($tst['budget']) ?></span>
          <?php endforeach; ?>
          <span class="entry-bill <?= $r['billable'] ? 'on' : '' ?>" title="<?= h($r['billable'] ? t('Χρεώσιμο') : t('Μη χρεώσιμο')) ?>"><?= icon('euro') ?></span>
          <span class="entry-times font-monospace"><?= fmt_time($r['start_at']) ?> – <?= fmt_time($r['end_at']) ?><?php if (substr((string)$r['end_at'], 0, 10) !== substr($r['start_at'], 0, 10)): ?><sup>+1</sup><?php endif; ?></span>
          <span class="entry-dur font-monospace"><?= fmt_dur($r['dur']) ?></span>
          <form method="post" action="entry_action.php" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="continue"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return" value="<?= h($return) ?>">
            <button class="btn btn-icon fs-5" type="submit" title="<?= h(t('Συνέχεια (νέο χρονόμετρο)')) ?>"><?= icon('play') ?></button>
          </form>
          <div class="dropdown">
            <button class="btn btn-icon fs-5" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= h(t('Περισσότερα')) ?>"><?= icon('dots') ?></button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
              <li><button type="button" class="dropdown-item" data-edit-trigger><?= icon('edit', 'me-2') ?><?= h(t('Επεξεργασία')) ?></button></li>
              <li>
                <form method="post" action="entry_action.php">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return" value="<?= h($return) ?>">
                  <button type="submit" class="dropdown-item" name="action" value="duplicate"><?= icon('copy', 'me-2') ?><?= h(t('Αντιγραφή')) ?></button>
                  <button type="submit" class="dropdown-item text-danger" name="action" value="delete" data-confirm="<?= h(t('Διαγραφή αυτής της καταχώρησης;')) ?>"><?= icon('trash', 'me-2') ?><?= h(t('Διαγραφή')) ?></button>
                </form>
              </li>
            </ul>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>

<?php if ($olderExists): ?>
  <div class="text-center my-4"><a class="btn btn-outline-secondary" href="tracker.php?weeks=<?= $weeks + 4 ?>"><?= h(t('Φόρτωση παλαιότερων')) ?></a></div>
<?php endif; ?>

<?php render_entry_dialog($user, $return); ?>
<?php render_footer();
