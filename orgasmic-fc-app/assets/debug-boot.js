(function () {
  var b = window.__oaDbg = window.__oaDbg || {};
  if (!b.t) b.t = Date.now();
  if (!b.err) b.err = [];
  if (!b.fail) b.fail = [];
  b.sent = b.sent || 0;

  function add(list, msg, max) {
    if (list.length > max) list.shift();
    list.push(String(msg || '').slice(0, 180));
  }

  window.addEventListener('error', function (e) {
    add(b.err, (e.message || 'error') + ' @' + (e.filename || '') + ':' + (e.lineno || ''), 24);
  });
  window.addEventListener('unhandledrejection', function (e) {
    var reason = e && e.reason;
    add(b.err, 'reject:' + ((reason && reason.message) || reason || ''), 24);
  });

  if (typeof window.fetch === 'function' && !window.fetch.__oaDbg) {
    var origFetch = window.fetch.bind(window);
    window.fetch = function oaDbgFetch(input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      return origFetch(input, init).then(function (res) {
        if (res && !res.ok) add(b.fail, res.status + ' ' + String(url).slice(0, 140), 16);
        return res;
      }).catch(function (err) {
        add(b.fail, 'net ' + String(url).slice(0, 140), 16);
        throw err;
      });
    };
    window.fetch.__oaDbg = true;
  }

  function native() {
    try {
      if (window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()) {
        return true;
      }
    } catch (e) {}
    return /wv\)|; wv\)|Capacitor/i.test(navigator.userAgent || '');
  }

  function post(ajax, payload) {
    return new Promise(function (resolve, reject) {
      try {
        var xhr = new XMLHttpRequest();
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

  function snapshot() {
    var ptr = document.getElementById('orgasmic-ptr');
    return {
      v: b.v || '',
      href: String(location.href || '').slice(0, 240),
      ua: String(navigator.userAgent || '').slice(0, 220),
      native: native(),
      cap: !!window.Capacitor,
      ready: document.readyState,
      ptr: ptr ? (ptr.hidden ? 'hidden' : String(ptr.textContent || '').slice(0, 40)) : '',
      skel: document.querySelectorAll('[class*="skeleton"], [class*="Skeleton"], .el-skeleton').length,
      feed: document.querySelectorAll('.each_feed, [class*="each_feed"], [class*="EachFeed"]').length,
      load: document.querySelectorAll('.el-loading-mask, [class*="spinner"], [class*="is-loading"]').length,
      online: navigator.onLine !== false,
      vis: document.visibilityState || '',
      err: b.err.slice(-12),
      fail: b.fail.slice(-12),
      ms: Date.now() - b.t,
      reason: 'early',
    };
  }

  function send() {
    if (b.sent) return;
    if (!native() && String(location.search || '').indexOf('oa_debug=1') === -1) return;
    b.sent = 1;
    post(b.ajax || '/wp-admin/admin-ajax.php', snapshot()).catch(function () {
      b.sent = 0;
    });
  }

  setTimeout(function () {
    if (!b.sent) send();
  }, 8000);
})();
