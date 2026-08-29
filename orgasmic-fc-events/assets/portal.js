(function () {
  const cfg = window.OrgasmicFcEvents || {};
  if (!cfg.root) return;

  const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
  const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

  let state = {
    view: 'list',
    month: new Date(),
    events: [],
    event: null,
    bootstrap: null,
    zoomUsers: [],
    error: '',
    saving: false,
  };

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function h(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content;
  }

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function ymdLocal(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function eventTz(ev) {
    return (ev && ev.timezone) || (state.bootstrap && state.bootstrap.timezone) || 'Europe/Berlin';
  }

  function zonedParts(iso, timeZone) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return { date: '', time: '' };
    const fmt = new Intl.DateTimeFormat('en-US', {
      timeZone: timeZone || undefined,
      hourCycle: 'h23',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });
    const map = {};
    fmt.formatToParts(d).forEach((p) => {
      if (p.type !== 'literal') map[p.type] = p.value;
    });
    return {
      date: map.year + '-' + map.month + '-' + map.day,
      time: map.hour + ':' + map.minute,
    };
  }

  function tzOffsetMs(date, timeZone) {
    const fmt = new Intl.DateTimeFormat('en-US', {
      timeZone,
      hourCycle: 'h23',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
    const map = {};
    fmt.formatToParts(date).forEach((p) => {
      if (p.type !== 'literal') map[p.type] = p.value;
    });
    const asUTC = Date.UTC(+map.year, +map.month - 1, +map.day, +map.hour, +map.minute, +map.second);
    return asUTC - date.getTime();
  }

  function zonedTimeToIso(dateStr, timeStr, timeZone) {
    if (!dateStr || !timeStr) return '';
    const local = dateStr + 'T' + (timeStr.length === 5 ? timeStr + ':00' : timeStr);
    const utcGuess = new Date(local + 'Z');
    if (Number.isNaN(utcGuess.getTime())) return '';
    let offset = tzOffsetMs(utcGuess, timeZone);
    let result = new Date(utcGuess.getTime() - offset);
    const offset2 = tzOffsetMs(result, timeZone);
    if (offset2 !== offset) {
      result = new Date(utcGuess.getTime() - offset2);
    }
    return result.toISOString();
  }

  function eventDayKey(ev) {
    try {
      return zonedParts(ev.starts_at, eventTz(ev)).date;
    } catch (e) {
      return (ev.starts_at || '').slice(0, 10);
    }
  }

  function eventEndDayKey(ev) {
    if (!ev.ends_at) return eventDayKey(ev);
    try {
      return zonedParts(ev.ends_at, eventTz(ev)).date;
    } catch (e) {
      return eventDayKey(ev);
    }
  }

  function isEventOnDay(ev, dayKey) {
    const start = eventDayKey(ev);
    const end = eventEndDayKey(ev);
    return dayKey >= start && dayKey <= end;
  }

  function isHappeningNow(ev) {
    const now = Date.now();
    const start = new Date(ev.starts_at).getTime();
    if (Number.isNaN(start)) return false;
    const end = ev.ends_at ? new Date(ev.ends_at).getTime() : start + 60 * 60 * 1000;
    return now >= start && now <= end;
  }

  function isEventToday(ev) {
    const todayKey = zonedParts(new Date().toISOString(), eventTz(ev)).date;
    return isEventOnDay(ev, todayKey) || isHappeningNow(ev);
  }

  function todayBadge(ev) {
    if (isHappeningNow(ev)) return '<span class="oc-badge oc-now">Jetzt</span>';
    if (isEventToday(ev)) return '<span class="oc-badge">Heute</span>';
    return '';
  }

  function solidBackground() {
    let el = document.body;
    while (el) {
      const bg = getComputedStyle(el).backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') return bg;
      el = el.parentElement;
    }
    return '#ffffff';
  }

  function applyTheme(root) {
    const cs = getComputedStyle(document.body);
    root.style.setProperty('--oc-portal-bg', solidBackground());
    root.style.setProperty('--oc-portal-text', cs.color || '#1d2327');
    const appearance = (state.bootstrap && state.bootstrap.portal && state.bootstrap.portal.appearance) || cfg.appearance || 'auto';
    const accent = (state.bootstrap && state.bootstrap.portal && state.bootstrap.portal.accent) || cfg.accent || '';
    if (accent) root.style.setProperty('--oc-accent-set', accent);
    const overlay = root.querySelector('.orgasmic-cal-overlay');
    if (overlay) {
      overlay.classList.add('oc-theme-' + appearance);
    }
  }

  function subtitleText() {
    return (state.bootstrap && state.bootstrap.portal && state.bootstrap.portal.subtitle)
      || cfg.subtitle
      || 'Termine für deine Kreise — RSVP, Zoom, wer dabei ist.';
  }

  function paintNavIcons() {
    const svg = cfg.navIcon;
    if (!svg) return;
    document.querySelectorAll('.orgasmic-cal-nav a, a[data-orgasmic-calendar], a[href*="#orgasmic-calendar"]').forEach((host) => {
      host.querySelectorAll('.el-icon, i[class*="el-icon"]').forEach((node) => {
        if (!node.querySelector('path, rect, circle')) node.remove();
      });
      const existing = host.querySelector('svg');
      if (existing && existing.querySelector('path, rect, circle')) return;
      if (existing) existing.remove();
      host.insertAdjacentHTML('afterbegin', svg);
    });
  }

  function watchNavIcons() {
    let scheduled = 0;
    const run = () => {
      scheduled = 0;
      paintNavIcons();
    };
    const schedule = () => {
      if (scheduled) return;
      scheduled = requestAnimationFrame(run);
    };
    paintNavIcons();
    [200, 800, 2000].forEach((ms) => setTimeout(paintNavIcons, ms));
    if (typeof MutationObserver === 'undefined' || !document.body) return;
    const obs = new MutationObserver(schedule);
    obs.observe(document.body, { childList: true, subtree: true });
    setTimeout(() => obs.disconnect(), 8000);
  }

  function updateNavIndicator() {
    const hasToday = state.events.some(isEventToday) || !!cfg.hasToday;
    document.querySelectorAll('.orgasmic-cal-nav, a[data-orgasmic-calendar], a[href*="#orgasmic-calendar"]').forEach((el) => {
      const node = el.classList && el.classList.contains('orgasmic-cal-nav') ? el : el.closest('.orgasmic-cal-nav') || el;
      node.classList.toggle('has-today', hasToday);
      const link = node.tagName === 'A' ? node : node.querySelector('a') || node;
      if (hasToday && link && !link.querySelector('.oc-nav-dot')) {
        const dot = document.createElement('span');
        dot.className = 'oc-nav-dot';
        dot.title = 'Heute findet ein Event statt';
        link.appendChild(dot);
      }
      if (!hasToday && link) {
        link.querySelectorAll('.oc-nav-dot').forEach((d) => d.remove());
      }
    });
  }

  async function api(path, options) {
    const res = await fetch(cfg.root + path.replace(/^\//, ''), Object.assign({
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': cfg.nonce,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    }, options || {}));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(data.message || 'Fehler ' + res.status);
    }
    return data;
  }

  function hashEvent() {
    const hash = location.hash || '';
    const neuDated = hash.match(/^#orgasmic-event-new-(\d{4}-\d{2}-\d{2})$/);
    if (hash === '#orgasmic-calendar') return { view: 'list' };
    if (neuDated) return { view: 'form', id: null, date: neuDated[1] };
    if (hash === '#orgasmic-event-new') return { view: 'form', id: null, date: null };
    if (hash.match(/^#orgasmic-event-\d+-edit$/)) {
      return { view: 'form', id: hash.match(/\d+/)[0], date: null };
    }
    const event = hash.match(/^#orgasmic-event-(\d+)/);
    if (event) return { view: 'detail', id: event[1] };
    return null;
  }

  function openOverlay() {
    const root = document.getElementById('orgasmic-cal-root');
    if (!root) return;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeOverlay() {
    const root = document.getElementById('orgasmic-cal-root');
    if (!root) return;
    root.hidden = true;
    root.innerHTML = '';
    document.body.style.overflow = '';
    if ((location.hash || '').indexOf('orgasmic-') === 1) {
      history.replaceState(null, '', location.pathname + location.search);
    }
  }

  function fmtDate(iso, tz) {
    try {
      return new Intl.DateTimeFormat('de-DE', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: tz || undefined,
      }).format(new Date(iso));
    } catch (e) {
      return iso;
    }
  }

  function spacesHtml(spaces) {
    return (spaces || []).map((s) => '<span>' + escapeHtml(s.title) + '</span>').join('');
  }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  async function bootFromHash() {
    const route = hashEvent();
    if (!route) {
      closeOverlay();
      return;
    }
    openOverlay();
    await ensureBootstrap();
    updateNavIndicator();
    if (route.view === 'list') return renderList();
    if (route.view === 'detail') return renderDetail(route.id);
    if (route.view === 'form') return renderForm(route.id, route.date);
  }

  async function ensureBootstrap() {
    if (!state.bootstrap) {
      state.bootstrap = await api('bootstrap');
    }
    if (!state.events.length) {
      const data = await api('events');
      state.events = data.items || [];
    }
  }

  function shell(inner) {
    const canManage = !!(state.bootstrap && state.bootstrap.can_manage);
    return '<div class="orgasmic-cal-overlay"><div class="orgasmic-cal">'
      + '<header class="orgasmic-cal-top"><div>'
      + '<p class="oc-sub">ORGASMIC</p><h1>Kalender</h1>'
      + '<p class="oc-sub">' + escapeHtml(subtitleText()) + '</p>'
      + '</div><div class="orgasmic-cal-actions">'
      + (canManage ? '<a class="oc-btn" href="#orgasmic-event-new">Neues Event</a>' : '')
      + '<button type="button" class="oc-close" data-oc-close>Schließen</button>'
      + '</div></header>'
      + (state.error ? '<p class="oc-sub">' + escapeHtml(state.error) + '</p>' : '')
      + inner
      + '</div></div>';
  }

  function mount(root, html) {
    root.innerHTML = '';
    root.appendChild(h(html));
    applyTheme(root);
    bindShell(root);
  }

  function bindShell(root) {
    root.addEventListener('click', (e) => {
      if (e.target.closest('[data-oc-close]')) {
        e.preventDefault();
        closeOverlay();
      }
    });
  }

  function monthCells(monthDate, events) {
    const first = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
    const startOffset = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).getDate();
    const prevDays = new Date(monthDate.getFullYear(), monthDate.getMonth(), 0).getDate();
    const cells = [];
    for (let i = 0; i < 42; i++) {
      let day;
      let mute = false;
      let date = null;
      if (i < startOffset) {
        day = prevDays - startOffset + i + 1;
        mute = true;
        date = new Date(monthDate.getFullYear(), monthDate.getMonth() - 1, day);
      } else if (i >= startOffset + daysInMonth) {
        day = i - (startOffset + daysInMonth) + 1;
        mute = true;
        date = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, day);
      } else {
        day = i - startOffset + 1;
        date = new Date(monthDate.getFullYear(), monthDate.getMonth(), day);
      }
      const key = ymdLocal(date);
      const todays = events.filter((ev) => isEventOnDay(ev, key));
      cells.push({
        day,
        mute,
        date,
        key,
        todays,
        today: sameDay(date, new Date()),
        live: todays.some(isHappeningNow),
      });
    }
    return cells;
  }

  function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }

  async function renderList() {
    const root = document.getElementById('orgasmic-cal-root');
    const m = state.month;
    const canManage = !!(state.bootstrap && state.bootstrap.can_manage);
    const cells = monthCells(m, state.events);
    let grid = WEEKDAYS.map((d) => '<div class="oc-dow">' + d + '</div>').join('');
    cells.forEach((c) => {
      const classes = ['oc-day'];
      if (c.mute) classes.push('oc-mute');
      if (c.today) classes.push('oc-today');
      if (c.live || c.todays.some(isEventToday)) classes.push('oc-has-live');
      if (canManage && !c.mute) classes.push('oc-clickable');
      grid += '<div class="' + classes.join(' ') + '"'
        + (canManage && !c.mute ? ' data-oc-new="' + c.key + '"' : '')
        + '>'
        + '<div class="oc-num">' + c.day + '</div>'
        + c.todays.map((ev) => '<a class="oc-pill' + (isHappeningNow(ev) || isEventToday(ev) ? ' oc-live' : '') + '" href="#orgasmic-event-' + ev.id + '">' + escapeHtml(ev.title) + '</a>').join('')
        + '</div>';
    });

    const ordered = state.events.slice().sort((a, b) => {
      const aToday = isEventToday(a) ? 0 : 1;
      const bToday = isEventToday(b) ? 0 : 1;
      if (aToday !== bToday) return aToday - bToday;
      return String(a.starts_at).localeCompare(String(b.starts_at));
    });

    const cards = ordered.map((ev) => {
      const img = ev.image_url
        ? '<img src="' + escapeHtml(ev.image_url) + '" alt="">'
        : '<div class="orgasmic-cal-cover"></div>';
      return '<a class="orgasmic-cal-card' + (isEventToday(ev) ? ' oc-today-card' : '') + '" href="#orgasmic-event-' + ev.id + '">'
        + img
        + '<div class="oc-body"><div class="orgasmic-cal-meta">' + escapeHtml(fmtDate(ev.starts_at, ev.timezone)) + '</div>'
        + '<h3>' + escapeHtml(ev.title) + todayBadge(ev) + '</h3>'
        + '<div class="orgasmic-cal-spaces">' + spacesHtml(ev.spaces) + '</div>'
        + '<p class="oc-sub">' + escapeHtml(ev.excerpt || '') + '</p>'
        + '<p class="oc-sub">' + (ev.rsvp.counts.going || 0) + ' dabei</p>'
        + '</div></a>';
    }).join('') || '<div class="orgasmic-cal-empty">Noch keine Events in deinen Kreisen.</div>';

    mount(root, shell(
      '<div class="orgasmic-cal-month">'
      + '<button type="button" class="oc-ghost" data-oc-prev>←</button>'
      + '<strong>' + MONTHS[m.getMonth()] + ' ' + m.getFullYear() + '</strong>'
      + '<button type="button" class="oc-ghost" data-oc-next>→</button>'
      + '</div>'
      + '<div class="orgasmic-cal-grid">' + grid + '</div>'
      + '<div class="orgasmic-cal-list">' + cards + '</div>'
    ));
    root.querySelector('[data-oc-prev]').onclick = () => {
      state.month = new Date(m.getFullYear(), m.getMonth() - 1, 1);
      renderList();
    };
    root.querySelector('[data-oc-next]').onclick = () => {
      state.month = new Date(m.getFullYear(), m.getMonth() + 1, 1);
      renderList();
    };
    root.querySelectorAll('[data-oc-new]').forEach((el) => {
      el.addEventListener('click', (e) => {
        if (e.target.closest('a')) return;
        location.hash = '#orgasmic-event-new-' + el.getAttribute('data-oc-new');
      });
    });
  }

  async function renderDetail(id) {
    const root = document.getElementById('orgasmic-cal-root');
    state.error = '';
    try {
      state.event = await api('events/' + id);
    } catch (e) {
      state.error = e.message;
      mount(root, shell(''));
      return;
    }
    const ev = state.event;
    const hero = ev.image_url ? '<img src="' + escapeHtml(ev.image_url) + '" alt="">' : '';
    const rsvp = ['going', 'maybe', 'declined'].map((s) => {
      const labels = { going: 'Ich bin dabei', maybe: 'Vielleicht', declined: 'Kann nicht' };
      return '<button type="button" data-rsvp="' + s + '"' + (ev.rsvp.mine === s ? ' class="is-on"' : '') + '>' + labels[s] + '</button>';
    }).join('');
    const people = (ev.attendees || []).map((p) => '<figure><img src="' + escapeHtml(p.avatar || '') + '" alt=""><figcaption>' + escapeHtml(p.display_name) + '<br>' + (p.status === 'going' ? 'dabei' : 'vielleicht') + '</figcaption></figure>').join('');
    const join = ev.join_url ? '<a class="oc-btn" href="' + escapeHtml(ev.join_url) + '" target="_blank" rel="noopener">Zoom öffnen</a>' : '<p class="oc-sub">Der Zoom-Link erscheint, sobald du „Ich bin dabei“ wählst.</p>';
    const manage = ev.can_manage ? '<a class="oc-btn oc-ghost" href="#orgasmic-event-' + ev.id + '-edit">Bearbeiten</a> <button type="button" class="oc-ghost" data-oc-del>Löschen</button>' : '';

    mount(root, shell(
      '<p><a class="oc-btn oc-ghost" href="#orgasmic-calendar">← Alle Events</a></p>'
      + '<article class="orgasmic-cal-detail">'
      + '<div class="oc-hero">' + hero + '</div>'
      + '<div class="orgasmic-cal-spaces">' + spacesHtml(ev.spaces) + '</div>'
      + '<h2>' + escapeHtml(ev.title) + todayBadge(ev) + '</h2>'
      + '<p>' + escapeHtml(fmtDate(ev.starts_at, ev.timezone)) + (ev.ends_at ? ' – ' + escapeHtml(fmtDate(ev.ends_at, ev.timezone)) : '') + '</p>'
      + '<div class="orgasmic-cal-desc">' + (ev.description_html || '') + '</div>'
      + (ev.rsvp_enabled ? '<div class="orgasmic-cal-rsvp">' + rsvp + '</div>' : '')
      + '<p class="oc-sub">' + (ev.rsvp.counts.going || 0) + ' Zusagen' + (ev.rsvp_capacity ? ' / ' + ev.rsvp_capacity : '') + ', ' + (ev.rsvp.counts.maybe || 0) + ' vielleicht</p>'
      + join + ' ' + manage
      + '<h3>Wer nimmt teil</h3><div class="orgasmic-cal-people">' + (people || '<p class="oc-sub">Noch niemand hat zugesagt.</p>') + '</div>'
      + '</article>'
    ));
    root.querySelectorAll('[data-rsvp]').forEach((btn) => {
      btn.onclick = async () => {
        try {
          state.event = await api('events/' + id + '/rsvp', { method: 'POST', body: JSON.stringify({ status: btn.getAttribute('data-rsvp') }) });
          renderDetail(id);
        } catch (e) {
          alert(e.message);
        }
      };
    });
    const del = root.querySelector('[data-oc-del]');
    if (del) {
      del.onclick = async () => {
        if (!confirm('Event wirklich löschen?')) return;
        await api('events/' + id, { method: 'DELETE' });
        state.events = [];
        location.hash = '#orgasmic-calendar';
      };
    }
  }

  function addHoursToParts(dateStr, timeStr, hours) {
    const iso = zonedTimeToIso(dateStr, timeStr, (state.bootstrap && state.bootstrap.timezone) || 'Europe/Berlin');
    const d = new Date(iso);
    d.setTime(d.getTime() + hours * 60 * 60 * 1000);
    return zonedParts(d.toISOString(), (state.bootstrap && state.bootstrap.timezone) || 'Europe/Berlin');
  }

  async function renderForm(id, datePrefill) {
    const root = document.getElementById('orgasmic-cal-root');
    const tz = (state.bootstrap && state.bootstrap.timezone) || 'Europe/Berlin';
    let ev = {
      title: '', description_html: '', timezone: tz,
      visibility: 'spaces', space_ids: [], rsvp_enabled: true, location_type: 'zoom',
      reminder_minutes: (state.bootstrap && state.bootstrap.default_reminders) || [1440, 60],
      share_to_feed: true, create_zoom: true,
    };
    if (id) {
      ev = await api('events/' + id);
    }
    if (state.bootstrap && state.bootstrap.zoom_configured && !state.zoomUsers.length) {
      try {
        const z = await api('zoom/users');
        state.zoomUsers = z.items || [];
      } catch (e) {
        state.zoomUsers = [];
      }
    }

    const startParts = ev.starts_at
      ? zonedParts(ev.starts_at, ev.timezone || tz)
      : { date: datePrefill || ymdLocal(new Date()), time: '18:00' };
    const defaultEnd = addHoursToParts(startParts.date, startParts.time, 1);
    const endParts = ev.ends_at
      ? zonedParts(ev.ends_at, ev.timezone || tz)
      : defaultEnd;

    const spaces = ((state.bootstrap && state.bootstrap.spaces) || []).map((s) => {
      const checked = (ev.space_ids || (ev.spaces || []).map((x) => x.id) || []).map(String).indexOf(String(s.id)) !== -1;
      return '<label><input type="checkbox" name="space_ids" value="' + s.id + '"' + (checked ? ' checked' : '') + '> ' + escapeHtml(s.title) + '</label>';
    }).join('');
    const zoomOpts = ['<option value="">— Zoom-Account wählen —</option>'].concat(
      state.zoomUsers.map((u) => '<option value="' + escapeHtml(u.email) + '"' + (ev.zoom_user_email === u.email ? ' selected' : '') + '>' + escapeHtml(u.display_name + ' · ' + u.email) + '</option>')
    ).join('');

    mount(root, shell(
      '<p><a class="oc-btn oc-ghost" href="#orgasmic-calendar">← Zurück</a></p>'
      + '<form class="orgasmic-cal-form" id="oc-form">'
      + '<label>Name<input name="title" required value="' + escapeHtml(ev.title || '') + '"></label>'
      + '<div class="oc-row-4">'
      + '<label>Datum<input type="date" name="start_date" required value="' + escapeHtml(startParts.date) + '"></label>'
      + '<label>Startzeit<input type="time" name="start_time" required step="300" value="' + escapeHtml(startParts.time) + '"></label>'
      + '<label>Ende-Datum<input type="date" name="end_date" value="' + escapeHtml(endParts.date) + '"></label>'
      + '<label>Endezeit<input type="time" name="end_time" step="300" value="' + escapeHtml(endParts.time) + '"></label>'
      + '</div>'
      + '<label>Zeitzone<input name="timezone" value="' + escapeHtml(ev.timezone || tz) + '"></label>'
      + '<label>Beschreibung<div id="oc-desc" class="oc-editor" contenteditable="true">' + (ev.description_html || '') + '</div></label>'
      + '<label>Titelbild<input type="file" name="image" accept="image/*"></label>'
      + '<label>Sichtbar für<select name="visibility"><option value="spaces"' + (ev.visibility !== 'all' ? ' selected' : '') + '>Gewählte Spaces / Kreise</option><option value="all"' + (ev.visibility === 'all' ? ' selected' : '') + '>Alle Mitglieder</option></select></label>'
      + '<fieldset><legend>Spaces</legend>' + spaces + '</fieldset>'
      + '<label>Ort<select name="location_type"><option value="zoom">Zoom Meeting anlegen</option><option value="url"' + (ev.location_type === 'url' ? ' selected' : '') + '>Eigener Link</option><option value="none"' + (ev.location_type === 'none' ? ' selected' : '') + '>Kein Link</option></select></label>'
      + '<label>Zoom Sub-Account (E-Mail)<select name="zoom_user_email">' + zoomOpts + '</select></label>'
      + '<label>Oder Zoom-/Meeting-Link manuell<input name="zoom_join_url" value="' + escapeHtml(ev.join_url || ev.external_url || '') + '"></label>'
      + '<label>Kapazität (optional)<input type="number" min="1" name="rsvp_capacity" value="' + (ev.rsvp_capacity || '') + '"></label>'
      + '<label>Reminder (Minuten vor Start)<input name="reminder_minutes" value="' + escapeHtml((ev.reminder_minutes || []).join(', ')) + '"></label>'
      + '<label><input type="checkbox" name="share_to_feed"' + (ev.share_to_feed !== false ? ' checked' : '') + '> Im Activity Stream teilen</label>'
      + '<label><input type="checkbox" name="rsvp_enabled"' + (ev.rsvp_enabled !== false ? ' checked' : '') + '> RSVP aktiv</label>'
      + '<button type="submit">' + (id ? 'Speichern' : 'Event erstellen') + '</button>'
      + '</form>'
    ));
    const startDate = root.querySelector('[name="start_date"]');
    const startTime = root.querySelector('[name="start_time"]');
    const endDate = root.querySelector('[name="end_date"]');
    const endTime = root.querySelector('[name="end_time"]');
    const syncEnd = () => {
      if (!endDate.value) endDate.value = startDate.value;
      if (endDate.value === startDate.value && endTime.value && startTime.value && endTime.value <= startTime.value) {
        const next = addHoursToParts(startDate.value, startTime.value, 1);
        endDate.value = next.date;
        endTime.value = next.time;
      }
    };
    startDate.addEventListener('change', () => {
      if (!endDate.value || endDate.value === startParts.date) endDate.value = startDate.value;
    });
    startTime.addEventListener('change', syncEnd);

    $('#oc-form').onsubmit = async (e) => {
      e.preventDefault();
      const form = e.target;
      const spaceIds = [...form.querySelectorAll('[name="space_ids"]:checked')].map((i) => parseInt(i.value, 10));
      const zone = form.timezone.value || tz;
      const starts = zonedTimeToIso(form.start_date.value, form.start_time.value, zone);
      const ends = form.end_date.value && form.end_time.value
        ? zonedTimeToIso(form.end_date.value, form.end_time.value, zone)
        : null;
      const body = {
        title: form.title.value,
        description: ($('#oc-desc') && $('#oc-desc').innerHTML) || '',
        starts_at: starts,
        ends_at: ends,
        timezone: zone,
        visibility: form.visibility.value,
        space_ids: spaceIds,
        location_type: form.location_type.value,
        zoom_user_email: form.zoom_user_email.value,
        zoom_join_url: form.location_type.value === 'zoom' ? form.zoom_join_url.value : '',
        external_url: form.location_type.value === 'url' ? form.zoom_join_url.value : '',
        rsvp_capacity: form.rsvp_capacity.value ? parseInt(form.rsvp_capacity.value, 10) : null,
        reminder_minutes: form.reminder_minutes.value.split(/[,\s]+/).map((n) => parseInt(n, 10)).filter(Boolean),
        share_to_feed: form.share_to_feed.checked,
        rsvp_enabled: form.rsvp_enabled.checked,
        create_zoom: form.location_type.value === 'zoom' && !!form.zoom_user_email.value,
        status: 'published',
      };
      try {
        const saved = id
          ? await api('events/' + id, { method: 'PUT', body: JSON.stringify(body) })
          : await api('events', { method: 'POST', body: JSON.stringify(body) });
        const file = form.image.files[0];
        if (file) {
          const fd = new FormData();
          fd.append('image', file);
          await fetch(cfg.root + 'events/' + saved.id + '/image', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce },
            body: fd,
          });
        }
        if (form.share_to_feed.checked && saved.feed_share_error) {
          alert('Event gespeichert, aber der Activity Stream hat nicht übernommen: ' + saved.feed_share_error);
        }
        state.events = [];
        location.hash = '#orgasmic-event-' + saved.id;
      } catch (err) {
        alert(err.message);
      }
    };
  }

  function intercept(e) {
    const a = e.target.closest('a[href="#orgasmic-calendar"], a[href*="#orgasmic-event"], a[data-orgasmic-calendar]');
    if (!a) return;
    e.preventDefault();
    const href = a.getAttribute('href') || '#orgasmic-calendar';
    const hash = href.indexOf('#') >= 0 ? href.slice(href.indexOf('#')) : '#orgasmic-calendar';
    if (location.hash !== hash) location.hash = hash;
    else bootFromHash();
  }

  document.addEventListener('click', intercept);
  window.addEventListener('hashchange', bootFromHash);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      watchNavIcons();
      updateNavIndicator();
      bootFromHash();
    });
  } else {
    watchNavIcons();
    updateNavIndicator();
    bootFromHash();
  }
})();
