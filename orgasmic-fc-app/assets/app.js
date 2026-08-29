(function () {
  const cfg = window.OrgasmicFcApp || {};
  if (!cfg.root || !('serviceWorker' in navigator)) return;

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i += 1) output[i] = raw.charCodeAt(i);
    return output;
  }

  async function api(path, body) {
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

  async function bootstrap() {
    const res = await fetch(cfg.root + 'bootstrap', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
    });
    return res.json();
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
    await api('push/subscribe', {
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
    if (ev.target.closest('[data-oa-dismiss]')) {
      sessionStorage.setItem('orgasmic-app-prompt', '1');
      const root = document.getElementById('orgasmic-app-prompt');
      if (root) root.hidden = true;
      return;
    }
    if (!ev.target.closest('[data-oa-enable]')) return;
    try {
      const info = await bootstrap();
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

  navigator.serviceWorker.register(cfg.sw, { scope: '/' }).then(async () => {
    try {
      const info = await bootstrap();
      if (!info.enabled) return;
      if (Notification.permission === 'granted' && info.vapidPublicKey) {
        await enablePush(info);
        return;
      }
      if (Notification.permission === 'default') showPrompt(false);
    } catch (e) {}
  });
})();
