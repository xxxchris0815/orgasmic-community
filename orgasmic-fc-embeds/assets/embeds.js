(function () {
  const RE = /https?:\/\/(?:iframe|player)\.mediadelivery\.net\/(?:embed|play)\/(\d+)\/([0-9a-f-]{8,})/i;
  const POST_SEL = [
    '[data-feed_id]',
    '[data-feed-id]',
    '.fcom_single_feed',
    '.fcom-single-feed',
    '.fcom_feed_item',
    '.fcom-feed-item',
    '.feed_item',
    '[class*="feed_item"]',
    '[class*="FeedItem"]',
    '[class*="FeedCard"]',
    '[class*="single_feed"]',
  ].join(',');
  const CARD_SEL = [
    '.fcom_url_preview',
    '.fcom_link_preview',
    '.fcom-link-preview',
    '.fcom_og_preview',
    '[class*="link_preview"]',
    '[class*="url-preview"]',
    '[class*="og_preview"]',
    '[class*="media_preview"]',
    '[class*="MediaPreview"]',
  ].join(',');

  function embedSrc(library, video, autoplay) {
    return 'https://player.mediadelivery.net/embed/'
      + encodeURIComponent(library) + '/' + encodeURIComponent(video)
      + '?autoplay=' + (autoplay ? 'true' : 'false')
      + '&preload=true&responsive=true';
  }

  function iframeEl(library, video, autoplay) {
    const wrap = document.createElement('div');
    wrap.className = 'orgasmic-bunny-embed';
    wrap.setAttribute('data-orgasmic-bunny', library + '/' + video);
    const iframe = document.createElement('iframe');
    iframe.src = embedSrc(library, video, autoplay);
    iframe.allow = 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen';
    iframe.allowFullscreen = true;
    iframe.referrerPolicy = 'strict-origin-when-cross-origin';
    iframe.title = 'Video';
    wrap.appendChild(iframe);
    return wrap;
  }

  function parseHref(href) {
    const m = String(href || '').match(RE);
    return m ? { library: m[1], video: m[2].toLowerCase() } : null;
  }

  function parseText(text) {
    const m = String(text || '').match(RE);
    return m ? { library: m[1], video: m[2].toLowerCase() } : null;
  }

  function hideNode(node) {
    if (!node || node.nodeType !== 1 || node.dataset.orgasmicBunnyHidden === '1') return;
    if (node.querySelector && node.querySelector('iframe[src*="mediadelivery.net"]')) {
      return;
    }
    node.dataset.orgasmicBunnyHidden = '1';
    node.hidden = true;
    node.style.setProperty('display', 'none', 'important');
  }

  function hideWrap(node) {
    if (!node || node.nodeType !== 1) return;
    node.dataset.orgasmicBunnyHidden = '1';
    node.hidden = true;
    node.style.setProperty('display', 'none', 'important');
  }

  function postRoot(node) {
    if (!node || !node.closest) return document.body;
    const known = node.closest(POST_SEL);
    if (known && known !== document.body) return known;

    let el = node.parentElement;
    let fallback = el || document.body;
    for (let i = 0; i < 8 && el && el !== document.body; i++) {
      const cls = String(el.className || '');
      if (/(^|\s)(fcom_|feed_|post_|activity_)/i.test(cls) && el.querySelector) {
        const hasMedia = el.querySelector('iframe[src*="mediadelivery.net"], a[href*="mediadelivery.net"], ' + CARD_SEL);
        const hasText = (el.textContent || '').length > 30;
        if (hasMedia && hasText) return el;
        fallback = el;
      }
      el = el.parentElement;
    }
    return fallback;
  }

  function parsedFrom(node) {
    if (!node) return null;
    const own = parseHref(node.getAttribute && node.getAttribute('href'))
      || parseHref((node.querySelector && node.querySelector('a[href*="mediadelivery.net"]') || {}).href)
      || parseHref(node.getAttribute && node.getAttribute('src'))
      || parseText(node.getAttribute && (node.getAttribute('href') || node.getAttribute('src')))
      || parseText(node.textContent);
    if (own) return own;
    if (/mediadelivery\.net/i.test(node.textContent || '')) {
      return parseText(postRoot(node).textContent);
    }
    return null;
  }

  function playerWrap(iframe) {
    const ours = iframe.closest && iframe.closest('.orgasmic-bunny-embed');
    if (ours) return ours;
    const parent = iframe.parentElement;
    if (parent && parent.querySelectorAll('iframe[src*="mediadelivery.net"]').length === 1) {
      return parent;
    }
    return iframe;
  }

  function findPlayer(root, video) {
    if (!root || !root.querySelectorAll) return null;
    const iframes = root.querySelectorAll('iframe[src*="mediadelivery.net"]');
    for (const iframe of iframes) {
      const parsed = parseHref(iframe.src);
      if (parsed && parsed.video === video && playerWrap(iframe).dataset.orgasmicBunnyHidden !== '1') {
        return playerWrap(iframe);
      }
    }
    return null;
  }

  function startExisting(wrap, parsed) {
    if (!wrap || !parsed) return false;
    const iframe = wrap.tagName === 'IFRAME' ? wrap : wrap.querySelector('iframe');
    if (!iframe) return false;
    iframe.src = embedSrc(parsed.library, parsed.video, true);
    return true;
  }

  function collapse(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const iframes = [...scope.querySelectorAll('iframe[src*="mediadelivery.net"]')];
    const seen = new Set();

    iframes.forEach((iframe) => {
      const parsed = parseHref(iframe.src);
      if (!parsed) return;
      const wrap = playerWrap(iframe);
      if (wrap.dataset.orgasmicBunnyHidden === '1') return;

      const post = postRoot(iframe);
      if (post && post.dataset && !post.dataset.orgasmicBunnyPost) {
        post.dataset.orgasmicBunnyPost = 'p' + Math.random().toString(36).slice(2, 8);
      }
      const dedupe = (post && post.dataset ? post.dataset.orgasmicBunnyPost : 'page') + '|' + parsed.video;

      if (wrap.classList) wrap.classList.add('orgasmic-bunny-embed');
      if (wrap.setAttribute) wrap.setAttribute('data-orgasmic-bunny', parsed.library + '/' + parsed.video);

      if (seen.has(dedupe)) {
        hideWrap(wrap);
        return;
      }
      seen.add(dedupe);
    });
  }

  function hideOgBits(root, parsed) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('a[href*="mediadelivery.net"]').forEach((a) => {
      const found = parseHref(a.getAttribute('href'));
      if (!found || found.video !== parsed.video) return;
      if (a.closest('iframe') || a.closest('.orgasmic-bunny-embed')) return;
      hideNode(a.closest(CARD_SEL) || a);
    });
    root.querySelectorAll(CARD_SEL).forEach((card) => {
      if (card.querySelector('iframe[src*="mediadelivery.net"]')) return;
      const found = parsedFrom(card);
      if (found && found.video === parsed.video) hideNode(card);
    });
  }

  function placeEmbed(anchorPoint, parsed, autoplay) {
    const root = postRoot(anchorPoint);
    const current = findPlayer(root, parsed.video);
    if (current) {
      if (autoplay) startExisting(current, parsed);
      hideOgBits(root, parsed);
      return current;
    }

    const embed = iframeEl(parsed.library, parsed.video, autoplay);
    const card = anchorPoint.closest ? anchorPoint.closest(CARD_SEL) : null;
    const target = (card && !card.querySelector('iframe[src*="mediadelivery.net"]')) ? card : anchorPoint;

    if (target && target.insertAdjacentElement) {
      target.insertAdjacentElement('afterend', embed);
    } else if (target && target.parentNode) {
      target.parentNode.insertBefore(embed, target.nextSibling);
    }
    hideOgBits(root, parsed);
    collapse(root);
    return embed;
  }

  function matchesSelf(node, selector) {
    return !!(node && node.nodeType === 1 && node.matches && node.matches(selector));
  }

  function enhance(root, autoplay) {
    collapse(root);
    const scope = root && root.querySelectorAll ? root : document;
    const seen = new Set();
    const candidates = [];

    const push = (node) => {
      if (!node || node.closest && node.closest('.orgasmic-bunny-embed')) return;
      const parsed = parsedFrom(node);
      if (parsed) candidates.push({ node: node, parsed: parsed });
    };

    if (matchesSelf(scope, 'a[href*="mediadelivery.net"]') || matchesSelf(scope, CARD_SEL)) {
      push(scope);
    }
    if (scope.querySelectorAll) {
      scope.querySelectorAll('a[href*="mediadelivery.net"]').forEach(push);
      scope.querySelectorAll(CARD_SEL).forEach((card) => {
        if (card.dataset.orgasmicBunnyHidden === '1') return;
        if (card.querySelector('iframe[src*="mediadelivery.net"]')) return;
        push(card);
      });
    }

    candidates.forEach((item) => {
      const post = postRoot(item.node);
      if (post && post.dataset && !post.dataset.orgasmicBunnyPost) {
        post.dataset.orgasmicBunnyPost = 'p' + Math.random().toString(36).slice(2, 8);
      }
      const key = ((post && post.dataset && post.dataset.orgasmicBunnyPost) || 'page') + '|' + item.parsed.video;
      if (seen.has(key)) {
        hideOgBits(post, item.parsed);
        return;
      }
      seen.add(key);
      placeEmbed(item.node, item.parsed, !!autoplay);
    });

    collapse(document);
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest && e.target.closest('.orgasmic-bunny-embed, iframe[src*="mediadelivery.net"]')) return;

    const hit = e.target.closest && (
      e.target.closest('a[href*="mediadelivery.net"]')
      || e.target.closest(CARD_SEL)
    );
    if (!hit) return;

    const parsed = parsedFrom(hit);
    if (!parsed) return;

    e.preventDefault();
    e.stopPropagation();
    placeEmbed(hit, parsed, true);
  }, true);

  let scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      enhance(document, false);
    });
  }

  const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (node.nodeType === 1 && !(node.closest && node.closest('.orgasmic-bunny-embed'))) {
          schedule();
          return;
        }
      }
    }
  });

  function start() {
    enhance(document, true);
    if (document.body) {
      observer.observe(document.body, { childList: true, subtree: true });
    }
    let ticks = 0;
    const timer = setInterval(() => {
      enhance(document, false);
      ticks += 1;
      if (ticks >= 16) clearInterval(timer);
    }, 400);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
