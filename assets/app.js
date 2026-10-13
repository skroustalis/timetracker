/* Time Tracker */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var I18N = window.TT_I18N || {};
  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* ignore */ } }
  };

  /** Για αναζήτηση: πεζά, χωρίς τόνους/διαλυτικά, ς=σ («πανμονωτικη» βρίσκει «Πανμονωτική») */
  function norm(s) {
    return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ς/g, 'σ').trim();
  }
  var SEARCH_MIN = 10; // από τόσες επιλογές και πάνω εμφανίζεται αναζήτηση

  /* ---------- Προσαρμοσμένα dropdowns (αντί για τα native του λειτουργικού) ---------- */
  var openSel = null;
  function closeSel() {
    if (!openSel) return;
    openSel.menu.remove();
    openSel.btn.setAttribute('aria-expanded', 'false');
    openSel = null;
  }
  function selLabel(sel) {
    var o = sel.options[sel.selectedIndex];
    return o ? o.textContent : '';
  }
  function syncSel(sel) {
    if (!sel._tsel) return;
    var btn = sel._tsel;
    var o = sel.options[sel.selectedIndex];
    btn.querySelector('.tsel-text').textContent = selLabel(sel);
    var dot = btn.querySelector('.dot');
    var c = o && o.getAttribute('data-color');
    if (c) { dot.style.background = c; dot.hidden = false; } else { dot.hidden = true; }
    btn.disabled = sel.disabled;
  }
  function buildMenu(sel, btn) {
    var menu = document.createElement('div');
    menu.className = 'tsel-menu';
    menu.setAttribute('role', 'listbox');
    var search = null;
    if (sel.options.length >= SEARCH_MIN && !sel.hasAttribute('data-nosearch')) {
      var sw = document.createElement('div');
      sw.className = 'tsel-search-wrap';
      sw.innerHTML = '<i class="bi bi-search" aria-hidden="true"></i>';
      search = document.createElement('input');
      search.type = 'search';
      search.className = 'form-control form-control-sm tsel-search';
      search.placeholder = (I18N.search || 'Search') + '…';
      search.setAttribute('aria-label', I18N.search || 'Search');
      sw.appendChild(search);
      menu.appendChild(sw);
    }
    var list = document.createElement('div');
    list.className = 'tsel-list';
    menu.appendChild(list);
    var items = [];
    var group = '';
    var addOpt = function (o) {
      var it = document.createElement('button');
      it.type = 'button';
      it.className = 'dropdown-item tsel-opt' + (o.selected ? ' active' : '');
      it.setAttribute('role', 'option');
      it.setAttribute('aria-selected', o.selected ? 'true' : 'false');
      it.dataset.value = o.value;
      var c = o.getAttribute('data-color');
      if (c) { var d = document.createElement('span'); d.className = 'dot'; d.style.background = c; it.appendChild(d); }
      it.appendChild(document.createTextNode(o.textContent));
      it.dataset.q = norm(o.textContent + ' ' + group); // ψάχνει και στο όνομα του πελάτη (ομάδα)
      if (o.disabled) it.disabled = true;
      list.appendChild(it);
      items.push(it);
    };
    Array.prototype.forEach.call(sel.children, function (ch) {
      if (ch.tagName === 'OPTGROUP') {
        var h = document.createElement('div');
        h.className = 'dropdown-header tsel-group';
        h.textContent = ch.label;
        list.appendChild(h);
        group = ch.label;
        Array.prototype.forEach.call(ch.children, addOpt);
        group = '';
      } else if (ch.tagName === 'OPTION') addOpt(ch);
    });
    var empty = document.createElement('div');
    empty.className = 'tsel-empty';
    empty.textContent = I18N.noResults || 'No results';
    empty.hidden = true;
    list.appendChild(empty);
    var choose = function (it) {
      if (!it || it.disabled) return;
      sel.value = it.dataset.value;
      syncSel(sel);
      closeSel();
      btn.focus();
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    };
    list.addEventListener('click', function (e) { choose(e.target.closest('.tsel-opt')); });
    var visible = function () { return items.filter(function (i) { return !i.hidden; }); };
    var focusIdx = function (d) {
      var v = visible(); if (!v.length) return;
      var cur = v.indexOf(document.activeElement);
      var n = cur === -1 ? (d > 0 ? 0 : v.length - 1) : Math.max(0, Math.min(v.length - 1, cur + d));
      v[n].focus();
    };
    menu.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeSel(); btn.focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); focusIdx(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); focusIdx(-1); }
      else if (e.key === 'Enter' && document.activeElement === search) { e.preventDefault(); choose(visible()[0]); }
      else if (e.key === 'Tab') { closeSel(); }
    });
    if (search) {
      search.addEventListener('input', function () {
        var words = norm(search.value).split(/\s+/).filter(Boolean);
        var shown = 0;
        items.forEach(function (i) {
          i.hidden = !words.every(function (w) { return i.dataset.q.indexOf(w) !== -1; });
          if (!i.hidden) shown++;
        });
        empty.hidden = shown > 0;
        $$('.tsel-group', list).forEach(function (h) {
          var n = h.nextElementSibling, any = false;
          while (n && !n.classList.contains('tsel-group')) { if (!n.hidden && n.classList.contains('tsel-opt')) any = true; n = n.nextElementSibling; }
          h.hidden = !any;
        });
      });
    }
    return { menu: menu, search: search, items: items };
  }
  function placeMenu(btn, menu) {
    var host = btn.closest('.modal') || document.body;
    var r = btn.getBoundingClientRect();
    var hr = host === document.body ? { left: 0, top: 0 } : host.getBoundingClientRect();
    menu.style.minWidth = Math.max(r.width, 180) + 'px';
    var below = window.innerHeight - r.bottom;
    var h = Math.min(menu.scrollHeight, 340);
    var top = (below < h + 12 && r.top > below) ? r.top - h - 6 : r.bottom + 6;
    var left = Math.min(r.left, window.innerWidth - menu.offsetWidth - 8);
    menu.style.top = (top - hr.top) + 'px';
    menu.style.left = (Math.max(8, left) - hr.left) + 'px';
  }
  function openMenu(sel, btn) {
    if (openSel && openSel.sel === sel) { closeSel(); return; }
    closeSel();
    if (typeof closeDate === 'function') closeDate();
    var m = buildMenu(sel, btn);
    var host = btn.closest('.modal') || document.body;
    host.appendChild(m.menu);
    m.menu.style.position = 'fixed';
    placeMenu(btn, m.menu);
    btn.setAttribute('aria-expanded', 'true');
    openSel = { sel: sel, btn: btn, menu: m.menu };
    var act = m.menu.querySelector('.tsel-opt.active');
    if (m.search) m.search.focus();
    else if (act) act.focus();
    else if (m.items[0]) m.items[0].focus();
    if (act) act.scrollIntoView({ block: 'nearest' });
  }
  function enhanceSelect(sel) {
    if (sel._tsel || sel.multiple || sel.hasAttribute('data-native')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = sel.className + ' tsel-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    var lab = sel.getAttribute('aria-label') || (sel.id && $('label[for="' + sel.id + '"]') ? $('label[for="' + sel.id + '"]').textContent.trim() : '');
    if (lab) btn.setAttribute('aria-label', lab);
    btn.innerHTML = '<span class="dot" hidden></span><span class="tsel-text"></span>';
    sel.classList.add('tsel-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    sel.parentNode.insertBefore(btn, sel.nextSibling);
    sel._tsel = btn;
    if (sel.id) {
      var l = $('label[for="' + sel.id + '"]');
      if (l) l.addEventListener('click', function (e) { e.preventDefault(); btn.focus(); });
    }
    btn.addEventListener('click', function () { openMenu(sel, btn); });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); openMenu(sel, btn); }
    });
    sel.addEventListener('change', function () { syncSel(sel); });
    syncSel(sel);
  }
  window.TTSelect = { sync: function (root) { $$('select', root || document).forEach(syncSel); }, enhance: enhanceSelect };
  document.addEventListener('mousedown', function (e) {
    if (openSel && !openSel.menu.contains(e.target) && !openSel.btn.contains(e.target)) closeSel();
  });
  window.addEventListener('resize', closeSel);
  document.addEventListener('scroll', function (e) {
    if (openSel && !openSel.menu.contains(e.target)) closeSel();
  }, true);
  document.addEventListener('reset', function (e) { setTimeout(function () { window.TTSelect.sync(e.target); }, 0); }, true);

  /* ---------- Επιλογή ημερομηνίας (αντί για το ημερολόγιο του browser) ---------- */
  var MONTHS = I18N.months || ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var WDAYS = I18N.wd || ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
  var openDate = null;
  function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function parseYmd(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }
  function dateLabel(inp) {
    var d = parseYmd(inp.value);
    return d ? ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear() : '';
  }
  function syncDate(inp) {
    if (!inp._tdate) return;
    var t = dateLabel(inp);
    var txt = inp._tdate.querySelector('.tdate-text');
    txt.textContent = t || inp.getAttribute('placeholder') || (I18N.pickDate || 'Pick a date');
    inp._tdate.classList.toggle('is-empty', !t);
    inp._tdate.classList.remove('is-invalid');
  }
  function closeDate() {
    if (!openDate) return;
    openDate.menu.remove();
    openDate.btn.setAttribute('aria-expanded', 'false');
    openDate = null;
  }
  function setDate(inp, v) {
    inp.value = v;
    syncDate(inp);
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    inp.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function openDatePicker(inp, btn) {
    if (openDate && openDate.inp === inp) { closeDate(); return; }
    closeSel(); closeDate();
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var selected = parseYmd(inp.value);
    var view = new Date((selected || today).getFullYear(), (selected || today).getMonth(), 1);
    var focusDate = selected || today;
    var menu = document.createElement('div');
    menu.className = 'tdate-menu';
    menu.setAttribute('role', 'dialog');
    menu.setAttribute('aria-label', btn.getAttribute('aria-label') || '');
    var render = function (focus) {
      var start = new Date(view); // Δευτέρα πρώτη
      start.setDate(1 - ((view.getDay() + 6) % 7));
      var h = '<div class="tdate-head"><div class="tdate-title">' + MONTHS[view.getMonth()] + ' ' + view.getFullYear() + '</div>'
        + '<div class="tdate-nav"><button type="button" data-nav="-1" aria-label="' + (I18N.prevMonth || 'Previous month') + '"><i class="bi bi-chevron-left"></i></button>'
        + '<button type="button" data-nav="1" aria-label="' + (I18N.nextMonth || 'Next month') + '"><i class="bi bi-chevron-right"></i></button></div></div>'
        + '<div class="tdate-grid" role="grid">';
      WDAYS.forEach(function (w, i) { h += '<div class="tdate-wd' + (i > 4 ? ' we' : '') + '">' + w + '</div>'; });
      for (var i = 0; i < 42; i++) {
        var d = new Date(start); d.setDate(start.getDate() + i);
        var cls = 'tdate-day';
        if (d.getMonth() !== view.getMonth()) cls += ' out';
        if (+d === +today) cls += ' today';
        if (selected && +d === +selected) cls += ' sel';
        if (i % 7 > 4) cls += ' we';
        h += '<button type="button" class="' + cls + '" data-d="' + ymd(d) + '" tabindex="-1"' + (selected && +d === +selected ? ' aria-selected="true"' : '') + '>' + d.getDate() + '</button>';
      }
      h += '</div><div class="tdate-foot"><button type="button" data-act="today">' + (I18N.today || 'Today') + '</button>'
        + (inp.required ? '' : '<button type="button" data-act="clear">' + (I18N.clear || 'Clear') + '</button>') + '</div>';
      menu.innerHTML = h;
      if (focus) {
        var f = menu.querySelector('[data-d="' + ymd(focusDate) + '"]');
        if (f) { f.tabIndex = 0; f.focus(); }
      }
    };
    var move = function (days) {
      focusDate = new Date(focusDate); focusDate.setDate(focusDate.getDate() + days);
      if (focusDate.getMonth() !== view.getMonth() || focusDate.getFullYear() !== view.getFullYear()) view = new Date(focusDate.getFullYear(), focusDate.getMonth(), 1);
      render(true);
    };
    menu.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      if (b.hasAttribute('data-nav')) { view.setMonth(view.getMonth() + (+b.getAttribute('data-nav'))); focusDate = new Date(view); render(false); return; }
      if (b.getAttribute('data-act') === 'today') { setDate(inp, ymd(today)); closeDate(); btn.focus(); return; }
      if (b.getAttribute('data-act') === 'clear') { setDate(inp, ''); closeDate(); btn.focus(); return; }
      if (b.hasAttribute('data-d')) { setDate(inp, b.getAttribute('data-d')); closeDate(); btn.focus(); }
    });
    menu.addEventListener('keydown', function (e) {
      var k = e.key;
      if (k === 'Escape') { e.preventDefault(); e.stopPropagation(); closeDate(); btn.focus(); }
      else if (k === 'Tab') { closeDate(); }
      else if (e.target.classList.contains('tdate-day')) {
        if (k === 'ArrowLeft') { e.preventDefault(); move(-1); }
        else if (k === 'ArrowRight') { e.preventDefault(); move(1); }
        else if (k === 'ArrowUp') { e.preventDefault(); move(-7); }
        else if (k === 'ArrowDown') { e.preventDefault(); move(7); }
        else if (k === 'PageUp') { e.preventDefault(); move(-30); }
        else if (k === 'PageDown') { e.preventDefault(); move(30); }
      }
    });
    var host = btn.closest('.modal') || document.body;
    render(false);
    host.appendChild(menu);
    menu.style.position = 'fixed';
    var r = btn.getBoundingClientRect(), hr = host === document.body ? { left: 0, top: 0 } : host.getBoundingClientRect();
    var mh = menu.offsetHeight, mw = menu.offsetWidth;
    var top = (window.innerHeight - r.bottom < mh + 12 && r.top > mh + 12) ? r.top - mh - 6 : r.bottom + 6;
    var left = Math.max(8, Math.min(r.left, window.innerWidth - mw - 8));
    menu.style.top = (top - hr.top) + 'px';
    menu.style.left = (left - hr.left) + 'px';
    btn.setAttribute('aria-expanded', 'true');
    openDate = { inp: inp, btn: btn, menu: menu };
    render(true);
  }
  function enhanceDate(inp) {
    if (inp._tdate || inp.hasAttribute('data-native')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = inp.className + ' tdate-btn';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.setAttribute('aria-expanded', 'false');
    if (inp.getAttribute('aria-label')) btn.setAttribute('aria-label', inp.getAttribute('aria-label'));
    btn.innerHTML = '<span class="tdate-text"></span><i class="bi bi-calendar3" aria-hidden="true"></i>';
    inp.classList.add('tsel-native');
    inp.tabIndex = -1;
    inp.setAttribute('aria-hidden', 'true');
    inp.parentNode.insertBefore(btn, inp.nextSibling);
    inp._tdate = btn;
    if (inp.id) {
      var l = $('label[for="' + inp.id + '"]');
      if (l) l.addEventListener('click', function (e) { e.preventDefault(); btn.focus(); });
    }
    btn.addEventListener('click', function () { openDatePicker(inp, btn); });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); openDatePicker(inp, btn); }
    });
    // υποχρεωτικό πεδίο χωρίς τιμή: δείξε το λάθος στο κουμπί
    inp.addEventListener('invalid', function (e) { e.preventDefault(); btn.classList.add('is-invalid'); btn.focus(); });
    inp.addEventListener('change', function () { syncDate(inp); });
    syncDate(inp);
  }
  window.TTDate = { sync: function (root) { $$('input[type=date]', root || document).forEach(syncDate); } };
  document.addEventListener('mousedown', function (e) {
    if (openDate && !openDate.menu.contains(e.target) && !openDate.btn.contains(e.target)) closeDate();
  });
  window.addEventListener('resize', closeDate);
  document.addEventListener('scroll', function (e) {
    if (openDate && !openDate.menu.contains(e.target)) closeDate();
  }, true);
  document.addEventListener('reset', function (e) { setTimeout(function () { window.TTDate.sync(e.target); }, 0); }, true);

  /* ---------- Ζωντανά χρονόμετρα (ψάχνει κάθε φορά τα [data-since], ώστε να δουλεύει και μετά από ενέργεια χωρίς reload) ---------- */
  var offset = 0;
  function syncClock() {
    var serverNow = parseInt(document.body.getAttribute('data-server-now') || '0', 10) * 1000;
    offset = serverNow ? serverNow - Date.now() : 0;
  }
  syncClock();
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec));
    return pad(Math.floor(sec / 3600)) + ':' + pad(Math.floor(sec % 3600 / 60)) + ':' + pad(sec % 60);
  }
  // Στο tab: «▶ 01:23:45 · Περιγραφή» + εικονίδιο με κόκκινη τελεία όσο τρέχει
  var baseTitle = document.title;
  function tick() {
    var timers = $$('[data-since]');
    var now = (Date.now() + offset) / 1000;
    timers.forEach(function (el) { el.textContent = fmt(now - parseInt(el.getAttribute('data-since'), 10)); });
    if (timers.length) document.title = '▶ ' + fmt(now - parseInt(timers[0].getAttribute('data-since'), 10)) + ' · ' + (document.body.getAttribute('data-running-label') || baseTitle);
    else if (document.title !== baseTitle) document.title = baseTitle;
  }
  var fav = $('link[data-favicon]');
  var favHref = fav ? fav.getAttribute('href') : '', favDot = null;
  function setFavicon(running) {
    if (!fav) return;
    if (!running) { fav.setAttribute('href', favHref); return; }
    if (favDot) { fav.setAttribute('href', favDot); return; }
    if (favHref.indexOf('data:image/svg') === 0) {
      favDot = favHref.replace('</svg>', "<circle cx='19' cy='5' r='5' fill='%23e5484d' stroke='%23fff' stroke-width='1.5'/></svg>");
      fav.setAttribute('href', favDot);
    } else {
      // δικό μας εικονίδιο (εικόνα): ζωγραφίζουμε την κόκκινη τελεία με canvas
      var img = new Image();
      img.onload = function () {
        try {
          var c = document.createElement('canvas'); c.width = c.height = 64;
          var x = c.getContext('2d'); x.drawImage(img, 0, 0, 64, 64);
          x.beginPath(); x.arc(51, 13, 12, 0, Math.PI * 2); x.fillStyle = '#e5484d'; x.fill();
          x.lineWidth = 3.5; x.strokeStyle = '#fff'; x.stroke();
          favDot = c.toDataURL('image/png');
          if ($('[data-since]')) fav.setAttribute('href', favDot);
        } catch (e) { /* αφήνουμε το αρχικό */ }
      };
      img.src = favHref;
    }
  }
  function updateRunning() { setFavicon(!!$('[data-since]')); tick(); }
  updateRunning();
  setInterval(tick, 1000);

  /* ---------- Αυτόματο σταμάτημα: μόλις περάσουν οι ώρες, ανανέωση για να το σταματήσει ο server ---------- */
  var autoReloaded = false;
  setInterval(function () {
    var autoStop = parseInt(document.body.getAttribute('data-autostop') || '0', 10);
    var t = $('[data-since]');
    if (!t || autoStop <= 0 || autoReloaded) return;
    var now = (Date.now() + offset) / 1000;
    if (now - parseInt(t.getAttribute('data-since'), 10) > autoStop * 3600 + 5) { autoReloaded = true; window.location.reload(); }
  }, 15000);

  /* ---------- Καμπανάκι ειδοποιήσεων ---------- */
  var notifPoll = null;
  var notif = $('[data-notif]');
  if (notif) {
    var countEl = $('[data-notif-count]', notif);
    var csrfEl = $('input[name=csrf]');
    var setCount = function (n) { countEl.textContent = n > 0 ? (n > 9 ? '9+' : n) : ''; notif.classList.toggle('has-unread', n > 0); };
    // Όταν ανοίγει: όλες διαβασμένες (η επισήμανση μένει μέχρι να κλείσει)
    notif.addEventListener('shown.bs.dropdown', function () {
      if (!countEl.textContent || !csrfEl) return;
      var fd = new FormData(); fd.append('act', 'read_all'); fd.append('csrf', csrfEl.value);
      fetch('notifications.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (j) { setCount(j.unread || 0); }).catch(function () {});
    });
    // Καθαρισμός: σβήνει όλες τις ειδοποιήσεις του χρήστη
    var clearBtn = $('[data-notif-clear]', notif);
    if (clearBtn) clearBtn.addEventListener('click', function () {
      if (!csrfEl) return;
      clearBtn.disabled = true;
      var fd = new FormData(); fd.append('act', 'clear'); fd.append('csrf', csrfEl.value);
      fetch('notifications.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw r; return r.json(); }).then(function (j) {
          var list = $('.notif-list', notif); if (list) list.remove();
          var nw = $('[data-notif-new]', notif); if (nw) nw.remove();
          var em = $('[data-notif-empty]', notif); if (em) em.hidden = false;
          clearBtn.remove();
          setCount(j.unread || 0);
        }).catch(function () { clearBtn.disabled = false; });
    });
    notif.addEventListener('hidden.bs.dropdown', function () {
      $$('.notif-item.unread', notif).forEach(function (i) { i.classList.remove('unread'); });
      var nw = $('[data-notif-new]', notif); if (nw) nw.remove();
    });
    // Ειδοποιήσεις στην οθόνη του υπολογιστή (Windows / Mac / Android), όσο η εφαρμογή είναι ανοιχτή σε κάποια καρτέλα
    var canPush = 'Notification' in window;
    var pushRow = $('[data-push]', notif);
    var lastKey = 'tt_notif_last_' + notif.getAttribute('data-notif-user');
    var lsRaw = function () { try { return localStorage.getItem(lastKey); } catch (e) { return null; } };
    var lsGet = function () { return parseInt(lsRaw() || '0', 10) || 0; };
    var lsSet = function (v) { try { if (lsRaw() === null || v > lsGet()) localStorage.setItem(lastKey, String(v)); } catch (e) {} };
    var showPush = function (it) {
      try {
        var n = new Notification(I18N.appName || 'Time', { body: it.text, tag: 'tt-' + it.id, icon: I18N.notifyIcon || 'assets/icon-192.png' });
        n.onclick = function () { window.focus(); window.location.href = it.url; n.close(); };
      } catch (e) {}
    };
    var pushUi = function () {
      if (!pushRow) return;
      if (!canPush || Notification.permission === 'granted') { pushRow.hidden = true; return; }
      pushRow.hidden = false;
      if (Notification.permission === 'denied') {
        $('[data-push-msg]', pushRow).textContent = I18N.pushDenied;
        var b = $('[data-push-enable]', pushRow); if (b) b.remove();
        pushRow.classList.add('is-denied');
      }
    };
    pushUi();
    if (pushRow) $('[data-push-enable]', pushRow) && $('[data-push-enable]', pushRow).addEventListener('click', function () {
      var done = function (p) {
        pushUi();
        if (p === 'granted') showPush({ id: 'test', text: I18N.pushTest, url: window.location.href });
      };
      var r = Notification.requestPermission(done);
      if (r && r.then) r.then(done);
    });
    if (lsRaw() === null) lsSet(parseInt(notif.getAttribute('data-notif-last') || '0', 10)); // πρώτη φορά σε αυτόν τον browser

    // Ανανέωση κάθε 60'' (και με την καρτέλα στο παρασκήνιο, για να βγαίνει η ειδοποίηση)
    var poll = function () {
      fetch('notifications.php?count=1&after=' + lsGet(), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (j) {
          var n = j.unread || 0;
          if (n > 0 && String(n) !== countEl.textContent) notif.classList.add('has-new');
          setCount(n);
          var away = document.hidden || !document.hasFocus();
          if (canPush && Notification.permission === 'granted' && away) (j.items || []).forEach(showPush);
          lsSet(j.last || 0);
        }).catch(function () {});
    };
    poll();
    setInterval(poll, 60000);
    notifPoll = poll;
  }

  /* ---------- Επιβεβαιώσεις ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (b && !b.hasAttribute('data-delete') && !window.confirm(b.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);

  /* ---------- Προειδοποίηση budget ---------- */
  function showBudget(pid) {
    var warnBox = $('#budgetWarn');
    if (!warnBox) return;
    var b = (window.TT_BUDGET || {})[pid];
    warnBox.classList.toggle('d-none', !b);
    if (!b) return;
    warnBox.className = 'alert py-2 mb-2 alert-' + (b.state === 'over' ? 'danger' : (b.state === 'warn' ? 'warning' : 'success'));
    warnBox.textContent = (b.state === 'ok' ? '⏳ ' : '⚠ ') + b.msg;
  }

  /* ---------- Project -> tasks ---------- */
  function fillTasks(form, selected) {
    var ps = $('[data-project-select]', form), ts = $('[data-task-select]', form);
    if (!ps || !ts) return;
    var list = (window.TT_TASKS || {})[ps.value] || [];
    ts.innerHTML = '<option value="">' + (list.length ? (I18N.task || '— Task —') : '—') + '</option>';
    list.forEach(function (t) {
      var o = document.createElement('option');
      o.value = t.id; o.textContent = t.name;
      if (String(selected) === String(t.id)) o.selected = true;
      ts.appendChild(o);
    });
    ts.classList.toggle('is-empty', !list.length);
    ts.disabled = !list.length;
  }

  /* ---------- Ώρες: "930" -> "09:30" ---------- */
  function normTime(v) {
    v = (v || '').trim().replace(/[.,]/g, ':');
    var m;
    if ((m = v.match(/^(\d{1,2})$/))) return pad(+m[1]) + ':00';
    if ((m = v.match(/^(\d{1,2})(\d{2})$/))) return pad(+m[1]) + ':' + m[2];
    if ((m = v.match(/^(\d{1,2}):(\d{1,2})$/))) return pad(+m[1]) + ':' + pad(+m[2]);
    return v;
  }
  document.addEventListener('blur', function (e) {
    var t = e.target;
    if (t && (t.name === 'start' || t.name === 'end') && t.type === 'text') t.value = normTime(t.value);
  }, true);

  /* ---------- Modal καταχώρησης: το άνοιγμα από οποιοδήποτε σημείο (η φόρμα ορίζεται στο initPage) ---------- */
  var openDialog = null;
  document.addEventListener('click', function (e) {
    if (!openDialog || !e.target.closest) return;
    var t = e.target.closest('[data-edit-trigger], [data-edit-entry]:not(.entry)');
    if (!t) return;
    var src = t.hasAttribute('data-edit-entry') ? t : t.closest('[data-edit-entry]');
    if (!src) return;
    e.preventDefault();
    try { openDialog(JSON.parse(src.getAttribute('data-edit-entry')), 'edit'); } catch (err) { /* ignore */ }
  });

  /* ---------- Αναφορές: άνοιγμα/κλείσιμο ομάδων (π.χ. project → χρήστες) ---------- */
  function grpSet(head, open) {
    var id = head.getAttribute('data-grp');
    var btn = head.querySelector('.grp-toggle');
    head.classList.toggle('open', open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    $$('tr[data-of="' + id + '"]').forEach(function (r) { r.hidden = !open; });
  }

  /* ====================================================================
     Ό,τι δένεται σε στοιχεία της σελίδας. Τρέχει στην αρχή και ξανά μετά από
     κάθε ενέργεια χωρίς reload (στο νέο περιεχόμενο).
     ==================================================================== */
  function initPage(root) {
    $$('select.form-select', root).forEach(enhanceSelect);
    $$('input[type=date]', root).forEach(enhanceDate);
    /* ---------- Dashboard: καθαρισμός ορίου από τον πίνακα (μέχρι το επόμενο βήμα) ---------- */
    var limits = $('[data-limits]', root);
    if (limits) limits.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-limit-dismiss]') : null;
      var csrf = $('input[name=csrf]');
      if (!b || !csrf) return;
      b.disabled = true;
      var fd = new FormData(); fd.append('act', 'budget_dismiss'); fd.append('project_id', b.getAttribute('data-limit-dismiss')); fd.append('tag_id', b.getAttribute('data-limit-tag') || '0'); fd.append('csrf', csrf.value);
      fetch('notifications.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw r; return r.json(); }).then(function () {
          var row = b.closest('[data-limit-row]'); row.classList.add('is-leaving');
          setTimeout(function () { row.remove(); if (!$('[data-limit-row]', limits)) limits.remove(); }, 200);
        }).catch(function () { b.disabled = false; });
    });
    $$('[data-project-select]', root).forEach(function (ps) {
      var form = ps.form;
      var ts = $('[data-task-select]', form);
      fillTasks(form, ts ? ts.getAttribute('data-selected') : '');
      ps.addEventListener('change', function () {
        fillTasks(form, '');
        var opt = ps.options[ps.selectedIndex];
        var bill = form.querySelector('input[name=billable]');
        if (bill && opt) bill.checked = opt.getAttribute('data-billable') === '1';
        if (form.id === 'trackbar') showBudget(ps.value);
      });
      if (form.id === 'trackbar' && ps.value) showBudget(ps.value);
    });
    /* ---------- Tag picker ---------- */
    $$('.tagpicker', root).forEach(function (tp) {
      var cnt = $('.tp-count', tp), btn = $('[data-bs-toggle]', tp);
      var search = $('.tp-search', tp), emptyEl = $('.tp-empty', tp);
      var rows = $$('.tp-row', tp);
      rows.forEach(function (r) { r.dataset.q = norm(r.textContent); });
      tp.addEventListener('change', function (e) {
        if (e.target === search) return;
        var n = $$('input[type=checkbox]:checked', tp).length;
        cnt.textContent = n ? n : '';
        btn.classList.toggle('text-primary', n > 0);
      });
      if (!search) return;
      var filter = function () {
        var words = norm(search.value).split(/\s+/).filter(Boolean), shown = 0;
        rows.forEach(function (r) {
          // τα επιλεγμένα μένουν πάντα ορατά
          r.hidden = !$('input', r).checked && !words.every(function (w) { return r.dataset.q.indexOf(w) !== -1; });
          if (!r.hidden) shown++;
        });
        if (emptyEl) emptyEl.hidden = shown > 0;
      };
      search.addEventListener('input', filter);
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          var first = rows.filter(function (r) { return !r.hidden && !$('input', r).checked; })[0];
          if (first && search.value.trim() !== '') { $('input', first).click(); search.select(); }
        }
      });
      tp.addEventListener('shown.bs.dropdown', function () { search.focus(); });
      tp.addEventListener('hidden.bs.dropdown', function () { search.value = ''; filter(); });
    });
    /* ---------- Μπάρα χρονομέτρησης ---------- */
    var bar = $('#trackbar', root);
    if (bar) {
      var actionInput = bar.querySelector('input[name=action]');
      var mainBtn = $('.tb-btn', bar);
      if (!bar.classList.contains('is-running')) {
        var setMode = function (m) {
          bar.setAttribute('data-mode', m);
          actionInput.value = m === 'manual' ? 'add' : 'start';
          if (mainBtn) mainBtn.textContent = mainBtn.getAttribute(m === 'manual' ? 'data-label-manual' : 'data-label-timer');
          $$('[data-mode-btn]', bar).forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-mode-btn') === m); });
          store.set('tt_mode', m);
        };
        $$('[data-mode-btn]', bar).forEach(function (b) {
          b.addEventListener('click', function () { setMode(b.getAttribute('data-mode-btn')); });
        });
        if (store.get('tt_mode') === 'manual') setMode('manual');
      } else {
        // Αυτόματη αποθήκευση όσο τρέχει
        var saveTimer = null;
        var save = function () {
          clearTimeout(saveTimer);
          saveTimer = setTimeout(function () {
            var fd = new FormData(bar);
            fd.set('action', 'update_running');
            fd.set('ajax', '1');
            fetch('entry_action.php', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
          }, 400);
        };
        bar.addEventListener('change', function (e) {
          if (e.target && e.target.name !== 'action') save();
        });
      }
    }
    /* ---------- Modal καταχώρησης ---------- */
    var modalEl = $('#entryModal', root);
    if (modalEl && window.bootstrap) {
      var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
      var form = $('#entryDialogForm', modalEl);
      var setVal = function (name, v) { var el = form.elements[name]; if (el) el.value = v == null ? '' : v; };
      openDialog = function (d, mode) {
        form.reset();
        var isAdd = mode === 'add';
        form.querySelector('input[name=action]').value = isAdd ? 'add' : 'update';
        setVal('id', d.id || '');
        setVal('description', d.description || '');
        // Το project της καταχώρησης μπορεί να μην είναι στη λίστα (π.χ. αρχειοθετημένο): το προσθέτουμε για να μη χαθεί στην αποθήκευση
        var psel = form.elements.project_id;
        $$('option[data-extra-project]', psel).forEach(function (o) { o.remove(); });
        if (d.project_id && !psel.querySelector('option[value="' + d.project_id + '"]')) {
          var po = document.createElement('option');
          po.value = d.project_id;
          po.textContent = (d.project_name || ('#' + d.project_id)) + ' (' + (I18N.archived || 'archived') + ')';
          po.setAttribute('data-extra-project', '1');
          psel.appendChild(po);
        }
        setVal('project_id', d.project_id || '');
        fillTasks(form, d.task_id || '');
        setVal('date', d.date || '');
        setVal('start', d.start || '');
        setVal('end', d.end || '');
        form.elements.billable.checked = !!d.billable;
        $$('input[name="tags[]"]', form).forEach(function (c) { c.checked = (d.tags || []).indexOf(parseInt(c.value, 10)) !== -1; });
        var h = $('.modal-title', form);
        h.textContent = h.getAttribute(isAdd ? 'data-title-add' : 'data-title-edit');
        $('[data-user]', form).textContent = d.user || '';
        window.TTSelect.sync(form);
        if (window.TTDate) window.TTDate.sync(form);
        $('[data-delete]', form).hidden = isAdd;
        $('[data-running-note]', form).hidden = !d.running;
        modal.show();
      };
      modalEl.addEventListener('shown.bs.modal', function () { form.elements.description.focus(); });
      $('[data-delete]', form).addEventListener('click', function () {
        if (!window.confirm(this.getAttribute('data-confirm'))) return;
        form.querySelector('input[name=action]').value = 'delete';
        submitForm(form);
      });

      // Ημερολόγιο: κλικ σε κενό σημείο = νέα καταχώρηση
      $$('.cal-col.can-add', root).forEach(function (col) {
        col.addEventListener('click', function (e) {
          if (e.target.closest('.cal-ev')) return;
          var hourPx = parseFloat(getComputedStyle(col.closest('.cal')).getPropertyValue('--hour')) || 48;
          var y = e.clientY - col.getBoundingClientRect().top;
          var mins = Math.max(0, Math.min(23 * 60 + 45, Math.floor(y / hourPx * 4) * 15));
          var endM = Math.min(mins + 60, 23 * 60 + 59);
          openDialog({
            date: col.getAttribute('data-date'),
            start: pad(Math.floor(mins / 60)) + ':' + pad(mins % 60),
            end: pad(Math.floor(endM / 60)) + ':' + pad(endM % 60)
          }, 'add');
        });
      });
    }
    /* ---------- Ημερολόγιο: κύλιση στις 8:00 ---------- */
    var calBody = $('#calBody', root);
    if (calBody) {
      var hp = parseFloat(getComputedStyle(calBody.closest('.cal')).getPropertyValue('--hour')) || 48;
      calBody.scrollTop = hp * 7.5;
    }
    /* ---------- Επιλογή περιόδου ---------- */
    $$('[data-range-picker]', root).forEach(function (rp) {
      var preset = $('[data-preset]', rp);
      var form = rp.closest('form');
      preset.addEventListener('change', function () { if (preset.value !== 'custom') form.submit(); });
      $$('[data-range-date]', rp).forEach(function (inp) {
        inp.addEventListener('change', function () {
          preset.value = 'custom';
          // Αυτόματη υποβολή μόνο όπου δεν υπάρχει κουμπί "Εφαρμογή"
          if (inp.value && !form.querySelector('button[type=submit]')) form.submit();
        });
      });
    });
    /* ---------- Εμφάνιση: ζωντανή προεπισκόπηση ---------- */
    var app = $('[data-appearance]', root);
    if (app) {
      var root = document.documentElement;
      // Δείχνει τον επιλογέα του θέματος που διαλέχτηκε και εφαρμόζει θέμα, φόντο/σύνθεση και τόνο
      var applyLook = function () {
        var th = app.querySelector('input[name=theme]:checked');
        th = th ? th.value : root.getAttribute('data-theme');
        $$('[data-theme-picker]', app).forEach(function (el) { el.hidden = el.getAttribute('data-theme-picker') !== th; });
        var bg = app.querySelector('[data-theme-picker="' + th + '"] input:checked');
        var tone = th === 'classic' ? 'light' : (bg ? bg.getAttribute('data-tone') : 'dark');
        root.setAttribute('data-theme', th);
        if (bg) root.setAttribute('data-bg', bg.value);
        root.setAttribute('data-tone', tone);
        root.setAttribute('data-bs-theme', tone);
      };
      app.addEventListener('change', function (e) {
        if (e.target.name === 'theme' || e.target.name.indexOf('bg_') === 0) applyLook();
      });
    }
    /* ---------- Ρυθμίσεις: εμφάνιση σελίδας σύνδεσης και εφαρμογής (αποθήκευση αμέσως + προεπισκόπηση) ---------- */
    $$('[data-login-look]', root).forEach(function (ll) {
      var preview = ll.getAttribute('data-preview') || 'login.php?preview=1';
      var frame = $('[data-login-frame]', ll);
      var fitFrame = function () {
        if (!frame) return;
        var w = frame.parentNode.clientWidth;
        frame.style.transform = 'scale(' + (w / 1280) + ')';
      };
      fitFrame();
      window.addEventListener('resize', fitFrame);
      var llSave = function () {
        var th = ll.querySelector('input[name=theme]:checked');
        th = th ? th.value : 'glass';
        $$('[data-theme-picker]', ll).forEach(function (el) { el.hidden = el.getAttribute('data-theme-picker') !== th; });
        var bg = ll.querySelector('[data-theme-picker="' + th + '"] input:checked');
        if (frame) frame.src = preview + '&theme=' + encodeURIComponent(th) + '&bg=' + encodeURIComponent(bg ? bg.value : '');
        var fd = new FormData(ll); fd.set('ajax', '1');
        var ok = $('[data-login-saved]', ll); if (ok) ok.hidden = true;
        fetch('settings.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) throw r; return r.json(); })
          .then(function (j) {
            var now = $('[data-login-now]', ll); if (now && j.now) now.textContent = j.now;
            if (ok) ok.hidden = false;
          }).catch(function () { if (ok) ok.hidden = true; });
      };
      ll.addEventListener('change', function (e) {
        if (e.target.name === 'theme' || e.target.name.indexOf('bg_') === 0) llSave();
      });
    });
    /* ---------- Tooltips (π.χ. ονόματα στα avatars) ---------- */
    if (window.bootstrap) $$('[data-bs-toggle="tooltip"]', root).forEach(function (el) { window.bootstrap.Tooltip.getOrCreateInstance(el); });
    /* ---------- Φωτογραφία προφίλ: ανέβασμα μόλις επιλεγεί ---------- */
    $$('[data-avatar-input]', root).forEach(function (inp) {
      inp.addEventListener('change', function () { if (inp.files && inp.files.length) inp.form.submit(); });
    });
    /* ---------- Τυχαίος κωδικός ---------- */
    $$('[data-genpass-btn]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        var arr = new Uint32Array(12); window.crypto.getRandomValues(arr);
        var p = ''; for (var i = 0; i < arr.length; i++) p += chars[arr[i] % chars.length];
        var inp = b.closest('form').querySelector('[data-genpass]');
        if (inp) { inp.value = p; inp.focus(); inp.select(); }
      });
    });
    $$('tr.grp-head', root).forEach(function (head) {
      head.addEventListener('click', function (e) {
        if (e.target.closest('a, input, select, label')) return;
        grpSet(head, !head.classList.contains('open'));
      });
    });
    $$('[data-grp-all]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        var open = b.getAttribute('data-grp-all') === '1';
        $$('tr.grp-head').forEach(function (h) { grpSet(h, open); });
      });
    });
    /* ---------- Project: όρια ανά tag (προσθήκη / αφαίρεση γραμμών) ---------- */
    $$('[data-tb]', root).forEach(function (box) {
      var tpl = $('template', box), list = $('[data-tb-list]', box), add = $('[data-tb-add]', box);
      function refresh() { var e = $('[data-tb-empty]', box); if (e) e.hidden = !!$('[data-tb-row]', list); }
      if (add && tpl) add.addEventListener('click', function () {
        var row = tpl.content.firstElementChild.cloneNode(true);
        list.appendChild(row);
        $$('select.form-select', row).forEach(function (s) { window.TTSelect.enhance(s); });
        var s = $('select', row); if (s && s._tsel) s._tsel.focus();
        refresh();
      });
      box.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-tb-remove]') : null;
        if (!b) return;
        b.closest('[data-tb-row]').remove();
        refresh();
      });
    });
  }


  /* ====================================================================
     Ενέργειες χωρίς reload: οι φόρμες POST στέλνονται στο παρασκήνιο και
     αλλάζει μόνο το περιεχόμενο της σελίδας (όχι μενού, φόντο, CSS).
     Αν κάτι δεν πάει καλά, η σελίδα φορτώνει κανονικά.
     ==================================================================== */
  var busy = false;
  function canAjax(form) {
    if (!window.fetch || !window.DOMParser || !window.history || !history.pushState) return false;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return false;
    if (form.hasAttribute('data-noajax') || form.target) return false;
    if (form.enctype === 'multipart/form-data' && $('input[type=file]', form)) return false;
    var u;
    try { u = new URL(form.getAttribute('action') || window.location.href, window.location.href); } catch (e) { return false; }
    if (u.origin !== window.location.origin || /(logout|lang|login)\.php$/.test(u.pathname)) return false;
    return !!$('main.app-main');
  }
  // λεπτή μπάρα στο πάνω μέρος, μόνο αν η ενέργεια αργήσει (δικό της στοιχείο: δεν αγγίζει το φόντο/φίλτρο του θέματος)
  var progress = null;
  function busyOn(on) {
    if (on && !progress) { progress = document.createElement('div'); progress.className = 'tt-progress'; progress.setAttribute('aria-hidden', 'true'); document.body.appendChild(progress); }
    document.documentElement.classList.toggle('tt-busy', on); busy = on;
  }
  function cleanupPage(main) {
    closeSel(); closeDate();
    if (window.bootstrap) {
      $$('[data-bs-toggle="tooltip"]', main).forEach(function (el) { var t = window.bootstrap.Tooltip.getInstance(el); if (t) t.dispose(); });
      // τα παράθυρα (modal) δεν τα κάνουμε dispose: αν ήταν ακόμη σε animation, το bootstrap θα έψαχνε στοιχείο που δεν υπάρχει
    }
    $$('.modal-backdrop').forEach(function (b) { b.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    openDialog = null;
  }
  function swapPage(html, url) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var newMain = doc.querySelector('main.app-main'), oldMain = $('main.app-main');
    if (!newMain || !oldMain) return false;
    cleanupPage(oldMain);
    // χρονόμετρο στην πάνω μπάρα
    var oldPill = $('.app-topbar .running-pill'), newPill = doc.querySelector('.app-topbar .running-pill');
    if (oldPill) oldPill.remove();
    var brand = $('.app-topbar .navbar-brand');
    if (newPill && brand) brand.parentNode.insertBefore(document.importNode(newPill, true), brand.nextSibling);
    // μενού (ενεργή σελίδα, σήματα)
    var oldNav = $('#appSidebar nav'), newNav = doc.querySelector('#appSidebar nav');
    if (oldNav && newNav) oldNav.parentNode.replaceChild(document.importNode(newNav, true), oldNav);
    ['data-server-now', 'data-running-label', 'data-autostop'].forEach(function (a) {
      var v = doc.body.getAttribute(a);
      if (v === null) document.body.removeAttribute(a); else document.body.setAttribute(a, v);
    });
    syncClock();
    var main = document.importNode(newMain, true);
    oldMain.parentNode.replaceChild(main, oldMain);
    // τα <script> μέσα στο περιεχόμενο (π.χ. δεδομένα της σελίδας) πρέπει να τρέξουν ξανά
    $$('script', main).forEach(function (s) {
      var n = document.createElement('script');
      if (s.src) n.src = s.src; else n.textContent = s.textContent;
      s.parentNode.replaceChild(n, s);
    });
    baseTitle = doc.title;
    document.title = baseTitle;
    var u = new URL(url, window.location.href);
    if (u.pathname + u.search !== window.location.pathname + window.location.search) {
      window.history.pushState({ tt: 1 }, '', u.pathname + u.search + u.hash);
      window.scrollTo(0, 0);
    }
    initPage(main);
    updateRunning();
    if (notifPoll) notifPoll();
    var fl = $('.alert', main); if (fl && fl.getBoundingClientRect().top < 0) fl.scrollIntoView({ block: 'center' });
    return true;
  }
  function preloadImages(html) {
    var srcs = [], re = /<img[^>]+src="([^"]+)"/g, m;
    var main = html.indexOf('<main');
    re.lastIndex = main > 0 ? main : 0;
    while ((m = re.exec(html)) && srcs.length < 40) srcs.push(m[1].replace(/&amp;/g, '&'));
    if (!srcs.length || !window.Promise) return Promise.resolve();
    var all = Promise.all(srcs.map(function (src) {
      var i = new Image(); i.src = src;
      return i.decode ? i.decode().catch(function () {}) : Promise.resolve();
    }));
    return Promise.race([all, new Promise(function (r) { setTimeout(r, 250); })]);
  }
  function submitForm(form, submitter) {
    if (!canAjax(form)) { HTMLFormElement.prototype.submit.call(form); return; }
    if (busy) return;
    var fd;
    try { fd = submitter ? new FormData(form, submitter) : new FormData(form); }
    catch (e) { fd = new FormData(form); if (submitter && submitter.name) fd.append(submitter.name, submitter.value); }
    var url = new URL(form.getAttribute('action') || window.location.href, window.location.href).href;
    busyOn(true);
    if (submitter) { submitter.classList.add('tt-pressed'); submitter.disabled = true; }
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) {
        var finalUrl = r.url || url;
        var ct = r.headers.get('content-type') || '';
        if (!r.ok || ct.indexOf('text/html') === -1) { window.location.href = finalUrl; return; }
        return r.text().then(function (html) {
          // πρώτα φορτώνονται οι εικόνες (avatars κ.λπ.), για να αλλάξει η σελίδα σε ένα βήμα χωρίς τρεμόπαιγμα
          return preloadImages(html).then(function () {
            // η ενέργεια έγινε στον server· αν δεν μπορούμε να δείξουμε το αποτέλεσμα, απλή φόρτωση (όχι ξανά αποστολή)
            if (!swapPage(html, finalUrl)) window.location.href = finalUrl;
          });
        });
      })
      .catch(function () {
        var m = $('main.app-main');
        if (m) {
          var a = document.createElement('div');
          a.className = 'alert alert-danger alert-dismissible fade show';
          a.setAttribute('role', 'alert');
          a.textContent = I18N.netError || 'Connection problem. Try again.';
          m.insertBefore(a, m.firstChild);
        }
      })
      .then(function () {
        busyOn(false);
        if (submitter && document.contains(submitter)) { submitter.disabled = false; submitter.classList.remove('tt-pressed'); }
      });
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || !canAjax(form)) return;
    e.preventDefault();
    submitForm(form, e.submitter || null);
  });
  // Πίσω/Μπροστά μετά από αλλαγή σελίδας χωρίς reload: φορτώνουμε κανονικά τη σελίδα
  window.addEventListener('popstate', function () { window.location.reload(); });

  initPage(document);
})();
