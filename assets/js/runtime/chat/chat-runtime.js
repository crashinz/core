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
 *      Build 000022-A introduces message/channel state ownership only.
 *
 * Build:
 *      000022-A
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-A
 * - Introduced ChatRuntime foundation.
 * - Added ChatMessageStateService ownership and diagnostics.
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
                "000022-A",

            messages:
                this.#messages?.getDiagnostics() ?? null

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

    }

    /**
     * Called when the Chat Runtime is destroyed.
     */
    onDestroy() {

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

}

export default ChatRuntime;
