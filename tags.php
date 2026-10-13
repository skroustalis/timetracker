<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$admin = is_admin($user);
$dept = current_dept($user); // τα tags είναι χωριστά ανά ομάδα
// Μετονομασία / διαγραφή: ο admin και ο manager (μόνο τα tags της ομάδας του)
$canEdit = $admin || managed_group_id($user) !== null;
$inDept = fn(int $tid) => ($admin && $dept === null) || (bool)q_val('SELECT 1 FROM tt_tags WHERE id = ? AND group_id = ?', [$tid, $dept]);
$editable = fn(array $tg) => $canEdit && (($admin && $dept === null) || ($tg['group_id'] !== null && (int)$tg['group_id'] === $dept));

if (is_post()) {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    $tid = post_int('tag_id');
    $name = post_str('name', 80);
    try {
        if ($act === 'add' && $name !== '') {
            // όλοι μπορούν να προσθέσουν tag
            q('INSERT INTO tt_tags (group_id, name, created_at) VALUES (?, ?, ?)', [$dept, $name, now_sql()]);
            flash('success', t('Το tag προστέθηκε.'));
        } elseif ($canEdit && $act === 'rename' && $tid && $name !== '' && $inDept($tid)) {
            q('UPDATE tt_tags SET name = ? WHERE id = ?', [$name, $tid]);
        } elseif ($canEdit && $act === 'delete' && $tid && $inDept($tid)) {
            q('DELETE FROM tt_tags WHERE id = ?', [$tid]);
            flash('success', t('Το tag διαγράφηκε.'));
        }
    } catch (PDOException $e) {
        flash('error', $e->getCode() === '23000' ? t('Υπάρχει ήδη tag με αυτό το όνομα.') : t('Σφάλμα βάσης δεδομένων.'));
    }
    $back = (int)($_GET['page'] ?? 1);
    redirect('tags.php' . ($back > 1 ? '?page=' . $back : ''));
}

$tags = q_all('SELECT t.*, (SELECT COUNT(*) FROM tt_entry_tags et WHERE et.tag_id = t.id) AS uses FROM tt_tags t'
    . ($dept !== null ? ' WHERE t.group_id = ? OR t.group_id IS NULL' : '') . ' ORDER BY t.name', $dept !== null ? [$dept] : []);

// Σελιδοποίηση: 25 tags ανά σελίδα
$perPage = 25;
$total = count($tags);
$pages = max(1, (int)ceil($total / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$tags = array_slice($tags, ($page - 1) * $perPage, $perPage);

render_header('Tags', 'tags');
page_head('Tags');
?>
<form method="post" class="card mb-3">
  <div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="act" value="add">
    <div class="input-group" style="max-width:560px">
      <input type="text" class="form-control" name="name" placeholder="<?= h(t('Νέο tag (π.χ. Meeting, Bugfix, SEO)')) ?>" maxlength="80" required>
      <button class="btn btn-primary" type="submit"><?= icon('plus', 'me-1') ?><?= h(t('Προσθήκη')) ?></button>
    </div>
  </div>
</form>
<div class="card">
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead><tr><th>Tag</th><th class="text-num"><?= h(t('Χρήσεις')) ?></th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($tags as $tg): ?>
        <tr>
          <td><?php if ($editable($tg)): ?>
            <form method="post" class="inline-edit"><?= csrf_field() ?><input type="hidden" name="act" value="rename"><input type="hidden" name="tag_id" value="<?= (int)$tg['id'] ?>">
              <input type="text" class="form-control" name="name" value="<?= h($tg['name']) ?>" maxlength="80" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()" aria-label="Tag">
            </form>
          <?php else: ?><?= icon('tag', 'me-2 text-body-secondary') ?><?= h($tg['name']) ?><?php endif; ?></td>
          <td class="text-num"><?= (int)$tg['uses'] ?></td>
          <?php if ($canEdit): ?><td class="text-end"><?php if ($editable($tg)): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tag_id" value="<?= (int)$tg['id'] ?>">
              <button class="btn btn-icon" name="act" value="delete" title="<?= h(t('Διαγραφή')) ?>" data-confirm="<?= h(t('Διαγραφή tag; Θα αφαιρεθεί από όλες τις καταχωρήσεις.')) ?>"><?= icon('trash') ?></button>
            </form>
          <?php endif; ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tags): ?><tr><td colspan="3" class="text-body-secondary"><?= h(t('Δεν υπάρχουν tags ακόμη.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
      <span class="small text-body-secondary"><?= h(t('{a}–{b} από {n} tags', ['a' => ($page - 1) * $perPage + 1, 'b' => min($total, $page * $perPage), 'n' => $total])) ?></span>
      <?= pagination_html('tags.php', $page, $pages) ?>
    </div>
  <?php endif; ?>
</div>
<?php render_footer();
