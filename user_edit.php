<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// Admin: οποιονδήποτε. Manager: μόνο μέλη της ομάδας του (όνομα, γλώσσα, κωδικός, ενεργός, link κωδικού).
$me = require_lead();
$admin = is_admin($me);
$id = get_int('id') ?? post_int('id');
$u = $id ? q_one('SELECT * FROM tt_users WHERE id = ?', [$id]) : null;
if (!$u || !can_manage_user($me, $u)) { flash('error', t('Ο χρήστης δεν βρέθηκε.')); redirect('team.php'); }
$isSelf = (int)$u['id'] === (int)$me['id'];
$uGroup = user_group_id((int)$u['id']);

function other_active_admins(int $exceptId): int
{
    return (int)q_val("SELECT COUNT(*) FROM tt_users WHERE role = 'admin' AND active = 1 AND id <> ?", [$exceptId]);
}

if (is_post() && ($_POST['act'] ?? '') === 'send_link') {
    csrf_check();
    if (!$u['active']) {
        flash('error', t('Ο λογαριασμός είναι ανενεργός. Ενεργοποίησέ τον πρώτα.'));
    } elseif (send_password_link($u, $u['last_login_at'] ? 'reset' : 'invite', $me)) {
        flash('success', t('Στάλθηκε email με link ορισμού κωδικού στο {e}.', ['e' => $u['email']]));
    } else {
        flash('error', t('Το email δεν στάλθηκε. Έλεγξε τις ρυθμίσεις email (SMTP) στο config.'));
    }
    redirect('user_edit.php?id=' . $u['id']);
}

if (is_post()) {
    csrf_check();
    $name = post_str('name', 120);
    $active = !empty($_POST['active']) ? 1 : 0;
    $ulang = isset(LANGS[$_POST['lang'] ?? '']) ? $_POST['lang'] : null;
    // Email, ρόλο και ομάδα τα αλλάζει μόνο ο admin· κωδικό και ο manager (για τα μέλη του)
    $pass = (string)($_POST['password'] ?? '');
    if ($admin) {
        $email = mb_strtolower(post_str('email', 190));
        $role = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'member';
        $gid = post_int('group_id');
        if ($gid && !q_val('SELECT 1 FROM tt_groups WHERE id = ?', [$gid])) $gid = null;
    } else {
        $email = $u['email'];
        $role = $u['role'];
        $gid = $uGroup;
    }
    $err = '';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = t('Συμπλήρωσε όνομα και σωστό email.');
    elseif ($pass !== '' && mb_strlen($pass) < 8) $err = t('Ο κωδικός θέλει τουλάχιστον 8 χαρακτήρες.');
    elseif ($u['role'] === 'admin' && ($role !== 'admin' || !$active) && other_active_admins((int)$u['id']) === 0) $err = t('Πρέπει να μείνει τουλάχιστον ένας ενεργός admin.');
    elseif ($role === 'manager' && !$gid) $err = t('Ο manager χρειάζεται ομάδα.');
    elseif ($role === 'member' && !$gid) $err = t('Διάλεξε ομάδα για τον χρήστη.');
    if ($err) {
        flash('error', $err);
    } else {
        try {
            q('UPDATE tt_users SET name = ?, email = ?, role = ?, active = ?, lang = ? WHERE id = ?', [$name, $email, $role, $active, $ulang, $u['id']]);
            if ($pass !== '') {
                q('UPDATE tt_users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                end_user_sessions((int)$u['id'], $isSelf); // ο χρήστης αποσυνδέεται από παντού (εκτός αν το αλλάζει ο ίδιος)
            }
            if (!$active) {
                end_user_sessions((int)$u['id']);
                stop_running((int)$u['id']);
            }
            if ($admin && $gid !== $uGroup) set_user_group((int)$u['id'], $gid);
            flash('success', t('Ο χρήστης αποθηκεύτηκε.'));
            redirect('team.php');
        } catch (PDOException $e) {
            flash('error', $e->getCode() === '23000' ? t('Το email χρησιμοποιείται ήδη.') : t('Σφάλμα βάσης δεδομένων.'));
        }
    }
    redirect('user_edit.php?id=' . $u['id']);
}

$groups = $admin ? all_groups() : [];
$groupName = $uGroup ? (string)q_val('SELECT name FROM tt_groups WHERE id = ?', [$uGroup]) : '';
$entries = (int)q_val('SELECT COUNT(*) FROM tt_entries WHERE user_id = ?', [$u['id']]);

render_header($u['name'], 'team');
page_head('<a href="team.php" class="text-body-secondary text-decoration-none">' . h(tx('nav', 'Ομάδα')) . '</a> <span class="text-body-tertiary">/</span> ' . h($u['name']));
?>
<form method="post" class="card" style="max-width:720px" autocomplete="off">
  <div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    <div class="mb-3"><label class="form-label" for="uName"><?= h(t('Ονοματεπώνυμο')) ?></label><input type="text" class="form-control" id="uName" name="name" value="<?= h($u['name']) ?>" maxlength="120" required></div>
    <?php if ($admin): ?>
      <div class="mb-3"><label class="form-label" for="uEmail">Email</label><input type="email" class="form-control" id="uEmail" name="email" value="<?= h($u['email']) ?>" maxlength="190" required></div>
      <div class="row g-3 mb-3">
        <div class="col-sm-4"><label class="form-label" for="uRole"><?= h(t('Ρόλος')) ?></label><select class="form-select" id="uRole" name="role"><?php foreach (['member', 'manager', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= h(role_label($r)) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-4"><label class="form-label" for="uGroup"><?= h(t('Ομάδα')) ?></label><select class="form-select" id="uGroup" name="group_id"><option value="">— <?= h(t('Χωρίς ομάδα')) ?> —</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $uGroup === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-4"><label class="form-label" for="uLang"><?= h(t('Γλώσσα')) ?></label><select class="form-select" id="uLang" name="lang"><option value="">—</option><?php foreach (LANGS as $code => $label): ?><option value="<?= h($code) ?>" <?= ($u['lang'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
      </div>
    <?php else: ?>
      <div class="row g-3 mb-3">
        <div class="col-sm-8"><div class="form-label">Email</div><div class="form-control-plaintext py-1"><?= h($u['email']) ?></div></div>
        <div class="col-sm-4"><label class="form-label" for="uLang"><?= h(t('Γλώσσα')) ?></label><select class="form-select" id="uLang" name="lang"><option value="">—</option><?php foreach (LANGS as $code => $label): ?><option value="<?= h($code) ?>" <?= ($u['lang'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
      </div>
      <p class="small text-body-secondary"><?= h(t('Ρόλος')) ?>: <?= h(role_label($u['role'])) ?> · <?= h(t('Ομάδα')) ?>: <?= h($groupName) ?>. <?= h(t('Το email, τον ρόλο και την ομάδα τα αλλάζει μόνο ο admin.')) ?></p>
    <?php endif; ?>
    <div class="mb-3">
      <label class="form-label" for="uPass"><?= h(t('Νέος κωδικός')) ?> <span class="small text-body-secondary">(<?= h(t('άφησέ το κενό για να μην αλλάξει')) ?>)</span></label>
      <div class="input-group">
        <input type="text" class="form-control" id="uPass" name="password" minlength="8" autocomplete="new-password" data-genpass>
        <button type="button" class="btn btn-outline-secondary" data-genpass-btn title="<?= h(t('Δημιουργία τυχαίου κωδικού')) ?>"><?= icon('key') ?></button>
      </div>
    </div>
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" role="switch" id="uActive" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="uActive"><?= h(t('Ενεργός λογαριασμός')) ?></label>
    </div>
    <p class="small text-body-secondary"><?= h(t('Ο ανενεργός χρήστης δεν μπορεί να συνδεθεί. Ο χρόνος του ({n} καταχωρήσεις) μένει στις αναφορές. Δεν διαγράφουμε χρήστες, για να μη χαθεί ιστορικό.', ['n' => $entries])) ?></p>
    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-primary" type="submit"><?= h(t('Αποθήκευση')) ?></button>
      <a class="btn btn-light" href="team.php"><?= h(t('Πίσω')) ?></a>
      <a class="btn btn-outline-secondary ms-auto" href="reports.php?tab=detailed&amp;user=<?= (int)$u['id'] ?>&amp;preset=month"><?= icon('chart', 'me-1') ?><?= h(t('Ο χρόνος του')) ?></a>
    </div>
  </div>
</form>
<form method="post" class="card mt-3" style="max-width:720px">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="act" value="send_link">
    <div class="flex-grow-1 small text-body-secondary">
      <?= h($u['last_login_at']
          ? t('Στείλε στον χρήστη email με link για να ορίσει νέο κωδικό (ισχύει {h} ώρες). Ο τωρινός κωδικός ισχύει μέχρι να οριστεί ο νέος.', ['h' => RESET_TTL_HOURS])
          : t('Ο χρήστης δεν έχει συνδεθεί ακόμη. Μπορείς να του ξαναστείλεις την πρόσκληση (ισχύει {h} ώρες).', ['h' => INVITE_TTL_HOURS])) ?>
    </div>
    <button class="btn btn-outline-primary" type="submit"><?= icon('key', 'me-1') ?><?= h($u['last_login_at'] ? t('Αποστολή link για νέο κωδικό') : t('Επανάληψη πρόσκλησης')) ?></button>
  </div>
</form>
<?php render_footer();
