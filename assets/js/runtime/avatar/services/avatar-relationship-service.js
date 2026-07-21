/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-relationship-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns avatar relationship state and behavior.
 *
 *      AvatarRelationshipService owns the relationship graph for avatars.
 *      It manages relationship creation, removal, and relationship queries
 *      while leaving geometry, layout, rendering, and persistence to their
 *      documented owners.
 *
 * Build:
 *      000044 Part 5
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000012
 * - Introduced Avatar Relationship Service.
 * - Established runtime-owned relationship service.
 * - No relationship behavior migrated.
 *
 * Build 000017
 * - Added relationship graph ownership.
 * - Added relationship creation, removal, and query APIs.
 * - Redirected legacy room.js relationship state ownership.
 *
 * Build 000034
 * - Added relationship capability registry ownership.
 * - Added relationship metadata contract normalization.
 * - Added legacy directed-edge relationship metadata translation.
 *
 * Build 000035
 * - Added relationship geometry strategy declarations.
 *
 * Build 000037
 * - Added first-class runtime relationship identity objects, relationship
 *   event contract, and legacy directed-edge compatibility translation.
 *
 * Build 000038
 * - Added persisted relationship payload ingestion and cache reconciliation.
 *
 * Build 000044 Part 1
 * - Added authoritative structured relationship eligibility decisions.
 *
 * Build 000044 Part 2A
 * - Made versioned persisted group snapshots authoritative for membership.
 * - Added stale-version rejection and relationship tombstone handling.
 *
 * Build 000044 Part 3
 * - Added immutable ordered relationship presentation projections with
 *   presence, renderability, normal-row, and lap-host state.
 *
 * Build 000044 Part 4
 * - Added the immutable viewer-specific relationship-management projection.
 ******************************************************************************/

/**
 * @file avatar-relationship-service.js
 *
 * Defines the Avatar Relationship Service.
 */

//--------------------------------------------------
// Relationship Capability Model
//--------------------------------------------------

const RELATIONSHIP_METADATA_SCHEMA_VERSION = 1;
const RELATIONSHIP_ID_PREFIX = "legacy-edge";
const LAP_SIDES = Object.freeze(["bottom-left", "bottom-right"]);

function normalizeLapSide(side) {
    const normalized = typeof side === "string" ? side.trim() : "";
    return LAP_SIDES.includes(normalized) ? normalized : null;
}

function normalizeRelationshipRowOptions(options = {}) {

    const rowSpacing = Number(options?.rowSpacing ?? 0);
    const formation = ["horizontal-row", "bottom-center-trio", "top-center-trio", "grid"]
        .includes(String(options?.formation || ""))
        ? String(options.formation)
        : "horizontal-row";
    const transition = ["snap", "glide", "fade-reposition"]
        .includes(String(options?.transition || ""))
        ? String(options.transition)
        : "snap";

    return Object.freeze({
        schemaVersion: 2,
        rowSpacing:
            Number.isInteger(rowSpacing)
                ? Math.max(0, Math.min(64, rowSpacing))
                : 0,
        formation,
        transition
    });

}

function normalizeDancePlayback(playback = {}) {
    const danceId = ["synchronized-sway", "synchronized-bounce"]
        .includes(String(playback?.danceId || playback?.dance_id || ""))
        ? String(playback?.danceId || playback?.dance_id)
        : null;
    const generation = String(playback?.generation || "");
    const startedAtMs = Number(playback?.startedAtMs || playback?.started_at_ms || 0);
    const initiatorParticipantId = Number(
        playback?.initiatorParticipantId || playback?.initiator_participant_id || 0
    );
    const playing = String(playback?.state || "stopped") === "playing"
        && danceId
        && generation
        && Number.isFinite(startedAtMs)
        && startedAtMs > 0
        && initiatorParticipantId > 0;
    return Object.freeze({
        schemaVersion: 1,
        danceId: playing ? danceId : null,
        state: playing ? "playing" : "stopped",
        startedAtMs: playing ? startedAtMs : null,
        generation: generation || null,
        initiatorParticipantId: playing ? initiatorParticipantId : null
    });
}

function normalizeLapAnimations(states = [], relationshipVersion = 0, members = []) {
    const membersById = new Map(members.map(member => [Number(member.participantId), member]));
    const occupantsByHost = new Map();
    members.forEach(member => {
        if (member.relationshipRole !== "lap") return;
        const hostId = Number(member.lapHostParticipantId || 0);
        const occupants = occupantsByHost.get(hostId) || [];
        occupants.push(member);
        occupantsByHost.set(hostId, occupants);
    });
    const seenHosts = new Set();
    return Object.freeze(Array.from(states || []).map(state => {
        const hostParticipantId = Number(state?.hostParticipantId || state?.host_participant_id || 0);
        const occupantParticipantId = Number(state?.occupantParticipantId || state?.occupant_participant_id || 0);
        const occupantMembershipGeneration = String(
            state?.occupantMembershipGeneration || state?.occupant_membership_generation || ""
        );
        const boundVersion = Number(state?.relationshipVersion || state?.relationship_version || 0);
        const mode = ["lap_dance", "lap_bounce"].includes(String(state?.mode || ""))
            ? String(state.mode)
            : null;
        const generation = String(state?.generation || "");
        const startedAtMs = Number(state?.startedAtMs || state?.started_at_ms || 0);
        const lapSide = normalizeLapSide(state?.lapSide || state?.lap_side);
        const host = membersById.get(hostParticipantId);
        const occupant = membersById.get(occupantParticipantId);
        const valid = hostParticipantId > 0
            && occupantParticipantId > 0
            && !seenHosts.has(hostParticipantId)
            && mode
            && generation
            && Number.isFinite(startedAtMs)
            && startedAtMs > 0
            && boundVersion === Number(relationshipVersion)
            && host?.relationshipRole === "normal"
            && occupant?.relationshipRole === "lap"
            && Number(occupant.lapHostParticipantId) === hostParticipantId
            && occupant.lapSide === lapSide
            && occupantsByHost.get(hostParticipantId)?.length === 1
            && occupantMembershipGeneration
            && occupant.membershipGeneration === occupantMembershipGeneration;
        if (!valid) return null;
        seenHosts.add(hostParticipantId);
        return Object.freeze({
            schemaVersion: 1,
            relationshipVersion: boundVersion,
            hostParticipantId,
            hostUserId: Number(state?.hostUserId || state?.host_user_id || 0) || null,
            occupantParticipantId,
            occupantUserId: Number(state?.occupantUserId || state?.occupant_user_id || 0) || null,
            occupantMembershipGeneration,
            lapSide,
            mode,
            generation,
            startedAtMs
        });
    }).filter(Boolean));
}

const RELATIONSHIP_CAPABILITIES = Object.freeze({

    normal:
        Object.freeze({
            id: "normal",
            mode: "normal",
            label: "Normal Link",
            layout: "side-by-side",
            geometryStrategy: "sideBySide",
            legacyMode: "normal",
            supported: true,
            participantLimit: 2,
            roles:
                Object.freeze([
                    "initiator",
                    "target"
                ]),
            defaults:
                Object.freeze({
                    orientation: "right",
                    movement: "group",
                    drag: "group",
                    rendering: "stage-link-icon",
                    static: true,
                    animated: false
                }),
            supports:
                Object.freeze({
                    anchors: false,
                    perMemberAnchors: false,
                    ordering: false,
                    relationshipOptions: false,
                    multiplayerReconciliation: true,
                    persistence: true
                })
        }),

    lap:
        Object.freeze({
            id: "lap",
            mode: "lap",
            label: "Lap",
            layout: "lap",
            geometryStrategy: "anchorPair",
            legacyMode: "lap",
            supported: true,
            participantLimit: 2,
            roles:
                Object.freeze([
                    "initiator",
                    "target"
                ]),
            defaults:
                Object.freeze({
                    orientation: "bottom-right",
                    movement: "group",
                    drag: "hosted",
                    rendering: "lap-pair",
                    static: true,
                    animated: false
                }),
            supports:
                Object.freeze({
                    anchors: true,
                    perMemberAnchors: true,
                    ordering: false,
                    relationshipOptions: false,
                    multiplayerReconciliation: true,
                    persistence: true
                })
        })

});

const RELATIONSHIP_METADATA_CONTRACT = Object.freeze({
    schemaVersion: RELATIONSHIP_METADATA_SCHEMA_VERSION,
    relationshipId: null,
    groupId: null,
    mode: "normal",
    capability: "normal",
    geometryStrategy: "sideBySide",
    metadataSource: "fallback",
    members:
        Object.freeze([]),
    orientation: "right",
    order:
        Object.freeze([]),
    anchors:
        Object.freeze({
            relationship: null,
            members:
                Object.freeze({}),
            mode:
                Object.freeze({})
        }),
    options:
        Object.freeze({}),
    movement: "group",
    drag: "group",
    rendering: "stage-link-icon",
    persistence:
        Object.freeze({
            supported: true,
            legacyDirectedEdge: true,
            futureMetadata: false
        }),
    reconciliation:
        Object.freeze({
            supported: true,
            eventPayload: "link"
        }),
    behavior:
        Object.freeze({
            static: true,
            animated: false
        })
});

const RELATIONSHIP_EVENT_TYPES = Object.freeze({
    CREATED: "relationship.created",
    REMOVED: "relationship.removed",
    MEMBER_ADDED: "relationship.member.added",
    MEMBER_REMOVED: "relationship.member.removed",
    MODE_CHANGED: "relationship.mode.changed",
    METADATA_CHANGED: "relationship.metadata.changed",
    ANCHORS_CHANGED: "relationship.anchors.changed",
    ORDERING_CHANGED: "relationship.ordering.changed"
});

const RELATIONSHIP_EVENT_CONTRACT = Object.freeze({
    schemaVersion: 1,
    types: RELATIONSHIP_EVENT_TYPES,
    payload:
        Object.freeze({
            type: "",
            relationshipId: "",
            relationship: null,
            participantIds:
                Object.freeze([]),
            mode: "normal",
            capability: "normal",
            metadata: null,
            previous: null,
            changes:
                Object.freeze({}),
            source: "runtime"
        })
});

export const RELATIONSHIP_ELIGIBILITY_REASONS = Object.freeze({
    ELIGIBLE: "eligible",
    MISSING_INITIATOR: "missing-initiator",
    MISSING_TARGET: "missing-target",
    SELF: "self",
    BLOCKED: "blocked",
    INITIATOR_UNAVAILABLE: "initiator-unavailable",
    TARGET_UNAVAILABLE: "target-unavailable",
    ALREADY_RELATED: "already-related",
    INITIATOR_RELATIONSHIP: "initiator-relationship",
    TARGET_RELATIONSHIP: "target-relationship"
});

const CURRENT_RELATIONSHIP_CREATION_MODES = Object.freeze([
    "normal",
    "lap"
]);

//--------------------------------------------------
// Avatar Relationship Service
//--------------------------------------------------

/**
 * Owns avatar relationship state and behavior.
 *
 * AvatarRelationshipService is owned exclusively by AvatarRuntime.
 */
export class AvatarRelationshipService {

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
     * Runtime relationship event observers.
     *
     * @type {Set<Function>}
     */
    #relationshipEventListeners = new Set();

    /**
     * Persisted relationship payloads keyed by relationship id.
     *
     * @type {Map<string, Object>}
     */
    #persistedRelationships = new Map();

    /**
     * Persisted relationship payloads keyed by legacy link key.
     *
     * @type {Map<string, Object>}
     */
    #persistedRelationshipsByLegacyKey = new Map();

    /**
     * Highest reconciled version for each persisted relationship.
     *
     * @type {Map<string, number>}
     */
    #persistedRelationshipVersions = new Map();

    /**
     * Number of stale persisted snapshots rejected.
     *
     * @type {number}
     */
    #stalePersistedSnapshotCount = 0;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Relationship Service.
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
     * Participates in the runtime lifecycle.
     */
    initialize() {

    }

    /**
     * Releases resources owned by the service.
     */
    destroy() {

        this.#relationshipEventListeners.clear();
        this.#persistedRelationships.clear();
        this.#persistedRelationshipsByLegacyKey.clear();
        this.#persistedRelationshipVersions.clear();
        this.#stalePersistedSnapshotCount = 0;

    }

    //--------------------------------------------------
    // Public Rules
    //--------------------------------------------------

    /**
     * Returns every supported relationship capability.
     *
     * @returns {Object[]}
     */
    relationshipCapabilities() {

        return Object.freeze(
            Object.values(RELATIONSHIP_CAPABILITIES)
        );

    }

    /**
     * Returns the relationship metadata contract shape.
     *
     * @returns {Object}
     */
    relationshipMetadataContract() {

        return RELATIONSHIP_METADATA_CONTRACT;

    }

    /**
     * Returns the relationship event contract.
     *
     * @returns {Object}
     */
    relationshipEventContract() {

        return RELATIONSHIP_EVENT_CONTRACT;

    }

    /**
     * Observes runtime relationship lifecycle events.
     *
     * @param {Function} listener
     *
     * @returns {Function}
     */
    observeRelationshipEvents(listener) {

        if (typeof listener !== "function") {
            return () => {};
        }

        this.#relationshipEventListeners.add(listener);

        return () => {
            this.#relationshipEventListeners.delete(listener);
        };

    }

    /**
     * Seeds persisted relationship identity payloads from the API layer.
     *
     * @param {Object[]} relationships
     */
    seedPersistedRelationships(relationships = []) {

        this.#persistedRelationships.clear();
        this.#persistedRelationshipsByLegacyKey.clear();
        this.#persistedRelationshipVersions.clear();

        Array.from(relationships || [])
            .forEach(relationship => {
                this.upsertPersistedRelationship(relationship);
            });

    }

    /**
     * Adds or updates one persisted relationship identity payload.
     *
     * @param {Object} relationship
     *
     * @returns {Object|null}
     */
    upsertPersistedRelationship(relationship) {

        const normalized =
            this.#normalizePersistedRelationshipPayload(relationship);

        if (!normalized) {
            return null;
        }

        const currentVersion =
            this.#persistedRelationshipVersions.get(normalized.id) || 0;

        if (
            normalized.version < currentVersion ||
            (
                normalized.version === currentVersion &&
                currentVersion > 0 &&
                !this.#persistedRelationships.has(normalized.id) &&
                normalized.status === "active"
            )
        ) {
            this.#stalePersistedSnapshotCount += 1;
            return this.#persistedRelationships.get(normalized.id) || null;
        }

        const previous =
            this.#persistedRelationships.get(normalized.id) || null;

        if (previous?.legacyLinkKey) {
            this.#persistedRelationshipsByLegacyKey.delete(
                previous.legacyLinkKey
            );
        }

        this.#persistedRelationshipVersions.set(
            normalized.id,
            normalized.version
        );

        if (normalized.status !== "active") {
            this.#persistedRelationships.delete(normalized.id);
            if (normalized.legacyLinkKey) {
                this.#persistedRelationshipsByLegacyKey.delete(
                    normalized.legacyLinkKey
                );
            }
            return normalized;
        }

        this.#persistedRelationships.set(
            normalized.id,
            normalized
        );

        if (normalized.legacyLinkKey) {
            this.#persistedRelationshipsByLegacyKey.set(
                normalized.legacyLinkKey,
                normalized
            );
        }

        return normalized;

    }

    /**
     * Returns whether a snapshot matches the currently reconciled generation.
     *
     * @param {Object} relationship
     *
     * @returns {boolean}
     */
    isCurrentPersistedRelationshipSnapshot(relationship) {

        const id =
            String(
                relationship?.id ||
                relationship?.relationship_id ||
                ""
            );

        const version =
            Math.max(1, Number(relationship?.version || 1));

        const status =
            String(relationship?.status || "active");

        if (!id || this.#persistedRelationshipVersions.get(id) !== version) {
            return false;
        }

        return status === "active"
            ? this.#persistedRelationships.get(id)?.version === version
            : !this.#persistedRelationships.has(id);

    }

    /**
     * Removes a cached persisted relationship by participant pair.
     *
     * @param {number|string} firstParticipantId
     * @param {number|string} secondParticipantId
     */
    removePersistedRelationshipForPair(firstParticipantId, secondParticipantId) {

        const key =
            this.linkKeyFor(
                firstParticipantId,
                secondParticipantId
            );

        const relationship =
            this.#persistedRelationshipsByLegacyKey.get(key) ||
            this.#persistedRelationships.get(
                this.relationshipIdFor(firstParticipantId, secondParticipantId)
            );

        if (relationship) {
            this.#persistedRelationships.delete(relationship.id);
        }

        this.#persistedRelationshipsByLegacyKey.delete(key);

    }

    /**
     * Returns whether a relationship mode is currently supported.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {boolean}
     */
    isSupportedRelationshipMode(mode) {

        return Object.prototype.hasOwnProperty.call(
            RELATIONSHIP_CAPABILITIES,
            String(mode || "")
        );

    }

    /**
     * Returns the relationship capability for a mode.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {Object}
     */
    relationshipCapability(mode) {

        return RELATIONSHIP_CAPABILITIES[this.normalizeLinkMode(mode)];

    }

    /**
     * Returns the canonical relationship mode.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {string}
     */
    normalizeLinkMode(mode) {

        const candidate =
            String(mode || "normal");

        return Object.prototype.hasOwnProperty.call(
            RELATIONSHIP_CAPABILITIES,
            candidate
        )
            ? candidate
            : "normal";

    }

    /**
     * Normalizes relationship metadata for current and future capabilities.
     *
     * Missing metadata returns the certified current fallback behavior.
     *
     * @param {Object} metadata
     *        Relationship metadata supplied by a future owner.
     *
     * @param {Object} fallback
     *        Legacy relationship fallback context.
     *
     * @returns {Object}
     */
    normalizeRelationshipMetadata(metadata = {}, fallback = {}) {

        const source =
            metadata || {};

        const fallbackSource =
            fallback || {};

        const mode =
            this.normalizeLinkMode(source.mode || fallbackSource.mode);

        const capability =
            this.relationshipCapability(mode);

        const hasSourceMetadata =
            Object.keys(source).length > 0;

        const members =
            this.#normalizeRelationshipMembers(
                source.members || fallbackSource.members || [],
                capability
            );

        return Object.freeze({

            schemaVersion:
                RELATIONSHIP_METADATA_SCHEMA_VERSION,

            relationshipId:
                source.relationshipId || fallbackSource.relationshipId || null,

            groupId:
                source.groupId || fallbackSource.groupId || null,

            mode,

            capability:
                capability.id,

            geometryStrategy:
                source.geometryStrategy ||
                fallbackSource.geometryStrategy ||
                capability.geometryStrategy,

            metadataSource:
                source.metadataSource ||
                fallbackSource.metadataSource ||
                (hasSourceMetadata ? "metadata" : "fallback"),

            members:
                Object.freeze(members),

            orientation:
                source.orientation ||
                fallbackSource.orientation ||
                capability.defaults.orientation,

            order:
                Object.freeze(
                    Array.isArray(source.order)
                        ? source.order.slice()
                        : Array.isArray(fallbackSource.order)
                            ? fallbackSource.order.slice()
                        : members.map(member => member.participantId)
                ),

            anchors:
                this.#normalizeAnchors(source.anchors || fallbackSource.anchors),

            options:
                Object.freeze({
                    ...(fallbackSource.options || {}),
                    ...(source.options || {})
                }),

            movement:
                source.movement ||
                fallbackSource.movement ||
                capability.defaults.movement,

            drag:
                source.drag ||
                fallbackSource.drag ||
                capability.defaults.drag,

            rendering:
                source.rendering ||
                fallbackSource.rendering ||
                capability.defaults.rendering,

            persistence:
                Object.freeze({
                    supported:
                        Boolean(capability.supports.persistence),
                    legacyDirectedEdge:
                        true,
                    futureMetadata:
                        false
                }),

            reconciliation:
                Object.freeze({
                    supported:
                        Boolean(capability.supports.multiplayerReconciliation),
                    eventPayload:
                        "link"
                }),

            behavior:
                Object.freeze({
                    static:
                        Boolean(capability.defaults.static),
                    animated:
                        Boolean(capability.defaults.animated)
                })

        });

    }

    /**
     * Returns metadata translated from the current directed-edge model.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {Object}
     */
    relationshipMetadataForPair(firstParticipant, secondParticipant) {

        const relationship =
            this.relationshipForPair(
                firstParticipant,
                secondParticipant
            );

        if (relationship) {
            const persisted = this.#persistedRelationshipsByLegacyKey.get(
                this.linkKeyFor(firstParticipant?.id, secondParticipant?.id)
            );
            const authoritativeRelationship = persisted || relationship;
            const lapMember = authoritativeRelationship.mode === "lap"
                ? authoritativeRelationship.members.find(member => member.relationshipRole === "lap")
                : null;
            const explicitLapSide = normalizeLapSide(lapMember?.lapSide);

            return explicitLapSide
                ? Object.freeze({
                    ...relationship.metadata,
                    orientation: explicitLapSide
                })
                : relationship.metadata;
        }

        const initiator =
            this.relationshipInitiator(firstParticipant, secondParticipant) ||
            firstParticipant;

        const target =
            initiator === firstParticipant
                ? secondParticipant
                : firstParticipant;

        const mode =
            this.linkModeForPair(firstParticipant, secondParticipant);

        const relationshipId =
            this.relationshipIdFor(
                firstParticipant?.id,
                secondParticipant?.id
            );

        return this.normalizeRelationshipMetadata(
            {},
            {
                relationshipId,
                groupId:
                    relationshipId,
                mode,
                members:
                    [
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
                    ]
            }
        );

    }

    /**
     * Returns the stable runtime relationship id for two participants.
     *
     * @param {number|string} firstParticipantId
     * @param {number|string} secondParticipantId
     *
     * @returns {string}
     */
    relationshipIdFor(firstParticipantId, secondParticipantId) {

        return `${RELATIONSHIP_ID_PREFIX}:${this.linkKeyFor(
            firstParticipantId,
            secondParticipantId
        )}`;

    }

    /**
     * Returns all runtime relationship identity objects.
     *
     * @returns {Object[]}
     */
    relationships() {

        const relationships =
            Array.from(this.#persistedRelationships.values());

        const persistedLegacyKeys =
            new Set(
                relationships
                    .map(relationship => relationship.legacyLinkKey)
                    .filter(Boolean)
            );

        this.linkedPairs()
            .forEach(([key, first, second]) => {
                if (persistedLegacyKeys.has(key)) {
                    return;
                }

                const relationship =
                    this.#relationshipFromPair(first, second);

                if (relationship) {
                    relationships.push(relationship);
                }
            });

        return Object.freeze(relationships);

    }

    /**
     * Resolves a runtime relationship by id.
     *
     * @param {string} relationshipId
     *
     * @returns {Object|null}
     */
    relationshipById(relationshipId) {

        const id =
            String(relationshipId || "");

        return this.#persistedRelationships.get(id) ||
            this.relationships()
                .find(relationship => relationship.id === id) ||
            null;

    }

    /**
     * Resolves a runtime relationship for a participant pair.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {Object|null}
     */
    relationshipForPair(firstParticipant, secondParticipant) {

        return this.#relationshipFromPair(
            firstParticipant,
            secondParticipant
        );

    }

    /**
     * Resolves the primary runtime relationship for a participant.
     *
     * @param {number|string|Object} participantOrId
     *
     * @returns {Object|null}
     */
    relationshipForParticipant(participantOrId) {

        return this.relationshipsForParticipant(participantOrId)[0] || null;

    }

    /**
     * Resolves all runtime relationships for a participant.
     *
     * @param {number|string|Object} participantOrId
     *
     * @returns {Object[]}
     */
    relationshipsForParticipant(participantOrId) {

        const participantId =
            Number(
                typeof participantOrId === "object"
                    ? participantOrId?.id
                    : participantOrId
            );

        if (!Number.isFinite(participantId) || participantId <= 0) {
            return Object.freeze([]);
        }

        return Object.freeze(
            this.relationships()
                .filter(relationship =>
                    relationship.memberIds.includes(participantId)
                )
        );

    }

    /**
     * Returns normalized relationship members and roles.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object[]}
     */
    relationshipMembers(relationshipOrId) {

        const relationship =
            this.#resolveRelationship(relationshipOrId);

        return relationship?.members || Object.freeze([]);

    }

    /**
     * Returns immutable presentation projections for active relationships.
     *
     * @returns {Object[]}
     */
    relationshipPresentations() {

        return Object.freeze(
            this.relationships()
                .map(relationship => this.relationshipPresentation(relationship))
                .filter(Boolean)
        );

    }

    /**
     * Returns the current relationship presentation for a participant.
     *
     * @param {number|string|Object} participantOrId
     *
     * @returns {Object|null}
     */
    relationshipPresentationForParticipant(participantOrId) {

        const relationship =
            this.relationshipForParticipant(participantOrId);

        return relationship
            ? this.relationshipPresentation(relationship)
            : null;

    }

    /**
     * Projects authoritative membership into current presentation state.
     *
     * Persisted member order remains unchanged while presence and renderability
     * select the members that participate in the visible layout.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object|null}
     */
    relationshipPresentation(relationshipOrId) {

        const relationship =
            this.#resolveRelationship(relationshipOrId);

        if (!relationship || (relationship.status && relationship.status !== "active")) {
            return null;
        }

        const orderedMembers =
            this.#runtime.order?.orderRelationshipMembers(
                relationship.members || []
            ) || Array.from(relationship.members || []);

        const legacyTargetId =
            Number(
                relationship.targetId ||
                relationship.metadata?.members?.find(member => member.role === "target")?.participantId ||
                0
            ) || null;

        const members =
            orderedMembers.map(member => {

                const participantId = Number(member.participantId);
                const participant = this.#participant(participantId);
                const relationshipRole =
                    member.relationshipRole ||
                    (
                        relationship.mode === "lap" &&
                        (
                            member.role === "initiator" ||
                            participantId === Number(relationship.initiatorId)
                        )
                            ? "lap"
                            : "normal"
                    );
                const lapHostParticipantId =
                    relationshipRole === "lap"
                        ? Number(member.lapHostParticipantId || legacyTargetId || 0) || null
                        : null;
                const lapSide =
                    relationshipRole === "lap"
                        ? normalizeLapSide(member.lapSide) || (
                            relationship.source === "legacy" ? "bottom-right" : null
                        )
                        : null;
                const present = Boolean(
                    participant &&
                    participant.online !== false &&
                    !participant.exiting
                );
                const renderable = Boolean(
                    present &&
                    (participant.avatarEl || participant.webcamVideoEl)
                );

                return Object.freeze({
                    participantId,
                    relationshipRole,
                    permissionRole: member.permissionRole || null,
                    status: String(member.status || "active"),
                    order: Number(member.order || 0),
                    lapHostParticipantId,
                    lapSide,
                    anchor: member.anchor || null,
                    options: member.options || Object.freeze({}),
                    present,
                    renderable
                });

            });

        const activeMembers =
            members.filter(member => member.status === "active");
        const normalMembers =
            activeMembers.filter(member => member.relationshipRole === "normal");
        const lapMembers =
            activeMembers.filter(member => member.relationshipRole === "lap");
        const visibleNormalMembers =
            normalMembers.filter(member => member.renderable);
        const visibleNormalIds =
            new Set(visibleNormalMembers.map(member => member.participantId));
        const visibleLapMembers =
            lapMembers.filter(member =>
                member.renderable &&
                visibleNormalIds.has(Number(member.lapHostParticipantId))
            );
        const visibleMembers = [];

        visibleNormalMembers.forEach(normalMember => {
            visibleMembers.push(normalMember);
            visibleLapMembers
                .filter(lapMember =>
                    Number(lapMember.lapHostParticipantId) === normalMember.participantId
                )
                .forEach(lapMember => visibleMembers.push(lapMember));
        });

        const visibleMemberIds =
            visibleMembers.map(member => member.participantId);
        const selectedOptions = normalizeRelationshipRowOptions(relationship.options);
        const formationResolution = this.#runtime.formations.resolve(
            selectedOptions.formation,
            { normalMemberCount: visibleNormalMembers.length }
        );

        return Object.freeze({
            relationshipId: String(relationship.id),
            relationshipVersion: Math.max(0, Number(relationship.version || 0)),
            relationshipStatus: String(relationship.status || "active"),
            source: String(relationship.source || "persisted"),
            mode: this.normalizeLinkMode(relationship.mode),
            legacyLinkKey: relationship.legacyLinkKey || relationship.key || null,
            members: Object.freeze(activeMembers),
            normalMembers: Object.freeze(normalMembers),
            lapMembers: Object.freeze(lapMembers),
            visibleNormalMembers: Object.freeze(visibleNormalMembers),
            visibleLapMembers: Object.freeze(visibleLapMembers),
            visibleMembers: Object.freeze(visibleMembers),
            visibleMemberIds: Object.freeze(visibleMemberIds),
            options: selectedOptions,
            dancePlayback: relationship.dancePlayback,
            lapAnimations: relationship.lapAnimations,
            selectedFormation: formationResolution.selected,
            effectiveFormation: formationResolution.effective,
            formationFallbackReason: formationResolution.fallbackReason,
            metadata: relationship.metadata || Object.freeze({}),
            projectionKey: [
                String(relationship.id),
                Math.max(0, Number(relationship.version || 0)),
                visibleMemberIds.join(":")
            ].join("|")
        });

    }

    /**
     * Builds one immutable viewer-specific management projection without
     * introducing a second relationship state store.
     *
     * @param {Object|string|null} relationshipOrId
     * @param {Object} options
     * @returns {Object|null}
     */
    relationshipManagementProjection(relationshipOrId, options = {}) {

        const relationship =
            typeof relationshipOrId === "string"
                ? this.relationshipById(relationshipOrId)
                : relationshipOrId;
        const viewerParticipantId = Number(options.viewerParticipantId || 0);
        const participants = options.participants || this.#runtime.state;
        const requests = Array.from(options.requests || []);
        const participantFor = participantId =>
            participants?.get?.(Number(participantId)) || null;
        const viewerMember = relationship?.members?.find(member =>
            Number(member.participantId) === viewerParticipantId
        ) || null;
        const viewerActive = Boolean(
            relationship &&
            relationship.status === "active" &&
            viewerMember &&
            viewerMember.status === "active"
        );
        const viewerPermissionRole = String(
            viewerMember?.permissionRole ||
            relationship?.viewerMembership?.permissionRole ||
            "member"
        );
        const canManage =
            viewerActive &&
            ["creator", "manager"].includes(viewerPermissionRole);
        const members = Array.from(relationship?.members || [])
            .slice()
            .sort((first, second) =>
                Number(first.order) - Number(second.order) ||
                Number(first.participantId) - Number(second.participantId)
            )
            .map(member => {
                const participant = participantFor(member.participantId);
                const relationshipRole = String(member.relationshipRole || "normal");
                const permissionRole = String(member.permissionRole || "member");
                const isViewer = Number(member.participantId) === viewerParticipantId;
                const lapSide = normalizeLapSide(member.lapSide);
                const lapActions = new Map(
                    Array.from(this.#runtime.dances?.participantActions(participant) || [])
                        .map(action => [action.id, action])
                );
                const lapDanceAction = lapActions.get("avatar.lap-dance") || null;
                const lapBounceAction = lapActions.get("avatar.lap-bounce") || null;
                return Object.freeze({
                    participantId: Number(member.participantId),
                    displayName: String(participant?.display_name || "Room member"),
                    relationshipRole,
                    permissionRole,
                    membershipGeneration: member.membershipGeneration,
                    order: Number(member.order || 0),
                    lapHostParticipantId: Number(member.lapHostParticipantId || 0) || null,
                    lapSide,
                    present: Boolean(participant && participant.online !== false && !participant.exiting),
                    renderable: Boolean(participant?.avatarEl || participant?.webcam_enabled || participant?.webcam_path),
                    isViewer,
                    actions: Object.freeze({
                        promote: canManage && !isViewer && permissionRole === "member",
                        demote: canManage && !isViewer && permissionRole === "manager",
                        remove: canManage && !isViewer && permissionRole !== "creator",
                        reorder: canManage && relationshipRole === "normal",
                        switchLapSide: isViewer && viewerActive && relationshipRole === "lap",
                        lapDance: Object.freeze({
                            applicable: Boolean(lapDanceAction),
                            stop: Boolean(lapDanceAction?.active),
                            label: lapDanceAction?.label || "Start Lap Dance"
                        }),
                        lapBounce: Object.freeze({
                            applicable: Boolean(lapBounceAction),
                            stop: Boolean(lapBounceAction?.active),
                            label: lapBounceAction?.label || "Start Lap Bounce"
                        })
                    })
                });
            });
        const normalMembers = members.filter(member => member.relationshipRole === "normal");
        const lapMembers = members.filter(member => member.relationshipRole === "lap");
        const lapSeats = normalMembers.map(host => {
            const occupants = Object.fromEntries(LAP_SIDES.map(side => [
                side,
                lapMembers.find(member =>
                    member.lapHostParticipantId === host.participantId && member.lapSide === side
                ) || null
            ]));
            return Object.freeze({
                hostParticipantId: host.participantId,
                hostName: host.displayName,
                occupants: Object.freeze(occupants),
                availableSides: Object.freeze(LAP_SIDES.filter(side => !occupants[side]))
            });
        });
        const memberIds = new Set(members.map(member => member.participantId));
        const inviteCandidates = Array.from(participants?.values?.() || [])
            .filter(participant => {
                const participantId = Number(participant?.id || 0);
                return participantId > 0
                    && participantId !== viewerParticipantId
                    && !memberIds.has(participantId)
                    && participant.online !== false
                    && !participant.exiting
                    && !this.relationshipForParticipant(participantId);
            })
            .map(participant => Object.freeze({
                participantId: Number(participant.id),
                displayName: String(participant.display_name || "Room member")
            }));
        const visibleRequests = requests
            .filter(request => {
                const relationshipId = String(request?.relationshipId || request?.relationship_id || "");
                const pending = String(request?.status || "pending") === "pending";
                return pending && (!relationship || !relationshipId || relationshipId === relationship.id);
            })
            .map(request => {
                const requesterParticipantId = Number(request.requesterParticipantId || request.requester_participant_id || 0);
                const targetParticipantId = Number(request.targetParticipantId || request.target_participant_id || 0);
                const type = String(request.type || request.request_type || "join-request");
                const pending = String(request.status || "pending") === "pending";
                const requester = participantFor(requesterParticipantId);
                const target = participantFor(targetParticipantId);
                const isRequester = requesterParticipantId === viewerParticipantId;
                const isTarget = targetParticipantId === viewerParticipantId;
                return Object.freeze({
                    id: String(request.id || request.request_id || ""),
                    relationshipId: String(request.relationshipId || request.relationship_id || relationship?.id || ""),
                    relationshipVersion: Math.max(1, Number(request.relationshipVersion || request.relationship_version || relationship?.version || 1)),
                    type,
                    status: String(request.status || "pending"),
                    requestedRelationshipRole: String(request.requestedRelationshipRole || request.requested_relationship_role || "normal"),
                    requestedLapHostParticipantId: Number(request.requestedLapHostParticipantId || request.requested_lap_host_participant_id || 0) || null,
                    requestedLapSide: normalizeLapSide(request.requestedLapSide || request.requested_lap_side),
                    requesterParticipantId,
                    requesterName: String(requester?.display_name || "Room member"),
                    targetParticipantId,
                    targetName: String(target?.display_name || "Room member"),
                    actions: Object.freeze({
                        accept: pending && (type === "invitation" ? isTarget : canManage),
                        reject: pending && (type === "invitation" ? isTarget : canManage),
                        cancel: pending && (isRequester || (type === "invitation" && canManage))
                    })
                });
            });

        if (!relationship && !visibleRequests.length) return null;

        const rowOptions = normalizeRelationshipRowOptions(relationship?.options);
        const visibleNormalCount = normalMembers.filter(member => member.renderable).length;
        const formationResolution = this.#runtime.formations.resolve(
            rowOptions.formation,
            { normalMemberCount: visibleNormalCount }
        );
        const formationOptions = Object.freeze([
            Object.freeze({ id: "horizontal-row", label: "Horizontal Row", available: true }),
            Object.freeze({
                id: "bottom-center-trio",
                label: "Bottom-Center Trio",
                available: normalMembers.length === 3
            }),
            Object.freeze({
                id: "top-center-trio",
                label: "Top-Center Trio",
                available: normalMembers.length === 3
            }),
            Object.freeze({ id: "grid", label: "Grid", available: normalMembers.length >= 2 })
        ]);
        const normalizedDancePlayback = normalizeDancePlayback(relationship?.dancePlayback);
        const dancePlayback = normalizedDancePlayback.state === "playing"
            && !this.#runtime.dances?.isDanceEnabled?.(normalizedDancePlayback.danceId)
            ? Object.freeze({
                ...normalizedDancePlayback,
                state: "stopped",
                danceId: null,
                generation: null,
                startedAtMs: null
            })
            : normalizedDancePlayback;
        const lapAnimations = normalizeLapAnimations(
            relationship?.lapAnimations,
            Number(relationship?.version || 0),
            relationship?.members || []
        );
        const danceOptions = Object.freeze(
            Array.from(this.#runtime.dances?.approvedDances || []).map(dance => Object.freeze({
                id: dance.id,
                label: dance.label,
                durationMs: dance.durationMs,
                enabled: dance.enabled !== false,
                available: Boolean(dance.enabled !== false
                    && relationship && members.length >= 2
                    && normalMembers.some(member => member.renderable))
            }))
        );
        return Object.freeze({
            relationshipId: String(relationship?.id || visibleRequests[0]?.relationshipId || ""),
            relationshipVersion: Math.max(1, Number(relationship?.version || visibleRequests[0]?.relationshipVersion || 1)),
            relationshipStatus: String(relationship?.status || "pending"),
            joinPolicy: String(relationship?.joinPolicy || "approval-required"),
            conversationId: String(relationship?.conversationId || ""),
            viewer: Object.freeze({
                participantId: viewerParticipantId,
                active: viewerActive,
                relationshipRole: String(viewerMember?.relationshipRole || ""),
                permissionRole: viewerPermissionRole
            }),
            members: Object.freeze(members),
            normalMembers: Object.freeze(normalMembers),
            lapMembers: Object.freeze(lapMembers),
            lapSeats: Object.freeze(lapSeats),
            inviteCandidates: Object.freeze(inviteCandidates),
            requests: Object.freeze(visibleRequests),
            rowOptions: Object.freeze({
                schemaVersion: 2,
                rowSpacing: rowOptions.rowSpacing,
                formation: rowOptions.formation,
                transition: rowOptions.transition,
                effectiveFormation: formationResolution.effective,
                formationFallbackReason: formationResolution.fallbackReason,
                formationOptions
            }),
            dancePlayback,
            lapAnimations,
            danceOptions,
            actions: Object.freeze({
                manage: viewerActive,
                invite: canManage,
                requestJoin: Boolean(relationship && relationship.status === "active" && !viewerActive && !this.relationshipForParticipant(viewerParticipantId)),
                setJoinPolicy: canManage,
                reorder: canManage && normalMembers.length > 1,
                configurePosition: canManage,
                controlDance: canManage,
                leave: viewerActive,
                dissolve: canManage
            }),
            divergenceStatus: String(relationship?.divergenceStatus || "synced"),
            refreshRequired: String(relationship?.divergenceStatus || "synced") !== "synced"
        });

    }

    /**
     * Returns relationship metadata for a relationship object or id.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object}
     */
    relationshipMetadata(relationshipOrId) {

        const relationship =
            this.#resolveRelationship(relationshipOrId);

        return relationship?.metadata || this.normalizeRelationshipMetadata();

    }

    /**
     * Returns the relationship capability for a relationship object or id.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object}
     */
    relationshipCapabilityFor(relationshipOrId) {

        const relationship =
            this.#resolveRelationship(relationshipOrId);

        return this.relationshipCapability(relationship?.mode);

    }

    /**
     * Returns whether the relationship mode uses lap geometry.
     *
     * @param {string|null|undefined} mode
     *        Relationship mode.
     *
     * @returns {boolean}
     */
    isLapMode(mode) {

        return this.relationshipCapability(mode).layout === "lap";

    }

    /**
     * Returns the canonical relationship key for two participants.
     *
     * @param {number|string} firstParticipantId
     * @param {number|string} secondParticipantId
     *
     * @returns {string}
     */
    linkKeyFor(firstParticipantId, secondParticipantId) {

        return [
            Number(firstParticipantId),
            Number(secondParticipantId)
        ]
            .sort((a, b) => a - b)
            .join(":");

    }

    /**
     * Determines whether a participant initiates a lap relationship.
     *
     * @param {Object} participant
     *
     * @returns {boolean}
     */
    isLapLinkInitiator(participant) {

        const relationship =
            this.relationshipForParticipant(participant?.id);
        const member =
            relationship?.members?.find(candidate =>
                Number(candidate.participantId) === Number(participant?.id)
            );

        if (member?.relationshipRole) {
            return member.relationshipRole === "lap";
        }

        return this.isLapMode(participant?.link_mode) && Boolean(participant?.linked_to);

    }

    /**
     * Returns the authoritative finite lap side for one active occupant.
     * Legacy directed edges retain the certified bottom-right orientation.
     *
     * @param {Object|number|string} participant
     * @returns {string|null}
     */
    lapSideForParticipant(participant) {
        const participantId = Number(participant?.id || participant || 0);
        const relationship = this.relationshipForParticipant(participantId);
        const member = relationship?.members?.find(candidate =>
            Number(candidate.participantId) === participantId
        );
        if (member?.relationshipRole === "lap") {
            return normalizeLapSide(member.lapSide);
        }
        const state = typeof participant === "object" ? participant : this.#participant(participantId);
        return this.isLapMode(state?.link_mode) && state?.linked_to
            ? "bottom-right"
            : null;
    }

    /**
     * Determines whether a participant is the target of a lap relationship.
     *
     * @param {Object} participant
     *
     * @returns {boolean}
     */
    isLapLinkTarget(participant) {

        if (!participant) {
            return false;
        }

        const relationship =
            this.relationshipForParticipant(participant.id);

        if (relationship?.members?.some(member =>
            member.relationshipRole === "lap" &&
            Number(member.lapHostParticipantId) === Number(participant.id)
        )) {
            return true;
        }

        for (const other of this.#participants.values()) {

            if (
                Number(other.linked_to) === Number(participant.id) &&
                this.isLapMode(other.link_mode)
            ) {
                return true;
            }

        }

        return false;

    }

    /**
     * Returns the relationship mode for a participant pair.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {string}
     */
    linkModeForPair(firstParticipant, secondParticipant) {

        if (Number(firstParticipant?.linked_to) === Number(secondParticipant?.id)) {
            return this.normalizeLinkMode(firstParticipant.link_mode);
        }

        if (Number(secondParticipant?.linked_to) === Number(firstParticipant?.id)) {
            return this.normalizeLinkMode(secondParticipant.link_mode);
        }

        return "normal";

    }

    /**
     * Determines whether a participant belongs to any relationship.
     *
     * @param {Object} participant
     *
     * @returns {boolean}
     */
    isLinked(participant) {

        if (!participant) {
            return false;
        }

        return Boolean(this.#relationshipMembership(participant.id)) ||
            Boolean(participant.linked_to) ||
            this.followersOf(participant.id).length > 0;

    }

    /**
     * Evaluates whether two participants may start a relationship.
     *
     * The decision is side-effect free and deliberately operation-oriented so
     * the future multi-member policy can extend capacity without changing
     * callers.
     *
     * @param {number|string|Object} initiatorOrId
     * @param {number|string|Object} targetOrId
     * @param {Object} options
     *
     * @returns {Object}
     */
    relationshipEligibility(initiatorOrId, targetOrId, options = {}) {

        const initiator =
            typeof initiatorOrId === "object"
                ? this.#participant(initiatorOrId?.id)
                : this.#participant(initiatorOrId);

        const target =
            typeof targetOrId === "object"
                ? this.#participant(targetOrId?.id)
                : this.#participant(targetOrId);

        const initiatorId = Number(initiator?.id || initiatorOrId?.id || initiatorOrId || 0);
        const targetId = Number(target?.id || targetOrId?.id || targetOrId || 0);
        const initiatorRelationship = this.#relationshipMembership(initiatorId);
        const targetRelationship = this.#relationshipMembership(targetId);
        const sameRelationship = Boolean(
            initiatorRelationship &&
            targetRelationship &&
            initiatorRelationship.id === targetRelationship.id
        );
        const isBlocked = typeof options.isBlocked === "function"
            ? options.isBlocked
            : () => false;
        const blocked = Boolean(
            initiator &&
            target &&
            (isBlocked(initiator, target) || isBlocked(target, initiator))
        );

        const decision = reason => Object.freeze({
            allowed: reason === RELATIONSHIP_ELIGIBILITY_REASONS.ELIGIBLE,
            reason,
            initiatorParticipantId: initiatorId || null,
            targetParticipantId: targetId || null,
            initiatorRelationshipId: initiatorRelationship?.id || null,
            targetRelationshipId: targetRelationship?.id || null,
            allowedModes: reason === RELATIONSHIP_ELIGIBILITY_REASONS.ELIGIBLE
                ? CURRENT_RELATIONSHIP_CREATION_MODES
                : Object.freeze([]),
            stateFingerprint: this.#relationshipEligibilityFingerprint(
                initiator,
                target,
                initiatorRelationship,
                targetRelationship,
                blocked
            )
        });

        if (!initiator) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.MISSING_INITIATOR);
        }
        if (!target) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.MISSING_TARGET);
        }
        if (initiatorId === targetId) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.SELF);
        }

        if (blocked) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.BLOCKED);
        }

        const isAvailable = typeof options.isAvailable === "function"
            ? options.isAvailable
            : participant => participant?.online !== false && !participant?.exiting;

        if (!isAvailable(initiator)) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.INITIATOR_UNAVAILABLE);
        }
        if (!isAvailable(target)) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.TARGET_UNAVAILABLE);
        }
        if (sameRelationship) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.ALREADY_RELATED);
        }
        if (initiatorRelationship) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.INITIATOR_RELATIONSHIP);
        }
        if (targetRelationship) {
            return decision(RELATIONSHIP_ELIGIBILITY_REASONS.TARGET_RELATIONSHIP);
        }

        return decision(RELATIONSHIP_ELIGIBILITY_REASONS.ELIGIBLE);

    }

    /**
     * Returns the initiator for a participant pair.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {Object|null}
     */
    relationshipInitiator(firstParticipant, secondParticipant) {

        if (Number(firstParticipant?.linked_to) === Number(secondParticipant?.id)) {
            return firstParticipant;
        }

        if (Number(secondParticipant?.linked_to) === Number(firstParticipant?.id)) {
            return secondParticipant;
        }

        return null;

    }

    //--------------------------------------------------
    // Public Relationship Graph API
    //--------------------------------------------------

    /**
     * Creates or updates a relationship.
     *
     * The initiator owns the directed relationship edge. The target is reset
     * to normal mode to preserve the legacy room.js relationship model.
     *
     * @param {number|string} initiatorId
     * @param {number|string} targetId
     * @param {string} mode
     *
     * @returns {Object|null}
     */
    link(initiatorId, targetId, mode = "normal") {

        const previousRelationships =
            this.#relationshipsForParticipants([
                initiatorId,
                targetId
            ]);

        const initiator = this.#participant(initiatorId);
        const target = this.#participant(targetId);

        if (!initiator || !target) {
            return null;
        }

        initiator.linked_to = Number(target.id);
        initiator.link_mode = this.normalizeLinkMode(mode);

        target.linked_to = null;
        target.link_mode = "normal";

        const relationship =
            this.relationshipForPair(
                initiator,
                target
            );

        if (relationship) {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.CREATED,
                relationship,
                {
                    previous:
                        previousRelationships
                }
            );
        }

        return Object.freeze({

            initiator,

            target,

            mode:
                initiator.link_mode

        });

    }

    /**
     * Applies server relationship state to a participant.
     *
     * @param {number|string} participantId
     * @param {number|string|null} targetId
     * @param {string} mode
     *
     * @returns {Object|null}
     */
    setParticipantRelationship(participantId, targetId, mode = "normal") {

        const previousRelationship =
            this.relationshipForParticipant(participantId);

        const participant = this.#participant(participantId);

        if (!participant) {
            return null;
        }

        participant.linked_to = targetId
            ? Number(targetId)
            : null;

        participant.link_mode =
            this.normalizeLinkMode(mode);

        if (participant.linked_to) {

            const target = this.#participant(participant.linked_to);

            if (target) {
                target.link_mode = "normal";
            }

        }

        const relationship =
            participant.linked_to
                ? this.relationshipForParticipant(participant.id)
                : null;

        if (relationship) {
            this.#emitRelationshipEvent(
                previousRelationship
                    ? RELATIONSHIP_EVENT_TYPES.MODE_CHANGED
                    : RELATIONSHIP_EVENT_TYPES.CREATED,
                relationship,
                {
                    previous:
                        previousRelationship
                }
            );
        } else if (previousRelationship) {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.REMOVED,
                previousRelationship,
                {
                    previous:
                        previousRelationship
                }
            );
        }

        return participant;

    }

    /**
     * Projects one accepted persisted snapshot into the legacy participant
     * fields still consumed by presentation compatibility paths.
     *
     * @param {Object} relationship
     * @param {Array<number|string>} previousMemberIds
     * @returns {Object[]}
     */
    applyPersistedRelationshipProjection(relationship, previousMemberIds = []) {

        if (!relationship || typeof relationship !== "object") {
            return [];
        }

        const active = String(relationship.status || "active") === "active";
        const memberIds = active
            ? Array.from(relationship.memberIds || relationship.members || [])
                .map(member => Number(member?.participantId || member?.participant_id || member || 0))
                .filter(participantId => participantId > 0)
            : [];
        const affectedIds = Array.from(new Set([
            ...Array.from(previousMemberIds || []).map(Number),
            ...memberIds
        ])).filter(participantId => participantId > 0);
        const changed = [];

        affectedIds.forEach(participantId => {
            const participant = this.#participant(participantId);
            if (!participant) return;
            this.#clearParticipant(participant);
            changed.push(participant);
        });

        const projection = active
            ? relationship.legacyProjection || relationship.legacy_projection || null
            : null;
        const initiatorId = Number(
            projection?.initiatorParticipantId || projection?.initiator_participant_id || 0
        );
        const targetId = Number(
            projection?.targetParticipantId || projection?.target_participant_id || 0
        );

        if (memberIds.includes(initiatorId) && memberIds.includes(targetId)) {
            const initiator = this.#participant(initiatorId);
            const target = this.#participant(targetId);
            if (initiator && target) {
                initiator.linked_to = targetId;
                initiator.link_mode = this.normalizeLinkMode(projection?.mode || relationship.mode);
                target.linked_to = null;
                target.link_mode = "normal";
            }
        }

        return changed;

    }

    /**
     * Retires a locally cached active relationship while retaining its known
     * generation so an equal or older snapshot cannot resurrect it.
     *
     * @param {string} relationshipId
     * @returns {Object|null}
     */
    retirePersistedRelationship(relationshipId) {

        const id = String(relationshipId || "");
        const relationship = this.#persistedRelationships.get(id) || null;
        if (!relationship) return null;

        this.#persistedRelationships.delete(id);
        if (relationship.legacyLinkKey) {
            this.#persistedRelationshipsByLegacyKey.delete(relationship.legacyLinkKey);
        }

        return relationship;

    }

    /**
     * Clears relationship state for a participant and its followers.
     *
     * @param {number|string} participantId
     *
     * @returns {Object[]}
     */
    unlinkParticipant(participantId) {

        const previousRelationships =
            this.relationshipsForParticipant(participantId);

        const id = Number(participantId);
        const changed = [];
        const participant = this.#participant(id);

        if (participant) {

            const target = participant.linked_to
                ? this.#participant(participant.linked_to)
                : null;

            this.#clearParticipant(participant);
            changed.push(participant);

            if (target && Number(target.linked_to) === id) {
                this.#clearParticipant(target);
                changed.push(target);
            }

        }

        for (const other of this.#participants.values()) {

            if (Number(other.linked_to) !== id && Number(other.id) !== id) {
                continue;
            }

            this.#clearParticipant(other);

            if (!changed.includes(other)) {
                changed.push(other);
            }

        }

        previousRelationships.forEach(relationship => {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.REMOVED,
                relationship,
                {
                    previous:
                        relationship
                }
            );
        });

        return changed;

    }

    /**
     * Clears relationships pointing at a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object[]}
     */
    unlinkFollowersOf(participantId) {

        const id = Number(participantId);
        const changed = [];
        const previousRelationships = [];

        for (const participant of this.#participants.values()) {

            if (Number(participant.linked_to) !== id) {
                continue;
            }

            const relationship =
                this.relationshipForPair(
                    participant,
                    this.#participant(id)
                );

            if (relationship) {
                previousRelationships.push(relationship);
            }

            this.#clearParticipant(participant);
            changed.push(participant);

        }

        previousRelationships.forEach(relationship => {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.REMOVED,
                relationship,
                {
                    previous:
                        relationship
                }
            );
        });

        return changed;

    }

    /**
     * Returns relationship followers for a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object[]}
     */
    followersOf(participantId) {

        const id = Number(participantId);
        const followers = [];

        for (const participant of this.#participants.values()) {

            if (Number(participant.linked_to) === id) {
                followers.push(participant);
            }

        }

        return followers;

    }

    /**
     * Clears a participant relationship record.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    clearParticipant(participantId) {

        const previousRelationship =
            this.relationshipForParticipant(participantId);

        const participant = this.#participant(participantId);

        if (!participant) {
            return null;
        }

        this.#clearParticipant(participant);

        if (previousRelationship) {
            this.#emitRelationshipEvent(
                RELATIONSHIP_EVENT_TYPES.REMOVED,
                previousRelationship,
                {
                    previous:
                        previousRelationship
                }
            );
        }

        return participant;

    }

    //--------------------------------------------------
    // Public Queries
    //--------------------------------------------------

    /**
     * Returns every linked participant pair.
     *
     * @returns {Array<Array>}
     */
    linkedPairs() {

        const pairs = [];
        const seen = new Set();

        this.#participants.forEach(participant => {

            let partner = null;

            if (participant.linked_to && this.#participants.has(participant.linked_to)) {
                partner = this.#participants.get(participant.linked_to);
            } else {

                for (const other of this.#participants.values()) {

                    if (Number(other.linked_to) === Number(participant.id)) {
                        partner = other;
                        break;
                    }

                }

            }

            if (!partner) {
                return;
            }

            const key =
                this.linkKeyFor(participant.id, partner.id);

            if (seen.has(key)) {
                return;
            }

            seen.add(key);

            pairs.push([
                key,
                participant,
                partner
            ]);

        });

        return pairs;

    }

    /**
     * Returns the participant linked to the requested participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    linkedPartner(participantId) {

        const participant = this.#participant(participantId);

        if (!participant) {
            return null;
        }

        if (participant.linked_to && this.#participants.has(participant.linked_to)) {
            return this.#participants.get(participant.linked_to);
        }

        for (const other of this.#participants.values()) {

            if (Number(other.linked_to) === Number(participant.id)) {
                return other;
            }

        }

        return null;

    }

    /**
     * Rebuilds legacy-compatible link groups.
     *
     * @returns {Map<number, Object[]>}
     */
    rebuildLinkGroups() {

        const groups = new Map();
        const visited = new Set();
        let groupId = 1;

        for (const participant of this.#participants.values()) {

            if (visited.has(participant.id)) {
                continue;
            }

            const group = [];
            const stack = [participant];

            while (stack.length) {

                const current = stack.pop();

                if (!current || visited.has(current.id)) {
                    continue;
                }

                visited.add(current.id);
                group.push(current);

                for (const other of this.#participants.values()) {

                    if (Number(other.linked_to) === Number(current.id) && !visited.has(other.id)) {
                        stack.push(other);
                    }

                }

            }

            groups.set(groupId, group);

            groupId += 1;

        }

        return groups;

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
     * Returns service diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        const pairs = this.linkedPairs();
        const relationships =
            this.relationships();

        return Object.freeze({

            metadataSchemaVersion:
                RELATIONSHIP_METADATA_SCHEMA_VERSION,

            capabilityModes:
                Object.freeze(
                    Object.keys(RELATIONSHIP_CAPABILITIES)
                ),

            capabilities:
                this.relationshipCapabilities(),

            relationshipEventTypes:
                RELATIONSHIP_EVENT_TYPES,

            persistedRelationshipCount:
                this.#persistedRelationships.size,

            persistedRelationshipVersionCount:
                this.#persistedRelationshipVersions.size,

            stalePersistedSnapshotCount:
                this.#stalePersistedSnapshotCount,

            relationshipCount:
                relationships.length,

            relationships:
                relationships.map(relationship => Object.freeze({

                    id:
                        relationship.id,

                    key:
                        relationship.key,

                    source:
                        relationship.source,

                    participantIds:
                        relationship.memberIds,

                    mode:
                        relationship.mode,

                    capability:
                        relationship.capability,

                    memberRoles:
                        relationship.members.map(member =>
                            Object.freeze({
                                participantId:
                                    member.participantId,
                                role:
                                    member.role,
                                order:
                                    member.order
                            })
                        ),

                    metadata:
                        relationship.metadata

                })),

            pairCount:
                pairs.length,

            pairs:
                pairs.map(([key, first, second]) => Object.freeze({

                    key,

                    participantIds:
                        Object.freeze([
                            Number(first.id),
                            Number(second.id)
                        ]),

                    mode:
                        this.linkModeForPair(first, second),

                    metadata:
                        this.relationshipMetadataForPair(first, second)

                }))

        });

    }

    //--------------------------------------------------
    // Private Getters
    //--------------------------------------------------

    /**
     * Returns the participant registry owner.
     *
     * @returns {AvatarStateService}
     */
    get #participants() {

        return this.#runtime.state;

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns a participant from the registry.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    #participant(participantId) {

        return this.#participants.get(participantId) || null;

    }

    /**
     * Returns legacy or persisted relationship membership for a participant.
     *
     * @param {number|string} participantId
     *
     * @returns {Object|null}
     */
    #relationshipMembership(participantId) {

        const id = Number(participantId);

        if (!Number.isFinite(id) || id <= 0) {
            return null;
        }

        const runtimeRelationship = this.relationshipForParticipant(id);

        if (runtimeRelationship) {
            return runtimeRelationship;
        }

        for (const relationship of this.#persistedRelationships.values()) {
            if (relationship.members.some(member => Number(member.participantId) === id)) {
                return relationship;
            }
        }

        return null;

    }

    /**
     * Builds a stable identity for pending-choice revalidation.
     *
     * @returns {string}
     */
    #relationshipEligibilityFingerprint(
        initiator,
        target,
        initiatorRelationship,
        targetRelationship,
        blocked
    ) {

        return JSON.stringify({
            initiator: initiator
                ? [
                    Number(initiator.id),
                    Number(initiator.linked_to || 0),
                    this.normalizeLinkMode(initiator.link_mode),
                    initiator.online !== false,
                    Boolean(initiator.exiting),
                    initiatorRelationship?.id || null
                ]
                : null,
            target: target
                ? [
                    Number(target.id),
                    Number(target.linked_to || 0),
                    this.normalizeLinkMode(target.link_mode),
                    target.online !== false,
                    Boolean(target.exiting),
                    targetRelationship?.id || null
                ]
                : null,
            blocked
        });

    }

    /**
     * Resolves a relationship object or id.
     *
     * @param {string|Object} relationshipOrId
     *
     * @returns {Object|null}
     */
    #resolveRelationship(relationshipOrId) {

        if (
            relationshipOrId &&
            typeof relationshipOrId === "object" &&
            relationshipOrId.id
        ) {
            return relationshipOrId;
        }

        return this.relationshipById(relationshipOrId);

    }

    /**
     * Builds a runtime relationship identity object from a legacy pair.
     *
     * @param {Object} firstParticipant
     * @param {Object} secondParticipant
     *
     * @returns {Object|null}
     */
    #relationshipFromPair(firstParticipant, secondParticipant) {

        if (!firstParticipant || !secondParticipant) {
            return null;
        }

        const initiator =
            this.relationshipInitiator(firstParticipant, secondParticipant);

        if (!initiator) {
            return null;
        }

        const target =
            initiator === firstParticipant
                ? secondParticipant
                : firstParticipant;

        const key =
            this.linkKeyFor(
                firstParticipant.id,
                secondParticipant.id
            );

        const persisted =
            this.#persistedRelationshipsByLegacyKey.get(key) || null;

        const id =
            persisted?.id ||
            this.relationshipIdFor(
                firstParticipant.id,
                secondParticipant.id
            );

        const mode =
            persisted?.mode ||
            this.linkModeForPair(
                firstParticipant,
                secondParticipant
            );

        const capability =
            this.relationshipCapability(mode);

        const members =
            Object.freeze([
                Object.freeze({
                    participantId:
                        Number(initiator.id),
                    role:
                        "initiator",
                    order:
                        0,
                    participant:
                        initiator,
                    anchor:
                        null,
                    options:
                        Object.freeze({})
                }),
                Object.freeze({
                    participantId:
                        Number(target.id),
                    role:
                        "target",
                    order:
                        1,
                    participant:
                        target,
                    anchor:
                        null,
                    options:
                        Object.freeze({})
                })
            ]);

        const metadata =
            this.normalizeRelationshipMetadata(
                {},
                {
                    groupId:
                        id,
                    relationshipId:
                        id,
                    mode,
                    geometryStrategy:
                        persisted?.geometryStrategy ||
                        capability.geometryStrategy,
                    members:
                        persisted?.members?.length
                            ? persisted.members
                            : members.map(member => ({
                                participantId:
                                    member.participantId,
                                role:
                                    member.role,
                                order:
                                    member.order
                            })),
                    anchors:
                        persisted?.anchors,
                    options:
                        persisted?.options,
                    metadataSource:
                        persisted ? "persisted" : "legacy"
                }
            );

        return Object.freeze({

            id,

            key,

            source:
                persisted
                    ? "persisted"
                    : "legacy-directed-edge",

            stable:
                true,

            mode,

            capability:
                capability.id,

            geometryStrategy:
                capability.geometryStrategy,

            initiatorId:
                Number(initiator.id),

            targetId:
                Number(target.id),

            initiator,

            target,

            memberIds:
                Object.freeze(
                    members.map(member => member.participantId)
                ),

            members,

            order:
                metadata.order,

            metadata,

            anchors:
                metadata.anchors,

            options:
                metadata.options,

            persistence:
                metadata.persistence,

            reconciliation:
                metadata.reconciliation

        });

    }

    /**
     * Captures relationships touching any supplied participant ids.
     *
     * @param {Array<number|string>} participantIds
     *
     * @returns {Object[]}
     */
    #relationshipsForParticipants(participantIds = []) {

        const ids =
            new Set(
                Array.from(participantIds || [])
                    .map(id => Number(id))
                    .filter(id => Number.isFinite(id) && id > 0)
            );

        if (!ids.size) {
            return [];
        }

        const seen = new Set();
        const relationships = [];

        this.relationships()
            .forEach(relationship => {
                const touchesParticipant =
                    relationship.memberIds.some(id => ids.has(id));

                if (!touchesParticipant || seen.has(relationship.id)) {
                    return;
                }

                seen.add(relationship.id);
                relationships.push(relationship);
            });

        return relationships;

    }

    /**
     * Normalizes a persisted relationship payload from the API layer.
     *
     * @param {Object} relationship
     *
     * @returns {Object|null}
     */
    #normalizePersistedRelationshipPayload(relationship) {

        if (!relationship || typeof relationship !== "object") {
            return null;
        }

        const id =
            String(
                relationship.id ||
                relationship.relationship_id ||
                ""
            );

        if (!id) {
            return null;
        }

        const legacyLinkKey =
            String(
                relationship.legacy_link_key ||
                relationship.legacyLinkKey ||
                ""
            );

        const mode =
            this.normalizeLinkMode(relationship.mode);

        const capability =
            this.relationshipCapability(mode);

        const members =
            Array.from(relationship.members || [])
                .map((member, index) => Object.freeze({
                    participantId:
                        Number(
                            member?.participantId ||
                            member?.participant_id ||
                            0
                        ),
                    role:
                        String(member?.role || member?.member_role || ""),
                    relationshipRole:
                        String(
                            member?.relationshipRole ||
                            member?.relationship_role ||
                            "normal"
                        ),
                    permissionRole:
                        member?.permissionRole ||
                        member?.permission_role ||
                        null,
                    status:
                        String(member?.status || "active"),
                    order:
                        Number.isFinite(Number(member?.order ?? member?.member_order))
                            ? Number(member?.order ?? member?.member_order)
                            : index,
                    userId:
                        Number(member?.userId || member?.user_id || 0) || null,
                    membershipGeneration:
                        String(
                            member?.membershipGeneration ||
                            member?.membership_generation ||
                            ""
                        ) || null,
                    effectiveAt:
                        member?.effectiveAt ||
                        member?.membership_effective_at ||
                        null,
                    visibleAfterMessageId:
                        member?.visibleAfterMessageId ??
                        member?.visible_after_message_id ??
                        null,
                    lapHostParticipantId:
                        Number(
                            member?.lapHostParticipantId ||
                            member?.lap_host_participant_id ||
                            0
                        ) || null,
                    lapSide:
                        normalizeLapSide(
                            member?.lapSide ||
                            member?.lap_side
                        ),
                    anchor:
                        member?.anchor || null,
                    options:
                        Object.freeze({
                            ...(member?.options || {})
                        })
                }))
                .filter(member => member.participantId > 0);

        const uniqueMemberIds =
            new Set(members.map(member => member.participantId));

        if (uniqueMemberIds.size !== members.length) {
            return null;
        }

        const normalMemberIds = new Set(
            members
                .filter(member => member.relationshipRole === "normal")
                .map(member => member.participantId)
        );
        const occupiedLapSeats = new Set();
        for (const member of members) {
            if (member.relationshipRole === "normal") {
                if (member.lapHostParticipantId !== null || member.lapSide !== null) return null;
                continue;
            }
            if (member.relationshipRole !== "lap"
                || !normalMemberIds.has(member.lapHostParticipantId)
                || !member.lapSide) {
                return null;
            }
            const seatKey = `${member.lapHostParticipantId}:${member.lapSide}`;
            if (occupiedLapSeats.has(seatKey)) return null;
            occupiedLapSeats.add(seatKey);
        }

        return Object.freeze({
            id,
            version:
                Math.max(1, Number(relationship.version || 1)),
            status:
                String(relationship.status || "active"),
            creatorParticipantId:
                Number(
                    relationship.creatorParticipantId ||
                    relationship.creator_participant_id ||
                    0
                ) || null,
            joinPolicy:
                String(
                    relationship.joinPolicy ||
                    relationship.join_policy ||
                    "approval-required"
                ),
            conversationId:
                String(
                    relationship.conversationId ||
                    relationship.conversation_public_id ||
                    id
                ),
            viewerMembership:
                relationship.viewerMembership ||
                relationship.viewer_membership ||
                null,
            chatAccess:
                Object.freeze({
                    active:
                        relationship.chatAccess?.active !== false &&
                        relationship.chat_access?.active !== false,
                    conversationId:
                        String(
                            relationship.chatAccess?.conversationId ||
                            relationship.chat_access?.conversation_id ||
                            relationship.conversationId ||
                            relationship.conversation_public_id ||
                            id
                        ),
                    visibleAfterMessageId:
                        Math.max(0, Number(
                            relationship.chatAccess?.visibleAfterMessageId ??
                            relationship.chat_access?.visible_after_message_id ??
                            relationship.viewerMembership?.visibleAfterMessageId ??
                            relationship.viewer_membership?.visible_after_message_id ??
                            0
                        ))
                }),
            legacyLinkKey,
            mode,
            capability:
                relationship.capability || capability.id,
            geometryStrategy:
                relationship.geometryStrategy ||
                relationship.geometry_strategy ||
                capability.geometryStrategy,
            members:
                Object.freeze(members),
            memberIds:
                Object.freeze(
                    members.map(member => member.participantId)
                ),
            order:
                Object.freeze(
                    members
                        .slice()
                        .sort((first, second) => first.order - second.order)
                        .map(member => member.participantId)
                ),
            metadata:
                relationship.metadata || {},
            anchors:
                relationship.anchors || relationship.metadata?.anchors || null,
            options:
                normalizeRelationshipRowOptions(
                    relationship.options || relationship.metadata?.options || {}
                ),
            dancePlayback:
                normalizeDancePlayback(
                    relationship.dancePlayback || relationship.dance_playback || {}
                ),
            lapAnimations:
                normalizeLapAnimations(
                    relationship.lapAnimations || relationship.lap_animations || [],
                    Math.max(1, Number(relationship.version || 1)),
                    members
                ),
            divergenceStatus:
                relationship.divergence_status ||
                relationship.divergenceStatus ||
                "synced",
            legacyProjection:
                relationship.legacyProjection ||
                relationship.legacy_projection ||
                null,
            source:
                "persisted",
            stable:
                true
        });

    }

    /**
     * Emits a relationship lifecycle event.
     *
     * @param {string} type
     * @param {Object} relationship
     * @param {Object} details
     */
    #emitRelationshipEvent(type, relationship, details = {}) {

        if (!relationship || !this.#relationshipEventListeners.size) {
            return;
        }

        const event =
            Object.freeze({
                type:
                    type || RELATIONSHIP_EVENT_TYPES.METADATA_CHANGED,
                relationshipId:
                    relationship.id,
                relationship,
                participantIds:
                    relationship.memberIds,
                mode:
                    relationship.mode,
                capability:
                    relationship.capability,
                metadata:
                    relationship.metadata,
                previous:
                    details.previous || null,
                changes:
                    Object.freeze({
                        ...(details.changes || {})
                    }),
                source:
                    details.source || "runtime"
            });

        this.#relationshipEventListeners.forEach(listener => {
            try {
                listener(event);
            } catch (error) {
                void error;
            }
        });

    }

    /**
     * Normalizes relationship member metadata.
     *
     * @param {Object[]} members
     * @param {Object} capability
     *
     * @returns {Object[]}
     */
    #normalizeRelationshipMembers(members, capability) {

        const roles =
            capability.roles || [];

        return Array.from(members || [])
            .slice(0, capability.participantLimit || 2)
            .map((member, index) => Object.freeze({

                participantId:
                    Number(member?.participantId || 0),

                role:
                    roles.includes(member?.role)
                        ? member.role
                        : roles[index] || "member",

                order:
                    Number.isFinite(Number(member?.order))
                        ? Number(member.order)
                        : index,

                anchor:
                    member?.anchor || null,

                options:
                    Object.freeze({
                        ...(member?.options || {})
                    })

            }))
            .filter(member => member.participantId > 0);

    }

    /**
     * Normalizes optional relationship anchor metadata.
     *
     * @param {Object|null|undefined} anchors
     *
     * @returns {Object}
     */
    #normalizeAnchors(anchors) {

        const source =
            anchors || {};

        return Object.freeze({

            relationship:
                source.relationship || null,

            members:
                Object.freeze({
                    ...(source.members || {})
                }),

            mode:
                Object.freeze({
                    ...(source.mode || {})
                })

        });

    }

    /**
     * Clears relationship state from a participant object.
     *
     * @param {Object} participant
     */
    #clearParticipant(participant) {

        participant.linked_to = null;
        participant.link_mode = "normal";

    }

}

export default AvatarRelationshipService;
