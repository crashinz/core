const NestedFour = (() => {
  const SCALE_STEPS = Object.freeze([1, 1.25, 1.5, 1.75, 2]);
  const SCALE_STORAGE_KEY = "corechat.nested-four.board-scale.v1";
  const SOUND_FILES = Object.freeze({
    select: "ui-select.ogg",
    move: "piece-move.ogg",
    cover: "piece-capture.ogg",
    error: "game-error.ogg",
    win: "game-success.ogg",
    loss: "game-loss.ogg",
  });
  let selectedBoardScale = (() => {
    try {
      const stored = Number(localStorage.getItem(SCALE_STORAGE_KEY) || 1);
      return SCALE_STEPS.includes(stored) ? stored : 1;
    } catch (_) {
      return 1;
    }
  })();
  let lastHeardVersion = null;
  let motionSession=null, motionSequence=null, motionMoveNumber=null, activeMotion=null, motionTimer=0;
  const isAnimating=()=>Boolean(activeMotion && performance.now()-activeMotion.started<800);


  function makeNode(tag, className = "", text = "") {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== "") node.textContent = String(text);
    return node;
  }

  function safeText(value) {
    return String(value ?? "");
  }

  function boardScale() {
    return SCALE_STEPS.includes(selectedBoardScale) ? selectedBoardScale : 1;
  }

  function setBoardScaleGeometry(node, value) {
    const scale = SCALE_STEPS.includes(Number(value)) ? Number(value) : 1;
    node?.style.setProperty("--nf-board-max-width", String(980 * scale) + "px");
  }

  function applyBoardScale(value, controls) {
    const next = SCALE_STEPS.includes(Number(value)) ? Number(value) : 1;
    selectedBoardScale = next;
    try {
      localStorage.setItem(SCALE_STORAGE_KEY, String(next));
    } catch (_) {}
    const index = SCALE_STEPS.indexOf(next);
    const smaller = controls?.querySelector('[data-nf-board-scale-delta="-1"]');
    const larger = controls?.querySelector('[data-nf-board-scale-delta="1"]');
    const output = controls?.querySelector("[data-nf-board-scale-value]");
    if (smaller) smaller.disabled = index === 0;
    if (larger) larger.disabled = index === SCALE_STEPS.length - 1;
    if (output) output.textContent = String(Math.round(next * 100)) + "%";
    setBoardScaleGeometry(document.querySelector(".nf-stage-scaler"), next);
    document.querySelector(".nf-board-scroll")?.setAttribute("aria-label", "Nested Four board at " + String(Math.round(next * 100)) + " percent");
  }

  function appendBoardSizeOption({ grid, make }) {
    if (!grid || grid.querySelector(".nf-board-size-option")) return;
    const wrapper = make("div", "game-setting viewer-effect-option nf-board-size-option");
    const titleRow = make("div", "nf-option-title-row");
    const label = make("span", "game-setting-label", "Board size");
    label.id = "nf-board-size-label";
    const info = make("button", "inline-info", "i");
    info.type = "button";
    info.setAttribute("aria-label", "About Nested Four board size");
    info.setAttribute("aria-expanded", "false");
    const help = make("div", "compact-info-popover", "Viewer-local. 100% fits an ordinary laptop chat surface; larger choices enlarge when space allows and otherwise fit without inner scrolling.");
    help.hidden = true;
    info.addEventListener("click", () => {
      help.hidden = !help.hidden;
      info.setAttribute("aria-expanded", help.hidden ? "false" : "true");
    });
    titleRow.append(label, info);
    const controls = make("span", "nf-board-size-controls");
    const smaller = make("button", "", "-");
    const output = make("output", "", String(Math.round(boardScale() * 100)) + "%");
    const larger = make("button", "", "+");
    smaller.type = "button";
    larger.type = "button";
    smaller.dataset.nfBoardScaleDelta = "-1";
    larger.dataset.nfBoardScaleDelta = "1";
    output.dataset.nfBoardScaleValue = "";
    output.setAttribute("aria-label", "Nested Four board size");
    smaller.setAttribute("aria-label", "Make Nested Four board smaller");
    larger.setAttribute("aria-label", "Make Nested Four board larger");
    smaller.addEventListener("click", () => applyBoardScale(SCALE_STEPS[Math.max(0, SCALE_STEPS.indexOf(boardScale()) - 1)], controls));
    larger.addEventListener("click", () => applyBoardScale(SCALE_STEPS[Math.min(SCALE_STEPS.length - 1, SCALE_STEPS.indexOf(boardScale()) + 1)], controls));
    controls.append(smaller, output, larger);
    wrapper.append(titleRow, controls, help);
    grid.append(wrapper);
    applyBoardScale(boardScale(), controls);
  }

  function playCue(name, effectsEnabled, volume) {
    if (!effectsEnabled || !SOUND_FILES[name]) return;
    const audio = new Audio(new URL("../../assets/audio/built-in-games/" + SOUND_FILES[name], window.location.href).href);
    audio.volume = Math.max(0, Math.min(1, Number(volume ?? 1))) * 0.68;
    audio.play().catch(() => {});
  }

  function syncAuthoritativeCue(session, viewerUserId, effectsEnabled, volume) {
    const version = Number(session?.stateVersion || 0);
    if (lastHeardVersion === null) {
      lastHeardVersion = version;
      return;
    }
    if (version <= lastHeardVersion) return;
    lastHeardVersion = version;
    const state = session?.state || {};
    const action = state.lastAction || {};
    if (action.type === "select") playCue("select", effectsEnabled, volume);
    if (action.type === "move") playCue(action.covered ? "cover" : "move", effectsEnabled, volume);
    if (state.completed) {
      const won = Number(state.winnerUserId || 0) === viewerUserId;
      window.setTimeout(() => playCue(won ? "win" : "loss", effectsEnabled, volume), 140);
    }
  }

  function pieceNode(piece, playerIndex, extraClass = "") {
    const label = safeText(piece?.label || "");
    const node = makeNode("span", "nf-piece nf-size-" + label.toLowerCase() + " nf-player-" + String(Math.max(0, playerIndex)) + (extraClass ? " " + extraClass : ""));
    node.setAttribute("aria-hidden", "true");
    node.append(makeNode("span", "nf-piece-medallion", label));
    return node;
  }

  function render(api) {
    const { session, options, currentUserId, memberAvatar, memberName, performAction, optionCategory, setStatus, busy, rerender } = api;
    const state = session?.state || {};
    const viewerUserId = Number(currentUserId());
    const turnUserId = Number(session?.turnUserId || 0);
    const turnOrder = Array.isArray(state.turnOrder) ? state.turnOrder.map(Number) : [];
    const membersByUser = new Map((session?.members || []).map(member => [Number(member.userId), member]));
    const effectsEnabled = options?.effectsEnabled !== false;
    const masterVolume = Number(options?.masterVolume ?? 100) / 100;
    const showLegalMoves = optionCategory("showLegalMoves", true);
    const enabled=optionCategory("visualFxEnabled",true) && typeof Element!=="undefined" && typeof Element.prototype.animate==="function";
    const sequence=Number(state.actionSequence||0), moveNumber=Number(state.moveNumber||0), id=String(session?.publicId||"");
    if(id!==motionSession){motionSession=id;motionSequence=sequence;motionMoveNumber=moveNumber;activeMotion=null;}
    else if(sequence!==motionSequence){
      const previousMove=motionMoveNumber;motionSequence=sequence;motionMoveNumber=moveNumber;activeMotion=null;
      const a=state.lastAction;
      if(enabled && moveNumber===previousMove+1 && a?.type==="move"){
        const selector=a.sourceType==="board"?`.nf-cell[data-cell="${Number(a.sourceIndex)}"]`:`.nf-reserve-stack[data-user="${Number(a.userId)}"][data-stack="${Number(a.sourceIndex)}"]`;
        const origin=document.querySelector(selector)?.getBoundingClientRect();
        if(origin){
          const covered=a.covered?document.querySelector(`.nf-cell[data-cell="${Number(a.destination)}"] > .nf-piece`):null;
          activeMotion={started:performance.now(),destination:Number(a.destination),x:origin.x+origin.width/2,y:origin.y+origin.height/2,covered:covered?.cloneNode(true)||null};
        }
      }
    }
    if(!enabled)activeMotion=null;
    const motion=isAnimating()?activeMotion:null;
    clearTimeout(motionTimer);
    if(motion)motionTimer=setTimeout(rerender,Math.max(1,800-(performance.now()-motion.started))+20);
    const selected = state.selected || null;
    const legalSources = state.legalSources || {};
    const legalDestinations = new Set((state.legalDestinations || []).map(Number));
    syncAuthoritativeCue(session, viewerUserId, effectsEnabled, masterVolume);

    const shell = makeNode("section", "nf-shell");
    shell.dataset.visualFx = optionCategory("visualFxEnabled", true) ? "on" : "off";
    shell.setAttribute("aria-label", "Nested Four board");
    shell.addEventListener("keydown", event => {
      if (event.key !== "Escape" || !selected || Number(selected.userId || 0) !== viewerUserId) return;
      event.preventDefault();
      setStatus("That piece is committed and must be played.");
      playCue("error", effectsEnabled, masterVolume);
    });

    const statusTitle = state.completed ? (Number.isSafeInteger(Number(state.winnerUserId)) && Number(state.winnerUserId) !== 0 ? (memberName(Number(state.winnerUserId)) || "A player") + " wins" : state.winnerUserId === null ? "Game drawn" : "Game complete") : (state.phase === "lobby"
        ? "Starting layout preview"
        : (memberName(turnUserId) || "A player") + " to move");
    const statusDetail = state.completed ? "Game complete. No further moves are available." : (selected
      ? safeText(selected.piece?.label) + " is committed. Choose a highlighted destination."
      : "Selecting an exposed piece commits it for this turn.");
    const statusBand = makeNode("div", "nf-status-band");
    statusBand.append(makeNode("strong", "", statusTitle), makeNode("span", "", statusDetail));
    shell.append(statusBand);

    const playerRail = makeNode("div", "nf-player-rail");
    playerRail.setAttribute("aria-label", "Nested Four players");
    turnOrder.forEach((userId, playerIndex) => {
      const member = membersByUser.get(userId) || { userId, displayName: memberName(userId) };
      const frameworkPlayer = state?._framework?.players?.[String(userId)] || {};
      const connected = typeof frameworkPlayer.disconnected === "boolean"
        ? !frameworkPlayer.disconnected
        : null;
      const reserves = state.reserves?.[String(userId)] || [];
      const remaining = reserves.reduce((total, group) => total + Number(group.remaining || 0), 0);
      const plaque = makeNode("article", "nf-player-plaque nf-player-" + String(playerIndex));
      plaque.classList.toggle("is-current", userId === turnUserId && !state.completed);
      plaque.classList.toggle("is-viewer", userId === viewerUserId);
      plaque.classList.toggle("is-disconnected", connected === false);
      const copy = makeNode("div", "nf-player-copy");
      copy.append(
        makeNode("strong", "", safeText(member.displayName || memberName(userId) || "Player " + String(playerIndex + 1))),
        makeNode("span", "", (playerIndex === 0 ? "Coral" : "Ocean") + " pieces - " + String(remaining) + " reserved"),
        makeNode("small", connected === false ? "is-offline" : "", state.bots?.[String(userId)] ? "Practice bot" : connected === true ? "Connected" : connected === false ? "Disconnected" : "Connection unknown"),
      );
      plaque.append(memberAvatar(member, "nf-avatar"), copy);
      playerRail.append(plaque);
    });
    shell.append(playerRail);

    if (selected) {
      const selectedIndex = Math.max(0, turnOrder.indexOf(Number(selected.userId || 0)));
      const held = makeNode("div", "nf-held-piece");
      held.append(makeNode("span", "nf-held-label", "Committed"), pieceNode(selected.piece, selectedIndex, "is-held"), makeNode("strong", "", safeText(selected.piece?.label) + " must be played"));
      shell.append(held);
    }

    const scroll = makeNode("div", "nf-board-scroll");
    scroll.tabIndex = 0;
    scroll.setAttribute("aria-label", "Nested Four board at " + String(Math.round(boardScale() * 100)) + " percent");
    const scaler = makeNode("div", "nf-stage-scaler");
    setBoardScaleGeometry(scaler, boardScale());
    const layout = makeNode("div", "nf-game-layout");

    const renderReserve = (userId, playerIndex) => {
      const tray = makeNode("section", "nf-reserve-tray nf-player-" + String(playerIndex));
      tray.setAttribute("aria-label", (memberName(userId) || "Player") + " reserve stacks");
      tray.append(makeNode("strong", "nf-reserve-title", memberName(userId) || "Player " + String(playerIndex + 1)));
      const groups = state.reserves?.[String(userId)] || [];
      groups.forEach((group, stackIndex) => {
        const source = Number(userId) === viewerUserId
          ? legalSources["reserve:" + String(stackIndex)]
          : null;
        const button = makeNode("button", "nf-reserve-stack");
        button.type = "button";
        button.disabled = busy || Boolean(motion) || !source;
        button.dataset.user=String(userId);button.dataset.stack=String(stackIndex);
        button.classList.toggle("is-selectable", Boolean(source));
        button.classList.toggle("is-risky", Boolean(source) && Number(source.legalDestinationCount || 0) === 0);
        if (group.piece) button.append(pieceNode(group.piece, playerIndex));
        else button.append(makeNode("span", "nf-empty-reserve", "Empty"));
        button.append(makeNode("small", "", String(Number(group.remaining || 0)) + " nested"));
        const label = group.piece ? safeText(group.piece.label) + " exposed" : "Empty";
        button.setAttribute("aria-label", label + " in reserve stack " + String(stackIndex + 1) + (source ? ". Selecting commits this piece." : ""));
        button.addEventListener("click", async () => {
          if (!source || busy || isAnimating()) return;

          await performAction("select", { sourceType: "reserve", sourceIndex: stackIndex });
        });
        tray.append(button);
      });
      return tray;
    };

    const board = makeNode("div", "nf-board-frame");
    const grid = makeNode("div", "nf-board-grid");
    grid.setAttribute("role", "grid");
    grid.setAttribute("aria-label", "Four by four Nested Four board");
    for (let cell = 0; cell < 16; cell++) {
      const piece = state.board?.[String(cell)] || null;
      const ownerIndex = piece ? Math.max(0, turnOrder.indexOf(Number(piece.ownerUserId || 0))) : -1;
      const source = legalSources["board:" + String(cell)];
      const legalDestination = legalDestinations.has(cell);
      const button = makeNode("button", "nf-cell");
      button.type = "button";
      button.setAttribute("role", "gridcell");
      button.dataset.cell = String(cell);
      button.classList.toggle("nf-last-destination",state.lastAction?.type==="move" && Number(state.lastAction.destination)===cell);
      button.classList.toggle("has-piece", Boolean(piece));
      button.classList.toggle("is-source", Boolean(source));
      button.classList.toggle("is-legal", legalDestination);
      button.classList.toggle("is-legal-visible", legalDestination && showLegalMoves);
      // Keep the previously visible target until the covering piece arrives.
      if(motion?.destination===cell && motion.covered){
        const covered=motion.covered.cloneNode(true);covered.classList.add("nf-covered-during-motion");covered.setAttribute("aria-hidden","true");button.append(covered);
      }
      if (piece) button.append(pieceNode(piece, ownerIndex));
      const name = piece
        ? (memberName(Number(piece.ownerUserId)) || "Player") + " visible " + safeText(piece.label) + " piece"
        : "Empty cell";
      const actionLabel = source
        ? ". Select to commit this piece"
        : legalDestination
          ? ". Legal destination for committed " + safeText(selected?.piece?.label)
          : "";
      button.setAttribute("aria-label", "Row " + String(Math.floor(cell / 4) + 1) + ", column " + String((cell % 4) + 1) + ". " + name + actionLabel);
      button.disabled = busy || Boolean(motion) || (!source && !legalDestination);
      button.addEventListener("click", async () => {
        if (busy || isAnimating()) return;
        if (source) {

          await performAction("select", { sourceType: "board", sourceIndex: cell });
        } else if (legalDestination) {
          await performAction("move", { destination: cell });
        }
      });
      grid.append(button);
    }
    board.append(grid);
    if (turnOrder[0]) layout.append(renderReserve(Number(turnOrder[0]), 0));
    layout.append(board);
    if (turnOrder[1]) layout.append(renderReserve(Number(turnOrder[1]), 1));
    scaler.append(layout);
    scroll.append(scaler);
    shell.append(scroll);
    shell.append(makeNode("p", "nf-memory-note", "Covered pieces are hidden completely. Remember what each move reveals."));
    const live = makeNode("p", "nf-sr-status");
    live.setAttribute("aria-live", "polite");
    live.textContent = statusTitle + ". " + statusDetail;
    shell.append(live);
    if(motion)requestAnimationFrame(()=>{
      if(!shell.isConnected || activeMotion!==motion)return;
      const piece=shell.querySelector(`.nf-cell[data-cell="${motion.destination}"] > .nf-piece:not(.nf-covered-during-motion)`);
      if(!piece)return;const r=piece.getBoundingClientRect(),dx=motion.x-(r.x+r.width/2),dy=motion.y-(r.y+r.height/2);
      piece.classList.add("nf-traveling");
      const a=piece.animate([{transform:`translate(-50%, -50%) translate(${dx}px, ${dy}px) scale(1.08)`},{transform:"translate(-50%, -50%) translate(0px, 0px) scale(1)"}],{duration:800,easing:"ease-in-out",fill:"both"});
      a.currentTime=Math.max(0,performance.now()-motion.started);
    });
    return shell;
  }

  return Object.freeze({ render, appendBoardSizeOption, boardScale, isAnimating });
})();

window.CoreChatNestedFour = NestedFour;
