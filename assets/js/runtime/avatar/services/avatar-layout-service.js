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
 *      000035
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
 *
 * Build 000032
 * - Updated avatar frame and relationship geometry to consume rendered
 *   avatar dimensions.
 *
 * Build 000035
 * - Added metadata-driven relationship geometry strategy execution.
 * - Added sideBySide and anchorPair strategy ownership.
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

    /**
     * Last relationship strategy diagnostic snapshot.
     *
     * @type {Object|null}
     */
    #lastRelationshipStrategy = null;

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

        this.#lastRelationshipStrategy = null;

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
    avatarStageSize(participant, { baseSize, dimensions = null }) {

        if (dimensions) {

            return Math.max(
                Number(dimensions.width || 0),
                Number(dimensions.height || 0)
            ) || baseSize;

        }

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
    avatarFrame(participant, { stageWidth, stageHeight, baseSize, dimensions = null }) {

        const rendered =
            dimensions || {
                width:
                    this.avatarStageSize(participant, { baseSize }),
                height:
                    this.avatarStageSize(participant, { baseSize })
            };

        const width =
            Number(rendered.width || baseSize);

        const height =
            Number(rendered.height || baseSize);

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
     * Applies relationship geometry from normalized metadata.
     *
     * @param {Object} options
     * @param {Object} options.initiator
     * @param {Object} options.target
     * @param {number} options.stageWidth
     * @param {number} options.stageHeight
     * @param {Object} options.initiatorDimensions
     * @param {Object} options.targetDimensions
     * @param {Object} options.metadata
     * @param {number} options.gap
     * @param {boolean} [options.locked=false]
     *
     * @returns {Object[]}
     */
    applyRelationshipLayout({
        initiator,
        target,
        stageWidth,
        stageHeight,
        initiatorDimensions,
        targetDimensions,
        metadata = null,
        gap = 0,
        locked = false
    }) {

        if (!initiator || !target) {
            return [];
        }

        const relationshipMetadata =
            this.#relationships.normalizeRelationshipMetadata(
                metadata,
                this.#relationships.relationshipMetadataForPair(initiator, target)
            );

        const capability =
            this.#relationships.relationshipCapability(
                relationshipMetadata.mode
            );

        const strategy =
            relationshipMetadata.geometryStrategy ||
            capability.geometryStrategy ||
            "sideBySide";

        const participants =
            this.#participantsForMetadata(
                relationshipMetadata,
                initiator,
                target
            );

        const selectedInitiatorDimensions =
            participants.initiator === initiator
                ? initiatorDimensions
                : targetDimensions;

        const selectedTargetDimensions =
            participants.target === target
                ? targetDimensions
                : initiatorDimensions;

        this.#lastRelationshipStrategy =
            Object.freeze({
                strategy,
                mode:
                    relationshipMetadata.mode,
                capability:
                    relationshipMetadata.capability,
                orientation:
                    relationshipMetadata.orientation,
                fallback:
                    relationshipMetadata.metadataSource !== "metadata",
                metadataSource:
                    relationshipMetadata.metadataSource,
                order:
                    relationshipMetadata.order,
                anchors:
                    Object.freeze({
                        relationship:
                            Boolean(relationshipMetadata.anchors.relationship),
                        members:
                            Object.keys(relationshipMetadata.anchors.members || {}).length,
                        mode:
                            Object.keys(relationshipMetadata.anchors.mode || {}).length
                    }),
                memberCount:
                    relationshipMetadata.members.length
            });

        if (strategy === "anchorPair") {
            return this.#applyAnchorPairStrategy({
                initiator:
                    participants.initiator,
                target:
                    participants.target,
                stageWidth,
                stageHeight,
                primaryDimensions:
                    selectedTargetDimensions,
                lapDimensions:
                    selectedInitiatorDimensions,
                metadata:
                    relationshipMetadata,
                locked
            });
        }

        return this.#applySideBySideStrategy({
            initiator:
                participants.initiator,
            target:
                participants.target,
            stageWidth,
            stageHeight,
            targetDimensions:
                selectedTargetDimensions,
            initiatorDimensions:
                selectedInitiatorDimensions,
            metadata:
                relationshipMetadata,
            gap
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
     * @param {Object} options.initiatorDimensions
     * @param {Object} options.targetDimensions
     * @param {number} options.gap
     *
     * @returns {Object[]}
     */
    applyLinkedPairLayout({
        initiator,
        target,
        stageWidth,
        stageHeight,
        initiatorDimensions,
        targetDimensions,
        gap
    }) {

        return this.applyRelationshipLayout({
            initiator,
            target,
            stageWidth,
            stageHeight,
            initiatorDimensions,
            targetDimensions,
            metadata:
                this.#relationships.normalizeRelationshipMetadata(
                    {},
                    {
                        mode:
                            "normal",
                        members:
                            this.#legacyMembers(initiator, target)
                    }
                ),
            gap
        });

    }

    /**
     * Applies lap-pair layout.
     *
     * @param {Object} options
     * @param {Object} options.initiator
     * @param {Object} options.target
     * @param {number} options.stageWidth
     * @param {number} options.stageHeight
     * @param {Object} options.primaryDimensions
     * @param {Object} options.lapDimensions
     * @param {boolean} [options.locked=false]
     *
     * @returns {Object[]}
     */
    applyLappedPairLayout({
        initiator,
        target,
        stageWidth,
        stageHeight,
        primaryDimensions,
        lapDimensions,
        locked = false
    }) {

        return this.applyRelationshipLayout({
            initiator,
            target,
            stageWidth,
            stageHeight,
            initiatorDimensions:
                lapDimensions,
            targetDimensions:
                primaryDimensions,
            metadata:
                this.#relationships.normalizeRelationshipMetadata(
                    {},
                    {
                        mode:
                            "lap",
                        members:
                            this.#legacyMembers(initiator, target)
                    }
                ),
            locked
        });

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
                this.#layoutCount,

            lastRelationshipStrategy:
                this.#lastRelationshipStrategy

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

    //--------------------------------------------------
    // Private Relationship Geometry
    //--------------------------------------------------

    /**
     * Applies side-by-side relationship geometry.
     *
     * @param {Object} options
     *
     * @returns {Object[]}
     */
    #applySideBySideStrategy({
        initiator,
        target,
        stageWidth,
        stageHeight,
        initiatorDimensions,
        targetDimensions,
        metadata,
        gap
    }) {

        if (!initiator || !target) {
            return [];
        }

        const targetWidth =
            Number(targetDimensions?.width || 0);

        const targetHeight =
            Number(targetDimensions?.height || 0);

        const initiatorWidth =
            Number(initiatorDimensions?.width || targetWidth);

        const initiatorHeight =
            Number(initiatorDimensions?.height || targetHeight);

        const targetX =
            Number(target.position_x) * stageWidth;

        const y =
            Number(target.position_y) * stageHeight;

        const orientation =
            metadata?.orientation || "right";

        const direction =
            orientation === "left"
                ? -1
                : 1;

        const initiatorX =
            direction > 0
                ? targetX + targetWidth + gap
                : targetX - initiatorWidth - gap;

        const bounds =
            this.#relationshipBounds([
                {
                    x:
                        targetX,
                    y,
                    width:
                        targetWidth,
                    height:
                        targetHeight
                },
                {
                    x:
                        initiatorX,
                    y,
                    width:
                        initiatorWidth,
                    height:
                        initiatorHeight
                }
            ]);

        const translation =
            this.#clampTranslation(
                bounds,
                stageWidth,
                stageHeight
            );

        target.position_x =
            (targetX + translation.x) / stageWidth;

        target.position_y =
            (y + translation.y) / stageHeight;

        initiator.position_x =
            (initiatorX + translation.x) / stageWidth;

        initiator.position_y =
            (y + translation.y) / stageHeight;

        this.#layoutCount += 1;

        return [
            target,
            initiator
        ];

    }

    /**
     * Applies anchor-pair relationship geometry.
     *
     * @param {Object} options
     *
     * @returns {Object[]}
     */
    #applyAnchorPairStrategy({
        initiator,
        target,
        stageWidth,
        stageHeight,
        primaryDimensions,
        lapDimensions,
        metadata,
        locked = false
    }) {

        if (locked || !initiator || !target) {
            return [];
        }

        const primaryWidth =
            Number(primaryDimensions?.width || 0);

        const primaryHeight =
            Number(primaryDimensions?.height || primaryWidth);

        const lapWidth =
            Number(lapDimensions?.width || 0);

        const lapHeight =
            Number(lapDimensions?.height || lapWidth);

        const offsets =
            this.#anchorPairOffsets(
                metadata,
                {
                    primaryWidth,
                    primaryHeight,
                    lapWidth,
                    lapHeight
                }
            );

        const targetX =
            Number(target.position_x) * stageWidth;

        const targetY =
            Number(target.position_y) * stageHeight;

        const bounds =
            this.#relationshipBounds([
                {
                    x:
                        targetX,
                    y:
                        targetY,
                    width:
                        primaryWidth,
                    height:
                        primaryHeight
                },
                {
                    x:
                        targetX + offsets.x,
                    y:
                        targetY + offsets.y,
                    width:
                        lapWidth,
                    height:
                        lapHeight
                }
            ]);

        const translation =
            this.#clampTranslation(
                bounds,
                stageWidth,
                stageHeight
            );

        const baseX =
            targetX + translation.x;

        const baseY =
            targetY + translation.y;

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
                (baseX + offsets.x) / stageWidth;

            initiator.position_y =
                (baseY + offsets.y) / stageHeight;

            changed.push(initiator);

        }

        this.#layoutCount += 1;

        return changed;

    }

    /**
     * Resolves anchor-pair offsets from metadata or fallback geometry.
     *
     * @param {Object} metadata
     * @param {Object} dimensions
     *
     * @returns {Object}
     */
    #anchorPairOffsets(metadata, dimensions) {

        const anchor =
            this.#anchorMetadata(metadata, "initiator") ||
            this.#anchorMetadata(metadata, "target") ||
            this.#anchorMetadata(metadata, "mode") ||
            {};

        const normalizedOffset =
            anchor.normalizedOffset ||
            anchor.offset ||
            {};

        const pixelOffset =
            anchor.pixelOffset ||
            {};

        const xRatio =
            Number.isFinite(Number(normalizedOffset.x))
                ? Number(normalizedOffset.x)
                : 0.5;

        const yRatio =
            Number.isFinite(Number(normalizedOffset.y))
                ? Number(normalizedOffset.y)
                : 65 / 150;

        const orientation =
            metadata?.orientation || "";

        const mirroredX =
            orientation.includes("left");

        const mirroredY =
            orientation.includes("top");

        const x =
            mirroredX
                ? dimensions.primaryWidth * (1 - xRatio) -
                    dimensions.lapWidth +
                    Number(pixelOffset.x || 0)
                : dimensions.primaryWidth * xRatio +
                    Number(pixelOffset.x || 0);

        const y =
            mirroredY
                ? dimensions.primaryHeight * (1 - yRatio) -
                    dimensions.lapHeight +
                    Number(pixelOffset.y || 0)
                : dimensions.primaryHeight * yRatio +
                    Number(pixelOffset.y || 0);

        return Object.freeze({
            x,
            y
        });

    }

    /**
     * Returns optional anchor metadata by role or mode.
     *
     * @param {Object} metadata
     * @param {string} key
     *
     * @returns {Object|null}
     */
    #anchorMetadata(metadata, key) {

        if (!metadata?.anchors) {
            return null;
        }

        if (key === "mode") {
            return metadata.anchors.mode?.[metadata.mode] || null;
        }

        const member =
            (metadata.members || [])
                .find(candidate => candidate.role === key);

        return member
            ? metadata.anchors.members?.[member.participantId] || member.anchor || null
            : null;

    }

    /**
     * Returns relationship bounds for rendered member boxes.
     *
     * @param {Object[]} boxes
     *
     * @returns {Object}
     */
    #relationshipBounds(boxes) {

        const active =
            boxes.filter(Boolean);

        const left =
            Math.min(...active.map(box => box.x));

        const top =
            Math.min(...active.map(box => box.y));

        const right =
            Math.max(...active.map(box => box.x + box.width));

        const bottom =
            Math.max(...active.map(box => box.y + box.height));

        return Object.freeze({
            left,
            top,
            right,
            bottom
        });

    }

    /**
     * Returns translation required to keep bounds inside the stage.
     *
     * @param {Object} bounds
     * @param {number} stageWidth
     * @param {number} stageHeight
     *
     * @returns {Object}
     */
    #clampTranslation(bounds, stageWidth, stageHeight) {

        let x = 0;
        let y = 0;

        if (bounds.left < 0) {
            x = -bounds.left;
        } else if (bounds.right > stageWidth) {
            x = stageWidth - bounds.right;
        }

        if (bounds.top < 0) {
            y = -bounds.top;
        } else if (bounds.bottom > stageHeight) {
            y = stageHeight - bounds.bottom;
        }

        return Object.freeze({ x, y });

    }

    /**
     * Returns relationship participants mapped by normalized metadata roles.
     *
     * @param {Object} metadata
     * @param {Object} initiator
     * @param {Object} target
     *
     * @returns {Object}
     */
    #participantsForMetadata(metadata, initiator, target) {

        const participants =
            new Map([
                [
                    Number(initiator?.id),
                    initiator
                ],
                [
                    Number(target?.id),
                    target
                ]
            ]);

        const initiatorMember =
            (metadata.members || [])
                .find(member => member.role === "initiator");

        const targetMember =
            (metadata.members || [])
                .find(member => member.role === "target");

        return Object.freeze({

            initiator:
                participants.get(Number(initiatorMember?.participantId)) ||
                initiator,

            target:
                participants.get(Number(targetMember?.participantId)) ||
                target

        });

    }

    /**
     * Returns legacy two-member relationship metadata input.
     *
     * @param {Object} initiator
     * @param {Object} target
     *
     * @returns {Object[]}
     */
    #legacyMembers(initiator, target) {

        return [
            {
                participantId:
                    Number(initiator?.id),
                role:
                    "initiator",
                order:
                    0
            },
            {
                participantId:
                    Number(target?.id),
                role:
                    "target",
                order:
                    1
            }
        ];

    }

}

export default AvatarLayoutService;
