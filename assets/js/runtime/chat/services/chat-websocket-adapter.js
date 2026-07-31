/******************************************************************************
 * Build 000052 capability-gated same-origin WSS framing adapter.
 *
 * The current release intentionally projects this adapter unavailable because
 * no repository-owned persistent WebSocket process has passed its lifecycle
 * and deployment audit. This narrow adapter is inert unless that exact server
 * capability later provides a same-origin WSS endpoint.
 ******************************************************************************/

export class ChatWebSocketAdapter {

    #context = null;

    #socket = null;

    #running = false;

    #failures = 0;

    configure(context = {}) {

        this.#context =
            context;

    }

    start() {

        if (this.#running) {
            return;
        }

        this.#running =
            true;

        this.#connect();

    }

    stop() {

        this.#running =
            false;

        if (this.#socket) {
            this.#socket.close(1000, "transport-stop");
        }

        this.#socket =
            null;

    }

    diagnostics() {

        return Object.freeze({

            id:
                "websocket",

            running:
                this.#running,

            connected:
                Boolean(this.#socket),

            failureCount:
                this.#failures

        });

    }

    #connect() {

        const context =
            this.#requireContext();

        const rawUrl =
            String(context.createUrl() || "");

        const url =
            new URL(rawUrl, globalThis.location?.href);

        if (url.protocol !== "wss:"
            || !globalThis.location
            || url.host !== globalThis.location.host) {
            this.#fail(
                new Error("WebSocket requires a proven same-origin WSS endpoint.")
            );
            return;
        }

        const socket =
            context.createWebSocket(
                url.href,
                ["chatspace.event-delivery.v1"]
            );

        this.#socket =
            socket;

        socket.onopen =
            () => {

                this.#failures =
                    0;

                socket.send(
                    JSON.stringify(
                        context.createSubscription()
                    )
                );

            };

        socket.onmessage =
            async event => {

                if (!this.#running || socket !== this.#socket) {
                    return;
                }

                try {

                    await context.onBatch(
                        JSON.parse(String(event.data || "{}")),
                        "websocket"
                    );

                } catch (error) {

                    this.#fail(error);

                }

            };

        socket.onerror =
            () => this.#fail(
                new Error("WebSocket connection failed.")
            );

        socket.onclose =
            event => {

                if (this.#running && event.code !== 1000) {
                    this.#fail(
                        new Error("WebSocket connection closed unexpectedly.")
                    );
                }

            };

    }

    #fail(error) {

        if (!this.#running) {
            return;
        }

        this.#failures += 1;

        this.#requireContext().onFailure?.(
            error,
            Object.freeze({

                adapter:
                    "websocket",

                failureCount:
                    this.#failures,

                retryDelay:
                    0,

                fallback:
                    true

            })
        );

    }

    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatWebSocketAdapter context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatWebSocketAdapter;
