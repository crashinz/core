(function () {
  "use strict";
  let historyOpen = false;
  let historyLayoutCleanup = null;
  let selectedCard = "";
  const make = (tag, cls, text) => { const node = document.createElement(tag); if (cls) node.className = cls; if (text !== undefined) node.textContent = text; return node; };
  const memberId = (member) => String(member?.userId ?? member?.user_id ?? member?.id ?? "");
  const gameState = (session) => session?.state || session?.gameState || {};
  const codeOf = (card) => String(card?.code ?? card?.card ?? card?.id ?? card ?? "").toUpperCase().replace("10", "T");
  const actionCodeOf = (card) => String(card?.code ?? card?.card ?? card?.id ?? card ?? "").toUpperCase();
  function cardParts(card) {
    const raw = codeOf(card);
    if (raw === "JB") return { rank: "Big Joker", suit: "S", joker: "big" };
    if (raw === "JL") return { rank: "Little Joker", suit: "S", joker: "little" };
    let rank = raw.slice(0, -1), suit = raw.slice(-1);
    if ("SHDC".includes(raw[0]) && !"SHDC".includes(suit)) { suit = raw[0]; rank = raw.slice(1); }
    return { rank: ({ T: "10", "10": "10", "11": "J", "12": "Q", "13": "K", "14": "A" })[rank] || rank, suit, joker: null };
  }
  let pendingPlayCard = "";
  function cardNode(card, legal, play) {
    const parts = cardParts(card), node = make("button", "spm-card " + ((parts.suit === "H" || parts.suit === "D") ? "is-red" : ""));
    node.type = "button"; node.disabled = legal === false; node.classList.toggle("is-unplayable", legal === false);
    node.dataset.cardCode = codeOf(card);
    if (parts.joker) {
      const image = document.createElement("img");
      image.src = new URL(`../../assets/images/spades-modern/joker-${parts.joker}.svg`, window.location.href).href;
      image.alt = `${parts.rank} card`;
      image.draggable = false;
      node.classList.add("is-joker");
      node.setAttribute("aria-label", parts.rank);
      node.append(image);
    } else {
      node.innerHTML = `<span>${parts.rank}</span><i>${({ S: "♠", H: "♥", D: "♦", C: "♣" })[parts.suit] || parts.suit}</i><small>${parts.rank}</small>`;
      node.setAttribute("aria-label", `${parts.rank} of ${{ S: "Spades", H: "Hearts", D: "Diamonds", C: "Clubs" }[parts.suit] || parts.suit}`);
    }
    if (play) bindCardActivation(node, play);
    return node;
  }
  function bindReliableActivation(node, activate) {
    let pointerActivationAt = Number.NEGATIVE_INFINITY;
    const now = () => Number(globalThis.performance?.now?.() ?? Date.now());
    node.addEventListener("pointerdown", (event) => {
      if (event.button !== 0 || event.isPrimary === false) return;
      event.preventDefault();
      pointerActivationAt = now();
      activate(event);
    });
    node.addEventListener("click", (event) => {
      if (event.detail === 0 || now() - pointerActivationAt > 700) activate(event);
    });
  }
  function bindCardActivation(node, activate) {
    let committedFromClick = false;
    node.addEventListener("click", (event) => {
      // Commit on the second native click itself. Some embedded browser drivers
      // omit the trailing dblclick event, so waiting for it can require a third
      // activation even though the user already confirmed the selected card.
      if (event.detail > 1) {
        committedFromClick = true;
        activate({ commit: true, input: "double-click" });
        return;
      }
      committedFromClick = false;
      activate({ commit: false, input: event.detail === 0 ? "keyboard" : "single-click" });
    });
    node.addEventListener("dblclick", (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (committedFromClick) {
        committedFromClick = false;
        return;
      }
      activate({ commit: true, input: "double-click" });
    });
  }
  function animateCardPlay(node, submit) {
    let ghost = null;
    let sourceObserver = null;
    const sourceCode = node.dataset.cardCode;
    const matchingSourceCards = () => Array.from(document.querySelectorAll(".spm-hand .spm-card")).filter((candidate) => candidate.dataset.cardCode === sourceCode);
    const hideMatchingSource = () => matchingSourceCards().forEach((candidate) => { candidate.style.visibility = "hidden"; });
    const revealMatchingSource = () => matchingSourceCards().forEach((candidate) => { candidate.style.visibility = ""; });
    const stopSourceObserver = () => { sourceObserver?.disconnect(); sourceObserver = null; };
    try {
      const table = node.closest(".spm-board")?.querySelector(".spm-table");
      if (!table || typeof node.animate !== "function") { submit(); return; }
      const from = node.getBoundingClientRect(), probe = node.cloneNode(true);
      probe.classList.remove("is-selected");
      probe.classList.add("spm-played", "spm-played-bottom");
      probe.style.visibility = "hidden";
      probe.style.pointerEvents = "none";
      table.append(probe);
      const to = probe.getBoundingClientRect();
      probe.remove();
      ghost = probe.cloneNode(true);
      ghost.style.visibility = "visible";
      ghost.classList.remove("is-selected", "is-unplayable");
      ghost.removeAttribute("aria-pressed");
      ghost.removeAttribute("aria-disabled");
      Object.assign(ghost.style, { position: "fixed", left: `${from.left}px`, top: `${from.top}px`, width: `${to.width}px`, height: `${to.height}px`, margin: "0", zIndex: "1000", pointerEvents: "none", transformOrigin: "50% 100%" });
      document.body.append(ghost);
      node.style.visibility = "hidden";
      sourceObserver = new MutationObserver(hideMatchingSource);
      sourceObserver.observe(document.body, { childList: true, subtree: true });
      hideMatchingSource();
      const angle = node.style.getPropertyValue("--card-angle") || "0deg";
      const flightStarted = performance.now();
      const flight = ghost.animate([
        { left: `${from.left}px`, top: `${from.top}px`, width: `${to.width}px`, height: `${to.height}px`, transform: `rotate(${angle})` },
        { left: `${to.left}px`, top: `${to.top}px`, width: `${to.width}px`, height: `${to.height}px`, transform: "rotate(0deg)" },
      ], { duration: 320, easing: "cubic-bezier(.2,.85,.25,1)", fill: "forwards" });
      const removeAfterBoardRender = () => {
        if (!ghost?.isConnected) return;
        const elapsed = performance.now() - flightStarted;
        const boardCardReady = document.querySelector(".spm-table .spm-played-bottom");
        if (elapsed >= 650 && boardCardReady) {
          stopSourceObserver();
          ghost.remove();
          return;
        }
        requestAnimationFrame(removeAfterBoardRender);
      };
      flight.finished.then(() => {
        if (!ghost?.isConnected) return;
        Object.assign(ghost.style, { left: `${to.left}px`, top: `${to.top}px`, width: `${to.width}px`, height: `${to.height}px`, transform: "rotate(0deg)", visibility: "visible", opacity: "1", display: "block" });
        flight.cancel();
        removeAfterBoardRender();
      }, () => {
        stopSourceObserver();
        ghost?.remove();
        revealMatchingSource();
      });
      setTimeout(() => {
        if (!ghost?.isConnected || document.querySelector(".spm-table .spm-played-bottom")) return;
        stopSourceObserver();
        ghost.remove();
        revealMatchingSource();
      }, 5000);
      setTimeout(() => {
        const boardCardReady = document.querySelector(".spm-table .spm-played-bottom");
        stopSourceObserver();
        ghost?.remove();
        if (!boardCardReady) revealMatchingSource();
      }, 8000);
    } catch (_) {
      stopSourceObserver();
      ghost?.remove();
      revealMatchingSource();
    }
    const submission = submit();
    if (submission && typeof submission.catch === "function") submission.catch(() => {
      stopSourceObserver();
      ghost?.remove();
      revealMatchingSource();
    });
  }
  function relativeSeats(members, viewerId) {
    const pivot = Math.max(0, members.findIndex((member) => memberId(member) === String(viewerId)));
    const ordered = members.slice(pivot).concat(members.slice(0, pivot));
    const positions = ["bottom", "left", "top", "right"];
    return ordered.map((member, index) => ({ member, position: positions[index] }));
  }
  function historyViewportBounds(view = window) {
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
  function historyPanelPlacement(bounds, anchor, size) {
    if (!bounds) return null;
    const gap = 8, width = Math.min(size.width, Math.max(0, bounds.right - bounds.left - 2 * gap));
    if (!(width > 0 && size.height > 0)) return null;
    const left = Math.max(bounds.left + gap, Math.min((anchor.left + anchor.right - width) / 2, bounds.right - gap - width));
    const minimumTop = bounds.top + gap, maximumTop = bounds.bottom - gap - size.height;
    const below = anchor.bottom + gap, above = anchor.top - gap - size.height;
    const preferred = below <= maximumTop ? below : above >= minimumTop ? above : below;
    return { left, top: Math.max(minimumTop, Math.min(preferred, Math.max(minimumTop, maximumTop))), width };
  }
  function placeHistoryPanel(root, panel, anchor) {
    const view = root.ownerDocument.defaultView, listeners = [];
    let disposed = false, pending = 0;
    const originalPosition = root.style.getPropertyValue('position'), originalOverflow = root.style.getPropertyValue('overflow');
    const preferredWidth = panel.getBoundingClientRect().width || Math.min(root.getBoundingClientRect().width, 640);
    root.style.position = 'relative'; root.style.overflow = 'visible';
    Object.assign(panel.style, { position: 'absolute', right: 'auto', bottom: 'auto', margin: '0', transform: 'none', boxSizing: 'border-box', height: 'auto', overflow: 'hidden', maxWidth: 'none' });
    const sync = () => {
      pending = 0;
      if (disposed || !root.isConnected || !panel.isConnected || !anchor.isConnected) return;
      const bounds = historyViewportBounds(view), rootBox = root.getBoundingClientRect();
      const sx = rootBox.width / Number(root.offsetWidth || rootBox.width), sy = rootBox.height / Number(root.offsetHeight || rootBox.height);
      if (!bounds || !(sx > 0 && sy > 0)) { panel.style.visibility = 'hidden'; return; }
      const width = Math.min(preferredWidth, Math.max(0, bounds.right - bounds.left - 16));
      panel.style.width = width / sx + 'px';
      panel.style.maxHeight = Math.max(180, (bounds.bottom - bounds.top - 16) / sy) + 'px';
      const size = panel.getBoundingClientRect(), position = historyPanelPlacement(bounds, anchor.getBoundingClientRect(), size);
      if (!position) { panel.style.visibility = 'hidden'; return; }
      panel.style.left = ((position.left - rootBox.left) / sx - Number(root.clientLeft || 0) + Number(root.scrollLeft || 0)) + 'px';
      panel.style.top = ((position.top - rootBox.top) / sy - Number(root.clientTop || 0) + Number(root.scrollTop || 0)) + 'px';
      panel.style.visibility = 'visible';
      const body = panel.querySelector('.spm-history-body');
      panel.dataset.historyOverflow = body && body.scrollHeight > body.clientHeight + 1 ? 'internal-scroll' : 'viewport-fit';
    };
    const schedule = () => {
      if (disposed || pending) return;
      pending = view.requestAnimationFrame(sync);
    };
    const listen = owner => {
      owner.addEventListener('resize', schedule, { passive: true });
      owner.addEventListener('scroll', schedule, { passive: true, capture: true });
      owner.visualViewport?.addEventListener('resize', schedule, { passive: true });
      owner.visualViewport?.addEventListener('scroll', schedule, { passive: true });
      listeners.push(owner);
    };
    listen(view);
    try { let owner = view; while (owner !== owner.parent) { owner = owner.parent; listen(owner); } } catch {}
    sync();
    return () => {
      disposed = true;
      if (pending) view.cancelAnimationFrame(pending);
      for (const owner of listeners) {
        owner.removeEventListener('resize', schedule);
        owner.removeEventListener('scroll', schedule, true);
        owner.visualViewport?.removeEventListener('resize', schedule);
        owner.visualViewport?.removeEventListener('scroll', schedule);
      }
      if (originalPosition) root.style.position = originalPosition; else root.style.removeProperty('position');
      if (originalOverflow) root.style.overflow = originalOverflow; else root.style.removeProperty('overflow');
    };
  }
  function historyPanel(state, memberName, onClose) {
    const panel = make("section", "spm-history"), header = make("div", "spm-history-head"), body = make("div", "spm-history-body"), close = make("button", "", "Close");
    const heading = make("h2", "", "Round history");
    panel.id = "spm-round-history-panel";
    panel.tabIndex = -1;
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-labelledby", "spm-round-history-heading");
    heading.id = "spm-round-history-heading";
    close.type = "button";
    close.setAttribute("aria-label", "Close round history");
    panel.style.visibility = "hidden";
    close.addEventListener("click", () => onClose?.({ restoreFocus: true }));
    header.append(heading, close); panel.append(header, body);
    const rounds = state.scoreHistory || state.roundHistory || state.history || [];
    const recordedHands = rounds.map(round => Number(round?.hand || 0)).filter(Number.isFinite);
    const currentHand = Math.max(1, Number(state.handNumber || 0), ...recordedHands);
    panel.dataset.handNumber = String(currentHand);
    const completedTricks = rounds.slice().reverse().filter((round) =>
      round && Number(round.hand || currentHand) === currentHand && Number(round.trick || 0) > 0
        && Array.isArray(round.cards) && round.cards.length > 0
    );
    if (completedTricks.length) body.append(make("h3", "spm-history-section-title", `Completed tricks · Hand ${currentHand}`));
    completedTricks.forEach((round) => {
      const row = make("article", "spm-history-row spm-trick-history-row");
      const title = make("strong", "", `Trick ${Number(round.trick)}`);
      const winner = make("b", "", `${memberName(round.winnerUserId)} won`);
      const cards = make("span", "spm-trick-cards");
      cards.textContent = round.cards.map((play) => {
        const parts = cardParts(play?.card);
        const suit = ({ S: "♠", H: "♥", D: "♦", C: "♣" })[parts.suit] || parts.suit;
        return `${memberName(play?.userId)} ${parts.joker ? parts.rank : parts.rank + suit}`;
      }).join(" · ");
      row.append(title, winner, cards);
      body.append(row);
    });
    if (!completedTricks.length) body.append(make("p", "spm-history-empty", "No completed tricks in this hand yet."));
    return panel;
  }
  function closeHistoryPanel(root, historyButton, { restoreFocus = true } = {}) {
    historyOpen = false;
    historyLayoutCleanup?.();
    historyLayoutCleanup = null;
    root.querySelector("#spm-round-history-panel")?.remove();
    historyButton.setAttribute("aria-expanded", "false");
    if (restoreFocus && historyButton.isConnected) historyButton.focus({ preventScroll: true });
  }
  function openHistoryPanel(root, state, historyButton, memberName, { focus = true } = {}) {
    historyLayoutCleanup?.();
    historyLayoutCleanup = null;
    root.querySelector("#spm-round-history-panel")?.remove();
    historyOpen = true;
    historyButton.setAttribute("aria-expanded", "true");
    const panel = historyPanel(state, memberName, (options) => closeHistoryPanel(root, historyButton, options));
    const closeOnOutsidePointer = (event) => {
      if (panel.contains(event.target) || historyButton.contains(event.target)) return;
      closeHistoryPanel(root, historyButton, { restoreFocus: false });
    };
    root.append(panel);
    root.addEventListener("pointerdown", closeOnOutsidePointer, true);
    let layoutCleanup = null;
    historyLayoutCleanup = () => {
      root.removeEventListener("pointerdown", closeOnOutsidePointer, true);
      layoutCleanup?.();
    };
    requestAnimationFrame(() => {
      if (!root.isConnected || !panel.isConnected || !historyOpen) return;
      layoutCleanup = placeHistoryPanel(root, panel, historyButton);
      if (focus) panel.focus({ preventScroll: true });
    });
    return panel;
  }
  function getHand(state, viewerId) {
    const hand = state.hands?.[viewerId];
    const isPlayer = Array.isArray(state.turnOrder)
      && state.turnOrder.some(userId => String(userId) === String(viewerId));
    if (hand === undefined && !isPlayer) return [];
    if (Array.isArray(hand)) return hand;
    // An eligible Blind Nil bidder has a count-only hand until choosing to view it.
    // This is a privacy projection, not an empty deal or permission to reveal cards.
    if (isPlayer && state.phase === "bidding" && hand && typeof hand === "object"
      && hand.private === true && hand.notViewed === true
      && Number.isInteger(hand.count) && hand.count >= 0 && hand.count <= 13
      && Object.keys(hand).length === 3
      && Object.keys(hand).every(key => ["private", "count", "notViewed"].includes(key))) return [];
    throw new TypeError("Invalid Spades viewer hand projection.");
  }
  function manualPlayAvailable(context, viewerId) {
    const state = gameState(context.session);
    return context.session?.status === "active" && state.phase === "playing"
      && !state.completed && !context.busy
      && String(context.session?.turnUserId) === String(viewerId);
  }
  function boardTurnLabel(context, state, members, viewerId) {
    const status = String(context.session?.status || "");
    const terminalLabels = { completed: "Game complete", forfeited: "Game forfeited", abandoned: "Game ended", ended: "Game ended", cancelled: "Game cancelled", expired: "Game expired" };
    const winner = state.winningTeam;
    if ((status === "completed" || (state.completed === true && !Object.hasOwn(terminalLabels, status)))
      && (winner === 0 || winner === 1)) return `Team ${winner + 1} wins`;
    if (Object.hasOwn(terminalLabels, status)) return terminalLabels[status];
    if (state.completed === true) return "Game complete";
    if (status === "lobby") return "Waiting to start";
    if (status === "paused") return "Game paused";
    if (state.phase === "deal") return "Waiting for deal";
    if (state.phase === "settling") return "Settling trick";
    const turnMember = members.find((member) => memberId(member) === String(context.session?.turnUserId));
    return turnMember ? (memberId(turnMember) === viewerId ? "Your turn" : context.memberName(memberId(turnMember)) + "'s turn") : "Waiting for next turn";
  }
  function render(context) {
    historyLayoutCleanup?.();
    historyLayoutCleanup = null;
    const appearance = context.session?.presentation?.effectivePack || "built-in";
    if (String(appearance).toLowerCase() !== "built-in") return null;
    const state = gameState(context.session), viewerId = String(typeof context.currentUserId === "function" ? context.currentUserId() : (context.currentUserId ?? "")), sourceMembers = (context.session?.members || state.players || []).filter(Boolean), members = (state.turnOrder || []).map((userId) => sourceMembers.find((member) => memberId(member) === String(userId))).filter(Boolean), root = make("div", "spm-board"), seatByPlayer = new Map();
    relativeSeats(members, viewerId).forEach(({ member, position }) => {
      seatByPlayer.set(memberId(member), position);
      const seat = make("article", "spm-seat spm-seat-" + position), avatar = context.memberAvatar(member, "spm-avatar"); avatar.dataset.diagnosticAvatarFrame = "true"; avatar.alt = context.memberName(memberId(member)) + " avatar"; avatar.draggable = false;
      const bids = state.bids || {}, tricks = state.tricksWon || {}, bid = bids[memberId(member)], bidKind = String(bid?.kind || "").toLowerCase(), bidAmount = Number(bid?.amount ?? bid), bidLabel = bid == null || (!['blind-nil', 'nil'].includes(bidKind) && !Number.isFinite(bidAmount)) ? "BIDDING" : (bidKind === "blind-nil" ? "BLIND NIL" : bidKind === "nil" ? "NIL" : "BID " + bidAmount);
      const name = make("strong", "", context.memberName(memberId(member))), status = make("span", "", bidLabel + (bid == null ? "" : " · " + Number(tricks[memberId(member)] || 0) + " tricks"));
      // Annotate painted components, not the transparent seat layout footprint.
      name.dataset.diagnosticAvatarFrame = "true"; status.dataset.diagnosticAvatarFrame = "true";
      seat.append(avatar, name, status); root.append(seat);
    });
    const table = make("section", "spm-table"), trick = make("div", "spm-trick"), currentTrick = state.currentTrick || state.trick || []; table.dataset.diagnosticPlayArea = "true";
    const played = Array.isArray(currentTrick) ? currentTrick : Object.entries(currentTrick).map(([playerId, card]) => ({ playerId, card }));
    played.forEach((entry, index) => {
      const playerId = String(entry.playerId ?? entry.playerUserId ?? entry.userId ?? memberId(members[index])), position = seatByPlayer.get(playerId) || ["bottom", "left", "top", "right"][index], playedCard = cardNode(entry.card ?? entry, true);
      playedCard.classList.add("spm-played", "spm-played-" + position);
      playedCard.tabIndex = -1;
      const owner = members.find((member) => memberId(member) === playerId); playedCard.append(make("small", "spm-card-owner", owner ? context.memberName(memberId(owner)) : "Player")); trick.append(playedCard);
    });
    table.append(trick); table.append(make("div", "spm-turn", boardTurnLabel(context, state, members, viewerId))); root.append(table);
    const scoreboard = make("aside", "spm-scoreboard"); scoreboard.append(make("h2", "", "Score details"));
    [0, 1].forEach((teamIndex) => {
      const teamUsers = (state.turnOrder || []).filter((_userId, index) => index % 2 === teamIndex);
      const individualBids = teamUsers.map((userId) => {
        const playerBid = state.bids?.[String(userId)];
        const kind = String(playerBid?.kind || "").toLowerCase();
        const amount = Number(playerBid?.amount ?? playerBid);
        const label = playerBid == null || (!['blind-nil', 'nil'].includes(kind) && !Number.isFinite(amount)) ? "Bid ?" : kind === "blind-nil" ? "Blind Nil bid" : kind === "nil" ? "Nil bid" : `Bid ${amount}`;
        return { name: context.memberName(userId), label };
      });
      const bid = Number(state.teamBids?.[String(teamIndex)]?.amount ?? 0);
      const tricks = teamUsers.reduce((sum, userId) => sum + Number(state.tricksWon?.[String(userId)] || 0), 0);
      const bags = Number(state.teamBags?.[String(teamIndex)] || 0);
      const bagRule = String(state.settings?.bagRule || "ten-minus-100");
      const bagTarget = bagRule === "five-minus-50" ? 5 : bagRule === "ten-minus-100" ? 10 : 0;
      const bagLabel = bagTarget ? `${bags}/${bagTarget}` : bagRule === "minus-10-each" ? "-10 each" : bagRule === "no-penalty" ? "+1 each" : "Off";
      const score = Number(state.teamScores?.[String(teamIndex)] || 0);
      const last = [...(state.history || [])].reverse().find((entry) => Number(entry?.team) === teamIndex && Object.hasOwn(entry || {}, "scoreChange"));
      const contractScore = Number(last?.contractScore ?? 0);
      const bagsEarned = Number(last?.bagsEarned ?? last?.bagPoints ?? 0);
      const nilAdjustment = Number(last?.nilAdjustment ?? 0);
      const bostonBonus = Number(last?.bostonBonus ?? 0);
      const bagPenalty = Number(last?.bagPenalty ?? 0);
      const handScore = Number(last?.scoreChange ?? 0);
      const signed = (value) => value > 0 ? `+${value}` : String(value);
      const prior = (value) => last ? signed(value) : "—";
      const panel = make("article", "spm-score-team" + (bagTarget > 0 && bags >= Math.max(1, bagTarget - 3) ? " has-bag-warning" : ""));
      panel.innerHTML = `<div class="spm-score-head"><div class="spm-team-title"><strong>Team ${teamIndex + 1}</strong><small></small></div><div class="spm-score-stat"><span>Total</span><b>${score}</b></div><div class="spm-score-stat"><span>Last hand</span><b>${prior(handScore)}</b></div><div class="spm-score-stat"><span>Bags</span><b>${bagLabel}</b></div></div><div class="spm-score-math"><div><span>Contract</span><b>${bid}</b></div><div><span>Tricks</span><b>${tricks}</b></div><div><span>Bid points</span><b>${prior(contractScore)}</b></div><div><span>Overtricks</span><b>${last ? bagsEarned : "—"}</b></div><div class="is-bonus"><span>Nil / Boston</span><b>${prior(nilAdjustment + bostonBonus)}</b></div><div class="is-warning"><span>Bag penalty</span><b>${prior(bagPenalty)}</b></div><div class="is-total"><span>Round score</span><b>${prior(handScore)}</b></div></div><div class="spm-bag-meter" aria-label="Team ${teamIndex + 1} bag status: ${bagLabel}"><span style="width:${bagTarget ? Math.max(0, Math.min(bagTarget, bags)) * 100 / bagTarget : 0}%"></span></div>`;
      const playerBidRows = panel.querySelector(".spm-team-title small");
      individualBids.forEach(({ name, label }) => {
        const row = make("span", "spm-player-bid");
        row.append(make("span", "spm-player-bid-name", name), make("b", "", label));
        playerBidRows.append(row);
      });
      scoreboard.append(panel);
    }); root.append(scoreboard);
    const hand = getHand(state, viewerId), legalCards = state.legalCards || [], isViewerTurn = String(context.session?.turnUserId) === viewerId;
    const passSelection = context.spadesPassSelection || {};
    const passSelectionActive = passSelection.active === true && typeof passSelection.toggle === "function";
    const passSelectionCount = Math.max(1, Math.min(2, Number(passSelection.requiredCount || 2)));
    const selectedPassCodes = new Set((Array.isArray(passSelection.selectedCards) ? passSelection.selectedCards : []).map(codeOf));
    const canPlayHand = manualPlayAvailable(context, viewerId);
    const handCodes = hand.map(codeOf);
    if (pendingPlayCard && !handCodes.includes(pendingPlayCard)) pendingPlayCard = "";
    if (!canPlayHand || !handCodes.includes(selectedCard)) selectedCard = "";
    const handArea = make("section", "spm-hand-area"), historyButton = make("button", "spm-round-history", "Round history"), fan = make("div", "spm-hand");
    historyButton.type = "button";
    historyButton.setAttribute("aria-controls", "spm-round-history-panel");
    historyButton.setAttribute("aria-expanded", historyOpen ? "true" : "false");
    bindReliableActivation(historyButton, () => {
      if (historyOpen) closeHistoryPanel(root, historyButton);
      else openHistoryPanel(root, state, historyButton, context.memberName);
    });
    handArea.append(historyButton);
    const boardWidth = document.querySelector(".spm-board")?.clientWidth || document.documentElement.clientWidth || window.innerWidth;
    const handSpacing = hand.length < 2 ? 0 : boardWidth <= 960 ? Math.min(44, Math.max(24, (boardWidth - 72) / (hand.length - 1))) : Math.min(66, Math.max(34, window.innerWidth * .057));
    hand.forEach((value, index) => {
      const code = codeOf(value), legal = legalCards.length === 0 || legalCards.map(codeOf).includes(code), canPlay = canPlayHand && legal;
      const passSelected = selectedPassCodes.has(code);
      const card = cardNode(value, passSelectionActive || !canPlayHand || legal, !passSelectionActive && canPlay ? (activation = {}) => {
        if (pendingPlayCard || !card.isConnected || !manualPlayAvailable(context, viewerId)) return;
        if (activation.commit === true || selectedCard === code) {
          selectedCard = "";
          pendingPlayCard = code;
          animateCardPlay(card, () => {
            let submission;
            try {
              submission = context.performAction("play", { card: actionCodeOf(value) });
            } catch (error) {
              pendingPlayCard = "";
              context.rerender();
              throw error;
            }
            if (submission && typeof submission.then === "function") {
              return submission.then((succeeded) => {
                if (succeeded === false) {
                  pendingPlayCard = "";
                  context.rerender();
                }
                return succeeded;
              }, (error) => {
                pendingPlayCard = "";
                context.rerender();
                throw error;
              });
            }
            if (submission === false) {
              pendingPlayCard = "";
              context.rerender();
            }
            return submission;
          });
        } else {
          selectedCard = code;
          card.parentElement?.querySelectorAll(".spm-card").forEach((candidate) => {
            const active = candidate === card;
            candidate.setAttribute("aria-pressed", active ? "true" : "false");
            candidate.classList.toggle("is-selected", active);
          });
        }
      } : null);
      if (passSelectionActive) bindReliableActivation(card, () => passSelection.toggle(actionCodeOf(value)));
      const relativeIndex = index - (hand.length - 1) / 2;
      card.disabled = !(passSelectionActive || canPlay);
      card.classList.toggle("is-unplayable", !passSelectionActive && canPlayHand && !legal);
      card.classList.toggle("is-pass-selected", passSelected);
      card.classList.toggle("is-selected", selectedCard === code || passSelected);
      card.setAttribute("aria-disabled", passSelectionActive || canPlay ? "false" : "true");
      card.setAttribute("aria-pressed", selectedCard === code || passSelected ? "true" : "false");
      if (passSelectionActive) card.setAttribute("aria-label", `${passSelected ? "Selected" : "Select"} ${card.getAttribute("aria-label")} for the ${passSelectionCount === 1 ? "one-card" : "two-card"} exchange`);
      card.style.setProperty("--card-offset", (relativeIndex * handSpacing) + "px");
      card.style.setProperty("--card-angle", (relativeIndex * .7) + "deg");
      card.style.setProperty("--card-index", index);
      if (pendingPlayCard === code) card.style.visibility = "hidden";
      fan.append(card);
    }); handArea.append(fan); root.append(handArea);
    fan.querySelectorAll(".spm-card").forEach(card=>card.classList.toggle("is-received-card", context.receivedCards?.has(actionCodeOf(card.dataset.cardCode)) || context.receivedCards?.has(card.dataset.cardCode)));
    if (historyOpen) openHistoryPanel(root, state, historyButton, context.memberName, { focus: false });
    return root;
  }
  window.CoreChatSpadesModern = { render, bindCardActivation };
})();

;(() => {
  "use strict";
  const compactClass = "spm-host-compact";
  const hostViewportHeight = () => {
    let height = Number(window.visualViewport?.height || window.innerHeight || 0);
    try {
      const host = window.top;
      const hostHeight = Number(host?.visualViewport?.height || host?.innerHeight || 0);
      if (hostHeight > 0) height = height > 0 ? Math.min(height, hostHeight) : hostHeight;
    } catch {}
    return height;
  };
  const syncCompactSeats = () => {
    const board = document.querySelector(".spm-board");
    if (board) board.classList.toggle(compactClass, hostViewportHeight() > 0 && hostViewportHeight() < 960);
  };
  const startCompactSeats = () => {
    window.addEventListener("resize", syncCompactSeats, { passive: true });
    window.visualViewport?.addEventListener("resize", syncCompactSeats, { passive: true });
    try { if (window.top !== window) window.top.addEventListener("resize", syncCompactSeats, { passive: true }); } catch {}
    const root = document.getElementById("game-root") || document.body;
    new MutationObserver(syncCompactSeats).observe(root, { childList: true, subtree: true });
    syncCompactSeats();
  };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", startCompactSeats, { once: true });
  else startCompactSeats();
})();

;(() => {
  const compactTeamBidLabels = () => {
    document.querySelectorAll(".spm-score-team").forEach((team) => {
      const line = Array.from(team.children).find((child) => /^Individual bids:/i.test(child.textContent.trim()));
      if (!line) return;
      const values = line.textContent.replace(/^Individual bids:\s*/i, "").split(/\s+\+\s+/);
      let total = 0;
      const specialBids = [];
      values.forEach((value) => {
        const match = value.match(/(Blind Nil|Nil|-?\d+)\s*$/i);
        if (!match) return;
        if (/^-?\d+$/.test(match[1])) total += Number(match[1]);
        else specialBids.push(match[1].replace(/^blind nil$/i, "Blind Nil").replace(/^nil$/i, "Nil"));
      });
      line.textContent = "Team bid: " + total + (specialBids.length ? " + " + specialBids.join(" + ") : "");
    });
  };
  const startCompactTeamBids = () => {
    const observer = new MutationObserver(compactTeamBidLabels);
    observer.observe(document.body, { childList: true, subtree: true });
    compactTeamBidLabels();
  };
  if (document.body) startCompactTeamBids();
  else document.addEventListener("DOMContentLoaded", startCompactTeamBids, { once: true });
})();
;(() => {
  const separateSpadesHandArea = () => {
    const board = document.querySelector(".spm-board");
    const handArea = board && board.querySelector(".spm-hand-area");
    if (board && handArea && handArea.parentElement !== board) board.appendChild(handArea);
  };
  const startSpadesHandSeparation = () => {
    const observer = new MutationObserver(separateSpadesHandArea);
    observer.observe(document.body, { childList: true, subtree: true });
    separateSpadesHandArea();
  };
  if (document.body) startSpadesHandSeparation();
  else document.addEventListener("DOMContentLoaded", startSpadesHandSeparation, { once: true });
})();
;(() => {
  const botAvatarByName = Object.freeze({
    "Normal Bot 2": "../../assets/images/spades-modern/bot-2.png",
    "Expert Bot 2": "../../assets/images/spades-modern/bot-2.png",
    "Normal Bot 3": "../../assets/images/spades-modern/bot-3.png",
    "Expert Bot 3": "../../assets/images/spades-modern/bot-3.png",
    "Normal Bot 4": "../../assets/images/spades-modern/normal-bot-4.png",
    "Expert Bot 4": "../../assets/images/spades-modern/normal-bot-4.png"
  });
  const assignSpadesBotAvatars = () => {
    document.querySelectorAll(".spm-seat").forEach((seat) => {
      const name = seat.querySelector("strong")?.textContent?.trim() || "";
      const avatarPath = botAvatarByName[name];
      const image = seat.querySelector("img");
      if (!avatarPath || !image || image.dataset.spmBotAvatar === avatarPath) return;
      image.src = avatarPath;
      image.dataset.spmBotAvatar = avatarPath;
    });
  };
  const startSpadesBotAvatars = () => {
    const observer = new MutationObserver(assignSpadesBotAvatars);
    observer.observe(document.body, { childList: true, subtree: true });
    assignSpadesBotAvatars();
  };
  if (document.body) startSpadesBotAvatars();
  else document.addEventListener("DOMContentLoaded", startSpadesBotAvatars, { once: true });
})();

;(() => {
  "use strict";
  const lingerMs = 3000;
  let completedCards = [];
  let completedSignature = "";
  let completedAt = 0;
  let clearTimer = 0;

  const removeOverlay = () => document.querySelectorAll(".spm-completed-trick").forEach((overlay) => overlay.remove());
  const cardSignature = (cards) => cards.map((card) => `${card.className}:${card.textContent.trim()}`).join("|");

  const syncCompletedTrick = () => {
    const table = document.querySelector(".spm-table");
    const trick = table?.querySelector(":scope > .spm-trick:not(.spm-completed-trick)");
    if (!table || !trick) return;
    const liveCards = Array.from(trick.querySelectorAll(".spm-played"));
    if (liveCards.length === 4) {
      const signature = cardSignature(liveCards);
      if (signature !== completedSignature) {
        completedCards = liveCards.map((card) => card.cloneNode(true));
        completedSignature = signature;
        completedAt = Date.now();
      }
      removeOverlay();
      return;
    }
    if (!completedCards.length) return;
    const remaining = lingerMs - (Date.now() - completedAt);
    if (remaining <= 0) {
      completedCards = [];
      completedSignature = "";
      removeOverlay();
      return;
    }
    let overlay = table.querySelector(":scope > .spm-completed-trick");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.className = "spm-trick spm-completed-trick";
      completedCards.forEach((card) => overlay.append(card.cloneNode(true)));
      table.append(overlay);
    }
    window.clearTimeout(clearTimer);
    clearTimer = window.setTimeout(() => {
      completedCards = [];
      completedSignature = "";
      removeOverlay();
    }, remaining);
  };

  new MutationObserver(syncCompletedTrick).observe(document.body, { childList: true, subtree: true });
  syncCompletedTrick();
})();/* Reflow the fanned hand whenever the embedded game pane changes size. */
;(() => {
  let frame = 0;
  let observedBoard = null;
  const fittedSideAvatars = new Map();

  const restoreSideAvatar = (avatar) => {
    const saved = fittedSideAvatars.get(avatar);
    if (!saved) return;
    for (const name of ['transform', 'transform-origin']) {
      const applied = saved.applied[name], original = saved.original[name];
      if (avatar.style.getPropertyValue(name) !== applied.value
        || avatar.style.getPropertyPriority(name) !== applied.priority) continue;
      if (original.value) avatar.style.setProperty(name, original.value, original.priority);
      else avatar.style.removeProperty(name);
    }
    fittedSideAvatars.delete(avatar);
  };

  // Only the portrait and its border shrink. Name/bid/trick layout stays intact.
  // offset dimensions are unzoomed; measured board ratios remove height-fit zoom.
  function syncNarrowSideAvatars(board) {
    const retained = new Set(), results = [];
    const finish = () => {
      for (const avatar of fittedSideAvatars.keys()) if (!retained.has(avatar)) restoreSideAvatar(avatar);
      return results;
    };
    if (!board?.isConnected || !(board.clientWidth > 0 && board.clientWidth < 420)) return finish();
    const table = board.querySelector('.spm-table');
    if (!table?.isConnected) return finish();
    const box = board.getBoundingClientRect(), play = table.getBoundingClientRect();
    const sx = box.width / board.offsetWidth, sy = box.height / board.offsetHeight;
    if (![sx, sy, play.left, play.right, play.top, play.bottom].every(Number.isFinite)
      || sx <= 0 || sy <= 0 || play.right <= play.left || play.bottom <= play.top) return finish();
    for (const side of ['left', 'right']) {
      const seat = board.querySelector('.spm-seat-' + side), avatar = seat?.querySelector('.spm-avatar');
      if (!avatar?.isConnected || avatar.parentElement !== seat || avatar.offsetParent !== seat) continue;
      const saved = fittedSideAvatars.get(avatar);
      if (saved && Object.keys(saved.applied).some(name => avatar.style.getPropertyValue(name) !== saved.applied[name].value
        || avatar.style.getPropertyPriority(name) !== saved.applied[name].priority)) {
        restoreSideAvatar(avatar);
        continue;
      }
      if (!saved && !['none', ''].includes(board.ownerDocument.defaultView.getComputedStyle(avatar).transform || 'none')) continue;
      const width = Number(avatar.offsetWidth), height = Number(avatar.offsetHeight), seatBox = seat.getBoundingClientRect();
      const left = seatBox.left + (Number(seat.clientLeft || 0) + Number(avatar.offsetLeft || 0)) * sx;
      const top = seatBox.top + (Number(seat.clientTop || 0) + Number(avatar.offsetTop || 0)) * sy;
      const right = left + width * sx, bottom = top + height * sy;
      if (![width, height, left, top, right, bottom].every(Number.isFinite) || width <= 0 || height <= 0) continue;
      // The bordered188px trick permits a side card1px beyond its outer edge.
      if (right <= play.left - sx || left >= play.right + sx || bottom <= play.top || top >= play.bottom) continue;
      const available = (side === 'left' ? play.left - left : right - play.right) / sx - 9;
      // Do not turn an impossible gutter into an invisible or unreadable portrait.
      if (available < 48) { results.push({ side, status: 'width-limited', available }); continue; }
      const scale = Math.min(1, Math.floor(available / width * 1000000) / 1000000);
      if (!(scale > 0 && scale < 1)) continue;
      const original = saved?.original || Object.fromEntries(['transform', 'transform-origin'].map(name => [name,
        { value: avatar.style.getPropertyValue(name), priority: avatar.style.getPropertyPriority(name) }]));
      const changes = { transform: 'scale(' + scale + ')', 'transform-origin': side + ' top' };
      for (const [name, value] of Object.entries(changes)) {
        if (avatar.style.getPropertyValue(name) !== value || avatar.style.getPropertyPriority(name) !== 'important') avatar.style.setProperty(name, value, 'important');
      }
      const applied = Object.fromEntries(Object.keys(changes).map(name => [name,
        { value: avatar.style.getPropertyValue(name), priority: avatar.style.getPropertyPriority(name) }]));
      fittedSideAvatars.set(avatar, { original, applied });
      retained.add(avatar);
      results.push({ side, status: 'scaled', scale, available });
    }
    return finish();
  }

  const fittedSideLabels = new Map();
  const restoreSideLabel = label => {
    const saved = fittedSideLabels.get(label);
    if (!saved) return;
    for (const key of Object.keys(saved.applied)) {
      const applied = saved.applied[key], original = saved.original[key];
      if (label.style.getPropertyValue(key) !== applied.value || label.style.getPropertyPriority(key) !== applied.priority) continue;
      if (original.value) label.style.setProperty(key, original.value, original.priority);
      else label.style.removeProperty(key);
    }
    fittedSideLabels.delete(label);
  };

  function syncNarrowSideLabels(board) {
    const retained = new Set(), results = [];
    const finish = () => {
      for (const label of fittedSideLabels.keys()) if (!retained.has(label)) restoreSideLabel(label);
      return results;
    };
    if (!board?.isConnected || !(board.clientWidth > 0 && board.clientWidth < 420)) return finish();
    const table = board.querySelector('.spm-table');
    if (!table?.isConnected) return finish();
    const box = board.getBoundingClientRect(), play = table.getBoundingClientRect();
    const sx = box.width / board.offsetWidth;
    if (!(sx > 0) || !Number.isFinite(sx)) return finish();
    for (const side of ['left', 'right']) {
      const seat = board.querySelector('.spm-seat-' + side);
      if (!seat?.isConnected) continue;
      const seatBox = seat.getBoundingClientRect();
      const available = Math.floor((side === 'left' ? play.left - seatBox.left : seatBox.right - play.right) / sx - 9);
      // Keep readable captions, with the same outer-edge alignment as portraits.
      if (!Number.isFinite(available) || available < 48) { results.push({ side, status: 'width-limited', available }); continue; }
      if (available >= seatBox.width / sx) continue;
      for (const label of seat.querySelectorAll('strong,span')) {
        const saved = fittedSideLabels.get(label);
        if (saved && Object.keys(saved.applied).some(key => label.style.getPropertyValue(key) !== saved.applied[key].value
          || label.style.getPropertyPriority(key) !== saved.applied[key].priority)) continue;
        const changes = { width: available + 'px', 'max-width': available + 'px',
          'margin-left': side === 'right' ? 'auto' : '0px', 'margin-right': side === 'left' ? 'auto' : '0px',
          'white-space': 'normal', 'overflow-wrap': 'anywhere', 'box-sizing': 'border-box' };
        const original = saved?.original || Object.fromEntries(Object.keys(changes).map(key => [key,
          { value: label.style.getPropertyValue(key), priority: label.style.getPropertyPriority(key) }]));
        for (const [key, value] of Object.entries(changes)) if (label.style.getPropertyValue(key) !== value || label.style.getPropertyPriority(key) !== 'important') label.style.setProperty(key, value, 'important');
        const applied = Object.fromEntries(Object.keys(changes).map(key => [key,
          { value: label.style.getPropertyValue(key), priority: label.style.getPropertyPriority(key) }]));
        fittedSideLabels.set(label, { original, applied }); retained.add(label);
      }
      results.push({ side, status: 'fitted', available });
    }
    return finish();
  }

  const fittedNorthAvatars = new Map();
  const restoreNorthAvatar = avatar => {
    const saved = fittedNorthAvatars.get(avatar);
    if (!saved) return;
    for (const name of Object.keys(saved.applied)) {
      const applied = saved.applied[name], original = saved.original[name];
      if (avatar.style.getPropertyValue(name) !== applied.value || avatar.style.getPropertyPriority(name) !== applied.priority) continue;
      if (original.value) avatar.style.setProperty(name, original.value, original.priority); else avatar.style.removeProperty(name);
    }
    fittedNorthAvatars.delete(avatar);
  };

  function syncNorthAvatarClearance(board) {
    let retained = null;
    const finish = result => {
      for (const avatar of fittedNorthAvatars.keys()) if (avatar !== retained) restoreNorthAvatar(avatar);
      return result;
    };
    const seat = board?.querySelector('.spm-seat-top'), avatar = seat?.querySelector('.spm-avatar');
    const name = seat?.querySelector('strong'), status = seat?.querySelector('span'), table = board?.querySelector('.spm-table');
    if (![board, seat, avatar, name, status, table].every(node => node?.isConnected)
      || avatar.parentElement !== seat || avatar.offsetParent !== seat) return finish({ status: 'unavailable' });
    const saved = fittedNorthAvatars.get(avatar);
    if (saved && Object.keys(saved.applied).some(key => avatar.style.getPropertyValue(key) !== saved.applied[key].value
      || avatar.style.getPropertyPriority(key) !== saved.applied[key].priority)) return finish({ status: 'external-style' });
    const style = board.ownerDocument.defaultView.getComputedStyle(avatar);
    if (!saved && (!['none', ''].includes(style.transform || 'none') || Number.parseFloat(style.marginBottom || '0') !== 0)) return finish({ status: 'authored-style' });
    const box = board.getBoundingClientRect(), seatBox = seat.getBoundingClientRect(), play = table.getBoundingClientRect();
    const sx = box.width / board.offsetWidth, sy = box.height / board.offsetHeight;
    const width = Number(avatar.offsetWidth), height = Number(avatar.offsetHeight);
    const left = seatBox.left + (Number(seat.clientLeft || 0) + Number(avatar.offsetLeft || 0)) * sx;
    const top = seatBox.top + (Number(seat.clientTop || 0) + Number(avatar.offsetTop || 0)) * sy;
    const nameBox = name.getBoundingClientRect(), statusBox = status.getBoundingClientRect();
    const priorMargin = Number.parseFloat(saved?.applied['margin-bottom'].value || '0');
    // Transform leaves authored image dimensions measurable. Its matching negative
    // margin lifts the unchanged name/bid/trick rows instead of shrinking their text.
    const authoredBottom = statusBox.bottom - priorMargin * sy;
    const captionHeight = (authoredBottom - top) / sy - height;
    const unionLeft = Math.min(left, nameBox.left, statusBox.left), unionRight = Math.max(left + width * sx, nameBox.right, statusBox.right);
    if (![sx, sy, width, height, left, top, authoredBottom, captionHeight, unionLeft, unionRight, play.left, play.right, play.top].every(Number.isFinite)
      || sx <= 0 || sy <= 0 || width <= 0 || height <= 0 || captionHeight <= 0
      || nameBox.height <= 0 || statusBox.height <= 0) return finish({ status: 'invalid-geometry' });
    if (unionRight <= play.left || unionLeft >= play.right) return finish({ status: 'clear' });
    let protectedTop = play.top;
    for (const card of board.querySelectorAll('.spm-played-top')) {
      const cardBox = card.getBoundingClientRect();
      if (card.isConnected && cardBox.width > 0 && cardBox.height > 0 && unionRight > cardBox.left && unionLeft < cardBox.right) protectedTop = Math.min(protectedTop, cardBox.top);
    }
    const available = (protectedTop - top) / sy - captionHeight - 9;
    if (available >= height) return finish({ status: 'clear' });
    const factor = Math.floor(Math.min(1, available / height) * 1000000) / 1000000;
    if (!(factor > 0) || Math.min(width, height) * factor < 48) return finish({ status: 'height-limited', available });
    const changes = { transform: 'scale(' + factor + ')', 'transform-origin': 'center top', 'margin-bottom': (height * (factor - 1)) + 'px' };
    const original = saved?.original || Object.fromEntries(Object.keys(changes).map(key => [key,
      { value: avatar.style.getPropertyValue(key), priority: avatar.style.getPropertyPriority(key) }]));
    for (const [key, value] of Object.entries(changes)) if (avatar.style.getPropertyValue(key) !== value || avatar.style.getPropertyPriority(key) !== 'important') avatar.style.setProperty(key, value, 'important');
    const applied = Object.fromEntries(Object.keys(changes).map(key => [key,
      { value: avatar.style.getPropertyValue(key), priority: avatar.style.getPropertyPriority(key) }]));
    fittedNorthAvatars.set(avatar, { original, applied }); retained = avatar;
    return finish({ status: 'scaled', factor, available });
  }

  const fittedSouthAvatars = new Map();
  const restoreSouthAvatar = avatar => {
    const saved = fittedSouthAvatars.get(avatar);
    if (!saved) return;
    for (const key of Object.keys(saved.applied)) {
      const applied = saved.applied[key], original = saved.original[key];
      if (avatar.style.getPropertyValue(key) !== applied.value || avatar.style.getPropertyPriority(key) !== applied.priority) continue;
      if (original.value) avatar.style.setProperty(key, original.value, original.priority); else avatar.style.removeProperty(key);
    }
    fittedSouthAvatars.delete(avatar);
  };

  // Keep the bottom portrait below the trick and turn label in fitted bidding
  // as well as play. Bottom anchoring preserves the name/bid/trick row positions.
  function syncSouthAvatarClearance(board) {
    let retained = null;
    const finish = result => {
      for (const avatar of fittedSouthAvatars.keys()) if (avatar !== retained) restoreSouthAvatar(avatar);
      return result;
    };
    const seat = board?.querySelector('.spm-seat-bottom'), avatar = seat?.querySelector('.spm-avatar');
    const table = board?.querySelector('.spm-table');
    if (![board, seat, avatar, table].every(node => node?.isConnected)
      || avatar.parentElement !== seat || avatar.offsetParent !== seat) return finish({ status: 'unavailable' });
    const saved = fittedSouthAvatars.get(avatar);
    if (saved && Object.keys(saved.applied).some(key => avatar.style.getPropertyValue(key) !== saved.applied[key].value
      || avatar.style.getPropertyPriority(key) !== saved.applied[key].priority)) return finish({ status: 'external-style' });
    const style = board.ownerDocument.defaultView.getComputedStyle(avatar);
    if (!saved && !['none', ''].includes(style.transform || 'none')) return finish({ status: 'authored-style' });
    const box = board.getBoundingClientRect(), seatBox = seat.getBoundingClientRect(), play = table.getBoundingClientRect();
    const sx = box.width / board.offsetWidth, sy = box.height / board.offsetHeight;
    const width = Number(avatar.offsetWidth), height = Number(avatar.offsetHeight);
    const left = seatBox.left + (Number(seat.clientLeft || 0) + Number(avatar.offsetLeft || 0)) * sx;
    const top = seatBox.top + (Number(seat.clientTop || 0) + Number(avatar.offsetTop || 0)) * sy;
    const right = left + width * sx, bottom = top + height * sy;
    if (![sx, sy, width, height, left, top, right, bottom, play.left, play.right, play.bottom].every(Number.isFinite)
      || sx <= 0 || sy <= 0 || width <= 0 || height <= 0) return finish({ status: 'invalid-geometry' });
    if (right <= play.left || left >= play.right) return finish({ status: 'clear' });
    let protectedBottom = play.bottom;
    for (const node of board.querySelectorAll('.spm-played-bottom, .spm-turn')) {
      const rect = node.getBoundingClientRect();
      if (node.isConnected && rect.width > 0 && rect.height > 0 && right > rect.left && left < rect.right) protectedBottom = Math.max(protectedBottom, rect.bottom);
    }
    const available = (bottom - protectedBottom) / sy - 9;
    if (available >= height) return finish({ status: 'clear' });
    const factor = Math.floor(Math.min(1, available / height) * 1000000) / 1000000;
    if (!(factor > 0) || Math.min(width, height) * factor < 48) return finish({ status: 'height-limited', available });
    const changes = { transform: 'scale(' + factor + ')', 'transform-origin': 'center bottom' };
    const original = saved?.original || Object.fromEntries(Object.keys(changes).map(key => [key,
      { value: avatar.style.getPropertyValue(key), priority: avatar.style.getPropertyPriority(key) }]));
    for (const [key, value] of Object.entries(changes)) if (avatar.style.getPropertyValue(key) !== value || avatar.style.getPropertyPriority(key) !== 'important') avatar.style.setProperty(key, value, 'important');
    const applied = Object.fromEntries(Object.keys(changes).map(key => [key,
      { value: avatar.style.getPropertyValue(key), priority: avatar.style.getPropertyPriority(key) }]));
    fittedSouthAvatars.set(avatar, { original, applied }); retained = avatar;
    return finish({ status: 'scaled', factor, available });
  }

  const relayoutHand = () => {
    frame = 0;
    const board = document.querySelector('.spm-board');
    const cards = Array.from(document.querySelectorAll('.spm-hand > .spm-card'));
    if (board !== observedBoard) {
      if (observedBoard) handResizeObserver?.unobserve(observedBoard);
      observedBoard = board;
      if (board) handResizeObserver?.observe(board);
    }
    syncNarrowSideAvatars(board);
    syncNarrowSideLabels(board);
    syncNorthAvatarClearance(board);
    syncSouthAvatarClearance(board);
    if (!board || cards.length < 2) return;

    const compact = board.clientWidth <= 960;
    const spacing = compact
      ? Math.min(44, Math.max(24, (board.clientWidth - 72) / (cards.length - 1)))
      : Math.min(66, Math.max(34, window.innerWidth * 0.057));
    const center = (cards.length - 1) / 2;

    cards.forEach((card, index) => {
      card.style.setProperty('--card-offset', `${(index - center) * spacing}px`);
    });
  };

  const scheduleHandLayout = () => {
    if (frame) cancelAnimationFrame(frame);
    frame = requestAnimationFrame(relayoutHand);
  };

  window.addEventListener('resize', scheduleHandLayout, { passive: true });
  window.visualViewport?.addEventListener('resize', scheduleHandLayout, { passive: true });
  try {
    if (window.top !== window) window.top.addEventListener('resize', scheduleHandLayout, { passive: true });
  } catch {}
  document.fonts?.addEventListener('loadingdone', scheduleHandLayout);
  // Host compact-mode classes can resize portraits without resizing the board.
  // Observe classes, not our own inline transforms, to avoid a feedback loop.
  new MutationObserver(scheduleHandLayout).observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class'],
  });
  const handResizeObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(scheduleHandLayout) : null;
  handResizeObserver?.observe(document.documentElement);
  scheduleHandLayout();
})();

