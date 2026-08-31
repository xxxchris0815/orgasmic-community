(function () {
  const RE = /https?:\/\/(?:iframe|player)\.mediadelivery\.net\/(?:embed|play)\/(\d+)\/([0-9a-f-]{8,})/i;
  const COMPOSER_SEL = [
    '[contenteditable="true"]',
    'textarea',
    'input:not([type="hidden"])',
    '.ql-editor',
    '.ProseMirror',
    '.fcom_editor',
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

  function parseHref(href) {
    const m = String(href || '').match(RE);
    return m ? { library: m[1], video: m[2].toLowerCase() } : null;
  }

  function inComposer(node) {
    if (!node || !node.closest) return false;
    if (node.closest(COMPOSER_SEL)) return true;
    const dialog = node.closest('.el-dialog, .el-drawer');
    return !!(dialog && /Attach Media|Ein Video|OEmbed|HTML Code/i.test(dialog.textContent || ''));
  }

  function hide(node) {
    if (!node || node.nodeType !== 1 || node.dataset.orgasmicBunnyHidden === '1') return;
    if (inComposer(node)) return;
    if (node.querySelector && node.querySelector('iframe[src*="mediadelivery.net"]')) return;
    node.dataset.orgasmicBunnyHidden = '1';
    node.hidden = true;
    node.style.setProperty('display', 'none', 'important');
  }

  function cluster(node) {
    if (!node) return document.body;
    let el = node.parentElement || node;
    for (let i = 0; i < 5 && el.parentElement && el.parentElement !== document.body; i++) {
      el = el.parentElement;
    }
    return el;
  }

  function clusterKey(node, video) {
    const el = cluster(node);
    if (el.dataset && !el.dataset.orgasmicBunnyCluster) {
      el.dataset.orgasmicBunnyCluster = 'c' + Math.random().toString(36).slice(2, 8);
    }
    return video + '@' + (el.dataset ? el.dataset.orgasmicBunnyCluster : 'page');
  }

  function bunnyIframes(root) {
    if (!root || !root.querySelectorAll) return [];
    return [...root.querySelectorAll('iframe[src*="mediadelivery.net"]')].filter((iframe) => {
      const wrap = iframe.closest('.orgasmic-bunny-embed') || iframe;
      return wrap.dataset.orgasmicBunnyHidden !== '1' && !inComposer(iframe);
    });
  }

  function findPlayer(root, video) {
    return bunnyIframes(root).find((iframe) => {
      const parsed = parseHref(iframe.src);
      return parsed && parsed.video === video;
    }) || null;
  }

  function autoplayEnabled() {
    return !!(window.OrgasmicFcEmbeds && window.OrgasmicFcEmbeds.autoplay);
  }

  function embedSrc(library, video, autoplay) {
    return 'https://iframe.mediadelivery.net/embed/'
      + encodeURIComponent(library) + '/' + encodeURIComponent(video)
      + '?autoplay=' + (autoplay ? 'true' : 'false')
      + '&preload=true&responsive=true&playerjs=true';
  }

  function inFeedPost(node) {
    if (!node || !node.closest) return false;
    const post = node.closest('.each_feed, [class*="each_feed"], [class*="EachFeed"], [class*="single_feed"], .fcom_single_feed');
    if (post) {
      const w = post.clientWidth || 0;
      if (w === 0 || (w >= 280 && w <= 1100)) return true;
    }
    let el = node;
    for (let i = 0; i < 10 && el && el !== document.body; i += 1) {
      const w = el.clientWidth || 0;
      const h = el.offsetHeight || 0;
      if (w >= 280 && w <= 1100 && h >= 150 && h < 2200
        && el.querySelector
        && el.querySelector(':scope > .feed_reaction_meta, :scope .feed_reaction_meta, :scope [class*="feed_reaction"]')) {
        return true;
      }
      el = el.parentElement;
    }
    return false;
  }

  function isTrendingTitle(el) {
    if (!el) return false;
    const tag = el.tagName || '';
    const text = el.textContent || '';
    if (!/angesagte|popular|trending|featured|beliebte beitr/i.test(text)) return false;
    if (text.length > 80) return false;
    return /^H[1-4]$/.test(tag)
      || (el.classList && el.classList.contains('widget_title'))
      || /widget_title|WidgetTitle|sidebar_title/i.test(el.className || '');
  }

  function headingIsTrending(el) {
    if (!el || !el.children) return false;
    return [...el.children].some((child) => {
      if (isTrendingTitle(child)) return true;
      return [...(child.children || [])].some(isTrendingTitle);
    });
  }

  function isCompactContext(node) {
    if (!node || !node.closest) return false;
    if (node.closest('#orgasmic-chat-root, #orgasmic-cal-root, [contenteditable="true"]')) return false;
    if (inFeedPost(node)) return false;
    if (node.closest([
      '.fcom_right_sidebar',
      '.fcom-portal-sidebar',
      '.fcom_space_sidebar',
      '[class*="popular_post"]',
      '[class*="PopularPost"]',
      '[class*="featured_post"]',
      '[class*="FeaturedPost"]',
      '[class*="trending_post"]',
    ].join(','))) {
      return true;
    }
    let el = node;
    for (let i = 0; i < 8 && el && el !== document.body; i += 1) {
      if (headingIsTrending(el)) return true;
      el = el.parentElement;
    }
    return false;
  }

  function chipEl() {
    const chip = document.createElement('span');
    chip.className = 'orgasmic-bunny-chip';
    chip.setAttribute('data-orgasmic-bunny-chip', '1');
    chip.innerHTML = '<span class="orgasmic-bunny-chip-play" aria-hidden="true"></span> Video';
    return chip;
  }

  function compactWrap(library, video) {
    const wrap = document.createElement('div');
    wrap.className = 'orgasmic-bunny-embed is-compact';
    wrap.setAttribute('data-orgasmic-bunny', library + '/' + video);
    wrap.dataset.bunnyCompact = '1';
    wrap.appendChild(chipEl());
    return wrap;
  }

  function toChip(wrap) {
    if (!wrap || wrap.dataset.bunnyCompact === '1') return;
    wrap.dataset.bunnyCompact = '1';
    wrap.classList.add('is-compact');
    wrap.querySelectorAll('iframe').forEach((f) => f.remove());
    if (!wrap.querySelector('[data-orgasmic-bunny-chip]')) {
      wrap.appendChild(chipEl());
    }
  }

  function restorePlayer(wrap) {
    if (!wrap || wrap.dataset.bunnyCompact !== '1') return;
    const key = String(wrap.getAttribute('data-orgasmic-bunny') || '');
    const parts = key.split('/');
    if (parts.length < 2) return;
    wrap.dataset.bunnyCompact = '';
    delete wrap.dataset.bunnyCompact;
    wrap.classList.remove('is-compact');
    wrap.querySelectorAll('[data-orgasmic-bunny-chip]').forEach((el) => el.remove());
    if (!wrap.querySelector('iframe')) {
      const iframe = document.createElement('iframe');
      iframe.src = embedSrc(parts[0], parts[1], autoplayEnabled());
      iframe.allow = 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen';
      iframe.allowFullscreen = true;
      iframe.referrerPolicy = 'strict-origin-when-cross-origin';
      iframe.title = 'Video';
      wrap.appendChild(iframe);
    }
  }

  function compactify() {
    document.querySelectorAll('.orgasmic-bunny-embed').forEach((wrap) => {
      if (inFeedPost(wrap) || !isCompactContext(wrap)) {
        if (wrap.dataset.bunnyCompact === '1') restorePlayer(wrap);
        return;
      }
      toChip(wrap);
    });
    document.querySelectorAll('iframe[src*="mediadelivery.net"]').forEach((iframe) => {
      if (inComposer(iframe) || inFeedPost(iframe) || !isCompactContext(iframe)) return;
      const wrap = iframe.closest('.orgasmic-bunny-embed');
      if (wrap) {
        toChip(wrap);
        return;
      }
      const parsed = parseHref(iframe.src);
      if (!parsed) return;
      iframe.replaceWith(compactWrap(parsed.library, parsed.video));
    });
  }

  function scrubPlayUrls(root) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('a[href*="mediadelivery.net"]').forEach((a) => {
      if (inComposer(a) || a.closest('.orgasmic-bunny-embed')) return;
      hide(a);
    });
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        if (!node || !node.nodeValue || !RE.test(node.nodeValue)) return NodeFilter.FILTER_REJECT;
        const el = node.parentElement;
        if (!el || inComposer(el) || el.closest('.orgasmic-bunny-embed, script, style, textarea, input')) {
          return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      },
    });
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((textNode) => {
      const next = String(textNode.nodeValue || '').replace(RE, ' ').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n');
      textNode.nodeValue = next;
      const el = textNode.parentElement;
      if (el && !String(el.textContent || '').trim()) hide(el);
    });
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

  function hidePreviewBits(root, video) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('a[href*="mediadelivery.net"]').forEach((a) => {
      if (inComposer(a) || a.closest('.orgasmic-bunny-embed')) return;
      const parsed = parseHref(a.getAttribute('href'));
      if (parsed && parsed.video === video) hide(a);
    });
    root.querySelectorAll(CARD_SEL).forEach((card) => {
      if (inComposer(card) || card.querySelector('iframe[src*="mediadelivery.net"]')) return;
      const parsed = parseHref((card.querySelector('a[href*="mediadelivery.net"]') || {}).href)
        || parseHref(card.textContent);
      if (parsed && parsed.video === video) hide(card);
    });
  }

  function collapse() {
    const groups = new Map();
    bunnyIframes(document).forEach((iframe) => {
      const parsed = parseHref(iframe.src);
      if (!parsed) return;
      const key = clusterKey(iframe, parsed.video);
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(iframe);
    });

    groups.forEach((list) => {
      list.slice(1).forEach((iframe) => {
        const wrap = iframe.closest('.orgasmic-bunny-embed') || iframe;
        wrap.dataset.orgasmicBunnyHidden = '1';
        wrap.hidden = true;
        wrap.style.setProperty('display', 'none', 'important');
      });
    });
  }

  function parsedFrom(node) {
    if (!node || inComposer(node)) return null;
    return parseHref(node.getAttribute && node.getAttribute('href'))
      || parseHref((node.querySelector && node.querySelector('a[href*="mediadelivery.net"]') || {}).href)
      || parseHref(node.textContent);
  }

  function place(node, parsed) {
    const root = cluster(node);
    if (findPlayer(root, parsed.video)) {
      hidePreviewBits(root, parsed.video);
      return;
    }

    const card = node.closest && node.closest(CARD_SEL);
    const target = (card && !card.querySelector('iframe[src*="mediadelivery.net"], [data-orgasmic-bunny-chip]')) ? card : node;
    const embed = isCompactContext(node)
      ? compactWrap(parsed.library, parsed.video)
      : iframeEl(parsed.library, parsed.video, autoplayEnabled());
    target.insertAdjacentElement('afterend', embed);
    hidePreviewBits(root, parsed.video);
    if (isCompactContext(node) || isCompactContext(embed)) {
      const widget = node.closest('li, article, [class*="popular"], [class*="featured"], [class*="trending"]') || cluster(node);
      scrubPlayUrls(widget);
    }
    collapse();
  }

  function enhanceLooseUrls() {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        if (!node || !node.nodeValue || !RE.test(node.nodeValue)) return NodeFilter.FILTER_REJECT;
        const el = node.parentElement;
        if (!el || inComposer(el) || el.closest('.orgasmic-bunny-embed, script, style, textarea, input')) {
          return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      },
    });
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((textNode) => {
      const parsed = parseHref(textNode.nodeValue);
      if (!parsed) return;
      const el = textNode.parentElement;
      if (!el) return;
      const block = el.closest('p, div, li, article, section') || el;
      const compact = isCompactContext(block);
      if (findPlayer(cluster(block), parsed.video) || block.querySelector('[data-orgasmic-bunny-chip]')) {
        textNode.nodeValue = String(textNode.nodeValue || '').replace(RE, ' ').replace(/[ \t]+/g, ' ');
        if (!String(el.textContent || '').trim()) hide(el);
        return;
      }
      place(block, parsed);
      textNode.nodeValue = String(textNode.nodeValue || '').replace(RE, ' ').replace(/[ \t]+/g, ' ');
      if (compact) scrubPlayUrls(block);
      else if (/^\s*$/.test(textNode.nodeValue || '')) hide(el);
    });
  }

  function enhance() {
    if (!document.body) return;
    collapse();
    const seen = new Set();

    document.querySelectorAll('a[href*="mediadelivery.net"], ' + CARD_SEL).forEach((node) => {
      if (inComposer(node) || node.closest('.orgasmic-bunny-embed')) return;
      if (node.matches && node.matches(CARD_SEL) && node.querySelector('iframe[src*="mediadelivery.net"]')) {
        return;
      }
      const parsed = parsedFrom(node);
      if (!parsed) return;

      const root = cluster(node);
      const key = clusterKey(node, parsed.video);
      if (seen.has(key)) {
        hidePreviewBits(root, parsed.video);
        return;
      }
      seen.add(key);

      if (findPlayer(root, parsed.video)) {
        hidePreviewBits(root, parsed.video);
        return;
      }
      place(node, parsed);
    });

    enhanceLooseUrls();
    compactify();
    document.querySelectorAll('.fcom_right_sidebar, .fcom-portal-sidebar, .fcom_space_sidebar').forEach(scrubPlayUrls);
    document.querySelectorAll('h2, h3, h4, .widget_title').forEach((title) => {
      if (!/angesagte|popular|trending|featured|beliebte beitr/i.test(title.textContent || '')) return;
      const box = title.closest('aside, section, div') || title.parentElement;
      if (box) scrubPlayUrls(box);
    });
    collapse();
  }

  document.addEventListener('click', (e) => {
    if (!e.target.closest) return;
    if (e.target.closest('.orgasmic-bunny-embed, iframe[src*="mediadelivery.net"]')) return;
    if (e.target.closest(COMPOSER_SEL)) return;

    const hit = e.target.closest('a[href*="mediadelivery.net"]') || e.target.closest(CARD_SEL);
    if (!hit) return;
    const parsed = parsedFrom(hit);
    if (!parsed) return;
    e.preventDefault();
    e.stopPropagation();
    place(hit, parsed);
  }, true);

  let scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      enhance();
    });
  }

  const observer = new MutationObserver(schedule);

  function start() {
    enhance();
    if (document.body) {
      observer.observe(document.body, { childList: true, subtree: true });
    }
    let ticks = 0;
    const timer = setInterval(() => {
      enhance();
      ticks += 1;
      if (ticks >= 20) clearInterval(timer);
    }, 400);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
