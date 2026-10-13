<?php
declare(strict_types=1);

/**
 * Αποστολή email.
 * Αν υπάρχει $CONFIG['smtp'] στέλνει μέσω SMTP (SSL στη 465 ή STARTTLS στη 587),
 * αλλιώς πέφτει στο mail() της PHP. Απλό κείμενο UTF-8, και προαιρετικά HTML έκδοση (multipart/alternative).
 *
 * Παράδειγμα στο includes/config.php:
 *   'app_url'   => 'https://timetracker.example.com/',
 *   'mail_from' => 'noreply@example.com',
 *   'smtp' => [
 *       'host'   => 'mail.example.com',
 *       'port'   => 465,
 *       'secure' => 'ssl',        // 'ssl' (465) ή 'tls' (587)
 *       'user'   => 'noreply@example.com',
 *       'pass'   => '...',
 *   ],
 */

const SMTP_TIMEOUT = 15;

/** Απόλυτο URL της εφαρμογής, π.χ. https://timetracker.example.com/ — από το config, όχι από το Host header */
function app_url(string $path = ''): string
{
    global $CONFIG;
    $base = trim((string)($CONFIG['app_url'] ?? ''));
    if ($base === '') {
        // Εφεδρικά: μόνο αν δεν έχει οριστεί app_url (δεν εμπιστευόμαστε τυφλά το Host header)
        $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $base = (is_https() ? 'https://' : 'http://') . $host . app_base_path();
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function mail_from_address(): string
{
    global $CONFIG;
    $from = (string)($CONFIG['mail_from'] ?? '');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $host = (string)parse_url(app_url(), PHP_URL_HOST);
        $from = 'no-reply@' . preg_replace('/^www\./', '', $host ?: 'localhost');
    }
    return $from;
}

/** "=?UTF-8?B?...?=" μόνο όταν χρειάζεται */
function mail_encode_header(string $s): string
{
    $s = str_replace(["\r", "\n"], ' ', $s);
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/**
 * Στέλνει email. Το $body είναι πάντα το απλό κείμενο· αν δοθεί $html, φεύγει και σχεδιασμένη έκδοση
 * (ο παραλήπτης βλέπει την HTML, και όσα προγράμματα δεν τη δείχνουν βλέπουν το κείμενο).
 * Επιστρέφει true αν ο server το δέχτηκε. Τα λάθη γράφονται στο error_log, ποτέ στην οθόνη.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $body, ?string $html = null): bool
{
    global $CONFIG;
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $from = mail_from_address();
    $fromHeader = mail_encode_header(app_name()) . " <{$from}>";
    $toHeader = $toName !== '' ? mail_encode_header($toName) . " <{$toEmail}>" : $toEmail;
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $fromHeader,
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
    ];
    $b64 = fn(string $s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
    if ($html === null) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $encodedBody = $b64($body);
    } else {
        $boundary = '=_tt_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $encodedBody = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($body) . "\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n"
            . "--{$boundary}--";
    }

    $smtp = $CONFIG['smtp'] ?? null;
    if (is_array($smtp) && !empty($smtp['host'])) {
        $msg = implode("\r\n", array_merge($headers, ['To: ' . $toHeader, 'Subject: ' . mail_encode_header($subject)]))
            . "\r\n\r\n" . $encodedBody . "\r\n";
        try {
            smtp_send($smtp, $from, $toEmail, $msg);
            return true;
        } catch (Throwable $e) {
            error_log('smtp: ' . $e->getMessage());
            return false;
        }
    }

    // Χωρίς SMTP: mail() της PHP (To/Subject τα βάζει η ίδια η mail())
    return @mail($toHeader, mail_encode_header($subject), $encodedBody, implode("\r\n", $headers), '-f' . $from);
}

/** Ελάχιστος SMTP client: EHLO, STARTTLS/SSL, AUTH LOGIN, MAIL, RCPT, DATA. Πετάει exception σε λάθος. */
function smtp_send(array $cfg, string $from, string $to, string $message): void
{
    $host = (string)$cfg['host'];
    $port = (int)($cfg['port'] ?? 587);
    $secure = strtolower((string)($cfg['secure'] ?? ($port === 465 ? 'ssl' : 'tls')));
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'allow_self_signed' => false,
    ]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, SMTP_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $why = $errstr !== '' ? "{$errstr} ({$errno})" : 'πιθανώς το πιστοποιητικό TLS δεν ταιριάζει με το host — δοκίμασε το hostname του server';
        throw new RuntimeException("σύνδεση στο {$remote} απέτυχε: {$why}");
    }
    stream_set_timeout($fp, SMTP_TIMEOUT);

    $read = function () use ($fp): array {
        $lines = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $lines .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break; // τελευταία γραμμή απάντησης: "250 ..."
        }
        if ($lines === '') throw new RuntimeException('ο server δεν απάντησε');
        return [(int)substr($lines, 0, 3), trim($lines)];
    };
    $cmd = function (string $line, array $okCodes, bool $secret = false) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException(($secret ? '[AUTH]' : strtok($line, ' ')) . ' -> ' . $text);
        }
        return $text;
    };

    try {
        [$code, $text] = $read();
        if ($code !== 220) throw new RuntimeException('greeting -> ' . $text);
        $ehloName = preg_replace('/[^a-z0-9.\-]/i', '', (string)parse_url(app_url(), PHP_URL_HOST)) ?: 'localhost';
        $ehlo = $cmd('EHLO ' . $ehloName, [250]);

        if ($secure === 'tls') {
            if (!preg_match('/STARTTLS/i', $ehlo)) throw new RuntimeException('ο server δεν υποστηρίζει STARTTLS');
            $cmd('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!stream_socket_enable_crypto($fp, true, $method)) throw new RuntimeException('αποτυχία TLS');
            $cmd('EHLO ' . $ehloName, [250]);
        }

        if (!empty($cfg['user'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string)$cfg['user']), [334], true);
            $cmd(base64_encode((string)($cfg['pass'] ?? '')), [235], true);
        }

        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        // dot-stuffing: γραμμές που ξεκινούν με "." διπλασιάζουν την τελεία
        $data = preg_replace('/^\./m', '..', $message);
        $cmd(rtrim($data, "\r\n") . "\r\n.", [250]);
        try { $cmd('QUIT', [221]); } catch (Throwable $e) { /* δεν πειράζει */ }
    } finally {
        fclose($fp);
    }
}

/* ---------- Σχέδιο των email (HTML) ----------
 * Πίνακες και inline styles, ώστε να φαίνεται σωστά σε Gmail, Outlook και κινητά.
 * Χρώματα της εφαρμογής: navy κεφαλίδα με το λογότυπο, χρυσή λεπτομέρεια, ένα κουμπί.
 */
const MAIL_NAVY = '#14213d';
const MAIL_GOLD = '#c9a96e';
const MAIL_INK = '#1f2937';
const MAIL_DIM = '#6b7280';
const MAIL_LINE = '#e5e7eb';
const MAIL_FONT = "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

/** Απόλυτο URL του λογότυπου για τα email (το ανεβασμένο, αλλιώς το προεπιλεγμένο) */
function mail_logo_url(): string
{
    $f = function_exists('custom_logo_file') ? custom_logo_file() : null;
    return app_url($f ? 'uploads/brand/' . $f : 'assets/img/logo.png');
}

/** Κουμπί που δουλεύει και στο Outlook (πίνακας με χρώμα στο κελί) */
function mail_button(string $label, string $url): string
{
    return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 4px"><tr>'
        . '<td bgcolor="' . MAIL_NAVY . '" style="border-radius:8px;background:' . MAIL_NAVY . '">'
        . '<a href="' . h($url) . '" target="_blank" style="display:inline-block;padding:13px 26px;font-family:' . MAIL_FONT . ';font-size:15px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;border-radius:8px">' . h($label) . '</a>'
        . '</td></tr></table>';
}

/** Κουτί με ζεύγη ετικέτα/τιμή (π.χ. email σύνδεσης) */
function mail_facts(array $rows): string
{
    $html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:6px 0 18px;background:#f6f7fb;border:1px solid ' . MAIL_LINE . ';border-radius:8px">';
    foreach ($rows as [$label, $value]) {
        $html .= '<tr><td style="padding:12px 16px;font-family:' . MAIL_FONT . ';font-size:13px;color:' . MAIL_DIM . ';white-space:nowrap;width:1%">' . h($label) . '</td>'
            . '<td style="padding:12px 16px 12px 0;font-family:' . MAIL_FONT . ';font-size:14px;font-weight:600;color:' . MAIL_INK . ';word-break:break-all">' . h($value) . '</td></tr>';
    }
    return $html . '</table>';
}

/**
 * Ολόκληρο email. $o: preheader, title, greeting, paras[] (απλό κείμενο), block (έτοιμο HTML), button [label, url],
 * after[] (μικρό κείμενο κάτω από το κουμπί), link (για όποιον δεν μπορεί να πατήσει το κουμπί)
 */
function mail_html(array $o): string
{
    $p = fn(string $t, string $style = '') => '<p style="margin:0 0 14px;font-family:' . MAIL_FONT . ';font-size:15px;line-height:1.6;color:' . MAIL_INK . ';' . $style . '">' . nl2br(h($t)) . '</p>';
    $body = '';
    if (!empty($o['title'])) $body .= '<h1 style="margin:0 0 16px;font-family:' . MAIL_FONT . ';font-size:22px;line-height:1.3;font-weight:700;color:' . MAIL_INK . '">' . h($o['title']) . '</h1>';
    if (!empty($o['greeting'])) $body .= $p($o['greeting']);
    foreach ($o['paras'] ?? [] as $t) $body .= $p($t);
    $body .= $o['block'] ?? '';
    if (!empty($o['button'])) $body .= mail_button($o['button'][0], $o['button'][1]);
    foreach ($o['after'] ?? [] as $t) $body .= $p($t, 'font-size:13px;color:' . MAIL_DIM . ';margin:12px 0 0');
    if (!empty($o['link'])) {
        $body .= '<p style="margin:18px 0 0;font-family:' . MAIL_FONT . ';font-size:12px;line-height:1.5;color:' . MAIL_DIM . '">'
            . h(t('Αν το κουμπί δεν ανοίγει, αντέγραψε αυτό το link στον browser:')) . '<br>'
            . '<a href="' . h($o['link']) . '" style="color:' . MAIL_NAVY . ';word-break:break-all">' . h($o['link']) . '</a></p>';
    }
    $pre = h((string)($o['preheader'] ?? ''));
    return '<!doctype html><html lang="' . h(lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . h(app_name()) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef0f5">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $pre . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#eef0f5" style="background:#eef0f5"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px">'
        // κεφαλίδα
        . '<tr><td bgcolor="' . MAIL_NAVY . '" style="background:' . MAIL_NAVY . ';border-radius:12px 12px 0 0;padding:22px 32px">'
        . '<img src="' . h(mail_logo_url()) . '" width="170" alt="' . h(app_name()) . '" style="display:block;width:170px;max-width:60%;height:auto;border:0;color:#ffffff;font-family:' . MAIL_FONT . ';font-size:18px;font-weight:600">'
        . '</td></tr>'
        . '<tr><td bgcolor="' . MAIL_GOLD . '" style="background:' . MAIL_GOLD . ';height:3px;line-height:3px;font-size:0">&nbsp;</td></tr>'
        // σώμα
        . '<tr><td bgcolor="#ffffff" style="background:#ffffff;padding:32px 32px 28px;border-radius:0 0 12px 12px">' . $body . '</td></tr>'
        // υποσέλιδο
        . '<tr><td align="center" style="padding:18px 24px 0;font-family:' . MAIL_FONT . ';font-size:12px;line-height:1.5;color:' . MAIL_DIM . '">'
        . h(app_name()) . ' · <a href="' . h(app_url('login.php')) . '" style="color:' . MAIL_DIM . '">' . h(preg_replace('#^https?://#', '', rtrim(app_url(), '/'))) . '</a><br>'
        . h(t('Αυτόματο μήνυμα, μην απαντάς σε αυτό το email.'))
        . '</td></tr></table></td></tr></table></body></html>';
}

