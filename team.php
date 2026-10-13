<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// Admin: όλους τους χρήστες και τις ομάδες. Manager: μόνο τα μέλη της ομάδας του.
$user = require_lead();
$admin = is_admin($user);
$myGroup = managed_group_id($user);

if (is_post()) {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    try {
        if ($act === 'add_user') {
            $name = post_str('name', 120);
            $email = mb_strtolower(post_str('email', 190));
            $pass = (string)($_POST['password'] ?? '');
            $invite = !empty($_POST['send_invite']);
            $ulang = isset(LANGS[$_POST['lang'] ?? '']) ? $_POST['lang'] : null;
            if ($admin) {
                $role = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'member';
                $gid = post_int('group_id');
                if ($gid && !q_val('SELECT 1 FROM tt_groups WHERE id = ?', [$gid])) $gid = null;
            } else {
                $role = 'member';
                $gid = $myGroup;
            }
            $err = '';
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = t('Συμπλήρωσε όνομα και σωστό email.');
            elseif ($pass === '' && !$invite) $err = t('Βάλε κωδικό ή άφησε επιλεγμένη την αποστολή πρόσκλησης με email.');
            elseif ($pass !== '' && mb_strlen($pass) < 8) $err = t('Ο κωδικός θέλει τουλάχιστον 8 χαρακτήρες.');
            elseif ($role === 'manager' && !$gid) $err = t('Ο manager χρειάζεται ομάδα.');
            elseif ($role === 'member' && !$gid) $err = t('Διάλεξε ομάδα για τον χρήστη.');
            if ($err) {
                flash('error', $err);
            } else {
                // Με πρόσκληση και χωρίς κωδικό: ο χρήστης ορίζει μόνος του κωδικό από το link
                $hash = $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : unusable_password_hash();
                $uid = q_insert('INSERT INTO tt_users (name, email, password_hash, role, lang, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)',
                    [$name, $email, $hash, $role, $ulang, now_sql()]);
                set_user_group($uid, $gid);
                if ($invite) {
                    $newUser = ['id' => $uid, 'name' => $name, 'email' => $email, 'lang' => $ulang];
                    if (send_password_link($newUser, 'invite', $user)) {
                        flash('success', t('Ο λογαριασμός για {n} φτιάχτηκε και στάλθηκε πρόσκληση στο {e}.', ['n' => $name, 'e' => $email]));
                    } else {
                        flash('error', t('Ο λογαριασμός φτιάχτηκε, αλλά το email δεν στάλθηκε. Έλεγξε τις ρυθμίσεις email και ξαναστείλε την πρόσκληση από την επεξεργασία χρήστη.'));
                    }
                } else {
                    flash('success', t('Ο λογαριασμός για {n} φτιάχτηκε. Δώσε του το email και τον κωδικό για να συνδεθεί.', ['n' => $name]));
                }
            }
        } elseif ($admin && $act === 'add_group') {
            $name = post_str('name', 80);
            if ($name !== '') {
                $n = (int)q_val('SELECT COUNT(*) FROM tt_groups');
                q('INSERT INTO tt_groups (name, color, created_at) VALUES (?, ?, ?)', [$name, valid_color(post_str('color', 7), PALETTE[$n % count(PALETTE)]), now_sql()]);
                flash('success', t('Η ομάδα «{n}» φτιάχτηκε.', ['n' => $name]));
            }
        } elseif ($admin && $act === 'save_group' && ($gid = post_int('group_id'))) {
            $name = post_str('name', 80);
            if ($name !== '') q('UPDATE tt_groups SET name = ?, color = ? WHERE id = ?', [$name, valid_color(post_str('color', 7)), $gid]);
            flash('success', t('Η ομάδα αποθηκεύτηκε.'));
        } elseif ($admin && $act === 'delete_group' && ($gid = post_int('group_id'))) {
            if ($m = group_manager($gid)) {
                flash('error', t('Η ομάδα έχει manager ({n}). Άλλαξε πρώτα τον ρόλο ή την ομάδα του.', ['n' => $m['name']]));
            } elseif (q_val('SELECT 1 FROM tt_group_members WHERE group_id = ? UNION SELECT 1 FROM tt_projects WHERE group_id = ? UNION SELECT 1 FROM tt_tags WHERE group_id = ? LIMIT 1', [$gid, $gid, $gid])) {
                // αλλιώς μέλη, projects και tags θα έμεναν χωρίς ομάδα και θα φαίνονταν σε όλες τις ομάδες
                flash('error', t('Η ομάδα έχει ακόμα χρήστες, projects ή tags. Μετάφερέ τα πρώτα σε άλλη ομάδα.'));
            } else {
                q('DELETE FROM tt_groups WHERE id = ?', [$gid]);
                flash('success', t('Η ομάδα διαγράφηκε. Τα μέλη της έμειναν χωρίς ομάδα.'));
            }
        }
    } catch (PDOException $e) {
        flash('error', $e->getCode() === '23000' ? t('Υπάρχει ήδη αυτό το email ή όνομα ομάδας.') : t('Σφάλμα βάσης δεδομένων.'));
    }
    redirect('team.php' . (str_contains($act, 'group') ? '#groups' : ''));
}

$showInactive = !empty($_GET['inactive']);
$where = [];
$params = [];
if (!$showInactive) $where[] = 'u.active = 1';
// μόνο η ομάδα (ο admin: η ομάδα που έχει επιλέξει στην πάνω μπάρα)
$listGroup = $admin ? current_dept($user) : $myGroup;
if ($listGroup !== null || !$admin) { $where[] = 'u.id IN (SELECT user_id FROM tt_group_members WHERE group_id = ?)'; $params[] = $listGroup; }
// όχι ώρες ανά άτομο εδώ (όχι στοχοποίηση): μόνο πότε συνδέθηκε τελευταία φορά
$users = q_all('SELECT u.*, g.id AS group_id, g.name AS group_name, g.color AS group_color
                FROM tt_users u
                LEFT JOIN tt_group_members gm ON gm.user_id = u.id
                LEFT JOIN tt_groups g ON g.id = gm.group_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY u.active DESC, FIELD(u.role, 'admin', 'manager', 'member'), u.name", $params);
// Σελιδοποίηση: 20 λογαριασμοί ανά σελίδα
$perPage = 20;
$totalUsers = count($users);
$pages = max(1, (int)ceil($totalUsers / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$users = array_slice($users, ($page - 1) * $perPage, $perPage);
$groups = all_groups();
$groupInfo = [];
foreach (q_all("SELECT gm.group_id, u.name, u.role FROM tt_group_members gm JOIN tt_users u ON u.id = gm.user_id WHERE u.active = 1 ORDER BY u.name") as $r) {
    if ($r['role'] === 'manager') $groupInfo[(int)$r['group_id']]['manager'] = isset($groupInfo[(int)$r['group_id']]['manager']) ? $groupInfo[(int)$r['group_id']]['manager'] . ', ' . $r['name'] : $r['name'];
    else $groupInfo[(int)$r['group_id']]['members'][] = $r['name'];
}
$myGroupName = $myGroup ? (string)q_val('SELECT name FROM tt_groups WHERE id = ?', [$myGroup]) : '';
$roleBadge = ['admin' => 'text-bg-primary', 'manager' => 'text-bg-info', 'member' => 'text-bg-secondary'];

render_header(tx('nav', 'Ομάδα'), 'team');
$titleGroup = $listGroup ? (string)q_val('SELECT name FROM tt_groups WHERE id = ?', [$listGroup]) : '';
page_head(h(tx('nav', 'Ομάδα') . ($titleGroup !== '' ? ' · ' . $titleGroup : '')));
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= h(t('Λογαριασμοί')) ?> <span class="badge text-bg-light border ms-1"><?= $totalUsers ?></span></span>
        <a class="small fw-normal" href="team.php<?= $showInactive ? '' : '?inactive=1' ?>"><?= h($showInactive ? t('Απόκρυψη ανενεργών') : t('Εμφάνιση ανενεργών')) ?></a>
      </div>
      <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 team-table">
        <thead><tr><th><?= h(t('Όνομα')) ?></th><th><?= h(t('Ρόλος')) ?></th><th class="d-none d-md-table-cell"><?= h(t('Ομάδα')) ?></th><th class="d-none d-md-table-cell th-wrap"><?= h(t('Τελευταία σύνδεση')) ?></th><th></th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr class="<?= $u['active'] ? '' : 'row-muted' ?>">
              <td><div class="d-flex align-items-center gap-2"><?= avatar_html($u, 'avatar-sm') ?><div><div class="fw-semibold"><?= h($u['name']) ?><?= $u['active'] ? '' : ' <span class="badge text-bg-light border fw-normal">' . h(t('ανενεργός')) . '</span>' ?><?= $u['last_login_at'] ? '' : ' <span class="badge text-bg-light border fw-normal">' . h(t('δεν έχει συνδεθεί')) . '</span>' ?></div><div class="small text-body-secondary u-email"><?= h($u['email']) ?></div><?php if ($u['last_login_at']): ?><div class="small text-body-secondary d-md-none"><?= h(t('Τελευταία σύνδεση')) ?>: <?= h(fmt_date($u['last_login_at']) . ' ' . fmt_time($u['last_login_at'])) ?></div><?php endif; ?></div></div></td>
              <td><span class="badge <?= $roleBadge[$u['role']] ?? 'text-bg-secondary' ?>"><?= h(role_label($u['role'])) ?></span></td>
              <td class="small text-nowrap d-none d-md-table-cell"><?php if ($u['group_name']): ?><span class="dot" style="background:<?= h($u['group_color']) ?>"></span><?= h($u['group_name']) ?><?php else: ?><span class="text-body-tertiary">—</span><?php endif; ?></td>
              <td class="small text-nowrap d-none d-md-table-cell"><?= $u['last_login_at'] ? h(fmt_date($u['last_login_at']) . ' ' . fmt_time($u['last_login_at'])) : '<span class="text-body-tertiary">—</span>' ?></td>
              <td class="text-end"><?php if (can_manage_user($user, $u)): ?><a class="btn btn-icon" href="user_edit.php?id=<?= (int)$u['id'] ?>" title="<?= h(t('Επεξεργασία')) ?>"><?= icon('edit') ?></a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($pages > 1): ?>
        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
          <span class="small text-body-secondary"><?= h(t('{a}–{b} από {n} λογαριασμούς', ['a' => ($page - 1) * $perPage + 1, 'b' => min($totalUsers, $page * $perPage), 'n' => $totalUsers])) ?></span>
          <?= pagination_html('team.php', $page, $pages) ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($admin): ?>
    <div class="card" id="groups">
      <div class="card-header"><?= h(t('Ομάδες')) ?></div>
      <div class="card-body">
        <p class="small text-body-secondary"><?= h(t('Κάθε χρήστης ανήκει σε μία ομάδα. Μια ομάδα μπορεί να έχει όσους managers θέλεις. Την ομάδα και τον ρόλο τα ορίζεις από την επεξεργασία του χρήστη.')) ?></p>
        <form method="post" class="d-flex flex-wrap gap-2 mb-3">
          <?= csrf_field() ?><input type="hidden" name="act" value="add_group">
          <input type="text" class="form-control w-auto flex-grow-1" style="max-width:320px" name="name" placeholder="<?= h(t('Όνομα νέας ομάδας')) ?>" maxlength="80" required>
          <input type="color" class="form-control form-control-color" name="color" value="<?= h(PALETTE[count($groups) % count(PALETTE)]) ?>" title="<?= h(t('Χρώμα')) ?>">
          <button class="btn btn-primary" type="submit"><?= icon('plus', 'me-1') ?><?= h(t('Νέα ομάδα')) ?></button>
        </form>
        <?php if ($groups): ?>
        <div class="accordion" id="groupsAcc">
          <?php foreach ($groups as $g): $gi = $groupInfo[(int)$g['id']] ?? []; ?>
            <div class="accordion-item">
              <h3 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#grp<?= (int)$g['id'] ?>" aria-expanded="false" aria-controls="grp<?= (int)$g['id'] ?>">
                  <span class="dot" style="background:<?= h($g['color']) ?>"></span><strong class="me-2"><?= h($g['name']) ?></strong>
                  <span class="small text-body-secondary"><?= h(t('Managers')) ?>: <?= isset($gi['manager']) ? h($gi['manager']) : '<span class="text-warning-emphasis">' . h(t('κανένας')) . '</span>' ?> · <?= h(t('{n} μέλη', ['n' => (int)$g['members']])) ?></span>
                </button>
              </h3>
              <div id="grp<?= (int)$g['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#groupsAcc">
                <form method="post" class="accordion-body">
                  <?= csrf_field() ?><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                  <div class="d-flex flex-wrap gap-2 mb-3">
                    <input type="text" class="form-control w-auto flex-grow-1" style="max-width:320px" name="name" value="<?= h($g['name']) ?>" maxlength="80" required aria-label="<?= h(t('Όνομα')) ?>">
                    <input type="color" class="form-control form-control-color" name="color" value="<?= h($g['color']) ?>" title="<?= h(t('Χρώμα')) ?>">
                  </div>
                  <p class="small mb-3"><span class="text-body-secondary"><?= h(t('Μέλη')) ?>:</span> <?= !empty($gi['members']) ? h(implode(', ', $gi['members'])) : '<span class="text-body-tertiary">—</span>' ?></p>
                  <button class="btn btn-primary btn-sm" name="act" value="save_group"><?= h(t('Αποθήκευση')) ?></button>
                  <button class="btn btn-outline-danger btn-sm" name="act" value="delete_group" formnovalidate data-confirm="<?= h(t('Διαγραφή της ομάδας;')) ?>"><?= h(t('Διαγραφή')) ?></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?><p class="text-body-secondary mb-0"><?= h(t('Δεν υπάρχουν ομάδες ακόμη.')) ?></p><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-xl-4">
    <form method="post" class="card" autocomplete="off">
      <div class="card-header"><?= h(t('Νέος χρήστης')) ?></div>
      <div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="act" value="add_user">
        <div class="mb-3"><label class="form-label" for="nuName"><?= h(t('Ονοματεπώνυμο')) ?> *</label><input type="text" class="form-control" id="nuName" name="name" maxlength="120" required></div>
        <div class="mb-3"><label class="form-label" for="nuEmail">Email *</label><input type="email" class="form-control" id="nuEmail" name="email" maxlength="190" required autocomplete="off"></div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="nuInvite" name="send_invite" value="1" checked>
          <label class="form-check-label" for="nuInvite"><?= h(t('Αποστολή πρόσκλησης με email')) ?></label>
        </div>
        <p class="small text-body-secondary"><?= h(t('Ο χρήστης παίρνει email με link (ισχύει {h} ώρες) για να ορίσει μόνος του τον κωδικό του.', ['h' => INVITE_TTL_HOURS])) ?></p>
        <div class="mb-3">
          <label class="form-label" for="nuPass"><?= h(t('Κωδικός')) ?> <span class="text-body-secondary small">(<?= h(t('προαιρετικό με πρόσκληση· τουλ. 8 χαρακτήρες')) ?>)</span></label>
          <div class="input-group">
            <input type="text" class="form-control" id="nuPass" name="password" minlength="8" autocomplete="new-password" data-genpass>
            <button type="button" class="btn btn-outline-secondary" data-genpass-btn title="<?= h(t('Δημιουργία τυχαίου κωδικού')) ?>"><?= icon('key') ?></button>
          </div>
        </div>
        <?php if ($admin): ?>
          <div class="row g-3 mb-3">
            <div class="col-6"><label class="form-label" for="nuRole"><?= h(t('Ρόλος')) ?></label><select class="form-select" id="nuRole" name="role"><?php foreach (['member', 'manager', 'admin'] as $r): ?><option value="<?= $r ?>"><?= h(role_label($r)) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label" for="nuLang"><?= h(t('Γλώσσα')) ?></label><select class="form-select" id="nuLang" name="lang"><?php foreach (LANGS as $code => $label): ?><option value="<?= h($code) ?>" <?= lang() === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="nuGroup"><?= h(t('Ομάδα')) ?></label>
            <select class="form-select" id="nuGroup" name="group_id"><option value="">— <?= h(t('Χωρίς ομάδα')) ?> —</option><?php foreach ($groups as $g): $gi = $groupInfo[(int)$g['id']] ?? []; ?><option value="<?= (int)$g['id'] ?>"<?= (int)$g['id'] === (int)current_dept($user) ? ' selected' : '' ?>><?= h($g['name']) ?><?= isset($gi['manager']) ? ' (managers: ' . h($gi['manager']) . ')' : '' ?></option><?php endforeach; ?></select>
          </div>
          <p class="small text-body-secondary"><strong>Admin:</strong> <?= h(t('βλέπει και κάνει τα πάντα.')) ?> <strong>Manager:</strong> <?= h(t('διαχειρίζεται τα μέλη, τον χρόνο και τα projects της ομάδας του.')) ?> <strong><?= h(t('Μέλος')) ?>:</strong> <?= h(t('βλέπει μόνο τον δικό του χρόνο.')) ?></p>
        <?php else: ?>
          <div class="mb-3"><label class="form-label" for="nuLang"><?= h(t('Γλώσσα')) ?></label><select class="form-select" id="nuLang" name="lang"><?php foreach (LANGS as $code => $label): ?><option value="<?= h($code) ?>" <?= lang() === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
          <p class="small text-body-secondary"><?= h(t('Ο νέος χρήστης μπαίνει ως μέλος στην ομάδα «{g}».', ['g' => $myGroupName])) ?></p>
        <?php endif; ?>
        <button class="btn btn-primary w-100" type="submit"><?= h(t('Δημιουργία χρήστη')) ?></button>
      </div>
    </form>
  </div>
</div>
<?php render_footer();
