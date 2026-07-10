/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      imported-room-website-compatibility-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Imported Room Runtime
 *
 * Purpose:
 *      Owns imported website page-level music-player compatibility routing.
 *
 * Build:
 *      000033
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000033
 * - Introduced ImportedRoomWebsiteCompatibilityService.
 * - Isolated website compatibility routing from generic imported music
 *   ownership.
 ******************************************************************************/

/**
 * @file imported-room-website-compatibility-service.js
 *
 * Defines imported website page-level compatibility routing.
 */

import {

    InnerTranquillityCompatibility

} from "../compat/inner-tranquillity-compatibility.js";

//--------------------------------------------------
// Imported Room Website Compatibility Service
//--------------------------------------------------

/**
 * Owns imported website page-level compatibility routing.
 */
export class ImportedRoomWebsiteCompatibilityService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #context = null;

    #compatibilities = [];

    #lastCompatibilityApplied = false;

    #lastDomain = "";

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Imported Room Website Compatibility Service.
     *
     * @param {ImportedRoomRuntime} runtime
     *        Owning Imported Room Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;
        this.#compatibilities = [
            new InnerTranquillityCompatibility()
        ];

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
     * Releases imported website compatibility state.
     */
    destroy() {

        this.#compatibilities.forEach(compatibility => {
            compatibility.destroy?.();
        });

        this.#context = null;
        this.#lastCompatibilityApplied = false;
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

        this.#compatibilities.forEach(compatibility => {
            compatibility.configure?.(context);
        });

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
     * Applies imported website compatibility behavior.
     *
     * @param {Object} options
     *
     * @returns {boolean}
     */
    applyCompatibility(options = {}) {

        this.#lastCompatibilityApplied =
            false;

        this.#lastDomain =
            "";

        for (const compatibility of this.#compatibilities) {

            if (!compatibility.matches?.(options)) continue;

            const applied =
                Boolean(
                    compatibility.apply?.(
                        options
                    )
                );

            this.#lastCompatibilityApplied =
                applied;

            this.#lastDomain =
                compatibility.getDiagnostics?.().domain || "";

            return applied;

        }

        return false;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns imported website compatibility diagnostics.
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

            compatibilityApplied:
                this.#lastCompatibilityApplied,

            domain:
                this.#lastDomain,

            implementations:
                this.#compatibilities.map(compatibility =>
                    compatibility.getDiagnostics?.() || null
                )

        });

    }

}

export default ImportedRoomWebsiteCompatibilityService;
