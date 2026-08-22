(function () {
  const RE = /https?:\/\/(?:iframe|player)\.mediadelivery\.net\/(?:embed|play)\/(\d+)\/([0-9a-f-]{8,})/i;
  const FEED_SEL = [
    '[data-feed_id]',
    '[data-feed-id]',
    '.fcom_feed',
    '.fcom-feed-item',
    '.feed_item',
    '[class*="feed_item"]',
    '[class*="FeedCard"]',
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
    node.dataset.orgasmicBunnyHidden = '1';
    node.hidden = true;
    node.style.setProperty('display', 'none', 'important');
  }

  function feedRoot(node) {
    return (node && node.closest && node.closest(FEED_SEL)) || (node && node.parentElement) || document.body;
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

  function parsedFrom(node) {
    if (!node) return null;
    return parseHref(node.getAttribute && node.getAttribute('href'))
      || parseHref((node.querySelector && node.querySelector('a[href*="mediadelivery.net"]') || {}).href)
      || parseText(node.getAttribute && (node.getAttribute('href') || node.getAttribute('src')))
      || parseText(node.textContent)
      || parseText(feedRoot(node).textContent);
  }

  function existingEmbed(root, video) {
    return root && root.querySelector
      ? root.querySelector('.orgasmic-bunny-embed[data-orgasmic-bunny$="/' + video + '"]')
      : null;
  }

  function startExisting(embed, parsed) {
    if (!embed) return false;
    const iframe = embed.querySelector('iframe');
    if (!iframe || !parsed) return false;
    iframe.src = embedSrc(parsed.library, parsed.video, true);
    return true;
  }

  function placeEmbed(anchorPoint, parsed, autoplay) {
    const root = feedRoot(anchorPoint);
    const current = existingEmbed(root, parsed.video);
    if (current) {
      if (autoplay) startExisting(current, parsed);
      return current;
    }

    const embed = iframeEl(parsed.library, parsed.video, autoplay);
    const card = anchorPoint.closest ? anchorPoint.closest(CARD_SEL) : null;
    const target = card || anchorPoint;

    if (target && target.insertAdjacentElement) {
      target.insertAdjacentElement('afterend', embed);
    } else if (target && target.parentNode) {
      target.parentNode.insertBefore(embed, target.nextSibling);
    }
    hideNode(target);
    return embed;
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
      const found = parsedFrom(card);
      if (found && found.video === parsed.video) hideNode(card);
    });
  }

  function matchesSelf(node, selector) {
    return !!(node && node.nodeType === 1 && node.matches && node.matches(selector));
  }

  function enhance(root, autoplay) {
    const scope = root && root.querySelectorAll ? root : document;
    const seen = new Set();
    const candidates = [];

    const push = (node) => {
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
        push(card);
      });
    }

    candidates.forEach((item) => {
      const key = feedKey(item.node) + '|' + item.parsed.video;
      if (seen.has(key)) {
        hideDuplicates(feedRoot(item.node), item.parsed);
        return;
      }
      seen.add(key);
      placeEmbed(item.node, item.parsed, !!autoplay);
      hideDuplicates(feedRoot(item.node), item.parsed);
    });
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest && e.target.closest('.orgasmic-bunny-embed')) return;

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
    hideDuplicates(feedRoot(hit), parsed);
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
      if (mutation.removedNodes && mutation.removedNodes.length) {
        for (const node of mutation.removedNodes) {
          if (node.nodeType === 1 && (node.classList && node.classList.contains('orgasmic-bunny-embed')
            || (node.querySelector && node.querySelector('.orgasmic-bunny-embed')))) {
            schedule();
          }
        }
      }
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
      if (ticks >= 20) clearInterval(timer);
    }, 500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
