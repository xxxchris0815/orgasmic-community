(function () {
  const cfg = window.OrgasmicFcEmbeds || {};
  if (!cfg.uploadEnabled || !cfg.root) return;

  const VIDEO_RE = /\.(mp4|m4v|mov|webm|avi|mkv|mpeg|mpg|3gp)$/i;
  const SKIP = '#orgasmic-chat-root, #orgasmic-cal-root, #orgasmic-app-prefs, #orgasmic-bunny-upload';
  const DIALOG_RE = /Ein Video für diesen Beitrag|Füge hier die URL zum Einbetten|Add a video for this post|Paste the embed URL/i;
  const DIALOG_TITLE_RE = /^(Attach Media|Medium anhängen|Medium hinzufügen|Medien anhängen)$/i;
  const VIDEO_LABEL_RE = /^(video|video hinzufügen|add video|embed video|oembed)$/i;
  const VIDEO_HINT_RE = /video-camera|videocam|el-icon-video|icon-video|media-video|oembed|film-outline|camcorder/i;
  const SKIP_LABEL_RE = /einbetten|html code|youtube|vimeo|wistia|kommentar|comment|like|teilen|share/i;

  let busy = false;
  let tusReady = null;
  let picking = false;
  let pickerArmed = false;
  let lastEditor = null;
  let mediaPermAsked = false;
  const pendingPlayUrls = [];

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
      input.style.pointerEvents = 'none';
      if (!file || !isVideoFile(file)) return;
      snapshotVideo(file).then((snap) => uploadFile(snap || file).catch(() => {}));
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
    const title = ((el.querySelector('.el-dialog__title, .el-drawer__title, [class*="dialog__title"]') || {}).textContent || '').replace(/\s+/g, ' ').trim();
    if (DIALOG_TITLE_RE.test(title)) return true;
    const body = el.querySelector('.el-dialog__body, .el-drawer__body') || el;
    return DIALOG_RE.test(body.textContent || '');
  }

  function closeAttachDialogs() {
    document.querySelectorAll('.el-dialog, [role="dialog"]').forEach((el) => {
      if (!isAttachDialog(el)) return;
      const close = el.querySelector('.el-dialog__headerbtn, .el-dialog__close, [aria-label="Close"], [aria-label="close"], [aria-label="Schließen"]');
      if (close) close.click();
      el.style.setProperty('display', 'none', 'important');
    });
  }

  function openPickerNow() {
    picking = true;
    const input = fileInput();
    input.style.pointerEvents = 'auto';
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

  function pickFile() {
    rememberEditor(document.activeElement);
    closeAttachDialogs();
    openPickerNow();
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
    rememberEditor(control);
    if (ev.type === 'pointerdown' || !pickerArmed) {
      pickerArmed = true;
      openPickerNow();
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
    fileInput();
    const style = window.getComputedStyle(btn);
    if (style.position === 'static') {
      btn.style.position = 'relative';
    }
    const label = document.createElement('label');
    label.setAttribute('data-orgasmic-bunny-overlay', '1');
    label.setAttribute('aria-label', 'Video hochladen');
    label.htmlFor = 'orgasmic-bunny-file';
    label.style.cssText = 'position:absolute;inset:0;z-index:6;margin:0;cursor:pointer;background:transparent;';
    label.addEventListener('click', (ev) => {
      ev.stopPropagation();
      if (typeof ev.stopImmediatePropagation === 'function') {
        ev.stopImmediatePropagation();
      }
      pickerArmed = true;
      rememberEditor(btn);
      picking = true;
      fileInput().style.pointerEvents = 'auto';
      ensureMediaPermission().catch(() => {});
      window.setTimeout(closeAttachDialogs, 0);
      window.setTimeout(closeAttachDialogs, 80);
      window.setTimeout(() => {
        pickerArmed = false;
      }, 1200);
    }, true);
    btn.appendChild(label);
  }

  function bindToolbarOverlays() {
    fileInput();
    let found = false;
    document.querySelectorAll('button, [role="button"], .el-button, a.el-button, .el-dropdown-item').forEach((el) => {
      if (looksLikeVideoControl(el)) {
        overlayNativePicker(el);
        found = true;
      }
    });
    if (found && isNativeShell() && !mediaPermAsked) {
      mediaPermAsked = true;
      ensureMediaPermission().catch(() => {});
    }
  }

  function panel() {
    return document.getElementById('orgasmic-bunny-upload');
  }

  function userFacing(text, fallback) {
    const raw = String(text || '').trim();
    const clean = String(fallback || '').trim();
    if (!raw || /bunny|tus|mediadelivery|bunnycdn|fingerprint/i.test(raw)) {
      if (clean && !/bunny|tus|mediadelivery|bunnycdn|fingerprint/i.test(clean)) {
        return clean;
      }
      return 'Upload fehlgeschlagen.';
    }
    return raw;
  }

  function setStatus(text, pct) {
    const root = panel();
    if (!root) return;
    root.hidden = false;
    const status = root.querySelector('[data-obu-status]');
    const bar = root.querySelector('[data-obu-bar]');
    if (status) status.textContent = userFacing(text, 'Wird hochgeladen…');
    if (bar) bar.style.width = Math.max(0, Math.min(100, pct || 0)) + '%';
  }

  function hideStatus() {
    const root = panel();
    if (root) root.hidden = true;
  }

  document.addEventListener('click', (ev) => {
    if (ev.target && ev.target.closest && ev.target.closest('[data-obu-close]')) {
      hideStatus();
    }
  });

  function loadTus() {
    if (window.tus) return Promise.resolve(window.tus);
    if (tusReady) return tusReady;
    tusReady = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = cfg.tus;
      script.onload = () => (window.tus ? resolve(window.tus) : reject(new Error('Video-Upload nicht bereit')));
      script.onerror = () => reject(new Error('Video-Upload nicht bereit'));
      document.head.appendChild(script);
    });
    return tusReady;
  }

  function ajaxUrl() {
    return cfg.ajax || '/wp-admin/admin-ajax.php';
  }

  function isNativeShell() {
    return !!(window.Capacitor || window.capacitor || window.CapacitorWebView);
  }

  async function ensureMediaPermission() {
    if (!isNativeShell()) return;
    const Plugins = window.Capacitor && window.Capacitor.Plugins;
    if (!Plugins) return;
    try {
      if (Plugins.OrgasmicNative && typeof Plugins.OrgasmicNative.requestVideoRead === 'function') {
        await Plugins.OrgasmicNative.requestVideoRead();
        return;
      }
    } catch (e) {}
    try {
      if (Plugins.Camera && Plugins.Camera.requestPermissions) {
        await Plugins.Camera.requestPermissions({ permissions: ['photos'] });
      }
    } catch (e) {}
  }

  function readViaFileReader(blob) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => {
        if (!reader.result) reject(new Error('Datei konnte nicht gelesen werden.'));
        else resolve(reader.result);
      };
      reader.onerror = () => reject(new Error('Datei konnte nicht gelesen werden.'));
      reader.readAsArrayBuffer(blob);
    });
  }

  async function readAsBufferOnce(blob) {
    if (!blob) throw new Error('Datei konnte nicht gelesen werden.');
    if (typeof blob.arrayBuffer === 'function') {
      try {
        const buf = await blob.arrayBuffer();
        if (buf && buf.byteLength) return buf;
      } catch (e) {}
    }
    try {
      const url = URL.createObjectURL(blob);
      try {
        const res = await fetch(url);
        const buf = await res.arrayBuffer();
        if (buf && buf.byteLength) return buf;
      } finally {
        URL.revokeObjectURL(url);
      }
    } catch (e) {}
    return readViaFileReader(blob);
  }

  async function snapshotVideo(file) {
    try {
      const buf = await readAsBuffer(file);
      if (buf && buf.byteLength) {
        return new File([buf], file.name || 'video.mp4', { type: file.type || 'video/mp4', lastModified: file.lastModified || Date.now() });
      }
    } catch (e) {}
    return file;
  }

  async function readAsBuffer(blob) {
    let lastErr = null;
    const tries = isNativeShell() ? 4 : 1;
    for (let i = 0; i < tries; i++) {
      try {
        const buf = await readAsBufferOnce(blob);
        if (buf && buf.byteLength) return buf;
      } catch (e) {
        lastErr = e;
      }
      if (i + 1 < tries) {
        await new Promise((resolve) => setTimeout(resolve, 250 * (i + 1)));
      }
    }
    if (lastErr) throw lastErr;
    throw new Error('Datei konnte nicht gelesen werden.');
  }

  async function parseReply(res, fallback) {
    const data = await res.json().catch(() => ({}));
    const payload = data && data.success === true && data.data ? data.data : data;
    if (!res.ok) throw new Error(userFacing(payload && payload.message, fallback));
    return payload;
  }

  async function restJson(path, body, fallback) {
    const res = await fetch(cfg.root + path, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'X-WP-Nonce': cfg.nonce,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body || {}),
    });
    return parseReply(res, fallback);
  }

  async function ajaxForm(action, fields, fileField, blob, filename, fallback) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', cfg.ajaxNonce || '');
    Object.keys(fields || {}).forEach((key) => fd.append(key, String(fields[key] ?? '')));
    if (fileField && blob) fd.append(fileField, blob, filename || 'video.mp4');
    const res = await fetch(ajaxUrl(), {
      method: 'POST',
      credentials: 'include',
      headers: { Accept: 'application/json' },
      body: fd,
    });
    return parseReply(res, fallback);
  }

  async function apiCreate(title) {
    try {
      return await restJson('upload/create', { title: title || '' }, 'Video-Upload konnte nicht gestartet werden.');
    } catch (err) {
      if (!cfg.ajaxNonce) throw err;
      return ajaxForm('orgasmic_fc_upload_create', { title: title || '' }, null, null, '', 'Video-Upload konnte nicht gestartet werden.');
    }
  }

  function editorSelector() {
    return 'textarea, .el-textarea__inner, [contenteditable="true"], .ql-editor, .ProseMirror, .tiptap, .fcom_editor, [name="message"], [name="body"]';
  }

  function isUsableEditor(el) {
    if (!el || inSkip(el) || el.id === 'orgasmic-bunny-file') return false;
    if (el.closest && el.closest('#orgasmic-bunny-upload')) return false;
    return true;
  }

  function isVisibleBox(el) {
    if (!el) return false;
    const st = window.getComputedStyle(el);
    if (st.display === 'none' || st.visibility === 'hidden') return false;
    const rect = el.getBoundingClientRect();
    return rect.width > 20 && rect.height > 10;
  }

  function editorHint(el) {
    if (!el) return '';
    const parent = el.parentElement;
    return [
      el.getAttribute('placeholder'),
      el.getAttribute('data-placeholder'),
      el.getAttribute('data-placeholder-text'),
      el.getAttribute('aria-placeholder'),
      el.getAttribute('aria-label'),
      el.getAttribute('name'),
      typeof el.className === 'string' ? el.className : '',
      parent && parent.getAttribute('data-placeholder'),
      parent && (typeof parent.className === 'string' ? parent.className : ''),
    ].join(' ').toLowerCase();
  }

  function isTitleEditor(el) {
    if (!el) return false;
    const hint = editorHint(el);
    if (/message|body|status|content|beschreibung|teilen|share|schreib/.test(hint)) return false;
    if (/title|titel|headline|betreff|subject/.test(hint)) return true;
    if (el.closest && el.closest('h1, h2, h3, [class*="post_title"], [class*="PostTitle"], [class*="feed_title"]')) return true;
    const st = window.getComputedStyle(el);
    const rect = el.getBoundingClientRect();
    const weight = parseInt(st.fontWeight, 10) || 0;
    const bold = weight >= 600 || st.fontWeight === 'bold' || st.fontWeight === 'bolder';
    return bold && rect.height > 0 && rect.height < 52;
  }

  function isBodyEditor(el) {
    if (!el || isTitleEditor(el)) return false;
    const hint = editorHint(el);
    if (/title|titel|headline|betreff|subject/.test(hint) && !/status|teilen|message|body/.test(hint)) return false;
    if (/message|body|status|content|beschreibung|teilen|share|schreib|what.?s on|beitrag|nachricht|update/.test(hint)) return true;
    if (el.getAttribute('name') === 'message' || el.getAttribute('name') === 'body') return true;
    const rect = el.getBoundingClientRect();
    return rect.height >= 56;
  }

  function scoreEditor(el) {
    if (!el) return -999;
    if (isTitleEditor(el)) return -100;
    if (isBodyEditor(el)) return 200 + el.getBoundingClientRect().height;
    return el.getBoundingClientRect().height;
  }

  function composerRoot(from) {
    return (from && from.closest && from.closest([
      '[class*="composer"]',
      '[class*="Composer"]',
      '[class*="create_post"]',
      '[class*="CreatePost"]',
      '[class*="feed_form"]',
      '[class*="FeedForm"]',
      '.fcom_feed_form',
      '.fcom-feed-form',
      'form',
      '.el-dialog',
    ].join(','))) || document.body;
  }

  function editorsIn(root) {
    return [...(root || document).querySelectorAll(editorSelector())].filter(isUsableEditor);
  }

  function pickBodyEditor(from) {
    const root = composerRoot(from || lastEditor);
    const list = editorsIn(root);
    const ranked = list.slice().sort((a, b) => scoreEditor(b) - scoreEditor(a));
    const body = ranked.find((el) => scoreEditor(el) > 0 && !isTitleEditor(el));
    return body || null;
  }

  function rememberEditor(from) {
    const picked = pickBodyEditor(from) || findComposerEditor();
    if (picked && !isTitleEditor(picked)) lastEditor = picked;
    return lastEditor;
  }

  function findComposerEditor() {
    const body = pickBodyEditor(document.activeElement);
    if (body) return body;
    const active = document.activeElement;
    if (active && isUsableEditor(active) && !isTitleEditor(active) && (
      active.isContentEditable
      || active.tagName === 'TEXTAREA'
      || (active.closest && active.closest('.ql-editor, .ProseMirror, .tiptap, .fcom_editor'))
    )) {
      return active.isContentEditable || active.tagName === 'TEXTAREA'
        ? active
        : active.closest('.ql-editor, .ProseMirror, .tiptap, .fcom_editor');
    }
    return lastEditor && !isTitleEditor(lastEditor) ? lastEditor : null;
  }

  function setNativeValue(el, value) {
    const proto = el.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
    const desc = Object.getOwnPropertyDescriptor(proto, 'value');
    if (desc && desc.set) desc.set.call(el, value);
    else el.value = value;
  }

  function fireInput(el) {
    try {
      el.dispatchEvent(new InputEvent('input', { bubbles: true, cancelable: true, inputType: 'insertText' }));
    } catch (e) {
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }
    el.dispatchEvent(new Event('change', { bubbles: true }));
    el.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
  }

  function editorContains(el, text) {
    if (!el) return false;
    const hay = (el.value || el.innerText || el.textContent || '');
    return hay.indexOf(text) !== -1;
  }

  function insertText(el, text) {
    if (!el) return false;
    const current = (el.value != null && (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT'))
      ? el.value
      : (el.innerText || el.textContent || '');
    const chunk = String(current || '').trim() === '' ? text : '\n\n' + text;
    if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
      const start = typeof el.selectionStart === 'number' ? el.selectionStart : String(el.value || '').length;
      const end = typeof el.selectionEnd === 'number' ? el.selectionEnd : start;
      const before = String(el.value || '').slice(0, start);
      const after = String(el.value || '').slice(end);
      const sep = before && !/\n$/.test(before) ? '\n\n' : '';
      setNativeValue(el, before + sep + text + (after && !/^\n/.test(after) ? '\n' : '') + after);
      try { el.focus(); } catch (e) {}
      fireInput(el);
      return editorContains(el, text);
    }
    if (el.isContentEditable || (el.closest && el.closest('[contenteditable="true"]'))) {
      const target = el.isContentEditable ? el : el.closest('[contenteditable="true"]');
      try { target.focus(); } catch (e) {}
      let ok = false;
      try {
        ok = document.execCommand('insertText', false, chunk);
      } catch (e) {
        ok = false;
      }
      if (!ok && !editorContains(target, text)) {
        const p = document.createElement('p');
        p.textContent = text;
        target.appendChild(p);
      }
      fireInput(target);
      return editorContains(target, text) || editorContains(el, text);
    }
    return false;
  }

  function alreadyInserted(url) {
    const root = composerRoot(lastEditor);
    if (root && root.querySelector('[data-orgasmic-bunny-object]')) return true;
    const parsed = parsePlay(url);
    return !!(parsed && document.querySelector('[data-orgasmic-bunny-object][data-bunny-play*="' + parsed.video + '"]'));
  }

  function stripUrlFromEditor(el, url) {
    if (!el || !url || !editorContains(el, url)) return;
    if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
      setNativeValue(el, String(el.value || '').split(url).join('').replace(/^\s+|\s+$/g, ''));
      fireInput(el);
      return;
    }
    const target = el.isContentEditable ? el : (el.closest && el.closest('[contenteditable="true"]'));
    if (!target) return;
    if (target.querySelector && target.querySelector('[data-orgasmic-bunny-object]')) {
      /* keep the player object; only remove leftover visible URL text */
    } else if ((target.innerText || '').trim() === url) {
      target.innerHTML = '';
      fireInput(target);
      return;
    }
    const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        if (node.parentElement && node.parentElement.closest('[data-orgasmic-bunny-object], .orgasmic-bunny-url-hide')) {
          return NodeFilter.FILTER_REJECT;
        }
        return (node.nodeValue || '').indexOf(url) !== -1 ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      },
    });
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      node.nodeValue = node.nodeValue.split(url).join('');
    });
    fireInput(target);
  }

  function parsePlay(url) {
    const m = String(url || '').match(/mediadelivery\.net\/(?:embed|play)\/(\d+)\/([0-9a-f-]+)/i);
    return m ? { library: m[1], video: m[2].toLowerCase() } : null;
  }

  function embedSrc(parsed) {
    return 'https://iframe.mediadelivery.net/embed/'
      + encodeURIComponent(parsed.library) + '/' + encodeURIComponent(parsed.video)
      + '?autoplay=false&preload=true&responsive=true&playerjs=true';
  }

  function playerObject(url) {
    const parsed = parsePlay(url);
    const wrap = document.createElement('div');
    wrap.className = 'orgasmic-bunny-embed orgasmic-bunny-object';
    wrap.setAttribute('contenteditable', 'false');
    wrap.setAttribute('data-orgasmic-bunny-object', parsed ? parsed.library + '/' + parsed.video : '1');
    wrap.setAttribute('data-bunny-play', url);
    if (parsed) {
      const iframe = document.createElement('iframe');
      iframe.src = embedSrc(parsed);
      iframe.allow = 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen';
      iframe.allowFullscreen = true;
      iframe.title = 'Video';
      wrap.appendChild(iframe);
    }
    const keep = document.createElement('a');
    keep.href = url;
    keep.className = 'orgasmic-bunny-url-hide';
    keep.textContent = url;
    wrap.appendChild(keep);
    return wrap;
  }

  function stripAllUrls(root, url) {
    editorsIn(root).forEach((el) => stripUrlFromEditor(el, url));
    if (root && root.querySelectorAll) {
      root.querySelectorAll('[data-orgasmic-bunny-preview]').forEach((n) => n.remove());
    }
  }

  function insertPlayerObject(el, url) {
    if (!el || !url) return false;
    const root = composerRoot(el);
    stripAllUrls(root, url);

    const target = el.isContentEditable
      ? el
      : ((el.closest && el.closest('[contenteditable="true"]')) || el);
    const parsed = parsePlay(url);
    const existing = root.querySelector('[data-orgasmic-bunny-object]');
    if (existing && parsed && existing.getAttribute('data-orgasmic-bunny-object') === parsed.library + '/' + parsed.video) {
      if (!existing.closest('[contenteditable="true"]') && target && target.isContentEditable) {
        target.appendChild(existing);
      }
      lastEditor = target;
      return true;
    }

    const obj = playerObject(url);
    if (target && target.isContentEditable) {
      try { target.focus(); } catch (e) {}
      try {
        document.execCommand('insertHTML', false, obj.outerHTML);
      } catch (e) {}
      if (!target.querySelector('[data-orgasmic-bunny-object]')) {
        target.appendChild(obj);
      }
      fireInput(target);
      lastEditor = target;
      return !!target.querySelector('[data-orgasmic-bunny-object]');
    }

    const host = (el.parentElement || root);
    host.appendChild(obj);
    lastEditor = el;
    return true;
  }

  function rememberPlayUrl(url) {
    if (url && pendingPlayUrls.indexOf(url) === -1) pendingPlayUrls.push(url);
  }

  function finishInsert(el, url) {
    rememberPlayUrl(url);
    const ok = insertPlayerObject(el, url);
    [50, 160, 400, 900].forEach((ms) => {
      window.setTimeout(() => insertPlayerObject(el, url), ms);
    });
    return ok;
  }

  function insertPlayUrl(url) {
    if (!url) return false;
    if (alreadyInserted(url)) {
      rememberPlayUrl(url);
      const root = composerRoot(lastEditor);
      stripAllUrls(root, url);
      return true;
    }
    const root = composerRoot(lastEditor || document.activeElement);
    const body = pickBodyEditor(lastEditor) || pickBodyEditor(root) || findComposerEditor();
    if (body && !isTitleEditor(body)) return finishInsert(body, url);
    const ranked = editorsIn(root).filter((el) => !isTitleEditor(el)).sort((a, b) => scoreEditor(b) - scoreEditor(a));
    if (ranked[0]) return finishInsert(ranked[0], url);
    return false;
  }

  function injectPlayIntoPayload(raw, url) {
    if (!raw || !url) return raw;
    try {
      const data = JSON.parse(raw);
      const keys = ['message', 'content', 'description'];
      let changed = false;
      keys.forEach((key) => {
        if (typeof data[key] !== 'string') return;
        if (/mediadelivery\.net|orgasmic-bunny/.test(data[key])) return;
        data[key] = (data[key].trim() ? data[key].trim() + '\n\n' : '') + url;
        changed = true;
      });
      if (data.feed && typeof data.feed.message === 'string' && !/mediadelivery\.net|orgasmic-bunny/.test(data.feed.message)) {
        data.feed.message = (data.feed.message.trim() ? data.feed.message.trim() + '\n\n' : '') + url;
        changed = true;
      }
      return changed ? JSON.stringify(data) : raw;
    } catch (e) {
      return raw;
    }
  }

  function hookFeedSubmit() {
    if (window.fetch && !window.fetch.__orgasmicBunny) {
      const orig = window.fetch;
      window.fetch = function (input, init) {
        const href = typeof input === 'string' ? input : (input && input.url) || '';
        if (pendingPlayUrls.length && init && init.method && /post|put|patch/i.test(init.method) && /\/feeds(\b|\/|\?)/.test(href) && typeof init.body === 'string') {
          init = Object.assign({}, init, { body: injectPlayIntoPayload(init.body, pendingPlayUrls[pendingPlayUrls.length - 1]) });
        }
        return orig.call(this, input, init);
      };
      window.fetch.__orgasmicBunny = true;
    }
    if (window.XMLHttpRequest && !window.XMLHttpRequest.prototype.__orgasmicBunny) {
      const send = window.XMLHttpRequest.prototype.send;
      const open = window.XMLHttpRequest.prototype.open;
      window.XMLHttpRequest.prototype.open = function (method, url) {
        this.__orgasmicBunnyFeed = /post|put|patch/i.test(String(method || '')) && /\/feeds(\b|\/|\?)/.test(String(url || ''));
        return open.apply(this, arguments);
      };
      window.XMLHttpRequest.prototype.send = function (body) {
        if (this.__orgasmicBunnyFeed && pendingPlayUrls.length && typeof body === 'string') {
          body = injectPlayIntoPayload(body, pendingPlayUrls[pendingPlayUrls.length - 1]);
        }
        return send.call(this, body);
      };
      window.XMLHttpRequest.prototype.__orgasmicBunny = true;
    }
  }

  function insertPlayUrlRetry(url) {
    if (insertPlayUrl(url)) return Promise.resolve(true);
    return new Promise((resolve) => {
      let tries = 0;
      const timer = window.setInterval(() => {
        tries += 1;
        const ok = insertPlayUrl(url);
        if (ok || tries >= 8) {
          window.clearInterval(timer);
          if (!ok) {
            try { navigator.clipboard.writeText(url); } catch (e) {}
            setStatus('Video hochgeladen.', 100);
          }
          resolve(ok);
        }
      }, 180);
    });
  }

  async function apiStatus(videoId) {
    const ctrl = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = ctrl ? window.setTimeout(() => ctrl.abort(), 8000) : 0;
    try {
      const res = await fetch(cfg.root + 'upload/status?video_id=' + encodeURIComponent(videoId), {
        credentials: 'include',
        headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
        signal: ctrl ? ctrl.signal : undefined,
      });
      return await parseReply(res, 'Video-Status fehlgeschlagen.');
    } catch (err) {
      if (!cfg.ajaxNonce) throw err;
      return ajaxForm('orgasmic_fc_upload_status', { video_id: videoId }, null, null, '', 'Video-Status fehlgeschlagen.');
    } finally {
      if (timer) window.clearTimeout(timer);
    }
  }

  function preferOriginUpload() {
    if (window.Capacitor || window.capacitor || window.CapacitorWebView) return true;
    if (navigator.standalone) return true;
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
    if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) return true;
    const ua = String(navigator.userAgent || '');
    if (/wv\)|WebView|PWABuilder|pwabuilder|Capacitor/i.test(ua)) return true;
    if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches && window.innerWidth < 920) return true;
    return false;
  }

  async function apiPush(file, videoId) {
    const body = new Blob([await readAsBuffer(file)], { type: file.type || 'application/octet-stream' });
    try {
      const fd = new FormData();
      fd.append('video_id', videoId);
      fd.append('file', body, file.name || 'video.mp4');
      const res = await fetch(cfg.root + 'upload/push?_wpnonce=' + encodeURIComponent(cfg.nonce), {
        method: 'POST',
        credentials: 'include',
        headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
        body: fd,
      });
      return await parseReply(res, 'Video-Upload fehlgeschlagen.');
    } catch (err) {
      if (!cfg.ajaxNonce) throw err;
      return ajaxForm('orgasmic_fc_upload_push', { video_id: videoId }, 'file', body, file.name || 'video.mp4', 'Video-Upload fehlgeschlagen.');
    }
  }

  async function sendChunkAjax(buffer, videoId, offset, total, name) {
    const body = new Blob([buffer], { type: 'application/octet-stream' });
    return ajaxForm(
      'orgasmic_fc_upload_chunk',
      { video_id: videoId, offset: offset, total: total },
      'chunk',
      body,
      name || 'chunk.bin',
      'Video-Upload fehlgeschlagen.'
    );
  }

  async function sendChunkRest(buffer, videoId, offset, total) {
    const url = cfg.root + 'upload/chunk?video_id=' + encodeURIComponent(videoId)
      + '&offset=' + encodeURIComponent(String(offset))
      + '&total=' + encodeURIComponent(String(total))
      + '&_wpnonce=' + encodeURIComponent(cfg.nonce);
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'X-WP-Nonce': cfg.nonce,
        Accept: 'application/json',
        'Content-Type': 'application/octet-stream',
      },
      body: buffer,
    });
    return parseReply(res, 'Video-Upload fehlgeschlagen.');
  }

  async function sendChunk(blob, videoId, offset, total, name) {
    const buffer = await readAsBuffer(blob);
    if (preferOriginUpload() && cfg.ajaxNonce) {
      try {
        return await sendChunkAjax(buffer, videoId, offset, total, name);
      } catch (err) {
        return sendChunkRest(buffer, videoId, offset, total);
      }
    }
    try {
      return await sendChunkRest(buffer, videoId, offset, total);
    } catch (err) {
      if (!cfg.ajaxNonce) throw err;
      return sendChunkAjax(buffer, videoId, offset, total, name);
    }
  }

  async function originUpload(file, videoId) {
    const total = file.size;
    const chunkSize = preferOriginUpload() ? 256 * 1024 : 1024 * 1024;
    let bytes = null;
    try {
      bytes = new Uint8Array(await readAsBuffer(file));
    } catch (e) {
      throw new Error(
        isNativeShell()
          ? 'Datei konnte nicht gelesen werden. Fotos/Videos in den App-Einstellungen erlauben und eine andere Datei wählen.'
          : 'Datei konnte nicht gelesen werden.'
      );
    }
    if (!bytes.byteLength) {
      throw new Error('Datei konnte nicht gelesen werden.');
    }
    let offset = 0;
    let last = {};
    setStatus('Video wird hochgeladen… 0%', 4);
    while (offset < total) {
      const end = Math.min(offset + chunkSize, total);
      const blob = new Blob([bytes.subarray(offset, end)], { type: 'application/octet-stream' });
      let attempt = 0;
      let data = null;
      while (attempt < 3) {
        try {
          data = await sendChunk(blob, videoId, offset, total, file.name || 'video.mp4');
          break;
        } catch (err) {
          attempt += 1;
          if (attempt >= 3) throw err;
          await new Promise((resolve) => setTimeout(resolve, 700 * attempt));
        }
      }
      last = data || {};
      offset = end;
      const pct = Math.min(99, Math.round((offset / total) * 100));
      setStatus(offset >= total ? 'Video wird verarbeitet…' : ('Video wird hochgeladen… ' + pct + '%'), pct);
    }
    return last;
  }

  function tusUpload(file, creds, tus) {
    return new Promise((resolve, reject) => {
      const expire = String(creds.expirationTime || creds.expire || '');
      let progressed = false;
      let finished = false;
      let lastPct = 0;
      let upload = null;
      let completeWait = 0;
      const done = (err) => {
        if (finished) return;
        finished = true;
        window.clearTimeout(stall);
        window.clearTimeout(completeWait);
        window.clearTimeout(hard);
        try { if (err && upload) upload.abort(true); } catch (e) {}
        if (err) reject(err);
        else resolve(creds);
      };
      const stall = window.setTimeout(() => {
        if (progressed) return;
        done(new Error('TUS_STALL'));
      }, 8000);
      const hard = window.setTimeout(() => {
        if (lastPct >= 100) done();
        else done(new Error('TUS_STALL'));
      }, 90000);
      upload = new tus.Upload(file, {
        endpoint: creds.endpoint || 'https://video.bunnycdn.com/tusupload',
        retryDelays: [0, 3000, 5000, 10000, 20000, 60000],
        removeFingerprintOnSuccess: true,
        fingerprint(current) {
          return Promise.resolve(
            ['tus-bunny', creds.video_id, current.name || '', current.size || 0, current.lastModified || 0].join('-')
          );
        },
        headers: {
          AuthorizationSignature: creds.signature,
          AuthorizationExpire: expire,
          VideoId: creds.video_id,
          LibraryId: String(creds.library_id),
        },
        metadata: {
          filetype: file.type || 'video/mp4',
          title: file.name || 'community-video',
        },
        onError(err) {
          done(err);
        },
        onProgress(sent, total) {
          if (sent > 0) {
            progressed = true;
            window.clearTimeout(stall);
          }
          const pct = total ? Math.round((sent / total) * 100) : 0;
          lastPct = pct;
          setStatus('Video wird hochgeladen… ' + pct + '%', pct);
          if (total && sent >= total) {
            window.clearTimeout(completeWait);
            completeWait = window.setTimeout(() => done(), 1200);
          }
        },
        onSuccess() {
          done();
        },
      });
      upload.start();
    });
  }

  function videoReceived(status) {
    return !!(status && (status.received || status.done || status.status >= 1 || (status.storageSize || 0) > 0));
  }

  function sendBytes(file, creds) {
    if (preferOriginUpload()) {
      return originUpload(file, creds.video_id)
        .catch((err) => {
          if (file.size > 32 * 1024 * 1024) throw err;
          setStatus('Zweiter Upload-Versuch…', 8);
          return apiPush(file, creds.video_id);
        })
        .then(() => creds);
    }
    return loadTus()
      .then((tus) => {
        setStatus('Video wird hochgeladen… 0%', 4);
        return tusUpload(file, creds, tus);
      })
      .then(() => creds)
      .catch((err) => {
        const msg = String(err && err.message ? err.message : err || '');
        if (msg !== 'TUS_STALL' && !/tus|network|failed|abort|CORS|PATCH/i.test(msg)) {
          throw err;
        }
        setStatus('Zweiter Upload-Versuch…', 8);
        return originUpload(file, creds.video_id).then(() => creds);
      });
  }

  function uploadFile(file) {
    if (busy) return Promise.reject(new Error('Ein Upload läuft bereits.'));
    if (!file || !file.size) return Promise.reject(new Error('Die Datei ist leer.'));
    if (file.size > 512 * 1024 * 1024) {
      return Promise.reject(new Error('Video ist größer als 512 MB.'));
    }
    busy = true;
    closeAttachDialogs();
    setStatus('Video wird vorbereitet…', 2);
    return apiCreate(file && file.name)
      .then((creds) => sendBytes(file, creds))
      .then((creds) => {
        setStatus('Video wird in den Beitrag gesetzt…', 98);
        apiStatus(creds.video_id).catch(() => null);
        return insertPlayUrlRetry(creds.play_url).then((ok) => {
          if (ok) {
            setStatus('Video ist im Beitrag und wird jetzt verarbeitet.', 100);
            setTimeout(hideStatus, 2200);
          } else {
            setStatus('Video hochgeladen.', 100);
            setTimeout(hideStatus, 4000);
          }
          return creds;
        });
      })
      .catch((err) => {
        setStatus(userFacing(err && err.message, 'Upload fehlgeschlagen.'), 0);
        setTimeout(hideStatus, 4000);
        throw err;
      })
      .finally(() => {
        busy = false;
      });
  }

  document.addEventListener('focusin', (ev) => {
    if (ev.target && isUsableEditor(ev.target) && inComposerArea(ev.target) && !isTitleEditor(ev.target)) {
      rememberEditor(ev.target);
      if (isNativeShell()) ensureMediaPermission().catch(() => {});
    }
  }, true);
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

  hookFeedSubmit();
  fileInput();
  bindToolbarOverlays();
})();
