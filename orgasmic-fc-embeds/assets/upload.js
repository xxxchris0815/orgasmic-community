(function () {
  const cfg = window.OrgasmicFcEmbeds || {};
  if (!cfg.uploadEnabled || !cfg.root) return;

  const VIDEO_RE = /\.(mp4|m4v|mov|webm|avi|mkv|mpeg|mpg|3gp)$/i;
  const SKIP = '#orgasmic-chat-root, #orgasmic-cal-root, #orgasmic-app-prefs, #orgasmic-bunny-upload';
  const DIALOG_RE = /Attach Media|Medium anhängen|Ein Video für diesen Beitrag|Füge hier die URL zum Einbetten|OEmbed|HTML Code/i;
  const VIDEO_LABEL_RE = /^(video|video hinzufügen|add video|embed video|oembed)$/i;
  const VIDEO_HINT_RE = /video-camera|videocam|el-icon-video|icon-video|media-video|oembed|film-outline|camcorder/i;
  const SKIP_LABEL_RE = /einbetten|html code|youtube|vimeo|wistia|kommentar|comment|like|teilen|share/i;

  let busy = false;
  let tusReady = null;
  let picking = false;
  let pickerArmed = false;

  function fileInput() {
    let input = document.getElementById('orgasmic-bunny-file');
    if (input) return input;
    input = document.createElement('input');
    input.id = 'orgasmic-bunny-file';
    input.type = 'file';
    input.accept = 'video/*,.mp4,.m4v,.mov,.webm,.avi,.mkv';
    input.setAttribute('data-orgasmic-bunny-file', '1');
    input.style.cssText = 'position:fixed;left:0;top:0;width:1px;height:1px;opacity:0;pointer-events:none;';
    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      input.value = '';
      picking = false;
      if (file && isVideoFile(file)) uploadFile(file).catch(() => {});
    });
    document.body.appendChild(input);
    return input;
  }

  function isVideoFile(file) {
    if (!file || typeof file !== 'object') return false;
    const type = String(file.type || '');
    const name = String(file.name || '');
    return type.indexOf('video/') === 0 || VIDEO_RE.test(name);
  }

  function inSkip(node) {
    return !!(node && node.closest && node.closest(SKIP));
  }

  function inComposerArea(el) {
    return !!(el && el.closest && (
      el.closest('[class*="composer"]') ||
      el.closest('[class*="Composer"]') ||
      el.closest('[class*="editor"]') ||
      el.closest('[class*="Editor"]') ||
      el.closest('[class*="create_post"]') ||
      el.closest('[class*="CreatePost"]') ||
      el.closest('[class*="feed_form"]') ||
      el.closest('.fcom_feed_form') ||
      el.closest('.fcom-feed-form') ||
      el.closest('.el-dialog') ||
      el.closest('.el-overlay') ||
      el.closest('[class*="toolbar"]')
    ));
  }

  function isControl(el) {
    if (!el || !el.matches) return false;
    if (el.matches('button, [role="button"], .el-button, a.el-button, .el-dropdown-item, a')) return true;
    const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
    return VIDEO_LABEL_RE.test(text) && el.childElementCount <= 6;
  }

  function looksLikeVideoControl(el) {
    if (!el || el.nodeType !== 1 || inSkip(el) || !isControl(el)) return false;
    if (el.id === 'orgasmic-bunny-file' || el.closest('#orgasmic-bunny-upload')) return false;
    if (!inComposerArea(el)) return false;
    const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (text.length > 48) return false;
    const aria = (el.getAttribute('aria-label') || el.getAttribute('title') || el.getAttribute('data-original-title') || '').trim();
    const cls = (typeof el.className === 'string' ? el.className : '') + ' ' + [...el.querySelectorAll('i, svg, [class]')].slice(0, 8).map((n) => n.className || '').join(' ');
    const hay = (text + ' ' + aria + ' ' + cls).replace(/\s+/g, ' ');
    if (SKIP_LABEL_RE.test(hay)) return false;
    return VIDEO_LABEL_RE.test(text) || VIDEO_LABEL_RE.test(aria) || VIDEO_HINT_RE.test(hay);
  }

  function findVideoControl(from) {
    let node = from;
    while (node && node !== document.body && node.nodeType === 1) {
      if (looksLikeVideoControl(node)) return node;
      node = node.parentElement;
    }
    return null;
  }

  function isAttachDialog(el) {
    if (!el || el.nodeType !== 1) return false;
    return DIALOG_RE.test(el.textContent || '');
  }

  function closeAttachDialogs() {
    document.querySelectorAll('.el-dialog, .el-overlay-dialog, .el-dialog__wrapper, .el-overlay, [role="dialog"]').forEach((el) => {
      if (!isAttachDialog(el)) return;
      const close = el.querySelector('.el-dialog__headerbtn, .el-dialog__close, [aria-label="Close"], [aria-label="close"], [aria-label="Schließen"]');
      if (close) close.click();
      const overlay = el.closest('.el-overlay, .el-dialog__wrapper, .el-overlay-dialog') || el;
      overlay.style.setProperty('display', 'none', 'important');
      overlay.setAttribute('hidden', 'hidden');
    });
    document.body.classList.remove('el-popup-parent--hidden');
    document.body.style.removeProperty('overflow');
  }

  function pickFile() {
    closeAttachDialogs();
    picking = true;
    const input = fileInput();
    try {
      if (typeof input.showPicker === 'function') {
        input.showPicker();
      } else {
        input.click();
      }
    } catch (e) {
      try {
        input.click();
      } catch (err) {
        picking = false;
      }
    }
    window.setTimeout(() => {
      picking = false;
    }, 4000);
  }

  function interceptVideoControl(ev) {
    if (ev.target && ev.target.closest && ev.target.closest('[data-orgasmic-bunny-overlay]')) {
      return;
    }
    const control = findVideoControl(ev.target);
    if (!control) return;
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof ev.stopImmediatePropagation === 'function') {
      ev.stopImmediatePropagation();
    }
    if (ev.type === 'pointerdown' || !pickerArmed) {
      pickerArmed = true;
      pickFile();
      window.setTimeout(() => {
        pickerArmed = false;
      }, 1200);
    }
    window.setTimeout(closeAttachDialogs, 0);
    window.setTimeout(closeAttachDialogs, 80);
    window.setTimeout(closeAttachDialogs, 250);
  }

  function overlayNativePicker(btn) {
    if (!btn || btn.querySelector('[data-orgasmic-bunny-overlay]')) return;
    const style = window.getComputedStyle(btn);
    if (style.position === 'static') {
      btn.style.position = 'relative';
    }
    const label = document.createElement('label');
    label.setAttribute('for', 'orgasmic-bunny-file');
    label.setAttribute('data-orgasmic-bunny-overlay', '1');
    label.setAttribute('aria-label', 'Video hochladen');
    label.style.cssText = 'position:absolute;inset:0;z-index:6;margin:0;cursor:pointer;background:transparent;';
    label.addEventListener('click', (ev) => {
      ev.stopPropagation();
      if (typeof ev.stopImmediatePropagation === 'function') {
        ev.stopImmediatePropagation();
      }
      pickerArmed = true;
      picking = true;
      window.setTimeout(closeAttachDialogs, 0);
      window.setTimeout(closeAttachDialogs, 80);
      window.setTimeout(() => {
        pickerArmed = false;
        picking = false;
      }, 4000);
    }, true);
    btn.appendChild(label);
  }

  function bindToolbarOverlays() {
    fileInput();
    document.querySelectorAll('button, [role="button"], .el-button, a.el-button, .el-dropdown-item').forEach((el) => {
      if (looksLikeVideoControl(el)) overlayNativePicker(el);
    });
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
      el.dispatchEvent(new InputEvent('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }
    if (el.isContentEditable) {
      el.focus();
      try {
        document.execCommand('insertHTML', false, '<p><a href="' + text + '">' + text + '</a></p>');
      } catch (e) {
        try {
          document.execCommand('insertText', false, chunk);
        } catch (err) {
          el.appendChild(document.createTextNode(chunk));
        }
      }
      el.dispatchEvent(new InputEvent('input', { bubbles: true }));
      return true;
    }
    return false;
  }

  function insertPlayUrl(url) {
    if (insertText(findComposerEditor(), url)) return true;
    try {
      navigator.clipboard.writeText(url);
    } catch (e) {}
    window.prompt('Player-Link in den Beitrag einfügen:', url);
    return false;
  }

  function uploadFile(file) {
    if (busy) return Promise.reject(new Error('Ein Upload läuft bereits.'));
    busy = true;
    closeAttachDialogs();
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
        setStatus('Video ist im Beitrag.', 100);
        insertPlayUrl(creds.play_url);
        setTimeout(hideStatus, 1600);
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

  document.addEventListener('pointerdown', interceptVideoControl, true);
  document.addEventListener('click', interceptVideoControl, true);

  document.addEventListener('drop', (ev) => {
    if (!ev.dataTransfer || !ev.dataTransfer.files || !ev.dataTransfer.files.length) return;
    const target = ev.target;
    if (inSkip(target)) return;
    const files = Array.from(ev.dataTransfer.files).filter(isVideoFile);
    if (!files.length) return;
    ev.preventDefault();
    ev.stopImmediatePropagation();
    closeAttachDialogs();
    uploadFile(files[0]).catch(() => {});
  }, true);

  if (typeof MutationObserver !== 'undefined') {
    const obs = new MutationObserver(() => {
      const open = [...document.querySelectorAll('.el-dialog, [role="dialog"]')].some(isAttachDialog);
      if (open) closeAttachDialogs();
      bindToolbarOverlays();
    });
    if (document.body) obs.observe(document.body, { childList: true, subtree: true });
  }

  fileInput();
  bindToolbarOverlays();
})();
