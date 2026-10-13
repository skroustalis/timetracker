<?php
/** Επιλογή περιόδου. Χρειάζεται $from, $to, $preset. Μπαίνει μέσα σε <form method="get">. */
$__len = $from->diff($to)->days + 1;
$__isMonth = $from->format('d') === '01' && $to->format('Y-m-d') === $to->modify('last day of this month')->format('Y-m-d');
if ($__isMonth) {
    $__prev = [$from->modify('first day of last month'), $from->modify('last day of last month')];
    $__next = [$from->modify('first day of next month'), $from->modify('first day of next month')->modify('last day of this month')];
} else {
    $__prev = [$from->modify("-{$__len} days"), $to->modify("-{$__len} days")];
    $__next = [$from->modify("+{$__len} days"), $to->modify("+{$__len} days")];
}
?>
<div class="d-flex flex-wrap align-items-center gap-2" data-range-picker>
  <select name="preset" class="form-select w-auto" data-preset data-nosearch aria-label="<?= h(t('Περίοδος')) ?>">
    <?php foreach (DATE_PRESETS as $k => $label): ?>
      <option value="<?= h($k) ?>" <?= $preset === $k ? 'selected' : '' ?>><?= h(t($label)) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="input-group w-auto">
    <a class="btn btn-outline-secondary" title="<?= h(t('Προηγούμενη περίοδος')) ?>" href="?<?= h(qs(['preset' => 'custom', 'from' => $__prev[0]->format('Y-m-d'), 'to' => $__prev[1]->format('Y-m-d'), 'page' => null])) ?>"><?= icon('chev-l') ?></a>
    <input type="date" class="form-control" name="from" value="<?= h($from->format('Y-m-d')) ?>" data-range-date aria-label="<?= h(t('Από')) ?>">
    <input type="date" class="form-control" name="to" value="<?= h($to->format('Y-m-d')) ?>" data-range-date aria-label="<?= h(t('Έως')) ?>">
    <a class="btn btn-outline-secondary" title="<?= h(t('Επόμενη περίοδος')) ?>" href="?<?= h(qs(['preset' => 'custom', 'from' => $__next[0]->format('Y-m-d'), 'to' => $__next[1]->format('Y-m-d'), 'page' => null])) ?>"><?= icon('chev-r') ?></a>
  </div>
</div>
