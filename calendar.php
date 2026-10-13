<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$uid = (int)$user['id'];

$viewUid = $uid;
if (is_lead($user) && ($req = get_int('user')) && can_see_user($user, $req)) {
    if (q_val('SELECT 1 FROM tt_users WHERE id = ?', [$req])) $viewUid = $req;
}
$isOwn = $viewUid === $uid;

$anchor = parse_date((string)($_GET['date'] ?? '')) ?? now()->setTime(0, 0);
$ws = week_start($anchor);
$we = $ws->modify('+6 days');

// φέρνουμε και μία μέρα πριν για καταχωρήσεις που περνούν τα μεσάνυχτα
$cf = ['user_ids' => [$viewUid], 'from' => $ws->modify('-1 day'), 'to' => $we, 'order' => 'e.start_at ASC'];
// manager που βλέπει άλλον: μόνο ό,τι ανήκει στην ομάδα του (π.χ. όχι τις νέες ώρες κάποιου που άλλαξε ομάδα)
if (!$isOwn && !is_admin($user)) $cf += scope_filter($user);
$rows = entries_query($cf);

const HOUR_PX = 48;
$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = $ws->modify("+{$i} days");
    $days[$d->format('Y-m-d')] = ['date' => $d, 'segs' => [], 'total' => 0];
}
$nowTs = time();
foreach ($rows as $r) {
    $s = (new DateTimeImmutable($r['start_at']))->getTimestamp();
    $e = $r['end_at'] ? (new DateTimeImmutable($r['end_at']))->getTimestamp() : $nowTs;
    foreach ($days as $k => &$day) {
        $d0 = $day['date']->getTimestamp();
        $d1 = $day['date']->modify('+1 day')->getTimestamp();
        $a = max($s, $d0);
        $b = min($e, $d1);
        if ($b <= $a && !($s >= $d0 && $s < $d1)) continue;
        if ($b < $a) $b = $a;
        $day['segs'][] = ['r' => $r, 'a' => $a - $d0, 'b' => $b - $d0];
        $day['total'] += $b - $a;
    }
    unset($day);
}

// Διάταξη επικαλυπτόμενων σε "λωρίδες"
foreach ($days as &$day) {
    usort($day['segs'], fn($x, $y) => $x['a'] <=> $y['a'] ?: $y['b'] <=> $x['b']);
    $clusters = [];
    $cur = [];
    $curEnd = -1;
    foreach ($day['segs'] as $i => $sg) {
        $vis = max($sg['b'], $sg['a'] + 900); // ελάχιστο ύψος 15'
        if ($cur && $sg['a'] >= $curEnd) { $clusters[] = $cur; $cur = []; $curEnd = -1; }
        $cur[] = $i;
        $curEnd = max($curEnd, $vis);
    }
    if ($cur) $clusters[] = $cur;
    foreach ($clusters as $cl) {
        $laneEnds = [];
        foreach ($cl as $i) {
            $sg = &$day['segs'][$i];
            $vis = max($sg['b'], $sg['a'] + 900);
            $lane = null;
            foreach ($laneEnds as $li => $end) if ($end <= $sg['a']) { $lane = $li; break; }
            if ($lane === null) { $lane = count($laneEnds); }
            $laneEnds[$lane] = $vis;
            $sg['lane'] = $lane;
            unset($sg);
        }
        foreach ($cl as $i) $day['segs'][$i]['lanes'] = count($laneEnds);
    }
}
unset($day);

$weekTotal = array_sum(array_column($days, 'total'));
$users = is_lead($user) ? team_users($user) : [];
$return = 'calendar.php?' . qs();

render_header(t('Ημερολόγιο'), 'calendar');
ob_start(); ?>
  <form method="get" class="d-flex flex-wrap align-items-center gap-2">
    <?php if ($users): ?>
      <select name="user" class="form-select w-auto" onchange="this.form.submit()" aria-label="<?= h(t('Χρήστης')) ?>">
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $viewUid ? 'selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <div class="input-group w-auto">
      <a class="btn btn-outline-secondary" href="calendar.php?<?= h(qs(['date' => $ws->modify('-7 days')->format('Y-m-d')])) ?>" title="<?= h(t('Προηγούμενη')) ?>"><?= icon('chev-l') ?></a>
      <input type="date" class="form-control" name="date" value="<?= h($anchor->format('Y-m-d')) ?>" onchange="this.form.submit()" aria-label="<?= h(t('Ημερομηνία')) ?>">
      <a class="btn btn-outline-secondary" href="calendar.php?<?= h(qs(['date' => $ws->modify('+7 days')->format('Y-m-d')])) ?>" title="<?= h(t('Επόμενη')) ?>"><?= icon('chev-r') ?></a>
    </div>
    <a class="btn btn-outline-secondary" href="calendar.php?<?= h(qs(['date' => null])) ?>"><?= h(t('Σήμερα')) ?></a>
    <span class="text-body-secondary ms-1"><?= h(t('Σύνολο')) ?> <strong class="font-monospace text-body"><?= fmt_dur($weekTotal) ?></strong></span>
  </form>
<?php page_head(h(t('Ημερολόγιο')), ob_get_clean()); ?>

<div class="card cal" style="--hour: <?= HOUR_PX ?>px">
  <div class="cal-head">
    <div class="cal-gutter"></div>
    <?php foreach ($days as $k => $day): ?>
      <div class="cal-dayhead <?= $k === now()->format('Y-m-d') ? 'today' : '' ?>">
        <strong><?= h(fmt_day($day['date'])) ?></strong>
        <span class="font-monospace small text-body-secondary"><?= fmt_dur($day['total']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="cal-body" id="calBody">
    <div class="cal-gutter">
      <?php for ($hh = 0; $hh < 24; $hh++): ?><div class="cal-hour"><span><?= sprintf('%02d:00', $hh) ?></span></div><?php endfor; ?>
    </div>
    <?php foreach ($days as $k => $day): ?>
      <div class="cal-col <?= $isOwn ? 'can-add' : '' ?>" data-date="<?= h($k) ?>">
        <?php for ($hh = 0; $hh < 24; $hh++): ?><div class="cal-hour"></div><?php endfor; ?>
        <?php foreach ($day['segs'] as $sg):
            $r = $sg['r'];
            $top = $sg['a'] / 3600 * HOUR_PX;
            $height = max(($sg['b'] - $sg['a']) / 3600 * HOUR_PX, HOUR_PX / 4);
            $w = 100 / $sg['lanes'];
            $color = $r['project_color'] ?? '#90a4ae';
        ?>
          <button type="button" class="cal-ev <?= $r['running'] ? 'running' : '' ?>" data-edit-entry='<?= entry_json($r) ?>'
                  style="top:<?= round($top, 1) ?>px;height:<?= round($height, 1) ?>px;left:calc(<?= round($w * $sg['lane'], 3) ?>% + 2px);width:calc(<?= round($w, 3) ?>% - 4px);--c:<?= h($color) ?>"
                  title="<?= h(($r['description'] ?: t('Χωρίς περιγραφή')) . ' · ' . ($r['project_name'] ?? t('Χωρίς project')) . ' · ' . fmt_dur($r['dur'])) ?>">
            <strong><?= h($r['description'] !== '' ? $r['description'] : t('(Χωρίς περιγραφή)')) ?></strong>
            <span><?= h($r['project_name'] ?? '') ?></span>
            <span class="font-monospace"><?= fmt_time($r['start_at']) ?>–<?= $r['end_at'] ? fmt_time($r['end_at']) : h(t('τώρα')) ?></span>
          </button>
        <?php endforeach; ?>
        <?php if ($k === now()->format('Y-m-d')): $nt = (time() - $day['date']->getTimestamp()) / 3600 * HOUR_PX; ?>
          <div class="cal-now" style="top:<?= round($nt, 1) ?>px"></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php if ($isOwn): ?><p class="small text-body-secondary"><?= h(t('Κάνε κλικ σε κενό σημείο για να προσθέσεις χρόνο, ή σε μια καταχώρηση για να την αλλάξεις.')) ?></p><?php endif; ?>

<?php render_entry_dialog($user, $return); ?>
<?php render_footer();
