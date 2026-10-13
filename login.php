<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$ret = safe_return($_GET['r'] ?? $_POST['r'] ?? null);
// Προεπισκόπηση για τον admin (Ρυθμίσεις → Εμφάνιση σελίδας σύνδεσης): δείχνει τη σελίδα όπως τη βλέπει κάποιος χωρίς σύνδεση
$preview = false;
if (current_user()) {
    if (is_admin() && isset($_GET['preview']) && is_post() === false) {
        $pth = (string)($_GET['theme'] ?? '');
        if (isset(THEMES[$pth])) {
            $pbg = (string)($_GET['bg'] ?? '');
            $GLOBALS['TT_LOGIN_PREVIEW'] = [$pth, isset(theme_backgrounds($pth)[$pbg]) ? $pbg : theme_default_bg($pth)];
        }
        $GLOBALS['__CURRENT_USER'] = null; // μόνο για αυτή τη σελίδα: εμφάνιση σαν επισκέπτης
        $preview = true;
    } else {
        redirect($ret);
    }
}

$error = '';
$email = '';
if (is_post()) {
    csrf_check();
    $email = post_str('email', 190);
    if (login_throttled($email)) {
        $error = t('Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε {n} λεπτά.', ['n' => LOGIN_WINDOW_MIN]);
    } elseif (attempt_login($email, (string)($_POST['password'] ?? ''), !empty($_POST['remember']))) {
        redirect($ret);
    } else {
        $error = t('Λάθος email ή κωδικός.');
    }
}

render_header(t('Σύνδεση'));
?>
<div class="auth-wrap">
  <div class="card auth-card shadow-lg">
    <div class="card-body p-4 p-sm-5">
      <div class="text-center mb-4 auth-head">
        <?php if (in_array(current_theme(), ['glass', 'neo', 'minimal'], true)): ?>
          <?= logo_img('auth-logo mb-3') ?>
        <?php else: ?>
          <span class="brand-mark mb-3" style="width:56px;height:56px;font-size:1.8rem;border-radius:14px"><?= icon('stopwatch') ?></span>
        <?php endif; ?>
        <h1 class="h5 mb-0 fw-semibold"><?= h(app_name()) ?></h1>
      </div>
      <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
      <form method="post"<?= $preview ? ' inert' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="r" value="<?= h($ret) ?>">
        <div class="mb-3">
          <label class="form-label" for="email">Email</label>
          <input class="form-control form-control-lg" type="email" id="email" name="email" value="<?= h($email) ?>" required autofocus autocomplete="username">
        </div>
        <div class="mb-3">
          <label class="form-label" for="password"><?= h(t('Κωδικός')) ?></label>
          <input class="form-control form-control-lg" type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1" checked>
          <label class="form-check-label" for="remember"><?= h(t('Να με θυμάσαι σε αυτή τη συσκευή')) ?></label>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit"><?= h(t('Σύνδεση')) ?></button>
      </form>
      <p class="small text-body-secondary text-center mt-4 mb-2"><?= h(t('Ξέχασες τον κωδικό; Ζήτα από έναν διαχειριστή να σου βάλει νέο.')) ?></p>
      <div class="text-center small">
        <?php foreach (LANGS as $code => $label): ?>
          <a class="mx-1 <?= lang() === $code ? 'fw-semibold text-body' : '' ?>" href="lang.php?lang=<?= h($code) ?>&amp;return=<?= h(urlencode('login.php' . ($ret !== 'tracker.php' ? '?r=' . urlencode($ret) : ''))) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
