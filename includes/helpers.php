<?php
declare(strict_types=1);

/** HTML escape */
function h(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function now_sql(): string
{
    return now()->format('Y-m-d H:i:s');
}

/** 3725 -> "01:02:05" */
function fmt_dur(int|float|null $sec): string
{
    $sec = max(0, (int)round((float)$sec));
    return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
}

/** 3725 -> "1,03" (δεκαδικές ώρες) */
function fmt_hours(int|float|null $sec, int $dec = 2): string
{
    [$d, $t] = num_seps();
    return number_format(((float)$sec) / 3600, $dec, $d, $t);
}

/** 3725 -> "01:02" (ώρες:λεπτά, στρογγυλεμένα στο λεπτό) */
function fmt_hm(int|float|null $sec): string
{
    $m = (int)round(max(0, (float)$sec) / 60);
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** Όριο ωρών: "5" / "5:30" / "05:00" / "5,5" -> ώρες (5.5). null αν είναι κενό, λάθος ή μηδέν. */
function parse_limit_hours(string $v): ?float
{
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^(\d{1,5}):([0-5]?\d)$/', $v, $m)) {
        $h = (int)$m[1] + (int)$m[2] / 60;
    } else {
        $v = str_replace(',', '.', $v);
        if (!is_numeric($v)) return null;
        $h = (float)$v;
    }
    return $h > 0 ? round($h, 4) : null;
}

function fmt_money(float $v): string
{
    [$d, $t] = num_seps();
    return lang() === 'en' ? '€' . number_format($v, 2, $d, $t) : number_format($v, 2, $d, $t) . ' €';
}

function fmt_pct(float $v): string
{
    [$d, $t] = num_seps();
    return number_format($v, 1, $d, $t) . '%';
}

/** "Τρι, 6 Οκτ" / "Tue, 6 Oct" */
function fmt_day(DateTimeInterface $d, bool $withYear = false): string
{
    $s = day_names_short()[(int)$d->format('N') - 1] . ', ' . $d->format('j') . ' ' . month_names_short()[(int)$d->format('n') - 1];
    return $withYear ? $s . ' ' . $d->format('Y') : $s;
}

/** "6 Οκτ" */
function fmt_short(DateTimeInterface $d): string
{
    return $d->format('j') . ' ' . month_names_short()[(int)$d->format('n') - 1];
}

function fmt_date(?string $sql): string
{
    if (!$sql) return '';
    return (new DateTimeImmutable($sql))->format('d/m/Y');
}

function fmt_time(?string $sql): string
{
    if (!$sql) return '';
    return (new DateTimeImmutable($sql))->format('H:i');
}

function week_start(DateTimeImmutable $d): DateTimeImmutable
{
    return $d->setTime(0, 0)->modify('-' . ((int)$d->format('N') - 1) . ' days');
}

/** Επιστρέφει [from, to] (inclusive ημερομηνίες Y-m-d) για preset */
function date_preset(string $preset): ?array
{
    $today = now()->setTime(0, 0);
    $ws = week_start($today);
    return match ($preset) {
        'today' => [$today, $today],
        'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
        'week' => [$ws, $ws->modify('+6 days')],
        'lastweek' => [$ws->modify('-7 days'), $ws->modify('-1 day')],
        'month' => [$today->modify('first day of this month'), $today->modify('last day of this month')],
        'lastmonth' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
        'year' => [$today->setDate((int)$today->format('Y'), 1, 1), $today->setDate((int)$today->format('Y'), 12, 31)],
        'lastyear' => [$today->setDate((int)$today->format('Y') - 1, 1, 1), $today->setDate((int)$today->format('Y') - 1, 12, 31)],
        default => null,
    };
}

const DATE_PRESETS = [
    'today' => 'Σήμερα',
    'yesterday' => 'Χθες',
    'week' => 'Αυτή την εβδομάδα',
    'lastweek' => 'Προηγούμενη εβδομάδα',
    'month' => 'Αυτόν τον μήνα',
    'lastmonth' => 'Προηγούμενο μήνα',
    'year' => 'Φέτος',
    'lastyear' => 'Πέρσι',
    'custom' => 'Προσαρμογή',
];

const RANGE_MAX_DAYS = 1827; // 5 χρόνια: όριο για να μη γονατίσει ο server

/** Διαβάζει from/to/preset από GET και επιστρέφει [from, to, preset, κόπηκε η περίοδος (bool)] */
function read_range(string $defaultPreset = 'week'): array
{
    $preset = (string)($_GET['preset'] ?? '');
    $from = parse_date((string)($_GET['from'] ?? ''));
    $to = parse_date((string)($_GET['to'] ?? ''));
    if ($preset !== '' && $preset !== 'custom' && ($r = date_preset($preset))) {
        return [$r[0], $r[1], $preset];
    }
    if ($from && $to) {
        if ($to < $from) [$from, $to] = [$to, $from];
        $clamped = false;
        if ($from->diff($to)->days > RANGE_MAX_DAYS) { $from = $to->modify('-' . RANGE_MAX_DAYS . ' days'); $clamped = true; }
        return [$from, $to, 'custom', $clamped];
    }
    $r = date_preset($defaultPreset);
    return [$r[0], $r[1], $defaultPreset];
}

function parse_date(string $s): ?DateTimeImmutable
{
    $s = trim($s);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        // ημερομηνία που δεν υπάρχει (π.χ. 31/02) απορρίπτεται, δεν «γλιστράει» στον επόμενο μήνα
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d ?: null;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])) ?: null;
        }
    }
    return null;
}

/** "9", "930", "9:30", "09.30" -> [9,30] */
function parse_hm(string $s): ?array
{
    $s = trim(str_replace(['.', ','], ':', $s));
    if ($s === '') return null;
    if (preg_match('/^(\d{1,2})$/', $s, $m)) {
        $h = (int)$m[1]; $i = 0;
    } elseif (preg_match('/^(\d{1,2})(\d{2})$/', $s, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2];
    } elseif (preg_match('/^(\d{1,2}):(\d{1,2})(?::\d{1,2})?$/', $s, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2];
    } else {
        return null;
    }
    if ($h > 23 || $i > 59) return null;
    return [$h, $i];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Επιστρέφει ασφαλές τοπικό URL επιστροφής (μόνο αρχεία της εφαρμογής) */
function safe_return(?string $url, string $fallback = 'tracker.php'): string
{
    $url = (string)$url;
    if ($url !== '' && preg_match('#^[a-z_]+\.php(\?[^\r\n]*)?$#i', $url)) {
        return $url;
    }
    return $fallback;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($t === '' || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit(function_exists('t') ? t('Η φόρμα έληξε. Γύρισε πίσω, ανανέωσε τη σελίδα και ξαναδοκίμασε.') : 'Session expired. Go back, reload and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post_str(string $k, int $max = 500): string
{
    $v = trim((string)($_POST[$k] ?? ''));
    return mb_substr($v, 0, $max);
}

function post_int(string $k): ?int
{
    $v = $_POST[$k] ?? '';
    return ($v === '' || !is_numeric($v)) ? null : (int)$v;
}

function post_dec(string $k): ?float
{
    $v = str_replace(',', '.', trim((string)($_POST[$k] ?? '')));
    return ($v === '' || !is_numeric($v)) ? null : max(0, (float)$v);
}

function post_ids(string $k): array
{
    $v = $_POST[$k] ?? [];
    if (!is_array($v)) $v = [$v];
    return array_values(array_unique(array_filter(array_map('intval', $v), fn($x) => $x > 0)));
}

function get_int(string $k): ?int
{
    $v = $_GET[$k] ?? '';
    return ($v === '' || !is_numeric($v)) ? null : (int)$v;
}

function get_ids(string $k): array
{
    $v = $_GET[$k] ?? [];
    if (!is_array($v)) $v = $v === '' ? [] : explode(',', (string)$v);
    return array_values(array_unique(array_filter(array_map('intval', $v), fn($x) => $x > 0)));
}

function valid_color(string $c, string $fallback = '#03a9f4'): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $fallback;
}

const PALETTE = ['#03a9f4', '#e91e63', '#8bc34a', '#ff9800', '#9c27b0', '#009688', '#f44336', '#3f51b5', '#cddc39', '#795548', '#607d8b', '#00bcd4', '#ffc107', '#673ab7', '#4caf50'];

function qs(array $overrides = []): string
{
    $q = array_merge($_GET, $overrides);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '' && $v !== []);
    return http_build_query($q);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
