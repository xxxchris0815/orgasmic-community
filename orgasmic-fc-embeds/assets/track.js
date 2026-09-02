(function () {
  const cfg = window.OrgasmicFcEmbeds || {};
  const RE = /https?:\/\/(?:iframe|player)\.mediadelivery\.net\/(?:embed|play)\/(\d+)\/([0-9a-f-]{8,})/i;
  const HEARTBEAT = 15;
  const attached = new WeakSet();
  const sessions = new WeakMap();

  function parseHref(href) {
    const m = String(href || '').match(RE);
    return m ? { library: m[1], video: m[2].toLowerCase() } : null;
  }

  function send(event, payload) {
    if (!cfg.loggedIn || !cfg.root || !cfg.nonce) return;
    fetch(String(cfg.root).replace(/\/?$/, '/') + 'watch', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce,
      },
      body: JSON.stringify(payload),
    }).catch(function () {});
  }

  function snapshot(parsed, state, event) {
    return {
      event: event,
      library_id: parsed.library,
      video_id: parsed.video,
      seconds: Math.max(0, Number(state.seconds) || 0),
      duration: Math.max(0, Number(state.duration) || 0),
      max_seconds: Math.max(0, Number(state.max) || 0),
      page: window.location.href,
    };
  }

  function attach(iframe) {
    if (!cfg.loggedIn || attached.has(iframe) || typeof playerjs === 'undefined') return;
    const parsed = parseHref(iframe.src);
    if (!parsed) return;
    attached.add(iframe);

    const state = { seconds: 0, duration: 0, max: 0, lastBeat: 0, playing: false, parsed: parsed };
    sessions.set(iframe, state);
    const player = new playerjs.Player(iframe);

    player.on('ready', function () {
      player.getDuration(function (duration) {
        state.duration = Number(duration) || 0;
      });

      player.on('play', function () {
        state.playing = true;
        send('play', snapshot(parsed, state, 'play'));
      });

      player.on('pause', function () {
        state.playing = false;
        send('pause', snapshot(parsed, state, 'pause'));
      });

      player.on('ended', function () {
        state.playing = false;
        if (state.duration > 0) {
          state.seconds = state.duration;
          state.max = Math.max(state.max, state.duration);
        }
        send('ended', snapshot(parsed, state, 'ended'));
      });

      player.on('seeked', function () {
        send('seeked', snapshot(parsed, state, 'seeked'));
      });

      player.on('timeupdate', function (data) {
        const seconds = Number(data && data.seconds) || 0;
        const duration = Number(data && data.duration) || state.duration;
        state.seconds = seconds;
        state.duration = duration;
        if (seconds > state.max) state.max = seconds;
        if (state.playing && seconds - state.lastBeat >= HEARTBEAT) {
          state.lastBeat = seconds;
          send('progress', snapshot(parsed, state, 'progress'));
        }
      });
    });
  }

  function scan() {
    document.querySelectorAll('iframe[src*="mediadelivery.net"]').forEach(attach);
  }

  let scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(function () {
      scheduled = false;
      scan();
    });
  }

  function start() {
    const begin = function () {
      scan();
      if (document.body) {
        new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
      }
      window.addEventListener('pagehide', function () {
        document.querySelectorAll('iframe[src*="mediadelivery.net"]').forEach(function (iframe) {
          const state = sessions.get(iframe);
          if (!state || !state.playing) return;
          send('pause', snapshot(state.parsed, state, 'pause'));
        });
      });
    };

    if (window.playerjs) {
      begin();
      return;
    }
    let tries = 0;
    const wait = setInterval(function () {
      tries += 1;
      if (window.playerjs || tries > 40) {
        clearInterval(wait);
        if (window.playerjs) begin();
      }
    }, 250);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
