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
 *      000044 Part 3
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
 *
 * Build 000044 Part 3
 * - Added ordered mixed-size group layout, host-attached lap geometry, and
 *   whole-group drag translation/clamping.
 ******************************************************************************/

/**
 * @file avatar-layout-service.js
 *
 * Defines the Avatar Layout Service.
 */

import { resolveAvatarGroupLayout } from "./avatar-group-layout-policy.js";

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
     * @param {string} [options.lapSide="bottom-right"]
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
        lapSide = "bottom-right",
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
            metadata: {
                ...this.#relationships.normalizeRelationshipMetadata(
                    {},
                    {
                        mode:
                            "lap",
                        members:
                            this.#legacyMembers(initiator, target)
                    }
                ),
                orientation: lapSide === "bottom-left" ? "bottom-left" : "bottom-right"
            },
            locked
        });

    }

    /**
     * Applies one ordered, mixed-size relationship-group layout.
     *
     * @param {Object} options
     * @param {Object[]} options.normalMembers
     * @param {Object[]} options.lapAttachments
     * @param {number} options.stageWidth
     * @param {number} options.stageHeight
     * @param {number} [options.gap=0]
     * @param {Object} [options.metadata]
     * @param {Object|null} [options.anchor]
     * @param {boolean} [options.locked=false]
     * @param {boolean} [options.apply=true]
     *
     * @returns {Object[]|Object}
     */
    applyRelationshipGroupLayout({
        normalMembers = [],
        lapAttachments = [],
        stageWidth,
        stageHeight,
        gap = 0,
        metadata = null,
        anchor = null,
        formation = "horizontal-row",
        locked = false,
        apply = true
    }) {

        const width = Number(stageWidth || 0);
        const height = Number(stageHeight || 0);
        const normalGap = Math.max(0, Number(gap || 0));
        const normals = Array.from(normalMembers || [])
            .filter(entry => entry?.participant && entry?.dimensions);

        if (locked || !normals.length || width <= 0 || height <= 0) {
            return [];
        }

        const anchorX = Number(
            anchor?.x ?? normals[0].participant.position_x ?? 0
        ) * width;
        const anchorY = Number(
            anchor?.y ?? normals[0].participant.position_y ?? 0
        ) * height;
        const attachmentsByHost = new Map();
        Array.from(lapAttachments || []).forEach(attachment => {
            const hostParticipantId = Number(attachment?.hostParticipantId || 0);
            const lapSide = String(attachment?.lapSide || "");
            if (!attachment?.participant || !attachment?.dimensions || !hostParticipantId
                || !["bottom-left", "bottom-right"].includes(lapSide)) {
                return;
            }
            const list = attachmentsByHost.get(hostParticipantId) || [];
            if (list.some(item => item.lapSide === lapSide)) return;
            list.push({ ...attachment, lapSide });
            attachmentsByHost.set(hostParticipantId, list);
        });

        const hostUnits = normals.map(entry => {
            const memberWidth = Math.max(1, Number(entry.dimensions.width || 0));
            const memberHeight = Math.max(1, Number(entry.dimensions.height || 0));
            const attachments = attachmentsByHost.get(Number(entry.participant.id)) || [];
            const relativeLapBoxes = attachments.map(attachment => {
                const lapWidth = Math.max(1, Number(attachment.dimensions.width || 0));
                const lapHeight = Math.max(1, Number(attachment.dimensions.height || 0));
                const offsets = this.#anchorPairOffsets(
                    {
                        ...(metadata || {}),
                        mode: "lap",
                        orientation: attachment.lapSide,
                        members: [
                            {
                                participantId: Number(attachment.participant.id),
                                role: "initiator",
                                anchor: attachment.anchor || null
                            },
                            {
                                participantId: Number(entry.participant.id),
                                role: "target"
                            }
                        ]
                    },
                    {
                        primaryWidth: memberWidth,
                        primaryHeight: memberHeight,
                        lapWidth,
                        lapHeight
                    }
                );
                return {
                    entry: attachment,
                    x: offsets.x,
                    y: offsets.y,
                    width: lapWidth,
                    height: lapHeight,
                    visualBounds: {
                        x: offsets.x + Number(attachment.presentationEnvelope?.offsetX || 0),
                        y: offsets.y + Number(attachment.presentationEnvelope?.offsetY || 0),
                        width: Math.max(1, Number(attachment.presentationEnvelope?.width || lapWidth)),
                        height: Math.max(1, Number(attachment.presentationEnvelope?.height || lapHeight))
                    }
                };
            });
            const unitBounds = this.#relationshipBounds([
                { x: 0, y: 0, width: memberWidth, height: memberHeight },
                ...relativeLapBoxes.map(box => box.visualBounds)
            ]);
            return {
                entry,
                width: memberWidth,
                height: memberHeight,
                relativeLapBoxes,
                bounds: unitBounds
            };
        });

        const formationResult = this.#formations.layout({
            selectedFormation: formation,
            units: hostUnits.map(unit => ({
                participantId: Number(unit.entry.participant.id),
                width: unit.width,
                height: unit.height,
                bounds: unit.bounds
            })),
            anchor: { x: anchorX, y: anchorY },
            rowSpacing: normalGap
        });
        const layoutResolution = resolveAvatarGroupLayout({
            units: hostUnits.map(unit => ({
                participantId: Number(unit.entry.participant.id),
                bounds: unit.bounds
            })),
            basePlacements: formationResult.placements,
            stageWidth: width,
            stageHeight: height,
            rowSpacing: normalGap,
            anchor: { x: anchorX, y: anchorY },
            allowCanvasExpansion: true
        });
        const resolvedWidth = Math.ceil(
            Math.max(width, Number(layoutResolution.canvasWidth || 0))
        );
        const resolvedHeight = Math.ceil(
            Math.max(height, Number(layoutResolution.canvasHeight || 0))
        );
        const placementByParticipantId = new Map(
            layoutResolution.placements.map(placement => [placement.participantId, placement])
        );
        const normalBoxes = [];
        const lapBoxes = [];
        const lapVisualBoxes = [];

        hostUnits.forEach(unit => {
            const placement = placementByParticipantId.get(Number(unit.entry.participant.id));
            const hostBox = {
                entry: unit.entry,
                x: placement.x,
                y: placement.y,
                width: unit.width,
                height: unit.height
            };
            normalBoxes.push(hostBox);
            unit.relativeLapBoxes.forEach(box => {
                lapBoxes.push({
                    ...box,
                    x: placement.x + box.x,
                    y: placement.y + box.y
                });
                lapVisualBoxes.push({
                    entry: box.entry,
                    x: placement.x + box.visualBounds.x,
                    y: placement.y + box.visualBounds.y,
                    width: box.visualBounds.width,
                    height: box.visualBounds.height
                });
            });
        });

        const allBoxes = [...normalBoxes, ...lapBoxes];
        const bounds = this.#relationshipBounds([...normalBoxes, ...lapVisualBoxes]);
        const translation = layoutResolution.valid
            ? Object.freeze({ x: 0, y: 0 })
            : this.#clampTranslation(bounds, width, height);
        const logicalCanvas = Object.freeze({
            width: resolvedWidth,
            height: resolvedHeight,
            expanded: resolvedWidth > width || resolvedHeight > height
        });
        if (!apply) {
            return Object.freeze({
                valid: Boolean(layoutResolution.valid),
                layoutMode: layoutResolution.mode,
                rowCount: layoutResolution.rowCount,
                columnCount: layoutResolution.columnCount,
                logicalCanvas,
                policyDiagnostics: layoutResolution.diagnostics || null,
                bounds: Object.freeze({
                    left: bounds.left,
                    top: bounds.top,
                    right: bounds.right,
                    bottom: bounds.bottom,
                    width: bounds.right - bounds.left,
                    height: bounds.bottom - bounds.top
                })
            });
        }
        const changed = [];

        allBoxes.forEach(box => {

            const participant = box.entry.participant;

            if (participant._lockedPosition) {
                return;
            }

            participant.position_x = (box.x + translation.x) / resolvedWidth;
            participant.position_y = (box.y + translation.y) / resolvedHeight;
            changed.push(participant);

        });

        this.#lastRelationshipStrategy = Object.freeze({
            strategy: formationResult.effective,
            selectedFormation: formationResult.selected,
            effectiveFormation: formationResult.effective,
            fallbackReason: formationResult.fallbackReason,
            layoutMode: layoutResolution.mode,
            fitValid: layoutResolution.valid,
            rowCount: layoutResolution.rowCount,
            columnCount: layoutResolution.columnCount,
            wholeFormationTranslation: layoutResolution.translation,
            logicalCanvas,
            policyDiagnostics: layoutResolution.diagnostics || null,
            mode: String(metadata?.mode || "normal"),
            memberCount: allBoxes.length,
            normalMemberCount: normalBoxes.length,
            lapMemberCount: lapBoxes.length,
            mixedSize: new Set(
                allBoxes.map(box => `${box.width}x${box.height}`)
            ).size > 1,
            bounds: Object.freeze({
                left: bounds.left,
                top: bounds.top,
                right: bounds.right,
                bottom: bounds.bottom,
                width: bounds.right - bounds.left,
                height: bounds.bottom - bounds.top
            })
        });
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
     * Translates one captured relationship group while preserving every
     * member's relative offset and clamping the complete mixed-size group.
     *
     * @param {Object} options
     * @param {Object[]} options.members
     * @param {number|string} options.actorParticipantId
     * @param {number} options.desiredX
     * @param {number} options.desiredY
     * @param {number} options.stageWidth
     * @param {number} options.stageHeight
     *
     * @returns {Object[]}
     */
    translateRelationshipGroup({
        members = [],
        actorParticipantId,
        desiredX,
        desiredY,
        stageWidth,
        stageHeight
    }) {

        const width = Number(stageWidth || 0);
        const height = Number(stageHeight || 0);
        const entries = Array.from(members || [])
            .filter(entry => entry?.participant && entry?.dimensions);
        const actor = entries.find(entry =>
            Number(entry.participant.id) === Number(actorParticipantId)
        );

        if (!actor || !entries.length || width <= 0 || height <= 0) {
            return [];
        }

        const deltaX = (Number(desiredX || 0) - Number(actor.originX || 0)) * width;
        const deltaY = (Number(desiredY || 0) - Number(actor.originY || 0)) * height;
        const boxes = entries.map(entry => ({
            entry,
            x: Number(entry.originX || 0) * width + deltaX,
            y: Number(entry.originY || 0) * height + deltaY,
            width: Math.max(1, Number(entry.dimensions.width || 0)),
            height: Math.max(1, Number(entry.dimensions.height || 0))
        }));
        const bounds = this.#relationshipBounds(boxes);
        const translation = this.#clampTranslation(bounds, width, height);
        const changed = [];

        boxes.forEach(box => {
            const participant = box.entry.participant;
            if (participant._lockedPosition) return;
            participant.position_x = (box.x + translation.x) / width;
            participant.position_y = (box.y + translation.y) / height;
            changed.push(participant);
        });

        this.#lastRelationshipStrategy = Object.freeze({
            strategy: "relationshipGroupTranslation",
            memberCount: boxes.length,
            mixedSize: new Set(boxes.map(box => `${box.width}x${box.height}`)).size > 1,
            bounds: Object.freeze({
                width: bounds.right - bounds.left,
                height: bounds.bottom - bounds.top
            })
        });
        this.#layoutCount += 1;

        return changed;

    }

    /**
     * Clamps one shared dance offset against the complete visible group.
     */
    constrainRelationshipDanceOffset({
        participants = [],
        requestedOffset = null,
        stageWidth,
        stageHeight
    } = {}) {

        const width = Number(stageWidth || 0);
        const height = Number(stageHeight || 0);
        const boxes = Array.from(participants || []).map(participant => {
            const frame = this.#runtime.renderer?.renderedAvatarFrame?.(participant);
            if (frame) return frame;
            const dimensions = this.#runtime.renderer?.renderedAvatarDimensions(participant);
            return dimensions ? {
                x: Number(participant.position_x || 0) * width,
                y: Number(participant.position_y || 0) * height,
                width: Math.max(1, Number(dimensions.width || 0)),
                height: Math.max(1, Number(dimensions.height || 0))
            } : null;
        }).filter(Boolean);

        if (!boxes.length || width <= 0 || height <= 0) {
            return Object.freeze({ x: 0, y: 0 });
        }

        const bounds = this.#relationshipBounds(boxes);
        const requestedX = Number(requestedOffset?.x || 0);
        const requestedY = Number(requestedOffset?.y || 0);
        const x = Math.max(-bounds.left, Math.min(width - bounds.right, requestedX));
        const y = Math.max(-bounds.top, Math.min(height - bounds.bottom, requestedY));

        return Object.freeze({
            x: Number.isFinite(x) && x !== 0 ? x : 0,
            y: Number.isFinite(y) && y !== 0 ? y : 0
        });

    }

    /**
     * Resolves one Lap Dance/Bounce static envelope from authoritative rendered
     * dimensions and the same lap anchor used by relationship layout.
     */
    lapAnimationGeometry({
        mode,
        hostDimensions,
        occupantDimensions,
        lapSide,
        anchor = null,
        danceStrategy = null,
        bounceStrategy = null
    } = {}) {

        const hostWidth = Math.max(1, Number(hostDimensions?.width || 0));
        const hostHeight = Math.max(1, Number(hostDimensions?.height || 0));
        const occupantWidth = Math.max(1, Number(occupantDimensions?.width || 0));
        const occupantHeight = Math.max(1, Number(occupantDimensions?.height || 0));
        if (!["bottom-left", "bottom-right"].includes(String(lapSide || ""))) return null;
        const offsets = this.#anchorPairOffsets({
            mode: "lap",
            orientation: lapSide,
            anchors: {},
            members: [
                { role: "initiator", anchor },
                { role: "target" }
            ]
        }, {
            primaryWidth: hostWidth,
            primaryHeight: hostHeight,
            lapWidth: occupantWidth,
            lapHeight: occupantHeight
        });

        if (mode === "lap_dance" && danceStrategy?.envelope) {
            const envelope = danceStrategy.envelope({ width: occupantWidth, height: occupantHeight });
            return Object.freeze({
                mode,
                baseline: Object.freeze({ x: offsets.x, y: offsets.y }),
                effectiveRisePx: 0,
                envelope
            });
        }
        if (mode !== "lap_bounce" || !bounceStrategy?.envelope) return null;
        const centerClearance = offsets.y + occupantHeight / 2 - hostHeight / 2;
        const headClearance = offsets.y - hostHeight * Number(bounceStrategy.protectedHostFraction || 0.25);
        const effectiveRisePx = Math.floor(Math.min(
            Number(bounceStrategy.preferredRisePx || 14),
            centerClearance,
            headClearance
        ));
        if (effectiveRisePx <= 0) return null;
        return Object.freeze({
            mode,
            baseline: Object.freeze({ x: offsets.x, y: offsets.y }),
            effectiveRisePx,
            centerClearance,
            headClearance,
            envelope: bounceStrategy.envelope({
                width: occupantWidth,
                height: occupantHeight,
                effectiveRisePx
            })
        });

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

    /**
     * Restores relationship-departed avatars to deterministic independent
     * positions without changing their authoritative rendered dimensions.
     *
     * @param {Object} options
     * @returns {Object[]}
     */
    restoreIndependentLayout({
        participants = [],
        occupiedParticipants = [],
        stageWidth,
        stageHeight,
        gap = 12
    } = {}) {

        const width = Number(stageWidth || 0);
        const height = Number(stageHeight || 0);
        const spacing = Math.max(0, Number(gap || 0));
        const moving = Array.from(participants || [])
            .filter(Boolean)
            .sort((first, second) => Number(first.id) - Number(second.id));

        if (!moving.length || width <= 0 || height <= 0) {
            return [];
        }

        const dimensionsFor = participant => {
            const dimensions = this.#runtime.renderer?.renderedAvatarDimensions(participant) || {};
            return {
                width: Math.max(1, Number(dimensions.width || 1)),
                height: Math.max(1, Number(dimensions.height || 1))
            };
        };
        const boxFor = participant => {
            const dimensions = dimensionsFor(participant);
            return {
                x: Math.max(0, Math.min(width - dimensions.width, Number(participant.position_x || 0) * width)),
                y: Math.max(0, Math.min(height - dimensions.height, Number(participant.position_y || 0) * height)),
                ...dimensions
            };
        };
        const overlaps = (candidate, box) => !(
            candidate.x + candidate.width + spacing <= box.x ||
            box.x + box.width + spacing <= candidate.x ||
            candidate.y + candidate.height + spacing <= box.y ||
            box.y + box.height + spacing <= candidate.y
        );
        const movingIds = new Set(moving.map(participant => Number(participant.id)));
        const occupied = Array.from(occupiedParticipants || [])
            .filter(participant => participant && !movingIds.has(Number(participant.id)))
            .map(boxFor);

        moving.forEach(participant => {
            const origin = boxFor(participant);
            const candidates = [origin];

            occupied.forEach(box => {
                candidates.push(
                    { ...origin, x: box.x + box.width + spacing },
                    { ...origin, x: box.x - origin.width - spacing },
                    { ...origin, y: box.y + box.height + spacing },
                    { ...origin, y: box.y - origin.height - spacing }
                );
            });
            for (let ring = 1; ring <= 12; ring += 1) {
                const distance = ring * 24;
                candidates.push(
                    { ...origin, x: origin.x + distance },
                    { ...origin, x: origin.x - distance },
                    { ...origin, y: origin.y + distance },
                    { ...origin, y: origin.y - distance }
                );
            }

            const selected = candidates
                .map(candidate => ({
                    ...candidate,
                    x: Math.max(0, Math.min(width - candidate.width, candidate.x)),
                    y: Math.max(0, Math.min(height - candidate.height, candidate.y))
                }))
                .filter(candidate => !occupied.some(box => overlaps(candidate, box)))
                .sort((first, second) => {
                    const firstDistance = Math.hypot(first.x - origin.x, first.y - origin.y);
                    const secondDistance = Math.hypot(second.x - origin.x, second.y - origin.y);
                    return firstDistance - secondDistance || first.y - second.y || first.x - second.x;
                })[0] || origin;

            participant.position_x = selected.x / width;
            participant.position_y = selected.y / height;
            occupied.push(selected);
        });

        this.#layoutCount += moving.length;
        return moving;

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

    /**
     * Returns the formation strategy owner.
     */
    get #formations() {

        return this.#runtime.formations;

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
