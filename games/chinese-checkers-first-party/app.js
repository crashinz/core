const ChineseCheckers = (() => {
  const SCALE_STEPS = Object.freeze([1, 1.25, 1.5, 1.75, 2]);
  const SCALE_STORAGE_KEY = "corechat.chinese-checkers.board-scale.v1";
  const ARMS = Object.freeze(["top", "upper-right", "lower-right", "bottom", "lower-left", "upper-left"]);
  const ROW_COUNTS = Object.freeze([1, 2, 3, 4, 13, 12, 11, 10, 9, 10, 11, 12, 13, 4, 3, 2, 1]);
  const PIECE_COLORS = Object.freeze(["coral", "amber", "jade", "azure", "violet", "ivory"]);
  const SOUND_FILES = Object.freeze({
    select: "ui-select.ogg",
    deselect: "ui-select.ogg",
    error: "game-error.ogg",
    move: "chinese-checkers-step.wav",
    jump: "chinese-checkers-jump.wav",
    turn: "ui-select.ogg",
    win: "game-success.ogg",
    loss: "game-loss.ogg",
  });
  const ARM_ANCHORS = Object.freeze({
    top: [400, 34],
    "upper-right": [716, 182],
    "lower-right": [716, 498],
    bottom: [400, 646],
    "lower-left": [84, 498],
    "upper-left": [84, 182],
  });
  let selectedHole = "";
  let lastHeardVersion = null;
  let activeMasterVolume = 100;
  let selectedBoardScale = (() => {
    try {
      const stored = Number(localStorage.getItem(SCALE_STORAGE_KEY) || 1);
      return SCALE_STEPS.includes(stored) ? stored : 1;
    } catch (_) {
      return 1;
    }
  })();

  function safeText(value) {
    return String(value ?? "");
  }

  function boardScale() {
    return SCALE_STEPS.includes(selectedBoardScale) ? selectedBoardScale : 1;
  }

  function setBoardScale(value) {
    const next = SCALE_STEPS.includes(Number(value)) ? Number(value) : 1;
    selectedBoardScale = next;
    try {
      localStorage.setItem(SCALE_STORAGE_KEY, String(next));
    } catch (_) {}
    return next;
  }

  function setBoardScaleGeometry(node, value) {
    const scale = SCALE_STEPS.includes(Number(value)) ? Number(value) : 1;
    node?.style.setProperty("--cc-board-scale", String(scale));
    node?.style.setProperty("--cc-board-max-width", `${800 * scale}px`);
    node?.style.setProperty("--cc-board-inverse-width", `${100 / scale}%`);
  }

  function applyBoardScale(value, controls) {
    const next = setBoardScale(value);
    const scaleIndex = SCALE_STEPS.indexOf(next);
    const smaller = controls?.querySelector('[data-cc-board-scale-delta="-1"]');
    const larger = controls?.querySelector('[data-cc-board-scale-delta="1"]');
    const output = controls?.querySelector("[data-cc-board-scale-value]");
    if (smaller) smaller.disabled = scaleIndex === 0;
    if (larger) larger.disabled = scaleIndex === SCALE_STEPS.length - 1;
    if (output) output.textContent = `${Math.round(next * 100)}%`;
    setBoardScaleGeometry(document.querySelector(".cc-stage-scaler"), next);
    document.querySelector(".cc-board-scroll")?.setAttribute(
      "aria-label",
      `Chinese Checkers board at ${Math.round(next * 100)} percent`,
    );
  }

  function changeBoardScale(delta, controls) {
    const currentIndex = SCALE_STEPS.indexOf(boardScale());
    const nextIndex = Math.max(0, Math.min(SCALE_STEPS.length - 1, currentIndex + delta));
    applyBoardScale(SCALE_STEPS[nextIndex], controls);
  }

  function appendBoardSizeOption({ grid, make }) {
    if (!grid || grid.querySelector(".cc-board-size-option")) return;
    const wrapper = make("div", "game-setting viewer-effect-option cc-board-size-option");
    const titleRow = make("div", "cc-option-title-row");
    const label = make("span", "game-setting-label", "Board size");
    label.id = "cc-board-size-label";
    const info = make("button", "inline-info cc-info-button", "i");
    info.type = "button";
    info.setAttribute("aria-label", "About Chinese Checkers board size");
    info.setAttribute("aria-expanded", "false");
    info.setAttribute("aria-controls", "cc-board-size-help");
    const help = make(
      "div",
      "compact-info-popover cc-option-help",
      "Viewer-local. 100% is the responsive default. Larger choices enlarge the same board up to 200% when space allows and otherwise fit it without inner scrolling.",
    );
    help.id = "cc-board-size-help";
    help.hidden = true;
    info.addEventListener("click", () => {
      help.hidden = !help.hidden;
      info.setAttribute("aria-expanded", help.hidden ? "false" : "true");
    });
    titleRow.append(label, info);
    const controls = make("span", "classic-board-scale-controls cc-board-size-choices");
    controls.setAttribute("role", "group");
    controls.setAttribute("aria-labelledby", label.id);
    const current = boardScale();
    const smaller = make("button", "", "−");
    const output = make("output", "", `${Math.round(current * 100)}%`);
    const larger = make("button", "", "+");
    smaller.type = "button";
    larger.type = "button";
    smaller.dataset.ccBoardScaleDelta = "-1";
    larger.dataset.ccBoardScaleDelta = "1";
    output.dataset.ccBoardScaleValue = "true";
    smaller.disabled = SCALE_STEPS.indexOf(current) === 0;
    larger.disabled = SCALE_STEPS.indexOf(current) === SCALE_STEPS.length - 1;
    smaller.setAttribute("aria-label", "Make Chinese Checkers board smaller");
    larger.setAttribute("aria-label", "Make Chinese Checkers board larger");
    output.setAttribute("aria-label", "Chinese Checkers board size");
    smaller.addEventListener("click", () => changeBoardScale(-1, controls));
    larger.addEventListener("click", () => changeBoardScale(1, controls));
    controls.append(smaller, output, larger);
    wrapper.append(titleRow, controls, help);
    grid.append(wrapper);
  }

  function createGeometry() {
    const holes = [];
    const byId = new Map();
    ROW_COUNTS.forEach((count, row) => {
      for (let index = 0; index < count; index += 1) {
        const id = `r${String(row).padStart(2, "0")}c${String(index).padStart(2, "0")}`;
        const x2 = -(count - 1) + (2 * index);
        const hole = { id, row, index, x2, x: 400 + (x2 * 22.0833333333), y: 34 + (row * 38.25) };
        holes.push(hole);
        byId.set(id, hole);
      }
    });
    return { holes, byId };
  }

  const BOARD_GEOMETRY = createGeometry();

  function rotatePoint(point, degrees) {
    const radians = degrees * Math.PI / 180;
    const dx = point[0] - 400;
    const dy = point[1] - 340;
    return [
      400 + (dx * Math.cos(radians)) - (dy * Math.sin(radians)),
      340 + (dx * Math.sin(radians)) + (dy * Math.cos(radians)),
    ];
  }

  function viewerRotation(state, viewerUserId) {
    const viewerArm = safeText(state.homeByUser?.[String(viewerUserId)] || "bottom");
    const index = Math.max(0, ARMS.indexOf(viewerArm));
    return (3 - index) * 60;
  }

  function armAfterRotation(arm, degrees) {
    const steps = Math.round(degrees / 60);
    const index = ARMS.indexOf(arm);
    return ARMS[(index + steps + 60) % 6] || arm;
  }

  function makeNode(tag, className = "", text = "") {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== "") node.textContent = text;
    return node;
  }

  function playCue(name, effectsEnabled = true) {
    if (!effectsEnabled || !SOUND_FILES[name]) return;
    const slot = SOUND_FILES[name];
    const url = new URL(`../../assets/audio/built-in-games/${slot}`, window.location.href);
    const player = new Audio(url.href);
    player.volume = Math.max(0, Math.min(1, activeMasterVolume / 100)) * (name === "error" ? 0.42 : 0.52);
    const recordTrace = (event, error = "") => {
      document.body.dataset.lastMediaTrace = JSON.stringify({
        event,
        slot,
        at: new Date().toISOString(),
        reason: `chinese-checkers-${name}`,
        soundOwner: "built-in-public",
        channel: "effects",
        ...(error ? { error } : {}),
      });
    };
    recordTrace("effect-play-requested");
    void player.play()
      .then(() => recordTrace("effect-playing"))
      .catch(error => recordTrace("effect-unavailable", String(error?.message || error || "Audio playback failed.")));
  }

  function syncAuthoritativeCue(session, viewerUserId, effectsEnabled) {
    const version = Number(session?.stateVersion || 0);
    if (lastHeardVersion === null) {
      lastHeardVersion = version;
      return;
    }
    if (version <= lastHeardVersion) return;
    lastHeardVersion = version;
    const state = session?.state || {};
    if (state.completed) {
      playCue(Number(state.winnerUserId || 0) === viewerUserId ? "win" : "loss", effectsEnabled);
      return;
    }
    const action = state.lastAction || {};
    if (action.type === "move") {
      const jumpCount = Number(action.jumpCount || 0);
      if (jumpCount > 0) {
        for (let segment = 0; segment < jumpCount; segment += 1) {
          window.setTimeout(() => playCue("jump", effectsEnabled), segment * 210);
        }
      } else {
        playCue("move", effectsEnabled);
      }
    }
    if (Number(session?.turnUserId || 0) === viewerUserId) {
      window.setTimeout(() => playCue("turn", effectsEnabled), 160);
    }
  }

  function render(api) {
    const {
      session,
      options,
      currentUserId,
      memberAvatar,
      memberName,
      performAction,
      rerender,
      optionCategory,
      setStatus,
      busy,
    } = api;
    const state = session?.state || {};
    const viewerUserId = Number(currentUserId());
    const turnUserId = Number(session?.turnUserId || 0);
    const effectsEnabled = options?.effectsEnabled !== false;
    activeMasterVolume = Math.max(0, Math.min(100, Number(options?.masterVolume ?? 100)));
    const showLegalMoves = optionCategory("showLegalMoves", true);
    const visualFxEnabled = optionCategory("visualFxEnabled", true);
    syncAuthoritativeCue(session, viewerUserId, effectsEnabled);

    const turnOrder = Array.isArray(state.turnOrder) ? state.turnOrder.map(Number) : [];
    const board = state.board || {};
    const legalMoves = state.legalMoves || {};
    if (state.completed || turnUserId !== viewerUserId || Number(board[selectedHole] || 0) !== viewerUserId) {
      selectedHole = "";
    }
    const selectedMoves = selectedHole ? (legalMoves[selectedHole] || {}) : {};
    const rotation = viewerRotation(state, viewerUserId);
    const theme = ["enamel", "wood", "glass"].includes(session?.presentation?.effectivePack)
      ? session.presentation.effectivePack
      : "enamel";
    const shell = makeNode("section", "cc-shell");
    shell.dataset.theme = theme;
    shell.dataset.visualFx = visualFxEnabled ? "on" : "off";
    shell.setAttribute("aria-label", "Chinese Checkers board");
    shell.addEventListener("keydown", event => {
      if (event.key !== "Escape" || !selectedHole) return;
      event.preventDefault();
      selectedHole = "";
      playCue("deselect", effectsEnabled);
      setStatus("Marble deselected.");
      rerender();
    });

    const status = makeNode("div", "cc-status-band");
    status.append(
      makeNode(
        "strong",
        "",
        state.completed
          ? state.terminalReason === "resignation"
            ? `${memberName(Number(state.resignedUserId || 0)) || "A player"} resigned`
            : `${memberName(Number(state.winnerUserId || 0)) || "A player"} completed the destination triangle`
          : state.phase === "lobby"
            ? "Starting layout preview"
            : `${memberName(turnUserId) || "A player"} to move`,
      ),
      makeNode(
        "span",
        "",
        state.completed
          ? state.terminalReason === "resignation"
            ? "Game ended by resignation."
            : "Game complete."
          : selectedHole
            ? `${Object.keys(selectedMoves).length} legal destination${Object.keys(selectedMoves).length === 1 ? "" : "s"}`
            : "Select one of your marbles to review its legal routes.",
      ),
    );
    shell.append(status);

    const playerOrbit = makeNode("div", "cc-player-orbit");
    playerOrbit.setAttribute("aria-label", "Players surrounding the Chinese Checkers board");

    const scroll = makeNode("div", "cc-board-scroll");
    scroll.tabIndex = 0;
    scroll.setAttribute("aria-label", `Chinese Checkers board at ${Math.round(boardScale() * 100)} percent`);
    const scaler = makeNode("div", "cc-stage-scaler");
    setBoardScaleGeometry(scaler, boardScale());
    const frame = makeNode("div", "cc-stage-frame");
    const stage = makeNode("div", "cc-stage");
    stage.dataset.theme = theme;
    const boardField = makeNode("div", "cc-board-field");
    boardField.setAttribute("aria-hidden", "true");
    stage.append(boardField);

    const routeSvg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    routeSvg.classList.add("cc-route-layer");
    routeSvg.setAttribute("viewBox", "0 0 800 680");
    routeSvg.setAttribute("aria-hidden", "true");
    const routeLine = document.createElementNS("http://www.w3.org/2000/svg", "polyline");
    routeLine.classList.add("cc-route-line");
    routeSvg.append(routeLine);
    stage.append(routeSvg);
    const transformedPoint = holeId => {
      const hole = BOARD_GEOMETRY.byId.get(holeId);
      return hole ? rotatePoint([hole.x, hole.y], rotation) : null;
    };
    const updateRoute = path => {
      const complete = selectedHole ? [selectedHole, ...(Array.isArray(path) ? path : [])] : [];
      const points = complete.map(transformedPoint).filter(Boolean);
      routeLine.setAttribute("points", points.map(point => `${point[0]},${point[1]}`).join(" "));
      routeSvg.classList.toggle("is-visible", points.length > 1);
    };

    const armOwner = new Map();
    for (const [userId, arm] of Object.entries(state.homeByUser || {})) armOwner.set(arm, Number(userId));
    const armHoles = new Map();
    for (const arm of ARMS) armHoles.set(arm, new Set());
    BOARD_GEOMETRY.holes.forEach(hole => {
      if (hole.row <= 3) armHoles.get("top").add(hole.id);
      if (hole.row >= 13) armHoles.get("bottom").add(hole.id);
      if (hole.row >= 4 && hole.row <= 7) {
        const take = 8 - hole.row;
        if (hole.index < take) armHoles.get("upper-left").add(hole.id);
        if (hole.index >= ROW_COUNTS[hole.row] - take) armHoles.get("upper-right").add(hole.id);
      }
      if (hole.row >= 9 && hole.row <= 12) {
        const take = hole.row - 8;
        if (hole.index < take) armHoles.get("lower-left").add(hole.id);
        if (hole.index >= ROW_COUNTS[hole.row] - take) armHoles.get("lower-right").add(hole.id);
      }
    });

    for (const hole of BOARD_GEOMETRY.holes) {
      const point = rotatePoint([hole.x, hole.y], rotation);
      const ownerUserId = Number(board[hole.id] || 0);
      const ownerIndex = Math.max(0, turnOrder.indexOf(ownerUserId));
      const legal = Boolean(selectedMoves[hole.id]);
      const button = makeNode("button", "cc-hole");
      button.type = "button";
      button.disabled = Boolean(state.completed) || turnUserId !== viewerUserId;
      button.dataset.hole = hole.id;
      button.style.setProperty("--cc-x", `${point[0] / 8}%`);
      button.style.setProperty("--cc-y", `${point[1] / 6.8}%`);
      button.classList.toggle("has-piece", ownerUserId > 0);
      button.classList.toggle("is-selected", hole.id === selectedHole);
      button.classList.toggle("is-legal", legal);
      button.classList.toggle("is-legal-visible", legal && showLegalMoves);
      button.classList.toggle("is-own-piece", ownerUserId === viewerUserId);
      if (ownerUserId > 0) button.classList.add(`cc-piece-${PIECE_COLORS[ownerIndex % PIECE_COLORS.length]}`);
      for (const arm of ARMS) {
        if (!armHoles.get(arm).has(hole.id)) continue;
        const zoneIndex = turnOrder.indexOf(armOwner.get(arm));
        if (zoneIndex >= 0) button.classList.add(`cc-zone-${PIECE_COLORS[zoneIndex % PIECE_COLORS.length]}`);
      }
      const route = selectedMoves[hole.id];
      const ownerLabel = ownerUserId > 0 ? `${memberName(ownerUserId)} marble` : "Empty hole";
      const legalLabel = legal
        ? `, legal ${route.kind === "jump" ? `${route.path.length}-jump` : "step"} destination`
        : "";
      button.setAttribute("aria-label", `${ownerLabel}${legalLabel}`);
      button.setAttribute("aria-pressed", hole.id === selectedHole ? "true" : "false");
      button.tabIndex = ownerUserId === viewerUserId || legal ? 0 : -1;
      button.addEventListener("mouseenter", () => {
        if (legal) updateRoute(route.path);
      });
      button.addEventListener("mouseleave", () => updateRoute([]));
      button.addEventListener("focus", () => {
        if (legal) updateRoute(route.path);
      });
      button.addEventListener("blur", () => updateRoute([]));
      button.addEventListener("click", async () => {
        if (busy || state.completed || state.phase !== "playing") return;
        if (ownerUserId === viewerUserId && turnUserId === viewerUserId) {
          if (selectedHole === hole.id) {
            selectedHole = "";
            playCue("deselect", effectsEnabled);
            setStatus("Marble deselected.");
          } else {
            selectedHole = hole.id;
            playCue("select", effectsEnabled);
            setStatus(`${Object.keys(legalMoves[hole.id] || {}).length} legal destinations available.`);
          }
          rerender();
          return;
        }
        if (selectedHole && legal) {
          const source = selectedHole;
          const succeeded = await performAction("move", { from: source, to: hole.id });
          if (succeeded) {
            selectedHole = "";
            rerender();
          }
          return;
        }
        if (selectedHole) {
          playCue("error", effectsEnabled);
          setStatus("That hole is not a legal destination for the selected marble.");
        }
      });
      stage.append(button);
    }

    const membersByUser = new Map((session?.members || []).map(member => [Number(member.userId), member]));
    const progress = state.homeProgress || {};
    for (const userId of turnOrder) {
      const arm = safeText(state.homeByUser?.[String(userId)] || "");
      if (!ARMS.includes(arm)) continue;
      const member = membersByUser.get(userId) || { userId, displayName: memberName(userId) };
      const plaque = makeNode("article", "cc-player-plaque");
      const displayArm = armAfterRotation(arm, rotation);
      plaque.dataset.arm = displayArm;
      const colorIndex = Math.max(0, turnOrder.indexOf(userId));
      plaque.classList.add(`cc-player-${PIECE_COLORS[colorIndex % PIECE_COLORS.length]}`);
      plaque.classList.toggle("is-current", userId === turnUserId && !state.completed);
      plaque.classList.toggle("is-viewer", userId === viewerUserId);
      const frameworkPlayer = state?._framework?.players?.[String(userId)] || {};
      const connected = typeof frameworkPlayer.disconnected === "boolean" ? !frameworkPlayer.disconnected : null;
      plaque.classList.toggle("is-disconnected", connected === false);
      const avatar = memberAvatar(member, "cc-avatar");
      const copy = makeNode("div", "cc-player-copy");
      copy.append(
        makeNode("strong", "", safeText(member.displayName || memberName(userId) || `Player ${colorIndex + 1}`)),
        makeNode("span", "", `${PIECE_COLORS[colorIndex % PIECE_COLORS.length]} marbles · ${Number(progress[String(userId)] || 0)}/10 home`),
        makeNode("small", connected === false ? "is-offline" : "", connected === null ? "Connection unknown" : connected ? "Connected" : "Disconnected"),
      );
      plaque.append(avatar, copy);
      const playerSlot = makeNode("div", `cc-player-slot cc-player-slot-${displayArm}`);
      playerSlot.append(plaque);
      stage.append(playerSlot);
    }

    const live = makeNode("p", "cc-sr-status");
    live.setAttribute("aria-live", "polite");
    live.textContent = state.completed
      ? "Game complete."
      : selectedHole
        ? `Marble selected with ${Object.keys(selectedMoves).length} legal destinations.`
        : `${memberName(turnUserId) || "Player"} to move.`;
    stage.append(live);
    frame.append(stage);
    scaler.append(frame);
    scroll.append(scaler);
    playerOrbit.append(scroll);
    shell.append(playerOrbit);
    return shell;
  }

  return Object.freeze({ render, appendBoardSizeOption, boardScale });
})();

window.CoreChatChineseCheckers = ChineseCheckers;
