<?php
declare(strict_types=1);
/**
 * Εγκατάσταση από τον browser.
 * 1) Κλειδί εγκατάστασης + στοιχεία βάσης -> φτιάχνει πίνακες και includes/config.php
 * 2) Πρώτος λογαριασμός admin -> κλειδώνει (σβήνει το includes/install_key.php)
 */
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Athens');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

session_name('tt_install');
session_start();

$configFile = __DIR__ . '/includes/config.php';
$keyFile = __DIR__ . '/includes/install_key.php';
$step = 1;
$error = '';
$pdo = null;
$CONFIG = null;

if (is_file($configFile)) {
    $CONFIG = require $configFile;
    try {
        $c = $CONFIG['db'];
        $pdo = db_connect($c['host'], $c['name'], $c['user'], $c['pass'], (int)($c['port'] ?? 3306));
        $hasUsers = (int)$pdo->query("SELECT COUNT(*) FROM tt_users")->fetchColumn() > 0;
        if ($hasUsers) $step = 3; else $step = 2;
    } catch (Throwable $e) {
        $step = 1;
        $error = 'Υπάρχει ρύθμιση, αλλά η σύνδεση στη βάση απέτυχε. Ξαναβάλε τα στοιχεία.';
    }
}
// Πρώτη εγκατάσταση: φτιάχνεται τυχαίο κλειδί. Το hash μπαίνει στο install_key.php και το ίδιο το κλειδί
// στο includes/INSTALL_KEY.txt, που δεν ανοίγει από τον browser (includes/.htaccess) — ο κάτοχος το διαβάζει με FTP/File Manager.
$keyTxt = __DIR__ . '/includes/INSTALL_KEY.txt';
if (!is_file($configFile) && !is_file($keyFile)) {
    $newKey = implode('-', str_split(bin2hex(random_bytes(12)), 6));
    $okHash = @file_put_contents($keyFile, "<?php\nreturn " . var_export(password_hash($newKey, PASSWORD_DEFAULT), true) . ";\n", LOCK_EX);
    $okTxt = @file_put_contents($keyTxt, $newKey . "\n", LOCK_EX);
    if ($okHash === false || $okTxt === false) {
        @unlink($keyFile);
        @unlink($keyTxt);
        $error = 'Δεν μπόρεσα να γράψω στον φάκελο includes. Έλεγξε ότι έχει δικαίωμα εγγραφής (755) και ξαναφόρτωσε τη σελίδα.';
    }
}
if ($step < 3 && !is_file($keyFile)) $step = 4; // κλειδωμένο

function key_ok(string $k): bool
{
    global $keyFile;
    if (!is_file($keyFile)) return false;
    $hash = require $keyFile;
    return is_string($hash) && $k !== '' && password_verify(trim($k), $hash);
}

function run_schema(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}

if (is_post() && $step === 1) {
    csrf_check();
    $k = (string)($_POST['setup_key'] ?? '');
    $host = post_str('db_host', 100) ?: 'localhost';
    $port = post_int('db_port') ?? 3306;
    $name = post_str('db_name', 64);
    $usr = post_str('db_user', 64);
    $pass = (string)($_POST['db_pass'] ?? '');
    $appName = post_str('app_name', 60) ?: 'Time Tracker';
    if (!key_ok($k)) {
        $error = 'Λάθος κλειδί εγκατάστασης.';
        sleep(1);
    } else {
        try {
            $pdo = db_connect($host, $name, $usr, $pass, $port);
            run_schema($pdo);
            $cfg = [
                'app_name' => $appName,
                'timezone' => 'Europe/Athens',
                'debug' => false,
                'db' => ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $usr, 'pass' => $pass],
            ];
            $php = "<?php\n// Δημιουργήθηκε από το install.php στις " . date('d/m/Y H:i') . "\nreturn " . var_export($cfg, true) . ";\n";
            if (@file_put_contents($configFile, $php, LOCK_EX) === false) {
                $error = 'Δεν μπόρεσα να γράψω το includes/config.php. Έλεγξε ότι ο φάκελος includes έχει δικαίωμα εγγραφής (755).';
            } else {
                @chmod($configFile, 0640);
                $_SESSION['install_ok'] = true;
                header('Location: install.php');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Η σύνδεση στη βάση απέτυχε: ' . $e->getMessage();
        }
    }
}

if (is_post() && $step === 2) {
    csrf_check();
    if (empty($_SESSION['install_ok']) && !key_ok((string)($_POST['setup_key'] ?? ''))) {
        $error = 'Λάθος κλειδί εγκατάστασης.';
    } else {
        $_SESSION['install_ok'] = true;
        $name = post_str('name', 120);
        $email = mb_strtolower(post_str('email', 190));
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Συμπλήρωσε όνομα και σωστό email.';
        elseif (mb_strlen($p1) < 8) $error = 'Ο κωδικός θέλει τουλάχιστον 8 χαρακτήρες.';
        elseif ($p1 !== $p2) $error = 'Οι κωδικοί δεν ταιριάζουν.';
        else {
            $st = $pdo->prepare('INSERT INTO tt_users (name, email, password_hash, role, active, created_at) VALUES (?, ?, ?, \'admin\', 1, ?)');
            $st->execute([$name, $email, password_hash($p1, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            @unlink($keyFile);
            @unlink($keyTxt);
            unset($_SESSION['install_ok']);
            header('Location: login.php');
            exit;
        }
    }
}
?><!doctype html>
<html lang="el" data-theme="glass" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Εγκατάσταση</title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="auth-wrap">
  <div class="card auth-card wide shadow-lg">
    <div class="card-body p-4 p-sm-5">
    <img class="auth-logo mb-3" src="assets/img/logo.png" alt="Time Tracker" width="640" height="119">
    <h1 class="h4 mb-3 text-center">Εγκατάσταση</h1>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <?php if ($step === 1): ?>
      <p class="text-body-secondary">Βήμα 1 από 2: σύνδεση με τη βάση δεδομένων. Θα φτιαχτούν οι πίνακες αυτόματα.</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Κλειδί εγκατάστασης</label><input class="form-control" type="text" name="setup_key" required autocomplete="off">
          <div class="form-text">Θα το βρεις στο αρχείο <code>includes/INSTALL_KEY.txt</code> (άνοιξέ το με FTP ή από το File Manager).</div></div>
        <div class="mb-3"><label class="form-label">Όνομα εφαρμογής</label><input class="form-control" type="text" name="app_name" value="Time Tracker" maxlength="60"></div>
        <div class="row g-3 mb-3">
          <div class="col-8"><label class="form-label">Server βάσης</label><input class="form-control" type="text" name="db_host" value="localhost"></div>
          <div class="col-4"><label class="form-label">Port</label><input class="form-control" type="text" name="db_port" value="3306" inputmode="numeric"></div>
        </div>
        <div class="mb-3"><label class="form-label">Όνομα βάσης</label><input class="form-control" type="text" name="db_name" placeholder="π.χ. cpaneluser_timetracker" required></div>
        <div class="mb-3"><label class="form-label">Χρήστης βάσης</label><input class="form-control" type="text" name="db_user" placeholder="π.χ. cpaneluser_tt" required></div>
        <div class="mb-4"><label class="form-label">Κωδικός χρήστη βάσης</label><input class="form-control" type="password" name="db_pass" autocomplete="new-password"></div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Σύνδεση &amp; δημιουργία πινάκων</button>
      </form>

    <?php elseif ($step === 2): ?>
      <div class="alert alert-success">Η βάση είναι έτοιμη.</div>
      <p class="text-body-secondary">Βήμα 2 από 2: ο πρώτος λογαριασμός διαχειριστή (admin).</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <?php if (empty($_SESSION['install_ok'])): ?>
          <div class="mb-3"><label class="form-label">Κλειδί εγκατάστασης</label><input class="form-control" type="text" name="setup_key" required></div>
        <?php endif; ?>
        <div class="mb-3"><label class="form-label">Ονοματεπώνυμο</label><input class="form-control" type="text" name="name" required maxlength="120"></div>
        <div class="mb-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required maxlength="190"></div>
        <div class="row g-3 mb-4">
          <div class="col-sm-6"><label class="form-label">Κωδικός (τουλ. 8)</label><input class="form-control" type="password" name="password" minlength="8" required autocomplete="new-password"></div>
          <div class="col-sm-6"><label class="form-label">Ξανά ο κωδικός</label><input class="form-control" type="password" name="password2" minlength="8" required autocomplete="new-password"></div>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Δημιουργία admin &amp; τέλος</button>
      </form>

    <?php elseif ($step === 3): ?>
      <div class="alert alert-success">Η εφαρμογή είναι ήδη εγκατεστημένη.</div>
      <a class="btn btn-primary" href="login.php">Σύνδεση</a>

    <?php else: ?>
      <div class="alert alert-danger mb-0">Η εγκατάσταση είναι κλειδωμένη (λείπει το κλειδί εγκατάστασης).</div>
    <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
