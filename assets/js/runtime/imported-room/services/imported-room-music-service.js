/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      imported-room-music-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Imported Room Runtime
 *
 * Purpose:
 *      Owns imported room music-player rendering, modal lifecycle, and
 *      diagnostics.
 *
 * Build:
 *      000033
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000030
 * - Introduced ImportedRoomMusicService.
 * - Transferred imported room music-player ownership from room.js.
 * Build 000031
 * - Delegated page-level imported website player compatibility to
 *   ImportedRoomWebsitePlayerService.
 * Build 000033
 * - Delegated page-level imported website compatibility to
 *   ImportedRoomWebsiteCompatibilityService.
 ******************************************************************************/

/**
 * @file imported-room-music-service.js
 *
 * Defines the Imported Room Music Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Imported Room Music Service
//--------------------------------------------------

/**
 * Owns imported room music-player behavior.
 */
export class ImportedRoomMusicService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #websiteCompatibility;

    #context = null;

    #tracks = [];

    #activeTrack = null;

    #bindings = [];

    #modalOpen = false;

    #dragInitialized = false;

    #inlineCompatibilityApplied = false;

    #lastTrackCount = 0;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Imported Room Music Service.
     *
     * @param {ImportedRoomRuntime} runtime
     *        Owning Imported Room Runtime.
     *
     * @param {ImportedRoomWebsiteCompatibilityService} websiteCompatibility
     *        Runtime-owned imported website compatibility service.
     */
    constructor(runtime, websiteCompatibility) {

        this.#runtime = runtime;
        this.#websiteCompatibility = websiteCompatibility;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Initializes the service.
     */
    initialize() {

    }

    /**
     * Releases imported room music state and presentation.
     */
    destroy() {

        this.#clearBindings();
        this.closeModal();
        this.#tracks = [];
        this.#activeTrack = null;
        this.#context = null;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Imported Room Runtime.
     *
     * @returns {ImportedRoomRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures DOM shell callbacks and helpers.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#clearBindings();
        this.#context = context;
        this.#websiteCompatibility?.configure(context);
        this.#bindModalControls();
        this.#initDrag();

    }

    //--------------------------------------------------
    // Public Presentation
    //--------------------------------------------------

    /**
     * Renders the imported room music player.
     *
     * @param {Array<Object>} playlist
     */
    renderPlayer(playlist) {

        const player =
            this.#playerElement();

        const audio =
            this.#audioElement();

        const select =
            this.#selectElement();

        if (!player || !audio || !select) return;

        this.#tracks =
            Array.isArray(playlist) ? playlist.filter(track =>
                track && track.url
            ) : [];

        this.#lastTrackCount =
            this.#tracks.length;

        if (!this.#tracks.length) {

            player.hidden = true;
            audio.removeAttribute("src");
            select.innerHTML = "";

            if (this.#launchElement()) {

                this.#launchElement().hidden = true;

            }

            return;

        }

        select.innerHTML =
            this.#tracks.map((track, index) =>
                `<option value="${index}">${this.#esc(track.label || `Audio ${index + 1}`)}</option>`
            ).join("");

        select.hidden =
            this.#tracks.length < 2;

        select.onchange =
            () => this.setTrack(select.value);

        const launch =
            this.#launchElement();

        if (launch) {

            launch.onclick =
                () => {

                    if (this.#modalElement()?.classList.contains("open")) {

                        this.closeModal();
                        launch.textContent = "Launch YouTube Pop-Up";
                        return;

                    }

                    this.openModal(
                        this.#activeTrack
                    );

                    launch.textContent = "Close YouTube Pop-Up";

                };

        }

        const embed =
            this.#embedElement();

        if (embed) {

            embed.onclick =
                () => this.#toggleInlineEmbed();

        }

        this.setTrack(
            0
        );

        player.hidden =
            false;

    }

    /**
     * Selects the active imported room music track.
     *
     * @param {number|string} index
     */
    setTrack(index) {

        const audio =
            this.#audioElement();

        if (!audio || !this.#tracks.length) return;

        const track =
            this.#tracks[Number(index) || 0] || this.#tracks[0];

        this.#activeTrack =
            track;

        this.#hideInlineEmbed();

        const isLaunchTrack =
            track.type === "youtube" || Boolean(track.embed_url);

        audio.hidden =
            isLaunchTrack;

        const launch =
            this.#launchElement();

        if (launch) {

            launch.hidden =
                !isLaunchTrack;

            launch.textContent =
                "Launch YouTube Pop-Up";

        }

        const embed =
            this.#embedElement();

        if (embed) {

            embed.hidden =
                !isLaunchTrack;

            embed.textContent =
                "Launch YouTube Embed";

        }

        if (isLaunchTrack) {

            audio.pause();
            audio.removeAttribute("src");
            audio.load();
            this.#hideInlineEmbed();
            return;

        }

        audio.hidden = false;
        audio.src =
            this.#mediaUrl(
                track.url
            );
        audio.load();

    }

    /**
     * Returns imported room inline player HTML for domain compatibility.
     *
     * @param {Object} track
     *
     * @returns {string}
     */
    inlinePlayerHtml(track) {

        return this.#websiteCompatibility?.inlinePlayerHtml(
            track
        ) || "";

    }

    /**
     * Applies imported website music-player compatibility behavior.
     *
     * @param {Object} options
     */
    applyInlinePlayerCompatibility(options = {}) {

        this.#inlineCompatibilityApplied =
            Boolean(this.#websiteCompatibility?.applyCompatibility(
                options
            ));

    }

    /**
     * Opens the imported music modal.
     *
     * @param {Object} track
     */
    openModal(track) {

        const modal =
            this.#modalElement();

        const frameWrap =
            this.#frameWrapElement();

        if (!track || !modal || !frameWrap) return;

        const embedUrl =
            track.embed_url || "";

        const title =
            this.#modalTitleElement();

        if (title) {

            title.textContent =
                track.label || "Room Music";

        }

        if (embedUrl && this.#isHttpUrl(embedUrl)) {

            frameWrap.innerHTML =
                `<iframe src="${this.#esc(embedUrl)}" title="${this.#esc(track.label || "Room Music")}" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin"></iframe>`;

        } else {

            frameWrap.innerHTML =
                `<a class="btn btn-primary" href="${this.#esc(track.url)}" target="_blank" rel="noopener noreferrer">Open Music</a>`;

        }

        modal.classList.add(
            "open"
        );

        this.#modalOpen =
            true;

        this.setMinimized(
            false
        );

        this.clampModal();

    }

    /**
     * Closes the imported music modal.
     */
    closeModal() {

        this.#modalElement()?.classList.remove(
            "open"
        );

        const frameWrap =
            this.#frameWrapElement();

        if (frameWrap) {

            frameWrap.innerHTML = "";

        }

        const launch =
            this.#launchElement();

        if (launch) {

            launch.textContent =
                "Launch YouTube Pop-Up";

        }

        this.#modalOpen =
            false;

        this.setMinimized(
            false
        );

    }

    /**
     * Sets the imported music modal minimized state.
     *
     * @param {boolean} minimized
     */
    setMinimized(minimized) {

        const modalBox =
            this.#modalBoxElement();

        if (!modalBox) return;

        modalBox.classList.toggle(
            "minimized",
            Boolean(minimized)
        );

        const minimize =
            this.#modalMinimizeElement();

        if (minimize) {

            minimize.textContent =
                minimized ? "+" : "−";

            minimize.setAttribute(
                "aria-label",
                minimized ? "Restore" : "Minimize"
            );

        }

        this.#window()?.requestAnimationFrame?.(
            () => this.clampModal()
        );

    }

    /**
     * Keeps the imported music modal within the viewport.
     */
    clampModal() {

        const modalBox =
            this.#modalBoxElement();

        if (!modalBox || !this.#modalElement()?.classList.contains("open")) return;

        const rect =
            modalBox.getBoundingClientRect();

        const halfWidth =
            rect.width / 2;

        const halfHeight =
            rect.height / 2;

        const centerX =
            rect.left + halfWidth;

        const centerY =
            rect.top + halfHeight;

        const windowRef =
            this.#window();

        const x =
            Math.max(
                halfWidth + 8,
                Math.min(windowRef.innerWidth - halfWidth - 8, centerX)
            );

        const y =
            Math.max(
                halfHeight + 8,
                Math.min(windowRef.innerHeight - halfHeight - 8, centerY)
            );

        modalBox.style.setProperty(
            "--vp-music-left",
            `${x}px`
        );

        modalBox.style.setProperty(
            "--vp-music-top",
            `${y}px`
        );

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns imported room music diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ImportedRoomRuntime",

            build:
                "000033",

            configured:
                Boolean(this.#context),

            trackCount:
                this.#lastTrackCount,

            activeTrackType:
                this.#activeTrack?.type || null,

            modalOpen:
                this.#modalOpen,

            dragInitialized:
                this.#dragInitialized,

            inlineCompatibilityApplied:
                this.#inlineCompatibilityApplied,

            websiteCompatibility:
                this.#websiteCompatibility?.getDiagnostics() ?? null

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #window() {

        return this.#context?.window || globalThis.window || null;

    }

    #esc(value) {

        return this.#context?.esc?.(
            value
        ) || String(value ?? "");

    }

    #mediaUrl(path) {

        return this.#context?.mediaUrl?.(
            path
        ) || path;

    }

    #isHttpUrl(value) {

        return this.#context?.isHttpUrl?.(
            value
        ) || /^https?:\/\//i.test(String(value || ""));

    }

    #playerElement() {

        return this.#context?.getMusicPlayerElement?.() || null;

    }

    #selectElement() {

        return this.#context?.getMusicSelectElement?.() || null;

    }

    #audioElement() {

        return this.#context?.getMusicAudioElement?.() || null;

    }

    #launchElement() {

        return this.#context?.getMusicLaunchElement?.() || null;

    }

    #embedElement() {

        return this.#context?.getMusicEmbedElement?.() || null;

    }

    #youtubeElement() {

        return this.#context?.getMusicYoutubeElement?.() || null;

    }

    #modalElement() {

        return this.#context?.getMusicModalElement?.() || null;

    }

    #modalTitleElement() {

        return this.#context?.getMusicModalTitleElement?.() || null;

    }

    #modalCloseElement() {

        return this.#context?.getMusicModalCloseElement?.() || null;

    }

    #modalMinimizeElement() {

        return this.#context?.getMusicModalMinimizeElement?.() || null;

    }

    #modalDragHandleElement() {

        return this.#context?.getMusicDragHandleElement?.() || null;

    }

    #modalBoxElement() {

        return this.#context?.getMusicModalBoxElement?.() || null;

    }

    #frameWrapElement() {

        return this.#context?.getMusicFrameWrapElement?.() || null;

    }

    #bindModalControls() {

        const close =
            this.#modalCloseElement();

        if (close) {

            const closeHandler =
                () => this.closeModal();

            close.addEventListener(
                "click",
                closeHandler
            );

            this.#bindings.push(() =>
                close.removeEventListener(
                    "click",
                    closeHandler
                )
            );

        }

        const minimize =
            this.#modalMinimizeElement();

        if (minimize) {

            const minimizeHandler =
                () => this.setMinimized(
                    !this.#modalBoxElement()?.classList.contains("minimized")
                );

            minimize.addEventListener(
                "click",
                minimizeHandler
            );

            this.#bindings.push(() =>
                minimize.removeEventListener(
                    "click",
                    minimizeHandler
                )
            );

        }

    }

    #initDrag() {

        const dragHandle =
            this.#modalDragHandleElement();

        const modalBox =
            this.#modalBoxElement();

        if (!dragHandle || !modalBox) return;

        let dragging =
            false;

        let startX =
            0;

        let startY =
            0;

        let startCenterX =
            0;

        let startCenterY =
            0;

        const move =
            event => {

                if (!dragging) return;

                const rect =
                    modalBox.getBoundingClientRect();

                const halfWidth =
                    rect.width / 2;

                const halfHeight =
                    rect.height / 2;

                const windowRef =
                    this.#window();

                const x =
                    Math.max(
                        halfWidth + 8,
                        Math.min(windowRef.innerWidth - halfWidth - 8, startCenterX + event.clientX - startX)
                    );

                const y =
                    Math.max(
                        halfHeight + 8,
                        Math.min(windowRef.innerHeight - halfHeight - 8, startCenterY + event.clientY - startY)
                    );

                modalBox.style.setProperty(
                    "--vp-music-left",
                    `${x}px`
                );

                modalBox.style.setProperty(
                    "--vp-music-top",
                    `${y}px`
                );

            };

        const stop =
            event => {

                if (!dragging) return;

                dragging = false;
                modalBox.classList.remove(
                    "is-dragging"
                );
                dragHandle.releasePointerCapture?.(
                    event.pointerId
                );

            };

        const start =
            event => {

                if (event.button !== 0 || event.target.closest("button")) return;

                const rect =
                    modalBox.getBoundingClientRect();

                dragging = true;
                startX = event.clientX;
                startY = event.clientY;
                startCenterX = rect.left + rect.width / 2;
                startCenterY = rect.top + rect.height / 2;
                modalBox.classList.add(
                    "is-dragging"
                );
                dragHandle.setPointerCapture?.(
                    event.pointerId
                );
                event.preventDefault();

            };

        const resize =
            () => this.clampModal();

        dragHandle.addEventListener("pointerdown", start);
        dragHandle.addEventListener("pointermove", move);
        dragHandle.addEventListener("pointerup", stop);
        dragHandle.addEventListener("pointercancel", stop);
        this.#window()?.addEventListener("resize", resize);

        this.#bindings.push(() => dragHandle.removeEventListener("pointerdown", start));
        this.#bindings.push(() => dragHandle.removeEventListener("pointermove", move));
        this.#bindings.push(() => dragHandle.removeEventListener("pointerup", stop));
        this.#bindings.push(() => dragHandle.removeEventListener("pointercancel", stop));
        this.#bindings.push(() => this.#window()?.removeEventListener("resize", resize));

        this.#dragInitialized =
            true;

    }

    #clearBindings() {

        this.#bindings.splice(0).forEach(dispose =>
            dispose()
        );

        this.#dragInitialized =
            false;

    }

    #hideInlineEmbed() {

        const youtube =
            this.#youtubeElement();

        if (!youtube) return;

        youtube.hidden = true;
        youtube.innerHTML = "";

    }

    #toggleInlineEmbed() {

        if (!this.#activeTrack?.embed_url || !this.#youtubeElement()) {

            return;

        }

        const youtube =
            this.#youtubeElement();

        const embed =
            this.#embedElement();

        if (!youtube.hidden) {

            youtube.hidden = true;
            youtube.innerHTML = "";

            if (embed) {

                embed.textContent =
                    "Launch YouTube Embed";

            }

            return;

        }

        youtube.hidden = false;
        youtube.innerHTML =
            `<iframe src="${this.#esc(this.#activeTrack.embed_url)}"
                allow="autoplay; encrypted-media"
                allowfullscreen>
             </iframe>`;

        if (embed) {

            embed.textContent =
                "Hide YouTube Embed";

        }

    }

}

export default ImportedRoomMusicService;
