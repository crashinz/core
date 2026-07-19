/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-poll-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns main chat polling transport behavior.
 *
 *      ChatPollService owns poll endpoint execution, room/community event-id
 *      tracking, and main poll scheduling. It leaves non-chat room event
 *      handling with the room composition root and leaves event classification
 *      with ChatEventRouter.
 *
 * Build:
 *      000022-L
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-L
 * - Introduced Chat Poll Service.
 * - Transferred main poll transport and event-id ownership from room.js.
 ******************************************************************************/

/**
 * @file chat-poll-service.js
 *
 * Defines the Chat Poll Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Poll Service
//--------------------------------------------------

/**
 * Owns main chat polling transport behavior.
 */
export class ChatPollService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Chat Runtime.
     *
     * @type {ChatRuntime}
     */
    #runtime;

    /**
     * Polling context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    /**
     * Last observed room event id.
     *
     * @type {number}
     */
    #lastEventId = 0;

    /**
     * Last observed community event id.
     *
     * @type {number}
     */
    #lastCommunityEventId = 0;

    /**
     * Active poll timer.
     *
     * @type {number|null}
     */
    #pollTimer = null;

    /**
     * Whether the main poll loop is active.
     *
     * @type {boolean}
     */
    #running = false;

    /**
     * Whether a poll request is currently executing.
     *
     * @type {boolean}
     */
    #polling = false;

    /** Consecutive failed poll requests. */
    #failureCount = 0;

    /** Delay selected for the next poll request. */
    #nextPollDelay = 25;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Poll Service.
     *
     * @param {ChatRuntime} runtime
     *        Owning Chat Runtime.
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
     * Stops polling and releases poll-owned references.
     */
    destroy() {

        this.stop();

        this.#context = null;

        this.#failureCount = 0;

        this.#nextPollDelay = 25;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Chat Runtime.
     *
     * @returns {ChatRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures poll extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Poll API
    //--------------------------------------------------

    /**
     * Seeds poll event ids from the current room configuration.
     *
     * @param {Object} ids
     */
    seed(ids = {}) {

        this.#lastEventId =
            Number(ids.lastEventId || 0);

        this.#lastCommunityEventId =
            Number(ids.lastCommunityEventId || 0);

    }

    /**
     * Starts the main poll loop.
     */
    start() {

        if (this.#running) {
            return;
        }

        this.#running = true;

        this.poll();

    }

    /**
     * Stops the main poll loop.
     */
    stop() {

        this.#running = false;

        this.#clearPollTimer();

    }

    /**
     * Polls the room endpoint once and schedules the next run.
     *
     * @returns {Promise<void>}
     */
    async poll() {

        const context =
            this.#requireContext();

        if (!this.#running || context.shouldStop?.()) {
            this.stop();
            return;
        }

        if (this.#polling) {
            this.#scheduleNextPoll();
            return;
        }

        this.#polling = true;

        try {

            const config =
                context.getConfig();

            const query =
                new URLSearchParams({

                    session_id:
                        config.sessionId,

                    last_event_id:
                        String(this.#lastEventId),

                    last_community_event_id:
                        String(this.#lastCommunityEventId),

                    join_token:
                        config.myJoinToken

                });

            const data =
                await context.fetchPoll(
                    query
                );

            this.#failureCount = 0;

            this.#nextPollDelay = context.pollInterval ?? 25;

            context.handleProjection?.(data);

            (data.events || []).forEach(
                event => this.#routeRoomEvent(event)
            );

            (data.community_events || []).forEach(
                event => this.#routeCommunityEvent(event)
            );

        } catch (error) {

            this.#failureCount += 1;

            const baseDelay = Math.max(250, Number(context.failureBackoffBase ?? 1000));

            const maximumDelay = Math.max(baseDelay, Number(context.failureBackoffMax ?? 30000));

            this.#nextPollDelay = Math.min(
                maximumDelay,
                baseDelay * (2 ** Math.min(this.#failureCount - 1, 5))
            );

            context.warnError?.(
                error,
                Object.freeze({
                    failureCount: this.#failureCount,
                    retryDelay: this.#nextPollDelay,
                })
            );

        } finally {

            this.#polling = false;

            if (this.#running && !context.shouldStop?.()) {
                this.#scheduleNextPoll();
            }

        }

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns service diagnostic information.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ChatRuntime",

            build:
                "000022-L",

            configured:
                Boolean(this.#context),

            running:
                this.#running,

            polling:
                this.#polling,

            hasPollTimer:
                Boolean(this.#pollTimer),

            lastEventId:
                this.#lastEventId,

            lastCommunityEventId:
                this.#lastCommunityEventId,

            failureCount:
                this.#failureCount,

            nextPollDelay:
                this.#nextPollDelay

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Routes one room poll event.
     *
     * @param {Object} event
     */
    #routeRoomEvent(event) {

        this.#lastEventId =
            Math.max(
                this.#lastEventId,
                Number(event?.id || 0)
            );

        if (this.#runtime.events.routeRoomEvent(event)) {
            return;
        }

        this.#requireContext().handleRoomEvent?.(
            event
        );

    }

    /**
     * Routes one community poll event.
     *
     * @param {Object} event
     */
    #routeCommunityEvent(event) {

        this.#lastCommunityEventId =
            Math.max(
                this.#lastCommunityEventId,
                Number(event?.id || 0)
            );

        if (this.#runtime.events.routeCommunityEvent(event)) {
            return;
        }

        this.#requireContext().handleCommunityEvent?.(
            event
        );

    }

    /**
     * Schedules the next main poll run.
     */
    #scheduleNextPoll() {

        this.#clearPollTimer();

        this.#pollTimer =
            setTimeout(
                () => this.poll(),
                this.#nextPollDelay
            );

    }

    /**
     * Clears the active poll timer.
     */
    #clearPollTimer() {

        if (this.#pollTimer) {
            clearTimeout(this.#pollTimer);
        }

        this.#pollTimer = null;

    }

    /**
     * Returns configured poll context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatPollService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatPollService;
