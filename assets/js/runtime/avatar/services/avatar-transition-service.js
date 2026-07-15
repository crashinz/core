const APPROVED_TRANSITIONS = Object.freeze([
    "snap",
    "glide",
    "fade-reposition"
]);
const GLIDE_DURATION_MS = 350;
const FADE_DURATION_MS = 160;
const TRANSITION_EASING = "ease";

/**
 * Owns finite relationship-configuration transition strategies and lifetime.
 */
export class AvatarTransitionService {

    #runtime;
    #active = new Map();
    #completedVersions = new Map();
    #operationSerial = 0;
    #diagnostics = {
        started: 0,
        completed: 0,
        cancelled: 0,
        snapped: 0,
        duplicate: 0,
        stale: 0,
        fallback: 0
    };
    #lastOperation = null;

    constructor(runtime) {

        this.#runtime = runtime;

    }

    initialize() {

    }

    destroy() {

        this.finishAll("runtime-destroy");
        this.#completedVersions.clear();
        this.#lastOperation = null;

    }

    get approvedTransitionIds() {

        return APPROVED_TRANSITIONS;

    }

    /**
     * Applies one deliberate, authoritative relationship configuration.
     */
    transition({
        relationshipId,
        relationshipVersion,
        transition = "snap",
        participants = [],
        positionsChanged = true,
        applyFinal
    } = {}) {

        const id = String(relationshipId || "");
        const version = Number(relationshipVersion || 0);
        const selected = APPROVED_TRANSITIONS.includes(String(transition))
            ? String(transition)
            : "snap";
        const completedVersion = Number(this.#completedVersions.get(id) || 0);
        const active = this.#active.get(id) || null;

        if (!id || !Number.isInteger(version) || version < 1
            || typeof applyFinal !== "function") {
            return Object.freeze({ accepted: false, reason: "invalid-transition-request" });
        }
        if (version < completedVersion || (active && version < active.version)) {
            this.#diagnostics.stale += 1;
            return Object.freeze({ accepted: false, reason: "stale-relationship-version" });
        }
        if (version === completedVersion || (active && version === active.version)) {
            this.#diagnostics.duplicate += 1;
            return Object.freeze({ accepted: true, duplicate: true, transition: selected });
        }
        if (active) {
            this.finish(id, {
                relationshipVersion: version,
                reason: "newer-configuration"
            });
        }

        const operation = {
            id,
            version,
            selected,
            token: ++this.#operationSerial,
            applyFinal,
            finalApplied: false,
            animations: new Set(),
            phase: "created"
        };

        this.#active.set(id, operation);
        this.#diagnostics.started += 1;

        if (!positionsChanged || selected === "snap") {
            this.#applyFinal(operation);
            this.#diagnostics.snapped += 1;
            this.#complete(operation, positionsChanged ? "snap" : "no-position-change");
            return Object.freeze({ accepted: true, transition: "snap", animated: false });
        }

        const targets = this.#runtime.renderer?.relationshipTransitionTargets(participants) || [];
        if (!targets.length || targets.some(target => typeof target.element?.animate !== "function")) {
            this.#applyFinal(operation);
            this.#diagnostics.fallback += 1;
            this.#complete(operation, "animation-api-unavailable");
            return Object.freeze({ accepted: true, transition: "snap", animated: false });
        }

        if (selected === "glide") {
            this.#startGlide(operation, targets);
        } else {
            this.#startFadeReposition(operation, targets);
        }

        return Object.freeze({ accepted: true, transition: selected, animated: true });

    }

    /**
     * Finishes an active transition at its exact authoritative final state.
     */
    finish(relationshipId, { relationshipVersion = null, reason = "cancelled" } = {}) {

        const id = String(relationshipId || "");
        const operation = this.#active.get(id);
        if (!operation) return false;
        const version = Number(relationshipVersion || 0);
        if (version > 0 && version < operation.version) {
            this.#diagnostics.stale += 1;
            return false;
        }

        this.#applyFinal(operation);
        this.#cancelAnimations(operation);
        this.#active.delete(id);
        this.#completedVersions.set(
            id,
            Math.max(operation.version, Number(this.#completedVersions.get(id) || 0))
        );
        this.#diagnostics.cancelled += 1;
        this.#lastOperation = Object.freeze({
            relationshipId: id,
            relationshipVersion: operation.version,
            transition: operation.selected,
            outcome: "finished-to-final",
            reason: String(reason || "cancelled")
        });
        return true;

    }

    finishAll(reason = "cancelled") {

        Array.from(this.#active.keys()).forEach(relationshipId => {
            this.finish(relationshipId, { reason });
        });

    }

    getDiagnostics() {

        return Object.freeze({
            approvedTransitionIds: APPROVED_TRANSITIONS,
            activeRelationshipCount: this.#active.size,
            completedRelationshipCount: this.#completedVersions.size,
            ...this.#diagnostics,
            lastOperation: this.#lastOperation
        });

    }

    #startGlide(operation, targets) {

        const before = new Map(targets.map(target => [
            target.element,
            this.#rect(target.element)
        ]));
        this.#applyFinal(operation);

        try {
            targets.forEach(target => {
                const first = before.get(target.element);
                const last = this.#rect(target.element);
                const deltaX = first.left - last.left;
                const deltaY = first.top - last.top;
                if (Math.abs(deltaX) < 0.01 && Math.abs(deltaY) < 0.01) return;
                operation.animations.add(target.element.animate([
                    { translate: `${deltaX}px ${deltaY}px` },
                    { translate: "0px 0px" }
                ], {
                    duration: GLIDE_DURATION_MS,
                    easing: TRANSITION_EASING
                }));
            });
            operation.phase = "glide";
            if (!operation.animations.size) {
                this.#complete(operation, "no-rendered-frame-change");
                return;
            }
            this.#settleAnimations(operation, "glide-complete");
        } catch (error) {
            this.#diagnostics.fallback += 1;
            this.finish(operation.id, {
                relationshipVersion: operation.version,
                reason: "glide-fallback"
            });
        }

    }

    async #startFadeReposition(operation, targets) {

        const opacities = new Map(targets.map(target => [
            target.element,
            this.#effectiveOpacity(target.element)
        ]));

        try {
            operation.phase = "fade-out";
            targets.forEach(target => {
                const opacity = opacities.get(target.element);
                operation.animations.add(target.element.animate([
                    { opacity },
                    { opacity: 0 }
                ], {
                    duration: FADE_DURATION_MS,
                    easing: TRANSITION_EASING,
                    fill: "forwards"
                }));
            });
            await Promise.allSettled(
                Array.from(operation.animations, animation => animation.finished)
            );
            if (!this.#isCurrent(operation)) return;

            this.#applyFinal(operation);
            const finalParticipants = Array.from(new Map(
                targets.map(target => [target.participantId, target.participant])
            ).values());
            const finalTargets = this.#runtime.renderer?.relationshipTransitionTargets(
                finalParticipants
            ) || targets;
            const fadeIn = new Set();
            finalTargets.forEach(target => {
                const opacity = opacities.has(target.element)
                    ? opacities.get(target.element)
                    : this.#effectiveOpacity(target.element);
                fadeIn.add(target.element.animate([
                    { opacity: 0 },
                    { opacity }
                ], {
                    duration: FADE_DURATION_MS,
                    easing: TRANSITION_EASING,
                    fill: "backwards"
                }));
            });
            this.#cancelAnimations(operation);
            operation.animations = fadeIn;
            operation.phase = "fade-in";
            this.#settleAnimations(operation, "fade-reposition-complete");
        } catch (error) {
            this.#diagnostics.fallback += 1;
            this.finish(operation.id, {
                relationshipVersion: operation.version,
                reason: "fade-reposition-fallback"
            });
        }

    }

    async #settleAnimations(operation, outcome) {

        await Promise.allSettled(
            Array.from(operation.animations, animation => animation.finished)
        );
        if (this.#isCurrent(operation)) this.#complete(operation, outcome);

    }

    #applyFinal(operation) {

        if (operation.finalApplied) return;
        operation.finalApplied = true;
        operation.applyFinal();

    }

    #complete(operation, outcome) {

        if (!this.#isCurrent(operation)) return;
        this.#cancelAnimations(operation);
        this.#active.delete(operation.id);
        this.#completedVersions.set(
            operation.id,
            Math.max(operation.version, Number(this.#completedVersions.get(operation.id) || 0))
        );
        this.#diagnostics.completed += 1;
        this.#lastOperation = Object.freeze({
            relationshipId: operation.id,
            relationshipVersion: operation.version,
            transition: operation.selected,
            outcome
        });

    }

    #cancelAnimations(operation) {

        operation.animations.forEach(animation => {
            try {
                animation.cancel();
            } catch (error) {
                void error;
            }
        });
        operation.animations.clear();

    }

    #isCurrent(operation) {

        return this.#active.get(operation.id)?.token === operation.token;

    }

    #rect(element) {

        const rect = element.getBoundingClientRect();
        return Object.freeze({ left: Number(rect.left), top: Number(rect.top) });

    }

    #effectiveOpacity(element) {

        const view = element.ownerDocument?.defaultView || globalThis;
        const opacity = Number(view.getComputedStyle?.(element)?.opacity);
        return Number.isFinite(opacity) ? opacity : 1;

    }

}

export default AvatarTransitionService;
