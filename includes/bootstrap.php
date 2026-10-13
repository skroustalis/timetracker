<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.9.33');

$__configFile = __DIR__ . '/config.php';
if (!is_file($__configFile)) {
    header('Location: install.php');
    exit;
}
$CONFIG = require $__configFile;

date_default_timezone_set($CONFIG['timezone'] ?? 'Europe/Athens');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');
ini_set('log_errors', '1');

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require __DIR__ . '/i18n.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/data.php';
require __DIR__ . '/avatar.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/invite.php';
require __DIR__ . '/notify.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/migrate.php';

// Η εφαρμογή δεν περιμένει ποτέ λίστες στο URL: ένα πειραγμένο link (π.χ. ?by1[]=x) αγνοείται
foreach ($_GET as $__k => $__v) {
    if (is_array($__v)) unset($_GET[$__k]);
}
// Απρόβλεπτο σφάλμα: φιλικό μήνυμα αντί για λευκή σελίδα (το σφάλμα γράφεται στο log του server)
set_exception_handler(function (Throwable $e): void {
    global $CONFIG;
    error_log((string)$e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg = function_exists('t') ? t('Κάτι πήγε στραβά. Δοκίμασε ξανά ή γύρνα στην αρχική.') : 'Something went wrong.';
    $back = function_exists('t') ? t('Αρχική') : 'Home';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:15vh auto;padding:0 16px;text-align:center">'
        . '<p style="font-size:1.1rem">' . htmlspecialchars($msg) . '</p><p><a href="tracker.php">' . htmlspecialchars($back) . '</a></p>'
        . (!empty($CONFIG['debug']) ? '<pre style="text-align:left;white-space:pre-wrap;font-size:12px">' . htmlspecialchars((string)$e) . '</pre>' : '')
        . '</div>';
});

function app_base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/') . '/';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function app_name(): string
{
    global $CONFIG;
    return (string)($CONFIG['app_name'] ?? 'Time Tracker');
}

session_name('tt_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => app_base_path(),
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

run_migrations();
auth_bootstrap();
auto_stop_long_timers();
check_running_budgets();
if (current_user()) sync_budget_notifications(current_user());
set_lang(detect_lang(current_user()));

// Ρυθμίσεις → Εμφάνιση εφαρμογής: ο admin βλέπει προεπισκόπηση με ?look_preview=1&theme=…&bg=… (δεν αποθηκεύεται τίποτα)
if (isset($_GET['look_preview']) && !is_post() && is_admin()) {
    $__pth = (string)($_GET['theme'] ?? '');
    if (isset(THEMES[$__pth])) {
        $__pbg = (string)($_GET['bg'] ?? '');
        $GLOBALS['TT_APP_PREVIEW'] = [$__pth, isset(theme_backgrounds($__pth)[$__pbg]) ? $__pbg : theme_default_bg($__pth)];
    }
}
