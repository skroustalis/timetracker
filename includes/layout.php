<?php
declare(strict_types=1);

/** Bootstrap Icons */
function icon(string $name, string $extra = ''): string
{
    $map = [
        'clock' => 'clock', 'calendar' => 'calendar3', 'dashboard' => 'grid-1x2', 'chart' => 'bar-chart-line',
        'folder' => 'folder2', 'briefcase' => 'briefcase', 'tag' => 'tag', 'users' => 'people',
        'play' => 'play-fill', 'stop' => 'stop-fill', 'trash' => 'trash3', 'edit' => 'pencil', 'copy' => 'copy',
        'euro' => 'currency-euro', 'list' => 'list-ul', 'logout' => 'box-arrow-right', 'user' => 'person-circle',
        'plus' => 'plus-lg', 'download' => 'download', 'print' => 'printer', 'chev-l' => 'chevron-left',
        'chev-r' => 'chevron-right', 'menu' => 'list', 'dots' => 'three-dots-vertical', 'x' => 'x-lg',
        'globe' => 'translate', 'stopwatch' => 'stopwatch', 'check' => 'check2', 'key' => 'key', 'search' => 'search',
    ];
    return '<i class="bi bi-' . ($map[$name] ?? $name) . ($extra ? ' ' . $extra : '') . '" aria-hidden="true"></i>';
}

function current_url_rel(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? 'tracker.php') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
}

function render_header(string $title, string $active = ''): void
{
    $u = current_user();
    $running = $u ? running_entry((int)$u['id']) : null;
    $nav = [
        ['tracker', 'tracker.php', 'Χρονομέτρηση', 'clock', false],
        ['calendar', 'calendar.php', 'Ημερολόγιο', 'calendar', false],
        ['_h', '', 'Ανάλυση', '', false],
        ['dashboard', 'dashboard.php', 'Dashboard', 'dashboard', false],
        ['reports', 'reports.php', 'Αναφορές', 'chart', false],
        ['_h', '', 'Διαχείριση', '', false],
        ['projects', 'projects.php', 'Projects', 'folder', false],
        ['tags', 'tags.php', 'Tags', 'tag', false],
        ['team', 'team.php', 'Ομάδα', 'users', 'lead'],
        ['settings', 'settings.php', 'Ρυθμίσεις', 'gear', 'lead'],
    ];
    $theme = current_theme();
    $tone = current_tone();
    ?><!doctype html>
<html lang="<?= h(lang()) ?>" data-theme="<?= h($theme) ?>" data-bg="<?= h(current_bg()) ?>" data-tone="<?= h($tone) ?>" data-bs-theme="<?= h($tone) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title) ?> · <?= h(app_name()) ?></title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css?v=5.3.8">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css?v=1.13.1">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
<meta name="theme-color" content="<?= h(theme_meta_color($theme, $tone)) ?>">
<link rel="icon" data-favicon href="<?= h(favicon_href()) ?>">
<link rel="apple-touch-icon" href="<?= h(custom_favicon_file() ? favicon_href() : 'assets/icon-192.png') ?>">
</head>
<body class="<?= $u ? 'has-app' : 'no-app' ?>" data-server-now="<?= time() ?>" data-autostop="<?= auto_stop_hours() ?>"<?php if ($running): ?> data-running-label="<?= h($running['description'] !== '' ? $running['description'] : ($running['project_name'] ?? t('Χωρίς περιγραφή'))) ?>"<?php endif; ?>>
<?php if ($u): ?>
<nav class="navbar app-topbar fixed-top">
  <div class="container-fluid gap-2 flex-nowrap">
    <button class="btn btn-link text-body d-lg-none px-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Menu"><?= icon('menu', 'fs-3') ?></button>
    <a class="navbar-brand d-flex align-items-center gap-3 me-auto py-0" href="tracker.php" title="<?= h(app_name()) ?>">
      <?= logo_img('topbar-logo') ?>
      <?php
      // ο admin βλέπει μία ομάδα τη φορά (αλλάζει από την πάνω μπάρα)· οι υπόλοιποι την ομάδα τους
      $deptId = current_dept($u);
      $myGroups = is_admin($u) ? ($deptId ? q_all('SELECT id, name, color FROM tt_groups WHERE id = ?', [$deptId]) : []) : user_groups((int)$u['id']);
      ?>
      <span class="brand-sep d-none d-md-inline"></span>
      <span class="brand-name d-none d-md-inline-flex align-items-center gap-2">
        <?php if ($myGroups): foreach ($myGroups as $i => $g): ?>
          <?php if ($i): ?><span class="brand-dot-sep">·</span><?php endif; ?>
          <span class="brand-group"><span class="dot" style="background:<?= h($g['color'] ?: '#9fb3c8') ?>"></span><?= h($g['name']) ?></span>
        <?php endforeach; else: ?>
          <?= h(app_name()) ?>
        <?php endif; ?>
      </span>
    </a>
    <?php if ($running && $active !== 'tracker'): ?>
      <form method="post" action="entry_action.php" class="running-pill d-flex align-items-center gap-2 rounded-pill ps-3 pe-1 py-1">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="stop_simple">
        <input type="hidden" name="return" value="<?= h(current_url_rel()) ?>">
        <a href="tracker.php" class="text-body text-decoration-none text-truncate d-none d-md-inline running-pill-label" title="<?= h($running['description']) ?>">
          <span class="dot" style="background:<?= h($running['project_color'] ?? '#adb5bd') ?>"></span><?= h($running['description'] !== '' ? $running['description'] : ($running['project_name'] ?? t('Χωρίς περιγραφή'))) ?>
        </a>
        <span class="font-monospace fw-semibold" data-since="<?= (new DateTimeImmutable($running['start_at']))->getTimestamp() ?>">00:00:00</span>
        <button class="btn btn-danger btn-sm rounded-pill" type="submit" title="<?= h(t('Διακοπή')) ?>"><?= icon('stop') ?></button>
      </form>
    <?php endif; ?>
    <?php if (is_admin($u) && count($deptList = all_groups()) > 1): ?>
      <div class="dropdown dept-switch">
        <button class="btn btn-sm dept-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= h(t('Αλλαγή ομάδας')) ?>">
          <?php foreach ($deptList as $dg) if ((int)$dg['id'] === $deptId): ?><span class="dot" style="background:<?= h($dg['color'] ?: '#9fb3c8') ?>"></span><span class="dept-name"><?= h($dg['name']) ?></span><?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><h6 class="dropdown-header"><?= h(t('Ομάδα που βλέπεις')) ?></h6></li>
          <?php foreach ($deptList as $dg): ?>
            <li><a class="dropdown-item d-flex align-items-center gap-2<?= (int)$dg['id'] === $deptId ? ' active' : '' ?>" href="dept.php?g=<?= (int)$dg['id'] ?>&amp;return=<?= h(urlencode(current_url_rel())) ?>"><span class="dot" style="background:<?= h($dg['color'] ?: '#9fb3c8') ?>"></span><?= h($dg['name']) ?><small class="ms-auto ps-3 opacity-75"><?= (int)$dg['members'] ?></small></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php $nUnread = unread_notifications((int)$u['id']); $nList = latest_notifications((int)$u['id']); ?>
    <div class="dropdown notif<?= $nUnread ? ' has-unread' : '' ?>" data-notif data-notif-user="<?= (int)$u['id'] ?>" data-notif-last="<?= last_notification_id((int)$u['id']) ?>">
      <button class="btn btn-icon notif-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="<?= h(t('Ειδοποιήσεις')) ?>" title="<?= h(t('Ειδοποιήσεις')) ?>">
        <?= icon('bell', 'fs-5') ?><span class="notif-count" data-notif-count><?= $nUnread ? ($nUnread > 9 ? '9+' : $nUnread) : '' ?></span>
      </button>
      <div class="dropdown-menu dropdown-menu-end notif-menu">
        <div class="notif-head"><span><?= h(t('Ειδοποιήσεις')) ?></span><span class="notif-head-r"><?php if ($nUnread): ?><span class="notif-new" data-notif-new><?= h(t('{n} νέες', ['n' => $nUnread])) ?></span><?php endif; ?><?php if ($nList): ?><button type="button" class="notif-clear" data-notif-clear title="<?= h(t('Διαγραφή όλων των ειδοποιήσεων')) ?>"><?= h(t('Καθαρισμός')) ?></button><?php endif; ?></span></div>
        <div class="notif-empty" data-notif-empty<?= $nList ? ' hidden' : '' ?>><?= icon('bell') ?><div><?= h(t('Δεν έχεις ειδοποιήσεις.')) ?></div></div>
        <?php if ($nList): ?>
          <div class="notif-list">
            <?php foreach ($nList as $n): [$ic, $tone, $text] = notification_view($n); ?>
              <a class="notif-item<?= $n['read_at'] ? '' : ' unread' ?>" href="notifications.php?open=<?= (int)$n['id'] ?>">
                <span class="notif-ico tone-<?= h($tone) ?>"><?= icon($ic) ?></span>
                <span class="notif-body"><span class="notif-text"><?= h($text) ?></span><span class="notif-time"><?= h(notification_when($n['created_at'])) ?></span></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="notif-push" data-push hidden>
          <span class="notif-push-text"><?= icon('display') ?><span data-push-msg><?= h(t('Ειδοποιήσεις και στην οθόνη του υπολογιστή')) ?></span></span>
          <button type="button" class="btn btn-sm btn-primary" data-push-enable><?= h(t('Ενεργοποίηση')) ?></button>
        </div>
      </div>
    </div>
    <div class="dropdown">
      <button class="btn btn-link text-body p-0 border-0 d-flex align-items-center gap-2 text-decoration-none topbar-user" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('Λογαριασμός')) ?>">
        <span class="topbar-user-name d-none d-sm-inline text-truncate"><?= h($u['name']) ?></span>
        <?= avatar_html($u) ?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow">
        <li class="menu-profile d-flex align-items-center gap-3">
          <?= avatar_html($u, 'avatar-lg') ?>
          <div>
          <div class="fw-semibold"><?= h($u['name']) ?></div>
          <div class="small text-body-secondary"><?= h($u['email']) ?></div>
          <span class="badge <?= ['admin' => 'text-bg-primary', 'manager' => 'text-bg-info'][$u['role']] ?? 'text-bg-secondary' ?> mt-1"><?= h(role_label($u['role'])) ?></span>
          </div>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="profile.php"><?= icon('user') ?><?= h(t('Το προφίλ μου')) ?></a></li>
        <li><a class="dropdown-item" href="profile.php#appearance"><?= icon('palette') ?><?= h(t('Εμφάνιση εφαρμογής')) ?></a></li>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header"><?= h(t('Γλώσσα')) ?></h6></li>
        <?php foreach (LANGS as $code => $label): ?>
          <li>
            <form method="post" action="lang.php">
              <?= csrf_field() ?><input type="hidden" name="lang" value="<?= h($code) ?>"><input type="hidden" name="return" value="<?= h(current_url_rel()) ?>">
              <button class="dropdown-item<?= lang() === $code ? ' active is-current' : '' ?>" type="submit"><span class="bi" aria-hidden="true"></span><?= h($label) ?></button>
            </form>
          </li>
        <?php endforeach; ?>
        <li><hr class="dropdown-divider"></li>
        <li>
          <form method="post" action="logout.php"><?= csrf_field() ?><button class="dropdown-item item-danger" type="submit"><?= icon('logout') ?><?= h(t('Αποσύνδεση')) ?></button></form>
        </li>
      </ul>
    </div>
  </div>
</nav>

<aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-label="Menu">
  <div class="offcanvas-header d-lg-none">
    <span></span>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0 d-flex flex-column">
    <a href="tracker.php" class="sidebar-logo d-lg-none" title="<?= h(app_name()) ?>">
      <?= logo_img() ?>
      <span><?= h(app_name()) ?></span>
    </a>
    <nav class="nav flex-column w-100 py-2">
      <?php foreach ($nav as [$key, $href, $label, $ic, $adminOnly]):
          if ($adminOnly === 'lead' && !is_lead($u)) continue;  // Ομάδα, Ρυθμίσεις: admin και managers
          if ($adminOnly === 'admin' && !is_admin($u)) continue; // Ρυθμίσεις: μόνο admin
          if ($key === '_h'): ?>
            <div class="nav-heading"><?= h(t($label)) ?></div>
          <?php else: ?>
            <a href="<?= h($href) ?>" class="nav-link<?= $active === $key ? ' active' : '' ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= h(tx('nav', $label)) ?></span></a>
          <?php endif;
      endforeach; ?>
    </nav>
  </div>
</aside>
<main class="app-main">
<?php else: ?>
<main class="app-main-full">
<?php endif; ?>
<?php foreach (take_flashes() as $f): ?>
  <div class="alert alert-<?= $f['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
    <?= h($f['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endforeach;
}

function render_footer(): void
{
    $i18n = [
        'confirmDelete' => t('Διαγραφή αυτής της καταχώρησης;'),
        'search' => t('Αναζήτηση'),
        'noResults' => t('Δεν βρέθηκε τίποτα.'),
        'months' => lang() === 'en'
            ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
            : ['Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'],
        'wd' => lang() === 'en' ? ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] : ['Δε', 'Τρ', 'Τε', 'Πε', 'Πα', 'Σα', 'Κυ'],
        'today' => t('Σήμερα'),
        'clear' => t('Καθαρισμός'),
        'pickDate' => t('Επιλογή ημερομηνίας'),
        'prevMonth' => t('Προηγούμενος μήνας'),
        'nextMonth' => t('Επόμενος μήνας'),
        'appName' => app_name(),
        'notifyIcon' => notify_icon_url(),
        'pushDenied' => t('Οι ειδοποιήσεις είναι μπλοκαρισμένες από τον browser. Επίτρεψέ τες από το εικονίδιο δίπλα στη διεύθυνση.'),
        'pushOn' => t('Οι ειδοποιήσεις στην οθόνη είναι ενεργές.'),
        'pushTest' => t('Έτσι θα εμφανίζονται οι ειδοποιήσεις του time tracker.'),
        'netError' => t('Δεν ήταν δυνατή η σύνδεση με τον server. Δοκίμασε ξανά.'),
        'archived' => t('αρχειοθετημένο'),
    ];
    ?>
</main>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js?v=5.3.8"></script>
<script>window.TT_I18N = <?= json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html><?php
}

/** Αριθμοί σελίδων: 1 … (τρέχουσα ± 3) … τελευταία. Κρατά τα υπόλοιπα φίλτρα του URL. */
function pagination_html(string $script, int $page, int $pages): string
{
    if ($pages <= 1) return '';
    $shown = array_unique(array_merge([1, $pages], range(max(1, $page - 3), min($pages, $page + 3))));
    sort($shown);
    $html = '<nav aria-label="' . h(t('Σελίδες')) . '"><ul class="pagination pagination-sm flex-wrap mb-0">';
    $prev = 0;
    foreach ($shown as $p) {
        if ($p - $prev > 1) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        $html .= '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . h($script . '?' . qs(['page' => $p])) . '"' . ($p === $page ? ' aria-current="page"' : '') . '>' . $p . '</a></li>';
        $prev = $p;
    }
    return $html . '</ul></nav>';
}

function page_head(string $title, string $right = ''): void
{
    echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4"><h1 class="h3 mb-0">' . $title . '</h1>' . ($right !== '' ? '<div class="d-flex flex-wrap align-items-center gap-2">' . $right . '</div>' : '') . '</div>';
}

/** <select> projects ομαδοποιημένα ανά πελάτη */
function project_options(array $projects, $selected = null, ?string $emptyLabel = null): string
{
    $html = '<option value="">' . h($emptyLabel ?? t('Χωρίς project')) . '</option>';
    $byClient = [];
    foreach ($projects as $p) $byClient[$p['client_name'] ?? ''][] = $p;
    foreach ($byClient as $client => $list) {
        if ($client !== '') $html .= '<optgroup label="' . h($client) . '">';
        foreach ($list as $p) {
            $sel = ((string)$selected === (string)$p['id']) ? ' selected' : '';
            $html .= '<option value="' . (int)$p['id'] . '" data-color="' . h($p['color']) . '" data-billable="' . (int)$p['billable'] . '"' . $sel . '>' . h($p['name']) . ($p['archived'] ? ' (' . h(t('αρχειοθετημένο')) . ')' : '') . '</option>';
        }
        if ($client !== '') $html .= '</optgroup>';
    }
    return $html;
}

/** Dropdown tags με checkboxes */
function tag_picker(array $tags, array $selected, string $name = 'tags[]'): string
{
    $count = count($selected);
    $html = '<div class="dropdown tagpicker">'
        . '<button class="btn btn-icon' . ($count ? ' text-primary' : '') . '" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Tags">'
        . icon('tag', 'fs-5') . '<span class="tp-count badge rounded-pill text-bg-primary">' . ($count ?: '') . '</span></button>'
        . '<div class="dropdown-menu dropdown-menu-end shadow p-2 tagpicker-menu">';
    if (!$tags) $html .= '<div class="small text-body-secondary p-2">' . h(t('Δεν υπάρχουν tags.')) . ' <a href="tags.php">' . h(t('Πρόσθεσε')) . '</a></div>';
    if (count($tags) >= 10) {
        $html .= '<div class="tsel-search-wrap">' . icon('search') . '<input type="search" class="form-control form-control-sm tp-search" placeholder="' . h(t('Αναζήτηση tag…')) . '" aria-label="' . h(t('Αναζήτηση tag…')) . '"></div>';
    }
    $html .= '<div class="tp-list">';
    foreach ($tags as $t) {
        $id = 'tp' . (int)$t['id'] . '_' . substr(md5($name), 0, 4);
        $chk = in_array((int)$t['id'], $selected, true) ? ' checked' : '';
        $html .= '<div class="form-check tp-row"><input class="form-check-input" type="checkbox" name="' . h($name) . '" value="' . (int)$t['id'] . '" id="' . $id . '"' . $chk . '><label class="form-check-label w-100" for="' . $id . '">' . h($t['name']) . '</label></div>';
    }
    $html .= '<div class="tsel-empty tp-empty" hidden>' . h(t('Δεν βρέθηκε tag.')) . '</div>';
    $html .= '</div></div></div>';
    return $html;
}

/** Chips (btn-check) για πολλαπλή επιλογή */
function chips(string $name, array $items, array $checked, string $idPrefix, bool $withColor = false): string
{
    $html = '<div class="d-flex flex-wrap gap-2">';
    foreach ($items as $it) {
        $id = $idPrefix . (int)$it['id'];
        $html .= '<input type="checkbox" class="btn-check" name="' . h($name) . '" value="' . (int)$it['id'] . '" id="' . $id . '" autocomplete="off"' . (in_array((int)$it['id'], $checked, true) ? ' checked' : '') . '>'
            . '<label class="btn btn-sm btn-outline-primary rounded-pill" for="' . $id . '">'
            . ($withColor && !empty($it['color']) ? '<span class="dot" style="background:' . h($it['color']) . '"></span>' : '')
            . h($it['name']) . '</label>';
    }
    return $html . '</div>';
}

/** Modal επεξεργασίας / προσθήκης καταχώρησης (κοινό σε tracker, ημερολόγιο, αναφορές) */
function render_entry_dialog(array $user, string $return): void
{
    $projects = visible_projects($user);
    $tags = all_tags();
    ?>
<div class="modal fade" id="entryModal" tabindex="-1" aria-labelledby="entryModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form method="post" action="entry_action.php" class="modal-content" id="entryDialogForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="">
      <input type="hidden" name="return" value="<?= h($return) ?>">
      <div class="modal-header">
        <h2 class="modal-title h5" id="entryModalTitle" data-title-edit="<?= h(t('Επεξεργασία καταχώρησης')) ?>" data-title-add="<?= h(t('Νέα καταχώρηση')) ?>"><?= h(t('Επεξεργασία καταχώρησης')) ?></h2>
        <span class="ms-2 small text-body-secondary" data-user></span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="emDesc"><?= h(t('Περιγραφή')) ?></label>
          <input type="text" class="form-control" id="emDesc" name="description" maxlength="500" placeholder="<?= h(t('Σε τι δούλεψες;')) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="emProject">Project</label>
          <select class="form-select" id="emProject" name="project_id" data-project-select><?= project_options($projects) ?></select>
        </div>
        <div class="mb-3">
          <div class="form-label">Tags</div>
          <?= $tags ? chips('tags[]', $tags, [], 'emTag') : '<span class="small text-body-secondary">' . h(t('Δεν υπάρχουν tags ακόμη.')) . '</span>' ?>
        </div>
        <div class="row g-3 align-items-end">
          <div class="col-sm-4">
            <label class="form-label" for="emDate"><?= h(t('Ημερομηνία')) ?></label>
            <input type="date" class="form-control" id="emDate" name="date" required>
          </div>
          <div class="col-6 col-sm-2">
            <label class="form-label" for="emStart"><?= h(t('Έναρξη')) ?></label>
            <input type="text" class="form-control text-center" id="emStart" name="start" placeholder="09:00" inputmode="numeric" required>
          </div>
          <div class="col-6 col-sm-2">
            <label class="form-label" for="emEnd"><?= h(t('Λήξη')) ?></label>
            <input type="text" class="form-control text-center" id="emEnd" name="end" placeholder="10:30" inputmode="numeric">
          </div>
          <div class="col-sm-4">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="emBill" name="billable" value="1">
              <label class="form-check-label" for="emBill"><?= h(t('Χρεώσιμο')) ?> <?= icon('euro') ?></label>
            </div>
          </div>
        </div>
        <p class="small text-body-secondary mt-3 mb-0" data-running-note hidden><?= h(t('Η καταχώρηση τρέχει ακόμη· άφησε κενή τη λήξη για να συνεχίσει.')) ?></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" data-delete data-confirm="<?= h(t('Διαγραφή αυτής της καταχώρησης;')) ?>"><?= icon('trash', 'me-1') ?><?= h(t('Διαγραφή')) ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= h(t('Άκυρο')) ?></button>
        <button type="submit" class="btn btn-primary"><?= h(t('Αποθήκευση')) ?></button>
      </div>
    </form>
  </div>
</div>
<?php
}
