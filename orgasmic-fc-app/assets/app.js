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

  document.addEventListener('submit', async (ev) => {
    const form = ev.target.closest('[data-oa-prefs]');
    if (!form) return;
    ev.preventDefault();
    const prefs = {};
    ['chat', 'feed', 'comment', 'event'].forEach((key) => {
      const input = form.querySelector('[name="' + key + '"]');
      prefs[key] = !!(input && input.checked);
    });
    try {
      const data = await postJson('prefs', { prefs: prefs });
      cfg.prefs = data.prefs || prefs;
      applyPrefs(cfg.prefs);
      setPrefsStatus('Gespeichert.');
      setTimeout(() => setPrefsStatus(''), 2000);
    } catch (e) {
      setPrefsStatus(e.message || 'Speichern fehlgeschlagen.', true);
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
    document.documentElement.classList.add('orgasmic-native');
    const vp = document.querySelector('meta[name="viewport"]');
    if (vp) {
      const content = vp.getAttribute('content') || '';
      if (!/viewport-fit/.test(content)) {
        vp.setAttribute('content', content.replace(/\s+$/, '') + ', viewport-fit=cover');
      }
    }
    const Cap = window.Capacitor && window.Capacitor.Plugins;
    if (Cap && Cap.StatusBar) {
      try {
        if (Cap.StatusBar.setOverlaysWebView) {
          await Cap.StatusBar.setOverlaysWebView({ overlay: false });
        }
        if (Cap.StatusBar.setBackgroundColor) {
          await Cap.StatusBar.setBackgroundColor({ color: '#ffffff' });
        }
        if (Cap.StatusBar.setStyle) {
          await Cap.StatusBar.setStyle({ style: 'LIGHT' });
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
        rememberFcmToken(ev.value, platform);
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
