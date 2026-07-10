/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-renderer.js
 *
 * Layer:
 *      Renderer
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns avatar presentation.
 *
 *      AvatarRenderer is responsible for rendering avatar state to the
 *      user interface.
 *
 *      Rendering includes visual updates, DOM interaction, styling,
 *      animation hooks, and presentation logic.
 *
 *      AvatarRenderer does not perform runtime coordination,
 *      relationship management, ordering, or layout calculation.
 *
 * Build:
 *      000032
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000012
 * - Introduced Avatar Renderer.
 * - Established renderer ownership.
 * - No rendering behavior migrated.
 *
 * Build 000020
 * - Migrated avatar image source presentation from room.js.
 * - Migrated avatar layer creation and synchronization from room.js.
 * - Migrated avatar frame CSS synchronization from room.js.
 * - Migrated stage presence removal from room.js.
 * - Migrated webcam element presentation from room.js.
 * - Migrated aura layer presentation from room.js.
 * - Migrated typing and speech bubble presentation from room.js.
 *
 * Build 000025
 * - Migrated stage link icon presentation from room.js.
 * - Added renderer-owned stage link icon cache.
 *
 * Build 000032
 * - Added authoritative rendered avatar dimensions API.
 ******************************************************************************/

/**
 * @file avatar-renderer.js
 *
 * Defines the Avatar Renderer.
 */

//
// No imports required.
//

//--------------------------------------------------
// Constants
//--------------------------------------------------

const DEFAULT_AVATAR_FALLBACK_SIZE = 150;
const DEFAULT_AVATAR_VISUAL_MAX_SIZE = 200;

//--------------------------------------------------
// Avatar Renderer
//--------------------------------------------------

/**
 * Owns avatar presentation.
 *
 * AvatarRenderer is owned exclusively by AvatarRuntime.
 *
 * The renderer is an internal implementation detail and is not exposed
 * through the runtime's public API.
 */
export class AvatarRenderer {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Avatar Runtime.
     */
    #runtime;

    /**
     * Number of presentation operations performed.
     */
    #renderCount = 0;

    /**
     * Stage link icon elements keyed by relationship key.
     *
     * @type {Map<string, HTMLElement>}
     */
    #stageLinkIcons = new Map();

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Renderer.
     *
     * @param {AvatarRuntime} runtime
     *        Owning Avatar Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Methods
    //--------------------------------------------------

    /**
     * Initializes the renderer.
     */
    initialize() {

        this.#renderCount = 0;

    }

    /**
     * Renders the current avatar presentation.
     */
    render() {

        this.#renderCount += 1;

    }

    /**
     * Releases resources owned by the renderer.
     */
    destroy() {

        this.clearStageLinkIcons({
            removeImmediately:
                true
        });

        this.#renderCount = 0;

    }

    /**
     * Returns the owning Avatar Runtime.
     *
     * @returns {AvatarRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    /**
     * Updates an avatar image element source.
     *
     * @param {HTMLImageElement} image
     *        Avatar image element.
     *
     * @param {string} nextSource
     *        Source URL to render.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {void}
     */
    setAvatarImageSource(image, nextSource, options = {}) {

        if (!image || !nextSource) return;

        this.#renderCount += 1;

        if (!options.flip) {
            image.src = nextSource;
            return;
        }

        image.classList.remove("avatar-flipping");
        void image.offsetWidth;
        image.classList.add("avatar-flipping");

        const scheduler = options.window || window;

        scheduler.setTimeout(() => {
            image.src = nextSource;
        }, 145);

        scheduler.setTimeout(() => {
            image.classList.remove("avatar-flipping");
        }, 330);

    }

    /**
     * Synchronizes a participant's avatar stage layers.
     *
     * @param {Object} participant
     *        Participant being rendered.
     *
     * @param {Object} options
     *        Rendering dependencies and options.
     *
     * @returns {Object}
     *          Synchronized participant.
     */
    syncParticipant(participant, options = {}) {

        if (!participant) return participant;

        this.#renderCount += 1;

        const documentRef = options.document || document;
        const stage = options.stage;
        let image = participant.avatarEl;
        let label = participant.labelEl;
        let aura = participant.auraEl;

        if (!image) {
            image = documentRef.createElement("img");
            image.className = "avatar";
            image.draggable = false;
            image.dataset.participantId = participant.id;

            aura = documentRef.createElement("div");
            aura.className = "avatar-aura-layer";
            aura.dataset.participantId = participant.id;
            this.cleanupAuraLayer(aura, { document: documentRef });

            label = documentRef.createElement("div");
            label.className = "avatar-name";

            const layers =
                this.#runtime.order?.stageLayerOrder({
                    avatar: image,
                    aura,
                    label
                }) || [image, aura, label];

            layers.forEach(layer => stage?.appendChild(layer));

            participant.avatarEl = image;
            participant.auraEl = aura;
            participant.labelEl = label;

            if (options.own) options.makeDraggable?.(image);
            options.addContextListeners?.(image);
        }

        this.setAvatarImageSource(
            image,
            options.avatarSource,
            {
                flip:
                    Boolean(options.flipImage),

                window:
                    options.window
            }
        );

        image.classList.toggle("webcam", Boolean(options.webcam));
        image.classList.toggle("lap-avatar", Boolean(options.lapInitiator));
        image.classList.toggle("lap-primary-avatar", Boolean(options.lapTarget));
        label.textContent = options.displayName || "";

        if (participant.webcamVideoEl && options.webcamEnabled) {
            image.classList.add("avatar-hidden-behind-webcam");
        }

        return participant;

    }

    /**
     * Returns authoritative rendered dimensions for an avatar participant.
     *
     * @param {Object} participant
     *        Participant whose avatar dimensions are requested.
     *
     * @param {Object} options
     *        Dimension options.
     *
     * @returns {Object}
     *          Rendered width and height.
     */
    renderedAvatarDimensions(participant, options = {}) {

        const fallbackSize =
            Number(options.fallbackSize || DEFAULT_AVATAR_FALLBACK_SIZE);

        const visualMaxSize =
            Number(options.visualMaxSize || DEFAULT_AVATAR_VISUAL_MAX_SIZE);

        const lapMaxSize =
            Number(options.lapMaxSize || Math.round(fallbackSize * 0.5));

        const image =
            participant?.avatarEl || null;

        const naturalWidth =
            Number(image?.naturalWidth) ||
            Number(image?.videoWidth) ||
            fallbackSize;

        const naturalHeight =
            Number(image?.naturalHeight) ||
            Number(image?.videoHeight) ||
            fallbackSize;

        const maxSide =
            options.lapInitiator
                ? lapMaxSize
                : visualMaxSize;

        const scale =
            Math.min(
                1,
                maxSide / Math.max(naturalWidth, naturalHeight, 1)
            );

        return Object.freeze({

            width:
                Math.max(
                    1,
                    Math.round(naturalWidth * scale)
                ),

            height:
                Math.max(
                    1,
                    Math.round(naturalHeight * scale)
                )

        });

    }

    /**
     * Applies a calculated avatar frame to participant presentation layers.
     *
     * @param {Object} participant
     *        Participant to position.
     *
     * @param {Object} frame
     *        Calculated avatar frame.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {void}
     */
    applyParticipantFrame(participant, frame, options = {}) {

        const image = participant?.avatarEl;
        const label = participant?.labelEl;

        if (!image || !label || !frame) return;

        this.#renderCount += 1;

        const width = frame.width;
        const height = frame.height;
        const x = frame.x;
        const y = frame.y;

        this.#applyBox(image, {
            width,
            height,
            left: x,
            top: y
        });

        if (participant.auraEl) {
            this.#applyBox(participant.auraEl, {
                width,
                height,
                left: x,
                top: y
            });
        }

        if (participant.webcamVideoEl) {
            this.#applyBox(participant.webcamVideoEl, {
                width,
                height,
                left: x,
                top: y
            });
        }

        label.style.left = `${x + width / 2}px`;
        label.style.top = `${y + height + 7}px`;

        if (participant.typingEl) {
            participant.typingEl.style.left = `${x + width - 42}px`;
            participant.typingEl.style.top = `${y - 10}px`;
        }

        if (participant.speechEl) {
            this.positionSpeechBubble(participant, frame, options);
        }

    }

    /**
     * Removes participant presentation from the stage.
     *
     * @param {Object} participant
     *        Participant presentation to remove.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {void}
     */
    removeStagePresence(participant, options = {}) {

        if (!participant) return;

        this.#renderCount += 1;

        this.cleanupAuraLayer(participant.auraEl, options);

        participant.avatarEl?.remove();
        participant.auraEl?.remove();
        participant.labelEl?.remove();
        participant.typingEl?.remove();
        participant.speechEl?.remove();
        participant.webcamVideoEl?.remove();

        participant.avatarEl = null;
        participant.auraEl = null;
        participant.labelEl = null;
        participant.typingEl = null;
        participant.speechEl = null;
        participant.webcamVideoEl = null;

    }

    /**
     * Attaches webcam presentation for a participant.
     *
     * @param {Object} participant
     *        Participant receiving webcam presentation.
     *
     * @param {MediaStream} stream
     *        Media stream to render.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {HTMLVideoElement|null}
     *          Rendered video element.
     */
    attachWebcam(participant, stream, options = {}) {

        if (!participant || !stream) return null;

        this.#renderCount += 1;

        const documentRef = options.document || document;
        const stage = options.stage;
        let video = participant.webcamVideoEl;

        if (!video) {
            video = documentRef.createElement("video");
            video.className = "avatar avatar-webcam-video";
            video.dataset.participantId = participant.id;
            video.autoplay = true;
            video.playsInline = true;
            video.muted = Boolean(options.own);

            options.addContextListeners?.(video);
            if (options.own) options.makeDraggable?.(video);

            stage?.insertBefore(
                video,
                this.#runtime.order?.webcamInsertBefore(participant) || null
            );

            participant.webcamVideoEl = video;
        }

        if (video.srcObject !== stream) video.srcObject = stream;
        video.muted = Boolean(options.own);
        video.classList.toggle("avatar-webcam-self", Boolean(options.own));
        video.play?.().catch(() => {});
        participant.avatarEl?.classList.add("avatar-hidden-behind-webcam");

        return video;

    }

    /**
     * Detaches webcam presentation for a participant.
     *
     * @param {Object} participant
     *        Participant losing webcam presentation.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {void}
     */
    detachWebcam(participant, options = {}) {

        if (!participant) return;

        this.#renderCount += 1;

        const video = participant.webcamVideoEl;
        participant.webcamVideoEl = null;
        participant.avatarEl?.classList.remove("avatar-hidden-behind-webcam");

        if (!video) return;

        video.srcObject = null;

        if (!options.flip) {
            video.remove();
            return;
        }

        video.classList.remove("avatar-flipping");
        void video.offsetWidth;
        video.classList.add("avatar-flipping");

        const scheduler = options.window || window;
        scheduler.setTimeout(() => video.remove(), 330);

    }

    /**
     * Synchronizes linked presentation class for a participant.
     *
     * @param {Object} participant
     *        Participant to synchronize.
     *
     * @param {boolean} linked
     *        Whether the participant is linked.
     *
     * @returns {void}
     */
    syncLinkedClass(participant, linked) {

        if (!participant?.avatarEl) return;

        this.#renderCount += 1;
        participant.avatarEl.classList.toggle("linked", Boolean(linked));

    }

    /**
     * Synchronizes relationship link icon presentation on the avatar stage.
     *
     * @param {Array} linkedPairs
     *        Relationship pairs in `[key, first, second]` form.
     *
     * @param {Object} options
     *        Rendering dependencies.
     *
     * @returns {void}
     */
    syncStageLinkIcons(linkedPairs = [], options = {}) {

        const stage =
            options.stage;

        if (!stage) return;

        this.#renderCount += 1;

        const documentRef =
            options.document || stage.ownerDocument || document;

        const active =
            new Set();

        const pairs =
            Array.isArray(linkedPairs)
                ? linkedPairs
                : [];

        pairs.forEach(pair => {

            const [
                key,
                first,
                second
            ] = pair || [];

            if (!key || !first?.avatarEl || !second?.avatarEl) {
                return;
            }

            if (options.linkModeForPair?.(first, second) === "lap") {
                return;
            }

            const iconName =
                options.linkIconNameForStage?.(key) || "";

            if (!iconName) {
                return;
            }

            const relationshipKey =
                String(key);

            active.add(relationshipKey);

            const element =
                this.#stageLinkIconElement(
                    relationshipKey,
                    {
                        stage,
                        document:
                            documentRef
                    }
                );

            element.classList.remove("removing");

            const image =
                element.querySelector("img");

            const iconUrl =
                this.#relationshipIconUrl(
                    iconName,
                    options
                );

            if (image && image.getAttribute("src") !== iconUrl) {
                image.src = iconUrl;
            }

            this.#positionStageLinkIcon(
                element,
                first.avatarEl,
                second.avatarEl
            );

        });

        Array.from(this.#stageLinkIcons.entries()).forEach(([key, element]) => {

            if (active.has(key)) {
                return;
            }

            this.#removeStageLinkIcon(
                key,
                element,
                options
            );

        });

    }

    /**
     * Clears all renderer-owned stage link icons.
     *
     * @param {Object} options
     *
     * @returns {void}
     */
    clearStageLinkIcons(options = {}) {

        Array.from(this.#stageLinkIcons.entries()).forEach(([key, element]) => {
            this.#removeStageLinkIcon(
                key,
                element,
                options
            );
        });

    }

    /**
     * Cleans an aura presentation layer.
     *
     * @param {HTMLElement} layer
     *        Aura layer to clean.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {HTMLElement|null}
     *          Effect layer.
     */
    cleanupAuraLayer(layer, options = {}) {

        if (!layer) return null;

        this.#renderCount += 1;

        const cleanup = layer._auraCleanup;
        layer._auraCleanup = null;

        if (cleanup) {
            try {
                cleanup();
            } catch {}
        }

        layer.replaceChildren();
        layer.dataset.auraKey = "";
        layer._auraMounted = false;
        layer._auraLoadingKey = "";

        const documentRef = options.document || layer.ownerDocument || document;
        const effect = documentRef.createElement("div");
        effect.className = "avatar-aura-effect";
        layer.appendChild(effect);

        return effect;

    }

    /**
     * Applies an aura renderer to a presentation layer.
     *
     * @param {HTMLElement} layer
     *        Aura layer.
     *
     * @param {string} key
     *        Aura key.
     *
     * @param {Object} options
     *        Aura rendering dependencies.
     *
     * @returns {Promise<void>}
     */
    async applyAuraToLayer(layer, key, options = {}) {

        if (!layer) return;

        this.#renderCount += 1;

        const auraKey = key || "";

        if (
            layer.dataset.auraKey === auraKey &&
            (
                auraKey === "" ||
                layer._auraMounted ||
                layer._auraLoadingKey === auraKey
            )
        ) {
            return;
        }

        const effectLayer = this.cleanupAuraLayer(layer, options);
        layer.dataset.auraKey = auraKey;

        if (!auraKey) return;

        layer._auraLoadingKey = auraKey;

        const aura =
            options.auraByKey?.(auraKey) ||
            {
                key: auraKey,
                label: auraKey,
                script: `/assets/auras/${encodeURIComponent(auraKey)}.js`
            };

        try {
            const module = await options.loadAuraModule(aura);
            if (layer.dataset.auraKey !== auraKey || !effectLayer?.isConnected) return;

            const runtime = module.render(effectLayer);
            layer._auraCleanup = () => this.#cleanupAuraRuntime(runtime);
            layer._auraMounted = true;
            layer._auraLoadingKey = "";
        } catch (error) {
            if (layer.dataset.auraKey === auraKey) {
                this.cleanupAuraLayer(layer, options);
            }
            options.onError?.(error);
        }

    }

    /**
     * Applies participant aura presentation.
     *
     * @param {Object} participant
     *        Participant to synchronize.
     *
     * @param {Object} options
     *        Aura rendering dependencies.
     *
     * @returns {Promise<void>}
     */
    async applyParticipantAura(participant, options = {}) {

        if (!participant?.auraEl) return;

        await this.applyAuraToLayer(
            participant.auraEl,
            participant.aura_effect || "",
            options
        );

    }

    /**
     * Synchronizes typing bubble presentation.
     *
     * @param {Object} participant
     *        Participant typing state belongs to.
     *
     * @param {boolean} active
     *        Whether typing is active.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {HTMLElement|null}
     *          Typing bubble element.
     */
    syncTyping(participant, active, options = {}) {

        if (!participant) return null;

        this.#renderCount += 1;

        if (!active) {
            participant.typingEl?.remove();
            participant.typingEl = null;
            return null;
        }

        if (!participant.typingEl) {
            const documentRef = options.document || document;
            const element = documentRef.createElement("div");
            element.className = "typing-bubble";
            element.innerHTML = "<span></span><span></span><span></span>";
            options.stage?.appendChild(element);
            participant.typingEl = element;
        }

        return participant.typingEl;

    }

    /**
     * Ensures a participant has a speech bubble element.
     *
     * @param {Object} participant
     *        Participant receiving speech presentation.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {HTMLElement|null}
     *          Speech bubble.
     */
    ensureSpeechBubble(participant, options = {}) {

        if (!participant) return null;

        this.#renderCount += 1;

        if (!participant.speechEl) {
            const documentRef = options.document || document;
            const element = documentRef.createElement("div");
            element.className = "chat-bubble";
            options.stage?.appendChild(element);
            participant.speechEl = element;
        }

        return participant.speechEl;

    }

    /**
     * Prepares a speech bubble for new content.
     *
     * @param {Object} participant
     *        Participant speaking.
     *
     * @param {Object} options
     *        Speech presentation options.
     *
     * @returns {void}
     */
    prepareSpeechBubble(participant, options = {}) {

        if (!participant?.speechEl) return;

        this.#renderCount += 1;

        participant.speechEl.classList.remove("show");
        participant.speechEl.classList.toggle("chat-bubble-gif", Boolean(options.gif));
        participant.speechEl.classList.toggle("chat-bubble-gesture", Boolean(options.gesture));
        participant.speechEl.onclick = null;

    }

    /**
     * Renders image content into a speech bubble.
     *
     * @param {Object} participant
     *        Participant speaking.
     *
     * @param {Object} options
     *        Image rendering options.
     *
     * @returns {HTMLImageElement|null}
     *          Rendered image.
     */
    renderSpeechImage(participant, options = {}) {

        if (!participant?.speechEl) return null;

        this.#renderCount += 1;

        const documentRef = options.document || document;
        const image = documentRef.createElement("img");
        image.src = options.src || "";
        image.alt = options.alt || "";

        if (options.gesture) {
            const caption = documentRef.createElement("div");
            caption.className = "chat-bubble-gesture-text";
            caption.textContent = options.caption || "";
            participant.speechEl.replaceChildren(image, caption);
            participant.speechEl.onclick = options.onclick || null;
        } else {
            participant.speechEl.replaceChildren(image);
        }

        return image;

    }

    /**
     * Renders text content into a speech bubble.
     *
     * @param {Object} participant
     *        Participant speaking.
     *
     * @param {string} text
     *        Text to render.
     *
     * @returns {void}
     */
    renderSpeechText(participant, text) {

        if (!participant?.speechEl) return;

        this.#renderCount += 1;
        participant.speechEl.textContent =
            text.length > 180 ? `${text.slice(0, 177)}...` : text;

    }

    /**
     * Shows a speech bubble.
     *
     * @param {Object} participant
     *        Participant speaking.
     *
     * @returns {void}
     */
    showSpeechBubble(participant) {

        if (!participant?.speechEl) return;

        this.#renderCount += 1;
        participant.speechEl.classList.add("show");

    }

    /**
     * Clears speech bubble presentation.
     *
     * @param {Object} participant
     *        Participant to clear.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {void}
     */
    clearSpeechBubble(participant, options = {}) {

        if (!participant?.speechEl) return;

        this.#renderCount += 1;

        participant.speechEl.classList.remove("show");

        const scheduler = options.window || window;
        scheduler.setTimeout(() => {
            if (participant.speechEl) participant.speechEl.remove();
            participant.speechEl = null;
        }, 220);

    }

    /**
     * Positions a speech bubble relative to an avatar frame.
     *
     * @param {Object} participant
     *        Participant speaking.
     *
     * @param {Object} frame
     *        Avatar frame.
     *
     * @param {Object} options
     *        Rendering options.
     *
     * @returns {void}
     */
    positionSpeechBubble(participant, frame, options = {}) {

        if (!participant?.speechEl || !frame) return;

        this.#renderCount += 1;

        const stage = options.stage;
        const roomWidth = stage?.clientWidth || 0;
        const roomHeight = stage?.clientHeight || 0;
        const bubbleWidth = participant.speechEl.offsetWidth || 200;
        const bubbleHeight = participant.speechEl.offsetHeight || 52;
        const placeRight = frame.x + frame.width + bubbleWidth + 16 < roomWidth;
        const placeBelow = frame.y + frame.height + bubbleHeight + 18 < roomHeight;
        const clampX = value => Math.max(8, Math.min(value, roomWidth - bubbleWidth - 8));
        const clampY = value => Math.max(8, Math.min(value, roomHeight - bubbleHeight - 8));

        participant.speechEl.classList.remove("br", "bl", "tr", "tl");

        if (placeBelow) {
            participant.speechEl.style.top = `${clampY(frame.y + frame.height + 10)}px`;

            if (placeRight) {
                participant.speechEl.style.left = `${clampX(frame.x + frame.width - 28)}px`;
                participant.speechEl.classList.add("br");
            } else {
                participant.speechEl.style.left = `${clampX(frame.x - bubbleWidth + 28)}px`;
                participant.speechEl.classList.add("bl");
            }

            return;
        }

        participant.speechEl.style.top = `${clampY(frame.y - bubbleHeight - 10)}px`;

        if (placeRight) {
            participant.speechEl.style.left = `${clampX(frame.x + frame.width - 28)}px`;
            participant.speechEl.classList.add("tr");
        } else {
            participant.speechEl.style.left = `${clampX(frame.x - bubbleWidth + 28)}px`;
            participant.speechEl.classList.add("tl");
        }

    }

    /**
     * Returns renderer diagnostic information.
     *
     * @returns {Object}
     *          Renderer diagnostics.
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "AvatarRenderer",

            build:
                "000032",

            renderCount:
                this.#renderCount,

            stageLinkIconCount:
                this.#stageLinkIcons.size

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Applies a rectangular CSS box to an element.
     *
     * @param {HTMLElement} element
     *        Element to update.
     *
     * @param {Object} box
     *        Box coordinates.
     *
     * @returns {void}
     */
    #applyBox(element, box) {

        element.style.width = `${box.width}px`;
        element.style.height = `${box.height}px`;
        element.style.left = `${box.left}px`;
        element.style.top = `${box.top}px`;

    }

    /**
     * Returns an existing or newly-created stage link icon element.
     *
     * @param {string} key
     * @param {Object} options
     *
     * @returns {HTMLElement}
     */
    #stageLinkIconElement(key, options = {}) {

        let element =
            this.#stageLinkIcons.get(key);

        if (element) {
            return element;
        }

        const documentRef =
            options.document || document;

        element =
            documentRef.createElement("div");
        element.className = "stage-link-icon";
        element.innerHTML = "<img alt=\"\">";

        options.stage?.appendChild(element);

        this.#stageLinkIcons.set(
            key,
            element
        );

        return element;

    }

    /**
     * Positions a stage link icon between two avatar elements.
     *
     * @param {HTMLElement} element
     * @param {HTMLElement} firstAvatar
     * @param {HTMLElement} secondAvatar
     *
     * @returns {void}
     */
    #positionStageLinkIcon(element, firstAvatar, secondAvatar) {

        const firstX =
            firstAvatar.offsetLeft + firstAvatar.offsetWidth / 2;

        const firstY =
            firstAvatar.offsetTop + firstAvatar.offsetHeight / 2;

        const secondX =
            secondAvatar.offsetLeft + secondAvatar.offsetWidth / 2;

        const secondY =
            secondAvatar.offsetTop + secondAvatar.offsetHeight / 2;

        const size =
            element.offsetWidth || 44;

        element.style.left =
            `${(firstX + secondX) / 2 - size / 2}px`;

        element.style.top =
            `${(firstY + secondY) / 2 - size / 2}px`;

    }

    /**
     * Removes a stage link icon from renderer ownership.
     *
     * @param {string} key
     * @param {HTMLElement} element
     * @param {Object} options
     *
     * @returns {void}
     */
    #removeStageLinkIcon(key, element, options = {}) {

        this.#stageLinkIcons.delete(key);

        if (!element) {
            return;
        }

        if (options.removeImmediately) {
            element.remove();
            return;
        }

        element.classList.add("removing");

        const scheduler =
            options.window || window;

        scheduler.setTimeout(
            () => element.remove(),
            240
        );

    }

    /**
     * Resolves a relationship icon image URL.
     *
     * @param {string} iconName
     * @param {Object} options
     *
     * @returns {string}
     */
    #relationshipIconUrl(iconName = "plus", options = {}) {

        const clean =
            String(iconName || "plus").replace(/[^a-z0-9-]/g, "") || "plus";

        const catalog =
            Array.isArray(options.linkIconCatalog)
                ? options.linkIconCatalog
                : [];

        const item =
            catalog.find(icon => icon.icon_name === clean);

        if (item?.file_path) {
            return options.appUrl?.(item.file_path) || item.file_path;
        }

        return options.appUrl?.(`/assets/images/cs-icons/${clean}.png`) ||
            `/assets/images/cs-icons/${clean}.png`;

    }

    /**
     * Cleans up an aura runtime instance.
     *
     * @param {*} runtime
     *        Aura runtime instance.
     *
     * @returns {void}
     */
    #cleanupAuraRuntime(runtime) {

        if (typeof runtime === "number") {
            clearInterval(runtime);
            clearTimeout(runtime);
            return;
        }

        if (typeof runtime === "function") {
            runtime();
            return;
        }

        runtime?.destroy?.();
        runtime?.cleanup?.();

    }

}

export default AvatarRenderer;
