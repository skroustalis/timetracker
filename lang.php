<?php
declare(strict_types=1);
// Προτιμήσεις χρήστη: γλώσσα και θέμα εμφάνισης
require __DIR__ . '/includes/bootstrap.php';

$lang = (string)($_POST['lang'] ?? $_GET['lang'] ?? '');
$theme = (string)($_POST['theme'] ?? '');
$return = safe_return($_POST['return'] ?? $_GET['return'] ?? null);
$u = current_user();

$cookie = fn(string $name, string $value) => setcookie($name, $value, [
    'expires' => time() + 86400 * 365,
    'path' => app_base_path(),
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (is_post()) {
    csrf_check();
    if (isset(LANGS[$lang])) {
        if ($u) q('UPDATE tt_users SET lang = ? WHERE id = ?', [$lang, $u['id']]);
        $cookie(LANG_COOKIE, $lang);
    }
    if (isset(THEMES[$theme])) {
        if ($u) q('UPDATE tt_users SET theme = ? WHERE id = ?', [$theme, $u['id']]);
        $cookie(THEME_COOKIE, $theme);
    }
    // Κάθε θέμα έχει δική του λίστα φόντων/συνθέσεων (πεδίο bg_<θέμα>, ή bg για συμβατότητα)
    $forTheme = isset(THEMES[$theme]) ? $theme : current_theme();
    $bg = (string)($_POST['bg_' . $forTheme] ?? $_POST['bg'] ?? '');
    if (isset(theme_backgrounds($forTheme)[$bg])) {
        if ($u) q('UPDATE tt_users SET bg = ? WHERE id = ?', [$bg, $u['id']]);
        $cookie('tt_bg', $bg);
    }
    if (!empty($_POST['saved'])) flash('success', t('Η εμφάνιση αποθηκεύτηκε.'));
} elseif (!$u && isset(LANGS[$lang])) {
    // Αλλαγή γλώσσας μέσω GET μόνο χωρίς σύνδεση (σελίδα login)
    $cookie(LANG_COOKIE, $lang);
}
redirect($return);
