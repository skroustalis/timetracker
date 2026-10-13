<?php
declare(strict_types=1);

const LANGS = ['el' => 'Ελληνικά', 'en' => 'English'];
const LANG_COOKIE = 'tt_lang';

$__LANG = 'el';
$__DICT = [];

function set_lang(string $lang): void
{
    global $__LANG, $__DICT;
    $__LANG = isset(LANGS[$lang]) ? $lang : 'el';
    $__DICT = $__LANG === 'el' ? [] : (require __DIR__ . '/lang/' . $__LANG . '.php');
}

function lang(): string
{
    global $__LANG;
    return $__LANG;
}

/** Μετάφραση: το κλειδί είναι το ελληνικό κείμενο. Παράμετροι ως {name}. */
function t(string $s, array $p = []): string
{
    global $__DICT;
    $out = $__DICT[$s] ?? $s;
    if ($p) {
        $r = [];
        foreach ($p as $k => $v) $r['{' . $k . '}'] = (string)$v;
        $out = strtr($out, $r);
    }
    return $out;
}

/** Μετάφραση με context, όταν η ίδια ελληνική λέξη έχει άλλη απόδοση (π.χ. «Ομάδα» = Team ή Group) */
function tx(string $ctx, string $s, array $p = []): string
{
    global $__DICT;
    return isset($__DICT[$ctx . '|' . $s]) ? t($ctx . '|' . $s, $p) : t($s, $p);
}

/** Επιλογή γλώσσας: χρήστης > cookie > ρύθμιση */
function detect_lang(?array $user): string
{
    global $CONFIG;
    if ($user && !empty($user['lang']) && isset(LANGS[$user['lang']])) return $user['lang'];
    $c = (string)($_COOKIE[LANG_COOKIE] ?? '');
    if (isset(LANGS[$c])) return $c;
    return (string)($CONFIG['default_lang'] ?? 'el');
}

function day_names_short(): array
{
    return lang() === 'en' ? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] : ['Δευ', 'Τρι', 'Τετ', 'Πέμ', 'Παρ', 'Σάβ', 'Κυρ'];
}

function month_names_short(): array
{
    return lang() === 'en'
        ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
        : ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μαΐ', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];
}

/** [δεκαδικό, χιλιάδες] */
function num_seps(): array
{
    return lang() === 'en' ? ['.', ','] : [',', '.'];
}

/* ---------- Θέμα εμφάνισης ---------- */

const THEMES = ['glass' => 'Liquid glass', 'classic' => 'Κλασικό', 'neo' => 'Neomorphism', 'minimal' => 'Minimalism'];
const THEME_COOKIE = 'tt_theme';

/**
 * Εμφάνιση της σελίδας σύνδεσης (και όσων σελίδων βλέπει κανείς χωρίς σύνδεση), όπως την όρισε ο admin.
 * null = δεν έχει οριστεί: ισχύει ό,τι διάλεξε τελευταία η συσκευή (cookie) ή η προεπιλογή.
 */
function login_look(): ?array
{
    if (!empty($GLOBALS['TT_LOGIN_PREVIEW'])) return $GLOBALS['TT_LOGIN_PREVIEW']; // προεπισκόπηση του admin
    return look_setting('login');
}

/**
 * Εμφάνιση της εφαρμογής (μέσα, μετά τη σύνδεση), όπως την όρισε ο admin. Ισχύει για όσους χρήστες
 * δεν έχουν διαλέξει δική τους εμφάνιση στο προφίλ. null = δεν έχει οριστεί.
 */
function app_look(): ?array
{
    if (!empty($GLOBALS['TT_APP_PREVIEW'])) return $GLOBALS['TT_APP_PREVIEW']; // προεπισκόπηση του admin
    return look_setting('app');
}

/** [θέμα, φόντο] από τις ρυθμίσεις {$prefix}_theme / {$prefix}_bg ή null */
function look_setting(string $prefix): ?array
{
    if (!function_exists('setting')) return null;
    $th = (string)setting($prefix . '_theme', '');
    if (!isset(THEMES[$th])) return null;
    $bg = (string)setting($prefix . '_bg', '');
    $list = theme_backgrounds($th);
    if ($list && !isset($list[$bg])) $bg = theme_default_bg($th);
    return [$th, $bg];
}

/** Κείμενο για μια εμφάνιση [θέμα, φόντο] (Ρυθμίσεις) */
function look_label(?array $look, string $unset): string
{
    if (!$look) return $unset;
    $bgs = theme_backgrounds($look[0]);
    return t(THEMES[$look[0]]) . (isset($bgs[$look[1]]) ? ' · ' . t($bgs[$look[1]][0]) : '');
}

/** Κείμενο για την τρέχουσα εμφάνιση της σελίδας σύνδεσης (Ρυθμίσεις) */
function login_look_label(): string
{
    return look_label(login_look(), t('δεν έχει οριστεί (κάθε συσκευή δείχνει την τελευταία εμφάνιση που χρησιμοποίησε)'));
}

/** Κείμενο για την τρέχουσα εμφάνιση της εφαρμογής (Ρυθμίσεις) */
function app_look_label(): string
{
    return look_label(app_look(), t('δεν έχει οριστεί (ισχύει η αρχική εμφάνιση της εφαρμογής)'));
}

function current_theme(): string
{
    global $CONFIG;
    $u = function_exists('current_user') ? current_user() : null;
    if ($u && !empty($GLOBALS['TT_APP_PREVIEW'])) return $GLOBALS['TT_APP_PREVIEW'][0];
    if ($u && !empty($u['theme']) && isset(THEMES[$u['theme']])) return $u['theme'];
    if ($u && ($al = app_look())) return $al[0];
    if (!$u && ($ll = login_look())) return $ll[0];
    $c = (string)($_COOKIE[THEME_COOKIE] ?? '');
    if (isset(THEMES[$c])) return $c;
    $d = (string)($CONFIG['default_theme'] ?? 'glass');
    return isset(THEMES[$d]) ? $d : 'glass';
}

/** Φόντα για το θέμα glass: key => [ετικέτα, είδος, τόνος (dark: λευκά κείμενα, light: σκούρα κείμενα)] */
const BACKGROUNDS = [
    'streaks-violet' => ['Γραμμές μωβ', 'texture', 'dark'],
    'streaks-ocean' => ['Γραμμές ωκεανός', 'texture', 'dark'],
    'midnight' => ['Μεσάνυχτα', 'smooth', 'dark'],
    'aurora' => ['Σέλας', 'smooth', 'dark'],
    'ocean' => ['Ωκεανός', 'smooth', 'dark'],
    'forest' => ['Δάσος', 'smooth', 'dark'],
    'ember' => ['Χόβολη', 'smooth', 'dark'],
    'graphite' => ['Γραφίτης', 'smooth', 'dark'],
    'peach' => ['Ροδάκινο', 'smooth', 'light'],
    'lavender' => ['Λεβάντα', 'smooth', 'light'],
    'mint' => ['Μέντα', 'smooth', 'light'],
    'sky' => ['Ουρανός', 'smooth', 'light'],
    'sand' => ['Άμμος', 'smooth', 'light'],
    'blossom' => ['Ανθός', 'smooth', 'light'],
    'lemon' => ['Λεμόνι', 'smooth', 'light'],
    'ice' => ['Πάγος', 'smooth', 'light'],
    'streaks-pastel' => ['Γραμμές παστέλ', 'texture', 'light'],
    'streaks-dawn' => ['Γραμμές αυγής', 'texture', 'light'],
];

/** Συνθέσεις για το θέμα Neomorphism: key => [ετικέτα, είδος, τόνος] */
const NEO_SCHEMES = [
    'neo-cloud' => ['Σύννεφο', 'scheme', 'light'],
    'neo-porcelain' => ['Πορσελάνη', 'scheme', 'light'],
    'neo-lavender' => ['Λεβάντα', 'scheme', 'light'],
    'neo-mint' => ['Μέντα', 'scheme', 'light'],
    'neo-peach' => ['Ροδάκινο', 'scheme', 'light'],
    'neo-sky' => ['Ουρανός', 'scheme', 'light'],
    'neo-sand' => ['Άμμος', 'scheme', 'light'],
    'neo-rose' => ['Τριαντάφυλλο', 'scheme', 'light'],
    'neo-graphite' => ['Γραφίτης', 'scheme', 'dark'],
    'neo-night' => ['Νύχτα', 'scheme', 'dark'],
];

/** Συνθέσεις για το θέμα Minimalism: key => [ετικέτα, είδος, τόνος] */
const MIN_SCHEMES = [
    'min-paper' => ['Χαρτί', 'scheme', 'light'],
    'min-cobalt' => ['Κοβάλτιο', 'scheme', 'light'],
    'min-snow' => ['Χιόνι', 'scheme', 'light'],
    'min-linen' => ['Λινό', 'scheme', 'light'],
    'min-sage' => ['Φασκόμηλο', 'scheme', 'light'],
    'min-rose' => ['Ροζ σκόνη', 'scheme', 'light'],
    'min-ochre' => ['Ώχρα', 'scheme', 'light'],
    'min-slate' => ['Ψυχρό γκρι', 'scheme', 'light'],
    'min-ink' => ['Μελάνι', 'scheme', 'dark'],
    'min-navy' => ['Ναυτικό', 'scheme', 'dark'],
];

/** Οι επιλογές φόντου/σύνθεσης κάθε θέματος (το Κλασικό δεν έχει) */
function theme_backgrounds(string $theme): array
{
    return match ($theme) {
        'glass' => BACKGROUNDS,
        'neo' => NEO_SCHEMES,
        'minimal' => MIN_SCHEMES,
        default => [],
    };
}

function theme_default_bg(string $theme): string
{
    return match ($theme) {
        'neo' => 'neo-cloud',
        'minimal' => 'min-paper',
        default => 'streaks-violet',
    };
}

function user_pref(string $col, array $allowed, string $cookie, string $default): string
{
    $u = function_exists('current_user') ? current_user() : null;
    if ($u && !empty($u[$col]) && isset($allowed[$u[$col]])) return $u[$col];
    $c = (string)($_COOKIE[$cookie] ?? '');
    return isset($allowed[$c]) ? $c : $default;
}

/** Το φόντο/σύνθεση του χρήστη για ένα θέμα· αν η αποθηκευμένη τιμή ανήκει σε άλλο θέμα, η προεπιλογή του */
function bg_for_theme(string $theme): string
{
    $list = theme_backgrounds($theme) ?: BACKGROUNDS;
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u && ($ll = login_look()) && $ll[0] === $theme && isset($list[$ll[1]])) return $ll[1];
    // χρήστης χωρίς δική του εμφάνιση (ή προεπισκόπηση του admin): το φόντο της εμφάνισης εφαρμογής
    if ($u && (!empty($GLOBALS['TT_APP_PREVIEW']) || empty($u['theme']) || !isset(THEMES[$u['theme']]))
        && ($al = app_look()) && $al[0] === $theme && isset($list[$al[1]])) return $al[1];
    return user_pref('bg', $list, 'tt_bg', theme_default_bg($theme));
}

function current_bg(): string
{
    return bg_for_theme(current_theme());
}

/** Χρώμα της γραμμής του browser στο κινητό (meta theme-color) */
function theme_meta_color(string $theme, string $tone): string
{
    static $base = [
        'peach' => '#fbe9df',
        'lavender' => '#eeeafb',
        'mint' => '#e4f6ee',
        'sky' => '#e6f1fc',
        'sand' => '#f6f0e5',
        'blossom' => '#fcecf1',
        'lemon' => '#fbf7df',
        'ice' => '#eff4f7',
        'streaks-pastel' => '#efe9fb',
        'streaks-dawn' => '#fdf1e6',
        'neo-cloud' => '#e4e8ee',
        'neo-porcelain' => '#edeef1',
        'neo-lavender' => '#e8e4f4',
        'neo-mint' => '#e0f0ea',
        'neo-peach' => '#f4e6dd',
        'neo-sky' => '#dfeaf5',
        'neo-sand' => '#ede6d9',
        'neo-rose' => '#f3e3e9',
        'neo-graphite' => '#2a2d34',
        'neo-night' => '#1f2433',
        'min-paper' => '#f7f7f6',
        'min-cobalt' => '#ffffff',
        'min-snow' => '#f4f7fb',
        'min-linen' => '#f4f0e8',
        'min-sage' => '#eff3ee',
        'min-rose' => '#f9f1f2',
        'min-ochre' => '#faf6ec',
        'min-slate' => '#eceff2',
        'min-ink' => '#111214',
        'min-navy' => '#0e1628',
    ];
    if ($theme === 'classic') return '#ffffff';
    return $base[current_bg()] ?? ($tone === 'light' ? '#ffffff' : '#1a1240');
}

/** light ή dark: τι χρώμα έχουν τα κείμενα και τα στοιχεία του Bootstrap */
function current_tone(): string
{
    $theme = current_theme();
    if ($theme === 'classic') return 'light';
    $list = theme_backgrounds($theme);
    return $list[current_bg()][2] ?? 'dark';
}

