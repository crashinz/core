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
 *      Build 000022-F adds reply draft workflow ownership.
 *
 * Build:
 *      000022-F
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
 *
 * Build 000022-D
 * - Added ChatMessageActionService ownership and diagnostics.
 *
 * Build 000022-E
 * - Added ChatUnreadService ownership and diagnostics.
 *
 * Build 000022-F
 * - Added ChatReplyService ownership and diagnostics.
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

import {

    ChatMessageActionService

} from "./services/chat-message-action-service.js";

import {

    ChatUnreadService

} from "./services/chat-unread-service.js";

import {

    ChatReplyService

} from "./services/chat-reply-service.js";

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
     * Unread orchestration runtime component.
     */
    #unread = null;

    /**
     * Reply draft runtime component.
     */
    #reply = null;

    /**
     * Message renderer runtime component.
     */
    #renderer = null;

    /**
     * Event router runtime component.
     */
    #events = null;

    /**
     * Message action runtime component.
     */
    #actions = null;

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
     * Returns the Chat Unread Service.
     *
     * @returns {ChatUnreadService}
     *         Unread orchestration runtime component.
     */
    get unread() {

        return this.#unread;

    }

    /**
     * Returns the Chat Reply Service.
     *
     * @returns {ChatReplyService}
     *         Reply draft runtime component.
     */
    get reply() {

        return this.#reply;

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

    /**
     * Returns the Chat Message Action Service.
     *
     * @returns {ChatMessageActionService}
     *         Message action runtime component.
     */
    get actions() {

        return this.#actions;

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
                "000022-F",

            messages:
                this.#messages?.getDiagnostics() ?? null,

            unread:
                this.#unread?.getDiagnostics() ?? null,

            reply:
                this.#reply?.getDiagnostics() ?? null,

            renderer:
                this.#renderer?.getDiagnostics() ?? null,

            events:
                this.#events?.getDiagnostics() ?? null,

            actions:
                this.#actions?.getDiagnostics() ?? null

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

        this.#createUnread();

        this.#createReply();

        this.#createRenderer();

        this.#createEvents();

        this.#createActions();

    }

    /**
     * Called when the Chat Runtime is destroyed.
     */
    onDestroy() {

        this.#actions?.destroy();

        this.#events?.destroy();

        this.#renderer?.destroy();

        this.#reply?.destroy();

        this.#unread?.destroy();

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
     * Creates the unread orchestration runtime component.
     */
    #createUnread() {

        this.#unread =
            new ChatUnreadService(
                this
            );

        this.#unread.initialize();

    }

    /**
     * Creates the reply draft runtime component.
     */
    #createReply() {

        this.#reply =
            new ChatReplyService(
                this
            );

        this.#reply.initialize();

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

    /**
     * Creates the message action runtime component.
     */
    #createActions() {

        this.#actions =
            new ChatMessageActionService(
                this
            );

        this.#actions.initialize();

    }

}

export default ChatRuntime;
