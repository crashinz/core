/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      room-runtime.js
 *
 * Layer:
 *      Runtime
 *
 * Owner:
 *      Room Runtime
 *
 * Purpose:
 *      Owns room-level runtime coordination.
 *
 *      RoomRuntime coordinates room-specific runtime components while
 *      participating in the framework module lifecycle.
 *
 * Build:
 *      000026
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000026
 * - Introduced RoomRuntime foundation.
 * - Added RoomEventRouter ownership and diagnostics.
 ******************************************************************************/

/**
 * @file room-runtime.js
 *
 * Defines the Room Runtime.
 */

import {

    CoreModule

} from "../../core/core-module.js";

import {

    RoomEventRouter

} from "./routing/room-event-router.js";

import {

    ParticipantActionCatalogService

} from "./services/participant-action-catalog-service.js";

//--------------------------------------------------
// Room Runtime
//--------------------------------------------------

/**
 * Coordinates room runtime components.
 */
export class RoomRuntime extends CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Non-chat event routing runtime component.
     */
    #events = null;

    #participantActions = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Room Runtime.
     */
    constructor() {

        super({

            id:
                "room-runtime",

            name:
                "Room Runtime",

            version:
                "1.0.0",

            description:
                "Coordinates room runtime components.",

            metadata:
                {}

        });

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the Room Event Router.
     *
     * @returns {RoomEventRouter}
     */
    get events() {

        return this.#events;

    }

    get participantActions() {

        return this.#participantActions;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns RoomRuntime diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "RoomRuntime",

            build:
                "000026",

            events:
                this.#events?.getDiagnostics() ?? null,

            participantActions:
                this.#participantActions?.getDiagnostics() ?? null

        });

    }

    //--------------------------------------------------
    // Core Lifecycle
    //--------------------------------------------------

    /**
     * Creates runtime-owned room components.
     */
    onInitialize() {

        this.#createEventRouter();

        this.#createParticipantActionCatalog();

    }

    /**
     * Releases runtime-owned room components.
     */
    onDestroy() {

        this.#events?.destroy();

        this.#participantActions?.destroy();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates the Room Event Router runtime component.
     */
    #createEventRouter() {

        this.#events =
            new RoomEventRouter(
                this
            );

        this.#events.initialize();

    }

    #createParticipantActionCatalog() {

        this.#participantActions =
            new ParticipantActionCatalogService(
                this
            );

        this.#participantActions.initialize();

    }

}

export default RoomRuntime;
