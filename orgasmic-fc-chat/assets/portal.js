(function () {
  const cfg = window.OrgasmicFcChat || {};
  if (!cfg.root) return;

  const EMOJI = ['😀', '😍', '🔥', '❤️', '😂', '👍', '🙏', '🎉', '😢', '😮', '🤝', '✨', '💋', '🥵', '🌙', '✅'];

  const state = {
    rooms: [],
    messages: [],
    spaceId: 0,
    me: cfg.me || { id: 0 },
    portal: {
      subtitle: cfg.subtitle || '',
      appearance: cfg.appearance || 'auto',
      accent: cfg.accent || '',
      bg: cfg.bg || '',
      text: cfg.text || '',
      card: cfg.card || '',
      mine: cfg.mine || '',
      theirs: cfg.theirs || '',
      maxLength: cfg.maxLength || 2000,
    },
    unread: cfg.unread || 0,
    canManage: !!cfg.canManage,
    query: '',
    error: '',
    sending: false,
    emojiOpen: false,
    pendingImage: null,
  };

  let unreadTimer = null;
  let threadTimer = null;
  let lastId = 0;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function linkify(str) {
    const escaped = escapeHtml(str);
    return escaped.replace(/(https?:\/\/[^\s<]+)/g, (url) => {
      if (!/^https?:\/\//i.test(url)) return url;
      return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
    });
  }

  function initial(name) {
    const text = String(name || '?').trim();
    return escapeHtml(text.slice(0, 1).toUpperCase() || '?');
  }

  function avatarHtml(url, name, cls) {
    if (url) {
      return '<img class="' + cls + '" src="' + escapeHtml(url) + '" alt="" />';
    }
    return '<span class="' + cls + '" aria-hidden="true">' + initial(name) + '</span>';
  }

  function fmtTime(iso) {
    if (!iso) return '';
    const date = parseUtc(iso);
    if (!date) return '';
    return new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit' }).format(date);
  }

  function fmtDay(iso) {
    const date = parseUtc(iso);
    if (!date) return '';
    return new Intl.DateTimeFormat('de-DE', {
      weekday: 'short',
      day: '2-digit',
      month: 'short',
    }).format(date);
  }

  function parseUtc(iso) {
    if (!iso) return null;
    const raw = String(iso).includes('T') ? iso : String(iso).replace(' ', 'T') + 'Z';
    const date = new Date(raw);
    return Number.isNaN(date.getTime()) ? null : date;
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

  function applyPortal(data) {
    if (!data) return;
    ['subtitle', 'appearance', 'accent', 'bg', 'text', 'card', 'mine', 'theirs'].forEach((key) => {
      if (Object.prototype.hasOwnProperty.call(data, key)) state.portal[key] = data[key] || '';
    });
    if (data.max_length) state.portal.maxLength = data.max_length;
    const sub = $('[data-och-subtitle]');
    if (sub) {
      sub.textContent = state.portal.subtitle || '';
      sub.hidden = !state.portal.subtitle;
    }
    const root = document.getElementById('orgasmic-chat-root');
    if (root && !root.hidden) applyTheme(root);
  }

  function applyTheme(root) {
    const cs = getComputedStyle(document.body);
    root.style.setProperty('--och-portal-bg', solidBackground());
    root.style.setProperty('--och-portal-text', cs.color || '#1d2327');
    const overlay = root.querySelector('.orgasmic-chat-overlay');
    if (!overlay) return;
    overlay.classList.remove('och-theme-auto', 'och-theme-light', 'och-theme-dark');
    overlay.classList.add('och-theme-' + (state.portal.appearance || 'auto'));
    const map = {
      accent: '--och-accent-set',
      bg: '--och-bg',
      text: '--och-text',
      card: '--och-card',
      mine: '--och-mine',
      theirs: '--och-theirs',
    };
    Object.keys(map).forEach((key) => {
      if (state.portal[key]) overlay.style.setProperty(map[key], state.portal[key]);
      else overlay.style.removeProperty(map[key]);
    });
  }

  async function api(path, options) {
    const opts = Object.assign({
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': cfg.nonce,
        Accept: 'application/json',
      },
    }, options || {});
    if (opts.body && !(opts.body instanceof FormData) && !opts.headers['Content-Type']) {
      opts.headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(cfg.root + path.replace(/^\//, ''), opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(data.message || 'Fehler ' + res.status);
    }
    return data;
  }

  function badgeText(n) {
    if (n < 1) return '';
    return n > 99 ? '99+' : String(n);
  }

  function updateNavBadge(total) {
    state.unread = total;
    const nodes = document.querySelectorAll('.orgasmic-chat-nav, a[data-orgasmic-chat], a[href="#orgasmic-chat"]');
    nodes.forEach((el) => {
      const node = el.classList && el.classList.contains('orgasmic-chat-nav') ? el : el.closest('.orgasmic-chat-nav') || el;
      node.classList.toggle('has-unread', total > 0);
      const link = node.tagName === 'A' ? node : node.querySelector('a') || node;
      if (!link) return;
      let badge = link.querySelector('[data-orgasmic-chat-badge]');
      if (total > 0) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'och-nav-badge';
          badge.setAttribute('data-orgasmic-chat-badge', '1');
          link.appendChild(badge);
        }
        badge.textContent = badgeText(total);
      } else if (badge) {
        badge.remove();
      }
      if (link.getAttribute('aria-label') !== null || node.classList.contains('orgasmic-chat-nav')) {
        link.setAttribute('aria-label', total > 0 ? 'Chat, ' + total + ' ungelesen' : 'Chat');
      }
    });
  }

  function hashRoute() {
    const hash = location.hash || '';
    if (hash === '#orgasmic-chat') return { spaceId: 0 };
    const match = hash.match(/^#orgasmic-chat-(\d+)$/);
    if (match) return { spaceId: parseInt(match[1], 10) };
    return null;
  }

  function openOverlay() {
    const root = document.getElementById('orgasmic-chat-root');
    if (!root) return;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    document.documentElement.style.overflow = 'hidden';
  }

  function closeOverlay() {
    const root = document.getElementById('orgasmic-chat-root');
    if (!root) return;
    root.hidden = true;
    root.innerHTML = '';
    document.body.style.overflow = '';
    document.documentElement.style.overflow = '';
    state.spaceId = 0;
    state.messages = [];
    lastId = 0;
    stopThreadPoll();
    if ((location.hash || '').indexOf('orgasmic-chat') === 1) {
      history.replaceState(null, '', location.pathname + location.search);
    }
  }

  function roomById(id) {
    return state.rooms.find((r) => r.space_id === id) || null;
  }

  function filteredRooms() {
    const q = state.query.trim().toLowerCase();
    if (!q) return state.rooms;
    return state.rooms.filter((r) => String(r.title || '').toLowerCase().includes(q));
  }

  function roomsHtml() {
    const rooms = filteredRooms();
    if (!rooms.length) {
      return '<div class="orgasmic-chat-empty">Keine Chats in deinen Kreisen.</div>';
    }
    return rooms.map((room) => {
      const last = room.last_message || {};
      const preview = last.preview || last.body || (last.attachment ? '📷 Bild' : 'Noch keine Nachrichten');
      const who = last.author && last.author.display_name ? last.author.display_name + ': ' : '';
      return '<button type="button" class="orgasmic-chat-room' + (state.spaceId === room.space_id ? ' is-active' : '') + '" data-och-room="' + room.space_id + '">'
        + avatarHtml(room.logo, room.title, 'och-avatar')
        + '<span><span class="och-name">' + escapeHtml(room.title) + '</span>'
        + '<span class="och-preview">' + escapeHtml((who + preview).slice(0, 90)) + '</span></span>'
        + '<span class="och-meta"><span class="och-time">' + escapeHtml(fmtTime(last.created_at)) + '</span>'
        + (room.unread > 0 ? '<span class="och-unread">' + escapeHtml(badgeText(room.unread)) + '</span>' : '')
        + '</span></button>';
    }).join('');
  }

  function messageHtml(msg, prevDay) {
    const day = fmtDay(msg.created_at);
    let html = '';
    if (day && day !== prevDay) {
      html += '<div class="och-day">' + escapeHtml(day) + '</div>';
    }
    const mine = state.me && msg.user_id === state.me.id;
    const author = msg.author || {};
    const canDelete = mine || state.canManage;
    html += '<article class="orgasmic-chat-msg' + (mine ? ' is-mine' : '') + '" data-och-msg="' + msg.id + '">';
    if (!mine) html += avatarHtml(author.avatar, author.display_name, 'och-avatar');
    html += '<div class="och-bubble">';
    if (!mine) html += '<div class="och-author">' + escapeHtml(author.display_name || 'Mitglied') + '</div>';
    if (msg.body) html += '<div class="och-text">' + linkify(msg.body) + '</div>';
    if (msg.attachment && msg.attachment.thumb) {
      html += '<a href="' + escapeHtml(msg.attachment.url || msg.attachment.thumb) + '" target="_blank" rel="noopener">'
        + '<img class="och-photo" src="' + escapeHtml(msg.attachment.thumb) + '" alt="" />'
        + '</a>';
    }
    html += '<div class="och-foot"><span>' + escapeHtml(fmtTime(msg.created_at)) + '</span>';
    if (canDelete) html += '<button type="button" class="och-del" data-och-del="' + msg.id + '">Löschen</button>';
    html += '</div></div></article>';
    return { html: html, day: day || prevDay };
  }

  function messagesHtml() {
    if (!state.messages.length) {
      return '<div class="orgasmic-chat-empty" data-och-empty>Schreib die erste Nachricht in diesem Kreis.</div>';
    }
    let lastDay = '';
    return state.messages.map((msg) => {
      const built = messageHtml(msg, lastDay);
      lastDay = built.day;
      return built.html;
    }).join('');
  }

  function lastRenderedDay() {
    const days = document.querySelectorAll('#och-scroll .och-day');
    return days.length ? days[days.length - 1].textContent : '';
  }

  function appendMessages(items, stick) {
    const scroller = $('#och-scroll');
    if (!scroller || !items.length) return;
    const empty = scroller.querySelector('[data-och-empty]');
    if (empty) empty.remove();
    let day = lastRenderedDay();
    const wrap = document.createElement('div');
    items.forEach((msg) => {
      const built = messageHtml(msg, day);
      day = built.day;
      wrap.insertAdjacentHTML('beforeend', built.html);
    });
    while (wrap.firstChild) scroller.appendChild(wrap.firstChild);
    if (stick) scroller.scrollTop = scroller.scrollHeight;
  }

  function refreshRooms() {
    const aside = $('.orgasmic-chat-rooms');
    if (!aside) return;
    const search = aside.querySelector('.orgasmic-chat-search');
    aside.querySelectorAll('.orgasmic-chat-room, .orgasmic-chat-empty').forEach((n) => n.remove());
    const wrap = document.createElement('div');
    wrap.innerHTML = roomsHtml();
    while (wrap.firstChild) aside.appendChild(wrap.firstChild);
    if (search && document.activeElement !== search.querySelector('input')) {
      const input = search.querySelector('input');
      if (input && input.value !== state.query) input.value = state.query;
    }
  }

  function setSending(on) {
    state.sending = on;
    const btn = $('[data-och-send-btn]');
    if (btn) {
      btn.disabled = on;
      btn.textContent = on ? 'Senden…' : 'Senden';
    }
  }

  function setError(message) {
    state.error = message || '';
    const box = $('[data-och-error]');
    if (!box) return;
    box.textContent = state.error;
    box.hidden = !state.error;
  }

  function composerExtras() {
    const host = $('[data-och-extras]');
    if (!host) return;
    let html = '';
    if (state.emojiOpen) {
      html += '<div class="och-emoji-panel">' + EMOJI.map((e) => '<button type="button" data-och-emoji="' + e + '">' + e + '</button>').join('') + '</div>';
    }
    if (state.pendingImage) {
      html += '<div class="och-pending"><img src="' + escapeHtml(state.pendingImage.thumb || state.pendingImage.url) + '" alt="" /><span>Bild angehängt</span><button type="button" class="och-ghost" data-och-clear-image>Entfernen</button></div>';
    }
    host.innerHTML = html;
  }

  function composerHtml(room) {
    if (!room) return '';
    return '<div class="orgasmic-chat-composer">'
      + '<div data-och-extras></div>'
      + '<p class="och-sub" data-och-error hidden></p>'
      + '<form data-och-send>'
      + '<div class="orgasmic-chat-tools">'
      + '<button type="button" class="och-ghost" data-och-emoji-toggle title="Emoji">☺</button>'
      + '<button type="button" class="och-ghost" data-och-image title="Bild">🖼</button>'
      + '</div>'
      + '<textarea name="body" maxlength="' + (state.portal.maxLength || 2000) + '" placeholder="Nachricht an ' + escapeHtml(room.title) + '" rows="1"></textarea>'
      + '<button type="submit" data-och-send-btn' + (state.sending ? ' disabled' : '') + '>Senden</button>'
      + '</form><input type="file" accept="image/*" hidden data-och-file /></div>';
  }

  function render() {
    const root = document.getElementById('orgasmic-chat-root');
    if (!root || root.hidden) return;
    const room = roomById(state.spaceId);
    const prev = $('#och-scroll', root);
    const stick = !prev || (prev.scrollHeight - prev.scrollTop - prev.clientHeight < 80);

    root.innerHTML = '<div class="orgasmic-chat-overlay"><div class="orgasmic-chat' + (state.spaceId ? ' is-thread' : '') + '">'
      + '<header class="orgasmic-chat-top"><div>'
      + '<p class="och-sub">ORGASMIC</p><h1>Chat</h1>'
      + '<p class="och-sub" data-och-subtitle' + (state.portal.subtitle ? '' : ' hidden') + '>' + escapeHtml(state.portal.subtitle || '') + '</p>'
      + '</div><div class="orgasmic-chat-actions">'
      + '<button type="button" class="och-close" data-och-close>Schließen</button>'
      + '</div></header>'
      + '<div class="orgasmic-chat-body">'
      + '<aside class="orgasmic-chat-rooms">'
      + '<div class="orgasmic-chat-search"><input type="search" value="' + escapeHtml(state.query) + '" placeholder="Kreis suchen" data-och-search /></div>'
      + roomsHtml()
      + '</aside>'
      + '<section class="orgasmic-chat-thread">'
      + (room
        ? '<div class="orgasmic-chat-thread-top">'
          + '<button type="button" class="och-ghost orgasmic-chat-back" data-och-back>Zurück</button>'
          + avatarHtml(room.logo, room.title, 'och-avatar')
          + '<h2>' + escapeHtml(room.title) + '</h2></div>'
          + '<div class="orgasmic-chat-messages" id="och-scroll">' + messagesHtml() + '</div>'
          + composerHtml(room)
        : '<div class="orgasmic-chat-placeholder">Wähle links einen Kreis.</div>')
      + '</section></div></div></div>';

    applyTheme(root);
    composerExtras();
    if (state.error) setError(state.error);
    const scroller = $('#och-scroll', root);
    if (scroller && stick) scroller.scrollTop = scroller.scrollHeight;
    const box = $('textarea[name="body"]', root);
    if (box && state.spaceId) box.focus();
  }

  async function loadRooms() {
    const data = await api('rooms');
    state.rooms = data.rooms || [];
    state.me = data.me || state.me;
    state.canManage = !!data.can_manage;
    applyPortal(data.portal);
    updateNavBadge(data.unread || 0);
  }

  async function loadMessages(reset) {
    if (!state.spaceId) return [];
    const path = reset
      ? 'rooms/' + state.spaceId + '/messages?limit=50'
      : 'rooms/' + state.spaceId + '/messages?after=' + lastId + '&limit=50';
    const data = await api(path);
    const items = data.items || [];
    const incoming = [];
    if (reset) {
      state.messages = items;
      if (state.messages.length) lastId = state.messages[state.messages.length - 1].id;
    } else if (items.length) {
      const seen = new Set(state.messages.map((m) => m.id));
      items.forEach((m) => {
        if (!seen.has(m.id)) {
          state.messages.push(m);
          incoming.push(m);
        }
      });
      if (state.messages.length) lastId = state.messages[state.messages.length - 1].id;
    }
    await api('rooms/' + state.spaceId + '/read', {
      method: 'POST',
      body: JSON.stringify({ last_id: lastId || data.latest_id || 0 }),
    }).then((res) => {
      const room = roomById(state.spaceId);
      if (room) room.unread = 0;
      if (typeof res.unread === 'number') updateNavBadge(res.unread);
    }).catch(() => {});
    return incoming;
  }

  function stopThreadPoll() {
    if (threadTimer) {
      clearInterval(threadTimer);
      threadTimer = null;
    }
  }

  function startThreadPoll() {
    stopThreadPoll();
    const ms = Math.max(3000, ((cfg.pollSeconds || 6) * 1000) / 2);
    threadTimer = setInterval(async () => {
      if (document.hidden || !state.spaceId) return;
      try {
        const incoming = await loadMessages(false);
        if (incoming.length) {
          const scroller = $('#och-scroll');
          const stick = !scroller || (scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 80);
          appendMessages(incoming, stick);
        }
        refreshRooms();
      } catch (e) {}
    }, ms);
  }

  async function bootFromHash() {
    const route = hashRoute();
    if (!route) {
      closeOverlay();
      return;
    }
    openOverlay();
    state.spaceId = route.spaceId || 0;
    state.error = '';
    state.emojiOpen = false;
    state.pendingImage = null;
    try {
      await loadRooms();
      if (state.spaceId && !roomById(state.spaceId)) {
        state.spaceId = 0;
      }
      if (state.spaceId) {
        lastId = 0;
        await loadMessages(true);
        startThreadPoll();
      } else {
        stopThreadPoll();
      }
    } catch (e) {
      state.error = e.message || 'Chat konnte nicht geladen werden.';
    }
    render();
  }

  async function sendMessage(body) {
    if (!state.spaceId || state.sending) return;
    const box = $('textarea[name="body"]');
    const text = String(body || (box ? box.value : '')).trim();
    if (!text && !state.pendingImage) return;
    setSending(true);
    setError('');
    try {
      const payload = { body: text };
      if (state.pendingImage && state.pendingImage.id) payload.attachment_id = state.pendingImage.id;
      const msg = await api('rooms/' + state.spaceId + '/messages', {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      if (box) {
        box.value = '';
        box.style.height = '';
      }
      state.pendingImage = null;
      state.emojiOpen = false;
      composerExtras();
      if (msg && msg.id && !state.messages.some((m) => m.id === msg.id)) {
        state.messages.push(msg);
        lastId = Math.max(lastId, msg.id);
        appendMessages([msg], true);
      }
      await loadRooms();
      refreshRooms();
    } catch (e) {
      setError(e.message || 'Senden fehlgeschlagen.');
    }
    setSending(false);
    if (box) box.focus();
  }

  async function uploadImage(file) {
    const data = new FormData();
    data.append('file', file);
    const uploaded = await api('upload', { method: 'POST', body: data });
    state.pendingImage = uploaded;
    composerExtras();
  }

  document.addEventListener('click', (ev) => {
    const close = ev.target.closest('[data-och-close]');
    if (close) {
      ev.preventDefault();
      closeOverlay();
      return;
    }
    const back = ev.target.closest('[data-och-back]');
    if (back) {
      ev.preventDefault();
      location.hash = '#orgasmic-chat';
      return;
    }
    const room = ev.target.closest('[data-och-room]');
    if (room) {
      ev.preventDefault();
      location.hash = '#orgasmic-chat-' + room.getAttribute('data-och-room');
      return;
    }
    const emojiToggle = ev.target.closest('[data-och-emoji-toggle]');
    if (emojiToggle) {
      ev.preventDefault();
      state.emojiOpen = !state.emojiOpen;
      composerExtras();
      const box = $('textarea[name="body"]');
      if (box) box.focus();
      return;
    }
    const emoji = ev.target.closest('[data-och-emoji]');
    if (emoji) {
      ev.preventDefault();
      const box = $('textarea[name="body"]');
      if (box) {
        box.value += emoji.getAttribute('data-och-emoji');
        box.focus();
      }
      return;
    }
    const imageBtn = ev.target.closest('[data-och-image]');
    if (imageBtn) {
      ev.preventDefault();
      const input = $('[data-och-file]');
      if (input) input.click();
      return;
    }
    const clearImage = ev.target.closest('[data-och-clear-image]');
    if (clearImage) {
      ev.preventDefault();
      state.pendingImage = null;
      composerExtras();
      return;
    }
    const del = ev.target.closest('[data-och-del]');
    if (del) {
      ev.preventDefault();
      const id = parseInt(del.getAttribute('data-och-del'), 10);
      if (!id || !window.confirm('Nachricht löschen?')) return;
      api('messages/' + id, { method: 'DELETE' }).then(() => {
        state.messages = state.messages.filter((m) => m.id !== id);
        const node = document.querySelector('[data-och-msg="' + id + '"]');
        if (node) node.remove();
      }).catch((e) => setError(e.message || 'Löschen fehlgeschlagen.'));
    }
  });

  document.addEventListener('input', (ev) => {
    if (ev.target.matches('[data-och-search]')) {
      state.query = ev.target.value;
      refreshRooms();
      return;
    }
    if (ev.target.matches('#orgasmic-chat-root textarea[name="body"]')) {
      ev.target.style.height = 'auto';
      ev.target.style.height = Math.min(140, ev.target.scrollHeight) + 'px';
    }
  });

  document.addEventListener('change', (ev) => {
    if (!ev.target.matches('[data-och-file]')) return;
    const file = ev.target.files && ev.target.files[0];
    ev.target.value = '';
    if (!file) return;
    uploadImage(file).catch((e) => setError(e.message || 'Upload fehlgeschlagen.'));
  });

  document.addEventListener('submit', (ev) => {
    const form = ev.target.closest('[data-och-send]');
    if (!form) return;
    ev.preventDefault();
    const box = form.querySelector('textarea[name="body"]');
    sendMessage(box ? box.value : '');
  });

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && hashRoute()) {
      closeOverlay();
      return;
    }
    const box = ev.target.closest && ev.target.closest('#orgasmic-chat-root textarea[name="body"]');
    if (box && ev.key === 'Enter' && !ev.shiftKey) {
      ev.preventDefault();
      sendMessage(box.value);
    }
  });

  window.addEventListener('hashchange', () => {
    if (hashRoute() || (document.getElementById('orgasmic-chat-root') && !document.getElementById('orgasmic-chat-root').hidden)) {
      bootFromHash();
    }
  });

  function pollUnread() {
    const base = Math.max(3000, (cfg.pollSeconds || 6) * 1000);
    unreadTimer = setInterval(async () => {
      if (document.hidden) return;
      try {
        const data = await api('unread');
        updateNavBadge(data.total || 0);
        if (data.rooms && state.rooms.length) {
          Object.keys(data.rooms).forEach((id) => {
            const room = roomById(parseInt(id, 10));
            if (room) room.unread = parseInt(data.rooms[id], 10) || 0;
          });
          const root = document.getElementById('orgasmic-chat-root');
          if (root && !root.hidden) refreshRooms();
        }
      } catch (e) {}
    }, base);
  }

  updateNavBadge(cfg.unread || 0);
  pollUnread();
  if (hashRoute()) bootFromHash();
})();
