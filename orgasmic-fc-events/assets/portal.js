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
    const event = hash.match(/^#orgasmic-event-(\d+)/);
    if (hash === '#orgasmic-calendar') return { view: 'list' };
    if (hash === '#orgasmic-event-new') return { view: 'form', id: null };
    if (hash.match(/^#orgasmic-event-\d+-edit$/)) {
      return { view: 'form', id: hash.match(/\d+/)[0] };
    }
    if (event) return { view: 'detail', id: event[1] };
    return null;
  }

  function openOverlay() {
    const root = document.getElementById('orgamsic-cal-root');
    if (!root) return;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeOverlay() {
    const root = document.getElementById('orgamsic-cal-root');
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

  function localInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function toIso(local) {
    return local ? new Date(local).toISOString() : '';
  }

  async function bootFromHash() {
    const route = hashEvent();
    if (!route) {
      closeOverlay();
      return;
    }
    openOverlay();
    await ensureBootstrap();
    if (route.view === 'list') return renderList();
    if (route.view === 'detail') return renderDetail(route.id);
    if (route.view === 'form') return renderForm(route.id);
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
    return '<div class="orgamsic-cal-overlay"><div class="orgamsic-cal">'
      + '<header class="orgamsic-cal-top"><div>'
      + '<p class="oc-sub">ORGAMSIC</p><h1>Kalender</h1>'
      + '<p class="oc-sub">Termine für deine Kreise — RSVP, Zoom, wer dabei ist.</p>'
      + '</div><div class="orgamsic-cal-actions">'
      + (canManage ? '<a class="oc-btn" href="#orgasmic-event-new">Neues Event</a>' : '')
      + '<button type="button" class="oc-close" data-oc-close>Schließen</button>'
      + '</div></header>'
      + (state.error ? '<p class="oc-sub">' + escapeHtml(state.error) + '</p>' : '')
      + inner
      + '</div></div>';
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
      if (i < startOffset) {
        day = prevDays - startOffset + i + 1;
        mute = true;
      } else if (i >= startOffset + daysInMonth) {
        day = i - (startOffset + daysInMonth) + 1;
        mute = true;
      } else {
        day = i - startOffset + 1;
      }
      const date = mute
        ? null
        : new Date(monthDate.getFullYear(), monthDate.getMonth(), day);
      const key = date ? date.toISOString().slice(0, 10) : '';
      const todays = events.filter((ev) => (ev.starts_at || '').slice(0, 10) === key);
      cells.push({ day, mute, date, todays, today: date && sameDay(date, new Date()) });
    }
    return cells;
  }

  function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }

  async function renderList() {
    const root = document.getElementById('orgamsic-cal-root');
    const m = state.month;
    const cells = monthCells(m, state.events);
    let grid = WEEKDAYS.map((d) => '<div class="oc-dow">' + d + '</div>').join('');
    cells.forEach((c) => {
      grid += '<div class="oc-day' + (c.mute ? ' oc-mute' : '') + (c.today ? ' oc-today' : '') + '">'
        + '<div class="oc-num">' + c.day + '</div>'
        + c.todays.map((ev) => '<a class="oc-pill" href="#orgasmic-event-' + ev.id + '">' + escapeHtml(ev.title) + '</a>').join('')
        + '</div>';
    });

    const cards = state.events.map((ev) => {
      const img = ev.image_url
        ? '<img src="' + escapeHtml(ev.image_url) + '" alt="">'
        : '<div class="orgamsic-cal-cover"></div>';
      return '<a class="orgamsic-cal-card" href="#orgasmic-event-' + ev.id + '">'
        + img
        + '<div class="oc-body"><div class="orgamsic-cal-meta">' + escapeHtml(fmtDate(ev.starts_at, ev.timezone)) + '</div>'
        + '<h3>' + escapeHtml(ev.title) + '</h3>'
        + '<div class="orgamsic-cal-spaces">' + spacesHtml(ev.spaces) + '</div>'
        + '<p class="oc-sub">' + escapeHtml(ev.excerpt || '') + '</p>'
        + '<p class="oc-sub">' + (ev.rsvp.counts.going || 0) + ' dabei</p>'
        + '</div></a>';
    }).join('') || '<div class="orgamsic-cal-empty">Noch keine Events in deinen Kreisen.</div>';

    root.innerHTML = '';
    root.appendChild(h(shell(
      '<div class="orgamsic-cal-month">'
      + '<button type="button" class="oc-ghost" data-oc-prev>←</button>'
      + '<strong>' + MONTHS[m.getMonth()] + ' ' + m.getFullYear() + '</strong>'
      + '<button type="button" class="oc-ghost" data-oc-next>→</button>'
      + '</div>'
      + '<div class="orgamsic-cal-grid">' + grid + '</div>'
      + '<div class="orgamsic-cal-list">' + cards + '</div>'
    )));
    bindShell(root);
    root.querySelector('[data-oc-prev]').onclick = () => {
      state.month = new Date(m.getFullYear(), m.getMonth() - 1, 1);
      renderList();
    };
    root.querySelector('[data-oc-next]').onclick = () => {
      state.month = new Date(m.getFullYear(), m.getMonth() + 1, 1);
      renderList();
    };
  }

  async function renderDetail(id) {
    const root = document.getElementById('orgamsic-cal-root');
    state.error = '';
    try {
      state.event = await api('events/' + id);
    } catch (e) {
      state.error = e.message;
      root.innerHTML = '';
      root.appendChild(h(shell('')));
      bindShell(root);
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

    root.innerHTML = '';
    root.appendChild(h(shell(
      '<p><a class="oc-btn oc-ghost" href="#orgasmic-calendar">← Alle Events</a></p>'
      + '<article class="orgamsic-cal-detail">'
      + '<div class="oc-hero">' + hero + '</div>'
      + '<div class="orgamsic-cal-spaces">' + spacesHtml(ev.spaces) + '</div>'
      + '<h2>' + escapeHtml(ev.title) + '</h2>'
      + '<p>' + escapeHtml(fmtDate(ev.starts_at, ev.timezone)) + (ev.ends_at ? ' – ' + escapeHtml(fmtDate(ev.ends_at, ev.timezone)) : '') + '</p>'
      + '<div class="orgamsic-cal-desc">' + (ev.description_html || '') + '</div>'
      + (ev.rsvp_enabled ? '<div class="orgamsic-cal-rsvp">' + rsvp + '</div>' : '')
      + '<p class="oc-sub">' + (ev.rsvp.counts.going || 0) + ' Zusagen' + (ev.rsvp_capacity ? ' / ' + ev.rsvp_capacity : '') + ', ' + (ev.rsvp.counts.maybe || 0) + ' vielleicht</p>'
      + join + ' ' + manage
      + '<h3>Wer nimmt teil</h3><div class="orgamsic-cal-people">' + (people || '<p class="oc-sub">Noch niemand hat zugesagt.</p>') + '</div>'
      + '</article>'
    )));
    bindShell(root);
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

  async function renderForm(id) {
    const root = document.getElementById('orgamsic-cal-root');
    let ev = {
      title: '', description_html: '', timezone: (state.bootstrap && state.bootstrap.timezone) || 'Europe/Berlin',
      visibility: 'spaces', space_ids: [], rsvp_enabled: true, location_type: 'zoom', reminder_minutes: (state.bootstrap && state.bootstrap.default_reminders) || [1440, 60],
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
    const spaces = ((state.bootstrap && state.bootstrap.spaces) || []).map((s) => {
      const checked = (ev.space_ids || (ev.spaces || []).map((x) => x.id) || []).map(String).indexOf(String(s.id)) !== -1;
      return '<label><input type="checkbox" name="space_ids" value="' + s.id + '"' + (checked ? ' checked' : '') + '> ' + escapeHtml(s.title) + '</label>';
    }).join('');
    const zoomOpts = ['<option value="">— Zoom-Account wählen —</option>'].concat(
      state.zoomUsers.map((u) => '<option value="' + escapeHtml(u.email) + '"' + (ev.zoom_user_email === u.email ? ' selected' : '') + '>' + escapeHtml(u.display_name + ' · ' + u.email) + '</option>')
    ).join('');

    root.innerHTML = '';
    root.appendChild(h(shell(
      '<p><a class="oc-btn oc-ghost" href="#orgasmic-calendar">← Zurück</a></p>'
      + '<form class="orgamsic-cal-form" id="oc-form">'
      + '<label>Name<input name="title" required value="' + escapeHtml(ev.title || '') + '"></label>'
      + '<div class="oc-row"><label>Start<input type="datetime-local" name="starts_at" required value="' + localInput(ev.starts_at) + '"></label>'
      + '<label>Ende<input type="datetime-local" name="ends_at" value="' + localInput(ev.ends_at) + '"></label></div>'
      + '<label>Zeitzone<input name="timezone" value="' + escapeHtml(ev.timezone || '') + '"></label>'
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
      + '<label><input type="checkbox" name="rsvp_enabled" checked> RSVP aktiv</label>'
      + '<button type="submit">' + (id ? 'Speichern' : 'Event erstellen') + '</button>'
      + '</form>'
    )));
    bindShell(root);
    $('#oc-form').onsubmit = async (e) => {
      e.preventDefault();
      const form = e.target;
      const spaceIds = [...form.querySelectorAll('[name="space_ids"]:checked')].map((i) => parseInt(i.value, 10));
      const body = {
        title: form.title.value,
        description: ($('#oc-desc') && $('#oc-desc').innerHTML) || '',
        starts_at: toIso(form.starts_at.value),
        ends_at: form.ends_at.value ? toIso(form.ends_at.value) : null,
        timezone: form.timezone.value,
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
    if (location.hash !== href) location.hash = href.indexOf('#') === 0 ? href : '#orgasmic-calendar';
    else bootFromHash();
  }

  document.addEventListener('click', intercept);
  window.addEventListener('hashchange', bootFromHash);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootFromHash);
  } else {
    bootFromHash();
  }
})();
