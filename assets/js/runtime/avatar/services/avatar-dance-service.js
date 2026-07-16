import { SynchronizedSwayDance } from "../dances/synchronized-sway-dance.js";
import { SynchronizedBounceDance } from "../dances/synchronized-bounce-dance.js";

const APPROVED_DANCES = Object.freeze([
    Object.freeze({ id: "synchronized-sway", label: "Synchronized Sway", durationMs: 2400 }),
    Object.freeze({ id: "synchronized-bounce", label: "Synchronized Bounce", durationMs: 1600 })
]);

/**
 * Owns finite synchronized relationship-dance playback and scheduler lifetime.
 */
export class AvatarDanceService {

    #runtime;
    #context = null;
    #registry = new Map();
    #active = new Map();
    #serial = 0;
    #diagnostics = {
        started: 0,
        stopped: 0,
        suspended: 0,
        resumed: 0,
        stale: 0,
        frames: 0,
        fallback: 0
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
    }

    destroy() {
        this.stopAll("runtime-destroy");
        this.#registry.clear();
        this.#context = null;
        this.#lastOperation = null;
    }

    get approvedDances() {
        return APPROVED_DANCES;
    }

    reconcile(relationship, { reason = "relationship-reconcile" } = {}) {
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
        if (!strategy || !generation || !Number.isFinite(startedAtMs) || startedAtMs <= 0) {
            this.#diagnostics.fallback += 1;
            this.#record("relationship-dance-rejected", {
                relationshipId,
                generation: generation || null,
                danceId: danceId || null,
                reason: "invalid-playback"
            });
            this.stop(relationshipId, { reason: "invalid-playback" });
            return Object.freeze({ accepted: false, reason: "invalid-playback" });
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
            approvedDances: APPROVED_DANCES,
            registeredDanceIds: Object.freeze(Array.from(this.#registry.keys())),
            activeRelationshipCount: this.#active.size,
            ...this.#diagnostics,
            lastOperation: this.#lastOperation
        });
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
