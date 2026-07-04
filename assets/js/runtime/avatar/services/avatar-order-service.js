/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-order-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns avatar ordering state and behavior.
 *
 *      AvatarOrderService is responsible for determining participant ordering,
 *      linked group ordering, and avatar layer stacking decisions within the
 *      runtime.
 *
 *      This service is owned exclusively by AvatarRuntime and is an internal
 *      implementation detail of the Avatar Runtime.
 *
 * Build:
 *      000019
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000012
 * - Introduced Avatar Order Service.
 * - Established runtime-owned ordering service.
 * - No ordering behavior migrated.
 *
 * Build 000019
 * - Migrated participant list ordering from room.js.
 * - Migrated linked group ordering from room.js.
 * - Migrated avatar stage layer ordering from room.js.
 * - Migrated webcam stacking insertion decision from room.js.
 ******************************************************************************/

/**
 * @file avatar-order-service.js
 *
 * Defines the Avatar Order Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Avatar Order Service
//--------------------------------------------------

/**
 * Owns avatar ordering state and behavior.
 *
 * AvatarOrderService is owned exclusively by AvatarRuntime.
 *
 * The service is an internal implementation detail and is not exposed
 * through the runtime's public API.
 */
export class AvatarOrderService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Avatar Runtime.
     */
    #runtime;

    /**
     * Number of ordering operations performed.
     */
    #orderCount = 0;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Order Service.
     *
     * @param {AvatarRuntime} runtime
     *        Owning Avatar Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Methods
    //--------------------------------------------------

    /**
     * Initializes the order service.
     */
    initialize() {

        this.#orderCount = 0;

    }

    /**
     * Releases resources owned by the order service.
     */
    destroy() {

        this.#orderCount = 0;

    }

    /**
     * Returns the owning Avatar Runtime.
     *
     * @returns {AvatarRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    /**
     * Orders participants by the legacy display name ordering rule.
     *
     * @param {Iterable<Object>} participants
     *        Participants to order.
     *
     * @returns {Object[]}
     *          Ordered participant list.
     */
    orderParticipants(participants) {

        this.#orderCount += 1;

        return Array.from(participants || [])
            .filter(participant =>
                participant &&
                typeof participant === "object" &&
                participant.id
            )
            .sort((a, b) =>
                this.#displayName(a).localeCompare(this.#displayName(b))
            );

    }

    /**
     * Returns the visible participants in runtime-owned order.
     *
     * @param {Iterable<Object>} participants
     *        Participants to order.
     *
     * @returns {Object[]}
     *          Visible ordered participant list.
     */
    visibleParticipants(participants) {

        return this.orderParticipants(participants);

    }

    /**
     * Orders a linked participant group.
     *
     * The production implementation preserves relationship graph insertion
     * order for linked groups. This method owns that rule explicitly.
     *
     * @param {Iterable<Object>} group
     *        Linked participant group.
     *
     * @returns {Object[]}
     *          Ordered linked group.
     */
    orderLinkedGroup(group) {

        this.#orderCount += 1;

        return Array.from(group || [])
            .filter(participant =>
                participant &&
                typeof participant === "object" &&
                participant.id
            );

    }

    /**
     * Returns the stage layer append order for an avatar participant.
     *
     * @param {Object} layers
     *        Avatar layer elements.
     *
     * @returns {Element[]}
     *          Ordered stage layers.
     */
    stageLayerOrder(layers) {

        this.#orderCount += 1;

        return [

            layers?.avatar,
            layers?.aura,
            layers?.label

        ].filter(Boolean);

    }

    /**
     * Returns the insertion anchor for a participant webcam layer.
     *
     * @param {Object} participant
     *        Participant with existing avatar layers.
     *
     * @returns {Element|null}
     *          Element before which the webcam layer should be inserted.
     */
    webcamInsertBefore(participant) {

        this.#orderCount += 1;

        return participant?.labelEl || null;

    }

    /**
     * Returns service diagnostic information.
     *
     * @returns {Object}
     *          Order service diagnostics.
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "AvatarOrderService",

            build:
                "000019",

            orderCount:
                this.#orderCount

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns a participant display name for ordering.
     *
     * @param {Object} participant
     *        Participant to inspect.
     *
     * @returns {string}
     *          Display name.
     */
    #displayName(participant) {

        return participant?.display_name || "";

    }

}

export default AvatarOrderService;
