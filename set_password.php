<?php
declare(strict_types=1);
// Ορισμός κωδικού από link email (πρόσκληση ή νέος κωδικός)
require __DIR__ . '/includes/bootstrap.php';

header('Referrer-Policy: no-referrer');   // το token δεν φεύγει ποτέ σε άλλο site
header('Cache-Control: no-store');

$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$row = find_password_token($token);
if ($row) set_lang(detect_lang($row));

$error = '';
if ($row && is_post()) {
    csrf_check();
    $new = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');
    if (mb_strlen($new) < 10) {
        $error = t('Ο κωδικός θέλει τουλάχιστον 10 χαρακτήρες.');
    } elseif ($new !== $new2) {
        $error = t('Οι δύο κωδικοί δεν ταιριάζουν.');
    } elseif (mb_strtolower($new) === mb_strtolower((string)$row['email'])) {
        $error = t('Ο κωδικός δεν μπορεί να είναι το email σου.');
    } else {
        use_password_token($row, $new);
        // Αν ήταν συνδεδεμένος κάποιος άλλος σε αυτόν τον browser, τον αποσυνδέουμε
        if (current_user() && (int)current_user()['id'] !== (int)$row['id']) logout();
        flash('success', t('Ο κωδικός ορίστηκε. Σύνδεσου με το email σου και τον νέο κωδικό.'));
        redirect('login.php');
    }
}

// Σελίδα σύνδεσης, όχι σελίδα της εφαρμογής: χωρίς μενού και μπάρα, ακόμα κι αν σε αυτόν τον browser
// είναι συνδεδεμένος κάποιος άλλος (π.χ. ο admin που άνοιξε το link για δοκιμή).
$signedIn = current_user();
$otherUser = $row && $signedIn && (int)$signedIn['id'] !== (int)$row['id'] ? $signedIn : null;
$GLOBALS['__CURRENT_USER'] = null;
render_header(t('Ορισμός κωδικού'));
?>
<div class="auth-wrap">
  <div class="card auth-card shadow-lg">
    <div class="card-body p-4 p-sm-5">
      <div class="text-center mb-4 auth-head">
        <?php if (in_array(current_theme(), ['glass', 'neo', 'minimal'], true)): ?>
          <?= logo_img('auth-logo mb-3') ?>
        <?php endif; ?>
        <h1 class="h5 mb-0 fw-semibold"><?= h(t('Ορισμός κωδικού')) ?></h1>
      </div>
      <?php if (!$row): ?>
        <div class="alert alert-warning mb-3"><?= h(t('Το link δεν ισχύει: έχει λήξει ή έχει ήδη χρησιμοποιηθεί. Ζήτα από έναν διαχειριστή να σου στείλει νέο.')) ?></div>
        <a class="btn btn-primary w-100" href="login.php"><?= h(t('Σύνδεση')) ?></a>
      <?php else: ?>
        <p class="small text-body-secondary text-center mb-4"><?= h($row['name']) ?> · <?= h($row['email']) ?></p>
        <?php if ($otherUser): ?><div class="alert alert-warning small"><?= h(t('Σε αυτόν τον browser είσαι συνδεδεμένος ως {n}. Αν ορίσεις κωδικό, θα αποσυνδεθείς.', ['n' => $otherUser['name']])) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <form method="post" action="set_password.php" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="t" value="<?= h($token) ?>">
          <input type="text" name="username" value="<?= h($row['email']) ?>" autocomplete="username" hidden>
          <div class="mb-3">
            <label class="form-label" for="spNew"><?= h(t('Νέος κωδικός')) ?> <span class="small text-body-secondary">(<?= h(t('τουλ. 10 χαρακτήρες')) ?>)</span></label>
            <input class="form-control form-control-lg" type="password" id="spNew" name="new_password" minlength="10" required autofocus autocomplete="new-password">
          </div>
          <div class="mb-4">
            <label class="form-label" for="spNew2"><?= h(t('Ξανά ο νέος')) ?></label>
            <input class="form-control form-control-lg" type="password" id="spNew2" name="new_password2" minlength="10" required autocomplete="new-password">
          </div>
          <button class="btn btn-primary btn-lg w-100" type="submit"><?= h(t('Αποθήκευση κωδικού')) ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php render_footer();
