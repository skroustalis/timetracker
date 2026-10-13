<?php
declare(strict_types=1);

/**
 * Links ορισμού κωδικού (πρόσκληση νέου χρήστη / νέος κωδικός από admin).
 * Στη βάση κρατάμε μόνο το sha256 του token· το ίδιο το token υπάρχει μόνο στο email.
 * Κάθε link είναι μίας χρήσης και λήγει.
 */

const INVITE_TTL_HOURS = 72;   // πρόσκληση νέου λογαριασμού
const RESET_TTL_HOURS = 24;    // νέος κωδικός για υπάρχοντα χρήστη

/** Φτιάχνει νέο token για τον χρήστη (ακυρώνει τα προηγούμενα) και επιστρέφει το token σε καθαρή μορφή */
function create_password_token(int $uid, string $purpose): string
{
    $purpose = $purpose === 'invite' ? 'invite' : 'reset';
    $hours = $purpose === 'invite' ? INVITE_TTL_HOURS : RESET_TTL_HOURS;
    $token = bin2hex(random_bytes(32));
    q('DELETE FROM tt_password_tokens WHERE user_id = ? OR expires_at < ?', [$uid, now_sql()]);
    q('INSERT INTO tt_password_tokens (user_id, token_hash, purpose, expires_at, created_at) VALUES (?, ?, ?, ?, ?)', [
        $uid, hash('sha256', $token), $purpose, now()->modify("+{$hours} hours")->format('Y-m-d H:i:s'), now_sql(),
    ]);
    return $token;
}

/** Επιστρέφει [token row + στοιχεία χρήστη] αν το token ισχύει, αλλιώς null */
function find_password_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    return q_one('SELECT pt.id AS token_id, pt.purpose, pt.expires_at, u.*
                  FROM tt_password_tokens pt JOIN tt_users u ON u.id = pt.user_id
                  WHERE pt.token_hash = ? AND pt.expires_at > ? AND u.active = 1', [hash('sha256', $token), now_sql()]);
}

/** Ορίζει τον νέο κωδικό, σβήνει όλα τα tokens και τα "να με θυμάσαι" του χρήστη */
function use_password_token(array $row, string $newPassword): void
{
    $uid = (int)$row['id'];
    db()->beginTransaction();
    q('UPDATE tt_users SET password_hash = ? WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), $uid]);
    q('DELETE FROM tt_password_tokens WHERE user_id = ?', [$uid]);
    db()->commit();
    end_user_sessions($uid);
}

/** Κωδικός που δεν ταιριάζει με τίποτα: ο χρήστης μπαίνει μόνο αφού ορίσει δικό του από το link */
function unusable_password_hash(): string
{
    return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
}

/**
 * Στέλνει το email με το link ορισμού κωδικού, στη γλώσσα του παραλήπτη.
 * Επιστρέφει true αν ο mail server το δέχτηκε.
 */
function send_password_link(array $u, string $purpose, ?array $byAdmin = null): bool
{
    global $CONFIG;
    $token = create_password_token((int)$u['id'], $purpose);
    $link = app_url('set_password.php?t=' . $token);
    $hours = $purpose === 'invite' ? INVITE_TTL_HOURS : RESET_TTL_HOURS;

    $curLang = lang();
    set_lang(($u['lang'] ?? '') ?: ($CONFIG['default_lang'] ?? 'el'));
    if ($purpose === 'invite') {
        $subject = t('Ο λογαριασμός σου στο {app}', ['app' => app_name()]);
        $intro = t('Σου φτιάχτηκε λογαριασμός στο {app}{by}.', [
            'app' => app_name(),
            'by' => $byAdmin ? ' ' . t('από τον/την {n}', ['n' => $byAdmin['name']]) : '',
        ]);
    } else {
        $subject = t('Νέος κωδικός για το {app}', ['app' => app_name()]);
        $intro = t('Ένας διαχειριστής ζήτησε να ορίσεις νέο κωδικό για το {app}.', ['app' => app_name()]);
    }
    $body = t('Γεια σου {n},', ['n' => $u['name']]) . "\n\n"
        . $intro . "\n\n"
        . t('Email σύνδεσης: {e}', ['e' => $u['email']]) . "\n\n"
        . t('Για να ορίσεις τον κωδικό σου, άνοιξε αυτό το link:') . "\n"
        . $link . "\n\n"
        . t('Το link ισχύει {h} ώρες και μόνο για μία χρήση.', ['h' => $hours]) . "\n"
        . t('Μετά θα συνδέεσαι εδώ:') . ' ' . app_url('login.php') . "\n\n"
        . t('Αν δεν περίμενες αυτό το email, αγνόησέ το.') . "\n\n— " . app_name();
    $html = mail_html([
        'preheader' => $intro,
        'title' => $purpose === 'invite' ? t('Καλώς ήρθες στο {app}', ['app' => app_name()]) : t('Ορισμός νέου κωδικού'),
        'greeting' => t('Γεια σου {n},', ['n' => $u['name']]),
        'paras' => [$intro, t('Πάτα το κουμπί για να ορίσεις τον κωδικό σου.')],
        'block' => mail_facts([[t('Email σύνδεσης'), (string)$u['email']]]),
        'button' => [t('Ορισμός κωδικού'), $link],
        'after' => [
            t('Το link ισχύει {h} ώρες και μόνο για μία χρήση. Μετά θα συνδέεσαι από τη σελίδα σύνδεσης της εφαρμογής.', ['h' => $hours]),
            t('Αν δεν περίμενες αυτό το email, αγνόησέ το.'),
        ],
        'link' => $link,
    ]);
    set_lang($curLang);

    return send_mail((string)$u['email'], (string)$u['name'], $subject, $body, $html);
}
