/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      room-event-router.js
 *
 * Layer:
 *      Runtime Router
 *
 * Owner:
 *      Room Runtime
 *
 * Purpose:
 *      Owns non-chat room and community event routing.
 *
 *      RoomEventRouter classifies non-chat events received from the main poll
 *      transport and delegates behavior to host callbacks or owning runtimes.
 *      Chat event routing remains owned by ChatRuntime.
 *
 * Build:
 *      000044 Part 3
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000026
 * - Introduced RoomEventRouter.
 * - Transferred non-chat room/community poll event classification from room.js.
 *
 * Build 000044 Part 3
 * - Added atomic relationship-position event routing to AvatarCoordinator.
 ******************************************************************************/

/**
 * @file room-event-router.js
 *
 * Defines the Room Event Router.
 */

//
// No imports required.
//

//--------------------------------------------------
// Room Event Router
//--------------------------------------------------

/**
 * Owns non-chat room and community event routing.
 */
export class RoomEventRouter {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Room Runtime.
     *
     * @type {RoomRuntime}
     */
    #runtime;

    /**
     * Host callbacks supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    /**
     * Number of room events classified by this router.
     *
     * @type {number}
     */
    #roomEventCount = 0;

    /**
     * Number of community events classified by this router.
     *
     * @type {number}
     */
    #communityEventCount = 0;

    /**
     * Last routed room event type.
     *
     * @type {string}
     */
    #lastRoomEventType = "";

    /**
     * Last routed community event type.
     *
     * @type {string}
     */
    #lastCommunityEventType = "";

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Room Event Router.
     *
     * @param {RoomRuntime} runtime
     *        Owning Room Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Initializes the router.
     */
    initialize() {

    }

    /**
     * Releases router-owned callback references.
     */
    destroy() {

        this.#context = null;
        this.#roomEventCount = 0;
        this.#communityEventCount = 0;
        this.#lastRoomEventType = "";
        this.#lastCommunityEventType = "";

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Room Runtime.
     *
     * @returns {RoomRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures host event routing callbacks.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Routing API
    //--------------------------------------------------

    /**
     * Routes one non-chat room poll event.
     *
     * @param {Object} event
     *
     * @returns {boolean}
     */
    routeRoomEvent(event = {}) {

        const type =
            String(event?.type || "");

        const payload =
            event?.payload || {};

        this.#roomEventCount += 1;
        this.#lastRoomEventType = type;

        switch (type) {

            case "participant_join":
                this.#context?.onParticipantJoin?.(payload, event);
                return true;

            case "participant_leave":
                this.#context?.onParticipantLeave?.(payload, event);
                return true;

            case "position":
                this.#context?.onParticipantPosition?.(payload, event);
                return true;

            case "relationship_position":
                this.#context?.onRelationshipPosition?.(payload, event);
                return true;

            case "webcam":
                this.#context?.onParticipantWebcam?.(payload, event);
                return true;

            case "avatar":
                this.#context?.onParticipantAvatar?.(payload, event);
                return true;

            case "avatar_size_policy":
                this.#context?.onAvatarSizePolicy?.(payload, event);
                return true;

            case "aura":
                this.#context?.onParticipantAura?.(payload, event);
                return true;

            case "user_role_update":
                this.#context?.onUserRoleUpdate?.(payload, event);
                return true;

            case "role_colors_update":
                this.#context?.onRoleColorsUpdate?.(payload, event);
                return true;

            case "typing":
                this.#context?.onTyping?.(payload, event);
                return true;

            case "presence_leave":
                this.#context?.onPresenceLeave?.(payload, event);
                return true;

            case "link":
                this.#context?.onRemoteLink?.(payload, event);
                return true;

            case "relationship":
                this.#context?.onRemoteRelationship?.(payload, event);
                return true;

            case "link_icon":
                this.#context?.onRemoteLinkIcon?.(payload, event);
                return true;

            case "block":
                this.#context?.onBlock?.(payload, event);
                return true;

            case "unblock":
                this.#context?.onUnblock?.(payload, event);
                return true;

            case "game_start":
            case "game_end":
            case "game_update":
                this.#context?.onGameEvent?.(payload, event);
                return true;

            case "room_update":
                this.#context?.onRoomUpdate?.(payload, event);
                return true;

            case "room_deleted":
                this.#context?.onRoomDeleted?.(payload, event);
                return true;

            case "room_effect":
                this.#context?.onRoomEffect?.(payload, event);
                return true;

            case "host_warning":
                this.#context?.onHostWarning?.(payload, event);
                return true;

            case "host_ejection":
                this.#context?.onHostEjection?.(payload, event);
                return true;

            case "community_ejection":
                this.#context?.onCommunityEjection?.(payload, event);
                return true;

            default:
                return false;

        }

    }

    /**
     * Routes one non-chat community poll event.
     *
     * @param {Object} event
     *
     * @returns {boolean}
     */
    routeCommunityEvent(event = {}) {

        const type =
            String(event?.type || "");

        const payload =
            event?.payload || {};

        this.#communityEventCount += 1;
        this.#lastCommunityEventType = type;

        switch (type) {

            case "link_typing":
                this.#context?.onLinkTyping?.(payload, event);
                return true;

            case "game_typing":
                this.#context?.onGameTyping?.(payload, event);
                return true;

            default:
                return false;

        }

    }

    /**
     * Returns router diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "RoomRuntime",

            build:
                "000026",

            configured:
                Boolean(this.#context),

            roomEventCount:
                this.#roomEventCount,

            communityEventCount:
                this.#communityEventCount,

            lastRoomEventType:
                this.#lastRoomEventType,

            lastCommunityEventType:
                this.#lastCommunityEventType

        });

    }

}

export default RoomEventRouter;
