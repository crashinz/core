//--------------------------------------------------
// Shared In-Game Webcam Overlay Service
//--------------------------------------------------

const STORAGE_VERSION = 1;
const CARD_MIN_WIDTH = 132;
const CARD_MAX_WIDTH = 224;
const KEYBOARD_STEP = 8;
const DEFAULT_POSITIONS = Object.freeze([
    Object.freeze({ x: 0.78, y: 0.72 }),
    Object.freeze({ x: 0.02, y: 0.72 }),
    Object.freeze({ x: 0.78, y: 0.38 }),
    Object.freeze({ x: 0.02, y: 0.38 }),
    Object.freeze({ x: 0.40, y: 0.72 }),
]);

function numericId(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, value));
}

function liveVideoTrack(stream) {
    return stream?.getVideoTracks?.().find(track => track.readyState === "live") || null;
}

function memberLabel(person, seat, participantId) {
    return String(
        person?.display_name || person?.name || seat?.display_name || seat?.name || `Player ${participantId}`
    ).trim().slice(0, 80);
}

/**
 * Presents canonical VoiceMediaService streams above the shared parent game
 * shell. It never captures media, creates peers, signals, or writes game state.
 */
export class GameWebcamOverlayService {
    #runtime;
    #context = null;
    #cards = new Map();
    #eligible = [];
    #positions = new Map();
    #lobbyCode = "";
    #topZ = 1;
    #drag = null;
    #resizeObserver = null;
    #boundResize = null;
    #boundVisibility = null;
    #boundPanelClick = null;
    #boundPanelKeydown = null;
    #boundToggle = null;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    configure(context = {}) {
        this.#detachListeners();
        this.#context = context;
        const toggle = this.#toggle();
        const panel = this.#panel();
        this.#boundToggle = () => this.#togglePanel();
        this.#boundPanelClick = event => this.#handlePanelClick(event);
        this.#boundPanelKeydown = event => {
            if (event.key !== "Escape" || panel?.hidden) return;
            event.preventDefault();
            panel.hidden = true;
            toggle?.setAttribute("aria-expanded", "false");
            if (toggle?.isConnected !== false) toggle?.focus?.({ preventScroll: true });
        };
        toggle?.addEventListener("click", this.#boundToggle);
        panel?.addEventListener("click", this.#boundPanelClick);
        panel?.addEventListener("keydown", this.#boundPanelKeydown);
        this.#boundResize = () => this.#syncGeometry();
        this.#boundVisibility = () => this.reconcile({ reason: "document-visibility" });
        context.window?.addEventListener?.("resize", this.#boundResize, { passive: true });
        context.document?.addEventListener?.("visibilitychange", this.#boundVisibility);
        const ResizeObserverOwner = context.window?.ResizeObserver || globalThis.ResizeObserver;
        if (ResizeObserverOwner && this.#frame()) {
            this.#resizeObserver = new ResizeObserverOwner(() => this.#syncGeometry());
            this.#resizeObserver.observe(this.#frame());
        }
        this.reconcile({ reason: "configured" });
    }

    destroy() {
        this.#detachListeners();
        this.#removeAllCards();
        this.#context = null;
    }

    hide(reason = "hidden") {
        const layer = this.#layer();
        const panel = this.#panel();
        if (layer) layer.hidden = true;
        if (panel) panel.hidden = true;
        this.#toggle()?.setAttribute("aria-expanded", "false");
        this.#context?.recordDiagnostic?.("gameWebcams", "overlay-hidden", { reason });
    }

    reconcile(detail = {}) {
        const game = this.#context?.getActiveGame?.();
        const frame = this.#frame();
        const layer = this.#layer();
        const toggle = this.#toggle();
        const actions = toggle?.closest?.(".game-presentation-actions");
        if (!game || !frame || !layer || frame.hidden || this.#context?.document?.hidden) {
            this.#eligible = [];
            this.#removeAllCards();
            if (toggle) toggle.hidden = true;
            this.hide(detail.reason || "inactive-game");
            return;
        }

        const lobbyCode = String(game.lobby_code || game.lobbyCode || "").trim();
        if (lobbyCode !== this.#lobbyCode) {
            this.#removeAllCards();
            this.#positions.clear();
            this.#lobbyCode = lobbyCode;
            this.#loadPositions();
        }

        const participants = this.#context?.getParticipants?.();
        const media = this.#context?.getMedia?.();
        const viewerPolicy = this.#context?.getViewerPolicy?.();
        const seats = this.#gameSeats(game);
        const eligible = [];
        const visible = [];
        for (const seat of seats) {
            let participantId = numericId(seat?.participant_id ?? seat?.participantId);
            let person = participantId ? participants?.get?.(participantId) : null;
            if (!person && numericId(seat?.user_id ?? seat?.userId)) {
                const userId = numericId(seat.user_id ?? seat.userId);
                person = [...(participants?.values?.() || [])].find(candidate => numericId(candidate?.user_id) === userId) || null;
                participantId = numericId(person?.id);
            }
            if (!participantId || !person) continue;
            const policy = viewerPolicy?.effectiveFor?.(person) || { receive: true };
            if (policy.receive === false) continue;
            const canonical = media?.getCanonicalWebcamPresentation?.(participantId);
            if (!canonical?.stream || !liveVideoTrack(canonical.stream)) continue;
            const identity = String(person.user_id || participantId);
            const stored = this.#position(identity, eligible.length);
            const item = { participantId, identity, person, seat, canonical, stored };
            eligible.push(item);
            if (stored.visible !== false) visible.push(item);
        }
        this.#eligible = eligible;

        const nextIds = new Set(visible.map(item => item.participantId));
        for (const [participantId] of this.#cards) {
            if (!nextIds.has(participantId)) this.#removeCard(participantId);
        }
        for (const item of visible) this.#upsertCard(item);

        const hasEligible = eligible.length > 0;
        toggle.hidden = !hasEligible;
        if (actions && hasEligible) actions.hidden = false;
        layer.hidden = visible.length === 0;
        if (!hasEligible) this.#panel().hidden = true;
        this.#syncGeometry();
        this.#renderPanel();
        this.#context?.recordDiagnostic?.("gameWebcams", "overlay-reconciled", {
            reason: detail.reason || "reconcile",
            lobbyCode,
            visibleCount: visible.length,
            canonicalTrackIds: eligible.map(item => item.canonical.trackId).filter(Boolean),
            createsCaptureOrPeer: false,
        });
    }

    getDiagnostics() {
        return Object.freeze({
            lobbyCode: this.#lobbyCode || null,
            eligibleCount: this.#eligible.length,
            renderedCount: this.#cards.size,
            canonicalTrackIds: this.#eligible.map(item => item.canonical.trackId).filter(Boolean),
            localStateOnly: true,
            captureOwner: "VoiceMediaService",
        });
    }

    #frame() { return this.#context?.getFrame?.() || null; }
    #wrap() { return this.#context?.getFrameWrap?.() || null; }
    #layer() { return this.#context?.getLayer?.() || null; }
    #panel() { return this.#context?.getPanel?.() || null; }
    #toggle() { return this.#context?.getToggle?.() || null; }

    #storageKey() {
        const userId = numericId(this.#context?.getCurrentUserId?.());
        return `corechat.game-webcams.v${STORAGE_VERSION}:${userId}:${this.#lobbyCode}`;
    }

    #loadPositions() {
        try {
            const parsed = JSON.parse(this.#context?.storage?.getItem?.(this.#storageKey()) || "{}");
            for (const [identity, value] of Object.entries(parsed?.members || {})) {
                if (!value || typeof value !== "object") continue;
                this.#positions.set(identity, {
                    x: clamp(Number(value.x) || 0, 0, 1),
                    y: clamp(Number(value.y) || 0, 0, 1),
                    visible: value.visible !== false,
                    z: Math.max(1, Number(value.z) || 1),
                });
                this.#topZ = Math.max(this.#topZ, Number(value.z) || 1);
            }
        } catch {
            this.#positions.clear();
        }
    }

    #savePositions() {
        if (!this.#lobbyCode) return;
        const members = Object.fromEntries(this.#positions);
        this.#context?.storage?.setItem?.(this.#storageKey(), JSON.stringify({ version: STORAGE_VERSION, members }));
    }

    #position(identity, index) {
        if (!this.#positions.has(identity)) {
            const initial = DEFAULT_POSITIONS[index % DEFAULT_POSITIONS.length];
            this.#positions.set(identity, { ...initial, visible: true, z: ++this.#topZ });
        }
        return this.#positions.get(identity);
    }

    #upsertCard(item) {
        const documentOwner = this.#context.document;
        let card = this.#cards.get(item.participantId);
        if (!card) {
            const root = documentOwner.createElement("section");
            root.className = "game-webcam-card";
            root.dataset.participantId = String(item.participantId);
            root.setAttribute("aria-label", `${memberLabel(item.person, item.seat, item.participantId)} webcam`);
            const handle = documentOwner.createElement("div");
            handle.className = "game-webcam-card-handle";
            handle.tabIndex = 0;
            handle.setAttribute("role", "group");
            handle.setAttribute("aria-label", "Move webcam. Use arrow keys, or drag with mouse or touch.");
            const name = documentOwner.createElement("strong");
            const hide = documentOwner.createElement("button");
            hide.type = "button";
            hide.className = "game-webcam-hide";
            hide.textContent = "Hide";
            hide.addEventListener("click", event => {
                event.stopPropagation();
                this.#setVisible(item.identity, false);
            });
            handle.append(name, hide);
            const video = documentOwner.createElement("video");
            video.autoplay = true;
            video.playsInline = true;
            video.muted = true;
            root.append(handle, video);
            root.addEventListener("pointerdown", () => this.#bringForward(item.identity, root));
            handle.addEventListener("pointerdown", event => this.#beginDrag(event, item.identity, root));
            handle.addEventListener("keydown", event => this.#handleKeyboardMove(event, item.identity, root));
            this.#layer().appendChild(root);
            card = { root, handle, name, video, identity: item.identity, track: null };
            this.#cards.set(item.participantId, card);
        }
        card.identity = item.identity;
        card.name.textContent = memberLabel(item.person, item.seat, item.participantId);
        card.video.muted = true;
        if (card.video.srcObject !== item.canonical.stream) {
            card.video.srcObject = item.canonical.stream;
            card.video.play?.().catch(() => {});
        }
        const track = liveVideoTrack(item.canonical.stream);
        if (card.track !== track) {
            card.track?.removeEventListener?.("ended", card.onTrackChange);
            card.track?.removeEventListener?.("mute", card.onTrackChange);
            card.track?.removeEventListener?.("unmute", card.onTrackChange);
            card.track = track;
            card.onTrackChange = () => this.reconcile({ reason: "canonical-track-change" });
            track?.addEventListener?.("ended", card.onTrackChange, { once: true });
            track?.addEventListener?.("mute", card.onTrackChange);
            track?.addEventListener?.("unmute", card.onTrackChange);
        }
        this.#applyPosition(card.root, item.stored);
    }

    #removeCard(participantId) {
        const card = this.#cards.get(participantId);
        if (!card) return;
        card.track?.removeEventListener?.("ended", card.onTrackChange);
        card.track?.removeEventListener?.("mute", card.onTrackChange);
        card.track?.removeEventListener?.("unmute", card.onTrackChange);
        card.video.srcObject = null;
        card.root.remove();
        this.#cards.delete(participantId);
    }

    #removeAllCards() {
        for (const participantId of [...this.#cards.keys()]) this.#removeCard(participantId);
    }

    #syncGeometry() {
        const wrap = this.#wrap();
        const frame = this.#frame();
        const layer = this.#layer();
        if (!wrap || !frame || !layer || layer.hidden) return;
        const wrapRect = wrap.getBoundingClientRect();
        const frameRect = frame.getBoundingClientRect();
        layer.style.left = `${Math.max(0, frameRect.left - wrapRect.left + wrap.scrollLeft)}px`;
        layer.style.top = `${Math.max(0, frameRect.top - wrapRect.top + wrap.scrollTop)}px`;
        layer.style.width = `${Math.max(0, frameRect.width)}px`;
        layer.style.height = `${Math.max(0, frameRect.height)}px`;
        for (const item of this.#eligible) {
            const card = this.#cards.get(item.participantId);
            if (card) this.#applyPosition(card.root, item.stored);
        }
    }

    #applyPosition(root, position) {
        const layer = this.#layer();
        if (!layer) return;
        const width = clamp(layer.clientWidth * 0.18, CARD_MIN_WIDTH, CARD_MAX_WIDTH);
        root.style.width = `${Math.round(width)}px`;
        const maxX = Math.max(0, layer.clientWidth - width);
        const estimatedHeight = root.offsetHeight || Math.round(width * 0.78);
        const maxY = Math.max(0, layer.clientHeight - estimatedHeight);
        root.style.left = `${Math.round(clamp(position.x, 0, 1) * maxX)}px`;
        root.style.top = `${Math.round(clamp(position.y, 0, 1) * maxY)}px`;
        root.style.zIndex = String(position.z || 1);
    }

    #bringForward(identity, root) {
        const position = this.#positions.get(identity);
        if (!position) return;
        position.z = ++this.#topZ;
        root.style.zIndex = String(position.z);
        this.#savePositions();
    }

    #beginDrag(event, identity, root) {
        if (event.button !== undefined && event.button !== 0) return;
        if (event.target?.closest?.("button")) return;
        event.preventDefault();
        this.#bringForward(identity, root);
        const start = { x: event.clientX, y: event.clientY, left: root.offsetLeft, top: root.offsetTop };
        const move = moveEvent => {
            const layer = this.#layer();
            const maxX = Math.max(0, layer.clientWidth - root.offsetWidth);
            const maxY = Math.max(0, layer.clientHeight - root.offsetHeight);
            const left = clamp(start.left + moveEvent.clientX - start.x, 0, maxX);
            const top = clamp(start.top + moveEvent.clientY - start.y, 0, maxY);
            root.style.left = `${Math.round(left)}px`;
            root.style.top = `${Math.round(top)}px`;
        };
        const finish = finishEvent => {
            root.releasePointerCapture?.(finishEvent.pointerId);
            root.removeEventListener("pointermove", move);
            root.removeEventListener("pointerup", finish);
            root.removeEventListener("pointercancel", finish);
            this.#storePixels(identity, root);
        };
        root.setPointerCapture?.(event.pointerId);
        root.addEventListener("pointermove", move);
        root.addEventListener("pointerup", finish);
        root.addEventListener("pointercancel", finish);
    }

    #handleKeyboardMove(event, identity, root) {
        const directions = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
        if (!directions[event.key]) return;
        event.preventDefault();
        const multiplier = event.shiftKey ? 4 : 1;
        const [dx, dy] = directions[event.key];
        const layer = this.#layer();
        const maxX = Math.max(0, layer.clientWidth - root.offsetWidth);
        const maxY = Math.max(0, layer.clientHeight - root.offsetHeight);
        root.style.left = `${clamp(root.offsetLeft + dx * KEYBOARD_STEP * multiplier, 0, maxX)}px`;
        root.style.top = `${clamp(root.offsetTop + dy * KEYBOARD_STEP * multiplier, 0, maxY)}px`;
        this.#bringForward(identity, root);
        this.#storePixels(identity, root);
    }

    #storePixels(identity, root) {
        const layer = this.#layer();
        const position = this.#positions.get(identity);
        if (!layer || !position) return;
        position.x = clamp(root.offsetLeft / Math.max(1, layer.clientWidth - root.offsetWidth), 0, 1);
        position.y = clamp(root.offsetTop / Math.max(1, layer.clientHeight - root.offsetHeight), 0, 1);
        this.#savePositions();
    }

    #setVisible(identity, visible) {
        const position = this.#positions.get(identity) || this.#position(identity, 0);
        position.visible = Boolean(visible);
        this.#savePositions();
        this.reconcile({ reason: visible ? "member-shown" : "member-hidden" });
    }

    #togglePanel() {
        const panel = this.#panel();
        const toggle = this.#toggle();
        if (!panel || !toggle) return;
        panel.hidden = !panel.hidden;
        toggle.setAttribute("aria-expanded", panel.hidden ? "false" : "true");
        if (!panel.hidden) panel.querySelector("button, input")?.focus?.();
    }

    #gameSeats(game) {
        const canonicalMembers = Array.isArray(game?.framework?.members)
            ? game.framework.members
            : [];
        const compatibilityPlayers = Array.isArray(game?.players) ? game.players : [];
        const seats = new Map();
        for (const seat of [...canonicalMembers, ...compatibilityPlayers]) {
            const role = String(seat?.role || "").toLowerCase();
            const membershipStatus = String(seat?.membershipStatus ?? seat?.membership_status ?? "active").toLowerCase();
            if (membershipStatus !== "active"
                || (role && !["master", "player", "spectator"].includes(role))) continue;
            const participantId = numericId(seat?.participant_id ?? seat?.participantId);
            const userId = numericId(seat?.user_id ?? seat?.userId);
            const key = participantId ? `participant:${participantId}` : (userId ? `user:${userId}` : "");
            if (key && !seats.has(key)) seats.set(key, seat);
        }
        return [...seats.values()];
    }

    #renderPanel() {
        const panel = this.#panel();
        if (!panel) return;
        const documentOwner = this.#context.document;
        panel.replaceChildren();
        panel.appendChild(documentOwner.createElement("h3")).textContent = "In-game webcams";
        const instructions = documentOwner.createElement("p");
        instructions.className = "minor";
        instructions.textContent = "Drag a webcam by its name, or focus its handle and use the arrow keys. These choices affect only this game on this device.";
        panel.appendChild(instructions);
        const allSeats = this.#gameSeats(this.#context?.getActiveGame?.());
        const participants = this.#context?.getParticipants?.();
        for (const seat of allSeats) {
            let participantId = numericId(seat?.participant_id ?? seat?.participantId);
            let person = participantId ? participants?.get?.(participantId) : null;
            if (!person) continue;
            const canonical = this.#context?.getMedia?.()?.getCanonicalWebcamPresentation?.(participantId);
            const policy = this.#context?.getViewerPolicy?.()?.effectiveFor?.(person) || { receive: true };
            if (!canonical?.stream || policy.receive === false) continue;
            const identity = String(person.user_id || participantId);
            const row = documentOwner.createElement("label");
            row.className = "game-webcams-member-toggle";
            const checkbox = documentOwner.createElement("input");
            checkbox.type = "checkbox";
            checkbox.checked = this.#position(identity, 0).visible !== false;
            checkbox.dataset.webcamIdentity = identity;
            row.append(checkbox, documentOwner.createTextNode(memberLabel(person, seat, participantId)));
            panel.appendChild(row);
        }
        const reset = documentOwner.createElement("button");
        reset.type = "button";
        reset.className = "btn";
        reset.dataset.webcamReset = "true";
        reset.textContent = "Reset webcam positions";
        panel.appendChild(reset);
    }

    #handlePanelClick(event) {
        const reset = event.target?.closest?.("[data-webcam-reset]");
        if (reset) {
            this.#positions.clear();
            this.#context?.storage?.removeItem?.(this.#storageKey());
            this.reconcile({ reason: "positions-reset" });
            return;
        }
        const checkbox = event.target?.closest?.("[data-webcam-identity]");
        if (checkbox) this.#setVisible(String(checkbox.dataset.webcamIdentity), checkbox.checked);
    }

    #detachListeners() {
        const toggle = this.#toggle();
        const panel = this.#panel();
        if (toggle && this.#boundToggle) toggle.removeEventListener("click", this.#boundToggle);
        if (panel && this.#boundPanelClick) panel.removeEventListener("click", this.#boundPanelClick);
        if (panel && this.#boundPanelKeydown) panel.removeEventListener("keydown", this.#boundPanelKeydown);
        this.#context?.window?.removeEventListener?.("resize", this.#boundResize);
        this.#context?.document?.removeEventListener?.("visibilitychange", this.#boundVisibility);
        this.#resizeObserver?.disconnect?.();
        this.#resizeObserver = null;
    }
}
