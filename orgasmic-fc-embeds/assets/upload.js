(function () {
  const cfg = window.OrgasmicFcEmbeds || {};
  if (!cfg.uploadEnabled || !cfg.root) return;

  const VIDEO_RE = /\.(mp4|m4v|mov|webm|avi|mkv|mpeg|mpg|3gp)$/i;
  const SKIP = '#orgasmic-chat-root, #orgasmic-cal-root, #orgasmic-app-prefs, #orgasmic-bunny-upload';
  const COMPOSER = [
    '[contenteditable="true"]',
    'textarea',
    '.ql-editor',
    '.ProseMirror',
    '.fcom_editor',
    '[class*="composer"]',
    '[class*="Composer"]',
    '[class*="create_post"]',
    '[class*="CreatePost"]',
    '[class*="feed_form"]',
    '[class*="FeedForm"]',
    '[class*="new_post"]',
    '[class*="editor_wrap"]',
    '.el-dialog',
    '.el-drawer',
    '[class*="media"]',
    '[class*="Media"]',
    '[class*="upload"]',
    '[class*="Upload"]',
  ].join(',');

  let busy = false;
  let tusReady = null;

  function isVideoFile(file) {
    if (!file || typeof file !== 'object') return false;
    const type = String(file.type || '');
    const name = String(file.name || '');
    return type.indexOf('video/') === 0 || VIDEO_RE.test(name);
  }

  function inSkip(node) {
    return !!(node && node.closest && node.closest(SKIP));
  }

  function inComposer(node) {
    return !!(node && node.closest && node.closest(COMPOSER));
  }

  function isFcUploadUrl(url) {
    const u = String(url || '');
    return /\/fluent-player\/video-upload\b/.test(u)
      || /\/feeds\/media-upload\b/.test(u)
      || /\/fluent-community\/v\d+\/.*media/.test(u);
  }

  function panel() {
    return document.getElementById('orgasmic-bunny-upload');
  }

  function setStatus(text, pct) {
    const root = panel();
    if (!root) return;
    root.hidden = false;
    const status = root.querySelector('[data-obu-status]');
    const bar = root.querySelector('[data-obu-bar]');
    if (status) status.textContent = text;
    if (bar) bar.style.width = Math.max(0, Math.min(100, pct || 0)) + '%';
  }

  function hideStatus() {
    const root = panel();
    if (root) root.hidden = true;
  }

  function loadTus() {
    if (window.tus) return Promise.resolve(window.tus);
    if (tusReady) return tusReady;
    tusReady = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = cfg.tus;
      script.onload = () => (window.tus ? resolve(window.tus) : reject(new Error('TUS nicht geladen')));
      script.onerror = () => reject(new Error('TUS-Skript fehlgeschlagen'));
      document.head.appendChild(script);
    });
    return tusReady;
  }

  async function apiCreate(title) {
    const res = await fetch(cfg.root + 'upload/create', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': cfg.nonce,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ title: title || '' }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Bunny-Upload konnte nicht gestartet werden.');
    return data;
  }

  function findComposerEditor() {
    const active = document.activeElement;
    if (active && !inSkip(active) && (
      active.isContentEditable
      || active.tagName === 'TEXTAREA'
      || (active.closest && active.closest('.ql-editor, .ProseMirror, .fcom_editor'))
    )) {
      return active.isContentEditable || active.tagName === 'TEXTAREA'
        ? active
        : active.closest('.ql-editor, .ProseMirror, .fcom_editor');
    }

    const nodes = document.querySelectorAll('textarea, [contenteditable="true"], .ql-editor, .ProseMirror, .fcom_editor');
    for (let i = 0; i < nodes.length; i += 1) {
      const el = nodes[i];
      if (inSkip(el)) continue;
      const rect = el.getBoundingClientRect();
      if (rect.width > 40 && rect.height > 20) return el;
    }
    return null;
  }

  function insertText(el, text) {
    if (!el) return false;
    const chunk = (el.value || el.textContent || '').trim() === '' ? text : '\n\n' + text;
    if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
      const start = typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
      const end = typeof el.selectionEnd === 'number' ? el.selectionEnd : start;
      const before = el.value.slice(0, start);
      const after = el.value.slice(end);
      const sep = before && !/\n$/.test(before) ? '\n\n' : '';
      el.value = before + sep + text + (after && !/^\n/.test(after) ? '\n' : '') + after;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }
    if (el.isContentEditable) {
      el.focus();
      try {
        document.execCommand('insertText', false, chunk);
      } catch (e) {
        el.appendChild(document.createTextNode(chunk));
      }
      el.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    }
    return false;
  }

  function insertPlayUrl(url) {
    if (insertText(findComposerEditor(), url)) return;
    try {
      navigator.clipboard.writeText(url);
    } catch (e) {}
    window.prompt('Player-Link in den Beitrag einfügen:', url);
  }

  function successPayload(file, creds) {
    return {
      media: {
        media_id: 0,
        url: creds.play_url,
        media_key: creds.video_id,
        type: 'link',
        html: '',
        settings: {
          src: creds.play_url,
          title: file && file.name ? file.name : 'Video',
          provider: 'bunny',
        },
      },
    };
  }

  function uploadFile(file) {
    if (busy) return Promise.reject(new Error('Ein Upload läuft bereits.'));
    busy = true;
    setStatus('Video wird bei Bunny angelegt…', 2);
    return apiCreate(file && file.name)
      .then((creds) => loadTus().then((tus) => new Promise((resolve, reject) => {
        setStatus('Upload zu Bunny… 0%', 4);
        const upload = new tus.Upload(file, {
          endpoint: creds.endpoint,
          retryDelays: [0, 3000, 5000, 10000, 20000, 60000],
          chunkSize: 5 * 1024 * 1024,
          headers: {
            AuthorizationSignature: creds.signature,
            AuthorizationExpire: String(creds.expire),
            VideoId: creds.video_id,
            LibraryId: creds.library_id,
          },
          metadata: {
            filetype: file.type || 'video/mp4',
            title: file.name || 'community-video',
          },
          onError(err) {
            reject(err);
          },
          onProgress(sent, total) {
            const pct = total ? Math.round((sent / total) * 100) : 0;
            setStatus('Upload zu Bunny… ' + pct + '%', pct);
          },
          onSuccess() {
            resolve(creds);
          },
        });
        upload.findPreviousUploads().then((prev) => {
          if (prev && prev.length) upload.resumeFromPreviousUpload(prev[0]);
          upload.start();
        }).catch(() => upload.start());
      })))
      .then((creds) => {
        setStatus('Link wird eingefügt…', 100);
        insertPlayUrl(creds.play_url);
        setTimeout(hideStatus, 1200);
        return creds;
      })
      .catch((err) => {
        setStatus(err && err.message ? err.message : 'Upload fehlgeschlagen.', 0);
        setTimeout(hideStatus, 4000);
        throw err;
      })
      .finally(() => {
        busy = false;
      });
  }

  function takeVideoFromFormData(body) {
    if (!(body instanceof FormData)) return null;
    const keys = ['file', 'video', 'media', 'upload'];
    for (let i = 0; i < keys.length; i += 1) {
      const value = body.get(keys[i]);
      if (isVideoFile(value)) return value;
    }
    if (typeof body.entries === 'function') {
      const entries = Array.from(body.entries());
      for (let i = 0; i < entries.length; i += 1) {
        if (isVideoFile(entries[i][1])) return entries[i][1];
      }
    }
    return null;
  }

  document.addEventListener('change', (ev) => {
    const input = ev.target && ev.target.closest ? ev.target.closest('input[type="file"]') : null;
    if (!input || inSkip(input)) return;
    const files = Array.from(input.files || []).filter(isVideoFile);
    if (!files.length) return;
    if (!inComposer(input) && input.accept && String(input.accept).indexOf('video') === -1) return;
    ev.preventDefault();
    ev.stopImmediatePropagation();
    input.value = '';
    uploadFile(files[0]).catch(() => {});
  }, true);

  document.addEventListener('drop', (ev) => {
    if (!ev.dataTransfer || !ev.dataTransfer.files || !ev.dataTransfer.files.length) return;
    const target = ev.target;
    if (inSkip(target) || !inComposer(target)) return;
    const files = Array.from(ev.dataTransfer.files).filter(isVideoFile);
    if (!files.length) return;
    ev.preventDefault();
    ev.stopImmediatePropagation();
    uploadFile(files[0]).catch(() => {});
  }, true);

  const origFetch = window.fetch;
  window.fetch = function (input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const body = init && init.body;
    if (isFcUploadUrl(url) && body) {
      const file = takeVideoFromFormData(body);
      if (file) {
        return uploadFile(file).then((creds) => new Response(JSON.stringify(successPayload(file, creds)), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })).catch((err) => new Response(JSON.stringify({ message: err.message || 'Upload fehlgeschlagen' }), {
          status: 400,
          headers: { 'Content-Type': 'application/json' },
        }));
      }
    }
    return origFetch.apply(this, arguments);
  };

  const xhrOpen = XMLHttpRequest.prototype.open;
  const xhrSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this._obuUrl = url;
    return xhrOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function (body) {
    const file = isFcUploadUrl(this._obuUrl) ? takeVideoFromFormData(body) : null;
    if (!file) return xhrSend.apply(this, arguments);
    const xhr = this;
    uploadFile(file).then((creds) => {
      const payload = JSON.stringify(successPayload(file, creds));
      try {
        Object.defineProperty(xhr, 'status', { configurable: true, value: 200 });
        Object.defineProperty(xhr, 'readyState', { configurable: true, value: 4 });
        Object.defineProperty(xhr, 'responseText', { configurable: true, value: payload });
        Object.defineProperty(xhr, 'response', { configurable: true, value: JSON.parse(payload) });
      } catch (e) {}
      if (typeof xhr.onreadystatechange === 'function') xhr.onreadystatechange();
      if (typeof xhr.onload === 'function') xhr.onload();
      xhr.dispatchEvent(new Event('load'));
    }).catch((err) => {
      try {
        Object.defineProperty(xhr, 'status', { configurable: true, value: 400 });
        Object.defineProperty(xhr, 'responseText', { configurable: true, value: JSON.stringify({ message: err.message }) });
      } catch (e) {}
      if (typeof xhr.onerror === 'function') xhr.onerror();
      xhr.dispatchEvent(new Event('error'));
    });
  };
})();
