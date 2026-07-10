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
 *      000031
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

    ImportedRoomWebsitePlayerService

} from "./services/imported-room-website-player-service.js";

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
     * Imported website page-level player compatibility service.
     */
    #websitePlayer = null;

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
     * Returns the Imported Room Website Player Service.
     *
     * @returns {ImportedRoomWebsitePlayerService}
     */
    get websitePlayer() {

        return this.#websitePlayer;

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
                "000031",

            layout:
                this.#layout?.getDiagnostics() ?? null,

            music:
                this.#music?.getDiagnostics() ?? null,

            websitePlayer:
                this.#websitePlayer?.getDiagnostics() ?? null

        });

    }

    //--------------------------------------------------
    // Core Lifecycle
    //--------------------------------------------------

    /**
     * Creates runtime-owned imported room components.
     */
    onInitialize() {

        this.#createWebsitePlayerService();
        this.#createMusicService();
        this.#createLayoutRenderer();

    }

    /**
     * Releases runtime-owned imported room components.
     */
    onDestroy() {

        this.#layout?.destroy();
        this.#music?.destroy();
        this.#websitePlayer?.destroy();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates the Imported Room Website Player Service runtime component.
     */
    #createWebsitePlayerService() {

        this.#websitePlayer =
            new ImportedRoomWebsitePlayerService(
                this
            );

        this.#websitePlayer.initialize();

    }

    /**
     * Creates the Imported Room Music Service runtime component.
     */
    #createMusicService() {

        this.#music =
            new ImportedRoomMusicService(
                this,
                this.#websitePlayer
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
