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

  function pickFile() {
    rememberEditor(document.activeElement);
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
    rememberEditor(control);
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
      rememberEditor(btn);
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
    const body = pickBodyEditor(lastEditor) || lastEditor;
    return !!(body && !isTitleEditor(body) && editorContains(body, url));
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
    if ((target.innerText || '').trim() === url) {
      target.innerHTML = '';
      fireInput(target);
      return;
    }
    const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) {
      if ((walker.currentNode.nodeValue || '').indexOf(url) !== -1) nodes.push(walker.currentNode);
    }
    nodes.forEach((node) => {
      node.nodeValue = node.nodeValue.split(url).join('');
    });
    fireInput(target);
  }

  function cloakUrl(el, url) {
    const target = el && (el.isContentEditable ? el : (el.closest && el.closest('[contenteditable="true"]')));
    if (!target || !url) return;
    const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) {
      if ((walker.currentNode.nodeValue || '').indexOf(url) !== -1) nodes.push(walker.currentNode);
    }
    nodes.forEach((node) => {
      if (node.parentElement && node.parentElement.classList.contains('orgasmic-bunny-url-hide')) return;
      const parts = node.nodeValue.split(url);
      const frag = document.createDocumentFragment();
      if (parts[0]) frag.appendChild(document.createTextNode(parts[0]));
      const hide = document.createElement('span');
      hide.className = 'orgasmic-bunny-url-hide';
      hide.setAttribute('data-orgasmic-bunny-url', '1');
      hide.textContent = url;
      frag.appendChild(hide);
      if (parts.slice(1).join(url)) frag.appendChild(document.createTextNode(parts.slice(1).join(url)));
      node.parentNode.replaceChild(frag, node);
    });
  }

  function parsePlay(url) {
    const m = String(url || '').match(/mediadelivery\.net\/(?:embed|play)\/(\d+)\/([0-9a-f-]+)/i);
    return m ? { library: m[1], video: m[2].toLowerCase() } : null;
  }

  function showComposerPlayer(url, near) {
    const parsed = parsePlay(url);
    if (!parsed) return;
    const root = composerRoot(near || lastEditor);
    let box = root.querySelector('[data-orgasmic-bunny-preview]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'orgasmic-bunny-embed orgasmic-bunny-composer';
      box.setAttribute('data-orgasmic-bunny-preview', parsed.library + '/' + parsed.video);
      const body = pickBodyEditor(near) || lastEditor;
      const host = (body && (body.parentElement || body)) || root;
      if (body && body.nextSibling) host.insertBefore(box, body.nextSibling);
      else host.appendChild(box);
    }
    if (box.querySelector('iframe[src*="' + parsed.video + '"]')) return;
    box.innerHTML = '';
    const iframe = document.createElement('iframe');
    iframe.src = 'https://iframe.mediadelivery.net/embed/'
      + encodeURIComponent(parsed.library) + '/' + encodeURIComponent(parsed.video)
      + '?autoplay=false&preload=true&responsive=true';
    iframe.allow = 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen';
    iframe.allowFullscreen = true;
    iframe.title = 'Video';
    box.appendChild(iframe);
  }

  function finishInsert(el, url) {
    lastEditor = el;
    cloakUrl(el, url);
    showComposerPlayer(url, el);
    [60, 200, 500].forEach((ms) => {
      window.setTimeout(() => {
        editorsIn(composerRoot(el)).filter(isTitleEditor).forEach((n) => stripUrlFromEditor(n, url));
        cloakUrl(el, url);
        showComposerPlayer(url, el);
      }, ms);
    });
    return true;
  }

  function insertPlayUrl(url) {
    if (!url) return false;
    const root = composerRoot(lastEditor || document.activeElement);
    editorsIn(root).filter(isTitleEditor).forEach((el) => stripUrlFromEditor(el, url));

    const body = pickBodyEditor(lastEditor) || pickBodyEditor(root) || findComposerEditor();
    if (body && !isTitleEditor(body)) {
      if (editorContains(body, url) || insertText(body, url)) return finishInsert(body, url);
    }

    const ranked = editorsIn(root).filter((el) => !isTitleEditor(el)).sort((a, b) => scoreEditor(b) - scoreEditor(a));
    for (let i = 0; i < ranked.length; i += 1) {
      if (insertText(ranked[i], url)) return finishInsert(ranked[i], url);
    }
    return false;
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
            setStatus('Video hochgeladen. Link: ' + url, 100);
          }
          resolve(ok);
        }
      }, 180);
    });
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
        setStatus('Link wird eingefügt…', 96);
        return insertPlayUrlRetry(creds.play_url).then((ok) => {
          if (ok) {
            setStatus('Video ist im Beitrag.', 100);
            setTimeout(hideStatus, 1800);
          }
          return creds;
        });
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

  document.addEventListener('focusin', (ev) => {
    if (ev.target && isUsableEditor(ev.target) && inComposerArea(ev.target) && !isTitleEditor(ev.target)) {
      rememberEditor(ev.target);
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

  fileInput();
  bindToolbarOverlays();
})();
