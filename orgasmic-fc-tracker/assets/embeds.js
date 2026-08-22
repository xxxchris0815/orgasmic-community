(function () {
  const RE = /https?:\/\/(?:iframe|player)\.mediadelivery\.net\/(?:embed|play)\/(\d+)\/([0-9a-f-]{8,})/i;
  const FEED_SEL = [
    '.fcom_feed',
    '.fcom-feed-item',
    '.feed_item',
    '[data-feed_id]',
    '[data-feed-id]',
    '[class*="feed_item"]',
    '[class*="FeedCard"]',
    'article',
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

  function iframeEl(library, video) {
    const wrap = document.createElement('div');
    wrap.className = 'orgasmic-bunny-embed';
    wrap.setAttribute('data-orgasmic-bunny', library + '/' + video);
    const iframe = document.createElement('iframe');
    iframe.src = 'https://player.mediadelivery.net/embed/'
      + encodeURIComponent(library) + '/' + encodeURIComponent(video)
      + '?autoplay=false&preload=true&responsive=true';
    iframe.loading = 'lazy';
    iframe.allow = 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen';
    iframe.allowFullscreen = true;
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
    if (!node || node.dataset.orgasmicBunnyHidden === '1') return;
    node.dataset.orgasmicBunnyHidden = '1';
    node.hidden = true;
    node.style.setProperty('display', 'none', 'important');
  }

  function feedRoot(node) {
    return (node && node.closest && node.closest(FEED_SEL)) || node || document.body;
  }

  function feedKey(node) {
    const feed = node && node.closest && node.closest('[data-feed_id], [data-feed-id]');
    if (feed) {
      return feed.getAttribute('data-feed_id') || feed.getAttribute('data-feed-id') || '';
    }
    const root = feedRoot(node);
    if (root && root.dataset && root.dataset.orgasmicBunnyFeed) {
      return root.dataset.orgasmicBunnyFeed;
    }
    if (root && root.dataset) {
      root.dataset.orgasmicBunnyFeed = 'f' + Math.random().toString(36).slice(2, 9);
      return root.dataset.orgasmicBunnyFeed;
    }
    return 'page';
  }

  function alreadyHas(root, video) {
    return !!(root && root.querySelector && root.querySelector('.orgasmic-bunny-embed[data-orgasmic-bunny$="/' + video + '"]'));
  }

  function placeEmbed(anchorPoint, parsed) {
    const root = feedRoot(anchorPoint);
    if (alreadyHas(root, parsed.video)) {
      return;
    }

    const embed = iframeEl(parsed.library, parsed.video);
    const card = anchorPoint.closest ? anchorPoint.closest(CARD_SEL) : null;
    const target = card || anchorPoint;

    if (target && target.insertAdjacentElement) {
      target.insertAdjacentElement('afterend', embed);
    } else if (target && target.parentNode) {
      target.parentNode.insertBefore(embed, target.nextSibling);
    }
    hideNode(target);
  }

  function hideDuplicates(root, parsed) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('a[href*="mediadelivery.net"]').forEach((a) => {
      const found = parseHref(a.getAttribute('href'));
      if (!found || found.video !== parsed.video) return;
      if (a.closest('.orgasmic-bunny-embed')) return;
      hideNode(a.closest(CARD_SEL) || a);
    });
    root.querySelectorAll(CARD_SEL).forEach((card) => {
      if (card.dataset.orgasmicBunnyHidden === '1') return;
      const found = parseHref((card.querySelector('a[href*="mediadelivery.net"]') || {}).href)
        || parseText(card.textContent);
      if (found && found.video === parsed.video) hideNode(card);
    });
  }

  function enhance(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const seen = new Set();
    const candidates = [];

    scope.querySelectorAll('a[href*="mediadelivery.net"]').forEach((a) => {
      const parsed = parseHref(a.getAttribute('href'));
      if (parsed) candidates.push({ node: a, parsed: parsed });
    });
    scope.querySelectorAll(CARD_SEL).forEach((card) => {
      if (card.dataset.orgasmicBunnyHidden === '1') return;
      const parsed = parseHref((card.querySelector('a[href*="mediadelivery.net"]') || {}).href)
        || parseText(card.textContent);
      if (parsed) candidates.push({ node: card, parsed: parsed });
    });

    candidates.forEach((item) => {
      const key = feedKey(item.node) + '|' + item.parsed.video;
      if (seen.has(key)) {
        hideDuplicates(feedRoot(item.node), item.parsed);
        return;
      }
      seen.add(key);
      placeEmbed(item.node, item.parsed);
      hideDuplicates(feedRoot(item.node), item.parsed);
    });
  }

  document.addEventListener('click', (e) => {
    const hit = e.target.closest && (
      e.target.closest('a[href*="mediadelivery.net"]')
      || e.target.closest(CARD_SEL)
    );
    if (!hit) return;

    const parsed = parseHref(hit.getAttribute && hit.getAttribute('href'))
      || parseHref((hit.querySelector && hit.querySelector('a[href*="mediadelivery.net"]') || {}).href)
      || parseText(hit.textContent);
    if (!parsed) return;

    e.preventDefault();
    e.stopPropagation();
    placeEmbed(hit, parsed);
    hideDuplicates(feedRoot(hit), parsed);
  }, true);

  let scheduled = false;
  function schedule(node) {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      enhance(node || document);
    });
  }

  const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (node.nodeType === 1 && !(node.closest && node.closest('.orgasmic-bunny-embed'))) {
          schedule(node);
        }
      }
    }
  });

  function start() {
    enhance(document);
    if (document.body) {
      observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
