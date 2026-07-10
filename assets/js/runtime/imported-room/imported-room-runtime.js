/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      imported-room-runtime.js
 *
 * Layer:
 *      Runtime
 *
 * Owner:
 *      Imported Room Runtime
 *
 * Purpose:
 *      Owns imported room layout and music runtime coordination.
 *
 * Build:
 *      000033
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000030
 * - Introduced ImportedRoomRuntime foundation.
 * - Added ImportedRoomLayoutRenderer and ImportedRoomMusicService ownership.
 * Build 000031
 * - Added ImportedRoomWebsitePlayerService ownership for page-level imported
 *   website music-player compatibility.
 * Build 000033
 * - Replaced ImportedRoomWebsitePlayerService with
 *   ImportedRoomWebsiteCompatibilityService.
 ******************************************************************************/

/**
 * @file imported-room-runtime.js
 *
 * Defines the Imported Room Runtime.
 */

import {

    CoreModule

} from "../../core/core-module.js";

import {

    ImportedRoomLayoutRenderer

} from "./renderers/imported-room-layout-renderer.js";

import {

    ImportedRoomMusicService

} from "./services/imported-room-music-service.js";

import {

    ImportedRoomWebsiteCompatibilityService

} from "./services/imported-room-website-compatibility-service.js";

//--------------------------------------------------
// Imported Room Runtime
//--------------------------------------------------

/**
 * Coordinates imported room layout and music runtime components.
 */
export class ImportedRoomRuntime extends CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Imported room layout presentation renderer.
     */
    #layout = null;

    /**
     * Imported room music workflow service.
     */
    #music = null;

    /**
     * Imported website compatibility service.
     */
    #websiteCompatibility = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Imported Room Runtime.
     */
    constructor() {

        super({

            id:
                "imported-room-runtime",

            name:
                "Imported Room Runtime",

            version:
                "1.0.0",

            description:
                "Coordinates imported room layout and music behavior.",

            metadata:
                {}

        });

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the Imported Room Layout Renderer.
     *
     * @returns {ImportedRoomLayoutRenderer}
     */
    get layout() {

        return this.#layout;

    }

    /**
     * Returns the Imported Room Music Service.
     *
     * @returns {ImportedRoomMusicService}
     */
    get music() {

        return this.#music;

    }

    /**
     * Returns the Imported Room Website Compatibility Service.
     *
     * @returns {ImportedRoomWebsiteCompatibilityService}
     */
    get websiteCompatibility() {

        return this.#websiteCompatibility;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns ImportedRoomRuntime diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ImportedRoomRuntime",

            build:
                "000033",

            layout:
                this.#layout?.getDiagnostics() ?? null,

            music:
                this.#music?.getDiagnostics() ?? null,

            websiteCompatibility:
                this.#websiteCompatibility?.getDiagnostics() ?? null

        });

    }

    //--------------------------------------------------
    // Core Lifecycle
    //--------------------------------------------------

    /**
     * Creates runtime-owned imported room components.
     */
    onInitialize() {

        this.#createWebsiteCompatibilityService();
        this.#createMusicService();
        this.#createLayoutRenderer();

    }

    /**
     * Releases runtime-owned imported room components.
     */
    onDestroy() {

        this.#layout?.destroy();
        this.#music?.destroy();
        this.#websiteCompatibility?.destroy();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates the Imported Room Website Compatibility Service runtime component.
     */
    #createWebsiteCompatibilityService() {

        this.#websiteCompatibility =
            new ImportedRoomWebsiteCompatibilityService(
                this
            );

        this.#websiteCompatibility.initialize();

    }

    /**
     * Creates the Imported Room Music Service runtime component.
     */
    #createMusicService() {

        this.#music =
            new ImportedRoomMusicService(
                this,
                this.#websiteCompatibility
            );

        this.#music.initialize();

    }

    /**
     * Creates the Imported Room Layout Renderer runtime component.
     */
    #createLayoutRenderer() {

        this.#layout =
            new ImportedRoomLayoutRenderer(
                this,
                this.#music
            );

        this.#layout.initialize();

    }

}

export default ImportedRoomRuntime;
