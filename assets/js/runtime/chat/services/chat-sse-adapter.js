/******************************************************************************
 * Build 000052 capability-gated Server-Sent Events framing adapter.
 ******************************************************************************/

export class ChatSseAdapter {

    #context = null;

    #source = null;

    #timer = null;

    #running = false;

    #failures = 0;

    #renewing = false;

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

        this.#renewing =
            false;

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

            } catch (error) {

                this.#fail(error);

            }

        });

        source.addEventListener("renew", () => {

            if (!this.#running || source !== this.#source) {
                return;
            }

            this.#renewing =
                true;

            this.#closeSource();

            const jitter =
                Math.floor(Math.random() * 75);

            this.#schedule(50 + jitter);

            context.onRenewal?.(
                Object.freeze({

                    adapter:
                        "sse",

                    expected:
                        true,

                    retryDelay:
                        50 + jitter

                })
            );

        });

        source.addEventListener("authorization", event => {

            let detail = {};

            try {
                detail = JSON.parse(String(event.data || "{}"));
            } catch (_) {
                detail = {};
            }

            this.#fail(
                new Error(String(detail.error || "Realtime authorization ended."))
            );

        });

        source.onerror =
            () => {
                if (this.#renewing) {
                    return;
                }
                this.#fail(
                    new Error("Realtime connection failed.")
                );
            };

    }

    #fail(error) {

        if (!this.#running) {
            return;
        }

        const context =
            this.#requireContext();

        this.#failures += 1;

        this.#renewing =
            false;

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
