<?php
declare(strict_types=1);

/** Φωτογραφίες προφίλ: αποθηκεύονται ως τετράγωνο JPG 256x256 στο uploads/avatars */

const AVATAR_SIZE = 256;
const AVATAR_MAX_BYTES = 5 * 1024 * 1024;
const IMAGE_MAX_PIXELS = 25000000; // ~25 megapixel: μεγαλύτερη εικόνα θα γέμιζε τη μνήμη του server στην επεξεργασία

/** Μήνυμα αν η εικόνα είναι πολύ μεγάλη σε διαστάσεις, αλλιώς null */
function image_too_big(array $info): ?string
{
    return ((int)$info[0] * (int)$info[1] > IMAGE_MAX_PIXELS)
        ? t('Η εικόνα είναι πολύ μεγάλη σε διαστάσεις ({w}×{h}). Μίκρυνέ την (π.χ. κάτω από 5000×5000) και δοκίμασε ξανά.', ['w' => (int)$info[0], 'h' => (int)$info[1]])
        : null;
}

function avatar_dir(): string
{
    return APP_ROOT . '/uploads/avatars';
}

function avatar_url(?array $u): ?string
{
    $f = (string)($u['avatar'] ?? '');
    if ($f === '' || !preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f) || !is_file(avatar_dir() . '/' . $f)) return null;
    return 'uploads/avatars/' . $f;
}

function user_initials(string $name): string
{
    return mb_strtoupper(mb_substr($name, 0, 1) . mb_substr((string)(strstr($name, ' ') ?: ''), 1, 1));
}

/** <span class="avatar"> με φωτογραφία ή αρχικά */
function avatar_html(?array $u, string $extra = ''): string
{
    $url = avatar_url($u);
    $cls = 'avatar' . ($extra !== '' ? ' ' . $extra : '');
    if ($url) {
        return '<span class="' . h($cls) . ' has-img"><img src="' . h($url) . '" alt="" loading="lazy"></span>';
    }
    return '<span class="' . h($cls) . '">' . h(user_initials((string)($u['name'] ?? '?'))) . '</span>';
}

/** Επιστρέφει [όνομα αρχείου, null] ή [null, μήνυμα λάθους] */
function save_avatar_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return [null, t('Δεν ανέβηκε αρχείο.')];
    }
    if ((int)$file['size'] > AVATAR_MAX_BYTES) return [null, t('Η φωτογραφία είναι πάνω από 5 MB.')];
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) return [null, t('Επιτρέπονται μόνο εικόνες JPG, PNG, WEBP ή GIF.')];
    if ($big = image_too_big($info)) return [null, $big];

    $dir = avatar_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [null, t('Δεν μπόρεσα να αποθηκεύσω τη φωτογραφία.')];
    $name = bin2hex(random_bytes(12));

    // Με GD: τετράγωνη περικοπή, σμίκρυνση και επανακωδικοποίηση (καθαρίζει ό,τι κρυμμένο έχει το αρχείο)
    if (function_exists('imagecreatetruecolor')) {
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
            IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($file['tmp_name']),
            default => false,
        };
        if ($src) {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $o = (int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
                $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
            }
            $w = imagesx($src); $hgt = imagesy($src); $side = min($w, $hgt);
            $dst = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 30, 30, 50));
            imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($hgt - $side) / 2), AVATAR_SIZE, AVATAR_SIZE, $side, $side);
            $fn = $name . '.jpg';
            $ok = imagejpeg($dst, $dir . '/' . $fn, 86);
            imagedestroy($src); imagedestroy($dst);
            if ($ok) return [$fn, null];
        }
    }
    // Χωρίς GD: κρατάμε το αρχείο όπως είναι, με επέκταση από τον πραγματικό τύπο
    $fn = $name . '.' . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $fn)) return [null, t('Δεν μπόρεσα να αποθηκεύσω τη φωτογραφία.')];
    @chmod($dir . '/' . $fn, 0644);
    return [$fn, null];
}

function delete_avatar_file(?string $fn): void
{
    if ($fn && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $fn)) @unlink(avatar_dir() . '/' . $fn);
}

/* ---------- Λογότυπο εφαρμογής (αλλάζει ο admin από τις Ρυθμίσεις) ---------- */

const LOGO_MAX_BYTES = 3 * 1024 * 1024;
const LOGO_DEFAULT = 'assets/img/logo.png';

function logo_dir(): string
{
    return APP_ROOT . '/uploads/brand';
}

/** Το αρχείο του λογοτύπου που ανέβηκε (ή null για το προεπιλεγμένο) */
function custom_logo_file(): ?string
{
    $f = (string)setting('logo_file', '');
    if ($f === '' || !preg_match('/^[a-f0-9]{24}\.(png|jpg|webp)$/', $f) || !is_file(logo_dir() . '/' . $f)) return null;
    return $f;
}

/** <img> λογοτύπου με σωστές διαστάσεις */
function logo_img(string $class = '', string $alt = ''): string
{
    $f = custom_logo_file();
    $path = $f ? 'uploads/brand/' . $f : LOGO_DEFAULT;
    $size = @getimagesize(APP_ROOT . '/' . $path) ?: [640, 119];
    return '<img' . ($class !== '' ? ' class="' . h($class) . '"' : '') . ' src="' . h($path) . '" alt="' . h($alt !== '' ? $alt : app_name()) . '" width="' . (int)$size[0] . '" height="' . (int)$size[1] . '">';
}

/** Ανέβασμα λογοτύπου: PNG/JPG/WEBP έως 3 MB. Ξανασώζεται ως PNG (κρατά τη διαφάνεια), έως 1280×400. */
function save_logo_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return [null, t('Δεν ανέβηκε αρχείο.')];
    }
    if ((int)$file['size'] > LOGO_MAX_BYTES) return [null, t('Το λογότυπο είναι πάνω από 3 MB.')];
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) return [null, t('Επιτρέπονται μόνο εικόνες PNG, JPG ή WEBP.')];
    if ($big = image_too_big($info)) return [null, $big];
    $dir = logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [null, t('Δεν μπόρεσα να αποθηκεύσω το λογότυπο.')];
    $name = bin2hex(random_bytes(12));

    if (function_exists('imagecreatetruecolor')) {
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
            IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
            default => false,
        };
        if ($src) {
            $w = imagesx($src); $hgt = imagesy($src);
            $scale = min(1, 1280 / $w, 400 / $hgt);
            $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($hgt * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hgt);
            $fn = $name . '.png';
            $ok = imagepng($dst, $dir . '/' . $fn, 7);
            imagedestroy($src); imagedestroy($dst);
            if ($ok) return [$fn, null];
        }
    }
    $fn = $name . '.' . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $fn)) return [null, t('Δεν μπόρεσα να αποθηκεύσω το λογότυπο.')];
    @chmod($dir . '/' . $fn, 0644);
    return [$fn, null];
}

function delete_logo_file(?string $f): void
{
    if ($f && preg_match('/^[a-f0-9]{24}\.(png|jpg|webp)$/', $f)) @unlink(logo_dir() . '/' . $f);
}

/* ---------- Favicon (εικονίδιο καρτέλας) — αλλάζει ο admin από τις Ρυθμίσεις ---------- */

const FAVICON_SIZE = 192;
const FAVICON_DEFAULT_SVG = "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><circle cx='12' cy='12' r='11' fill='%2314213d'/><path d='M12 6v6l4 2' stroke='%23d9bb84' stroke-width='2.4' fill='none' stroke-linecap='round'/></svg>";

function custom_favicon_file(): ?string
{
    $f = (string)setting('favicon_file', '');
    if ($f === '' || !preg_match('/^fav-[a-f0-9]{24}\.(png|jpg|webp)$/', $f) || !is_file(logo_dir() . '/' . $f)) return null;
    return $f;
}

/** href για <link rel="icon"> */
function favicon_href(): string
{
    $f = custom_favicon_file();
    return $f ? 'uploads/brand/' . $f : FAVICON_DEFAULT_SVG;
}

/** Εικονίδιο για τις ειδοποιήσεις στην οθόνη */
function notify_icon_url(): string
{
    $f = custom_favicon_file();
    return $f ? 'uploads/brand/' . $f : 'assets/icon-192.png';
}

/** Ανέβασμα favicon: PNG/JPG/WEBP έως 1 MB, κόβεται σε τετράγωνο 192×192 PNG (κρατά τη διαφάνεια) */
function save_favicon_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return [null, t('Δεν ανέβηκε αρχείο.')];
    }
    if ((int)$file['size'] > 1024 * 1024) return [null, t('Το εικονίδιο είναι πάνω από 1 MB.')];
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) return [null, t('Επιτρέπονται μόνο εικόνες PNG, JPG ή WEBP.')];
    if ($big = image_too_big($info)) return [null, $big];
    $dir = logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [null, t('Δεν μπόρεσα να αποθηκεύσω το εικονίδιο.')];
    $name = 'fav-' . bin2hex(random_bytes(12));
    if (function_exists('imagecreatetruecolor')) {
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
            IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
            default => false,
        };
        if ($src) {
            $w = imagesx($src); $hgt = imagesy($src); $side = min($w, $hgt);
            $dst = imagecreatetruecolor(FAVICON_SIZE, FAVICON_SIZE);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($hgt - $side) / 2), FAVICON_SIZE, FAVICON_SIZE, $side, $side);
            $fn = $name . '.png';
            $ok = imagepng($dst, $dir . '/' . $fn, 7);
            imagedestroy($src); imagedestroy($dst);
            if ($ok) return [$fn, null];
        }
    }
    $fn = $name . '.' . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $fn)) return [null, t('Δεν μπόρεσα να αποθηκεύσω το εικονίδιο.')];
    @chmod($dir . '/' . $fn, 0644);
    return [$fn, null];
}

function delete_favicon_file(?string $f): void
{
    if ($f && preg_match('/^fav-[a-f0-9]{24}\.(png|jpg|webp)$/', $f)) @unlink(logo_dir() . '/' . $f);
}
