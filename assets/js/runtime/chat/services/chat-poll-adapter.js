/******************************************************************************
 * Build 000052 mandatory Polling framing adapter.
 ******************************************************************************/

export class ChatPollAdapter {

    #context = null;

    #timer = null;

    #running = false;

    #requesting = false;

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

        this.request();

    }

    stop() {

        this.#running =
            false;

        if (this.#timer) {
            clearTimeout(this.#timer);
        }

        this.#timer =
            null;

    }

    async request() {

        const context =
            this.#requireContext();

        if (!this.#running || context.shouldStop?.()) {
            this.stop();
            return;
        }

        if (this.#requesting) {
            this.#schedule(context.pollInterval ?? 25);
            return;
        }

        this.#requesting =
            true;

        let delay =
            Number(context.pollInterval ?? 25);

        try {

            const batch =
                await context.fetchBatch(
                    context.createQuery()
                );

            this.#failures =
                0;

            await context.onBatch(batch, "polling");

        } catch (error) {

            this.#failures += 1;

            const base =
                Math.max(250, Number(context.failureBackoffBase ?? 1000));

            const maximum =
                Math.max(base, Number(context.failureBackoffMax ?? 30000));

            delay =
                Math.min(
                    maximum,
                    base * (2 ** Math.min(this.#failures - 1, 5))
                );

            context.onFailure?.(
                error,
                Object.freeze({

                    adapter:
                        "polling",

                    failureCount:
                        this.#failures,

                    retryDelay:
                        delay,

                    fallback:
                        false

                })
            );

        } finally {

            this.#requesting =
                false;

            if (this.#running && !context.shouldStop?.()) {
                this.#schedule(delay);
            }

        }

    }

    diagnostics() {

        return Object.freeze({

            id:
                "polling",

            mandatory:
                true,

            permanentFallback:
                true,

            running:
                this.#running,

            requesting:
                this.#requesting,

            failureCount:
                this.#failures,

            hasTimer:
                Boolean(this.#timer)

        });

    }

    #schedule(delay) {

        if (this.#timer) {
            clearTimeout(this.#timer);
        }

        this.#timer =
            setTimeout(
                () => this.request(),
                Math.max(0, Number(delay || 0))
            );

    }

    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatPollAdapter context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatPollAdapter;
