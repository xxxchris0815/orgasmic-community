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
    recording: false,
    recordSeconds: 0,
    offline: false,
  };

  let unreadTimer = null;
  let threadTimer = null;
  let lastId = 0;
  let mediaRecorder = null;
  let mediaStream = null;
  let recordTimer = null;
  let recordChunks = [];
  let usingNativeVoice = false;
  let voiceAudio = null;
  let voiceBtn = null;
  let voiceSource = null;
  let voiceStartedAt = 0;
  let voiceDuration = 0;
  let voiceRaf = 0;
  let voiceObjectUrl = '';
  let audioCtx = null;
  const MEDIA_CACHE = 'orgasmic-chat-media-v1';
  const MAX_VOICE = cfg.maxVoiceSeconds || 90;

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

  function cacheUid() {
    return String((state.me && state.me.id) || (cfg.me && cfg.me.id) || 0);
  }

  function cacheGet(key) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function cacheSet(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      /* quota */
    }
  }

  async function mediaStore() {
    if (!('caches' in window)) return null;
    try {
      return await caches.open(MEDIA_CACHE);
    } catch (e) {
      return null;
    }
  }

  async function mediaMatch(url) {
    const cache = await mediaStore();
    if (!cache || !url) return null;
    try {
      return await cache.match(url);
    } catch (e) {
      return null;
    }
  }

  async function mediaPut(url, buffer, type) {
    const cache = await mediaStore();
    if (!cache || !url || !buffer) return;
    try {
      await cache.put(url, new Response(buffer, {
        headers: { 'Content-Type': type || 'application/octet-stream' },
      }));
    } catch (e) {}
  }

  async function mediaBytes(url) {
    if (!url) return null;
    const hit = await mediaMatch(url);
    if (hit) {
      return {
        buffer: await hit.arrayBuffer(),
        type: (hit.headers.get('Content-Type') || '').split(';')[0],
      };
    }
    const res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) return null;
    const type = (res.headers.get('content-type') || '').split(';')[0];
    const buffer = await res.arrayBuffer();
    await mediaPut(url, buffer, type);
    return { buffer: buffer, type: type };
  }

  function prefetchAttachments(messages) {
    (messages || []).forEach((msg) => {
      const url = msg && msg.attachment && msg.attachment.url;
      if (!url) return;
      mediaMatch(url).then((hit) => {
        if (hit) return;
        mediaBytes(url).catch(() => {});
      });
    });
  }

  function hydrateImages() {
    document.querySelectorAll('#orgasmic-chat-root img.och-photo').forEach(async (img) => {
      const url = img.getAttribute('data-och-src') || img.getAttribute('src');
      if (!url) return;
      img.setAttribute('data-och-src', url);
      const hit = await mediaMatch(url);
      if (!hit) return;
      const blob = await hit.blob();
      img.src = URL.createObjectURL(blob);
    });
  }

  function getAudioCtx() {
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) return null;
    if (!audioCtx) audioCtx = new Ctor();
    return audioCtx;
  }

  function audioMime(type) {
    const t = String(type || '').toLowerCase();
    if (!t || t === 'video/webm' || t === 'application/octet-stream') return 'audio/webm';
    return t;
  }

  function setOffline(on) {
    state.offline = !!on;
    const el = $('[data-och-offline]');
    if (el) el.hidden = !state.offline;
  }

  function nativePlugins() {
    return (window.Capacitor && window.Capacitor.Plugins) || {};
  }

  function attachPreview(att) {
    if (!att) return '';
    if (att.kind === 'audio' || (att.mime && (String(att.mime).indexOf('audio/') === 0 || att.mime === 'video/webm'))) {
      return '🎤 Sprachnachricht';
    }
    return '📷 Bild';
  }

  function fmtDuration(sec) {
    const n = Math.max(0, parseInt(sec, 10) || 0);
    return Math.floor(n / 60) + ':' + String(n % 60).padStart(2, '0');
  }

  function base64ToFile(b64, mime, name) {
    const raw = atob(b64);
    const bytes = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i += 1) bytes[i] = raw.charCodeAt(i);
    return new File([bytes], name, { type: mime || 'application/octet-stream' });
  }

  function badgeText(n) {
    if (n < 1) return '';
    return n > 99 ? '99+' : String(n);
  }

  function paintNavIcons() {
    const svg = cfg.navIcon;
    if (!svg) return;
    document.querySelectorAll('.orgasmic-chat-nav a, a[data-orgasmic-chat], a[href*="#orgasmic-chat"]').forEach((host) => {
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

  function updateNavBadge(total) {
    state.unread = total;
    const nodes = document.querySelectorAll('.orgasmic-chat-nav, a[data-orgasmic-chat], a[href*="#orgasmic-chat"]');
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
    stopVoicePlayback();
    cancelVoice(true);
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
      const preview = last.preview || last.body || (last.attachment ? attachPreview(last.attachment) : 'Noch keine Nachrichten');
      const who = last.author && last.author.display_name ? last.author.display_name + ': ' : '';
      return '<div class="orgasmic-chat-room' + (state.spaceId === room.space_id ? ' is-active' : '') + '" role="button" tabindex="0" data-och-room="' + room.space_id + '">'
        + avatarHtml(room.logo, room.title, 'och-avatar')
        + '<span class="och-copy"><span class="och-name">' + escapeHtml(room.title) + '</span>'
        + '<span class="och-preview">' + escapeHtml((who + preview).slice(0, 90)) + '</span></span>'
        + '<span class="och-meta"><span class="och-time">' + escapeHtml(fmtTime(last.created_at)) + '</span>'
        + (room.unread > 0 ? '<span class="och-unread">' + escapeHtml(badgeText(room.unread)) + '</span>' : '')
        + '</span></div>';
    }).join('');
  }

  function sameBurst(prev, msg) {
    return !!(prev && prev.user_id === msg.user_id && fmtDay(prev.created_at) === fmtDay(msg.created_at));
  }

  function iconSvg(path) {
    return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
  }

  function sendIcon() {
    return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3 10.2 15 12 3 13.8z"></path></svg>';
  }

  function playIcon() {
    return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M8 5.2v13.6L19 12z"></path></svg>';
  }

  function pauseIcon() {
    return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M7 5h4v14H7zm6 0h4v14h-4z"></path></svg>';
  }

  function voicePlayerHtml(url, duration) {
    return '<div class="och-voice">'
      + '<button type="button" class="och-voice-play" data-och-play="' + escapeHtml(url) + '" aria-label="Abspielen">' + playIcon() + '</button>'
      + '<span class="och-voice-track" aria-hidden="true"><i></i></span>'
      + '<span class="och-voice-dur" data-och-voice-dur>' + escapeHtml(fmtDuration(duration || 0)) + '</span>'
      + '</div>';
  }

  function stopVoicePlayback() {
    if (voiceRaf) {
      cancelAnimationFrame(voiceRaf);
      voiceRaf = 0;
    }
    if (voiceSource) {
      try { voiceSource.stop(); } catch (e) {}
      voiceSource = null;
    }
    if (voiceAudio) {
      try { voiceAudio.pause(); } catch (e) {}
      if (voiceAudio.parentNode) voiceAudio.parentNode.removeChild(voiceAudio);
      voiceAudio = null;
    }
    if (voiceObjectUrl) {
      URL.revokeObjectURL(voiceObjectUrl);
      voiceObjectUrl = '';
    }
    if (voiceBtn) {
      voiceBtn.classList.remove('is-playing');
      voiceBtn.setAttribute('aria-label', 'Abspielen');
      voiceBtn.innerHTML = playIcon();
      const track = voiceBtn.parentElement && voiceBtn.parentElement.querySelector('.och-voice-track i');
      if (track) track.style.width = '0%';
      voiceBtn = null;
    }
  }

  function paintVoiceProgress(btn, current, total) {
    const track = btn.parentElement && btn.parentElement.querySelector('.och-voice-track i');
    const label = btn.parentElement && btn.parentElement.querySelector('[data-och-voice-dur]');
    if (track && total) track.style.width = Math.min(100, (100 * current) / total) + '%';
    if (label) label.textContent = fmtDuration(Math.max(0, Math.round(current)));
  }

  async function playViaElement(btn, buffer, mime) {
    const blob = new Blob([buffer], { type: mime });
    voiceObjectUrl = URL.createObjectURL(blob);
    const audio = document.createElement('audio');
    audio.preload = 'auto';
    audio.muted = false;
    audio.defaultMuted = false;
    audio.volume = 1;
    audio.setAttribute('playsinline', 'true');
    audio.src = voiceObjectUrl;
    const root = document.getElementById('orgasmic-chat-root');
    if (root) root.appendChild(audio);
    await audio.play();
    voiceAudio = audio;
    voiceBtn = btn;
    btn.classList.add('is-playing');
    btn.setAttribute('aria-label', 'Pause');
    btn.innerHTML = pauseIcon();
    audio.addEventListener('timeupdate', () => {
      if (voiceBtn !== btn) return;
      paintVoiceProgress(btn, audio.currentTime || 0, audio.duration || 0);
    });
    audio.addEventListener('ended', stopVoicePlayback);
    audio.addEventListener('error', () => {
      stopVoicePlayback();
      setError('Sprachnachricht konnte nicht abgespielt werden.');
    });
  }

  async function toggleVoicePlayback(btn) {
    const url = btn.getAttribute('data-och-play');
    if (!url) return;
    if (voiceBtn === btn && (voiceSource || voiceAudio)) {
      stopVoicePlayback();
      return;
    }
    stopVoicePlayback();
    setError('');
    let packed = null;
    try {
      packed = await mediaBytes(url);
    } catch (e) {
      packed = null;
    }
    if (!packed || !packed.buffer || !packed.buffer.byteLength) {
      setError('Sprachnachricht nicht gefunden.');
      return;
    }
    const mime = audioMime(packed.type);
    const ctx = getAudioCtx();
    if (ctx) {
      try {
        if (ctx.state === 'suspended') await ctx.resume();
        const decoded = await ctx.decodeAudioData(packed.buffer.slice(0));
        const src = ctx.createBufferSource();
        const gain = ctx.createGain();
        gain.gain.value = 1;
        src.buffer = decoded;
        src.connect(gain);
        gain.connect(ctx.destination);
        voiceSource = src;
        voiceBtn = btn;
        voiceDuration = decoded.duration || 0;
        voiceStartedAt = ctx.currentTime;
        btn.classList.add('is-playing');
        btn.setAttribute('aria-label', 'Pause');
        btn.innerHTML = pauseIcon();
        src.onended = () => {
          if (voiceSource === src) stopVoicePlayback();
        };
        src.start();
        const tick = () => {
          if (voiceSource !== src) return;
          paintVoiceProgress(btn, Math.max(0, ctx.currentTime - voiceStartedAt), voiceDuration);
          voiceRaf = requestAnimationFrame(tick);
        };
        voiceRaf = requestAnimationFrame(tick);
        return;
      } catch (e) {}
    }
    try {
      await playViaElement(btn, packed.buffer, mime);
    } catch (e) {
      setError('Sprachnachricht konnte nicht abgespielt werden.');
    }
  }

  function messageHtml(msg, prevDay, prevMsg) {
    const day = fmtDay(msg.created_at);
    let html = '';
    if (day && day !== prevDay) {
      html += '<div class="och-day">' + escapeHtml(day) + '</div>';
    }
    const mine = state.me && msg.user_id === state.me.id;
    const follow = (!day || day === prevDay) && sameBurst(prevMsg, msg);
    const author = msg.author || {};
    const canDelete = mine || state.canManage;
    html += '<article class="orgasmic-chat-msg' + (mine ? ' is-mine' : '') + (follow ? ' is-follow' : '') + '" data-och-msg="' + msg.id + '" data-och-uid="' + msg.user_id + '">';
    if (!mine && !follow) html += avatarHtml(author.avatar, author.display_name, 'och-avatar');
    else if (!mine) html += '<span class="och-avatar och-avatar-spacer" aria-hidden="true"></span>';
    html += '<div class="och-bubble">';
    if (!mine && !follow) html += '<div class="och-author">' + escapeHtml(author.display_name || 'Mitglied') + '</div>';
    if (msg.body) html += '<div class="och-text">' + linkify(msg.body) + '</div>';
    if (msg.attachment && (msg.attachment.kind === 'audio' || (msg.attachment.mime && String(msg.attachment.mime).indexOf('audio/') === 0) || msg.attachment.mime === 'video/webm')) {
      html += voicePlayerHtml(msg.attachment.url, msg.attachment.duration);
    } else if (msg.attachment && (msg.attachment.thumb || msg.attachment.url)) {
      html += '<a href="' + escapeHtml(msg.attachment.url || msg.attachment.thumb) + '" target="_blank" rel="noopener">'
        + '<img class="och-photo" data-och-src="' + escapeHtml(msg.attachment.thumb || msg.attachment.url) + '" src="' + escapeHtml(msg.attachment.thumb || msg.attachment.url) + '" alt="" />'
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
    let prevMsg = null;
    return state.messages.map((msg) => {
      const built = messageHtml(msg, lastDay, prevMsg);
      lastDay = built.day;
      prevMsg = msg;
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
    let prevMsg = null;
    const lastArt = scroller.querySelector('article.orgasmic-chat-msg:last-of-type');
    if (lastArt) {
      const id = parseInt(lastArt.getAttribute('data-och-msg'), 10);
      prevMsg = state.messages.find((m) => m.id === id) || null;
    }
    const wrap = document.createElement('div');
    items.forEach((msg) => {
      const built = messageHtml(msg, day, prevMsg);
      day = built.day;
      prevMsg = msg;
      wrap.insertAdjacentHTML('beforeend', built.html);
    });
    while (wrap.firstChild) scroller.appendChild(wrap.firstChild);
    if (stick) scroller.scrollTop = scroller.scrollHeight;
    prefetchAttachments(items);
    hydrateImages();
  }

  function refreshRooms() {
    const list = $('[data-och-rooms]');
    if (!list) return;
    list.innerHTML = roomsHtml();
  }

  function setSending(on) {
    state.sending = on;
    const btn = $('[data-och-send-btn]');
    if (btn) {
      btn.disabled = on;
      btn.setAttribute('aria-label', on ? 'Senden…' : 'Senden');
      btn.innerHTML = sendIcon();
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
    if (state.recording) {
      html += '<div class="och-record"><span class="och-rec-dot" aria-hidden="true"></span><span>Aufnahme ' + escapeHtml(fmtDuration(state.recordSeconds)) + '</span>'
        + '<button type="button" class="och-ghost" data-och-voice-stop>Fertig</button>'
        + '<button type="button" class="och-ghost" data-och-voice-cancel>Abbrechen</button></div>';
    } else if (state.pendingImage && (state.pendingImage.kind === 'audio' || (state.pendingImage.mime && String(state.pendingImage.mime).indexOf('audio/') === 0))) {
      html += '<div class="och-pending och-pending-voice">' + voicePlayerHtml(state.pendingImage.url, state.pendingImage.duration)
        + '<span>Sprachnachricht' + (state.pendingImage.duration ? ' · ' + fmtDuration(state.pendingImage.duration) : '') + '</span>'
        + '<button type="button" class="och-ghost" data-och-clear-image>Entfernen</button></div>';
    } else if (state.pendingImage) {
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
      + '<button type="button" class="och-icon-btn" data-och-emoji-toggle title="Emoji" aria-label="Emoji">' + iconSvg('<circle cx="12" cy="12" r="9"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"></path>') + '</button>'
      + '<button type="button" class="och-icon-btn" data-och-image title="Bild" aria-label="Bild">' + iconSvg('<rect x="4" y="5" width="16" height="14" rx="2"></rect><circle cx="9" cy="10" r="1.4"></circle><path d="m8 16 3-3 2 2 3-4 3 5"></path>') + '</button>'
      + '<button type="button" class="och-icon-btn' + (state.recording ? ' is-rec' : '') + '" data-och-voice title="Sprachnachricht" aria-label="Sprachnachricht">' + iconSvg('<path d="M12 15a3 3 0 0 0 3-3V8a3 3 0 0 0-6 0v4a3 3 0 0 0 3 3z"></path><path d="M19 12a7 7 0 0 1-14 0M12 19v2"></path>') + '</button>'
      + '</div>'
      + '<textarea name="body" maxlength="' + (state.portal.maxLength || 2000) + '" placeholder="Nachricht" rows="1"></textarea>'
      + '<button type="submit" class="och-send" data-och-send-btn aria-label="Senden"' + (state.sending ? ' disabled' : '') + '>' + sendIcon() + '</button>'
      + '</form><input type="file" accept="image/*" hidden data-och-file /></div>';
  }

  function render() {
    const root = document.getElementById('orgasmic-chat-root');
    if (!root || root.hidden) return;
    const room = roomById(state.spaceId);
    const prev = $('#och-scroll', root);
    const stick = !prev || (prev.scrollHeight - prev.scrollTop - prev.clientHeight < 80);

    root.innerHTML = '<div class="orgasmic-chat-overlay"><div class="orgasmic-chat' + (state.spaceId ? ' is-thread' : '') + '">'
      + '<p class="orgasmic-chat-offline" data-och-offline' + (state.offline ? '' : ' hidden') + '>Offline — zuletzt geladene Nachrichten.</p>'
      + '<div class="orgasmic-chat-body">'
      + '<aside class="orgasmic-chat-rooms">'
      + '<header class="orgasmic-chat-list-top"><div><h1>Chats</h1>'
      + '<p class="och-sub" data-och-subtitle' + (state.portal.subtitle ? '' : ' hidden') + '>' + escapeHtml(state.portal.subtitle || '') + '</p>'
      + '</div><button type="button" class="och-icon-btn" data-och-close aria-label="Schließen" title="Schließen">'
      + iconSvg('<path d="M6 6l12 12M18 6 6 18"></path>') + '</button></header>'
      + '<div class="orgasmic-chat-search"><input type="search" value="' + escapeHtml(state.query) + '" placeholder="Suchen" data-och-search /></div>'
      + '<div class="orgasmic-chat-roomlist" data-och-rooms>' + roomsHtml() + '</div>'
      + '</aside>'
      + '<section class="orgasmic-chat-thread">'
      + (room
        ? '<div class="orgasmic-chat-thread-top">'
          + '<button type="button" class="och-icon-btn orgasmic-chat-back" data-och-back aria-label="Zurück">' + iconSvg('<path d="M15 6 9 12l6 6"></path>') + '</button>'
          + avatarHtml(room.logo, room.title, 'och-avatar')
          + '<div class="och-thread-who"><h2>' + escapeHtml(room.title) + '</h2><p class="och-sub">Kreis</p></div>'
          + '<button type="button" class="och-icon-btn orgasmic-chat-thread-close" data-och-close aria-label="Schließen">'
          + iconSvg('<path d="M6 6l12 12M18 6 6 18"></path>') + '</button></div>'
          + '<div class="orgasmic-chat-messages" id="och-scroll">' + messagesHtml() + '</div>'
          + composerHtml(room)
        : '<div class="orgasmic-chat-placeholder">Wähle links einen Kreis, um zu schreiben.</div>')
      + '</section></div></div></div>';

    applyTheme(root);
    composerExtras();
    if (state.error) setError(state.error);
    const scroller = $('#och-scroll', root);
    if (scroller && stick) scroller.scrollTop = scroller.scrollHeight;
    const box = $('textarea[name="body"]', root);
    if (box && state.spaceId) box.focus();
    prefetchAttachments(state.messages);
    hydrateImages();
  }

  async function loadRooms() {
    try {
      const data = await api('rooms');
      state.rooms = data.rooms || [];
      state.me = data.me || state.me;
      state.canManage = !!data.can_manage;
      applyPortal(data.portal);
      updateNavBadge(data.unread || 0);
      cacheSet('orgasmic-chat-rooms:' + cacheUid(), {
        rooms: state.rooms,
        me: state.me,
        can_manage: state.canManage,
        portal: data.portal || null,
        unread: data.unread || 0,
      });
      setOffline(false);
    } catch (err) {
      const cached = cacheGet('orgasmic-chat-rooms:' + cacheUid());
      if (cached && Array.isArray(cached.rooms) && cached.rooms.length) {
        state.rooms = cached.rooms;
        if (cached.me) state.me = cached.me;
        if (typeof cached.can_manage !== 'undefined') state.canManage = !!cached.can_manage;
        applyPortal(cached.portal);
        updateNavBadge(cached.unread || 0);
        setOffline(true);
        return;
      }
      throw err;
    }
  }

  async function loadMessages(reset) {
    if (!state.spaceId) return [];
    const path = reset
      ? 'rooms/' + state.spaceId + '/messages?limit=50'
      : 'rooms/' + state.spaceId + '/messages?after=' + lastId + '&limit=50';
    try {
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
      cacheSet('orgasmic-chat-msgs:' + cacheUid() + ':' + state.spaceId, state.messages.slice(-50));
      prefetchAttachments(reset ? state.messages : incoming);
      setOffline(false);
      await api('rooms/' + state.spaceId + '/read', {
        method: 'POST',
        body: JSON.stringify({ last_id: lastId || data.latest_id || 0 }),
      }).then((res) => {
        const room = roomById(state.spaceId);
        if (room) room.unread = 0;
        if (typeof res.unread === 'number') updateNavBadge(res.unread);
      }).catch(() => {});
      return incoming;
    } catch (err) {
      if (!reset) {
        setOffline(true);
        throw err;
      }
      const cached = cacheGet('orgasmic-chat-msgs:' + cacheUid() + ':' + state.spaceId);
      if (cached && Array.isArray(cached) && cached.length) {
        state.messages = cached;
        if (state.messages.length) lastId = state.messages[state.messages.length - 1].id;
        setOffline(true);
        return [];
      }
      throw err;
    }
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
    if (state.spaceId !== (route.spaceId || 0)) stopVoicePlayback();
    state.spaceId = route.spaceId || 0;
    state.error = '';
    state.emojiOpen = false;
    state.pendingImage = null;
    cancelVoice(true);
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
    if (state.recording) {
      try {
        await stopVoice();
      } catch (e) {
        setError(e.message || 'Aufnahme fehlgeschlagen.');
        return;
      }
    }
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

  async function uploadFile(file, extra) {
    const data = new FormData();
    data.append('file', file);
    if (extra && extra.duration) data.append('duration', String(extra.duration));
    const uploaded = await api('upload', { method: 'POST', body: data });
    state.pendingImage = uploaded;
    if (uploaded && uploaded.url && file && file.arrayBuffer) {
      file.arrayBuffer().then((buf) => mediaPut(uploaded.url, buf, file.type || uploaded.mime)).catch(() => {});
    }
    composerExtras();
    return uploaded;
  }

  async function pickNativeImage() {
    const Camera = nativePlugins().Camera;
    if (!Camera || !Camera.getPhoto) return null;
    const photo = await Camera.getPhoto({
      quality: 80,
      resultType: 'base64',
      source: 'PROMPT',
      width: 1600,
    });
    if (!photo || !photo.base64String) return null;
    const format = (photo.format || 'jpeg').replace('jpg', 'jpeg');
    return base64ToFile(photo.base64String, 'image/' + format, 'photo.' + (format === 'jpeg' ? 'jpg' : format));
  }

  async function chooseImage() {
    try {
      const file = await pickNativeImage();
      if (file) {
        await uploadFile(file);
        return;
      }
    } catch (e) {
      if (e && (e.message === 'User cancelled photos app' || e.message === 'canceled')) return;
    }
    const input = $('[data-och-file]');
    if (input) input.click();
  }

  function tickRecord() {
    state.recordSeconds += 1;
    const label = $('.och-record span:not(.och-rec-dot)');
    if (label) label.textContent = 'Aufnahme ' + fmtDuration(state.recordSeconds);
    if (state.recordSeconds >= MAX_VOICE) {
      stopVoice().catch((e) => setError(e.message || 'Aufnahme fehlgeschlagen.'));
    }
  }

  async function startVoice() {
    if (state.recording || state.sending) return;
    setError('');
    state.recordSeconds = 0;
    recordChunks = [];
    usingNativeVoice = false;

    const VoiceRecorder = nativePlugins().VoiceRecorder;
    if (VoiceRecorder && VoiceRecorder.startRecording) {
      if (VoiceRecorder.requestAudioRecordingPermission) {
        const perm = await VoiceRecorder.requestAudioRecordingPermission();
        if (perm && perm.value === false) throw new Error('Mikrofon nicht erlaubt.');
      }
      await VoiceRecorder.startRecording();
      usingNativeVoice = true;
      state.recording = true;
      composerExtras();
      recordTimer = setInterval(tickRecord, 1000);
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      throw new Error('Sprachnachrichten braucht Mikrofonzugriff (HTTPS) oder die Capacitor-App.');
    }
    mediaStream = await navigator.mediaDevices.getUserMedia({
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
        channelCount: 1,
      },
    });
    const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'].find((t) => MediaRecorder.isTypeSupported(t)) || '';
    const recOpts = { audioBitsPerSecond: 96000 };
    if (mime) recOpts.mimeType = mime;
    mediaRecorder = new MediaRecorder(mediaStream, recOpts);
    recordChunks = [];
    mediaRecorder.ondataavailable = (ev) => {
      if (ev.data && ev.data.size) recordChunks.push(ev.data);
    };
    mediaRecorder.start(250);
    state.recording = true;
    composerExtras();
    recordTimer = setInterval(tickRecord, 1000);
  }

  async function stopVoice() {
    if (!state.recording) return;
    if (recordTimer) {
      clearInterval(recordTimer);
      recordTimer = null;
    }
    const seconds = state.recordSeconds || 1;
    state.recording = false;
    composerExtras();

    if (usingNativeVoice) {
      usingNativeVoice = false;
      const VoiceRecorder = nativePlugins().VoiceRecorder;
      const res = VoiceRecorder && VoiceRecorder.stopRecording ? await VoiceRecorder.stopRecording() : null;
      const value = (res && res.value) || res || {};
      const b64 = value.recordDataBase64 || value.base64 || '';
      if (!b64) throw new Error('Keine Aufnahme.');
      const mime = value.mimeType || 'audio/aac';
      const ext = mime.indexOf('webm') >= 0 ? 'webm' : (mime.indexOf('ogg') >= 0 ? 'ogg' : 'm4a');
      const file = base64ToFile(b64, mime, 'voice.' + ext);
      const duration = value.msDuration ? Math.round(value.msDuration / 1000) : seconds;
      await uploadFile(file, { duration: duration });
      return;
    }

    await new Promise((resolve, reject) => {
      if (!mediaRecorder) {
        resolve();
        return;
      }
      mediaRecorder.onstop = () => resolve();
      mediaRecorder.onerror = () => reject(new Error('Aufnahme fehlgeschlagen.'));
      if (mediaRecorder.state !== 'inactive') mediaRecorder.stop();
      else resolve();
    });
    if (mediaStream) {
      mediaStream.getTracks().forEach((t) => t.stop());
      mediaStream = null;
    }
    const type = (mediaRecorder && mediaRecorder.mimeType) || 'audio/webm';
    mediaRecorder = null;
    const blob = new Blob(recordChunks, { type: type.split(';')[0] });
    recordChunks = [];
    if (!blob.size) throw new Error('Keine Aufnahme.');
    const ext = type.indexOf('mp4') >= 0 ? 'm4a' : (type.indexOf('ogg') >= 0 ? 'ogg' : 'webm');
    const file = new File([blob], 'voice.' + ext, { type: blob.type });
    await uploadFile(file, { duration: seconds });
  }

  function cancelVoice(silent) {
    if (recordTimer) {
      clearInterval(recordTimer);
      recordTimer = null;
    }
    state.recording = false;
    state.recordSeconds = 0;
    if (usingNativeVoice) {
      usingNativeVoice = false;
      const VoiceRecorder = nativePlugins().VoiceRecorder;
      if (VoiceRecorder && VoiceRecorder.stopRecording) {
        VoiceRecorder.stopRecording().catch(() => {});
      }
    }
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      try { mediaRecorder.stop(); } catch (e) {}
    }
    mediaRecorder = null;
    if (mediaStream) {
      mediaStream.getTracks().forEach((t) => t.stop());
      mediaStream = null;
    }
    recordChunks = [];
    if (!silent) composerExtras();
  }

  document.addEventListener('click', (ev) => {
    const play = ev.target.closest('[data-och-play]');
    if (play) {
      ev.preventDefault();
      toggleVoicePlayback(play);
      return;
    }
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
      chooseImage().catch((e) => setError(e.message || 'Bild fehlgeschlagen.'));
      return;
    }
    const voiceBtn = ev.target.closest('[data-och-voice]');
    if (voiceBtn) {
      ev.preventDefault();
      if (state.recording) {
        stopVoice().catch((e) => setError(e.message || 'Aufnahme fehlgeschlagen.'));
      } else {
        startVoice().catch((e) => setError(e.message || 'Mikrofon nicht verfügbar.'));
      }
      return;
    }
    const voiceStop = ev.target.closest('[data-och-voice-stop]');
    if (voiceStop) {
      ev.preventDefault();
      stopVoice().catch((e) => setError(e.message || 'Aufnahme fehlgeschlagen.'));
      return;
    }
    const voiceCancel = ev.target.closest('[data-och-voice-cancel]');
    if (voiceCancel) {
      ev.preventDefault();
      cancelVoice();
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
    uploadFile(file).catch((e) => setError(e.message || 'Upload fehlgeschlagen.'));
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
    const room = ev.target.closest && ev.target.closest('[data-och-room]');
    if (room && (ev.key === 'Enter' || ev.key === ' ')) {
      ev.preventDefault();
      location.hash = '#orgasmic-chat-' + room.getAttribute('data-och-room');
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
  watchNavIcons();
  pollUnread();
  if (hashRoute()) bootFromHash();
})();
