(function () {
  const cfg = window.OrgasmicFcApp || {};
  if (!cfg.root) return;

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i += 1) output[i] = raw.charCodeAt(i);
    return output;
  }

  async function getJson(path) {
    const res = await fetch(cfg.root + path.replace(/^\//, ''), {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Fehler ' + res.status);
    return data;
  }

  async function postJson(path, body) {
    const res = await fetch(cfg.root + path.replace(/^\//, ''), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': cfg.nonce,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Fehler ' + res.status);
    return data;
  }

  function prefsRoot() {
    return document.getElementById('orgasmic-app-prefs');
  }

  function setPrefsStatus(message, isError) {
    const el = document.querySelector('[data-oa-prefs-status]');
    if (!el) return;
    el.textContent = message || '';
    el.hidden = !message;
    el.classList.toggle('is-error', !!isError);
  }

  function applyPrefs(prefs) {
    const form = document.querySelector('[data-oa-prefs]');
    if (!form || !prefs) return;
    ['chat', 'feed', 'comment', 'event'].forEach((key) => {
      const input = form.querySelector('[name="' + key + '"]');
      if (input) input.checked = prefs[key] !== false;
    });
  }

  function openPrefs() {
    const root = prefsRoot();
    if (!root) return;
    root.hidden = false;
    document.documentElement.classList.add('orgasmic-prefs-open');
    applyPrefs(cfg.prefs);
    getJson('prefs').then((data) => {
      cfg.prefs = data.prefs || cfg.prefs;
      applyPrefs(cfg.prefs);
    }).catch(() => {});
  }

  function closePrefs() {
    const root = prefsRoot();
    if (!root) return;
    root.hidden = true;
    document.documentElement.classList.remove('orgasmic-prefs-open');
    if ((location.hash || '') === '#orgasmic-notify') {
      history.replaceState(null, '', location.pathname + location.search);
    }
  }

  function paintNavIcons() {
    const svg = cfg.navIcon;
    if (!svg) return;
    document.querySelectorAll('.orgasmic-app-nav a, a[data-orgasmic-notify], a[href*="#orgasmic-notify"], .oa-profile-notify').forEach((host) => {
      host.querySelectorAll('.el-icon, i[class*="el-icon"]').forEach((node) => {
        if (!node.querySelector('path, rect, circle')) node.remove();
      });
      const existing = host.querySelector('svg');
      if (existing && existing.querySelector('path, rect, circle')) return;
      if (existing) existing.remove();
      host.insertAdjacentHTML('afterbegin', svg);
    });
  }

  function looksLikeProfileMenu(el) {
    const text = (el.textContent || '').replace(/\s+/g, ' ');
    return /Abmelden|Sign out|Logout|Lesezeichen|Bookmarks/i.test(text)
      && el.querySelectorAll('a, button').length >= 2;
  }

  function injectProfileNotify() {
    const menus = [];
    document.querySelectorAll('[class*="dropdown"], [class*="profile"], [role="menu"]').forEach((el) => {
      if (looksLikeProfileMenu(el)) menus.push(el);
    });
    menus.forEach((menu) => {
      if (menu.querySelector('[data-orgasmic-notify], a[href="#orgasmic-notify"], .oa-profile-notify')) return;
      const links = Array.from(menu.querySelectorAll('a, button'));
      const logout = links.find((node) => /Abmelden|Sign out|Logout/i.test(node.textContent || ''));
      const row = logout && (logout.closest('li') || logout);
      const item = document.createElement(row && row.tagName === 'LI' ? 'li' : 'div');
      item.className = 'oa-profile-notify-item';
      item.innerHTML = '<a href="#orgasmic-notify" data-orgasmic-notify="1" class="oa-profile-notify">'
        + (cfg.navIcon || '')
        + '<span>Benachrichtigungen</span></a>';
      if (row && row.parentElement) {
        row.parentElement.insertBefore(item, row);
      } else {
        menu.appendChild(item);
      }
    });
  }

  function watchNavIcons() {
    let scheduled = 0;
    const run = () => {
      scheduled = 0;
      paintNavIcons();
      injectProfileNotify();
    };
    const schedule = () => {
      if (scheduled) return;
      scheduled = requestAnimationFrame(run);
    };
    paintNavIcons();
    injectProfileNotify();
    [200, 800, 2000].forEach((ms) => {
      setTimeout(paintNavIcons, ms);
      setTimeout(injectProfileNotify, ms);
    });
    if (typeof MutationObserver === 'undefined' || !document.body) return;
    const obs = new MutationObserver(schedule);
    obs.observe(document.body, { childList: true, subtree: true });
    setTimeout(() => obs.disconnect(), 8000);
  }

  function syncPrefsHash() {
    if ((location.hash || '') === '#orgasmic-notify') openPrefs();
    else if (prefsRoot() && !prefsRoot().hidden) closePrefs();
  }

  function showPrompt(installable) {
    const root = document.getElementById('orgasmic-app-prompt');
    if (!root || sessionStorage.getItem('orgasmic-app-prompt') === '1') return;
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    let text = 'Benachrichtigungen für Chat, Beiträge und Events erlauben.';
    if (ios && !standalone) {
      text = 'Auf iPhone: Teilen → „Zum Home-Bildschirm“, dann Benachrichtigungen erlauben.';
    } else if (installable) {
      text = 'ORGASMIC als App installieren und Benachrichtigungen anschalten.';
    }
    root.hidden = false;
    root.innerHTML = '<div class="orgasmic-app-banner"><p>' + text + '</p><div>'
      + '<button type="button" data-oa-enable>Erlauben</button>'
      + '<button type="button" class="oa-ghost" data-oa-dismiss>Später</button>'
      + '</div></div>';
  }

  async function enablePush(info) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return;
    const reg = await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(info.vapidPublicKey),
      });
    }
    const raw = sub.toJSON();
    await postJson('push/subscribe', {
      endpoint: raw.endpoint,
      keys: raw.keys,
      contentEncoding: (PushManager.supportedContentEncodings && PushManager.supportedContentEncodings[0]) || 'aes128gcm',
    });
  }

  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (ev) => {
    ev.preventDefault();
    deferredPrompt = ev;
    showPrompt(true);
  });

  document.addEventListener('click', async (ev) => {
    const notifyLink = ev.target.closest('a[href*="#orgasmic-notify"], [data-orgasmic-notify], .oa-profile-notify');
    if (notifyLink) {
      ev.preventDefault();
      ev.stopPropagation();
      openPrefs();
      return;
    }
    if (ev.target.closest('[data-oa-prefs-close]') || ev.target.closest('.orgasmic-app-prefs-overlay') === ev.target) {
      ev.preventDefault();
      closePrefs();
      return;
    }
    if (ev.target.closest('[data-oa-dismiss]')) {
      sessionStorage.setItem('orgasmic-app-prompt', '1');
      const root = document.getElementById('orgasmic-app-prompt');
      if (root) root.hidden = true;
      return;
    }
    if (!ev.target.closest('[data-oa-enable]')) return;
    try {
      const info = await getJson('bootstrap');
      if (deferredPrompt) {
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
      }
      if (info.enabled && info.vapidPublicKey) await enablePush(info);
      sessionStorage.setItem('orgasmic-app-prompt', '1');
      const root = document.getElementById('orgasmic-app-prompt');
      if (root) root.hidden = true;
    } catch (e) {}
  });

  async function persistPrefs(form, closeAfter) {
    const prefs = {};
    ['chat', 'feed', 'comment', 'event'].forEach((key) => {
      const input = form.querySelector('[name="' + key + '"]');
      prefs[key] = !!(input && input.checked);
    });
    try {
      const data = await postJson('prefs', { prefs: prefs });
      cfg.prefs = data.prefs || prefs;
      applyPrefs(cfg.prefs);
      if (closeAfter) {
        closePrefs();
        return;
      }
      setPrefsStatus('Gespeichert.');
      setTimeout(() => setPrefsStatus(''), 2000);
    } catch (e) {
      setPrefsStatus(e.message || 'Speichern fehlgeschlagen.', true);
    }
  }

  document.addEventListener('change', (ev) => {
    const form = ev.target.closest && ev.target.closest('[data-oa-prefs]');
    if (!form || !ev.target.matches || !ev.target.matches('input[type="checkbox"]')) return;
    persistPrefs(form, false);
  });

  document.addEventListener('submit', async (ev) => {
    const form = ev.target.closest('[data-oa-prefs]');
    if (!form) return;
    ev.preventDefault();
    const ios = document.documentElement.classList.contains('orgasmic-ios');
    await persistPrefs(form, ios);
  });

  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-oa-delete-account]');
    if (!btn) return;
    ev.preventDefault();
    if (!window.confirm('Konto wirklich dauerhaft löschen? Das kann nicht rückgängig gemacht werden.')) {
      return;
    }
    const typed = window.prompt('Tippe DELETE, um die Löschung zu bestätigen.');
    if (typed !== 'DELETE') {
      setPrefsStatus('Löschung abgebrochen.', true);
      return;
    }
    btn.disabled = true;
    setPrefsStatus('Konto wird gelöscht …');
    try {
      await postJson('account/delete', { confirm: 'DELETE' });
      window.location.href = '/portal/';
    } catch (e) {
      btn.disabled = false;
      setPrefsStatus(e.message || 'Löschen fehlgeschlagen.', true);
    }
  });

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && prefsRoot() && !prefsRoot().hidden) {
      closePrefs();
    }
  });

  window.addEventListener('hashchange', syncPrefsHash);
  applyPrefs(cfg.prefs);
  watchNavIcons();
  if ((location.hash || '') === '#orgasmic-notify') openPrefs();

  function isNativeShell() {
    return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
  }

  function isFluentBottomNav(node) {
    return !!(node && node.closest && node.closest(
      '.fcom_mobile_menu, .fcom-mobile-menu, .fcom_mobile_nav, .fcom-mobile-nav, .fluent_community_mobile_menu, [class*="mobile_menu"], [class*="mobile-menu"], [class*="bottom-nav"], [class*="bottom_nav"]'
    ));
  }

  async function setupNativeChrome() {
    if (!isNativeShell()) return;
    const platform = (window.Capacitor.getPlatform && window.Capacitor.getPlatform()) || '';
    const ios = platform === 'ios';
    document.documentElement.classList.add('orgasmic-native');
    document.documentElement.classList.toggle('orgasmic-ios', ios);
    const vp = document.querySelector('meta[name="viewport"]');
    if (vp) {
      let content = vp.getAttribute('content') || '';
      if (!/viewport-fit/.test(content)) {
        content = content.replace(/\s+$/, '') + ', viewport-fit=cover';
      }
      if (!/interactive-widget/.test(content)) {
        content += ', interactive-widget=resizes-content';
      }
      vp.setAttribute('content', content);
    }
    if (ios) {
      document.querySelectorAll('[data-oa-prefs-close]').forEach((btn) => {
        btn.textContent = 'Fertig';
      });
    }
    const Cap = window.Capacitor && window.Capacitor.Plugins;
    if (Cap && Cap.StatusBar) {
      try {
        if (Cap.StatusBar.setOverlaysWebView) {
          await Cap.StatusBar.setOverlaysWebView({ overlay: ios });
        }
        if (Cap.StatusBar.setStyle) {
          await Cap.StatusBar.setStyle({ style: 'LIGHT' });
        }
        if (!ios && Cap.StatusBar.setBackgroundColor) {
          await Cap.StatusBar.setBackgroundColor({ color: '#ffffff' });
        }
      } catch (e) {}
    }
  }

  document.addEventListener('click', (ev) => {
    const a = ev.target.closest && ev.target.closest('a');
    if (!a || a.closest('#orgasmic-app-prefs')) return;
    if (/#orgasmic-notify/.test(a.getAttribute('href') || '')) return;
    if (isFluentBottomNav(a)) closePrefs();
  }, true);

  setupNativeChrome();

  function overlayOpen() {
    if (document.documentElement.classList.contains('orgasmic-chat-open')) return true;
    if (document.documentElement.classList.contains('orgasmic-cal-open')) return true;
    const chat = document.getElementById('orgasmic-chat-root');
    const cal = document.getElementById('orgasmic-cal-root');
    const prefs = document.getElementById('orgasmic-app-prefs');
    return (chat && !chat.hidden) || (cal && !cal.hidden) || (prefs && !prefs.hidden);
  }

  function ptrBlocked() {
    if (overlayOpen()) return true;
    const overlays = document.querySelectorAll('.el-overlay, .el-overlay-dialog');
    for (let i = 0; i < overlays.length; i += 1) {
      const el = overlays[i];
      if (el.closest('#orgasmic-app-prefs, #orgasmic-chat-root, #orgasmic-cal-root')) continue;
      const st = window.getComputedStyle(el);
      if (st.display === 'none' || st.visibility === 'hidden' || Number(st.opacity) === 0) continue;
      if (el.offsetWidth > window.innerWidth * 0.6 && el.offsetHeight > window.innerHeight * 0.4) return true;
    }
    return false;
  }

  function isScrollableY(el) {
    if (!el || el.nodeType !== 1) return false;
    const st = window.getComputedStyle(el);
    const oy = st.overflowY;
    if (oy !== 'auto' && oy !== 'scroll' && oy !== 'overlay') return false;
    return el.scrollHeight > el.clientHeight + 8;
  }

  function scrollerOf(node) {
    let el = node && node.nodeType === 1 ? node : (node && node.parentElement);
    while (el && el !== document.body && el !== document.documentElement) {
      if (el.id === 'orgasmic-ptr') {
        el = el.parentElement;
        continue;
      }
      if (isScrollableY(el)) return el;
      el = el.parentElement;
    }
    return document.scrollingElement || document.documentElement;
  }

  function scrollerAtTop(el) {
    if (!el || el === document.body || el === document.documentElement || el === document.scrollingElement) {
      return (window.scrollY || document.documentElement.scrollTop || 0) <= 10;
    }
    return el.scrollTop <= 10;
  }

  function ptrIgnoreTarget(node) {
    const el = node && node.nodeType === 1 ? node : (node && node.parentElement);
    if (!el || !el.closest) return false;
    if (el.closest('#orgasmic-ptr')) return true;
    if (el.closest('input, textarea, select, [contenteditable="true"], .ql-editor, .ProseMirror')) return true;
    if (isFluentBottomNav(el)) return true;
    return false;
  }

  function setupAnnounce() {
    if (!cfg.loggedIn && !cfg.canAnnounce) return;

    const announceState = { push: false, email: false };
    let intentTimer = 0;
    let wrapped = false;
    let ticking = false;
    let cachedComposer = null;
    let panelOpen = false;
    const SKIP = '#oa-announce-host, #orgasmic-chat-root, #orgasmic-cal-root, #orgasmic-app-prefs, #orgasmic-bunny-upload';
    const COMMENT = '[class*="comment-form"], [class*="CommentForm"], [class*="comment_form"], [class*="ReplyBox"], [class*="each_comment"], .each_comment, .feed_comments';
    const EDITOR = 'textarea, [contenteditable="true"], [role="textbox"], .ql-editor, .ProseMirror, .tiptap, .fcom_editor, .el-textarea__inner';
    const CREATE_TITLE = /beitrag erstellen|create post|create a post|neuen beitrag|write a post|was denkst du/i;
    const PUBLISH_EXACT = /^(Beitrag|Posten|Post|Veröffentlichen|Publish|Teilen|Share)$/i;

    function flagsHeader() {
      const parts = [];
      if (announceState.push) parts.push('push');
      if (announceState.email) parts.push('email');
      return parts.join(',');
    }

    function normText(value) {
      return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function textOf(el) {
      return normText(el && (el.innerText || el.textContent));
    }

    function skipped(el) {
      return !!(el && el.closest && el.closest(SKIP));
    }

    function isCommentArea(el) {
      return !!(el && el.closest && el.closest(COMMENT));
    }

    function syncIntent() {
      postJson('announce/intent', {
        push: !!announceState.push,
        email: !!announceState.email,
      }).catch(() => {});
    }

    function scheduleIntent() {
      window.clearTimeout(intentTimer);
      intentTimer = window.setTimeout(syncIntent, 200);
    }

    function shadowRoot() {
      const host = document.getElementById('oa-announce-host');
      return (host && (host.shadowRoot || host)) || null;
    }

    function resetAnnounce() {
      announceState.push = false;
      announceState.email = false;
      panelOpen = false;
      const root = shadowRoot();
      if (!root) return;
      const push = root.querySelector('[data-oa-announce-push]');
      const email = root.querySelector('[data-oa-announce-email]');
      if (push) push.checked = false;
      if (email) email.checked = false;
      syncIcon();
      const host = document.getElementById('oa-announce-host');
      if (host) host.removeAttribute('data-panel');
    }

    function flashAnnounce() {
      let bar = document.getElementById('oa-announce-flash');
      if (!bar) {
        bar = document.createElement('div');
        bar.id = 'oa-announce-flash';
        (document.body || document.documentElement).appendChild(bar);
      }
      const bits = [];
      if (announceState.push) bits.push('Push');
      if (announceState.email) bits.push('E-Mail');
      bar.textContent = bits.length === 1
        ? bits[0] + ' geht an die Mitglieder…'
        : bits.join(' und ') + ' gehen an die Mitglieder…';
      bar.hidden = false;
      window.setTimeout(() => { bar.hidden = true; }, 2800);
    }

    function looksLikePublish(el) {
      if (!el || skipped(el) || isCommentArea(el)) return false;
      const text = textOf(el);
      if (!text || text.length > 32) return false;
      const label = normText((el.getAttribute('aria-label') || '') + ' ' + (el.getAttribute('title') || '') + ' ' + text);
      if (/kommentar|comment|antwort|reply|abbrechen|cancel|schließen|close|video|bild|image|umfrage|poll|gif/i.test(label)) return false;
      return PUBLISH_EXACT.test(text);
    }

    function findTitleEl() {
      if (!document.body) return null;
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      let node = walker.nextNode();
      while (node) {
        const text = normText(node.nodeValue);
        if (text && text.length < 80 && CREATE_TITLE.test(text)) {
          const el = node.parentElement;
          if (el && !skipped(el) && !isCommentArea(el)) return el;
        }
        node = walker.nextNode();
      }
      return null;
    }

    function findIconRow(composer) {
      const pub = findPublish(composer);
      if (!pub) return null;
      let node = pub.parentElement;
      for (let i = 0; i < 10 && node && node !== document.body; i += 1) {
        const clickables = node.querySelectorAll
          ? [...node.querySelectorAll('button, [role="button"], .el-button, a')]
          : [];
        const small = clickables.filter((el) => {
          if (el === pub || looksLikePublish(el) || skipped(el)) return false;
          const rect = el.getBoundingClientRect();
          return rect.width >= 16 && rect.width <= 72 && rect.height >= 16 && rect.height <= 72;
        });
        if (small.length >= 2) return { icons: small, publish: pub };
        node = node.parentElement;
      }
      return pub ? { icons: [], publish: pub } : null;
    }

    function findIconAnchor(composer) {
      const row = findIconRow(composer);
      if (!row) return null;
      const pubRect = row.publish.getBoundingClientRect();
      const lefties = row.icons.filter((el) => el.getBoundingClientRect().left < pubRect.left - 4);
      const pool = lefties.length ? lefties : row.icons;
      if (!pool.length) return row.publish;
      pool.sort((a, b) => a.getBoundingClientRect().left - b.getBoundingClientRect().left);
      return pool[pool.length - 1];
    }

    function findPublish(scope) {
      const root = scope || document;
      const nodes = root.querySelectorAll
        ? root.querySelectorAll('button, .el-button, [role="button"], a, span, div, p')
        : [];
      for (let i = 0; i < nodes.length; i += 1) {
        const el = nodes[i];
        if (el.childElementCount > 4) continue;
        if (!looksLikePublish(el)) continue;
        return el.closest('button, .el-button, [role="button"], a') || el;
      }
      return null;
    }

    function climbComposer(from) {
      let node = from;
      for (let i = 0; i < 18 && node && node !== document.body; i += 1) {
        if (node.nodeType === 1 && node.querySelector) {
          const editor = node.querySelector(EDITOR);
          if (editor && !isCommentArea(editor)) return node;
        }
        node = node.parentElement;
      }
      return from && from.parentElement ? from.parentElement : from;
    }

    function findComposer() {
      if (cachedComposer && cachedComposer.isConnected) {
        const slice = textOf(cachedComposer).slice(0, 400);
        if (CREATE_TITLE.test(slice) && cachedComposer.querySelector(EDITOR)) {
          return cachedComposer;
        }
      }
      const title = findTitleEl();
      if (title) {
        cachedComposer = climbComposer(title);
        return cachedComposer;
      }
      const focused = document.activeElement;
      if (focused && focused.matches && focused.matches(EDITOR) && !isCommentArea(focused) && !skipped(focused)) {
        cachedComposer = climbComposer(focused);
        return cachedComposer;
      }
      cachedComposer = null;
      return null;
    }

    function syncIcon() {
      const root = shadowRoot();
      const icon = root && root.querySelector('[data-oa-announce-icon]');
      if (icon) icon.setAttribute('data-on', (announceState.push || announceState.email) ? '1' : '0');
    }

    function setPanel(open) {
      panelOpen = !!open;
      const host = document.getElementById('oa-announce-host');
      if (host) {
        if (panelOpen) host.setAttribute('data-panel', '1');
        else host.removeAttribute('data-panel');
      }
    }

    function ensureHost() {
      let host = document.getElementById('oa-announce-host');
      if (host && host.isConnected && (host.shadowRoot || host.querySelector('[data-oa-announce-icon]'))) {
        return host;
      }
      if (host && host.parentNode) host.parentNode.removeChild(host);
      host = document.createElement('div');
      host.id = 'oa-announce-host';
      let root = host;
      try {
        root = host.attachShadow({ mode: 'open' });
      } catch (e) {
        root = host;
      }
      root.innerHTML = '<style>'
        + ':host{position:fixed;z-index:2147483646;display:none;box-sizing:border-box;font-family:system-ui,-apple-system,sans-serif;}'
        + ':host([data-open="1"]){display:block;}'
        + '.icon{width:100%;height:100%;border:0;background:transparent;color:inherit;cursor:pointer;border-radius:10px;'
        + 'display:flex;align-items:center;justify-content:center;padding:0;}'
        + '.icon svg{width:22px;height:22px;display:block;}'
        + '.icon[data-on="1"]{background:#f6f3ee;color:#1a1625;}'
        + '.box{display:none;position:absolute;left:0;top:calc(100% + 8px);width:min(320px,calc(100vw - 24px));'
        + 'pointer-events:auto;flex-direction:column;gap:8px;padding:10px 12px;border-radius:12px;'
        + 'border:1px solid rgba(26,22,37,.18);background:#f6f3ee;color:#1a1625;font-size:13px;line-height:1.35;font-weight:650;'
        + 'box-shadow:0 10px 28px rgba(15,23,42,.2);}'
        + ':host([data-panel="1"]) .box{display:flex;}'
        + 'label{display:flex;gap:8px;align-items:flex-start;cursor:pointer;}'
        + 'input{width:16px;height:16px;margin:2px 0 0;flex:0 0 auto;accent-color:#1a1625;}'
        + '.help{margin:0;font-weight:400;font-size:11px;color:#6b6575;}'
        + '</style>'
        + '<button type="button" class="icon" data-oa-announce-icon aria-label="Push und E-Mail an Mitglieder">'
        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M4 10v4a1 1 0 0 0 1 1h2l7 4V5L7 9H5a1 1 0 0 0-1 1z"></path>'
        + '<path d="M17 8.2a4.2 4.2 0 0 1 0 7.6"></path>'
        + '</svg></button>'
        + '<div class="box" data-oa-announce="1">'
        + '<label><input type="checkbox" data-oa-announce-push> Per Push an alle Mitglieder senden</label>'
        + '<label><input type="checkbox" data-oa-announce-email> Per E-Mail an alle Mitglieder senden</label>'
        + '<p class="help">Übergeht die persönlichen Einstellungen. Nur wer den Beitrag sehen darf. Geheime Räume bleiben geheim.</p>'
        + '</div>';
      const push = root.querySelector('[data-oa-announce-push]');
      const email = root.querySelector('[data-oa-announce-email]');
      const icon = root.querySelector('[data-oa-announce-icon]');
      if (push) push.checked = !!announceState.push;
      if (email) email.checked = !!announceState.email;
      if (icon) {
        icon.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          setPanel(!panelOpen);
          paintChrome();
        });
      }
      root.addEventListener('change', () => {
        announceState.push = !!(push && push.checked);
        announceState.email = !!(email && email.checked);
        syncIcon();
        scheduleIntent();
      });
      (document.body || document.documentElement).appendChild(host);
      syncIcon();
      return host;
    }

    function hideHost(host) {
      if (!host) return;
      panelOpen = false;
      host.removeAttribute('data-open');
      host.removeAttribute('data-panel');
      host.style.display = 'none';
    }

    function paintChrome() {
      const composer = findComposer();
      if (!composer) {
        hideHost(document.getElementById('oa-announce-host'));
        return;
      }
      const crect = composer.getBoundingClientRect();
      if (!crect.width || crect.height < 40 || crect.bottom < 40 || crect.top > window.innerHeight - 20) {
        hideHost(document.getElementById('oa-announce-host'));
        return;
      }
      const host = ensureHost();
      const anchor = findIconAnchor(composer);
      const pub = findPublish(composer);
      const arect = (anchor || pub || composer).getBoundingClientRect();
      const size = Math.max(28, Math.min(40, Math.round(arect.height || 36)));
      let left = arect.right + 6;
      let top = arect.top + (arect.height - size) / 2;
      if (pub && anchor !== pub) {
        const prect = pub.getBoundingClientRect();
        if (left + size > prect.left - 6) left = Math.max(8, prect.left - size - 8);
      }
      left = Math.min(Math.max(8, left), window.innerWidth - size - 8);
      host.setAttribute('data-open', '1');
      if (panelOpen) host.setAttribute('data-panel', '1');
      else host.removeAttribute('data-panel');
      host.style.display = 'block';
      host.style.position = 'fixed';
      host.style.zIndex = '2147483646';
      host.style.left = left + 'px';
      host.style.top = top + 'px';
      host.style.width = size + 'px';
      host.style.height = size + 'px';
      host.style.right = 'auto';
      host.style.bottom = 'auto';
      const box = (host.shadowRoot || host).querySelector('.box');
      if (box && panelOpen) {
        const bh = box.offsetHeight || 110;
        if (top + size + 8 + bh > window.innerHeight - 8 && top - 8 - bh >= 8) {
          box.style.top = 'auto';
          box.style.bottom = (size + 8) + 'px';
        } else {
          box.style.top = (size + 8) + 'px';
          box.style.bottom = 'auto';
        }
      }
      syncIcon();
    }

    function isFeedWrite(method, url) {
      const m = String(method || 'GET').toUpperCase();
      if (m !== 'POST' && m !== 'PUT') return false;
      const u = String(url || '');
      if (/comment/i.test(u)) return false;
      return /fluent-community/i.test(u) && /feed/i.test(u);
    }

    function wrapNetwork() {
      if (wrapped) return;
      wrapped = true;
      const origFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
      if (origFetch) {
        window.fetch = function orgasmicAnnounceFetch(input, init) {
          const url = typeof input === 'string' ? input : (input && input.url) || '';
          const method = (init && init.method) || (input && input.method) || 'GET';
          const sending = isFeedWrite(method, url) && (announceState.push || announceState.email);
          if (sending) {
            init = init ? Object.assign({}, init) : {};
            const headers = new Headers(init.headers || (input && input.headers) || undefined);
            headers.set('X-Orgasmic-Announce', flagsHeader());
            init.headers = headers;
            syncIntent();
          }
          const req = origFetch(input, init);
          if (sending && req && typeof req.then === 'function') {
            req.then((res) => {
              if (res && res.ok) {
                flashAnnounce();
                resetAnnounce();
              }
            }).catch(() => {});
          }
          return req;
        };
      }
      const xhrOpen = XMLHttpRequest.prototype.open;
      const xhrSend = XMLHttpRequest.prototype.send;
      XMLHttpRequest.prototype.open = function orgasmicAnnounceOpen(method, url) {
        this._oaAnnounce = isFeedWrite(method, url);
        this._oaUrl = url;
        return xhrOpen.apply(this, arguments);
      };
      XMLHttpRequest.prototype.send = function orgasmicAnnounceSend(body) {
        if (this._oaAnnounce && (announceState.push || announceState.email)) {
          try {
            this.setRequestHeader('X-Orgasmic-Announce', flagsHeader());
          } catch (e) {}
          syncIntent();
          this.addEventListener('load', () => {
            if (this.status >= 200 && this.status < 300) {
              flashAnnounce();
              resetAnnounce();
            }
          });
        }
        return xhrSend.apply(this, arguments);
      };
    }

    function startTick() {
      if (ticking) return;
      ticking = true;
      const loop = () => {
        const host = document.getElementById('oa-announce-host');
        if (host && host.getAttribute('data-open') === '1' && cachedComposer && cachedComposer.isConnected) {
          paintChrome();
          window.requestAnimationFrame(loop);
        } else {
          ticking = false;
        }
      };
      window.requestAnimationFrame(loop);
    }

    document.addEventListener('click', (ev) => {
      const btn = ev.target.closest && ev.target.closest('button, .el-button, [role="button"]');
      if (!btn || !looksLikePublish(btn)) return;
      const composer = findComposer();
      if (!composer || !composer.contains(btn)) return;
      if (announceState.push || announceState.email) syncIntent();
    }, true);

    document.addEventListener('focusin', (ev) => {
      const ed = ev.target && ev.target.closest && ev.target.closest(EDITOR);
      if (!ed || isCommentArea(ed) || skipped(ed)) return;
      paintChrome();
      startTick();
    }, true);

    document.addEventListener('pointerdown', (ev) => {
      if (!panelOpen) return;
      const host = document.getElementById('oa-announce-host');
      if (!host) return;
      const path = ev.composedPath ? ev.composedPath() : [];
      if (path.indexOf(host) !== -1 || host.contains(ev.target)) return;
      setPanel(false);
      paintChrome();
    }, true);

    wrapNetwork();
    paintChrome();
    startTick();
    [200, 600, 1200, 2500, 5000].forEach((ms) => window.setTimeout(() => {
      paintChrome();
      startTick();
    }, ms));
    window.addEventListener('scroll', paintChrome, true);
    window.addEventListener('resize', paintChrome);
    let scheduled = 0;
    const obs = new MutationObserver(() => {
      if (scheduled) return;
      scheduled = window.setTimeout(() => {
        scheduled = 0;
        paintChrome();
        startTick();
      }, 80);
    });
    if (document.body) obs.observe(document.body, { childList: true, subtree: true });
  }

  function setupFeedRefresh() {
    const THRESHOLD = 52;
    let startX = 0;
    let startY = 0;
    let pullOriginY = 0;
    let tracking = false;
    let pulling = false;
    let armed = false;
    let refreshing = false;
    let pullPx = 0;
    let startedAtTop = false;
    let scroller = null;

    const bar = document.createElement('div');
    bar.id = 'orgasmic-ptr';
    bar.hidden = true;
    bar.innerHTML = '<span class="orgasmic-ptr-disc" aria-hidden="true">'
      + '<svg class="orgasmic-ptr-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
      + '<path d="M21 12a9 9 0 1 1-3.2-6.9"></path>'
      + '<polyline points="21 3 21 9 15 9"></polyline>'
      + '</svg></span>';
    (document.body || document.documentElement).appendChild(bar);

    function setPull(px, busy) {
      pullPx = px;
      const show = busy || px > 10;
      bar.hidden = !show;
      bar.classList.toggle('is-busy', !!busy);
      bar.classList.toggle('is-armed', !busy && px > THRESHOLD);
      const t = Math.max(0, Math.min(1, px / 88));
      const y = Math.round(Math.min(72, px * 0.42));
      const rot = busy ? 0 : Math.round(t * 270);
      const scale = busy ? 1 : (0.55 + t * 0.45);
      bar.style.opacity = busy ? '1' : String(0.35 + t * 0.65);
      bar.style.transform = 'translate(-50%, ' + y + 'px) scale(' + scale + ')';
      const arrow = bar.querySelector('.orgasmic-ptr-arrow');
      if (arrow) arrow.style.transform = busy ? '' : 'rotate(' + rot + 'deg)';
    }

    function resetBar() {
      bar.classList.remove('is-busy', 'is-armed');
      bar.hidden = true;
      bar.style.opacity = '';
      bar.style.transform = '';
      const arrow = bar.querySelector('.orgasmic-ptr-arrow');
      if (arrow) arrow.style.transform = '';
      pullPx = 0;
    }

    function stopTrack() {
      tracking = false;
      pulling = false;
      armed = false;
      scroller = null;
    }

    function refreshPortal() {
      window.location.reload();
    }

    document.addEventListener('touchstart', (ev) => {
      if (refreshing || ptrBlocked() || ev.touches.length !== 1) {
        stopTrack();
        return;
      }
      const t = ev.touches[0];
      const target = ev.target;
      if (ptrIgnoreTarget(target)) {
        stopTrack();
        return;
      }
      startX = t.clientX;
      startY = t.clientY;
      pullOriginY = t.clientY;
      scroller = scrollerOf(target);
      startedAtTop = scrollerAtTop(scroller);
      tracking = true;
      pulling = false;
      armed = false;
    }, { capture: true, passive: true });

    document.addEventListener('touchmove', (ev) => {
      if (!tracking || refreshing || ptrBlocked()) return;
      if (ev.touches.length !== 1) {
        stopTrack();
        if (!refreshing) resetBar();
        return;
      }
      const t = ev.touches[0];
      const dy = t.clientY - startY;
      const dx = t.clientX - startX;
      if (!pulling) {
        if (Math.abs(dx) > 24 && Math.abs(dx) > Math.abs(dy)) {
          stopTrack();
          return;
        }
        const atTop = scrollerAtTop(scroller);
        if (dy > 8 && atTop && Math.abs(dy) >= Math.abs(dx)) {
          pulling = true;
          pullOriginY = startedAtTop ? startY : t.clientY;
        } else if (dy < -12) {
          stopTrack();
          return;
        } else {
          return;
        }
      }
      const pull = t.clientY - pullOriginY;
      if (pull > 0) {
        if (ev.cancelable) ev.preventDefault();
        armed = pull > THRESHOLD;
        setPull(pull, false);
      } else {
        armed = false;
        if (!bar.hidden && !bar.classList.contains('is-busy')) resetBar();
      }
    }, { capture: true, passive: false });

    function onTouchEnd() {
      if (pulling && armed && !ptrBlocked() && !refreshing) {
        refreshing = true;
        tracking = false;
        pulling = false;
        armed = false;
        setPull(88, true);
        window.setTimeout(() => {
          refreshPortal();
        }, 80);
        window.setTimeout(() => {
          resetBar();
          refreshing = false;
        }, 1600);
        return;
      }
      if (!refreshing) resetBar();
      stopTrack();
    }

    document.addEventListener('touchend', onTouchEnd, { capture: true, passive: true });
    document.addEventListener('touchcancel', onTouchEnd, { capture: true, passive: true });
  }

  function setupDeviceDebug() {
    const boot = window.__oaDbg || (window.__oaDbg = { v: cfg.version || '', t: Date.now(), err: [], sent: 0 });
    if (!boot.v && cfg.version) boot.v = cfg.version;
    const ajax = boot.ajax || cfg.ajax || '/wp-admin/admin-ajax.php';
    let taps = 0;
    let tapTimer = 0;
    let overlay = null;
    let errorSent = 0;

    function nativeGuess() {
      try {
        if (window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()) return true;
      } catch (e) {}
      return /wv\)|; wv\)|Capacitor/i.test(String(navigator.userAgent || ''));
    }

    function snapshot(reason) {
      const ptr = document.getElementById('orgasmic-ptr');
      const Cap = window.Capacitor || null;
      let platform = '';
      let plugins = [];
      try {
        platform = (Cap && Cap.getPlatform && Cap.getPlatform()) || '';
        plugins = Cap && Cap.Plugins ? Object.keys(Cap.Plugins).slice(0, 20) : [];
      } catch (e) {}
      const main = document.querySelector('main, .fcom_contents, .fcom-portal, #fluent-community, #app') || document.body;
      const text = String((main && (main.innerText || main.textContent)) || '')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 280);
      return {
        v: boot.v || cfg.version || '',
        href: String(location.href || '').slice(0, 240),
        ua: String(navigator.userAgent || '').slice(0, 220),
        native: nativeGuess(),
        cap: !!Cap,
        platform: platform,
        plugins: plugins,
        ready: document.readyState,
        ptr: ptr ? (ptr.hidden ? 'hidden' : String(ptr.textContent || '').slice(0, 40)) : '',
        ptrSkipped: false,
        skel: document.querySelectorAll('[class*="skeleton"], [class*="Skeleton"], .el-skeleton').length,
        feed: document.querySelectorAll('.each_feed, [class*="each_feed"], [class*="EachFeed"]').length,
        load: document.querySelectorAll('.el-loading-mask, [class*="spinner"], [class*="is-loading"]').length,
        text: text,
        fail: (boot.fail || []).slice(-12),
        cookieLen: String(document.cookie || '').length,
        fetchName: (window.fetch && window.fetch.name) || '',
        online: navigator.onLine !== false,
        vis: document.visibilityState || '',
        loggedIn: !!cfg.loggedIn,
        sw: !!(navigator.serviceWorker && navigator.serviceWorker.controller),
        err: (boot.err || []).slice(-12),
        ms: Date.now() - (boot.t || Date.now()),
        reason: reason || 'ping',
      };
    }

    function postAjax(payload) {
      return new Promise(function (resolve, reject) {
        try {
          const xhr = new XMLHttpRequest();
          xhr.open('POST', ajax);
          xhr.withCredentials = true;
          xhr.timeout = 15000;
          xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
          xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) resolve(xhr.responseText);
            else reject(new Error('HTTP ' + xhr.status));
          };
          xhr.onerror = function () { reject(new Error('Netzwerkfehler')); };
          xhr.ontimeout = function () { reject(new Error('Timeout')); };
          xhr.send('action=orgasmic_fc_app_device_log&payload=' + encodeURIComponent(JSON.stringify(payload)));
        } catch (e) {
          reject(e);
        }
      });
    }

    function postRest(payload) {
      if (!cfg.root) return Promise.reject(new Error('no rest'));
      const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      };
      if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
      return fetch(String(cfg.root).replace(/\/?$/, '/') + 'device-log', {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify(payload),
      }).then(function (res) {
        if (!res.ok) throw new Error('REST ' + res.status);
        return res.text();
      });
    }

    function send(reason) {
      boot.sent = 1;
      const payload = snapshot(reason);
      return postAjax(payload).catch(function () {
        return postRest(payload);
      });
    }

    function paintOverlay() {
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'oa-device-debug';
        overlay.innerHTML = '<div class="oa-dd-card"><p class="oa-dd-title">Geräte-Debug</p><pre></pre><div class="oa-dd-row">'
          + '<button type="button" data-oa-dd-send>An Admin senden</button>'
          + '<button type="button" class="oa-ghost" data-oa-dd-copy>Kopieren</button>'
          + '<button type="button" class="oa-ghost" data-oa-dd-close>Schließen</button></div></div>';
        (document.body || document.documentElement).appendChild(overlay);
        overlay.addEventListener('click', function (ev) {
          if (ev.target.closest('[data-oa-dd-close]')) {
            overlay.hidden = true;
            return;
          }
          if (ev.target.closest('[data-oa-dd-send]')) {
            const btn = ev.target.closest('[data-oa-dd-send]');
            btn.textContent = 'Senden…';
            send('overlay').then(function () {
              btn.textContent = 'Angekommen';
            }).catch(function (err) {
              btn.textContent = 'Fehler: ' + ((err && err.message) || 'nein');
            });
            return;
          }
          if (ev.target.closest('[data-oa-dd-copy]')) {
            const text = (overlay.querySelector('pre') && overlay.querySelector('pre').textContent) || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(text).catch(function () {});
            }
          }
        });
      }
      overlay.hidden = false;
      const pre = overlay.querySelector('pre');
      if (pre) pre.textContent = JSON.stringify(snapshot('overlay'), null, 2);
    }

    if (/[?&]oa_debug=1/.test(location.search) || location.hash === '#orgasmic-debug') {
      window.setTimeout(paintOverlay, 400);
    }

    document.addEventListener('touchend', function (ev) {
      const y = ev.changedTouches && ev.changedTouches[0] ? ev.changedTouches[0].clientY : 999;
      if (y > 72) {
        taps = 0;
        return;
      }
      taps += 1;
      window.clearTimeout(tapTimer);
      tapTimer = window.setTimeout(function () { taps = 0; }, 1600);
      if (taps >= 7) {
        taps = 0;
        paintOverlay();
        send('taps');
      }
    }, { passive: true });

    const want = nativeGuess() || /[?&]oa_debug=1/.test(location.search) || location.hash === '#orgasmic-debug';
    if (want) {
      window.setTimeout(function () { send('boot-3s'); }, 3000);
      window.setTimeout(function () { send('boot-12s'); }, 12000);
    }

    window.addEventListener('error', function () {
      if (!want || errorSent > 1) return;
      errorSent += 1;
      window.setTimeout(function () { send('error'); }, 400);
    });
  }

  setupFeedRefresh();
  setupAnnounce();
  setupDeviceDebug();

  const TOKEN_KEY = 'orgasmic-fcm-token';
  let pendingToken = '';
  let pendingPlatform = '';
  let tokenSynced = false;
  let listenersBound = false;
  let startPromise = null;

  async function startNativePush() {
    if (startPromise) return startPromise;
    startPromise = (async () => {
      await syncFcmToken();
      await enableNativePush();
      await syncFcmToken();
    })().finally(() => {
      startPromise = null;
    });
    return startPromise;
  }

  function ajaxUrl() {
    return cfg.ajax || '/wp-admin/admin-ajax.php';
  }

  async function refreshSession() {
    try {
      const res = await fetch(ajaxUrl() + '?action=orgasmic_fc_app_boot', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = await res.json().catch(() => ({}));
      const payload = data && data.data && typeof data.data === 'object' ? data.data : data;
      if (payload && payload.nonce) cfg.nonce = payload.nonce;
      if (payload && payload.loggedIn) {
        cfg.loggedIn = true;
        if (typeof payload.canAnnounce === 'boolean') cfg.canAnnounce = payload.canAnnounce;
        if (payload.prefs) {
          cfg.prefs = payload.prefs;
          applyPrefs(cfg.prefs);
        }
      }
      return !!cfg.loggedIn;
    } catch (e) {
      return !!cfg.loggedIn;
    }
  }

  function rememberFcmToken(token, platform) {
    pendingToken = token;
    pendingPlatform = platform || pendingPlatform;
    try {
      localStorage.setItem(TOKEN_KEY, JSON.stringify({
        token: token,
        platform: pendingPlatform,
        at: Date.now(),
      }));
    } catch (e) {}
  }

  function storedFcmToken() {
    if (pendingToken) return { token: pendingToken, platform: pendingPlatform };
    try {
      const raw = JSON.parse(localStorage.getItem(TOKEN_KEY) || 'null');
      if (raw && raw.token) return { token: raw.token, platform: raw.platform || '' };
    } catch (e) {}
    return null;
  }

  async function postFcmToken(token, platform) {
    try {
      await postJson('push/token', { channel: 'fcm', platform: platform || '', token: token });
      return true;
    } catch (e) {
      try {
        const body = new URLSearchParams();
        body.set('action', 'orgasmic_fc_app_push_token');
        body.set('token', token);
        body.set('platform', platform || '');
        const res = await fetch(ajaxUrl(), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const data = await res.json().catch(() => ({}));
        return !!(data && (data.ok || data.success));
      } catch (err) {
        return false;
      }
    }
  }

  async function syncFcmToken() {
    if (tokenSynced) return true;
    const saved = storedFcmToken();
    if (!saved) return false;
    const logged = cfg.loggedIn || (await refreshSession());
    if (!logged) return false;
    const ok = await postFcmToken(saved.token, saved.platform);
    if (ok) tokenSynced = true;
    return ok;
  }

  async function firebaseReady() {
    const Cap = window.Capacitor && window.Capacitor.Plugins;
    if (!Cap || !Cap.OrgasmicNative || !Cap.OrgasmicNative.pushReady) return false;
    try {
      const status = await Cap.OrgasmicNative.pushReady();
      return !!(status && status.ready);
    } catch (e) {
      return false;
    }
  }

  async function enableNativePush() {
    const Cap = window.Capacitor && window.Capacitor.Plugins;
    if (!Cap || !Cap.PushNotifications) return false;
    // PushNotifications.register() kills the Android process when google-services.json
    // was not baked into the APK. JS try/catch cannot catch that native crash.
    if (!(await firebaseReady())) {
      console.warn('[orgasmic-app] native push skipped (Firebase not initialized)');
      return false;
    }
    const perm = await Cap.PushNotifications.requestPermissions();
    if (perm.receive !== 'granted' && perm.display !== 'granted') return false;
    if (!listenersBound) {
      listenersBound = true;
      await Cap.PushNotifications.addListener('registrationError', (err) => {
        console.warn('[orgasmic-app] push registration error', err);
      });
      await Cap.PushNotifications.addListener('registration', async (ev) => {
        const platform = (window.Capacitor.getPlatform && window.Capacitor.getPlatform()) || '';
        let token = ev.value;
        if (Cap.OrgasmicNative && typeof Cap.OrgasmicNative.fcmToken === 'function') {
          try {
            const fcm = await Cap.OrgasmicNative.fcmToken();
            if (fcm && fcm.token) token = fcm.token;
          } catch (e) {}
        }
        rememberFcmToken(token, platform);
        tokenSynced = false;
        await syncFcmToken();
      });
      await Cap.PushNotifications.addListener('pushNotificationActionPerformed', (ev) => {
        const data = (ev.notification && ev.notification.data) || {};
        if (data.url) window.location.href = data.url;
      });
    }
    await Cap.PushNotifications.register();
    return true;
  }

  const isNative = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
  if (isNative) {
    const bootNative = async () => {
      if (cfg.loggedIn || (await refreshSession())) {
        await startNativePush();
        return;
      }
      let tries = 0;
      const timer = setInterval(async () => {
        tries += 1;
        const ok = await refreshSession();
        if (ok) {
          clearInterval(timer);
          await startNativePush();
        } else if (tries > 120) {
          clearInterval(timer);
        }
      }, 2500);
    };
    bootNative().catch((err) => {
      console.warn('[orgasmic-app] native push failed', err);
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState !== 'visible') return;
      syncFcmToken().catch(() => {});
      if (cfg.loggedIn) startNativePush().catch(() => {});
    });
    return;
  }

  if (!('serviceWorker' in navigator)) return;

  navigator.serviceWorker.register(cfg.sw, { scope: '/' }).then(async () => {
    try {
      const info = await getJson('bootstrap');
      if (info.prefs) {
        cfg.prefs = info.prefs;
        applyPrefs(cfg.prefs);
      }
      if (!info.enabled) return;
      if (Notification.permission === 'granted' && info.vapidPublicKey) {
        await enablePush(info);
        return;
      }
      if (Notification.permission === 'default') showPrompt(false);
    } catch (e) {}
  });
})();
