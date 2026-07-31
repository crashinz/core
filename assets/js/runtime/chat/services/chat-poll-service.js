/******************************************************************************
 * Chat Runtime main event transport facade.
 *
 * Build 000052 preserves the public ChatPollService lifecycle while moving
 * common cursor/deduplication truth and narrow Polling/SSE/WebSocket framing
 * under ChatTransportCoordinator. Polling remains mandatory and permanent.
 ******************************************************************************/

import {

    ChatTransportCoordinator

} from "./chat-transport-coordinator.js";

export class ChatPollService {

    #runtime;

    #context = null;

    #coordinator = new ChatTransportCoordinator();

    constructor(runtime) {

        this.#runtime =
            runtime;

    }

    initialize() {

    }

    destroy() {

        this.stop();

        this.#context =
            null;

    }

    get runtime() {

        return this.#runtime;

    }

    configure(context = {}) {

        this.#context =
            context;

        this.#coordinator.configure({

            ...context,

            handleRoomEvent:
                event => this.#routeRoomEvent(event),

            handleCommunityEvent:
                event => this.#routeCommunityEvent(event)

        });

    }

    seed(ids = {}) {

        this.#coordinator.seed(ids);

    }

    start() {

        this.#requireContext();

        this.#coordinator.start();

    }

    stop() {

        this.#coordinator.stop();

    }

    async poll() {

        this.#requireContext();

        await this.#coordinator.pollOnce();

    }

    getDiagnostics() {

        return Object.freeze({

            owner:
                "ChatRuntime",

            build:
                "000052",

            configured:
                Boolean(this.#context),

            transport:
                this.#coordinator.diagnostics()

        });

    }

    #routeRoomEvent(event) {

        if (this.#runtime.events.routeRoomEvent(event)) {
            return;
        }

        this.#requireContext().handleRoomEvent?.(
            event
        );

    }

    #routeCommunityEvent(event) {

        if (this.#runtime.events.routeCommunityEvent(event)) {
            return;
        }

        this.#requireContext().handleCommunityEvent?.(
            event
        );

    }

    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatPollService context has not been configured.");
        }

        return this.#context;

    }

}

export default ChatPollService;
