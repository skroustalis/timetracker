<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();

if (is_post()) {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'avatar_upload') {
        [$fn, $err] = save_avatar_upload($_FILES['avatar'] ?? []);
        if ($err) {
            flash('error', $err);
        } else {
            q('UPDATE tt_users SET avatar = ? WHERE id = ?', [$fn, $user['id']]);
            delete_avatar_file($user['avatar'] ?? null);
            flash('success', t('Η φωτογραφία αποθηκεύτηκε.'));
        }
        redirect('profile.php');
    }
    if ($act === 'avatar_remove') {
        q('UPDATE tt_users SET avatar = NULL WHERE id = ?', [$user['id']]);
        delete_avatar_file($user['avatar'] ?? null);
        flash('success', t('Η φωτογραφία αφαιρέθηκε.'));
        redirect('profile.php');
    }
    $name = post_str('name', 120);
    $ulang = (string)($_POST['lang'] ?? '');
    $cur = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');
    if ($name !== '' && $name !== $user['name']) q('UPDATE tt_users SET name = ? WHERE id = ?', [$name, $user['id']]);
    if (isset(LANGS[$ulang]) && $ulang !== ($user['lang'] ?? '')) {
        q('UPDATE tt_users SET lang = ? WHERE id = ?', [$ulang, $user['id']]);
        set_lang($ulang);
    }
    $ok = true;
    if ($new !== '' || $cur !== '') {
        if (!password_verify($cur, $user['password_hash'])) { flash('error', t('Ο τρέχων κωδικός είναι λάθος.')); $ok = false; }
        elseif (mb_strlen($new) < 8) { flash('error', t('Ο νέος κωδικός θέλει τουλάχιστον 8 χαρακτήρες.')); $ok = false; }
        elseif ($new !== $new2) { flash('error', t('Οι δύο νέοι κωδικοί δεν ταιριάζουν.')); $ok = false; }
        else {
            q('UPDATE tt_users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            end_user_sessions((int)$user['id'], true); // οι άλλες συσκευές αποσυνδέονται
            flash('success', t('Ο κωδικός άλλαξε. Οι άλλες συσκευές θα χρειαστεί να συνδεθούν ξανά.'));
        }
    }
    if ($ok) flash('success', t('Αποθηκεύτηκε.'));
    redirect('profile.php');
}

$groups = q_all('SELECT g.name, g.color FROM tt_group_members gm JOIN tt_groups g ON g.id = gm.group_id WHERE gm.user_id = ? ORDER BY g.name', [$user['id']]);

render_header(t('Το προφίλ μου'), '');
page_head(h(t('Το προφίλ μου')));
?>
<div class="card mb-4" style="max-width:720px">
  <div class="card-body d-flex flex-wrap align-items-center gap-4">
    <?= avatar_html($user, 'avatar-xl') ?>
    <div class="flex-grow-1">
      <h2 class="h6 mb-1"><?= h(t('Φωτογραφία προφίλ')) ?></h2>
      <p class="small text-body-secondary mb-2"><?= h(t('JPG, PNG, WEBP ή GIF έως 5 MB. Κόβεται αυτόματα σε τετράγωνο.')) ?></p>
      <div class="d-flex flex-wrap gap-2">
        <form method="post" enctype="multipart/form-data" class="d-inline" data-avatar-form>
          <?= csrf_field() ?><input type="hidden" name="act" value="avatar_upload">
          <input type="file" name="avatar" id="avatarFile" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none" data-avatar-input>
          <label for="avatarFile" class="btn btn-primary btn-sm mb-0"><?= icon('upload', 'me-1') ?><?= h(avatar_url($user) ? t('Αλλαγή φωτογραφίας') : t('Ανέβασμα φωτογραφίας')) ?></label>
        </form>
        <?php if (avatar_url($user)): ?>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="avatar_remove">
            <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="<?= h(t('Αφαίρεση της φωτογραφίας;')) ?>"><?= icon('trash', 'me-1') ?><?= h(t('Αφαίρεση')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<form method="post" class="card" style="max-width:720px">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-sm-8"><label class="form-label" for="pfName"><?= h(t('Ονοματεπώνυμο')) ?></label><input type="text" class="form-control" id="pfName" name="name" value="<?= h($user['name']) ?>" maxlength="120" required></div>
      <div class="col-sm-4"><label class="form-label" for="pfLang"><?= h(t('Γλώσσα')) ?></label><select class="form-select" id="pfLang" name="lang"><?php foreach (LANGS as $code => $label): ?><option value="<?= h($code) ?>" <?= lang() === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="small text-body-secondary">Email: <?= h($user['email']) ?> · <?= h(t('Ρόλος')) ?>: <?= h(role_label($user['role'])) ?><?php if ($groups): ?> · <?= h(t('Ομάδες')) ?>: <?php foreach ($groups as $g): ?><span class="dot" style="background:<?= h($g['color']) ?>"></span><?= h($g['name']) ?> <?php endforeach; ?><?php endif; ?></p>
    <hr>
    <h2 class="h6 mb-3"><?= h(t('Αλλαγή κωδικού')) ?></h2>
    <div class="mb-3"><label class="form-label" for="pfCur"><?= h(t('Τρέχων κωδικός')) ?></label><input type="password" class="form-control" id="pfCur" name="current_password" autocomplete="current-password"></div>
    <div class="row g-3 mb-3">
      <div class="col-sm-6"><label class="form-label" for="pfNew"><?= h(t('Νέος κωδικός')) ?></label><input type="password" class="form-control" id="pfNew" name="new_password" minlength="8" autocomplete="new-password"></div>
      <div class="col-sm-6"><label class="form-label" for="pfNew2"><?= h(t('Ξανά ο νέος')) ?></label><input type="password" class="form-control" id="pfNew2" name="new_password2" minlength="8" autocomplete="new-password"></div>
    </div>
    <button class="btn btn-primary" type="submit"><?= h(t('Αποθήκευση')) ?></button>
  </div>
</form>
<form method="post" action="lang.php" class="card mt-4" style="max-width:720px" id="appearance" data-appearance>
  <div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="return" value="profile.php"><input type="hidden" name="saved" value="1">
    <h2 class="h6 mb-3"><?= icon('palette', 'me-2') ?><?= h(t('Εμφάνιση εφαρμογής')) ?></h2>

    <div class="form-label"><?= h(t('Θέμα')) ?></div>
    <div class="d-flex flex-wrap gap-2 mb-4">
      <?php foreach (THEMES as $code => $label): ?>
        <input type="radio" class="btn-check" name="theme" id="th_<?= h($code) ?>" value="<?= h($code) ?>" <?= current_theme() === $code ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary btn-sm rounded-pill px-3" for="th_<?= h($code) ?>"><?= h(t($label)) ?></label>
      <?php endforeach; ?>
    </div>

    <?php
    // Ένας επιλογέας ανά θέμα· φαίνεται μόνο αυτός του επιλεγμένου θέματος (το Κλασικό δεν έχει)
    $pickers = ['glass' => t('Φόντο'), 'neo' => t('Σύνθεση'), 'minimal' => t('Σύνθεση')];
    foreach ($pickers as $pTheme => $pTitle):
        $pSel = bg_for_theme($pTheme);
    ?>
    <div data-theme-picker="<?= h($pTheme) ?>"<?= current_theme() === $pTheme ? '' : ' hidden' ?>>
      <div class="form-label"><?= h($pTitle) ?></div>
      <div class="bg-picker mb-4">
        <?php foreach (theme_backgrounds($pTheme) as $code => [$label, $kind, $tone]): ?>
          <input type="radio" class="btn-check" name="bg_<?= h($pTheme) ?>" id="bg_<?= h($code) ?>" value="<?= h($code) ?>" data-tone="<?= h($tone) ?>" <?= $pSel === $code ? 'checked' : '' ?>>
          <label class="bg-option" for="bg_<?= h($code) ?>">
            <span class="bg-swatch<?= $kind === 'scheme' ? ' sw-' . h($pTheme) : '' ?>" data-swatch="<?= h($code) ?>"><?php if ($kind === 'scheme'): ?><i></i><?php endif; ?></span>
            <span class="small"><?= h(t($label)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <button class="btn btn-primary" type="submit"><?= h(t('Αποθήκευση')) ?></button>
    <span class="small text-body-secondary ms-2"><?= h(t('Η προεπισκόπηση αλλάζει αμέσως· πάτα Αποθήκευση για να μείνει.')) ?></span>
  </div>
</form>
<?php render_footer();
