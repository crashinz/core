import { SynchronizedSwayDance } from "../dances/synchronized-sway-dance.js";
import { SynchronizedBounceDance } from "../dances/synchronized-bounce-dance.js";
import { LapDance } from "../dances/lap-dance.js";
import { LapBounce } from "../dances/lap-bounce.js";

const APPROVED_DANCES = Object.freeze([
    Object.freeze({ id: "synchronized-sway", label: "Synchronized Sway", durationMs: 2400 }),
    Object.freeze({ id: "synchronized-bounce", label: "Synchronized Bounce", durationMs: 1600 })
]);
const APPROVED_LAP_ANIMATIONS = Object.freeze([
    Object.freeze({ id: "lap_dance", label: "Lap Dance", durationMs: LapDance.durationMs }),
    Object.freeze({ id: "lap_bounce", label: "Lap Bounce", durationMs: LapBounce.durationMs })
]);

function normalizeCapabilityPolicy(policy = {}) {
    const projected = new Map(Array.from(policy?.dances || []).map(definition => [
        String(definition?.id || ""),
        definition
    ]));
    const enabled = policy?.enabled && typeof policy.enabled === "object"
        ? policy.enabled
        : {};
    const definitions = [...APPROVED_DANCES, ...APPROVED_LAP_ANIMATIONS].map((fallback, index) => {
        const source = projected.get(fallback.id) || {};
        const sourceEnabled = Object.hasOwn(enabled, fallback.id)
            ? enabled[fallback.id]
            : source.enabled;
        return Object.freeze({
            ...fallback,
            label: String(source.label || fallback.label),
            description: String(source.description || ""),
            kind: String(source.kind || (fallback.id.startsWith("lap_") ? "lap" : "relationship")),
            order: Number(source.order || (index + 1) * 10),
            defaultEnabled: source.defaultEnabled !== false,
            enabled: sourceEnabled !== false
        });
    });
    const enabledValues = Object.freeze(Object.fromEntries(
        definitions.map(definition => [definition.id, definition.enabled])
    ));
    const enabledCount = definitions.filter(definition => definition.enabled).length;
    return Object.freeze({
        settingKey: String(policy?.settingKey || "avatar_dance_capabilities"),
        revision: Math.max(0, Number(policy?.revision || 0)),
        categoryId: String(policy?.categoryId || "avatar-interactions"),
        sectionId: String(policy?.sectionId || "dances"),
        categoryLabel: String(policy?.categoryLabel || "Avatar Interactions"),
        sectionLabel: String(policy?.sectionLabel || "Dances"),
        description: String(policy?.description || "Choose which optional avatar dances members may start in this community."),
        enabled: enabledValues,
        dances: Object.freeze(definitions),
        enabledCount,
        totalCount: definitions.length,
        allEnabled: enabledCount === definitions.length,
        allDisabled: enabledCount === 0
    });
}

/**
 * Owns finite synchronized relationship-dance playback and scheduler lifetime.
 */
export class AvatarDanceService {

    #runtime;
    #context = null;
    #registry = new Map();
    #active = new Map();
    #activeLap = new Map();
    #pendingLap = new Set();
    #motionQuery = null;
    #motionQueryListener = null;
    #serial = 0;
    #capabilityPolicy = normalizeCapabilityPolicy();
    #diagnostics = {
        started: 0,
        stopped: 0,
        suspended: 0,
        resumed: 0,
        stale: 0,
        frames: 0,
        fallback: 0
    };
    #lapDiagnostics = {
        started: 0,
        stopped: 0,
        switched: 0,
        duplicate: 0,
        stale: 0,
        frames: 0,
        reducedMotion: 0,
        unavailable: 0,
        mutations: 0
    };
    #lastOperation = null;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    initialize() {
        this.#register(SynchronizedSwayDance);
        this.#register(SynchronizedBounceDance);
    }

    configure(context = {}) {
        this.#context = context;
        const configuredPolicy = context?.getConfig?.()?.danceCapability;
        if (configuredPolicy) this.configureCapabilityPolicy(configuredPolicy, { reason: "runtime-configure" });
        this.#removeMotionPreferenceListener();
        const matchMedia = context.matchMedia || globalThis.matchMedia;
        this.#motionQuery = typeof matchMedia === "function"
            ? matchMedia.call(context.window || globalThis, "(prefers-reduced-motion: reduce)")
            : null;
        this.#motionQueryListener = () => this.#reconcileMotionPreference();
        this.#motionQuery?.addEventListener?.("change", this.#motionQueryListener);
    }

    destroy() {
        this.#removeMotionPreferenceListener();
        this.#stopAllLap("runtime-destroy");
        this.stopAll("runtime-destroy");
        this.#registry.clear();
        this.#context = null;
        this.#lastOperation = null;
    }

    get approvedDances() {
        return Object.freeze(this.#capabilityPolicy.dances.filter(definition => definition.kind === "relationship"));
    }

    get approvedLapAnimations() {
        return Object.freeze(this.#capabilityPolicy.dances.filter(definition => definition.kind === "lap"));
    }

    get capabilityPolicy() {
        return this.#capabilityPolicy;
    }

    isDanceEnabled(danceId) {
        return Boolean(this.#capabilityPolicy.enabled[String(danceId || "")]);
    }

    configureCapabilityPolicy(policy = {}, { reason = "dance-capability-policy" } = {}) {
        const next = normalizeCapabilityPolicy(policy);
        if (next.revision < this.#capabilityPolicy.revision) {
            this.#diagnostics.stale += 1;
            this.#record("dance-capability-policy-stale", {
                currentRevision: this.#capabilityPolicy.revision,
                receivedRevision: next.revision,
                reason
            });
            return false;
        }
        const changed = next.revision !== this.#capabilityPolicy.revision
            || JSON.stringify(next.enabled) !== JSON.stringify(this.#capabilityPolicy.enabled);
        this.#capabilityPolicy = next;
        if (!changed) return false;
        Array.from(this.#active.entries()).forEach(([relationshipId, operation]) => {
            if (!this.isDanceEnabled(operation.danceId)) {
                this.stop(relationshipId, { reason: `${reason}-disabled` });
            }
        });
        Array.from(this.#activeLap.entries()).forEach(([key, operation]) => {
            if (!this.isDanceEnabled(operation.mode)) {
                this.#stopLapOperation(key, { reason: `${reason}-disabled`, scheduleRefresh: false });
            }
        });
        this.#runtime.coordinator?.scheduleRelationshipRefresh?.({ all: true, reason });
        this.#record("dance-capability-policy-configured", {
            revision: next.revision,
            enabledCount: next.enabledCount,
            totalCount: next.totalCount,
            reason
        });
        return true;
    }

    reconcile(relationship, { reason = "relationship-reconcile" } = {}) {
        const synchronized = this.#reconcileSynchronizedDance(relationship, { reason });
        const lap = this.#reconcileLapAnimations(relationship, { reason });
        return Object.freeze({ ...synchronized, lap });
    }

    #reconcileSynchronizedDance(relationship, { reason = "relationship-reconcile" } = {}) {
        const relationshipId = String(relationship?.id || relationship?.relationship_id || "");
        if (!relationshipId) return Object.freeze({ accepted: false, reason: "missing-relationship" });
        const playback = relationship?.dancePlayback || relationship?.dance_playback || {};
        if (String(playback.state || "stopped") !== "playing") {
            this.stop(relationshipId, { reason });
            return Object.freeze({ accepted: true, playing: false });
        }

        const danceId = String(playback.danceId || playback.dance_id || "");
        const generation = String(playback.generation || "");
        const startedAtMs = Number(playback.startedAtMs || playback.started_at_ms || 0);
        const strategy = this.#registry.get(danceId);
        if (!strategy || !this.isDanceEnabled(danceId)
            || !generation || !Number.isFinite(startedAtMs) || startedAtMs <= 0) {
            this.#diagnostics.fallback += 1;
            this.#record("relationship-dance-rejected", {
                relationshipId,
                generation: generation || null,
                danceId: danceId || null,
                reason: strategy && !this.isDanceEnabled(danceId)
                    ? "dance-disabled"
                    : "invalid-playback"
            });
            const rejectedReason = strategy && !this.isDanceEnabled(danceId)
                ? "dance-disabled"
                : "invalid-playback";
            this.stop(relationshipId, { reason: rejectedReason });
            return Object.freeze({ accepted: false, reason: rejectedReason });
        }

        const current = this.#active.get(relationshipId);
        if (current?.generation === generation) {
            current.relationshipVersion = Math.max(1, Number(relationship.version || current.relationshipVersion));
            if (current.suspended) {
                current.suspended = false;
                this.#diagnostics.resumed += 1;
                this.#schedule(current);
            }
            return Object.freeze({ accepted: true, playing: true, duplicate: true });
        }
        if (current) this.stop(relationshipId, { reason: "playback-replaced" });

        const operation = {
            token: ++this.#serial,
            relationshipId,
            relationshipVersion: Math.max(1, Number(relationship.version || 1)),
            generation,
            danceId,
            startedAtMs,
            frameHandle: null,
            suspended: false
        };
        this.#active.set(relationshipId, operation);
        this.#diagnostics.started += 1;
        this.#lastOperation = Object.freeze({
            relationshipId,
            relationshipVersion: operation.relationshipVersion,
            generation,
            danceId,
            outcome: "started",
            reason
        });
        this.#record("relationship-dance-started", this.#lastOperation);
        this.#schedule(operation);
        return Object.freeze({ accepted: true, playing: true });
    }

    participantActions(participant) {
        const participantId = Number(participant?.id || 0);
        const viewerParticipantId = Number(this.#context?.getConfig?.()?.myParticipantId || 0);
        const relationship = this.#runtime.relationships?.relationshipForParticipant(participantId);
        const member = relationship?.members?.find(candidate =>
            Number(candidate.participantId) === participantId
        );
        if (!relationship || !member || member.relationshipRole !== "lap") return Object.freeze([]);
        const hostParticipantId = Number(member.lapHostParticipantId || 0);
        const state = relationship.lapAnimations?.find(candidate =>
            Number(candidate.occupantParticipantId) === participantId
        ) || null;
        const viewerIsOccupant = participantId === viewerParticipantId;
        const viewerIsHost = hostParticipantId === viewerParticipantId;
        const occupants = relationship.members.filter(candidate =>
            candidate.relationshipRole === "lap"
            && Number(candidate.lapHostParticipantId) === hostParticipantId
        );
        const canStart = viewerIsOccupant
            && occupants.length === 1
            && !participant.webcam_enabled
            && !participant.webcam_path;
        const pending = this.#pendingLap.has(String(relationship.id));
        const actionIds = Object.freeze({
            lap_dance: "avatar.lap-dance",
            lap_bounce: "avatar.lap-bounce"
        });
        const definitions = this.approvedLapAnimations.map(definition => ({
            id: actionIds[definition.id],
            mode: definition.id,
            label: definition.label,
            enabled: definition.enabled
        }));
        return Object.freeze(definitions.map(definition => {
            const active = state?.mode === definition.mode;
            const geometry = canStart && definition.enabled
                ? this.#resolveLapGeometry(relationship, state || {
                    hostParticipantId,
                    occupantParticipantId: participantId,
                    lapSide: member.lapSide,
                    mode: definition.mode
                }, definition.mode)
                : null;
            const applicable = active
                ? viewerIsOccupant || viewerIsHost
                : definition.enabled && canStart && Boolean(geometry);
            return applicable ? Object.freeze({
                id: definition.id,
                label: `${active ? "Stop" : "Start"} ${definition.label}`,
                active,
                disabled: pending,
                applicable: true,
                mode: active ? "none" : definition.mode
            }) : null;
        }).filter(Boolean));
    }

    async performParticipantAction(actionId, participantId) {
        const action = this.participantActions(
            this.#runtime.state?.get?.(Number(participantId))
        ).find(candidate => candidate.id === actionId);
        if (!action || action.disabled) return false;
        const relationship = this.#runtime.relationships?.relationshipForParticipant(participantId);
        const occupant = relationship?.members?.find(member =>
            Number(member.participantId) === Number(participantId)
            && member.relationshipRole === "lap"
        );
        if (!relationship || !occupant) return false;
        return this.setLapAnimation({
            relationship,
            hostParticipantId: occupant.lapHostParticipantId,
            occupantParticipantId: occupant.participantId,
            occupantMembershipGeneration: occupant.membershipGeneration,
            mode: action.mode,
            reason: "participant-action"
        });
    }

    async setLapAnimation({
        relationship,
        hostParticipantId,
        occupantParticipantId,
        occupantMembershipGeneration,
        mode,
        reason = "lap-animation-action"
    } = {}) {
        const current = typeof relationship === "string"
            ? this.#runtime.relationships?.relationshipById(relationship)
            : relationship;
        const relationshipId = String(current?.id || "");
        if (!relationshipId || this.#pendingLap.has(relationshipId)
            || !["none", "lap_dance", "lap_bounce"].includes(String(mode || ""))) {
            return false;
        }
        if (mode !== "none" && !this.isDanceEnabled(mode)) {
            this.#context?.showWarning?.("That dance is disabled for this community.");
            return false;
        }
        const operationId = this.#operationId("lap-animation");
        this.#pendingLap.add(relationshipId);
        this.#stopLapOperation(this.#lapKey(relationshipId, occupantParticipantId), {
            reason: `${reason}-baseline-restore`,
            scheduleRefresh: false
        });
        try {
            const response = await this.#context?.mutateRelationship?.({
                action: "set_lap_animation",
                relationship_id: relationshipId,
                expected_version: Number(current.version || 0),
                operation_id: operationId,
                mode,
                host_participant_id: Number(hostParticipantId || 0),
                occupant_participant_id: Number(occupantParticipantId || 0),
                occupant_membership_generation: String(occupantMembershipGeneration || "")
            });
            if (!response?.ok || !response.relationship) {
                throw new Error(response?.error || "The lap animation was not accepted.");
            }
            const reconciled = this.#runtime.relationships?.upsertPersistedRelationship(
                response.relationship
            );
            this.#lapDiagnostics.mutations += 1;
            this.reconcile(reconciled, { reason });
            this.#runtime.coordinator?.scheduleRelationshipRefresh?.({
                all: true,
                reason
            });
            this.#context?.onLapAnimationStateChanged?.({ relationshipId, reason });
            return response;
        } catch (error) {
            this.reconcile(current, { reason: `${reason}-rejected` });
            this.#context?.showWarning?.(error?.message || "The lap animation could not be changed.");
            return false;
        } finally {
            this.#pendingLap.delete(relationshipId);
            this.#context?.onLapAnimationStateChanged?.({ relationshipId, reason: `${reason}-settled` });
        }
    }

    async stopLapAnimationsForDrag(participant) {
        const relationship = this.#runtime.relationships?.relationshipForParticipant(participant?.id);
        if (!relationship?.id) return relationship;
        const relationshipId = String(relationship.id);
        this.#stopLapOperationsForRelationship(relationshipId, "local-drag-start", false);
        try {
            const response = await this.#context?.mutateRelationship?.({
                action: "stop_lap_animations_for_drag",
                relationship_id: relationshipId,
                expected_version: Number(relationship.version || 0),
                operation_id: this.#operationId("lap-drag-stop")
            });
            if (!response?.ok || !response.relationship) {
                throw new Error(response?.error || "The relationship changed before dragging began.");
            }
            const reconciled = this.#runtime.relationships?.upsertPersistedRelationship(response.relationship);
            this.reconcile(reconciled, { reason: "local-drag-start" });
            this.#runtime.coordinator?.scheduleRelationshipRefresh?.({
                all: true,
                reason: "local-drag-start"
            });
            return reconciled;
        } catch (error) {
            this.reconcile(relationship, { reason: "local-drag-stop-rejected" });
            this.#context?.showWarning?.(error?.message || "Dragging could not begin.");
            return null;
        }
    }

    presentationEnvelopeFor(relationshipId, occupantParticipantId) {
        if (this.#reducedMotion()) return null;
        return this.#activeLap.get(this.#lapKey(relationshipId, occupantParticipantId))?.geometry?.envelope || null;
    }

    suspend(relationshipId, reason = "safety-cancel") {
        const operation = this.#active.get(String(relationshipId || ""));
        if (!operation) return false;
        operation.suspended = true;
        this.#cancelFrame(operation);
        this.#runtime.renderer?.clearRelationshipDanceOffset(operation.relationshipId);
        this.#diagnostics.suspended += 1;
        this.#lastOperation = Object.freeze({
            relationshipId: operation.relationshipId,
            relationshipVersion: operation.relationshipVersion,
            generation: operation.generation,
            danceId: operation.danceId,
            outcome: "suspended",
            reason
        });
        this.#record("relationship-dance-suspended", this.#lastOperation);
        return true;
    }

    stop(relationshipId, { reason = "stopped", generation = null } = {}) {
        const id = String(relationshipId || "");
        const operation = this.#active.get(id);
        if (!operation) {
            this.#runtime.renderer?.clearRelationshipDanceOffset(id);
            return false;
        }
        if (generation && generation !== operation.generation) {
            this.#diagnostics.stale += 1;
            this.#record("relationship-dance-stale-stop", {
                relationshipId: id,
                generation,
                activeGeneration: operation.generation,
                reason
            });
            return false;
        }
        this.#cancelFrame(operation);
        this.#runtime.renderer?.clearRelationshipDanceOffset(id);
        this.#active.delete(id);
        this.#diagnostics.stopped += 1;
        this.#lastOperation = Object.freeze({
            relationshipId: id,
            relationshipVersion: operation.relationshipVersion,
            generation: operation.generation,
            danceId: operation.danceId,
            outcome: "stopped",
            reason
        });
        this.#record("relationship-dance-stopped", this.#lastOperation);
        return true;
    }

    stopAll(reason = "stopped") {
        Array.from(this.#active.keys()).forEach(relationshipId => {
            this.stop(relationshipId, { reason });
        });
    }

    getDiagnostics() {
        return Object.freeze({
            capabilityPolicy: this.#capabilityPolicy,
            approvedDances: this.approvedDances,
            approvedLapAnimations: this.approvedLapAnimations,
            registeredDanceIds: Object.freeze(Array.from(this.#registry.keys())),
            activeRelationshipCount: this.#active.size,
            activeLapAnimationCount: this.#activeLap.size,
            pendingLapMutationCount: this.#pendingLap.size,
            ...this.#diagnostics,
            lap: Object.freeze({ ...this.#lapDiagnostics }),
            lastOperation: this.#lastOperation
        });
    }

    #reconcileLapAnimations(relationship, { reason = "relationship-reconcile" } = {}) {
        const relationshipId = String(relationship?.id || relationship?.relationship_id || "");
        if (!relationshipId) return Object.freeze({ accepted: false, reason: "missing-relationship" });
        const desired = Array.from(relationship?.lapAnimations || relationship?.lap_animations || []);
        const desiredKeys = new Set(desired.map(state =>
            this.#lapKey(relationshipId, state.occupantParticipantId || state.occupant_participant_id)
        ));
        Array.from(this.#activeLap.keys()).forEach(key => {
            const operation = this.#activeLap.get(key);
            if (operation?.relationshipId === relationshipId && !desiredKeys.has(key)) {
                this.#stopLapOperation(key, { reason, scheduleRefresh: true });
            }
        });

        let accepted = 0;
        desired.forEach(state => {
            const mode = String(state?.mode || "");
            const occupantParticipantId = Number(
                state?.occupantParticipantId || state?.occupant_participant_id || 0
            );
            const hostParticipantId = Number(
                state?.hostParticipantId || state?.host_participant_id || 0
            );
            const generation = String(state?.generation || "");
            const startedAtMs = Number(state?.startedAtMs || state?.started_at_ms || 0);
            const key = this.#lapKey(relationshipId, occupantParticipantId);
            const geometry = this.#resolveLapGeometry(relationship, state, mode);
            const occupant = this.#runtime.state?.get?.(occupantParticipantId);
            if (!geometry || !occupant || occupant.webcam_enabled || occupant.webcam_path
                || !["lap_dance", "lap_bounce"].includes(mode)
                || !this.isDanceEnabled(mode)
                || !generation || startedAtMs <= 0) {
                this.#stopLapOperation(key, {
                    reason: this.isDanceEnabled(mode) ? "lap-animation-inapplicable" : "dance-disabled",
                    scheduleRefresh: true
                });
                this.#lapDiagnostics.unavailable += 1;
                return;
            }
            const current = this.#activeLap.get(key);
            if (current?.generation === generation) {
                const geometryChanged = JSON.stringify(current.geometry) !== JSON.stringify(geometry);
                current.relationshipVersion = Number(relationship.version || current.relationshipVersion);
                current.geometry = geometry;
                current.hostParticipantId = hostParticipantId;
                if (geometryChanged) {
                    this.#runtime.coordinator?.scheduleRelationshipRefresh?.({
                        all: true,
                        reason: "lap-animation-geometry-change"
                    });
                }
                this.#lapDiagnostics.duplicate += 1;
                accepted += 1;
                return;
            }
            if (current) {
                this.#lapDiagnostics.switched += 1;
                this.#stopLapOperation(key, { reason: "lap-animation-switched", scheduleRefresh: false });
            }
            const operation = {
                token: ++this.#serial,
                key,
                relationshipId,
                relationshipVersion: Number(relationship.version || 0),
                hostParticipantId,
                occupantParticipantId,
                occupantMembershipGeneration: String(
                    state?.occupantMembershipGeneration || state?.occupant_membership_generation || ""
                ),
                lapSide: String(state?.lapSide || state?.lap_side || ""),
                mode,
                generation,
                startedAtMs,
                geometry,
                frameHandle: null
            };
            this.#activeLap.set(key, operation);
            this.#lapDiagnostics.started += 1;
            this.#record("lap-animation-started", {
                relationshipId,
                relationshipVersion: operation.relationshipVersion,
                hostParticipantId,
                occupantParticipantId,
                mode,
                generation,
                reason,
                reducedMotion: this.#reducedMotion()
            });
            if (this.#reducedMotion()) {
                this.#lapDiagnostics.reducedMotion += 1;
                this.#applyLapSample(operation, Object.freeze({ translateY: 0, rotateDegrees: 0 }));
            } else {
                this.#scheduleLap(operation);
            }
            this.#runtime.coordinator?.scheduleRelationshipRefresh?.({
                all: true,
                reason: "lap-animation-started"
            });
            accepted += 1;
        });
        return Object.freeze({ accepted: true, activeCount: accepted });
    }

    #resolveLapGeometry(relationship, state, mode) {
        const hostParticipantId = Number(state?.hostParticipantId || state?.host_participant_id || 0);
        const occupantParticipantId = Number(state?.occupantParticipantId || state?.occupant_participant_id || 0);
        const host = this.#runtime.state?.get?.(hostParticipantId);
        const occupant = this.#runtime.state?.get?.(occupantParticipantId);
        if (!host || !occupant) return null;
        const member = relationship?.members?.find(candidate =>
            Number(candidate.participantId) === occupantParticipantId
            && candidate.relationshipRole === "lap"
            && Number(candidate.lapHostParticipantId) === hostParticipantId
        );
        if (!member || member.lapSide !== String(state?.lapSide || state?.lap_side || member.lapSide)) return null;
        const hostDimensions = this.#runtime.renderer?.renderedAvatarDimensions(host);
        const occupantDimensions = this.#runtime.renderer?.renderedAvatarDimensions(occupant);
        if (!hostDimensions || !occupantDimensions) return null;
        return this.#runtime.layout?.lapAnimationGeometry({
            mode,
            hostDimensions,
            occupantDimensions,
            lapSide: member.lapSide,
            anchor: member.anchor || null,
            danceStrategy: LapDance,
            bounceStrategy: LapBounce
        }) || null;
    }

    #scheduleLap(operation) {
        if (!this.#isCurrentLap(operation) || operation.frameHandle !== null || this.#reducedMotion()) return;
        const scheduler = this.#context?.requestAnimationFrame
            || this.#context?.requestRelationshipRefreshFrame
            || globalThis.requestAnimationFrame;
        if (typeof scheduler !== "function") {
            this.#lapDiagnostics.unavailable += 1;
            return;
        }
        operation.frameHandle = scheduler(timestamp => {
            operation.frameHandle = null;
            this.#tickLap(operation, timestamp);
        });
    }

    #tickLap(operation, timestamp) {
        if (!this.#isCurrentLap(operation)) return;
        const occupant = this.#runtime.state?.get?.(operation.occupantParticipantId);
        if (!occupant || occupant.webcam_enabled || occupant.webcam_path) {
            this.#stopLapOperation(operation.key, {
                reason: "lap-animation-presentation-inapplicable",
                scheduleRefresh: true
            });
            return;
        }
        if (this.#reducedMotion()) {
            this.#lapDiagnostics.reducedMotion += 1;
            this.#applyLapSample(operation, Object.freeze({ translateY: 0, rotateDegrees: 0 }));
            return;
        }
        const now = Number.isFinite(Number(timestamp))
            ? Number(this.#context?.epochNow?.() ?? Date.now())
            : Date.now();
        const elapsedMs = Math.max(0, now - operation.startedAtMs);
        const sample = operation.mode === "lap_dance"
            ? LapDance.sample({ elapsedMs })
            : LapBounce.sample({
                elapsedMs,
                effectiveRisePx: operation.geometry.effectiveRisePx
            });
        this.#applyLapSample(operation, sample);
        this.#lapDiagnostics.frames += 1;
        this.#scheduleLap(operation);
    }

    #applyLapSample(operation, sample) {
        const occupant = this.#runtime.state?.get?.(operation.occupantParticipantId);
        if (!occupant) return false;
        return this.#runtime.renderer?.applyLapAnimationFrame({
            relationshipId: operation.relationshipId,
            generation: operation.generation,
            participant: occupant,
            sample
        }) || false;
    }

    #stopLapOperation(key, { reason = "stopped", scheduleRefresh = true } = {}) {
        const operation = this.#activeLap.get(key);
        if (!operation) return false;
        if (operation.frameHandle !== null) {
            const cancel = this.#context?.cancelAnimationFrame || globalThis.cancelAnimationFrame;
            if (typeof cancel === "function") cancel(operation.frameHandle);
            operation.frameHandle = null;
        }
        this.#runtime.renderer?.clearLapAnimation(
            operation.relationshipId,
            operation.occupantParticipantId
        );
        this.#activeLap.delete(key);
        this.#lapDiagnostics.stopped += 1;
        this.#record("lap-animation-stopped", {
            relationshipId: operation.relationshipId,
            relationshipVersion: operation.relationshipVersion,
            hostParticipantId: operation.hostParticipantId,
            occupantParticipantId: operation.occupantParticipantId,
            mode: operation.mode,
            generation: operation.generation,
            reason
        });
        if (scheduleRefresh) {
            this.#runtime.coordinator?.scheduleRelationshipRefresh?.({
                all: true,
                reason: "lap-animation-stopped"
            });
        }
        return true;
    }

    #stopLapOperationsForRelationship(relationshipId, reason, scheduleRefresh = true) {
        let stopped = false;
        Array.from(this.#activeLap.entries()).forEach(([key, operation]) => {
            if (operation.relationshipId !== String(relationshipId || "")) return;
            stopped = this.#stopLapOperation(key, { reason, scheduleRefresh: false }) || stopped;
        });
        if (stopped && scheduleRefresh) {
            this.#runtime.coordinator?.scheduleRelationshipRefresh?.({ all: true, reason });
        }
        return stopped;
    }

    #stopAllLap(reason) {
        Array.from(this.#activeLap.keys()).forEach(key => {
            this.#stopLapOperation(key, { reason, scheduleRefresh: false });
        });
        this.#runtime.renderer?.clearAllLapAnimations?.();
    }

    #reconcileMotionPreference() {
        this.#activeLap.forEach(operation => {
            if (operation.frameHandle !== null) {
                const cancel = this.#context?.cancelAnimationFrame || globalThis.cancelAnimationFrame;
                if (typeof cancel === "function") cancel(operation.frameHandle);
                operation.frameHandle = null;
            }
            if (this.#reducedMotion()) {
                this.#lapDiagnostics.reducedMotion += 1;
                this.#applyLapSample(operation, Object.freeze({ translateY: 0, rotateDegrees: 0 }));
            } else {
                this.#scheduleLap(operation);
            }
        });
        this.#runtime.coordinator?.scheduleRelationshipRefresh?.({
            all: true,
            reason: "lap-animation-reduced-motion-change"
        });
    }

    #removeMotionPreferenceListener() {
        if (this.#motionQuery && this.#motionQueryListener) {
            this.#motionQuery.removeEventListener?.("change", this.#motionQueryListener);
        }
        this.#motionQuery = null;
        this.#motionQueryListener = null;
    }

    #reducedMotion() {
        return Boolean(this.#motionQuery?.matches);
    }

    #operationId(prefix) {
        const uuid = globalThis.crypto?.randomUUID?.();
        return uuid ? `${prefix}-${uuid}` : `${prefix}-${Date.now()}-${++this.#serial}`;
    }

    #lapKey(relationshipId, occupantParticipantId) {
        return `${String(relationshipId || "")}:${Number(occupantParticipantId || 0)}`;
    }

    #isCurrentLap(operation) {
        return this.#activeLap.get(operation.key)?.token === operation.token;
    }

    #register(strategy) {
        const definition = APPROVED_DANCES.find(item => item.id === strategy?.id);
        if (!definition || typeof strategy?.offset !== "function"
            || Number(strategy?.durationMs) !== definition.durationMs) {
            throw new TypeError("Invalid avatar dance strategy.");
        }
        this.#registry.set(strategy.id, strategy);
    }

    #schedule(operation) {
        if (!this.#isCurrent(operation) || operation.suspended || operation.frameHandle !== null) return;
        const scheduler = this.#context?.requestAnimationFrame
            || this.#context?.requestRelationshipRefreshFrame
            || globalThis.requestAnimationFrame;
        if (typeof scheduler !== "function") {
            this.#diagnostics.fallback += 1;
            return;
        }
        operation.frameHandle = scheduler(timestamp => {
            operation.frameHandle = null;
            this.#tick(operation, timestamp);
        });
    }

    #tick(operation, timestamp) {
        if (!this.#isCurrent(operation) || operation.suspended) return;
        const presentation = this.#runtime.relationships?.relationshipPresentation(operation.relationshipId);
        const applicable = presentation
            && presentation.members.length >= 2
            && presentation.visibleNormalMembers.length >= 1;
        if (!applicable) {
            this.#runtime.renderer?.clearRelationshipDanceOffset(operation.relationshipId);
            this.#schedule(operation);
            return;
        }
        const participants = presentation.visibleMemberIds
            .map(participantId => this.#runtime.state?.get?.(participantId))
            .filter(Boolean);
        const now = Number.isFinite(Number(timestamp))
            ? Number(this.#context?.epochNow?.() ?? Date.now())
            : Date.now();
        const strategy = this.#registry.get(operation.danceId);
        const requested = strategy.offset({ elapsedMs: Math.max(0, now - operation.startedAtMs) });
        const stage = this.#context?.getStageDimensions?.()
            || this.#context?.stageSize?.()
            || {};
        const offset = this.#runtime.layout?.constrainRelationshipDanceOffset({
            participants,
            requestedOffset: requested,
            stageWidth: Number(stage.width || 0),
            stageHeight: Number(stage.height || 0)
        }) || Object.freeze({ x: 0, y: 0 });
        this.#runtime.renderer?.applyRelationshipDanceOffset({
            relationshipId: operation.relationshipId,
            generation: operation.generation,
            participants,
            offset
        });
        this.#diagnostics.frames += 1;
        this.#schedule(operation);
    }

    #cancelFrame(operation) {
        if (operation.frameHandle === null) return;
        const cancel = this.#context?.cancelAnimationFrame || globalThis.cancelAnimationFrame;
        if (typeof cancel === "function") cancel(operation.frameHandle);
        operation.frameHandle = null;
    }

    #isCurrent(operation) {
        return this.#active.get(operation.relationshipId)?.token === operation.token;
    }

    #record(event, details) {
        this.#context?.recordRelationshipDiagnostic?.({
            event,
            ...details
        });
    }
}

export default AvatarDanceService;
