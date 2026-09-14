/******************************************************************************
 * Build 000052 mandatory Polling framing adapter.
 ******************************************************************************/

export class ChatPollAdapter {

    #context = null;

    #timer = null;

    #running = false;

    #requesting = false;

    #failures = 0;

    #generation = 0;

    configure(context = {}) {

        this.#generation += 1;
        this.#failures = 0;

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

        this.#generation += 1;

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
            this.#schedule(this.#pollDelay(context));
            return;
        }

        this.#requesting =
            true;

        const generation = this.#generation;

        // Hidden rooms still receive DM/link messages and presence updates.
        // Reduce request frequency, but never skip fetching because of focus.
        let delay = this.#pollDelay(context);

        try {

            const batch =
                await context.fetchBatch(
                    context.createQuery()
                );

            if (!this.#running || context.shouldStop?.()
                || generation !== this.#generation || context !== this.#context) return;

            this.#failures =
                0;

            await context.onBatch(batch, "polling");

        } catch (error) {

            if (!this.#running || context.shouldStop?.()
                || generation !== this.#generation || context !== this.#context) return;

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

            if (this.#running && !this.#context?.shouldStop?.()) {
                this.#schedule(generation === this.#generation
                    ? Math.max(delay, this.#pollDelay()) : this.#pollDelay());
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

    #pollDelay(context = this.#context) {
        const normal = Math.max(25, Number(context?.pollInterval ?? 25) || 25);
        return typeof document !== "undefined" && document.hidden
            ? Math.max(normal, 1000, Number(context?.hiddenPollInterval ?? 2500) || 2500)
            : normal;
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
