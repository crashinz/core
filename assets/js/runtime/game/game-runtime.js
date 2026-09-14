/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      game-runtime.js
 *
 * Layer:
 *      Runtime
 *
 * Owner:
 *      Game Runtime
 *
 * Purpose:
 *      Owns embedded game lifecycle runtime coordination.
 *
 * Build:
 *      000028
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000028
 * - Introduced GameRuntime foundation.
 * - Added GameLifecycleService and GameStageRenderer ownership.
 ******************************************************************************/

/**
 * @file game-runtime.js
 *
 * Defines the Game Runtime.
 */

import {

    CoreModule

} from "../../core/core-module.js";

import {

    GameLifecycleService

} from "./services/game-lifecycle-service.js?v=b075f1589f29";

import {

    GameStageRenderer

} from "./renderers/game-stage-renderer.js?v=8dbe798644a5";

import {

    GameAdaptiveLayoutService

} from "./services/game-adaptive-layout-service.js?v=20260824-pregame-board-visible-r3";

import {

    GameWebcamOverlayService

} from "./services/game-webcam-overlay-service.js?v=20260902-shared-webcams-r1";

//--------------------------------------------------
// Game Runtime
//--------------------------------------------------

/**
 * Coordinates embedded game runtime components.
 */
export class GameRuntime extends CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Game lifecycle workflow service.
     */
    #lifecycle = null;

    /**
     * Game stage presentation renderer.
     */
    #stage = null;

    /**
     * Presentation-only adaptive game/chat layout owner.
     */
    #layout = null;

    /**
     * Shared parent-shell in-game webcam presentation owner.
     */
    #webcams = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Game Runtime.
     */
    constructor() {

        super({

            id:
                "game-runtime",

            name:
                "Game Runtime",

            version:
                "1.0.0",

            description:
                "Coordinates embedded game runtime components.",

            metadata:
                {}

        });

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the Game Lifecycle Service.
     *
     * @returns {GameLifecycleService}
     */
    get lifecycle() {

        return this.#lifecycle;

    }

    /**
     * Returns the Game Stage Renderer.
     *
     * @returns {GameStageRenderer}
     */
    get stage() {

        return this.#stage;

    }

    /**
     * Returns the adaptive game/chat layout service.
     *
     * @returns {GameAdaptiveLayoutService}
     */
    get layout() {

        return this.#layout;

    }

    /**
     * Returns the shared in-game webcam overlay service.
     *
     * @returns {GameWebcamOverlayService}
     */
    get webcams() {

        return this.#webcams;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns GameRuntime diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "GameRuntime",

            build:
                "000028",

            lifecycle:
                this.#lifecycle?.getDiagnostics() ?? null,

            stage:
                this.#stage?.getDiagnostics() ?? null,

            layout:
                this.#layout?.getDiagnostics() ?? null,

            webcams:
                this.#webcams?.getDiagnostics() ?? null

        });

    }

    //--------------------------------------------------
    // Core Lifecycle
    //--------------------------------------------------

    /**
     * Creates runtime-owned game components.
     */
    onInitialize() {

        this.#createLayoutService();
        this.#createStageRenderer();
        this.#createWebcamOverlayService();
        this.#createLifecycleService();

    }

    /**
     * Releases runtime-owned game components.
     */
    onDestroy() {

        this.#lifecycle?.destroy();
        this.#webcams?.destroy();
        this.#stage?.destroy();
        this.#layout?.destroy();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates the Game Stage Renderer runtime component.
     */
    #createStageRenderer() {

        this.#stage =
            new GameStageRenderer(
                this
            );

        this.#stage.initialize();

    }

    /**
     * Creates the presentation-only adaptive layout service.
     */
    #createLayoutService() {

        this.#layout =
            new GameAdaptiveLayoutService(
                this
            );

        this.#layout.initialize();

    }

    /**
     * Creates the Game Lifecycle Service runtime component.
     */
    #createLifecycleService() {

        this.#lifecycle =
            new GameLifecycleService(
                this,
                this.#stage
            );

        this.#lifecycle.initialize();

    }

    /**
     * Creates the parent-shell webcam presentation owner.
     */
    #createWebcamOverlayService() {

        this.#webcams =
            new GameWebcamOverlayService(this);

    }

}

export default GameRuntime;
