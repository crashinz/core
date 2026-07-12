/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      inner-tranquillity-compatibility.js
 *
 * Layer:
 *      Runtime Compatibility
 *
 * Owner:
 *      Imported Room Runtime
 *
 * Purpose:
 *      Owns inner-tranquillity.net imported website page-level player
 *      compatibility behavior.
 *
 * Build:
 *      000033
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000033
 * - Introduced InnerTranquillityCompatibility.
 * - Isolated inner-tranquillity.net player behavior from the generic imported
 *   website compatibility service.
 ******************************************************************************/

/**
 * @file inner-tranquillity-compatibility.js
 *
 * Defines inner-tranquillity.net imported website compatibility behavior.
 */

//--------------------------------------------------
// Inner Tranquillity Compatibility
//--------------------------------------------------

/**
 * Owns inner-tranquillity.net page-level player compatibility behavior.
 */
export class InnerTranquillityCompatibility {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #context = null;

    #lastApplied = false;

    #lastPlayerCount = 0;

    #lastCapabilityAvailable = false;

    #lastUnavailableReason = "";

    #playerPlaybackStates = new WeakMap();

    #playerElements = new Set();

    #playbackDiagnostics = [];

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Configures browser dependencies.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    /**
     * Releases compatibility state.
     */
    destroy() {

        this.prepareForRemoval("compatibility-destroy");
        this.#playerElements.forEach(player => {
            const state = this.#playerPlaybackStates.get(player);
            if (state?.originalPlay && player.play === state.guardedPlay) {
                player.play = state.originalPlay;
            }
        });
        this.#playerElements.clear();
        this.#playerPlaybackStates = new WeakMap();
        this.#context = null;
        this.#lastApplied = false;
        this.#lastPlayerCount = 0;
        this.#lastCapabilityAvailable = false;
        this.#lastUnavailableReason = "";

    }

    //--------------------------------------------------
    // Public Compatibility API
    //--------------------------------------------------

    /**
     * Returns whether this implementation applies to the options.
     *
     * @param {Object} options
     *
     * @returns {boolean}
     */
    matches(options = {}) {

        return Boolean(options.innerTranquillity);

    }

    /**
     * Applies inner-tranquillity.net player compatibility behavior.
     *
     * @param {Object} options
     *
     * @returns {boolean}
     */
    apply(options = {}) {

        this.#lastApplied =
            false;

        if (!this.matches(options)) return false;

        this.#lastCapabilityAvailable =
            Boolean(options.privatePlayerAvailable);
        this.#lastUnavailableReason =
            this.#lastCapabilityAvailable
                ? ""
                : String(options.privatePlayerUnavailableReason || "capability-unavailable");

        if (!this.#lastCapabilityAvailable) return false;

        const windowRef =
            this.#window();

        const jQueryRef =
            windowRef?.jQuery || windowRef?.$;

        if (!jQueryRef?.fn?.player) return false;

        this.#document()
            ?.querySelectorAll("audio.vp-page-player")
            .forEach(player => this.#guardPlayback(player));

        jQueryRef("audio.vp-page-player").player({
            audioWidth: 252,
            audioHeight: 30
        });

        this.#lastPlayerCount =
            this.#document()?.querySelectorAll("audio.vp-page-player").length || 0;

        this.#syncWrapperStyle(
            options
        );

        windowRef?.setTimeout?.(
            () => this.#syncMediaElementStyle(
                options
            ),
            100
        );

        this.#lastApplied =
            true;

        return true;

    }

    /**
     * Marks and pauses page-level players before intentional DOM replacement.
     *
     * @param {string} reason
     */
    prepareForRemoval(reason = "imported-layout-removal") {

        this.#playerElements.forEach(player => {
            const state = this.#playerPlaybackStates.get(player);
            if (!state) return;

            state.interruptions.set(
                state.playSequence,
                String(reason || "imported-layout-removal")
            );

            if (!player.paused) player.pause?.();
        });

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns compatibility diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ImportedRoomRuntime",

            build:
                "000033",

            domain:
                "inner-tranquillity.net",

            applied:
                this.#lastApplied,

            playerCount:
                this.#lastPlayerCount,

            capabilityAvailable:
                this.#lastCapabilityAvailable,

            unavailableReason:
                this.#lastUnavailableReason,

            playbackDiagnostics:
                this.#playbackDiagnostics.slice()

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #document() {

        return this.#context?.document || globalThis.document || null;

    }

    #window() {

        return this.#context?.window || globalThis.window || null;

    }

    #guardPlayback(player) {

        if (!player?.play || this.#playerPlaybackStates.has(player)) return;

        const state = {
            originalPlay: player.play,
            guardedPlay: null,
            playSequence: 0,
            interruptions: new Map()
        };

        state.guardedPlay = (...args) => {
            state.playSequence += 1;
            const playSequence = state.playSequence;
            const result = state.originalPlay.apply(player, args);

            result?.catch?.(error => {
                const interruptionReason = state.interruptions.get(playSequence) || null;
                state.interruptions.delete(playSequence);

                if (error?.name === "AbortError" && interruptionReason) {
                    this.#recordPlaybackDiagnostic({
                        event: "inline-player-play-aborted-intentionally",
                        playSequence,
                        reason: interruptionReason,
                        errorName: error.name,
                        message: error.message || String(error)
                    });
                    return;
                }

                const detail = {
                    event: "inline-player-play-rejected-unexpectedly",
                    playSequence,
                    reason: interruptionReason,
                    errorName: error?.name || null,
                    message: error?.message || String(error)
                };
                this.#recordPlaybackDiagnostic(detail);

                if (this.#context?.reportPlaybackError) {
                    this.#context.reportPlaybackError(error, detail);
                } else {
                    this.#window()?.console?.error?.(
                        "Imported website player playback failed.",
                        error
                    );
                }
            });

            return result;
        };

        player.play = state.guardedPlay;
        this.#playerPlaybackStates.set(player, state);
        this.#playerElements.add(player);

    }

    #recordPlaybackDiagnostic(entry) {

        this.#playbackDiagnostics.push({
            at: Date.now(),
            ...entry
        });

        if (this.#playbackDiagnostics.length > 80) {
            this.#playbackDiagnostics.splice(
                0,
                this.#playbackDiagnostics.length - 80
            );
        }

    }

    #syncWrapperStyle(options) {

        this.#document()?.querySelectorAll(".vp-import-player").forEach(wrapper => {

            wrapper.style.display = "flex";
            wrapper.style.alignItems = "center";
            wrapper.style.justifyContent = "center";

            if (options.backgroundTile && options.backgroundPath) {

                wrapper.style.background = "transparent";
                wrapper.style.border = "none";

            } else {

                const computedStyle =
                    options.stage
                        ? this.#window()
                            ?.getComputedStyle?.(
                                options.stage
                            )
                        : null;

                const background =
                    computedStyle
                        ?.getPropertyValue("--audio-player-bg")
                        ?.trim() || "";

                wrapper.style.background =
                    background || "";

            }

        });

    }

    #syncMediaElementStyle(options) {

        const documentRef =
            this.#document();

        if (!documentRef) return;

        documentRef
            .querySelectorAll(".vp-import-player .mejs__controls")
            .forEach(element => {

                element.style.display = "flex";
                element.style.alignItems = "center";

                if (options.backgroundPath) {

                    element.style.background = "transparent";

                }

            });

        documentRef
            .querySelectorAll(".vp-import-player .mejs__time-total")
            .forEach(element => {

                if (options.backgroundPath) {

                    element.style.background = "transparent";

                }

            });

        documentRef
            .querySelectorAll(".vp-import-player .mejs__horizontal-volume-total")
            .forEach(element => {

                if (options.backgroundPath) {

                    element.style.background = "transparent";

                }

            });

    }

}

export default InnerTranquillityCompatibility;
