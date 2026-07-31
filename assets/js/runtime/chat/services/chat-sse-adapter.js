/******************************************************************************
 * Build 000052 capability-gated Server-Sent Events framing adapter.
 ******************************************************************************/

export class ChatSseAdapter {

    #context = null;

    #source = null;

    #timer = null;

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

        if (this.#timer) {
            clearTimeout(this.#timer);
        }

        this.#timer =
            null;

        this.#closeSource();

    }

    diagnostics() {

        return Object.freeze({

            id:
                "sse",

            running:
                this.#running,

            connected:
                Boolean(this.#source),

            failureCount:
                this.#failures,

            hasTimer:
                Boolean(this.#timer)

        });

    }

    #connect() {

        const context =
            this.#requireContext();

        if (!this.#running || context.shouldStop?.()) {
            this.stop();
            return;
        }

        this.#closeSource();

        const url =
            context.createUrl(
                context.createQuery()
            );

        const source =
            context.createEventSource(url);

        this.#source =
            source;

        source.addEventListener("batch", async event => {

            if (!this.#running || source !== this.#source) {
                return;
            }

            try {

                const batch =
                    JSON.parse(String(event.data || "{}"));

                this.#failures =
                    0;

                await context.onBatch(batch, "sse");

                this.#closeSource();

                this.#schedule(25);

            } catch (error) {

                this.#fail(error);

            }

        });

        source.onerror =
            () => this.#fail(
                new Error("Server-Sent Events connection failed.")
            );

    }

    #fail(error) {

        if (!this.#running) {
            return;
        }

        const context =
            this.#requireContext();

        this.#failures += 1;

        this.#closeSource();

        context.onFailure?.(
            error,
            Object.freeze({

                adapter:
                    "sse",

                failureCount:
                    this.#failures,

                retryDelay:
                    0,

                fallback:
                    true

            })
        );

    }

    #schedule(delay) {

        if (this.#timer) {
            clearTimeout(this.#timer);
        }

        this.#timer =
            setTimeout(
                () => this.#connect(),
                Math.max(0, Number(delay || 0))
            );

    }

    #closeSource() {

        if (this.#source) {
            this.#source.close();
        }

        this.#source =
            null;

    }

    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatSseAdapter context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatSseAdapter;
