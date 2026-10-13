<?php
declare(strict_types=1);
// Ρυθμίσεις εφαρμογής. Αυτόματο σταμάτημα: μόνο admin. Επανάληψη ειδοποίησης ορίου: admin και managers.
require __DIR__ . '/includes/bootstrap.php';

$me = require_lead();
$admin = is_admin($me);
// Η «Επανάληψη ειδοποίησης» είναι ανά ομάδα: ο admin ρυθμίζει την ομάδα που έχει επιλέξει, ο manager τη δική του
$repGroup = $admin ? current_dept($me) : managed_group_id($me);
$repGroupName = $repGroup ? (string)q_val('SELECT name FROM tt_groups WHERE id = ?', [$repGroup]) : '';

if (is_post()) {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'favicon' && $admin) {
        $old = custom_favicon_file();
        if (!empty($_POST['reset'])) {
            set_setting('favicon_file', null);
            delete_favicon_file($old);
            flash('success', t('Επανήλθε το αρχικό εικονίδιο.'));
        } else {
            [$fn, $err] = save_favicon_upload($_FILES['favicon'] ?? []);
            if ($err) {
                flash('error', $err);
            } else {
                set_setting('favicon_file', $fn);
                delete_favicon_file($old);
                flash('success', t('Το εικονίδιο άλλαξε.'));
            }
        }
    } elseif ($act === 'logo' && $admin) {
        $old = custom_logo_file();
        if (!empty($_POST['reset'])) {
            set_setting('logo_file', null);
            delete_logo_file($old);
            flash('success', t('Επανήλθε το αρχικό λογότυπο.'));
        } else {
            [$fn, $err] = save_logo_upload($_FILES['logo'] ?? []);
            if ($err) {
                flash('error', $err);
            } else {
                set_setting('logo_file', $fn);
                delete_logo_file($old);
                flash('success', t('Το λογότυπο άλλαξε.'));
            }
        }
    } elseif (($act === 'login_look' || $act === 'app_look') && $admin) {
        // login_look: η σελίδα σύνδεσης. app_look: η εφαρμογή για όσους δεν έχουν διαλέξει δική τους εμφάνιση.
        // Μόνο ο admin. Δεν αλλάζει την εμφάνιση που έχει αποθηκεύσει κάποιος χρήστης στο προφίλ του.
        $prefix = $act === 'app_look' ? 'app' : 'login';
        if (!empty($_POST['reset'])) {
            set_setting($prefix . '_theme', null);
            set_setting($prefix . '_bg', null);
        } else {
            $th = (string)($_POST['theme'] ?? '');
            if (isset(THEMES[$th])) {
                $bg = (string)($_POST['bg_' . $th] ?? '');
                set_setting($prefix . '_theme', $th);
                set_setting($prefix . '_bg', isset(theme_backgrounds($th)[$bg]) ? $bg : null);
            }
        }
        if (!empty($_POST['ajax'])) {
            // αυτόματη αποθήκευση από τη σελίδα (χωρίς ανανέωση)
            settings_all(true);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'now' => $prefix === 'app' ? app_look_label() : login_look_label()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash('success', $prefix === 'app' ? t('Η εμφάνιση της εφαρμογής αποθηκεύτηκε.') : t('Η εμφάνιση της σελίδας σύνδεσης αποθηκεύτηκε.'));
    } elseif ($act === 'auto_stop' && $admin) {
        $on = !empty($_POST['auto_stop_on']);
        $hours = (int)($_POST['auto_stop_hours'] ?? 0);
        if ($on && ($hours < 1 || $hours > AUTO_STOP_MAX_HOURS)) {
            flash('error', t('Οι ώρες για το αυτόματο σταμάτημα πρέπει να είναι από 1 έως {n}.', ['n' => AUTO_STOP_MAX_HOURS]));
        } else {
            set_setting('auto_stop_hours', (string)($on ? $hours : 0));
            flash('success', t('Οι ρυθμίσεις αποθηκεύτηκαν.'));
        }
    } elseif ($act === 'budget_repeat') {
        $on = !empty($_POST['repeat_on']);
        $raw = str_replace(',', '.', trim((string)($_POST['repeat_hours'] ?? '')));
        $hours = is_numeric($raw) ? round((float)$raw, 2) : 0.0;
        if ($on && ($hours < 0.25 || $hours > 1000)) {
            flash('error', t('Το βήμα πρέπει να είναι από 0,25 έως 1000 ώρες.'));
        } else {
            set_setting($repGroup ? 'budget_repeat_hours_g' . $repGroup : 'budget_repeat_hours', $on ? rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.') : '0');
            flash('success', t('Οι ρυθμίσεις αποθηκεύτηκαν.'));
        }
    }
    redirect('settings.php');
}

$hours = auto_stop_hours();
$running = (int)q_val('SELECT COUNT(*) FROM tt_entries WHERE end_at IS NULL');
$repeat = budget_repeat_secs($repGroup) / 3600;

render_header(t('Ρυθμίσεις'), 'settings');
page_head(h(t('Ρυθμίσεις')));
?>
<?php if ($admin): ?>
<form method="post" class="card mb-3" style="max-width:720px" enctype="multipart/form-data">
  <div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="act" value="logo">
    <h2 class="h5 d-flex align-items-center gap-2 mb-1"><?= icon('image') ?><?= h(t('Λογότυπο')) ?></h2>
    <p class="small text-body-secondary mb-3"><?= h(t('Εμφανίζεται πάνω αριστερά και στη σελίδα σύνδεσης. PNG με διάφανο φόντο, οριζόντιο, έως 3 MB.')) ?></p>
    <div class="logo-preview mb-3"><?= logo_img('logo-preview-img') ?></div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <label class="btn btn-primary mb-0"><?= icon('upload', 'me-1') ?><?= h(t('Ανέβασμα λογοτύπου')) ?><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" hidden onchange="this.form.submit()"></label>
      <?php if (custom_logo_file()): ?><button class="btn btn-light" type="submit" name="reset" value="1" data-confirm="<?= h(t('Επαναφορά του αρχικού λογοτύπου;')) ?>"><?= h(t('Επαναφορά αρχικού')) ?></button><?php endif; ?>
    </div>
  </div>
</form>
<form method="post" class="card mb-3" style="max-width:720px" enctype="multipart/form-data">
  <div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="act" value="favicon">
    <h2 class="h5 d-flex align-items-center gap-2 mb-1"><?= icon('window') ?><?= h(t('Εικονίδιο καρτέλας (favicon)')) ?></h2>
    <p class="small text-body-secondary mb-3"><?= h(t('Φαίνεται στην καρτέλα του browser και στις ειδοποιήσεις στην οθόνη. Τετράγωνη εικόνα PNG, τουλάχιστον 64×64, έως 1 MB.')) ?></p>
    <div class="d-flex flex-wrap align-items-center gap-3">
      <span class="favicon-preview"><img src="<?= h(favicon_href()) ?>" alt="" width="32" height="32"></span>
      <label class="btn btn-primary mb-0"><?= icon('upload', 'me-1') ?><?= h(t('Ανέβασμα εικονιδίου')) ?><input type="file" name="favicon" accept="image/png,image/jpeg,image/webp" hidden onchange="this.form.submit()"></label>
      <?php if (custom_favicon_file()): ?><button class="btn btn-light" type="submit" name="reset" value="1" data-confirm="<?= h(t('Επαναφορά του αρχικού εικονιδίου;')) ?>"><?= h(t('Επαναφορά αρχικού')) ?></button><?php endif; ?>
    </div>
  </div>
</form>
<?php
// Δύο ξεχωριστές εμφανίσεις: η σελίδα σύνδεσης και η εφαρμογή (για όσους δεν έχουν διαλέξει δική τους)
$looks = [
    'login' => [
        'look' => login_look(), 'label' => login_look_label(), 'icon' => 'box-arrow-in-right', 'id' => 'l',
        'title' => t('Εμφάνιση σελίδας σύνδεσης'),
        'help' => t('Το θέμα και το φόντο που βλέπουν όλοι στη σελίδα σύνδεσης. Δεν αλλάζει την εμφάνιση της εφαρμογής κανενός χρήστη. Η επιλογή αποθηκεύεται αμέσως.'),
        'preview' => 'login.php?preview=1',
    ],
    'app' => [
        'look' => app_look(), 'label' => app_look_label(), 'icon' => 'palette', 'id' => 'a',
        'title' => t('Εμφάνιση μέσα στην εφαρμογή'),
        'help' => t('Το θέμα και το φόντο μέσα στην εφαρμογή για όσους χρήστες δεν έχουν διαλέξει δική τους εμφάνιση στο προφίλ τους. Μπορεί να είναι διαφορετική από τη σελίδα σύνδεσης. Η επιλογή αποθηκεύεται αμέσως.'),
        'preview' => 'dashboard.php?look_preview=1',
    ],
];
foreach ($looks as $lk => $L):
    $ll = $L['look'];
    $llTheme = $ll[0] ?? 'glass';
    $llBg = $ll[1] ?? theme_default_bg($llTheme);
    $pid = $L['id'];
?>
<form method="post" class="card mb-3" style="max-width:720px" data-login-look data-preview="<?= h($L['preview']) ?>">
  <div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="act" value="<?= h($lk) ?>_look">
    <h2 class="h5 d-flex align-items-center gap-2 mb-1"><?= icon($L['icon']) ?><?= h($L['title']) ?></h2>
    <p class="small text-body-secondary mb-3"><?= h($L['help']) ?></p>
    <div class="login-look">
      <div class="login-look-pick">
        <div class="form-label"><?= h(t('Θέμα')) ?></div>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php foreach (THEMES as $code => $label): ?>
            <input type="radio" class="btn-check" name="theme" id="<?= $pid ?>th_<?= h($code) ?>" value="<?= h($code) ?>" <?= $llTheme === $code ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary btn-sm rounded-pill px-3" for="<?= $pid ?>th_<?= h($code) ?>"><?= h(t($label)) ?></label>
          <?php endforeach; ?>
        </div>
        <?php foreach (['glass' => t('Φόντο'), 'neo' => t('Σύνθεση'), 'minimal' => t('Σύνθεση')] as $pTheme => $pTitle):
            $pSel = ($ll && $ll[0] === $pTheme) ? $ll[1] : theme_default_bg($pTheme); ?>
        <div data-theme-picker="<?= h($pTheme) ?>"<?= $llTheme === $pTheme ? '' : ' hidden' ?>>
          <div class="form-label"><?= h($pTitle) ?></div>
          <div class="bg-picker mb-3">
            <?php foreach (theme_backgrounds($pTheme) as $code => [$label, $kind, $tone]): ?>
              <input type="radio" class="btn-check" name="bg_<?= h($pTheme) ?>" id="<?= $pid ?>bg_<?= h($code) ?>" value="<?= h($code) ?>" <?= $pSel === $code ? 'checked' : '' ?>>
              <label class="bg-option" for="<?= $pid ?>bg_<?= h($code) ?>">
                <span class="bg-swatch<?= $kind === 'scheme' ? ' sw-' . h($pTheme) : '' ?>" data-swatch="<?= h($code) ?>"><?php if ($kind === 'scheme'): ?><i></i><?php endif; ?></span>
                <span class="small"><?= h(t($label)) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="login-look-preview">
        <div class="form-label"><?= h(t('Προεπισκόπηση')) ?></div>
        <div class="login-look-frame"><iframe src="<?= h($L['preview']) ?>&amp;theme=<?= h($llTheme) ?>&amp;bg=<?= h($llBg) ?>" title="<?= h(t('Προεπισκόπηση')) ?>" loading="lazy" tabindex="-1" data-login-frame></iframe></div>
      </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
      <span class="small" data-login-status><?= h(t('Τώρα:')) ?> <strong data-login-now><?= h($L['label']) ?></strong></span>
      <span class="small text-success-emphasis" data-login-saved hidden><?= icon('check2', 'me-1') ?><?= h(t('Αποθηκεύτηκε')) ?></span>
      <?php if ($ll): ?><button class="btn btn-light btn-sm ms-auto" type="submit" name="reset" value="1"><?= h(t('Κατάργηση')) ?></button><?php endif; ?>
      <noscript><button class="btn btn-primary btn-sm" type="submit"><?= h(t('Αποθήκευση')) ?></button></noscript>
    </div>
  </div>
</form>
<?php endforeach; ?>
<form method="post" class="card mb-3" style="max-width:720px">
  <div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="act" value="auto_stop">
    <h2 class="h5 d-flex align-items-center gap-2 mb-1"><?= icon('stopwatch') ?><?= h(t('Αυτόματο σταμάτημα χρονομέτρου')) ?></h2>
    <p class="small text-body-secondary mb-3"><?= h(t('Αν κάποιος ξεχάσει το χρονόμετρο ανοιχτό, σταματά μόνο του όταν φτάσει αυτές τις ώρες. Η ώρα λήξης μπαίνει στην έναρξη + τις ώρες και ο χρήστης βλέπει ειδοποίηση στο καμπανάκι για να τη διορθώσει.')) ?></p>
    <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" role="switch" id="asOn" name="auto_stop_on" value="1" <?= $hours > 0 ? 'checked' : '' ?> data-toggle-row="asHoursRow">
      <label class="form-check-label" for="asOn"><?= h(t('Ενεργό')) ?></label>
    </div>
    <div class="d-flex align-items-center gap-2 mb-3" id="asHoursRow">
      <label class="form-label mb-0" for="asHours"><?= h(t('Σταματά μετά από')) ?></label>
      <input type="number" class="form-control text-center" id="asHours" name="auto_stop_hours" value="<?= $hours > 0 ? $hours : 8 ?>" min="1" max="<?= AUTO_STOP_MAX_HOURS ?>" step="1" style="width:6rem">
      <span><?= h(t('ώρες')) ?></span>
    </div>
    <p class="small text-body-secondary mb-3"><?= h(t('Η αλλαγή ισχύει αμέσως και για όσα χρονόμετρα τρέχουν ήδη (τώρα τρέχουν: {n}).', ['n' => $running])) ?></p>
    <button class="btn btn-primary" type="submit"><?= h(t('Αποθήκευση')) ?></button>
  </div>
</form>
<?php endif; ?>
<form method="post" class="card" style="max-width:720px">
  <div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="act" value="budget_repeat">
    <h2 class="h5 d-flex align-items-center gap-2 mb-1"><?= icon('bell') ?><?= h(t('Επανάληψη ειδοποίησης ορίου ωρών')) ?></h2>
    <p class="small text-body-secondary mb-3"><?= h(t('Όταν ένα project ξεπεράσει το όριο ωρών, έρχεται ξανά ειδοποίηση κάθε φορά που περνά αυτές τις επιπλέον ώρες. Π.χ. όριο 3 ώρες και βήμα 1 ώρα: ειδοποίηση στις 3, στις 3 + 1, στις 3 + 2 κ.ο.κ. Ισχύει για όλα τα projects.')) ?></p>
    <?php if ($repGroupName !== ''): ?><p class="small mb-3"><?= h(t('Ισχύει μόνο για την ομάδα')) ?> <strong><?= h($repGroupName) ?></strong>.</p><?php endif; ?>
    <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" role="switch" id="rpOn" name="repeat_on" value="1" <?= $repeat > 0 ? 'checked' : '' ?> data-toggle-row="rpRow">
      <label class="form-check-label" for="rpOn"><?= h(t('Ενεργό')) ?></label>
    </div>
    <div class="d-flex align-items-center gap-2 mb-3" id="rpRow">
      <label class="form-label mb-0" for="rpHours"><?= h(t('Νέα ειδοποίηση κάθε')) ?></label>
      <input type="number" class="form-control text-center" id="rpHours" name="repeat_hours" value="<?= h(rtrim(rtrim(number_format($repeat > 0 ? $repeat : 1, 2, '.', ''), '0'), '.')) ?>" min="0.25" max="1000" step="0.25" style="width:6rem">
      <span><?= h(t('ώρες πάνω από το όριο')) ?></span>
    </div>
    <p class="small text-body-secondary mb-3"><?= h(t('Απενεργοποιημένο: μία μόνο ειδοποίηση όταν ξεπεραστεί το όριο.')) ?></p>
    <button class="btn btn-primary" type="submit"><?= h(t('Αποθήκευση')) ?></button>
  </div>
</form>
<script>
(function () {
  document.querySelectorAll('[data-toggle-row]').forEach(function (sw) {
    var row = document.getElementById(sw.getAttribute('data-toggle-row')), inp = row.querySelector('input');
    function upd() { row.classList.toggle('opacity-50', !sw.checked); inp.disabled = !sw.checked; }
    sw.addEventListener('change', upd); upd();
  });
})();
</script>
<?php render_footer();
