/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-coordinator.js
 *
 * Layer:
 *      Coordinator
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns coordination between avatar runtime components.
 *
 *      AvatarCoordinator owns avatar relationship lifecycle sequencing. It
 *      coordinates relationship graph changes, layout refreshes, renderer
 *      synchronization callbacks, host persistence callbacks, and relationship
 *      lifecycle state while leaving graph rules, geometry, rendering, host UI,
 *      networking, and ChatRuntime behavior with their documented owners.
 *
 * Build:
 *      000038
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000012
 * - Introduced Avatar Coordinator.
 * - Established coordination ownership.
 * - No coordination behavior migrated.
 *
 * Build 000023
 * - Transferred avatar relationship lifecycle sequencing from room.js.
 * - Added linked group cache ownership.
 * - Added relationship lifecycle persistence callback coordination.
 *
 * Build 000032
 * - Routed relationship layout through authoritative rendered avatar
 *   dimensions.
 *
 * Build 000034
 * - Routed lap layout selection through AvatarRelationshipService capability
 *   checks.
 *
 * Build 000035
 * - Delegated relationship geometry strategy selection to AvatarLayoutService.
 *
 * Build 000036
 * - Added authoritative relationship refresh scheduling, reason diagnostics,
 *   and resize/rendered-size stability orchestration.
 *
 * Build 000037
 * - Consumed runtime relationship identity objects during relationship
 *   refresh sequencing.
 *
 * Build 000038
 * - Reconciled persisted relationship payloads from remote link events.
 *
 * Build 000044 Part 1
 * - Added authoritative eligibility consumption and pending-choice lifecycle.
 * - Changed link completion to commit local state only after server acceptance.
 ******************************************************************************/

/**
 * @file avatar-coordinator.js
 *
 * Defines the Avatar Coordinator.
 */

//
// No imports required.
//

//--------------------------------------------------
// Constants
//--------------------------------------------------

const DEFAULT_AVATAR_SIZE = 150;
const DEFAULT_AVATAR_VISUAL_MAX_SIZE = 200;
const DEFAULT_LINK_GAP = 0;
const LINK_CHOICE_SUPPRESS_MS = 400;

//--------------------------------------------------
// Avatar Coordinator
//--------------------------------------------------

/**
 * Owns coordination between avatar runtime components.
 *
 * AvatarCoordinator is owned exclusively by AvatarRuntime.
 */
export class AvatarCoordinator {

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
     * Runtime-owned linked group cache.
     *
     * @type {Map<number, Object[]>}
     */
    #linkGroups = new Map();

    /**
     * Relationship icon metadata keyed by relationship key.
     *
     * @type {Map<string, string>}
     */
    #linkIcons = new Map();

    /**
     * Pending link choice state.
     *
     * @type {Object|null}
     */
    #pendingLinkChoice = null;

    /**
     * Participant ids temporarily suppressed from opening another link choice.
     *
     * @type {Set<number>}
     */
    #suppressedLinkChoiceParticipantIds = new Set();

    /**
     * Suppression timers keyed by participant id.
     *
     * @type {Map<number, *>}
     */
    #linkChoiceSuppressionTimers = new Map();

    /**
     * Count of eligibility decisions by reason.
     *
     * @type {Map<string, number>}
     */
    #relationshipEligibilityReasonCounts = new Map();

    /**
     * Count of pending-choice invalidations.
     *
     * @type {number}
     */
    #pendingLinkChoiceInvalidationCount = 0;

    /**
     * Relationship lifecycle operation count.
     *
     * @type {number}
     */
    #lifecycleOperationCount = 0;

    /**
     * Count of relationship refresh operations.
     *
     * @type {number}
     */
    #relationshipRefreshCount = 0;

    /**
     * Relationship refresh counts by reason.
     *
     * @type {Map<string, number>}
     */
    #relationshipRefreshReasonCounts = new Map();

    /**
     * Last relationship refresh diagnostic state.
     *
     * @type {Object|null}
     */
    #lastRelationshipRefresh = null;

    /**
     * Pending scheduled relationship refresh frame handle.
     *
     * @type {*}
     */
    #scheduledRelationshipRefresh = null;

    /**
     * Participant ids queued for relationship refresh.
     *
     * @type {Set<number>}
     */
    #scheduledRelationshipRefreshParticipantIds = new Set();

    /**
     * Reasons queued for the scheduled relationship refresh.
     *
     * @type {Set<string>}
     */
    #scheduledRelationshipRefreshReasons = new Set();

    /**
     * Whether the next scheduled refresh should refresh every relationship.
     *
     * @type {boolean}
     */
    #scheduledRelationshipRefreshAll = false;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Coordinator.
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
     * Initializes the coordinator.
     */
    initialize() {

    }

    /**
     * Releases resources owned by the coordinator.
     */
    destroy() {

        this.cancelPendingLinkChoice("runtime-destroy");
        this.#linkChoiceSuppressionTimers.forEach(timer => clearTimeout(timer));
        this.#linkChoiceSuppressionTimers.clear();
        this.#context = null;
        this.#linkGroups.clear();
        this.#linkIcons.clear();
        this.#pendingLinkChoice = null;
        this.#suppressedLinkChoiceParticipantIds.clear();
        this.#relationshipEligibilityReasonCounts.clear();
        this.#scheduledRelationshipRefresh = null;
        this.#scheduledRelationshipRefreshAll = false;
        this.#scheduledRelationshipRefreshParticipantIds.clear();
        this.#scheduledRelationshipRefreshReasons.clear();

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
     * Configures host relationship lifecycle callbacks.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Relationship Metadata API
    //--------------------------------------------------

    /**
     * Seeds relationship icon metadata.
     *
     * @param {Object|Map} icons
     */
    seedLinkIcons(icons = {}) {

        this.#linkIcons.clear();

        const entries =
            icons instanceof Map
                ? icons.entries()
                : Object.entries(icons || {});

        for (const [key, icon] of entries) {
            this.#linkIcons.set(
                String(key),
                icon || "plus"
            );
        }

    }

    /**
     * Sets relationship icon metadata.
     *
     * @param {string} linkKey
     * @param {string} iconName
     */
    setLinkIcon(linkKey, iconName = "plus") {

        this.#linkIcons.set(
            String(linkKey || ""),
            iconName || "plus"
        );

    }

    /**
     * Returns relationship icon metadata.
     *
     * @param {string} linkKey
     *
     * @returns {string}
     */
    linkIconName(linkKey) {

        return this.#linkIcons.get(String(linkKey || "")) || "plus";

    }

    /**
     * Returns the stage icon name for a relationship.
     *
     * @param {string} linkKey
     *
     * @returns {string}
     */
    linkIconNameForStage(linkKey) {

        const iconName =
            this.linkIconName(linkKey);

        return (iconName === "none" || iconName === "plus")
            ? ""
            : iconName;

    }

    /**
     * Returns the list icon name for a relationship.
     *
     * @param {string} linkKey
     *
     * @returns {string}
     */
    linkIconNameForList(linkKey) {

        const iconName =
            this.linkIconName(linkKey);

        return iconName === "none"
            ? "plus"
            : iconName;

    }

    /**
     * Returns spacing required by relationship icon presentation.
     *
     * @param {string} linkKey
     *
     * @returns {number}
     */
    linkPairGap(linkKey) {

        return this.linkIconNameForStage(linkKey)
            ? DEFAULT_LINK_GAP
            : 0;

    }

    //--------------------------------------------------
    // Public Linked Group API
    //--------------------------------------------------

    /**
     * Rebuilds the linked group cache from relationship state.
     *
     * @returns {Map<number, Object[]>}
     */
    rebuildLinkGroups() {

        this.#linkGroups =
            this.#relationships.rebuildLinkGroups();

        return this.linkGroups();

    }

    /**
     * Returns the linked group cache.
     *
     * @returns {Map<number, Object[]>}
     */
    linkGroups() {

        return new Map(this.#linkGroups);

    }

    /**
     * Returns the linked group containing a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object[]|null}
     */
    linkedGroupForParticipant(participantId) {

        const id =
            Number(participantId);

        for (const group of this.#linkGroups.values()) {

            if (group.some(member => Number(member.id) === id)) {
                return group;
            }

        }

        return null;

    }

    //--------------------------------------------------
    // Public Relationship Lifecycle API
    //--------------------------------------------------

    /**
     * Stores a pending link choice.
     *
     * @param {Object} initiator
     * @param {Object} target
     *
     * @returns {Object|null}
     */
    beginLinkChoice(initiator, target, eligibility = null) {

        const decision = eligibility || this.relationshipEligibility(initiator, target);

        if (!decision.allowed) {
            this.cancelPendingLinkChoice(decision.reason);
            return null;
        }

        this.#pendingLinkChoice = Object.freeze({

            initiatorId:
                Number(initiator.id),

            targetId:
                Number(target.id),

            allowedModes:
                Object.freeze(Array.from(decision.allowedModes || [])),

            stateFingerprint:
                decision.stateFingerprint,

            createdAt:
                Date.now()

        });

        return this.#pendingLinkChoice;

    }

    /**
     * Returns the authoritative relationship eligibility decision.
     *
     * @param {Object|number|string} initiator
     * @param {Object|number|string} target
     *
     * @returns {Object}
     */
    relationshipEligibility(initiator, target) {

        const decision = this.#relationships.relationshipEligibility(
            initiator,
            target,
            {
                isBlocked: (first, second) =>
                    Boolean(this.#context?.isRelationshipBlocked?.(first, second)),
                isAvailable: participant =>
                    participant?.online !== false && !participant?.exiting
            }
        );

        this.#recordEligibilityDiagnostic(decision, "evaluate");

        return decision;

    }

    /**
     * Cancels the current pending relationship choice.
     *
     * @param {string} reason
     * @param {Object} options
     *
     * @returns {boolean}
     */
    cancelPendingLinkChoice(reason = "cancelled", options = {}) {

        if (!this.#pendingLinkChoice) {
            if (options.close !== false) {
                this.#context?.closeLinkChoiceModal?.();
            }
            return false;
        }

        const pending = this.#pendingLinkChoice;
        this.#pendingLinkChoice = null;

        if (options.close !== false) {
            this.#context?.closeLinkChoiceModal?.();
        }

        if (options.warning) {
            this.#context?.showWarning?.(options.warning);
        }

        if (reason !== "cancelled" && reason !== "user-cancel") {
            this.#pendingLinkChoiceInvalidationCount += 1;
        }

        this.#recordEligibilityDiagnostic({
            reason,
            initiatorParticipantId: pending.initiatorId,
            targetParticipantId: pending.targetId,
            allowed: false
        }, "pending-choice-closed");

        return true;

    }

    /**
     * Invalidates a pending choice when participant or relationship state
     * changes.
     *
     * @param {string} reason
     * @param {Array<number|string>} participantIds
     *
     * @returns {boolean}
     */
    invalidatePendingLinkChoice(reason, participantIds = []) {

        const pending = this.#pendingLinkChoice;

        if (!pending) {
            return false;
        }

        const changedIds = new Set(
            Array.from(participantIds || []).map(id => Number(id)).filter(Boolean)
        );

        const explicitlyAffected = changedIds.size > 0 && (
            changedIds.has(pending.initiatorId) || changedIds.has(pending.targetId)
        );

        const decision = this.relationshipEligibility(
            pending.initiatorId,
            pending.targetId
        );

        if (
            !explicitlyAffected &&
            decision.allowed &&
            decision.stateFingerprint === pending.stateFingerprint
        ) {
            return false;
        }

        return this.cancelPendingLinkChoice(reason || decision.reason, {
            warning: "That relationship choice is no longer available."
        });

    }

    /**
     * Completes the pending link choice.
     *
     * @param {string} mode
     *
     * @returns {Promise<boolean>}
     */
    async completePendingLinkChoice(mode) {

        const pending =
            this.#pendingLinkChoice;

        if (!pending) {
            return false;
        }

        const initiator =
            this.#participant(pending.initiatorId);

        const target =
            this.#participant(pending.targetId);

        if (mode === "cancel") {
            this.cancelPendingLinkChoice("user-cancel");
            if (!initiator) {
                return false;
            }
            await this.#context?.persistPosition?.(initiator);
            return true;
        }

        const decision = this.relationshipEligibility(initiator, target);

        if (
            !decision.allowed ||
            decision.stateFingerprint !== pending.stateFingerprint ||
            !pending.allowedModes.includes(mode)
        ) {
            this.cancelPendingLinkChoice(decision.reason, {
                warning: "That relationship choice is no longer available."
            });
            return false;
        }

        const linkMode =
            this.#relationships.normalizeLinkMode(mode);

        this.cancelPendingLinkChoice("submitted");

        try {

            const response = await this.#context?.persistLink?.({

                initiator,
                target,
                linkMode

            });

            const freshInitiator = this.#participant(pending.initiatorId);
            const freshTarget = this.#participant(pending.targetId);

            if (!freshInitiator || !freshTarget) {
                return false;
            }

            const existingPair = this.#relationships.relationshipForPair(
                freshInitiator,
                freshTarget
            );

            if (!existingPair) {
                const postRequestDecision = this.relationshipEligibility(
                    freshInitiator,
                    freshTarget
                );

                if (!postRequestDecision.allowed) {
                    this.#context?.showWarning?.(
                        "The relationship changed before it could be displayed."
                    );
                    return false;
                }

                this.#relationships.link(
                    freshInitiator.id,
                    freshTarget.id,
                    linkMode
                );
            }

            if (response?.relationship) {
                this.#relationships.upsertPersistedRelationship(response.relationship);
            }

            this.refreshRelationship(
                freshInitiator,
                freshTarget,
                {
                    animate: true,
                    persist: false,
                    reason: "local-link-accepted"
                }
            );

            this.#renderParticipants([
                freshTarget,
                freshInitiator
            ]);

            this.#syncRelationshipPresentation();

            this.#lifecycleOperationCount += 1;

            return true;

        } catch (error) {

            this.#context?.showWarning?.(
                error?.message || "You cannot link with this user."
            );

            return false;

        }

    }

    /**
     * Refreshes relationship geometry and presentation.
     *
     * @param {Object} initiator
     * @param {Object} target
     * @param {Object} options
     * @param {boolean} options.animate
     * @param {boolean} options.persist
     *
     * @returns {Object[]}
     */
    refreshRelationship(initiator, target, options = {}) {

        if (!initiator || !target) {
            return [];
        }

        const {
            animate = true,
            persist = false,
            reason = "manual"
        } = options;

        const changed =
            this.#snapLinkedPair(
                initiator,
                target,
                animate
            );

        if (persist) {
            this.#context?.persistPositions?.([
                initiator,
                target
            ]);
        }

        this.#lifecycleOperationCount += 1;
        this.#recordRelationshipRefresh({
            reason,
            pairCount: 1,
            changedCount: changed.length,
            participantIds: [
                initiator.id,
                target.id
            ]
        });

        return changed;

    }

    /**
     * Refreshes a relationship after icon metadata changes.
     *
     * @param {string} linkKey
     * @param {Object} options
     *
     * @returns {Object[]}
     */
    adjustLinkedPairForIcon(linkKey, options = {}) {

        const pair =
            this.#relationships.linkedPairs()
                .find(([key]) => key === linkKey);

        if (!pair) {
            return [];
        }

        const [, first, second] =
            pair;

        const initiator =
            this.#relationships.relationshipInitiator(first, second) || first;

        const target =
            initiator === first
                ? second
                : first;

        return this.refreshRelationship(
            initiator,
            target,
            options
        );

    }

    /**
     * Applies a local relationship icon change.
     *
     * @param {number|string} targetId
     * @param {string} iconName
     *
     * @returns {Promise<boolean>}
     */
    async applyLocalLinkIcon(targetId, iconName = "plus") {

        const config =
            this.#context?.getConfig?.();

        const linkKey =
            this.#relationships.linkKeyFor(
                config?.myParticipantId,
                targetId
            );

        this.setLinkIcon(
            linkKey,
            iconName
        );

        this.adjustLinkedPairForIcon(
            linkKey,
            {
                animate: true,
                persist: true
            }
        );

        this.#syncRelationshipPresentation();
        this.#context?.updateStageLinkIcons?.();

        try {

            await this.#context?.persistLinkIcon?.({

                targetId,
                iconName

            });

            this.#lifecycleOperationCount += 1;

            return true;

        } catch (error) {

            this.#context?.alertError?.(error);

            return false;

        }

    }

    /**
     * Applies a remote link event.
     *
     * @param {Object} payload
     *
     * @returns {Object|null}
     */
    reconcileRemoteLink(payload = {}) {

        if (payload.relationship) {
            this.#relationships.upsertPersistedRelationship(payload.relationship);
            if (!this.#relationships.isCurrentPersistedRelationshipSnapshot(payload.relationship)) {
                return null;
            }
        }

        const person =
            this.#participant(payload.participant_id);

        if (!person) {
            this.#syncRelationshipPresentation();
            return null;
        }

        const previousPartnerId =
            Number(person.linked_to || 0);

        this.#relationships.setParticipantRelationship(
            person.id,
            payload.linked_to,
            payload.link_mode
        );

        this.invalidatePendingLinkChoice(
            "remote-relationship-change",
            [person.id, payload.linked_to, previousPartnerId]
        );

        if (payload.initiator_position) {
            this.#layout.applyParticipantPosition(person, {
                x: payload.initiator_position.x,
                y: payload.initiator_position.y
            });
        }

        if (payload.linked_to) {

            const target =
                this.#participant(payload.linked_to);

            if (target) {

                if (payload.target_position) {
                    this.#layout.applyParticipantPosition(target, {
                        x: payload.target_position.x,
                        y: payload.target_position.y
                    });
                }

                this.refreshRelationship(
                    person,
                    target,
                    {
                        animate: true,
                        persist: false,
                        reason: "remote-link"
                    }
                );

            }

        }

        this.#context?.renderParticipant?.(person);

        if (!payload.linked_to) {
            if (previousPartnerId) {
                this.#relationships.removePersistedRelationshipForPair(
                    person.id,
                    previousPartnerId
                );
            }
            this.clearScheduledRelationshipRefresh(person.id);
            this.clearScheduledRelationshipRefresh(previousPartnerId);
            this.#context?.onLinkUnavailable?.(person.id);
        }

        this.#syncRelationshipPresentation();
        this.#lifecycleOperationCount += 1;

        return person;

    }

    /**
     * Reconciles one versioned relationship lifecycle event.
     *
     * Request-only events intentionally carry no private membership snapshot.
     * Snapshot reconciliation remains authoritative in
     * AvatarRelationshipService and does not project unsupported multi-member
     * geometry into the legacy pair presentation.
     *
     * @param {Object} payload
     *
     * @returns {Object|null}
     */
    reconcileRemoteRelationship(payload = {}) {

        const relationship = payload.relationship;

        if (!relationship) {
            this.#context?.recordRelationshipDiagnostic?.({
                event: "relationship-event-observed",
                action: String(payload.action || "unknown"),
                relationshipId: String(payload.relationship_id || "") || null,
                relationshipVersion: Number(payload.relationship_version || 0) || null,
                relationshipStatus: String(payload.relationship_status || "") || null,
                snapshotIncluded: false
            });
            return null;
        }

        const reconciled =
            this.#relationships.upsertPersistedRelationship(relationship);

        if (!this.#relationships.isCurrentPersistedRelationshipSnapshot(relationship)) {
            this.#context?.recordRelationshipDiagnostic?.({
                event: "relationship-event-stale",
                action: String(payload.action || "unknown"),
                relationshipId: String(payload.relationship_id || relationship.id || "") || null,
                relationshipVersion: Number(payload.relationship_version || relationship.version || 0) || null,
                relationshipStatus: String(payload.relationship_status || relationship.status || "") || null
            });
            return null;
        }

        const participantIds =
            Array.from(relationship.members || [])
                .map(member => Number(member?.participantId || member?.participant_id || 0))
                .filter(participantId => participantId > 0);

        this.invalidatePendingLinkChoice(
            "remote-relationship-change",
            participantIds
        );

        this.#syncRelationshipPresentation();
        this.#lifecycleOperationCount += 1;

        this.#context?.recordRelationshipDiagnostic?.({
            event: "relationship-event-reconciled",
            action: String(payload.action || "unknown"),
            relationshipId: String(payload.relationship_id || relationship.id || "") || null,
            relationshipVersion: Number(payload.relationship_version || relationship.version || 0) || null,
            relationshipStatus: String(payload.relationship_status || relationship.status || "") || null,
            memberCount: participantIds.length,
            snapshotIncluded: true
        });

        return reconciled;

    }

    /**
     * Applies a remote link icon event.
     *
     * @param {Object} payload
     *
     * @returns {boolean}
     */
    reconcileRemoteLinkIcon(payload = {}) {

        if (!payload.link_key || !payload.icon_name) {
            return false;
        }

        this.setLinkIcon(
            payload.link_key,
            payload.icon_name
        );

        this.adjustLinkedPairForIcon(
            payload.link_key,
            {
                animate: true,
                persist: false,
                reason: "remote-link-icon"
            }
        );

        this.#context?.renderPeople?.();
        this.#context?.updateStageLinkIcons?.();
        this.#lifecycleOperationCount += 1;

        return true;

    }

    /**
     * Refreshes relationship layout affected by participant movement.
     *
     * @param {Object} participant
     * @param {Object} options
     *
     * @returns {boolean}
     */
    refreshRelationshipsForParticipant(participant, options = {}) {

        if (!participant) {
            return false;
        }

        const pairs =
            this.#relationshipPairsForParticipant(participant);

        if (!pairs.length) {
            return false;
        }

        pairs.forEach(([initiator, target]) => {
            this.refreshRelationship(
                initiator,
                target,
                {
                    ...options,
                    reason:
                        options.reason || "participant"
                }
            );
        });

        return true;

    }

    /**
     * Refreshes all currently linked relationships.
     *
     * @param {Object} options
     *
     * @returns {Object[]}
     */
    refreshAllRelationships(options = {}) {

        const changed = [];

        this.#relationships.linkedPairs()
            .forEach(([, first, second]) => {
                const initiator =
                    this.#relationships.relationshipInitiator(
                        first,
                        second
                    ) || first;

                const target =
                    initiator === first
                        ? second
                        : first;

                changed.push(
                    ...this.refreshRelationship(
                        initiator,
                        target,
                        {
                            ...options,
                            reason:
                                options.reason || "all"
                        }
                    )
                );
            });

        return changed;

    }

    /**
     * Schedules a coalesced relationship refresh.
     *
     * @param {Object} options
     *
     * @returns {boolean}
     */
    scheduleRelationshipRefresh(options = {}) {

        const {
            participant = null,
            participantId = null,
            all = false,
            reason = "scheduled"
        } = options;

        if (all) {
            this.#scheduledRelationshipRefreshAll = true;
        } else {
            const id =
                Number(
                    participant?.id ??
                    participantId
                );

            if (Number.isFinite(id) && id > 0) {
                this.#scheduledRelationshipRefreshParticipantIds.add(id);
            }
        }

        this.#scheduledRelationshipRefreshReasons.add(
            String(reason || "scheduled")
        );

        if (this.#scheduledRelationshipRefresh) {
            return true;
        }

        this.#scheduledRelationshipRefresh =
            this.#scheduleRefreshFrame(() => {
                this.#flushScheduledRelationshipRefresh();
            });

        return true;

    }

    /**
     * Clears pending relationship refresh state for a participant.
     *
     * @param {number|string} participantId
     */
    clearScheduledRelationshipRefresh(participantId) {

        const id =
            Number(participantId);

        if (Number.isFinite(id)) {
            this.#scheduledRelationshipRefreshParticipantIds.delete(id);
        }

    }

    /**
     * Breaks a relationship during local drag.
     *
     * @param {Object} participant
     *
     * @returns {boolean}
     */
    breakRelationshipForDrag(participant) {

        if (!participant?.linked_to) {
            return false;
        }

        this.#relationships.clearParticipant(participant.id);
        this.clearScheduledRelationshipRefresh(participant.id);
        this.#context?.persistUnlink?.();
        this.#context?.renderParticipant?.(participant);
        this.#context?.renderLinkTabs?.();
        this.#lifecycleOperationCount += 1;

        return true;

    }

    /**
     * Applies linked group movement during drag.
     *
     * @param {Object} options
     *
     * @returns {Object[]}
     */
    applyDragGroupMove(options = {}) {

        const {
            participant,
            group,
            baseX,
            baseY,
            spacing,
            relationshipBroken = false
        } = options;

        const activeGroup =
            Array.isArray(group)
                ? group.filter(Boolean)
                : [];

        if (!activeGroup.length) {
            return [];
        }

        const changed =
            this.#layout.applyDragGroupLayout({
                group: activeGroup,
                baseX,
                baseY,
                spacing
            });

        changed.forEach(member => {
            this.#context?.positionAvatar?.(member);
        });

        if (!relationshipBroken && participant) {

            let refreshed = false;

            this.#participants.forEach(other => {

                if (
                    Number(other.id) === Number(participant.id) ||
                    Number(other.linked_to) !== Number(participant.id)
                ) {
                    return;
                }

                refreshed = true;

                this.refreshRelationship(
                    other,
                    participant,
                    {
                        animate: false,
                        persist: false,
                        reason: "drag-move"
                    }
                );

            });

            if (!refreshed) {
                this.#context?.positionAvatar?.(participant);
            }

        }

        return changed;

    }

    /**
     * Opens a link choice for a drag target when allowed.
     *
     * @param {Object} participant
     * @param {Object} target
     *
     * @returns {boolean}
     */
    requestLinkChoiceForDrag(participant, target) {

        if (!participant || !target) {
            return false;
        }

        const decision = this.relationshipEligibility(participant, target);

        if (!decision.allowed) {
            return false;
        }

        const participantId =
            Number(participant.id);

        if (this.#suppressedLinkChoiceParticipantIds.has(participantId)) {
            return false;
        }

        this.beginLinkChoice(
            participant,
            target,
            decision
        );

        this.#context?.openLinkChoiceModal?.(
            participant,
            target
        );

        this.#suppressedLinkChoiceParticipantIds.add(participantId);

        const previousTimer = this.#linkChoiceSuppressionTimers.get(participantId);
        if (previousTimer) {
            clearTimeout(previousTimer);
        }

        const timer = setTimeout(() => {
            this.#suppressedLinkChoiceParticipantIds.delete(participantId);
            this.#linkChoiceSuppressionTimers.delete(participantId);
        }, LINK_CHOICE_SUPPRESS_MS);

        this.#linkChoiceSuppressionTimers.set(participantId, timer);

        return true;

    }

    /**
     * Persists drag completion state.
     *
     * @param {Object} participant
     *
     * @returns {Promise<void>}
     */
    async persistDragEnd(participant) {

        if (!participant) {
            return;
        }

        this.refreshRelationshipsForParticipant(
            participant,
            {
                animate: false,
                persist: false,
                reason: "drag-completion"
            }
        );

        const linkedFollowers =
            this.#relationships.followersOf(participant.id);

        if (linkedFollowers.length) {
            await this.#context?.persistPositions?.([
                participant,
                ...linkedFollowers
            ]);
            return;
        }

        await this.#context?.persistPosition?.(participant);

    }

    /**
     * Unlinks the current participant relationship.
     *
     * @param {Object} options
     *
     * @returns {boolean}
     */
    unlinkCurrentParticipant(options = {}) {

        const config =
            this.#context?.getConfig?.();

        const participantId =
            Number(options.participantId || config?.myParticipantId);

        const partnerId =
            Number(options.partnerId || 0);

        const changed =
            this.#relationships.unlinkParticipant(participantId);

        if (partnerId) {
            const partner =
                this.#relationships.clearParticipant(partnerId);

            if (partner && !changed.includes(partner)) {
                changed.push(partner);
            }
        }

        if (!changed.length) {
            return false;
        }

        changed.forEach(participant => {
            this.clearScheduledRelationshipRefresh(participant.id);
        });

        this.#renderParticipants(changed);
        this.#context?.onCurrentParticipantUnlinked?.();
        this.#context?.persistUnlink?.();
        this.#syncRelationshipPresentation();
        this.#lifecycleOperationCount += 1;

        return true;

    }

    /**
     * Clears relationship state for a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    clearParticipantRelationship(participantId) {

        this.invalidatePendingLinkChoice(
            "participant-relationship-cleared",
            [participantId]
        );

        const participant =
            this.#relationships.clearParticipant(participantId);

        if (participant) {
            this.clearScheduledRelationshipRefresh(participant.id);
            this.#lifecycleOperationCount += 1;
        }

        return participant;

    }

    /**
     * Clears relationships pointing at a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object[]}
     */
    unlinkFollowersOf(participantId) {

        this.invalidatePendingLinkChoice(
            "participant-removed",
            [participantId]
        );

        const changed =
            this.#relationships.unlinkFollowersOf(participantId);

        if (changed.length) {
            changed.forEach(participant => {
                this.clearScheduledRelationshipRefresh(participant.id);
            });
            this.#lifecycleOperationCount += 1;
        }

        return changed;

    }

    /**
     * Clears relationship state when a blocked user affects a participant.
     *
     * @param {Object} participant
     *
     * @returns {Object|null}
     */
    clearBlockedRelationship(participant) {

        if (!participant) {
            return null;
        }

        this.invalidatePendingLinkChoice(
            "block-state-change",
            [participant.id]
        );

        const changed =
            this.#relationships.clearParticipant(participant.id);

        if (changed) {
            this.clearScheduledRelationshipRefresh(participant.id);
            this.#context?.renderParticipant?.(changed);
            this.#lifecycleOperationCount += 1;
        }

        return changed;

    }

    /**
     * Returns coordinator diagnostic information.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "AvatarRuntime",

            build:
                "000038",

            configured:
                Boolean(this.#context),

            pendingLinkChoice:
                this.#pendingLinkChoice,

            pendingLinkChoiceInvalidations:
                this.#pendingLinkChoiceInvalidationCount,

            relationshipEligibilityReasons:
                Object.fromEntries(this.#relationshipEligibilityReasonCounts.entries()),

            linkedGroups:
                this.#linkGroups.size,

            linkIcons:
                this.#linkIcons.size,

            lifecycleOperationCount:
                this.#lifecycleOperationCount,

            relationshipRefreshCount:
                this.#relationshipRefreshCount,

            relationshipRefreshReasons:
                Object.fromEntries(
                    this.#relationshipRefreshReasonCounts.entries()
                ),

            lastRelationshipRefresh:
                this.#lastRelationshipRefresh,

            scheduledRelationshipRefresh:
                Boolean(this.#scheduledRelationshipRefresh),

            scheduledRelationshipRefreshParticipantCount:
                this.#scheduledRelationshipRefreshParticipantIds.size,

            scheduledRelationshipRefreshAll:
                this.#scheduledRelationshipRefreshAll

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
     * Returns relationship state owner.
     *
     * @returns {AvatarRelationshipService}
     */
    get #relationships() {

        return this.#runtime.relationships;

    }

    /**
     * Returns layout owner.
     *
     * @returns {AvatarLayoutService}
     */
    get #layout() {

        return this.#runtime.layout;

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns a participant by id.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    #participant(participantId) {

        return this.#participants.get(participantId) || null;

    }

    /**
     * Returns relationship pairs affected by a participant.
     *
     * @param {Object} participant
     *
     * @returns {Object[][]}
     */
    #relationshipPairsForParticipant(participant) {

        const pairs = [];
        const seen = new Set();

        const addPair = (initiator, target) => {
            if (!initiator || !target) return;

            const key =
                this.#relationships.linkKeyFor(
                    initiator.id,
                    target.id
                );

            if (seen.has(key)) return;

            seen.add(key);
            pairs.push([
                initiator,
                target
            ]);
        };

        if (participant.linked_to) {
            addPair(
                participant,
                this.#participant(participant.linked_to)
            );
        }

        this.#relationships.followersOf(participant.id)
            .forEach(initiator => {
                addPair(
                    initiator,
                    participant
                );
            });

        return pairs;

    }

    /**
     * Schedules refresh work on the host animation frame when available.
     *
     * @param {Function} callback
     *
     * @returns {*}
     */
    #scheduleRefreshFrame(callback) {

        const scheduler =
            this.#context?.requestRelationshipRefreshFrame ||
            this.#context?.requestAnimationFrame ||
            globalThis?.requestAnimationFrame;

        if (typeof scheduler === "function") {
            return scheduler(callback);
        }

        return setTimeout(callback, 0);

    }

    /**
     * Flushes the currently scheduled relationship refresh.
     */
    #flushScheduledRelationshipRefresh() {

        const all =
            this.#scheduledRelationshipRefreshAll;

        const participantIds =
            Array.from(
                this.#scheduledRelationshipRefreshParticipantIds
            );

        const reason =
            Array.from(
                this.#scheduledRelationshipRefreshReasons
            ).join("+") || "scheduled";

        this.#scheduledRelationshipRefresh = null;
        this.#scheduledRelationshipRefreshAll = false;
        this.#scheduledRelationshipRefreshParticipantIds.clear();
        this.#scheduledRelationshipRefreshReasons.clear();

        if (all) {
            this.refreshAllRelationships({
                animate: false,
                persist: false,
                reason
            });
            return;
        }

        participantIds.forEach(participantId => {
            const participant =
                this.#participant(participantId);

            if (participant) {
                this.refreshRelationshipsForParticipant(
                    participant,
                    {
                        animate: false,
                        persist: false,
                        reason
                    }
                );
            }
        });

    }

    /**
     * Records relationship refresh diagnostics.
     *
     * @param {Object} details
     */
    #recordRelationshipRefresh(details = {}) {

        const reason =
            String(details.reason || "unspecified");

        this.#relationshipRefreshCount += 1;
        this.#relationshipRefreshReasonCounts.set(
            reason,
            (
                this.#relationshipRefreshReasonCounts.get(reason) ||
                0
            ) + 1
        );

        this.#lastRelationshipRefresh = Object.freeze({
            reason,
            pairCount:
                Number(details.pairCount || 0),
            changedCount:
                Number(details.changedCount || 0),
            participantIds:
                Array.from(details.participantIds || [])
                    .map(id => Number(id))
                    .filter(id => Number.isFinite(id)),
            layout:
                this.#layout.getDiagnostics?.().lastRelationshipStrategy ||
                null
        });

    }

    /**
     * Records bounded eligibility lifecycle evidence through RuntimeDiagnostics.
     *
     * @param {Object} decision
     * @param {string} event
     */
    #recordEligibilityDiagnostic(decision = {}, event = "eligibility") {

        const reason = String(decision.reason || "unknown");

        this.#relationshipEligibilityReasonCounts.set(
            reason,
            (this.#relationshipEligibilityReasonCounts.get(reason) || 0) + 1
        );

        this.#context?.recordRelationshipDiagnostic?.({
            event,
            reason,
            allowed: Boolean(decision.allowed),
            initiatorParticipantId: Number(decision.initiatorParticipantId || 0) || null,
            targetParticipantId: Number(decision.targetParticipantId || 0) || null
        });

    }

    /**
     * Applies linked or lapped relationship layout.
     *
     * @param {Object} initiator
     * @param {Object} target
     * @param {boolean} animate
     *
     * @returns {Object[]}
     */
    #snapLinkedPair(initiator, target, animate = true) {

        if (!initiator?.avatarEl || !target?.avatarEl) {
            return [];
        }

        const stageSize =
            this.#stageSize();

        const relationship =
            this.#relationships.relationshipForPair(
                initiator,
                target
            );

        const metadata =
            relationship?.metadata ||
            this.#relationships.relationshipMetadataForPair(
                initiator,
                target
            );

        const initiatorDimensions =
            this.#renderedDimensions(initiator);

        const targetDimensions =
            this.#renderedDimensions(target);

        const changed =
            this.#layout.applyRelationshipLayout({
                initiator,
                target,
                stageWidth: stageSize.width,
                stageHeight: stageSize.height,
                initiatorDimensions,
                targetDimensions,
                metadata,
                gap: this.linkPairGap(
                    relationship?.key ||
                    this.#relationships.linkKeyFor(initiator.id, target.id)
                ),
                locked: Boolean(this.#context?.isLayoutLocked?.())
            });

        changed.forEach(participant => {
            this.#context?.positionAvatar?.(participant);
        });

        if (animate && metadata.geometryStrategy !== "anchorPair") {
            this.#context?.animateLinkedPair?.([
                target,
                initiator
            ]);
        }

        return changed;

    }

    /**
     * Returns current stage dimensions.
     *
     * @returns {Object}
     */
    #stageSize() {

        const stageSize =
            this.#context?.stageSize?.() || {};

        return Object.freeze({

            width:
                Number(stageSize.width || 0),

            height:
                Number(stageSize.height || 0)

        });

    }

    /**
     * Returns base avatar size.
     *
     * @returns {number}
     */
    #baseAvatarSize() {

        return Number(this.#context?.baseAvatarSize?.() || DEFAULT_AVATAR_SIZE);

    }

    /**
     * Returns authoritative rendered avatar dimensions.
     *
     * @param {Object} participant
     *
     * @returns {Object}
     */
    #renderedDimensions(participant) {

        return this.#runtime.renderer?.renderedAvatarDimensions(
            participant,
            {
                fallbackSize:
                    this.#baseAvatarSize(),
                visualMaxSize:
                    DEFAULT_AVATAR_VISUAL_MAX_SIZE,
                lapInitiator:
                    this.#relationships.isLapLinkInitiator(participant)
            }
        ) || {
            width:
                this.#baseAvatarSize(),
            height:
                this.#baseAvatarSize()
        };

    }

    /**
     * Synchronizes relationship presentation through host callbacks.
     */
    #syncRelationshipPresentation() {

        this.#context?.refreshLinkClasses?.();
        this.#context?.renderPeople?.();
        this.#context?.renderLinkTabs?.();

    }

    /**
     * Renders changed participants through host callbacks.
     *
     * @param {Object[]} participants
     */
    #renderParticipants(participants) {

        participants.forEach(participant => {
            if (participant) {
                this.#context?.renderParticipant?.(participant);
            }
        });

    }

}

export default AvatarCoordinator;
