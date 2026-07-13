/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * Owner: VoiceRuntime
 * Build: 000043 Part 4
 * Purpose: Own facade, participation, and microphone acquisition lifecycle
 *          state plus operation generations.
 ******************************************************************************/

const FACADE_STATE = Object.freeze({

    IDLE:
        "idle",

    ACTIVE:
        "active",

    DESTROYED:
        "destroyed"

});

const PARTICIPATION_STATE = Object.freeze({

    NOT_JOINED:
        "not-joined",

    JOINING:
        "joining",

    JOINED:
        "joined",

    LEAVING:
        "leaving"

});

const ACQUISITION_STATE = Object.freeze({

    IDLE:
        "idle",

    PENDING:
        "pending",

    READY:
        "ready",

    FAILED:
        "failed"

});

export class VoiceLifecycleService {

    #facadeState = FACADE_STATE.IDLE;

    #participationState = PARTICIPATION_STATE.NOT_JOINED;

    #microphoneRequested = false;

    #microphoneAcquisitionState = ACQUISITION_STATE.IDLE;

    #generations = new Map();

    #transitionSerial = 0;

    activate() {

        if (this.#facadeState === FACADE_STATE.DESTROYED) {

            return this.#result("destroyed", "configure");

        }

        const previous =
            this.getSnapshot();

        this.#facadeState =
            FACADE_STATE.ACTIVE;

        return this.#result("completed", "configure", previous);

    }

    beginJoin() {

        if (this.isDestroyed()) {

            return this.#result("destroyed", "join");

        }

        if (this.#participationState === PARTICIPATION_STATE.JOINED) {

            return this.#result("duplicate", "join");

        }

        if (this.#participationState === PARTICIPATION_STATE.JOINING) {

            return this.#result("in-progress", "join", null, {

                token:
                    this.currentToken("join")

            });

        }

        if (this.#participationState === PARTICIPATION_STATE.LEAVING) {

            return this.#result("invalid-transition", "join");

        }

        const previous =
            this.getSnapshot();

        const token =
            this.#nextToken("join");

        this.#participationState =
            PARTICIPATION_STATE.JOINING;

        this.#microphoneRequested =
            true;

        this.#microphoneAcquisitionState =
            ACQUISITION_STATE.PENDING;

        return this.#result("started", "join", previous, { token });

    }

    markMicrophoneReady(token) {

        if (!this.isCurrent(token)) {

            return this.#result(
                this.isDestroyed() ? "destroyed" : "stale-generation",
                "microphone-ready"
            );

        }

        const previous =
            this.getSnapshot();

        this.#microphoneAcquisitionState =
            ACQUISITION_STATE.READY;

        return this.#result("completed", "microphone-ready", previous, { token });

    }

    completeJoin(token) {

        if (
            !this.isCurrent(token) ||
            this.#participationState !== PARTICIPATION_STATE.JOINING
        ) {

            return this.#result(
                this.isDestroyed() ? "destroyed" : "stale-generation",
                "join-complete"
            );

        }

        const previous =
            this.getSnapshot();

        this.#participationState =
            PARTICIPATION_STATE.JOINED;

        this.#microphoneAcquisitionState =
            ACQUISITION_STATE.READY;

        return this.#result("completed", "join", previous, { token });

    }

    failJoin(token) {

        if (!this.isCurrent(token)) {

            return this.#result(
                this.isDestroyed() ? "destroyed" : "stale-generation",
                "join-failed"
            );

        }

        const previous =
            this.getSnapshot();

        this.#participationState =
            PARTICIPATION_STATE.NOT_JOINED;

        this.#microphoneRequested =
            false;

        this.#microphoneAcquisitionState =
            ACQUISITION_STATE.FAILED;

        return this.#result("failed", "join", previous, { token });

    }

    beginLeave() {

        if (this.isDestroyed()) {

            return this.#result("destroyed", "leave");

        }

        if (this.#participationState === PARTICIPATION_STATE.LEAVING) {

            return this.#result("in-progress", "leave", null, {

                token:
                    this.currentToken("leave")

            });

        }

        if (this.#participationState === PARTICIPATION_STATE.NOT_JOINED) {

            return this.#result("duplicate", "leave");

        }

        const previous =
            this.getSnapshot();

        this.cancel("join");

        const token =
            this.#nextToken("leave");

        this.#participationState =
            PARTICIPATION_STATE.LEAVING;

        this.#microphoneRequested =
            false;

        this.#microphoneAcquisitionState =
            ACQUISITION_STATE.IDLE;

        return this.#result("started", "leave", previous, { token });

    }

    completeLeave(token) {

        if (!this.isCurrent(token)) {

            return this.#result(
                this.isDestroyed() ? "destroyed" : "stale-generation",
                "leave-complete"
            );

        }

        const previous =
            this.getSnapshot();

        this.#participationState =
            PARTICIPATION_STATE.NOT_JOINED;

        this.#microphoneRequested =
            false;

        this.#microphoneAcquisitionState =
            ACQUISITION_STATE.IDLE;

        return this.#result("completed", "leave", previous, { token });

    }

    cancel(operation) {

        return this.#nextToken(operation);

    }

    beginOperation(operation) {

        if (this.isDestroyed()) return null;

        return this.#nextToken(operation);

    }

    destroy() {

        if (this.isDestroyed()) {

            return this.#result("duplicate", "destroy");

        }

        const previous =
            this.getSnapshot();

        for (const operation of ["join", "leave", "poll", "signal-drain"]) {

            this.cancel(operation);

        }

        this.#facadeState =
            FACADE_STATE.DESTROYED;

        this.#participationState =
            PARTICIPATION_STATE.NOT_JOINED;

        this.#microphoneRequested =
            false;

        this.#microphoneAcquisitionState =
            ACQUISITION_STATE.IDLE;

        return this.#result("completed", "destroy", previous);

    }

    isCurrent(token) {

        if (!token || this.isDestroyed()) return false;

        return (this.#generations.get(token.operation) || 0) === token.generation;

    }

    isDestroyed() {

        return this.#facadeState === FACADE_STATE.DESTROYED;

    }

    isJoined() {

        return this.#participationState === PARTICIPATION_STATE.JOINED;

    }

    currentToken(operation) {

        return Object.freeze({

            operation,

            generation:
                this.#generations.get(operation) || 0

        });

    }

    getSnapshot() {

        return Object.freeze({

            facadeState:
                this.#facadeState,

            participationState:
                this.#participationState,

            microphoneRequested:
                this.#microphoneRequested,

            microphoneAcquisitionState:
                this.#microphoneAcquisitionState,

            generations:
                Object.freeze(Object.fromEntries(this.#generations)),

            transitionSerial:
                this.#transitionSerial

        });

    }

    #nextToken(operation) {

        const generation =
            (this.#generations.get(operation) || 0) + 1;

        this.#generations.set(operation, generation);

        return Object.freeze({ operation, generation });

    }

    #result(status, operation, previous = null, extra = {}) {

        this.#transitionSerial += 1;

        return Object.freeze({

            status,

            operation,

            previous,

            current:
                this.getSnapshot(),

            ...extra

        });

    }

}

export default VoiceLifecycleService;
