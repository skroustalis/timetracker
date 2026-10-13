<?php
declare(strict_types=1);

const REMEMBER_COOKIE = 'tt_remember';
const REMEMBER_DAYS = 30;
const LOGIN_MAX_ATTEMPTS = 8;      // ανά λογαριασμό (email)
const LOGIN_MAX_ATTEMPTS_IP = 30;  // ανά IP, για όποιον δοκιμάζει πολλούς λογαριασμούς
const LOGIN_WINDOW_MIN = 15;

$__CURRENT_USER = null;

function auth_bootstrap(): void
{
    global $__CURRENT_USER;
    if (!empty($_SESSION['uid'])) {
        $u = q_one('SELECT * FROM tt_users WHERE id = ? AND active = 1', [(int)$_SESSION['uid']]);
        // αν άλλαξε ο κωδικός (σε άλλη συσκευή ή από τον admin), οι παλιές συνδέσεις κλείνουν
        if ($u && (int)($_SESSION['sv'] ?? 0) !== (int)($u['session_ver'] ?? 0)) $u = null;
        if ($u) {
            $__CURRENT_USER = $u;
            return;
        }
        unset($_SESSION['uid']);
    }
    // "Να με θυμάσαι"
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($cookie !== '' && str_contains($cookie, ':')) {
        [$selector, $validator] = explode(':', $cookie, 2);
        $row = q_one('SELECT * FROM tt_remember_tokens WHERE selector = ? AND expires_at > ?', [$selector, now_sql()]);
        if ($row && hash_equals($row['validator_hash'], hash('sha256', $validator))) {
            $u = q_one('SELECT * FROM tt_users WHERE id = ? AND active = 1', [(int)$row['user_id']]);
            if ($u) {
                q('DELETE FROM tt_remember_tokens WHERE id = ?', [$row['id']]);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                $_SESSION['sv'] = (int)($u['session_ver'] ?? 0);
                issue_remember_token((int)$u['id']);
                $__CURRENT_USER = $u;
                return;
            }
        }
        clear_remember_cookie();
    }
}

function current_user(): ?array
{
    global $__CURRENT_USER;
    return $__CURRENT_USER;
}

function is_admin(?array $u = null): bool
{
    $u ??= current_user();
    return $u !== null && $u['role'] === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $ret = basename($_SERVER['SCRIPT_NAME'] ?? 'tracker.php');
        if (!empty($_SERVER['QUERY_STRING'])) $ret .= '?' . $_SERVER['QUERY_STRING'];
        redirect('login.php?r=' . urlencode($ret));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!is_admin($u)) deny_access(t('Η σελίδα αυτή είναι μόνο για διαχειριστές.'));
    return $u;
}

/** Admin ή manager ομάδας */
function require_lead(): array
{
    $u = require_login();
    if (!is_lead($u)) deny_access(t('Η σελίδα αυτή είναι μόνο για διαχειριστές και managers.'));
    return $u;
}

function deny_access(string $msg): never
{
    http_response_code(403);
    render_header(t('Δεν επιτρέπεται'), '');
    echo '<div class="card"><div class="card-body"><h2 class="h4">' . h(t('Δεν έχεις πρόσβαση')) . '</h2><p class="mb-0">' . h($msg) . '</p></div></div>';
    render_footer();
    exit;
}

/* ---------- Ρόλοι: admin (τα πάντα) > manager (μία ομάδα) > μέλος (μόνο τον εαυτό του) ---------- */

const ROLES = ['admin' => 'Admin', 'manager' => 'Manager', 'member' => 'Μέλος'];

function role_label(string $role): string
{
    return t(ROLES[$role] ?? ROLES['member']);
}

function is_manager(?array $u = null): bool
{
    $u ??= current_user();
    return $u !== null && $u['role'] === 'manager';
}

/** Η (μία) ομάδα ενός χρήστη ή null */
function user_group_id(int $uid): ?int
{
    static $cache = [];
    if (!array_key_exists($uid, $cache)) {
        $g = q_val('SELECT group_id FROM tt_group_members WHERE user_id = ? LIMIT 1', [$uid]);
        $cache[$uid] = $g === null ? null : (int)$g;
    }
    return $cache[$uid];
}

/** Η ομάδα που διαχειρίζεται ο χρήστης (μόνο για managers) */
function managed_group_id(?array $u = null): ?int
{
    $u ??= current_user();
    return is_manager($u) ? user_group_id((int)$u['id']) : null;
}

/**
 * Η ομάδα (Developers, Marketing…) που «βλέπει» τώρα ο χρήστης. Οι ομάδες είναι εντελώς χωριστές:
 * projects, tags, χρήστες και αναφορές φαίνονται μόνο μέσα στην ίδια ομάδα.
 * Μέλος / manager: η ομάδα του. Admin: όποια διάλεξε στην πάνω μπάρα (προεπιλογή η πρώτη).
 */
function current_dept(?array $u = null): ?int
{
    $u ??= current_user();
    if (!$u) return null;
    if (!is_admin($u)) return user_group_id((int)$u['id']);
    static $dept = false;
    if ($dept !== false) return $dept;
    $ids = array_map('intval', array_column(q_all('SELECT id FROM tt_groups ORDER BY id'), 'id'));
    $want = (int)($_SESSION['dept'] ?? $_COOKIE['tt_dept'] ?? 0);
    if (!in_array($want, $ids, true)) {
        // προεπιλογή: η ομάδα του admin, αλλιώς η πρώτη
        $own = user_group_id((int)$u['id']);
        $want = $own && in_array($own, $ids, true) ? $own : ($ids[0] ?? 0);
    }
    return $dept = $want ?: null;
}

/** Admin ή manager με ομάδα: βλέπει σελίδες ομάδας (Χρήστες, Πελάτες, χρόνο άλλων) */
function is_lead(?array $u = null): bool
{
    $u ??= current_user();
    return $u !== null && (is_admin($u) || managed_group_id($u) !== null);
}

/**
 * Χρήστες που επιτρέπεται να δει ο χρήστης: null = όλους, αλλιώς λίστα ids.
 * Admin: τα μέλη της ομάδας που έχει επιλέξει + όσοι έχουν ώρες σε projects της (π.χ. άλλαξαν ομάδα).
 * Manager: το ίδιο για την ομάδα του, χωρίς τον admin (μόνο ο admin βλέπει τα πάντα).
 * Μέλος: μόνο τον εαυτό του.
 */
function team_user_ids(array $u): ?array
{
    static $cache = [];
    if (is_admin($u)) {
        $gid = current_dept($u);
        if ($gid === null) return null;
        $exclAdmins = false;
    } else {
        $gid = managed_group_id($u);
        if ($gid === null) return [(int)$u['id']];
        $exclAdmins = true;
    }
    $key = $gid . ':' . (int)$exclAdmins;
    if (!isset($cache[$key])) {
        $ids = array_map('intval', array_column(q_all(
            'SELECT gm.user_id AS id FROM tt_group_members gm WHERE gm.group_id = ?
             UNION
             SELECT DISTINCT e.user_id FROM tt_projects p JOIN tt_entries e ON e.project_id = p.id WHERE p.group_id = ?
             UNION
             SELECT DISTINCT e.user_id FROM tt_entries e WHERE e.group_id = ?', [$gid, $gid, $gid]), 'id'));
        if ($exclAdmins && $ids) {
            $admins = array_map('intval', array_column(q_all("SELECT id FROM tt_users WHERE role = 'admin'"), 'id'));
            $ids = array_values(array_diff($ids, $admins));
        }
        $cache[$key] = $ids;
    }
    $ids = $cache[$key];
    if (!is_admin($u) && !in_array((int)$u['id'], $ids, true)) $ids[] = (int)$u['id'];
    return $ids ?: [0];
}

function can_see_user(array $actor, int $uid): bool
{
    $ids = team_user_ids($actor);
    return $ids === null || in_array($uid, $ids, true);
}

/** Μπορεί να διαχειριστεί τον λογαριασμό: admin όλους, manager μόνο τα μέλη της ομάδας του */
function can_manage_user(array $actor, array $target): bool
{
    if (is_admin($actor)) return true;
    $gid = managed_group_id($actor);
    return $gid !== null && $target['role'] === 'member' && user_group_id((int)$target['id']) === $gid;
}

/** Βάζει τον χρήστη σε μία (το πολύ) ομάδα */
function set_user_group(int $uid, ?int $gid): void
{
    // οι καταχωρήσεις του χωρίς project μένουν στην παλιά ομάδα (όπως και όσες έχουν project της παλιάς ομάδας)
    $old = q_val('SELECT group_id FROM tt_group_members WHERE user_id = ? LIMIT 1', [$uid]);
    if ($old !== null && (int)$old !== (int)$gid) {
        q('UPDATE tt_entries SET group_id = ? WHERE user_id = ? AND project_id IS NULL AND group_id IS NULL', [(int)$old, $uid]);
    }
    q('DELETE FROM tt_group_members WHERE user_id = ?', [$uid]);
    if ($gid) q('INSERT INTO tt_group_members (group_id, user_id) SELECT id, ? FROM tt_groups WHERE id = ?', [$uid, $gid]);
}

/** Όλοι οι ενεργοί managers μιας ομάδας (μια ομάδα μπορεί να έχει όσους managers θέλει) */
function group_managers(int $gid): array
{
    return q_all("SELECT u.* FROM tt_users u JOIN tt_group_members gm ON gm.user_id = u.id
                  WHERE gm.group_id = ? AND u.role = 'manager' AND u.active = 1 ORDER BY u.name", [$gid]);
}

/** Ένας manager μιας ομάδας (ή null) — εκτός από τον $exceptUid */
function group_manager(int $gid, int $exceptUid = 0): ?array
{
    return q_one("SELECT u.* FROM tt_users u JOIN tt_group_members gm ON gm.user_id = u.id
                  WHERE gm.group_id = ? AND u.role = 'manager' AND u.active = 1 AND u.id <> ? LIMIT 1", [$gid, $exceptUid]);
}

/** Κλείδωμα: 8 λάθος κωδικοί σε έναν λογαριασμό ή 30 από την ίδια IP μέσα σε 15 λεπτά */
function login_throttled(string $email = ''): bool
{
    $since = now()->modify('-' . LOGIN_WINDOW_MIN . ' minutes')->format('Y-m-d H:i:s');
    $email = mb_strtolower(trim($email));
    if ($email !== '' && (int)q_val('SELECT COUNT(*) FROM tt_login_attempts WHERE email = ? AND attempted_at > ?', [$email, $since]) >= LOGIN_MAX_ATTEMPTS) return true;
    return (int)q_val('SELECT COUNT(*) FROM tt_login_attempts WHERE ip = ? AND attempted_at > ?', [client_ip(), $since]) >= LOGIN_MAX_ATTEMPTS_IP;
}

function attempt_login(string $email, string $password, bool $remember): bool
{
    $u = q_one('SELECT * FROM tt_users WHERE email = ? AND active = 1', [mb_strtolower(trim($email))]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        q('INSERT INTO tt_login_attempts (ip, email, attempted_at) VALUES (?, ?, ?)', [client_ip(), mb_substr(mb_strtolower(trim($email)), 0, 190), now_sql()]);
        return false;
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE tt_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    // μηδενίζει μόνο τον μετρητή αυτού του λογαριασμού (όχι όλης της IP)
    q('DELETE FROM tt_login_attempts WHERE email = ?', [mb_strtolower(trim($email))]);
    q('DELETE FROM tt_login_attempts WHERE attempted_at < ?', [now()->modify('-1 day')->format('Y-m-d H:i:s')]);
    q('UPDATE tt_users SET last_login_at = ? WHERE id = ?', [now_sql(), $u['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['sv'] = (int)($u['session_ver'] ?? 0);
    unset($_SESSION['csrf']);
    if ($remember) issue_remember_token((int)$u['id']);
    return true;
}

function issue_remember_token(int $uid): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = now()->modify('+' . REMEMBER_DAYS . ' days');
    q('INSERT INTO tt_remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)',
        [$uid, $selector, hash('sha256', $validator), $expires->format('Y-m-d H:i:s')]);
    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires' => $expires->getTimestamp(),
        'path' => app_base_path(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // καθάρισμα ληγμένων
    q('DELETE FROM tt_remember_tokens WHERE expires_at < ?', [now_sql()]);
}

function clear_remember_cookie(): void
{
    setcookie(REMEMBER_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => app_base_path(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function logout(): void
{
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($cookie !== '' && str_contains($cookie, ':')) {
        q('DELETE FROM tt_remember_tokens WHERE selector = ?', [explode(':', $cookie, 2)[0]]);
    }
    clear_remember_cookie();
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Όταν ο admin αλλάζει κωδικό ή απενεργοποιεί χρήστη, κόβουμε τα "να με θυμάσαι" του */
function revoke_remember_tokens(int $uid): void
{
    q('DELETE FROM tt_remember_tokens WHERE user_id = ?', [$uid]);
}

/**
 * Κλείνει όλες τις ανοιχτές συνδέσεις του χρήστη (άλλες συσκευές, "να με θυμάσαι").
 * $keepCurrent: ο ίδιος άλλαξε τον κωδικό του, οπότε αυτή η συσκευή μένει συνδεδεμένη.
 */
function end_user_sessions(int $uid, bool $keepCurrent = false): void
{
    q('UPDATE tt_users SET session_ver = session_ver + 1 WHERE id = ?', [$uid]);
    revoke_remember_tokens($uid);
    if ($keepCurrent && (int)($_SESSION['uid'] ?? 0) === $uid) {
        $_SESSION['sv'] = (int)q_val('SELECT session_ver FROM tt_users WHERE id = ?', [$uid]);
        if (!empty($_COOKIE[REMEMBER_COOKIE])) issue_remember_token($uid);
    }
}
