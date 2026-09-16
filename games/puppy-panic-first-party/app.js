(function () {
  "use strict";

  const ASSET_ROOT = "../../assets/images/puppy-panic/cards/";
  const CARDS = {
    "midnight-zoomies": ["Midnight Zoomies", "CHAOS PUPPY", "chaos-midnight-zoomies-corgi.png", "Chaos"],
    "mudroom-stampede": ["Mudroom Stampede", "CHAOS PUPPY", "chaos-mudroom-stampede.png", "Chaos"],
    "shoe-shredder": ["Shoe Shredder", "CHAOS PUPPY", "chaos-shoe-shredder-brindle.png", "Chaos"],
    "cushion-catastrophe": ["Couch-Cushion Catastrophe", "CHAOS PUPPY", "chaos-couch-cushion-catastrophe.png", "Chaos"],
    "squeaky-toy": ["Squeaky Toy", "CALM DOWN", "calm-squeaky-toy.png", "Calm Down"],
    "peanut-butter-puzzle": ["Peanut Butter Puzzle", "CALM DOWN", "calm-peanut-butter-puzzle.png", "Calm Down"],
    "belly-rub": ["Belly Rub", "CALM DOWN", "calm-belly-rub.png", "Calm Down"],
    "treat-trail": ["Treat Trail", "CALM DOWN", "calm-treat-trail.png", "Calm Down"],
    "cozy-blanket": ["Cozy Blanket", "CALM DOWN", "calm-cozy-blanket.png", "Calm Down"],
    "good-pup": ["Who's a Good Pup?", "CALM DOWN", "calm-good-pup.png", "Calm Down"],
    "puppy-pile-on": ["Puppy Pile-On", "ACTION", "action-puppy-pile-on.png", "Action"],
    "nap-time": ["Nap Time", "ACTION", "action-nap-time.png", "Action"],
    "puppy-cam": ["Puppy Cam", "ACTION", "action-puppy-cam.png", "Action"],
    "squirrel": ["Squirrel!", "ACTION", "action-squirrel-corgi.png", "Action"],
    "puppy-eyes": ["Puppy Eyes", "ACTION", "action-puppy-eyes.png", "Action"],
    "not-today": ["Not Today!", "ACTION", "action-not-today.png", "Action"],
    "sock-bandit": ["Sock Bandit", "MATCHING", "family-sock-bandit.png", "Matching"],
    "doorbell-detective": ["Doorbell Detective", "MATCHING", "family-doorbell-detective.png", "Matching"],
    "bubble-beard-bath-pup": ["Bubble-Beard Bath Pup", "MATCHING", "family-bubble-beard-bath-pup.png", "Matching"],
    "vacuum-nemesis": ["Vacuum Nemesis", "MATCHING", "family-vacuum-nemesis.png", "Matching"],
    "under-the-couch": ["Under the Couch", "MISCHIEF PACK", "mischief-under-the-couch.png", "Mischief"],
    "trainers-plan": ["Trainer's Plan", "MISCHIEF PACK", "mischief-trainers-plan.png", "Mischief"],
    "fetch-this": ["Fetch This!", "MISCHIEF PACK", "mischief-fetch-this.png", "Mischief"],
    "toy-basket-flip": ["Toy Basket Flip", "MISCHIEF PACK", "mischief-toy-basket-flip.png", "Mischief"]
  };
  const RULES = {
    "midnight-zoomies": "Reveal immediately. Play 1 Calm Down or you're out.",
    "mudroom-stampede": "Reveal immediately. Play 1 Calm Down or you're out.",
    "shoe-shredder": "Reveal immediately. Play 1 Calm Down or you're out.",
    "cushion-catastrophe": "Reveal immediately. Play 1 Calm Down or you're out.",
    "squeaky-toy": "Survive a Chaos Puppy. Secretly return it anywhere in the draw pile.",
    "peanut-butter-puzzle": "Survive a Chaos Puppy. Secretly return it anywhere in the draw pile.",
    "belly-rub": "Survive a Chaos Puppy. Secretly return it anywhere in the draw pile.",
    "treat-trail": "Survive a Chaos Puppy. Secretly return it anywhere in the draw pile.",
    "cozy-blanket": "Survive a Chaos Puppy. Secretly return it anywhere in the draw pile.",
    "good-pup": "Survive a Chaos Puppy. Secretly return it anywhere in the draw pile.",
    "puppy-pile-on": "Pass the current turn debt to the next player and add 2 required turns.",
    "nap-time": "End 1 required turn without drawing.",
    "puppy-cam": "Privately view the top 3 cards.",
    squirrel: "Shuffle the draw pile.",
    "puppy-eyes": "Choose a player. They choose 1 card to give you.",
    "not-today": "Cancel an action. Cannot cancel Chaos Puppy or Calm Down.",
    "sock-bandit": "Play 2 matching: steal 1 random card. Play 3 matching: request 1 named card.",
    "doorbell-detective": "Play 2 matching: steal 1 random card. Play 3 matching: request 1 named card.",
    "bubble-beard-bath-pup": "Play 2 matching: steal 1 random card. Play 3 matching: request 1 named card.",
    "vacuum-nemesis": "Play 2 matching: steal 1 random card. Play 3 matching: request 1 named card.",
    "under-the-couch": "Draw from the bottom to complete 1 required turn.",
    "trainers-plan": "Privately view and reorder the top 3 cards.",
    "fetch-this": "Choose a player. Transfer the current turn debt to them and add 2 required turns.",
    "toy-basket-flip": "Swap the top and bottom cards without viewing either."
  };
  const KIND_ORDER = { "Calm Down": 0, Action: 1, Matching: 2, Mischief: 3, Chaos: 4 };
  const DEFAULT_ACTIONS = { deal: "deal", play: "play", combo: "combo", draw: "draw", calm: "calm", eliminate: "eliminate", counter: "counter", settleAction: "settle-action", settleRandom: "settle-random", give: "give-card", reorder: "reorder" };
  const RANDOMNESS_PURPOSES = { deal: "puppy-panic-deal", "settle-random": "puppy-panic-random-effect" };
  const EFFECTS = { "puppy-pile-on": "pile-on", "nap-time": "nap", "puppy-cam": "peek", squirrel: "shuffle", "puppy-eyes": "favor", "not-today": "counter", "under-the-couch": "bottom-draw", "trainers-plan": "reorder", "fetch-this": "target-attack", "toy-basket-flip": "flip" };
  const BOT_AVATARS = {
    3: "calm-good-pup.png",
    4: "action-squirrel-corgi.png",
    5: "family-sock-bandit.png"
  };
  const selected = new Set();
  let historyOpen = false;
  let busyLocal = false;
  let pendingSettlementTimer = 0;
  let pendingSettlementKey = "";
  let activeRenderContext = null;
  let renderActionEpoch = 0;
  let submittedActionEpoch = -1;

  const make = (tag, cls, text) => { const value = document.createElement(tag); if (cls) value.className = cls; if (text !== undefined) value.textContent = text; return value; };
  const gameState = (session) => session?.state || session?.gameState || {};
  const privateState = (state, userId) => state.private || state.viewer || state.privateState || state.hands?.[String(userId)] || {};
  const cardId = (card) => String(card?.id ?? card?.instanceId ?? card?.cardId ?? card ?? "");
  const memberId = (member) => String(member?.userId ?? member?.user_id ?? member?.id ?? "");
  const cardType = (card) => String(card?.type ?? card?.key ?? card?.name ?? cardId(card).replace(/[-_:]?\d+$/, "")).toLowerCase();
  function cardData(card) {
    const key = cardType(card);
    const match = CARDS[key] ? key : Object.keys(CARDS).find((candidate) => key.includes(candidate));
    const data = CARDS[match] || ["Puppy Card", "ACTION", "action-puppy-cam.png", "Action"];
    return { key: match || key, title: data[0], badge: data[1], image: data[2], kind: data[3], rule: RULES[match || key] || "", effect: EFFECTS[match || key] || ((data[3] === "Matching") ? "matching" : "") };
  }
  function viewerHand(state, viewerId) {
    const priv = privateState(state, viewerId);
    const cards = priv.hand || priv.cards || state.hand || [];
    return Array.isArray(cards) ? cards.slice().sort((leftCard, rightCard) => {
      const left = cardData(leftCard), right = cardData(rightCard);
      return (KIND_ORDER[left.kind] - KIND_ORDER[right.kind]) || left.title.localeCompare(right.title) || cardId(leftCard).localeCompare(cardId(rightCard));
    }) : [];
  }
  function seats(members, viewerId) {
    const pivot = Math.max(0, members.findIndex((member) => memberId(member) === String(viewerId)));
    const ordered = members.slice(pivot).concat(members.slice(0, pivot));
    const positions = ["bottom", "left-low", "left-high", "right-high", "right-low"];
    return ordered.map((member, index) => ({ member, position: positions[index] || "top" }));
  }
  function fixtureSeatMembers(context, members) {
    let enabled = false;
    try {
      enabled = new URLSearchParams(window.parent.location.search).get("puppy-seat-fixtures") === "1";
    } catch (_) { enabled = false; }
    if (!enabled || String(context.session?.mode || "") !== "practice" || members.length >= 5) return members;
    const names = { 3: "Biscuit Bot", 4: "Maple Bot", 5: "Scout Bot" };
    const fixtures = [];
    for (let seat = members.length + 1; seat <= 5; seat += 1) {
      fixtures.push({ userId: -7390 - seat, seat, displayName: names[seat] || `Puppy Bot ${seat}`, bot: true, fixture: true, cards: 8 });
    }
    return members.concat(fixtures);
  }
  function displayMemberName(context, member) {
    return member?.fixture ? String(member.displayName || "Puppy Bot") : context.memberName(memberId(member));
  }
  function renderCard(card, interactive) {
    const data = cardData(card), id = cardId(card);
    const item = make(interactive ? "button" : "div", "pp-card pp-kind-" + data.kind.toLowerCase().replace(/\s/g, "-"));
    if (interactive) {
      item.type = "button";
      item.setAttribute("aria-pressed", selected.has(id) ? "true" : "false");
      item.setAttribute("aria-label", `${data.title}. ${data.badge}. ${data.rule} Click to select; double-click or press Enter to play.`);
      item.addEventListener("click", () => { selected.has(id) ? selected.delete(id) : selected.add(id); item.setAttribute("aria-pressed", selected.has(id) ? "true" : "false"); });
    }
    const image = make("img", "pp-card-art"); image.src = ASSET_ROOT + data.image; image.alt = ""; image.draggable = false;
    const rule = make("span", "pp-card-rule", data.rule);
    if (data.rule.length > 46) rule.classList.add("pp-card-rule-long");
    item.append(image, make("span", "pp-card-title", data.title), make("span", "pp-card-badge", data.badge), rule);
    return item;
  }
  async function act(context, action, payload) {
    const actionEpoch = renderActionEpoch;
    if (context !== activeRenderContext || busyLocal || context.busy || submittedActionEpoch === actionEpoch) return false;
    busyLocal = true;
    submittedActionEpoch = actionEpoch;
    try {
      const state = gameState(context.session), names = state.actionNames || state.actions || {};
      const succeeded = await context.performAction(names[action] || DEFAULT_ACTIONS[action] || action, payload || {}, RANDOMNESS_PURPOSES[action] || "");
      if (succeeded === false) {
        submittedActionEpoch = -1;
        clearSelection();
        context.rerender?.();
        return false;
      }
      return true;
    } catch (error) {
      submittedActionEpoch = -1;
      const code = String(error?.serverCode || error?.code || error?.data?.code || "");
      if (code === "MULTIPLAYER_GAME_STATE_STALE") {
        clearSelection();
        context.rerender?.();
      }
      context.setStatus?.(error?.message || "That puppy move could not be completed.");
      return false;
    }
    finally { busyLocal = false; }
  }
  function puppyPendingSettlementDelayMs(context, settleAfterUnixMs, localNow = Date.now()) {
    const serverNowUnixMs = Number(context?.session?.nowUnixMs || 0);
    const referenceNow = Number.isFinite(serverNowUnixMs) && serverNowUnixMs > 0
      ? serverNowUnixMs
      : Number(localNow || 0);
    return Math.max(120, Number(settleAfterUnixMs || 0) - referenceNow + 120);
  }
  function schedulePendingSettlement(context, state, viewerId) {
    const pending = state?.pendingAction;
    const isOwner = !state?.botTask && state?.phase === "pending-action"
      && pending
      && String(pending.actorUserId || "") === String(viewerId);
    if (!isOwner) {
      if (pendingSettlementTimer) window.clearTimeout(pendingSettlementTimer);
      pendingSettlementTimer = 0;
      pendingSettlementKey = "";
      return;
    }
    const settleAfter = Number(pending.settleAfterUnixMs || 0);
    if (!Number.isFinite(settleAfter) || settleAfter <= 0) return;
    const action = pending.requiresRandomness ? "settle-random" : "settle-action";
    const key = `${viewerId}:${pending.effect || "action"}:${settleAfter}:${action}`;
    if (pendingSettlementKey === key) return;
    if (pendingSettlementTimer) window.clearTimeout(pendingSettlementTimer);
    pendingSettlementKey = key;
    pendingSettlementTimer = window.setTimeout(() => {
      pendingSettlementTimer = 0;
      if (pendingSettlementKey !== key || context !== activeRenderContext) return;
      act(context, action).finally(() => {
        if (pendingSettlementKey === key) pendingSettlementKey = "";
        context.rerender?.();
      });
    }, puppyPendingSettlementDelayMs(context, settleAfter));
  }
  function puppyViewportBounds(view = window) {
    const viewport = owner => {
      const visual = owner.visualViewport;
      const left = Number(visual?.offsetLeft || 0), top = Number(visual?.offsetTop || 0);
      return { left, top, right: left + Number(visual?.width || owner.innerWidth || 0), bottom: top + Number(visual?.height || owner.innerHeight || 0) };
    };
    const bounds = viewport(view);
    let owner = view, offsetX = 0, offsetY = 0, scaleX = 1, scaleY = 1;
    const intersect = (box, horizontal = true, vertical = true) => {
      if (horizontal) { bounds.left = Math.max(bounds.left, (box.left - offsetX) / scaleX); bounds.right = Math.min(bounds.right, (box.right - offsetX) / scaleX); }
      if (vertical) { bounds.top = Math.max(bounds.top, (box.top - offsetY) / scaleY); bounds.bottom = Math.min(bounds.bottom, (box.bottom - offsetY) / scaleY); }
    };
    try {
      while (owner !== owner.parent) {
        const frame = owner.frameElement;
        if (!frame) break;
        const parent = owner.parent, rect = frame.getBoundingClientRect();
        const sx = rect.width / Number(frame.offsetWidth || rect.width), sy = rect.height / Number(frame.offsetHeight || rect.height);
        if (!(sx > 0 && sy > 0)) break;
        offsetX = rect.left + Number(frame.clientLeft || 0) * sx + offsetX * sx;
        offsetY = rect.top + Number(frame.clientTop || 0) * sy + offsetY * sy;
        scaleX *= sx; scaleY *= sy;
        intersect(viewport(parent));
        for (let ancestor = frame.parentElement; ancestor; ancestor = ancestor.parentElement) {
          const style = parent.getComputedStyle(ancestor);
          const clipX = /^(auto|scroll|hidden|clip)$/.test(style.overflowX), clipY = /^(auto|scroll|hidden|clip)$/.test(style.overflowY);
          if (!clipX && !clipY) continue;
          const box = ancestor.getBoundingClientRect();
          const ax = box.width / Number(ancestor.offsetWidth || box.width), ay = box.height / Number(ancestor.offsetHeight || box.height);
          const left = box.left + Number(ancestor.clientLeft || 0) * ax, top = box.top + Number(ancestor.clientTop || 0) * ay;
          intersect({ left, top, right: left + ancestor.clientWidth * ax, bottom: top + ancestor.clientHeight * ay }, clipX, clipY);
        }
        owner = parent;
      }
    } catch { /* A cross-origin host cannot expose its clipping geometry. */ }
    return Object.values(bounds).every(Number.isFinite) && bounds.right > bounds.left && bounds.bottom > bounds.top ? bounds : null;
  }

  function puppyPanelPlacement(rootBox, viewport, size, wanted = null) {
    if (!viewport) return null;
    const left = Math.max(rootBox.left, viewport.left), right = Math.min(rootBox.right, viewport.right);
    const top = Math.max(rootBox.top, viewport.top), bottom = viewport.bottom;
    if (![left, right, top, bottom, size.width, size.height].every(Number.isFinite)
      || right - left <= 16 || bottom - top <= 16 || rootBox.bottom <= viewport.top
      || size.width <= 0 || size.height <= 0) return null;
    const width = Math.min(size.width, right - left - 16), minimumTop = top + 8;
    const maximumTop = bottom - 8 - size.height;
    return {
      left: Math.max(left + 8, Math.min(wanted?.left ?? (left + right - width) / 2, right - width - 8)),
      top: Math.max(minimumTop, Math.min(wanted?.top ?? minimumTop + 16, Math.max(minimumTop, maximumTop))),
      width, outerPage: size.height > bottom - top - 16,
    };
  }
  const puppyPresentationOwners = new WeakMap();
  let puppyPresentationCleanup = null;
  function installPuppyPresentation(root) {
    const view = root.ownerDocument.defaultView, documentOwner = root.ownerDocument;
    const popups = new Map(), originals = new Map(), removers = [], cardsSeen = new Map();
    let disposed = false, queued = 0, spacer = null, cardSignature = '';
    const write = (node, name, value, priority = '') => {
      let fields = originals.get(node);
      if (!fields) { fields = new Map(); originals.set(node, fields); }
      let field = fields.get(name);
      if (!field) { field = { value: node.style.getPropertyValue(name), priority: node.style.getPropertyPriority(name) }; fields.set(name, field); }
      if (node.style.getPropertyValue(name) !== value || node.style.getPropertyPriority(name) !== priority) node.style.setProperty(name, value, priority);
      field.applied = { value, priority };
    };
    const restore = (node, name) => {
      const field = originals.get(node)?.get(name);
      if (!field || node.style.getPropertyValue(name) !== field.applied?.value || node.style.getPropertyPriority(name) !== field.applied?.priority) return;
      if (field.value) node.style.setProperty(name, field.value, field.priority); else node.style.removeProperty(name);
      field.applied = null;
    };
    const listen = (target, type, handler, options) => {
      target?.addEventListener(type, handler, options);
      if (target) removers.push(() => target.removeEventListener(type, handler, typeof options === 'boolean' ? options : Boolean(options?.capture)));
    };
    const scale = () => {
      const box = root.getBoundingClientRect(), sx = box.width / root.offsetWidth, sy = box.height / root.offsetHeight;
      return [sx, sy, box.left, box.top].every(Number.isFinite) && sx > 0 && sy > 0 ? { box, sx, sy } : null;
    };
    let ribbonFlow = null, ribbonHost = null;
    const blockedRibbons = new WeakSet();
    const ribbonFields = ['position', 'left', 'right', 'top', 'bottom', 'width', 'max-width', 'height', 'margin', 'transform', 'white-space', 'overflow', 'overflow-wrap'];
    const restoreRibbonFlow = () => {
      const saved = ribbonFlow; ribbonFlow = null; delete root.dataset.ppRibbonFlow;
      if (!saved) return;
      for (const name of ribbonFields) restore(saved.ribbon, name);
      restore(saved.hand, 'margin-top'); restore(root, 'overflow-anchor');
      if (saved.ribbon.parentElement === ribbonHost && root.contains(saved.stage)) saved.stage.insertBefore(saved.ribbon, saved.next?.parentElement === saved.stage ? saved.next : null);
      ribbonHost?.remove(); delete root.dataset.ppRibbonFlow;
    };
    const fitRibbon = geometry => {
      const stage = root.querySelector('.pp-stage'), hand = root.querySelector('.pp-hand-region');
      const ribbon = root.querySelector('.pp-turn-ribbon');
      if (![stage, hand, ribbon].every(node => node?.isConnected) || hand.parentElement !== root) { restoreRibbonFlow(); return; }
      if (ribbonFlow && (ribbonFlow.ribbon !== ribbon || ribbonFlow.stage !== stage || ribbonFlow.hand !== hand)) restoreRibbonFlow();
      if (ribbonFlow) {
        const owned = [[ribbon, ribbonFields], [hand, ['margin-top']], [root, ['overflow-anchor']]];
        if (ribbon.parentElement !== ribbonHost || ribbonHost?.parentElement !== root || owned.some(([node, fields]) => fields.some(name => {
          const applied = originals.get(node)?.get(name)?.applied;
          return applied && (node.style.getPropertyValue(name) !== applied.value || node.style.getPropertyPriority(name) !== applied.priority);
        }))) { blockedRibbons.add(ribbon); restoreRibbonFlow(); return; }
      } else if (ribbon.parentElement !== stage) return;
      if (blockedRibbons.has(ribbon)) return;
      const stageBox = stage.getBoundingClientRect();
      const parts = [...root.querySelectorAll('.pp-player .pp-avatar,.pp-player-name,.pp-player-status')]
        .map(node => node.getBoundingClientRect()).filter(box => box.width > 0 && box.height > 0);
      const controls = [...stage.querySelectorAll('.pp-deck,.pp-history-toggle,.pp-discard')]
        .map(node => node.getBoundingClientRect()).filter(box => box.width > 0 && box.height > 0);
      const numbers = [stage.offsetWidth, stage.offsetHeight, geometry.sx, geometry.sy, view.innerWidth, view.innerHeight];
      for (const box of [...parts, ...controls]) numbers.push((box.left - stageBox.left) / geometry.sx, (box.top - stageBox.top) / geometry.sy, box.width / geometry.sx, box.height / geometry.sy);
      if (!numbers.every(Number.isFinite)) return;
      // Memoize inputs, not a relaxed collision predicate: every actual overlap uses raw rectangles.
      const signature = numbers.map(value => Math.round(value * 64) / 64).join(':') + ':' + ribbon.textContent + ':' + (documentOwner.fonts?.status || 'loaded');
      if (ribbonFlow?.signature === signature) return;
      if (ribbonFlow) restoreRibbonFlow();
      const box = ribbon.getBoundingClientRect();
      const overlaps = other => Math.min(box.right, other.right) > Math.max(box.left, other.left)
        && Math.min(box.bottom, other.bottom) > Math.max(box.top, other.top);
      if (!parts.some(overlaps)) { delete root.dataset.ppRibbonFlow; return; }
      const handBox = hand.getBoundingClientRect(), margin = Number.parseFloat(view.getComputedStyle(hand).marginTop);
      const occupiedBottom = Math.max(...parts.map(part => part.bottom), ...controls.map(part => part.bottom));
      const width = Math.min(box.width / geometry.sx, hand.offsetWidth - 16);
      if (![margin, occupiedBottom, handBox.top, width].every(Number.isFinite) || width <= 0
        || (handBox.top - occupiedBottom) / geometry.sy < 8) { root.dataset.ppRibbonFlow = 'needs-review'; return; }
      const next = ribbon.nextElementSibling;
      if (!ribbonHost) ribbonHost = make('div', 'pp-ribbon-flow');
      for (const [name, value] of Object.entries({ position: 'relative', width: '100%', 'margin-top': margin + 'px', 'padding-bottom': '8px', overflow: 'visible', 'z-index': '20' })) write(ribbonHost, name, value, 'important');
      write(root, 'overflow-anchor', 'none', 'important'); write(hand, 'margin-top', '0px', 'important');
      root.insertBefore(ribbonHost, hand); ribbonHost.append(ribbon);
      for (const [name, value] of Object.entries({ position: 'relative', left: 'auto', right: 'auto', top: 'auto', bottom: 'auto', width: width + 'px', 'max-width': '100%', height: 'auto', margin: '0 auto', transform: 'none', 'white-space': 'normal', overflow: 'visible', 'overflow-wrap': 'anywhere' })) write(ribbon, name, value, 'important');
      ribbonFlow = { ribbon, stage, hand, next, signature }; root.dataset.ppRibbonFlow = 'before-hand';
    };
    const fitCards = geometry => {
      const hand = root.querySelector('.pp-hand');
      const cards = Array.from(root.querySelectorAll('.pp-hand .pp-card,.pp-floating-panel .pp-card,.pp-private-peek .pp-card'))
        .filter(card => !card.closest('[hidden]'));
      const signature = [root.clientWidth, geometry.sx, geometry.sy, documentOwner.fonts?.status || 'loaded', cards.length].join(':');
      if (signature === cardSignature && cards.every(card => cardsSeen.has(card))) return;
      cardSignature = signature;
      const unit = Math.ceil(100000 / Math.min(geometry.sx, geometry.sy)) / 100000;
      write(root, '--pp-readable-unit', unit + 'px');
      root.dataset.ppReadableCards = 'true';
      let handHeight = 0;
      for (const card of cards) {
        const regions = Array.from(card.querySelectorAll('.pp-card-title,.pp-card-rule'));
        let requestedWidth = 252, fits = false;
        for (let attempt = 0; attempt < 2; attempt++) {
          write(card, '--pp-readable-card-minimum', (Math.ceil(requestedWidth / geometry.sx * 64) / 64) + 'px');
          fits = regions.length === 2 && regions.every(region => {
            const font = Number.parseFloat(view.getComputedStyle(region).fontSize) * Math.min(geometry.sx, geometry.sy);
            return Number.isFinite(font) && font >= 12 && region.clientWidth > 0 && region.clientHeight > 0
              && region.scrollWidth <= region.clientWidth && region.scrollHeight <= region.clientHeight;
          });
          if (fits || requestedWidth === 268) break;
          requestedWidth = 268;
        }
        card.dataset.ppTextReadability = fits ? 'readable' : 'needs-review';
        cardsSeen.set(card, true);
        if (hand?.contains(card)) handHeight = Math.max(handHeight, card.offsetHeight);
      }
      if (hand && handHeight > 0) write(root, '--pp-readable-hand-height', Math.ceil(handHeight + 16) + 'px');
      for (const card of cardsSeen.keys()) if (!root.contains(card)) cardsSeen.delete(card);
    };
    const sync = event => {
      queued = 0;
      if (disposed) return;
      if (!root.isConnected) { cleanup(); return; }
      if (documentOwner.hidden) return;
      const geometry = scale();
      if (!geometry) return;
      fitCards(geometry); fitRibbon(geometry);
      if (!popups.size) return;
      const viewport = puppyViewportBounds(view);
      let reach = 0;
      for (const [panel, entry] of popups) {
        if (!panel.isConnected) { popups.delete(panel); continue; }
        if (!(entry.outerPage && event?.type === 'scroll')) {
          const current = panel.getBoundingClientRect();
          const desired = puppyPanelPlacement(geometry.box, viewport, { width: 520 * geometry.sx, height: current.height }, entry.wanted);
          if (!desired) { write(panel, 'visibility', 'hidden'); continue; }
          write(panel, 'width', desired.width / geometry.sx + 'px');
          const positioned = puppyPanelPlacement(geometry.box, viewport, panel.getBoundingClientRect(), entry.wanted);
          if (!positioned) continue;
          const localLeft = (positioned.left - geometry.box.left) / geometry.sx - Number(root.clientLeft || 0) + Number(root.scrollLeft || 0);
          const localTop = (positioned.top - geometry.box.top) / geometry.sy - Number(root.clientTop || 0) + Number(root.scrollTop || 0);
          write(panel, 'left', localLeft + 'px'); write(panel, 'top', localTop + 'px'); write(panel, 'visibility', 'visible');
          entry.outerPage = positioned.outerPage;
          panel.dataset.ppPanelOverflow = entry.outerPage ? 'outer-page' : 'viewport-fit';
        }
        reach = Math.max(reach, Number.parseFloat(panel.style.top || '0') + panel.offsetHeight + 8 / geometry.sy);
      }
      if (spacer) {
        const baselineHeight = root.offsetHeight - spacer.offsetHeight;
        write(spacer, 'height', Math.max(0, Math.ceil(reach - baselineHeight)) + 'px');
      }
    };
    const schedule = event => {
      if (disposed || queued) return;
      queued = view.requestAnimationFrame(() => sync(event));
    };
    const popup = (panel, handle, close, body, onClose, mandatory) => {
      const anchor = root.contains(documentOwner.activeElement) ? documentOwner.activeElement : null;
      const entry = { wanted: null, outerPage: false, collapsed: false, mandatory, anchor };
      popups.set(panel, entry);
      if (!spacer) { spacer = make('div', 'pp-popup-page-extent'); spacer.setAttribute('aria-hidden', 'true'); root.append(spacer); }
      write(root, 'overflow', 'visible');
      for (const [name, value] of Object.entries({ position: 'absolute', right: 'auto', bottom: 'auto', margin: '0', transform: 'none', maxHeight: 'none', overflow: 'visible', maxWidth: 'none', visibility: 'hidden' })) write(panel, name.replace(/[A-Z]/g, c => '-' + c.toLowerCase()), value);
      panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', handle.querySelector('strong')?.textContent || handle.textContent || 'Puppy game choices');
      const dismiss = () => {
        if (disposed || !root.isConnected || !popups.has(panel) || !close) return;
        if (mandatory) {
          entry.collapsed = !entry.collapsed; body.hidden = entry.collapsed;
          close.textContent = entry.collapsed ? 'Reopen' : 'Close'; close.setAttribute('aria-expanded', String(!entry.collapsed));
          entry.outerPage = false; schedule(); return;
        }
        const restoreFocus = panel.contains(documentOwner.activeElement);
        popups.delete(panel); resizeObserver?.unobserve(panel); panel.remove(); panel.replaceChildren();
        if (restoreFocus && anchor?.isConnected) anchor.focus?.({ preventScroll: true });
        if (!popups.size) { spacer?.remove(); spacer = null; restore(root, 'overflow'); }
        onClose?.(); schedule();
      };
      close?.addEventListener('click', dismiss); entry.dismiss = dismiss;
      let drag = null;
      handle.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.target.closest('button,input,select,a')) return;
        const box = panel.getBoundingClientRect(); drag = { x: event.clientX, y: event.clientY, left: box.left, top: box.top };
        handle.setPointerCapture?.(event.pointerId); event.preventDefault();
      });
      handle.addEventListener('pointermove', event => {
        if (!drag || disposed || !panel.isConnected) return;
        entry.wanted = { left: drag.left + event.clientX - drag.x, top: drag.top + event.clientY - drag.y };
        entry.outerPage = false; sync();
      });
      for (const type of ['pointerup', 'pointercancel', 'lostpointercapture']) handle.addEventListener(type, () => { drag = null; });
      resizeObserver?.observe(panel); schedule();
    };
    const cleanup = () => {
      if (disposed) return;
      disposed = true;
      if (queued) view.cancelAnimationFrame(queued);
      resizeObserver?.disconnect(); mutationObserver?.disconnect(); removers.forEach(remove => remove());
      restoreRibbonFlow(); ribbonHost = null;
      for (const panel of popups.keys()) { panel.remove(); panel.replaceChildren(); } popups.clear(); spacer?.remove(); spacer = null;
      for (const [node, fields] of originals) for (const [name, original] of fields) {
        if (node.style.getPropertyValue(name) !== original.applied?.value || node.style.getPropertyPriority(name) !== original.applied?.priority) continue;
        if (original.value) node.style.setProperty(name, original.value, original.priority); else node.style.removeProperty(name);
      }
      delete root.dataset.ppReadableCards;
      for (const card of cardsSeen.keys()) delete card.dataset.ppTextReadability;
      cardsSeen.clear(); originals.clear(); removers.length = 0; puppyPresentationOwners.delete(root);
    };
    const resizeObserver = typeof view.ResizeObserver === 'function' ? new view.ResizeObserver(schedule) : null;
    const mutationObserver = typeof view.MutationObserver === 'function' ? new view.MutationObserver(() => {
      if (!root.isConnected) cleanup(); else schedule();
    }) : null;
    resizeObserver?.observe(root);
    mutationObserver?.observe(documentOwner.body, { childList: true, subtree: true });
    mutationObserver?.observe(root, { attributes: true, attributeFilter: ['style', 'data-effective-viewer-scale'] });
    const watchView = owner => { listen(owner, 'resize', schedule, { passive: true }); listen(owner, 'scroll', schedule, { passive: true, capture: true }); listen(owner.visualViewport, 'resize', schedule, { passive: true }); listen(owner.visualViewport, 'scroll', schedule, { passive: true }); };
    watchView(view);
    try { let owner = view; while (owner !== owner.parent) { owner = owner.parent; watchView(owner); } } catch {}
    listen(view, 'pagehide', cleanup, { once: true }); listen(documentOwner, 'visibilitychange', schedule);
    const topPopup = () => [...popups.entries()]
      .filter(([panel]) => panel.isConnected && panel.style.visibility !== 'hidden')
      .sort(([left], [right]) => (Number.parseFloat(view.getComputedStyle(left).zIndex) || 0)
        - (Number.parseFloat(view.getComputedStyle(right).zIndex) || 0)).at(-1);
    listen(root, 'pointerdown', event => {
      const last = topPopup(); if (!last) return;
      const [panel, entry] = last;
      if (!entry.mandatory && root.contains(event.target) && !panel.contains(event.target)
        && !event.target.closest('.pp-floating-panel,.pp-private-peek,.pp-setup,.pp-history-toggle')) entry.dismiss();
    }, true);
    listen(root, 'keydown', event => {
      const entry = topPopup()?.[1];
      if (event.key === 'Escape' && entry && !entry.mandatory) { event.preventDefault(); entry.dismiss(); }
    });
    documentOwner.fonts?.ready?.then(() => { cardSignature = ''; schedule(); }).catch(() => {});
    puppyPresentationOwners.set(root, { popup, schedule }); schedule();
    return cleanup;
  }
  function floatingPanel(root, title, body, onClose, mandatory = false) {
    const panel = make('section', 'pp-floating-panel'), bar = make('div', 'pp-panel-bar'), close = make('button', 'pp-close', 'Close');
    close.type = 'button'; bar.append(make('strong', '', title), close); panel.append(bar, body);
    puppyPresentationOwners.get(root)?.popup(panel, bar, close, body, onClose, mandatory);
    return panel;
  }
  function clearSelection() { selected.clear(); }
  function targetPanel(context, root, members, viewerId, title, onChoose) {
    const body = make("div", "pp-choice-list");
    members.filter((member) => memberId(member) !== String(viewerId)).forEach((member) => { const button = make("button", "pp-target", context.memberName(memberId(member))); button.addEventListener("click", () => onChoose(memberId(member))); body.append(button); });
    root.append(floatingPanel(root, title, body));
  }
  function playSelection(context, root, state, members, viewerId) {
    const cardIds = Array.from(selected), cards = cardIds.map((id) => cardData(id));
    if (cardIds.length === 1) {
      const card = cards[0];
      if (card.effect === "counter") {
        if ((state.legalActions || []).includes("counter")) { clearSelection(); act(context, "counter", { card: cardIds[0] }); }
        else context.setStatus?.("Not Today! can only be played while countering another action.");
        return;
      }
      if (["Calm Down", "Chaos", "Matching"].includes(card.kind)) { context.setStatus?.("That card is used only by its matching or Chaos Puppy decision."); return; }
      const activeTargets = members.filter((member) => !state.eliminated?.[memberId(member)] && !["on_hold", "departed"].includes(String(member?.membershipStatus ?? member?.membership_status ?? member?.status ?? "")));
      if (card.effect === "favor" || card.effect === "target-attack") targetPanel(context, root, activeTargets, viewerId, "Choose an active player", (targetUserId) => { clearSelection(); act(context, "play", { card: cardIds[0], targetUserId }); });
      else { clearSelection(); act(context, "play", { card: cardIds[0] }); }
      return;
    }
    const titles = new Set(cards.map((card) => card.title));
    if ((cardIds.length === 2 || cardIds.length === 3) && titles.size === 1 && cards.every((card) => card.kind === "Matching")) {
      const requested = cardIds.length === 3 ? make("select", "pp-request-title") : null;
      if (requested) Object.values(CARDS).filter((value) => !["CHAOS PUPPY", "CALM DOWN"].includes(value[1])).forEach((value) => { const option = make("option", "", value[0]); option.value = value[0]; requested.append(option); });
      const activeTargets = members.filter((member) => !state.eliminated?.[memberId(member)] && !["on_hold", "departed"].includes(String(member?.membershipStatus ?? member?.membership_status ?? member?.status ?? "")));
      targetPanel(context, root, activeTargets, viewerId, cardIds.length === 2 ? "Choose a player for the random steal" : "Choose a player and requested card", (targetUserId) => { clearSelection(); act(context, "combo", { cards: cardIds, targetUserId, requestedTitle: requested?.value || "" }); });
      if (requested) root.querySelector(".pp-floating-panel .pp-choice-list")?.prepend(requested);
      return;
    }
    if (cardIds.length === 5 && titles.size === 5) {
      const body = make("div", "pp-history-list");
      (state.discardPile || []).filter((id) => !["Chaos", "Calm Down"].includes(cardData(id).kind) && !cardIds.includes(id)).forEach((id) => { const button = renderCard(id, false); button.setAttribute("role", "button"); button.tabIndex = 0; const choose = () => { clearSelection(); act(context, "combo", { cards: cardIds, retrieveCardId: id }); }; button.addEventListener("click", choose); button.addEventListener("keydown", (event) => { if (event.key === "Enter" || event.key === " ") choose(); }); body.append(button); });
      root.append(floatingPanel(root, "Choose a discarded card to retrieve", body)); return;
    }
    context.setStatus?.("Choose one action card, a matching pair or trio, or five different titles.");
  }
  function chaosPanel(context, root, state, viewerId, hand) {
    const pending = state.pendingChoice;
    if (!pending || pending.kind !== "chaos" || String(pending.actorUserId) !== String(viewerId)) return;
    const body = make("div", "pp-chaos-body"); body.append(make("p", "", "A Chaos Puppy was drawn. Use a Calm Down card or accept elimination."));
    const calm = (pending.calmCards || []).find((card) => hand.includes(card));
    if (calm) {
      body.append(make("p", "pp-help", "Choose where to return the Chaos Puppy:"));
      const positions = make("div", "pp-position-grid");
      [["top", "Top"], ["near top", "Near top"], ["middle", "Middle"], ["near bottom", "Near bottom"], ["bottom", "Bottom"]].forEach(([position, label]) => { const button = make("button", "", label); button.addEventListener("click", () => act(context, "calm", { card: cardId(calm), position })); positions.append(button); });
      body.append(positions);
    }
    if (!calm) { const eliminate = make("button", "pp-danger", "Accept elimination"); eliminate.addEventListener("click", () => act(context, "eliminate")); body.append(eliminate); }
    root.append(floatingPanel(root, "Chaos Puppy drawn", body, undefined, true));
  }
  function privateChoicePanel(context, root, state, viewerId, hand) {
    const choice = state.pendingChoice;
    if (!choice) return;
    if (choice.kind === "favor" && String(choice.targetUserId) === String(viewerId)) {
      const body = make("div", "pp-history-list"); hand.forEach((card) => { const button = renderCard(card, false); button.setAttribute("role", "button"); button.tabIndex = 0; const give = () => act(context, "give-card", { card: cardId(card) }); button.addEventListener("click", give); button.addEventListener("keydown", (event) => { if (event.key === "Enter" || event.key === " ") give(); }); body.append(button); }); root.append(floatingPanel(root, "Choose a card to give", body, undefined, true));
    }
    if (choice.kind === "reorder" && String(choice.actorUserId) === String(viewerId)) {
      const order = (choice.cards || []).slice(), body = make("div", "pp-reorder"), list = make("div", "pp-reorder-list");
      const redraw = () => { list.replaceChildren(); order.forEach((card, index) => { const row = make("div", "pp-reorder-row"); row.append(renderCard(card, false)); const earlier = make("button", "", "Earlier"), later = make("button", "", "Later"); earlier.disabled = index === 0; later.disabled = index === order.length - 1; earlier.addEventListener("click", () => { [order[index - 1], order[index]] = [order[index], order[index - 1]]; redraw(); }); later.addEventListener("click", () => { [order[index + 1], order[index]] = [order[index], order[index + 1]]; redraw(); }); row.append(earlier, later); list.append(row); }); };
      redraw(); const confirm = make("button", "pp-primary", "Confirm private order"); confirm.addEventListener("click", () => act(context, "reorder", { cards: order })); body.append(list, confirm); root.append(floatingPanel(root, "Reorder the next cards", body, undefined, true));
    }
  }
  function terminalPresentation(context, state) {
    const status = String(context.session?.status || "");
    const terminal = ["completed", "forfeited", "abandoned", "ended", "cancelled", "expired"].includes(status) || state.completed === true;
    const rawWinner = String(state.winnerUserId ?? "");
    const winnerId = /^[1-9][0-9]*$/.test(rawWinner) ? rawWinner : "";
    const labels = { forfeited: "Game forfeited", abandoned: "Game abandoned", ended: "Game ended", cancelled: "Game cancelled", expired: "Game expired" };
    return { terminal, status, winnerId, label: labels[status] || "Game complete" };
  }
  function playerStatus(state, member, terminal) {
    const membershipStatus = String(member?.membershipStatus ?? member?.membership_status ?? member?.status ?? "");
    if (membershipStatus === "on_hold") return "ON HOLD · NEXT MATCH";
    const count = state.cardCounts?.[memberId(member)] ?? member?.cardCount ?? member?.cards ?? 0;
    const userId = String(member?.userId ?? "");
    const eliminated = state.eliminated && typeof state.eliminated === "object" && !Array.isArray(state.eliminated) && userId !== "" && state.eliminated[userId] === true;
    let label;
    if (eliminated) label = "ELIMINATED";
    else if (terminal.terminal) label = terminal.winnerId && userId === terminal.winnerId ? "WINNER" : "COMPLETE";
    else label = member?.connection_status === "reconnecting" ? "RECONNECTING" : "PLAYING";
    return count + " CARDS · " + label;
  }
  function phaseRibbon(context, state, viewerId, turnUserId, terminal) {
    if (terminal.terminal) return terminal.winnerId ? context.memberName(terminal.winnerId) + " * winner * " + terminal.label : terminal.label;
    if (terminal.status === "paused") return "Game paused * waiting to resume";
    if (terminal.status === "lobby") return "Waiting for the game to start";
    const legalActions = Array.isArray(state.legalActions) ? state.legalActions : [];
    const choice = state.pendingChoice;
    const describeChoice = (actorId, instruction, activity) => {
      const actor = String(actorId ?? "");
      if (!actor) return "Waiting * " + activity;
      const name = context.memberName(actor);
      return actor === viewerId ? name + " * your choice * " + instruction : name + " * " + activity;
    };
    if (choice?.kind === "favor") return describeChoice(choice.targetUserId, "choose a card to give", "choosing a card to give");
    if (choice?.kind === "reorder") return describeChoice(choice.actorUserId, "choose the private card order", "choosing a private card order");
    if (choice?.kind === "chaos") return describeChoice(choice.actorUserId, "resolve the Chaos Puppy", "resolving a Chaos Puppy");
    if (state.phase === "pending-action") return legalActions.includes("counter") ? "Action pending * play Not Today! or wait" : "Action pending * waiting for it to resolve";
    if (["deal", "setup", "waiting"].includes(String(state.phase || ""))) return legalActions.includes("deal") ? "Deal puppies to begin" : "Waiting for puppies to be dealt";
    if (state.phase !== "playing" || !turnUserId) return "Waiting for the next game action";
    const turnName = context.memberName(turnUserId);
    if (turnUserId === viewerId) return legalActions.some(action => ["draw", "play", "combo"].includes(action)) ? turnName + " * your turn * choose a card or draw" : turnName + " * waiting for the next action";
    return turnName + " * current turn * choosing a card or drawing";
  }

  function render(context) {
    puppyPresentationCleanup?.(); puppyPresentationCleanup = null;
    activeRenderContext = context;
    renderActionEpoch += 1;
    submittedActionEpoch = -1;
    const state = gameState(context.session), viewerId = String(typeof context.currentUserId === "function" ? context.currentUserId() : (context.currentUserId ?? "")), members = (context.session?.members || state.players || []).filter(Boolean), seatMembers = fixtureSeatMembers(context, members), hand = viewerHand(state, viewerId);
    const terminal = terminalPresentation(context, state);
    schedulePendingSettlement(context, state, viewerId);
    const root = make("div", "pp-board"), stage = make("section", "pp-stage");
    puppyPresentationCleanup = installPuppyPresentation(root);
    seats(seatMembers, viewerId).forEach(({ member, position }) => {
      const station = make("article", "pp-player pp-seat-" + position);
      const botSeat = Number(member?.seat || 0), isBot = memberId(member).startsWith("-") || member?.bot === true;
      const avatar = isBot ? make("img", "pp-avatar") : context.memberAvatar(member, "pp-avatar");
      if (isBot) avatar.src = ASSET_ROOT + (BOT_AVATARS[botSeat] || "calm-good-pup.png");
      const displayName = displayMemberName(context, member);
      avatar.alt = displayName + " avatar"; avatar.draggable = false;
      const status = playerStatus(state, member, terminal);
      station.append(avatar, make("strong", "pp-player-name", displayName), make("span", "pp-player-status", status)); stage.append(station);
    });
    const turnSource = document.getElementById("turn");
    const turnUserId = String(turnSource?.dataset?.turnUserId ?? state.currentPlayerUserId ?? state.currentPlayerId ?? state.turnUserId ?? state.activePlayerId ?? "");
    stage.append(make("p", "pp-turn-ribbon", phaseRibbon(context, state, viewerId, turnUserId, terminal)));
    const legalActions = Array.isArray(state.legalActions) ? state.legalActions : [];
    const center = make("div", "pp-center"), draw = make("button", "pp-deck"), discard = make("div", "pp-discard"), discardGroup = make("div", "pp-discard-group"), history = make("button", "pp-history-toggle");
    draw.type = "button"; draw.disabled = Boolean(context.busy) || !legalActions.includes("draw"); draw.setAttribute("aria-label", "Draw a card"); draw.append(make("span", "pp-card-count", String(state.drawPile?.count ?? state.deckCount ?? state.drawPileCount ?? "")), make("span", "pp-table-caption", "Draw cards")); draw.addEventListener("click", () => act(context, "draw"));
    discard.setAttribute("role", "region"); discard.setAttribute("aria-label", "Discard pile"); discard.append(make("span", "pp-table-caption", "Discard cards"));
    history.type = "button"; history.setAttribute("aria-label", "Open table history from the toy basket"); history.setAttribute("aria-expanded", historyOpen ? "true" : "false"); history.append(make("span", "pp-table-caption", "Table history")); history.addEventListener("click", () => { historyOpen = !historyOpen; context.rerender(); }); discardGroup.append(discard, history);
    center.append(draw, discardGroup); stage.append(center);
    const handRegion = make("section", "pp-hand-region"), controls = make("div", "pp-controls");
    if (legalActions.includes("settle-action") || legalActions.includes("settle-random")) { const settle = make("button", "pp-primary", "Resolve action"); settle.addEventListener("click", () => act(context, legalActions.includes("settle-random") ? "settle-random" : "settle-action")); controls.append(settle); }
    if (controls.childElementCount) handRegion.append(controls);
    const scroller = make("div", "pp-hand"), left = make("button", "pp-scroll pp-scroll-left", "‹"), right = make("button", "pp-scroll pp-scroll-right", "›");
    left.addEventListener("click", () => scroller.scrollBy({ left: -Math.max(260, scroller.clientWidth * .7), behavior: "smooth" })); right.addEventListener("click", () => scroller.scrollBy({ left: Math.max(260, scroller.clientWidth * .7), behavior: "smooth" })); hand.forEach((card) => { const cardNode = renderCard(card, true), playCard = () => { const id = cardId(card); selected.add(id); cardNode.setAttribute("aria-pressed", "true"); playSelection(context, root, state, members, viewerId); }; cardNode.addEventListener("dblclick", playCard); cardNode.addEventListener("keydown", (event) => { if (event.key !== "Enter") return; event.preventDefault(); playCard(); }); scroller.append(cardNode); }); handRegion.append(left, scroller, right); root.append(stage, handRegion);
    if (historyOpen) { const list = make("div", "pp-history-list"), cards = state.discardPile || []; cards.slice(-12).reverse().forEach((card) => list.append(renderCard(card, false))); root.append(floatingPanel(root, "Table history", list, () => { historyOpen = false; context.rerender(); })); }
    if (state.privatePeek?.cards?.length) { const peek = make("div", "pp-private-peek"), heading = make("strong", "", "Your private Puppy Cam view"), list = make("div", "pp-history-list"); state.privatePeek.cards.forEach((card) => list.append(renderCard(card, false))); peek.append(heading, list); puppyPresentationOwners.get(root)?.popup(peek, heading, null, null, undefined, true); root.append(peek); }
    chaosPanel(context, root, state, viewerId, hand);
    privateChoicePanel(context, root, state, viewerId, hand);
    if (state.phase === "deal" || state.phase === "setup" || state.phase === "waiting") { const setup = make("section", "pp-setup"), heading = make("h2", "", context.options?.displayName || "Puppy Panic!"); setup.append(heading, make("p", "", state.settings?.mischiefPack === "mischief" ? "Mischief Pack enabled" : "Core deck")); const deal = make("button", "pp-primary", "Deal puppies"); deal.disabled = !legalActions.includes("deal"); deal.addEventListener("click", () => act(context, "deal")); setup.append(deal); puppyPresentationOwners.get(root)?.popup(setup, heading, null, null, undefined, true); root.append(setup); }
    const last = state.lastAction;
    const recent = make('p', 'pp-recent-action');
    recent.setAttribute('role', 'status');
    if (last) {
      const effectNames = { 'pile-on': 'Puppy Pile-On', nap: 'Nap Time', peek: 'Puppy Cam', shuffle: 'Squirrel!', favor: 'Puppy Eyes', reorder: "Trainer's Plan", 'target-attack': 'Fetch This!', flip: 'Toy Basket Flip', 'bottom-draw': 'Under the Couch', 'pair-steal': 'a matching pair', 'trio-request': 'a matching trio', 'discard-retrieve': 'five different titles' };
      const who = Number(last.userId) ? context.memberName(String(last.userId)) : '';
      recent.textContent = last.type === 'action-pending'
        ? `${who} played ${effectNames[last.effect] || 'an action'}.`
        : `${who ? who + ': ' : ''}${last.summary || ''}`;
    }
    root.append(recent);
    return root;
  }
  window.CoreChatPuppyPanic = { render };
}());

