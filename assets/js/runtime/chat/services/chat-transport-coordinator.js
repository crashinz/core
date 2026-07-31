/******************************************************************************
 * Build 000052 room event transport coordinator.
 ******************************************************************************/

import {

    ChatEventDeliveryContract

} from "./chat-event-delivery-contract.js";

import {

    ChatPollAdapter

} from "./chat-poll-adapter.js";

import {

    ChatSseAdapter

} from "./chat-sse-adapter.js";

import {

    ChatWebSocketAdapter

} from "./chat-websocket-adapter.js";

export class ChatTransportCoordinator {

    #context = null;

    #contract = new ChatEventDeliveryContract();

    #adapters = new Map();

    #active = null;

    #running = false;

    #fallbackReason = "";

    configure(context = {}) {

        this.stop();

        this.#context =
            context;

        this.#adapters =
            new Map([

                ["polling", new ChatPollAdapter()],

                ["sse", new ChatSseAdapter()],

                ["websocket", new ChatWebSocketAdapter()]

            ]);

        this.#configureAdapters();

    }

    seed(ids = {}) {

        this.#contract.seed(ids);

    }

    start() {

        if (this.#running) {
            return;
        }

        this.#running =
            true;

        this.#activate(
            this.#selectAdapter(),
            "configured-selection"
        );

    }

    stop() {

        this.#running =
            false;

        for (const adapter of this.#adapters.values()) {
            adapter.stop();
        }

        this.#active =
            null;

    }

    async pollOnce() {

        if (!this.#running) {
            this.start();
            return;
        }

        if (this.#active === "polling") {
            await this.#adapters.get("polling")?.request();
        }

    }

    diagnostics() {

        return Object.freeze({

            owner:
                "ChatTransportCoordinator",

            build:
                "000052",

            running:
                this.#running,

            activeAdapter:
                this.#active,

            fallbackAdapter:
                "polling",

            fallbackReason:
                this.#fallbackReason,

            cursors:
                this.#contract.cursors(),

            adapters:
                Object.freeze(
                    Object.fromEntries(
                        Array.from(
                            this.#adapters,
                            ([id, adapter]) => [id, adapter.diagnostics()]
                        )
                    )
                )

        });

    }

    #configureAdapters() {

        const polling =
            this.#adapters.get("polling");

        polling.configure({

            shouldStop:
                () => this.#shouldStop(),

            pollInterval:
                this.#context.pollInterval ?? 25,

            failureBackoffBase:
                this.#context.failureBackoffBase ?? 1000,

            failureBackoffMax:
                this.#context.failureBackoffMax ?? 30000,

            createQuery:
                () => this.#createQuery(),

            fetchBatch:
                query => this.#context.fetchPoll(query),

            onBatch:
                (batch, adapter) => this.#acceptBatch(batch, adapter),

            onFailure:
                (error, detail) => this.#handleFailure(error, detail)

        });

        const sse =
            this.#adapters.get("sse");

        sse.configure({

            shouldStop:
                () => this.#shouldStop(),

            createQuery:
                () => this.#createQuery(),

            createUrl:
                query => {

                    const endpoint =
                        this.#policy()?.adapters?.sse?.endpoint;

                    const url =
                        this.#context.resolveUrl(endpoint);

                    return `${url}?${query}`;

                },

            createEventSource:
                url => this.#context.createEventSource(url),

            onBatch:
                (batch, adapter) => this.#acceptBatch(batch, adapter),

            onFailure:
                (error, detail) => this.#handleFailure(error, detail)

        });

        const websocket =
            this.#adapters.get("websocket");

        websocket.configure({

            createUrl:
                () => this.#policy()?.adapters?.websocket?.endpoint,

            createWebSocket:
                (url, protocols) => this.#context.createWebSocket(url, protocols),

            createSubscription:
                () => {

                    const config =
                        this.#context.getConfig();

                    return Object.freeze({

                        action:
                            "subscribe",

                        session_id:
                            config.sessionId,

                        join_token:
                            config.myJoinToken,

                        cursor:
                            this.#contract.cursors()

                    });

                },

            onBatch:
                (batch, adapter) => this.#acceptBatch(batch, adapter),

            onFailure:
                (error, detail) => this.#handleFailure(error, detail)

        });

    }

    #policy() {

        return this.#context?.getTransportPolicy?.()
            || this.#context?.getConfig?.()?.transport
            || {};

    }

    #selectAdapter() {

        const policy =
            this.#policy();

        if (policy.configuredMode !== "automatic-best") {
            this.#fallbackReason =
                "Polling only is configured.";
            return "polling";
        }

        for (const id of ["websocket", "sse"]) {

            const adapter =
                policy.adapters?.[id];

            if (adapter?.eligible && adapter?.endpoint) {
                return id;
            }

        }

        this.#fallbackReason =
            policy.fallbackReason
            || "No optional adapter has complete capability proof.";

        return "polling";

    }

    #activate(id, reason) {

        if (!this.#running) {
            return;
        }

        const selected =
            this.#adapters.has(id) ? id : "polling";

        for (const [adapterId, adapter] of this.#adapters) {

            if (adapterId !== selected) {
                adapter.stop();
            }

        }

        this.#active =
            selected;

        if (selected === "polling" && reason !== "configured-selection") {
            this.#fallbackReason =
                reason;
        }

        this.#context.onAdapterChange?.(
            Object.freeze({

                activeAdapter:
                    selected,

                fallbackAdapter:
                    "polling",

                reason:
                    this.#fallbackReason || reason

            })
        );

        this.#adapters.get(selected).start();

    }

    async #acceptBatch(batch, adapter) {

        if (!this.#running || adapter !== this.#active) {
            return;
        }

        const accepted =
            this.#contract.accept(batch);

        this.#context.handleProjection?.(
            Object.freeze({

                avatar_visibility_preferences:
                    accepted.projection.avatarVisibilityPreferences,

                gesture_preferences:
                    accepted.projection.gesturePreferences,

                gesture_capabilities:
                    accepted.projection.gestureCapabilities

            })
        );

        for (const event of accepted.events) {
            this.#context.handleRoomEvent?.(event);
        }

        for (const event of accepted.communityEvents) {
            this.#context.handleCommunityEvent?.(event);
        }

    }

    #handleFailure(error, detail = {}) {

        this.#context.warnError?.(
            error,
            detail
        );

        if (detail.fallback && detail.adapter !== "polling") {
            this.#activate(
                "polling",
                `${detail.adapter} failed; Polling resumed without changing room state.`
            );
        }

    }

    #createQuery() {

        const config =
            this.#context.getConfig();

        const cursors =
            this.#contract.cursors();

        return new URLSearchParams({

            session_id:
                config.sessionId,

            last_event_id:
                String(cursors.room),

            last_community_event_id:
                String(cursors.community),

            join_token:
                config.myJoinToken

        });

    }

    #shouldStop() {

        return !this.#running
            || Boolean(this.#context?.shouldStop?.());

    }

}

export default ChatTransportCoordinator;
