(function () {
  var b = window.__oaDbg = window.__oaDbg || {};
  if (!b.t) b.t = Date.now();
  if (!b.err) b.err = [];
  b.sent = b.sent || 0;

  function add(msg) {
    if (b.err.length > 24) b.err.shift();
    b.err.push(String(msg || '').slice(0, 280));
  }

  window.addEventListener('error', function (e) {
    add((e.message || 'error') + ' @' + (e.filename || '') + ':' + (e.lineno || ''));
  });
  window.addEventListener('unhandledrejection', function (e) {
    var reason = e && e.reason;
    add('reject:' + ((reason && reason.message) || reason || ''));
  });

  function native() {
    try {
      if (window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()) {
        return true;
      }
    } catch (e) {}
    return /wv\)|; wv\)|Capacitor/i.test(navigator.userAgent || '');
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
      online: navigator.onLine !== false,
      vis: document.visibilityState || '',
      err: b.err.slice(-12),
      ms: Date.now() - b.t,
      reason: 'early',
    };
  }

  function send() {
    if (b.sent) return;
    if (!native() && String(location.search || '').indexOf('oa_debug=1') === -1) return;
    b.sent = 1;
    var ajax = b.ajax || '/wp-admin/admin-ajax.php';
    try {
      var body = 'action=orgasmic_fc_app_device_log&payload=' + encodeURIComponent(JSON.stringify(snapshot()));
      var blob = new Blob([body], { type: 'application/x-www-form-urlencoded;charset=UTF-8' });
      if (navigator.sendBeacon) navigator.sendBeacon(ajax, blob);
      else fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' } });
    } catch (e) {}
  }

  setTimeout(function () {
    if (!b.sent) send();
  }, 8000);
})();
