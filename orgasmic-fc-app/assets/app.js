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
  if ((location.hash || '') === '#orgasmic-notify') openPrefs();

  async function enableNativePush() {
    const Cap = window.Capacitor && window.Capacitor.Plugins;
    if (!Cap || !Cap.PushNotifications) return false;
    const perm = await Cap.PushNotifications.requestPermissions();
    if (perm.receive !== 'granted' && perm.display !== 'granted') return false;
    await Cap.PushNotifications.register();
    await Cap.PushNotifications.addListener('registration', async (ev) => {
      const platform = (window.Capacitor.getPlatform && window.Capacitor.getPlatform()) || '';
      await postJson('push/token', { channel: 'fcm', platform: platform, token: ev.value });
    });
    await Cap.PushNotifications.addListener('pushNotificationActionPerformed', (ev) => {
      const data = (ev.notification && ev.notification.data) || {};
      if (data.url) window.location.href = data.url;
    });
    return true;
  }

  const isNative = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
  if (isNative) {
    enableNativePush().catch(() => {});
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
