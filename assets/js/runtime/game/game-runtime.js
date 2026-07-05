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

} from "./services/game-lifecycle-service.js";

import {

    GameStageRenderer

} from "./renderers/game-stage-renderer.js";

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
                this.#stage?.getDiagnostics() ?? null

        });

    }

    //--------------------------------------------------
    // Core Lifecycle
    //--------------------------------------------------

    /**
     * Creates runtime-owned game components.
     */
    onInitialize() {

        this.#createStageRenderer();
        this.#createLifecycleService();

    }

    /**
     * Releases runtime-owned game components.
     */
    onDestroy() {

        this.#lifecycle?.destroy();
        this.#stage?.destroy();

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

}

export default GameRuntime;
