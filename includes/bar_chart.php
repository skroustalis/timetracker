<?php
/** Ραβδόγραμμα ανά ημέρα, στοιβαγμένο ανά project. Χρειάζεται $series, $maxDay. */
$__n = count($series);
$__step = $__n > 31 ? 7 : ($__n > 14 ? 2 : 1);
$__maxH = max(1, ceil($maxDay / 3600));
$__gridStep = $__maxH > 48 ? 12 : ($__maxH > 24 ? 8 : ($__maxH > 12 ? 4 : ($__maxH > 6 ? 2 : 1)));
$__maxH = (int)(ceil($__maxH / $__gridStep) * $__gridStep);
?>
<div class="barchart">
  <div class="bc-axis">
    <?php for ($hh = $__maxH; $hh >= 0; $hh -= $__gridStep): ?><span style="bottom:<?= $hh / $__maxH * 100 ?>%"><?= $hh ?>h</span><?php endfor; ?>
  </div>
  <div class="bc-plot">
    <?php for ($hh = $__gridStep; $hh <= $__maxH; $hh += $__gridStep): ?><i class="bc-grid" style="bottom:<?= $hh / $__maxH * 100 ?>%"></i><?php endfor; ?>
    <?php $i = 0; foreach ($series as $k => $d): ?>
      <div class="bc-col" title="<?= h(fmt_day($d['date']) . ': ' . fmt_dur($d['dur'])) ?>">
        <div class="bc-stack" style="height:<?= round($d['dur'] / ($__maxH * 3600) * 100, 2) ?>%">
          <?php foreach ($d['parts'] as $part): ?>
            <i style="flex:<?= $part['dur'] ?>;background:<?= h($part['color']) ?>" title="<?= h($part['label'] . ': ' . fmt_dur($part['dur'])) ?>"></i>
          <?php endforeach; ?>
          <?php if ($__n <= 14 && $d['dur'] > 0): ?><span class="bc-val mono"><?= fmt_dur($d['dur']) ?></span><?php endif; ?>
        </div>
        <?php // στο κινητό: χωρίς όνομα ημέρας, και σε περιόδους > 7 ημερών μόνο μία ετικέτα την εβδομάδα (για να μην πέφτουν η μία πάνω στην άλλη) ?>
        <span class="bc-label<?= $__n > 7 && $i % 7 !== 0 ? ' bc-minor' : '' ?>"><?php if ($i % $__step === 0): ?><?php if ($__n <= 7): ?><span class="bc-wd"><?= h(day_names_short()[(int)$d['date']->format('N') - 1]) ?> </span><?php endif; ?><?= h($d['date']->format('j/n')) ?><?php endif; ?></span>
      </div>
    <?php $i++; endforeach; ?>
  </div>
</div>
