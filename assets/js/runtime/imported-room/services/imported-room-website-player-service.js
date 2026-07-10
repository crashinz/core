/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      imported-room-website-player-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Imported Room Runtime
 *
 * Purpose:
 *      Owns imported website page-level music-player compatibility behavior.
 *
 * Build:
 *      000031
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000031
 * - Introduced ImportedRoomWebsitePlayerService.
 * - Transferred page-level imported website player compatibility ownership
 *   from ImportedRoomMusicService.
 ******************************************************************************/

/**
 * @file imported-room-website-player-service.js
 *
 * Defines imported website page-level music-player compatibility behavior.
 */

//
// No imports required.
//

//--------------------------------------------------
// Imported Room Website Player Service
//--------------------------------------------------

/**
 * Owns imported website page-level music-player compatibility.
 */
export class ImportedRoomWebsitePlayerService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #context = null;

    #lastCompatibilityApplied = false;

    #lastPlayerCount = 0;

    #lastDomain = "";

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Imported Room Website Player Service.
     *
     * @param {ImportedRoomRuntime} runtime
     *        Owning Imported Room Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

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
     * Releases imported website player compatibility state.
     */
    destroy() {

        this.#context = null;
        this.#lastCompatibilityApplied = false;
        this.#lastPlayerCount = 0;
        this.#lastDomain = "";

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

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Presentation
    //--------------------------------------------------

    /**
     * Returns imported website page-level player HTML.
     *
     * @param {Object} track
     *
     * @returns {string}
     */
    inlinePlayerHtml(track) {

        if (!track?.url) return "";

        if (track.type === "audio") {

            return `
      <div class="vp-import-player">
        <audio class="vp-page-player" preload="none" controls loop>
          <source src="${track.url}" type="audio/mpeg">
        </audio>
      </div>
    `;

        }

        if (track.type === "youtube") {

            return `
      <div class="vp-import-player">
        <audio class="vp-page-player" preload="none" controls loop>
          <source src="${track.url}" type="video/x-youtube">
        </audio>
      </div>
    `;

        }

        return "";

    }

    /**
     * Applies imported website player compatibility behavior.
     *
     * @param {Object} options
     *
     * @returns {boolean}
     */
    applyCompatibility(options = {}) {

        this.#lastCompatibilityApplied =
            false;

        this.#lastDomain =
            options.innerTranquillity ? "inner-tranquillity.net" : "";

        if (!options.innerTranquillity) return false;

        const windowRef =
            this.#window();

        const jQueryRef =
            windowRef?.jQuery || windowRef?.$;

        if (!jQueryRef?.fn?.player) return false;

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

        this.#lastCompatibilityApplied =
            true;

        return true;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns imported website player diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ImportedRoomRuntime",

            build:
                "000031",

            configured:
                Boolean(this.#context),

            compatibilityApplied:
                this.#lastCompatibilityApplied,

            playerCount:
                this.#lastPlayerCount,

            domain:
                this.#lastDomain

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

export default ImportedRoomWebsitePlayerService;
