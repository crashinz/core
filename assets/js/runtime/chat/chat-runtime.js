/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-runtime.js
 *
 * Layer:
 *      Runtime
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns the Chat Runtime foundation.
 *
 *      ChatRuntime coordinates chat-specific runtime components while
 *      participating in the framework module lifecycle.
 *
 *      Build 000022-C adds incoming chat event routing ownership.
 *
 * Build:
 *      000022-C
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-A
 * - Introduced ChatRuntime foundation.
 * - Added ChatMessageStateService ownership and diagnostics.
 *
 * Build 000022-B
 * - Added ChatMessageRenderer ownership and diagnostics.
 *
 * Build 000022-C
 * - Added ChatEventRouter ownership and diagnostics.
 ******************************************************************************/

/**
 * @file chat-runtime.js
 *
 * Defines the Chat Runtime.
 */

import {

    CoreModule

} from "../../core/core-module.js";

import {

    ChatMessageStateService

} from "./services/chat-message-state-service.js";

import {

    ChatMessageRenderer

} from "./renderers/chat-message-renderer.js";

import {

    ChatEventRouter

} from "./routing/chat-event-router.js";

//--------------------------------------------------
// Chat Runtime
//--------------------------------------------------

/**
 * Coordinates chat runtime components.
 *
 * ChatRuntime owns the lifetime of all internal chat runtime components.
 */
export class ChatRuntime extends CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Message state runtime component.
     */
    #messages = null;

    /**
     * Message renderer runtime component.
     */
    #renderer = null;

    /**
     * Event router runtime component.
     */
    #events = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Runtime.
     */
    constructor() {

        super({

            id: "chat-runtime",

            name: "Chat Runtime",

            version: "1.0.0",

            description:
                "Coordinates chat runtime components.",

            metadata: {}

        });

    }

    //--------------------------------------------------
    // Public Runtime Components
    //--------------------------------------------------

    /**
     * Returns the Chat Message State Service.
     *
     * @returns {ChatMessageStateService}
     *         Message state runtime component.
     */
    get messages() {

        return this.#messages;

    }

    /**
     * Returns the Chat Message Renderer.
     *
     * @returns {ChatMessageRenderer}
     *         Message renderer runtime component.
     */
    get renderer() {

        return this.#renderer;

    }

    /**
     * Returns the Chat Event Router.
     *
     * @returns {ChatEventRouter}
     *         Event router runtime component.
     */
    get events() {

        return this.#events;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns runtime diagnostic information.
     *
     * @returns {Object}
     *         Chat Runtime diagnostics.
     */
    getDiagnostics() {

        return Object.freeze({

            id:
                this.id,

            name:
                this.name,

            build:
                "000022-C",

            messages:
                this.#messages?.getDiagnostics() ?? null,

            renderer:
                this.#renderer?.getDiagnostics() ?? null,

            events:
                this.#events?.getDiagnostics() ?? null

        });

    }

    //--------------------------------------------------
    // Protected Lifecycle Hooks
    //--------------------------------------------------

    /**
     * Called when the Chat Runtime is initialized.
     */
    onInitialize() {

        this.#createMessages();

        this.#createRenderer();

        this.#createEvents();

    }

    /**
     * Called when the Chat Runtime is destroyed.
     */
    onDestroy() {

        this.#renderer?.destroy();

        this.#events?.destroy();

        this.#messages?.destroy();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates the message state runtime component.
     */
    #createMessages() {

        this.#messages =
            new ChatMessageStateService(
                this
            );

        this.#messages.initialize();

    }

    /**
     * Creates the message renderer runtime component.
     */
    #createRenderer() {

        this.#renderer =
            new ChatMessageRenderer(
                this
            );

        this.#renderer.initialize();

    }

    /**
     * Creates the event router runtime component.
     */
    #createEvents() {

        this.#events =
            new ChatEventRouter(
                this
            );

        this.#events.initialize();

    }

}

export default ChatRuntime;
