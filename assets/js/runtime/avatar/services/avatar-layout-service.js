/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-layout-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns avatar layout state and behavior.
 *
 *      AvatarLayoutService calculates avatar geometry, positioning, snapping,
 *      and layout state updates for AvatarRuntime. Rendering is owned by
 *      AvatarRenderer.
 *
 * Build:
 *      000018
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000012
 * - Introduced Avatar Layout Service.
 * - Established runtime-owned layout service.
 * - No layout behavior migrated.
 *
 * Build 000018
 * - Added avatar frame calculation.
 * - Added linked-pair and lap-pair layout ownership.
 * - Added drag group layout ownership.
 ******************************************************************************/

/**
 * @file avatar-layout-service.js
 *
 * Defines the Avatar Layout Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Avatar Layout Service
//--------------------------------------------------

/**
 * Owns avatar layout state and behavior.
 */
export class AvatarLayoutService {

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
     * Number of layout operations performed.
     *
     * @type {number}
     */
    #layoutCount = 0;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Layout Service.
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
     * Initializes the layout service.
     */
    initialize() {

    }

    /**
     * Releases resources owned by the layout service.
     */
    destroy() {

    }

    //--------------------------------------------------
    // Public Layout API
    //--------------------------------------------------

    /**
     * Returns the stage size for an avatar.
     *
     * @param {Object} participant
     * @param {Object} options
     * @param {number} options.baseSize
     *
     * @returns {number}
     */
    avatarStageSize(participant, { baseSize }) {

        return this.#relationships.isLapLinkInitiator(participant)
            ? Math.round(baseSize * 0.5)
            : baseSize;

    }

    /**
     * Calculates the clamped avatar frame for a participant.
     *
     * @param {Object} participant
     * @param {Object} options
     * @param {number} options.stageWidth
     * @param {number} options.stageHeight
     * @param {number} options.baseSize
     *
     * @returns {Object}
     */
    avatarFrame(participant, { stageWidth, stageHeight, baseSize }) {

        const width =
            this.avatarStageSize(participant, { baseSize });

        const height =
            this.avatarStageSize(participant, { baseSize });

        const x =
            Math.max(
                0,
                Math.min(
                    stageWidth - width,
                    Number(participant.position_x) * stageWidth
                )
            );

        const y =
            Math.max(
                0,
                Math.min(
                    stageHeight - height,
                    Number(participant.position_y) * stageHeight
                )
            );

        return Object.freeze({

            x,

            y,

            width,

            height

        });

    }

    /**
     * Applies linked-pair layout.
     *
     * @param {Object} options
     * @param {Object} options.initiator
     * @param {Object} options.target
     * @param {number} options.stageWidth
     * @param {number} options.stageHeight
     * @param {number} options.avatarSize
     * @param {number} options.gap
     *
     * @returns {Object[]}
     */
    applyLinkedPairLayout({
        initiator,
        target,
        stageWidth,
        stageHeight,
        avatarSize,
        gap
    }) {

        if (!initiator || !target) {
            return [];
        }

        const targetX =
            Number(target.position_x) * stageWidth;

        const y =
            Number(target.position_y) * stageHeight;

        const initiatorX =
            targetX + avatarSize + gap;

        const relationshipLeft =
            Math.min(targetX, initiatorX);

        const relationshipRight =
            Math.max(
                targetX + avatarSize,
                initiatorX + avatarSize
            );

        const relationshipTop =
            y;

        const relationshipBottom =
            y + avatarSize;

        let translateX = 0;
        let translateY = 0;

        if (relationshipLeft < 0) {
            translateX = -relationshipLeft;
        } else if (relationshipRight > stageWidth) {
            translateX = stageWidth - relationshipRight;
        }

        if (relationshipTop < 0) {
            translateY = -relationshipTop;
        } else if (relationshipBottom > stageHeight) {
            translateY = stageHeight - relationshipBottom;
        }

        target.position_x =
            (targetX + translateX) / stageWidth;

        target.position_y =
            (y + translateY) / stageHeight;

        initiator.position_x =
            (initiatorX + translateX) / stageWidth;

        initiator.position_y =
            (y + translateY) / stageHeight;

        this.#layoutCount += 1;

        return [
            target,
            initiator
        ];

    }

    /**
     * Applies lap-pair layout.
     *
     * @param {Object} options
     * @param {Object} options.initiator
     * @param {Object} options.target
     * @param {number} options.stageWidth
     * @param {number} options.stageHeight
     * @param {number} options.primarySize
     * @param {number} options.lapSize
     * @param {boolean} [options.locked=false]
     *
     * @returns {Object[]}
     */
    applyLappedPairLayout({
        initiator,
        target,
        stageWidth,
        stageHeight,
        primarySize,
        lapSize,
        locked = false
    }) {

        if (locked || !initiator || !target) {
            return [];
        }

        const lapHorizontalOffset =
            primarySize * 0.5;

        const lapVerticalOffset =
            primarySize * (65 / 150);

        const targetX =
            Number(target.position_x) * stageWidth;

        const targetY =
            Number(target.position_y) * stageHeight;

        const groupWidth =
            Math.max(
                primarySize,
                lapHorizontalOffset + lapSize
            );

        const groupHeight =
            Math.max(
                primarySize,
                lapVerticalOffset + lapSize
            );

        const baseX =
            Math.max(
                0,
                Math.min(targetX, stageWidth - groupWidth)
            );

        const baseY =
            Math.max(
                0,
                Math.min(targetY, stageHeight - groupHeight)
            );

        const changed = [];

        if (!target._lockedPosition) {

            target.position_x =
                baseX / stageWidth;

            target.position_y =
                baseY / stageHeight;

            changed.push(target);

        }

        if (!initiator._lockedPosition) {

            initiator.position_x =
                (baseX + lapHorizontalOffset) / stageWidth;

            initiator.position_y =
                (baseY + lapVerticalOffset) / stageHeight;

            changed.push(initiator);

        }

        this.#layoutCount += 1;

        return changed;

    }

    /**
     * Applies drag-group layout.
     *
     * @param {Object} options
     * @param {Object[]} options.group
     * @param {number} options.baseX
     * @param {number} options.baseY
     * @param {number} options.spacing
     *
     * @returns {Object[]}
     */
    applyDragGroupLayout({
        group,
        baseX,
        baseY,
        spacing
    }) {

        const members =
            Array.isArray(group)
                ? group
                : [];

        members.forEach((member, index) => {

            member.position_x = baseX;

            member.position_y = baseY;

            member.layout_offset_x =
                index * spacing;

            member.layout_offset_y =
                0;

            member.position_x +=
                member.layout_offset_x;

        });

        if (members.length) {
            this.#layoutCount += 1;
        }

        return members;

    }

    /**
     * Applies authoritative participant coordinates.
     *
     * @param {Object} participant
     * @param {Object} position
     * @param {number} position.x
     * @param {number} position.y
     *
     * @returns {Object|null}
     */
    applyParticipantPosition(participant, { x, y }) {

        if (!participant) {
            return null;
        }

        participant.position_x = x;

        participant.position_y = y;

        this.#layoutCount += 1;

        return participant;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns service diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            layoutCount:
                this.#layoutCount

        });

    }

    /**
     * Returns the owning Avatar Runtime.
     *
     * @returns {AvatarRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Private Getters
    //--------------------------------------------------

    /**
     * Returns the relationship owner.
     *
     * @returns {AvatarRelationshipService}
     */
    get #relationships() {

        return this.#runtime.relationships;

    }

}

export default AvatarLayoutService;
