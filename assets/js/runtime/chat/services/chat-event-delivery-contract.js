/******************************************************************************
 * Build 000052 transport-neutral room/community event delivery contract.
 *
 * The server event ledgers own persistence and ordering. This client owner
 * holds only the accepted cursors and a bounded deduplication window shared by
 * Polling, SSE, and WebSocket framing adapters.
 ******************************************************************************/

export class ChatEventDeliveryContract {

    #roomCursor = 0;

    #communityCursor = 0;

    #seen = new Map();

    #seenOrder = [];

    #seenLimit = 2048;

    seed(ids = {}) {

        this.#roomCursor = Math.max(0, Number(ids.lastEventId || ids.room || 0));

        this.#communityCursor = Math.max(
            0,
            Number(ids.lastCommunityEventId || ids.community || 0)
        );

        this.#seen.clear();

        this.#seenOrder = [];

    }

    cursors() {

        return Object.freeze({

            room:
                this.#roomCursor,

            community:
                this.#communityCursor

        });

    }

    accept(batch = {}) {

        if (!batch || typeof batch !== "object") {
            throw new TypeError("Event delivery batch must be an object.");
        }

        const roomEvents =
            this.#acceptStream(
                "room",
                Array.isArray(batch.events) ? batch.events : [],
                this.#roomCursor
            );

        const communityEvents =
            this.#acceptStream(
                "community",
                Array.isArray(batch.community_events)
                    ? batch.community_events
                    : [],
                this.#communityCursor
            );

        if (roomEvents.length) {
            this.#roomCursor =
                Number(roomEvents[roomEvents.length - 1].id);
        }

        if (communityEvents.length) {
            this.#communityCursor =
                Number(communityEvents[communityEvents.length - 1].id);
        }

        return Object.freeze({

            events:
                roomEvents,

            communityEvents,

            projection:
                Object.freeze({

                    avatarVisibilityPreferences:
                        batch.avatar_visibility_preferences ?? null,

                    gesturePreferences:
                        batch.gesture_preferences ?? null,

                    gestureCapabilities:
                        batch.gesture_capabilities ?? null

                }),

            cursors:
                this.cursors()

        });

    }

    reset() {

        this.seed();

    }

    #acceptStream(stream, events, cursor) {

        let previous =
            cursor;

        const accepted =
            [];

        for (const event of events) {

            const id =
                Number(event?.id || 0);

            if (!Number.isSafeInteger(id) || id <= 0) {
                throw new TypeError(`Invalid ${stream} event id.`);
            }

            if (id < previous) {
                throw new Error(`Out-of-order ${stream} event batch.`);
            }

            previous =
                id;

            const identity =
                `${stream}:${id}`;

            if (id <= cursor || this.#seen.has(identity)) {
                continue;
            }

            this.#remember(identity);

            accepted.push(event);

        }

        return accepted;

    }

    #remember(identity) {

        this.#seen.set(identity, true);

        this.#seenOrder.push(identity);

        while (this.#seenOrder.length > this.#seenLimit) {

            const expired =
                this.#seenOrder.shift();

            this.#seen.delete(expired);

        }

    }

}

export default ChatEventDeliveryContract;
