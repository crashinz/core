/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      room-effects-runtime.js
 *
 * Layer:
 *      Runtime
 *
 * Owner:
 *      Room Effects Runtime
 *
 * Purpose:
 *      Owns room-wide environmental effects runtime coordination.
 *
 * Build:
 *      000029
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000029
 * - Introduced RoomEffectsRuntime foundation.
 * - Added RoomEffectsService ownership and diagnostics.
 ******************************************************************************/

/**
 * @file room-effects-runtime.js
 *
 * Defines the Room Effects Runtime.
 */

import {

    CoreModule

} from "../../core/core-module.js";

import {

    RoomEffectsService

} from "./services/room-effects-service.js";

//--------------------------------------------------
// Room Effects Runtime
//--------------------------------------------------

/**
 * Coordinates room-wide environmental effect runtime components.
 */
export class RoomEffectsRuntime extends CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Room-wide environmental effects service.
     */
    #effects = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Room Effects Runtime.
     */
    constructor() {

        super({

            id:
                "room-effects-runtime",

            name:
                "Room Effects Runtime",

            version:
                "1.0.0",

            description:
                "Coordinates room-wide environmental effects.",

            metadata:
                {}

        });

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the Room Effects Service.
     *
     * @returns {RoomEffectsService}
     */
    get effects() {

        return this.#effects;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns RoomEffectsRuntime diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "RoomEffectsRuntime",

            build:
                "000029",

            effects:
                this.#effects?.getDiagnostics() ?? null

        });

    }

    //--------------------------------------------------
    // Core Lifecycle
    //--------------------------------------------------

    /**
     * Creates runtime-owned room effects components.
     */
    onInitialize() {

        this.#createEffectsService();

    }

    /**
     * Releases runtime-owned room effects components.
     */
    onDestroy() {

        this.#effects?.destroy();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates the Room Effects Service runtime component.
     */
    #createEffectsService() {

        this.#effects =
            new RoomEffectsService(
                this
            );

        this.#effects.initialize();

    }

}

export default RoomEffectsRuntime;
