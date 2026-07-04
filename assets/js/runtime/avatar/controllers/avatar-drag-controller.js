/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-drag-controller.js
 *
 * Layer:
 *      Controller
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns avatar drag/input lifecycle behavior.
 *
 *      AvatarDragController translates pointer input into avatar runtime
 *      commands. It owns drag state, pointer tracking, drag-to-link target
 *      detection, and drag completion sequencing while delegating relationship
 *      lifecycle decisions to AvatarCoordinator and layout calculations to
 *      AvatarLayoutService through the coordinator.
 *
 * Build:
 *      000024
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000024
 * - Introduced AvatarDragController.
 * - Transferred avatar drag/input lifecycle ownership from room.js.
 ******************************************************************************/

/**
 * @file avatar-drag-controller.js
 *
 * Defines the Avatar Drag Controller.
 */

//
// No imports required.
//

//--------------------------------------------------
// Constants
//--------------------------------------------------

const DEFAULT_LINK_TARGET_DISTANCE = 120;

//--------------------------------------------------
// Avatar Drag Controller
//--------------------------------------------------

/**
 * Owns avatar drag/input lifecycle behavior.
 *
 * AvatarDragController is owned exclusively by AvatarRuntime.
 */
export class AvatarDragController {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Avatar Runtime.
     *
     * @type {AvatarRuntime}
     */
    #runtime;

    /**
     * Host callbacks supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    /**
     * Attached drag state keyed by avatar element.
     *
     * @type {WeakMap<Element,Object>}
     */
    #dragStates = new WeakMap();

    /**
     * Elements with controller-owned event listeners.
     *
     * @type {Set<Element>}
     */
    #attachedElements = new Set();

    /**
     * Count of elements attached during this runtime lifetime.
     *
     * @type {number}
     */
    #attachedElementCount = 0;

    /**
     * Count of completed drag lifecycles.
     *
     * @type {number}
     */
    #completedDragCount = 0;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Drag Controller.
     *
     * @param {AvatarRuntime} runtime
     *        Owning Avatar Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Initializes the controller.
     */
    initialize() {

    }

    /**
     * Releases controller-owned drag state.
     */
    destroy() {

        this.#attachedElements.forEach(element => {

            const state = this.#dragStates.get(element);

            if (!state?.handlers) {
                return;
            }

            element.removeEventListener(
                "pointerdown",
                state.handlers.pointerdown
            );

            element.removeEventListener(
                "pointermove",
                state.handlers.pointermove
            );

            element.removeEventListener(
                "pointerup",
                state.handlers.pointerup
            );

        });

        this.#context = null;
        this.#dragStates = new WeakMap();
        this.#attachedElements = new Set();
        this.#attachedElementCount = 0;
        this.#completedDragCount = 0;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Avatar Runtime.
     *
     * @returns {AvatarRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures host drag lifecycle callbacks.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Drag API
    //--------------------------------------------------

    /**
     * Attaches drag lifecycle handlers to an avatar element.
     *
     * @param {Element} element
     *
     * @returns {boolean}
     */
    attachDraggable(element) {

        if (!element || this.#dragStates.has(element)) {
            return false;
        }

        const state = {

            dragging:
                false,

            relationshipBroken:
                false,

            offsetX:
                0,

            offsetY:
                0,

            group:
                null,

            handlers:
                null

        };

        state.handlers = {

            pointerdown:
                event => this.#handlePointerDown(element, state, event),

            pointermove:
                event => this.#handlePointerMove(element, state, event),

            pointerup:
                event => this.#handlePointerUp(element, state, event)

        };

        this.#dragStates.set(
            element,
            state
        );

        element.addEventListener(
            "pointerdown",
            state.handlers.pointerdown
        );

        element.addEventListener(
            "pointermove",
            state.handlers.pointermove
        );

        element.addEventListener(
            "pointerup",
            state.handlers.pointerup
        );

        this.#attachedElements.add(element);

        this.#attachedElementCount += 1;

        return true;

    }

    /**
     * Returns controller diagnostic information.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "AvatarRuntime",

            build:
                "000024",

            configured:
                Boolean(this.#context),

            attachedElementCount:
                this.#attachedElementCount,

            completedDragCount:
                this.#completedDragCount

        });

    }

    //--------------------------------------------------
    // Private Getters
    //--------------------------------------------------

    /**
     * Returns participant state owner.
     *
     * @returns {AvatarStateService}
     */
    get #participants() {

        return this.#runtime.state;

    }

    /**
     * Returns relationship lifecycle coordinator.
     *
     * @returns {AvatarCoordinator}
     */
    get #coordinator() {

        return this.#runtime.coordinator;

    }

    //--------------------------------------------------
    // Private Event Handlers
    //--------------------------------------------------

    /**
     * Handles avatar drag start.
     *
     * @param {Element} element
     * @param {Object} state
     * @param {PointerEvent} event
     */
    #handlePointerDown(element, state, event) {

        if (event.button !== 0) {
            return;
        }

        const participant =
            this.#currentParticipant();

        state.dragging = true;
        state.relationshipBroken = false;
        state.group =
            this.#coordinator?.linkedGroupForParticipant(participant?.id) || [
                participant
            ];
        state.offsetX =
            event.clientX - element.getBoundingClientRect().left;
        state.offsetY =
            event.clientY - element.getBoundingClientRect().top;

        element.setPointerCapture?.(
            event.pointerId
        );

        element.style.cursor = "grabbing";

    }

    /**
     * Handles avatar drag movement.
     *
     * @param {Element} element
     * @param {Object} state
     * @param {PointerEvent} event
     */
    #handlePointerMove(element, state, event) {

        if (!state.dragging || !state.group) {
            return;
        }

        this.#applyDragMove(
            element,
            state,
            event.clientX,
            event.clientY
        );

    }

    /**
     * Handles avatar drag completion.
     *
     * @param {Element} element
     * @param {Object} state
     * @param {PointerEvent} event
     */
    #handlePointerUp(element, state, event) {

        if (!state.dragging) {
            return;
        }

        state.dragging = false;
        element.style.cursor = "grab";

        element.releasePointerCapture?.(
            event.pointerId
        );

        const participant =
            this.#currentParticipant();

        if (!participant) {
            this.#resetDragState(state);
            return;
        }

        state.relationshipBroken = false;

        const target =
            this.#nearestLinkTarget(
                element,
                participant
            );

        this.#scheduleLinkChoice(
            target
        );

        this.#resetDragState(state);

        this.#coordinator?.persistDragEnd(
            participant
        );

        this.#completedDragCount += 1;

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Applies a drag movement to the active runtime drag group.
     *
     * @param {Element} element
     * @param {Object} state
     * @param {number} clientX
     * @param {number} clientY
     */
    #applyDragMove(element, state, clientX, clientY) {

        const stage =
            this.#stageElement();

        const participant =
            this.#currentParticipant();

        if (!stage || !participant) {
            return;
        }

        const rect =
            stage.getBoundingClientRect();

        const x =
            Math.max(
                0,
                Math.min(
                    rect.width - element.offsetWidth,
                    clientX - rect.left - state.offsetX
                )
            );

        const y =
            Math.max(
                0,
                Math.min(
                    rect.height - element.offsetHeight,
                    clientY - rect.top - state.offsetY
                )
            );

        if (!state.relationshipBroken && participant.linked_to) {
            state.relationshipBroken = true;
            this.#coordinator?.breakRelationshipForDrag(participant);
        }

        this.#coordinator?.applyDragGroupMove({

            participant,

            group:
                state.group || [
                    participant
                ],

            baseX:
                rect.width
                    ? x / rect.width
                    : 0,

            baseY:
                rect.height
                    ? y / rect.height
                    : 0,

            spacing:
                rect.width
                    ? element.offsetWidth / rect.width
                    : 0,

            relationshipBroken:
                state.relationshipBroken

        });

    }

    /**
     * Returns the current local participant.
     *
     * @returns {Object|null}
     */
    #currentParticipant() {

        const config =
            this.#context?.getConfig?.();

        return this.#participants.get(config?.myParticipantId) || null;

    }

    /**
     * Returns the configured avatar stage element.
     *
     * @returns {Element|null}
     */
    #stageElement() {

        return this.#context?.stageElement?.() || null;

    }

    /**
     * Finds the nearest valid drag-to-link target.
     *
     * @param {Element} element
     * @param {Object} participant
     *
     * @returns {Object|null}
     */
    #nearestLinkTarget(element, participant) {

        if (!element || !participant) {
            return null;
        }

        const rect =
            element.getBoundingClientRect();

        const center =
            Object.freeze({

                x:
                    rect.left + rect.width / 2,

                y:
                    rect.top + rect.height / 2

            });

        const threshold =
            Number(
                this.#context?.linkTargetDistance?.() ||
                    DEFAULT_LINK_TARGET_DISTANCE
            );

        let target =
            null;

        this.#participants.forEach(other => {

            if (
                Number(other.id) === Number(participant.id) ||
                !other.avatarEl ||
                this.#context?.isUserBlocked?.(other.user_id)
            ) {
                return;
            }

            const otherRect =
                other.avatarEl.getBoundingClientRect();

            const distance =
                Math.hypot(
                    center.x - (otherRect.left + otherRect.width / 2),
                    center.y - (otherRect.top + otherRect.height / 2)
                );

            if (distance < threshold) {
                target = other;
            }

        });

        return target;

    }

    /**
     * Schedules drag-to-link menu opening.
     *
     * @param {Object|null} target
     */
    #scheduleLinkChoice(target) {

        const schedule =
            this.#context?.requestAnimationFrame ||
            globalThis.requestAnimationFrame;

        if (typeof schedule !== "function") {
            this.#requestLinkChoice(target);
            return;
        }

        schedule(() => {
            this.#requestLinkChoice(target);
        });

    }

    /**
     * Requests a link choice for the current participant and target.
     *
     * @param {Object|null} target
     */
    #requestLinkChoice(target) {

        const participant =
            this.#currentParticipant();

        if (!participant || !target) {
            return;
        }

        const freshTarget =
            this.#participants.get(target.id);

        if (!freshTarget) {
            return;
        }

        this.#coordinator?.requestLinkChoiceForDrag(
            participant,
            freshTarget
        );

    }

    /**
     * Clears active drag state.
     *
     * @param {Object} state
     */
    #resetDragState(state) {

        state.relationshipBroken = false;
        state.group = null;

    }

}

export default AvatarDragController;
