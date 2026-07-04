/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      chat-message-renderer.js
 *
 * Layer:
 *      Runtime Renderer
 *
 * Owner:
 *      Chat Runtime
 *
 * Purpose:
 *      Owns chat message DOM rendering and presentation behavior.
 *
 *      ChatMessageRenderer renders message rows, system rows, reply previews,
 *      reaction chips, media/body markup, active chat redraws, scroll-stick
 *      behavior, and reply target highlighting.
 *
 *      This renderer consumes message state and room-provided rendering
 *      callbacks. It intentionally does not own composer workflows, uploads,
 *      polling, event routing, reactions API workflows, or chat business logic.
 *
 * Build:
 *      000022-B
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000022-B
 * - Introduced Chat Message Renderer.
 * - Transferred message DOM rendering from room.js.
 * - Transferred message scroll presentation state from room.js.
 * - Transferred reply preview, reaction, and media/body presentation markup.
 ******************************************************************************/

/**
 * @file chat-message-renderer.js
 *
 * Defines the Chat Message Renderer.
 */

//
// No imports required.
//

//--------------------------------------------------
// Chat Message Renderer
//--------------------------------------------------

/**
 * Owns chat message DOM rendering and presentation behavior.
 */
export class ChatMessageRenderer {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Chat Runtime.
     *
     * @type {ChatRuntime}
     */
    #runtime;

    /**
     * Rendering context supplied by the room composition root.
     *
     * @type {Object}
     */
    #context = null;

    /**
     * Whether the message viewport is pinned near the bottom.
     *
     * @type {boolean}
     */
    #messagesPinnedToBottom = true;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Chat Message Renderer.
     *
     * @param {ChatRuntime} runtime
     *        Owning Chat Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Participates in the runtime lifecycle.
     */
    initialize() {

    }

    /**
     * Releases renderer-owned presentation references.
     */
    destroy() {

        this.#context = null;

        this.#messagesPinnedToBottom = true;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Chat Runtime.
     *
     * @returns {ChatRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures renderer extension points.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Rendering API
    //--------------------------------------------------

    /**
     * Renders the active chat.
     *
     * @param {string} chatKey
     */
    renderActiveChat(chatKey = "room") {

        const messagesElement =
            this.#messagesElement();

        if (!messagesElement) {
            return;
        }

        messagesElement.innerHTML = "";

        this.#runtime.messages
            .sortedMessagesForChannel(chatKey)
            .forEach(message => this.bindMessageAutoScroll(
                this.appendMessage(message),
                true
            ));

        this.scrollMessagesToBottom();

    }

    /**
     * Appends a message row.
     *
     * @param {Object} message
     *
     * @returns {Element|null}
     */
    appendMessage(message) {

        const context =
            this.#requireContext();

        const messagesElement =
            this.#messagesElement();

        if (!messagesElement || !context.messageVisible(message)) {
            return null;
        }

        if (message.system) {
            const div =
                context.document.createElement("div");

            div.className = "chat-system";

            div.innerHTML =
                `<span class="system-badge">${context.esc(message.content)}</span>`;

            messagesElement.appendChild(div);

            return div;
        }

        const cfg =
            context.getConfig();

        const participants =
            context.getParticipants();

        const participant =
            participants.get(message.participant_id);

        const author =
            participant || message;

        const row =
            context.document.createElement("div");

        row.className =
            "message" +
            (message.participant_id === cfg.myParticipantId ? " me" : "") +
            (message.is_deleted ? " deleted" : "");

        row.dataset.messageId =
            message.id;

        const canShowOriginal =
            cfg.canModerateMessages &&
            message.original_content &&
            message.original_content !== message.content;

        const timeValue =
            !message.is_deleted && message.edited_at
                ? message.edited_at
                : message.sent_at;

        const timePrefix =
            !message.is_deleted && message.edited_at
                ? "Edited at "
                : "";

        const flagTime =
            timeValue
                ? `<span class="msg-name-time" data-ts="${context.esc(timeValue)}" data-prefix="${context.esc(timePrefix)}">${context.esc(timePrefix)}${context.esc(context.fullTimestamp(timeValue))}</span>`
                : "";

        const deletedMeta =
            message.is_deleted && message.deleted_at
                ? `<div class="msg-audit deleted-audit">Deleted at ${context.esc(context.fullTimestamp(message.deleted_at))}</div>`
                : "";

        const original =
            canShowOriginal
                ? `<details class="msg-original"><summary>Show original</summary><div>${context.esc(message.original_content)}</div></details>`
                : "";

        const body =
            message.is_deleted && cfg.canModerateMessages
                ? `<div class="msg-deleted-body">${this.messageBodyHtml(message)}</div>`
                : this.messageBodyHtml(message);

        const optionsButton =
            message.channel === "game"
                ? ""
                : '<button class="msg-options" type="button" aria-label="Message options">&#8942;</button>';

        row.innerHTML =
            `<div class="bubble"><div class="msg-head"><div class="msg-name ${context.participantRoleClass(author)}" title="${context.esc(context.participantRoleLabel(author))}"><img src="${context.esc(context.messageAvatarUrl(message, participant))}" alt=""><span class="msg-name-copy"><span class="msg-name-text">${context.esc(participant ? context.displayNameFor(participant) : message.display_name)}</span>${flagTime}</span></div>${optionsButton}</div>${this.replyPreviewHtml(message)}<div class="msg-content">${body}</div>${deletedMeta}${original}<div class="msg-meta-line">${this.renderReactions(message)}</div></div>`;

        row.querySelector(".msg-options")?.addEventListener("click", event => {

            event.stopPropagation();

            context.openMessageActionMenu(
                event.clientX,
                event.clientY,
                message
            );

        });

        if (message.channel !== "game") {
            row.addEventListener("contextmenu", event => {

                event.preventDefault();

                event.stopPropagation();

                context.openMessageActionMenu(
                    event.clientX,
                    event.clientY,
                    message
                );

            });
        }

        row.querySelectorAll(".reaction-chip").forEach(button => {

            button.addEventListener("click", () => context.applyReaction(
                message.id,
                button.dataset.msgReaction,
                context.getActiveChat()
            ));

        });

        row.querySelector(".msg-reply-preview")?.addEventListener("click", event => {

            event.preventDefault();

            this.jumpToMessage(
                event.currentTarget.dataset.replyTarget
            );

        });

        messagesElement.appendChild(row);

        return row;

    }

    /**
     * Renders reply preview markup.
     *
     * @param {Object} message
     *
     * @returns {string}
     */
    replyPreviewHtml(message) {

        const context =
            this.#requireContext();

        const reply =
            message.reply_to;

        if (!reply?.id) {
            return "";
        }

        const author =
            context.esc(reply.display_name || "Someone");

        const preview =
            context.esc(reply.preview || reply.original_name || "Message");

        return `<button class="msg-reply-preview" type="button" data-reply-target="${context.esc(reply.id)}"><span>Reply to ${author}</span><strong>${preview}</strong></button>`;

    }

    /**
     * Renders message body markup.
     *
     * @param {Object} message
     *
     * @returns {string}
     */
    messageBodyHtml(message) {

        const context =
            this.#requireContext();

        const url =
            context.esc(context.mediaUrl(message.content));

        const name =
            context.esc(message.original_name || "Attachment");

        const mime =
            String(message.mime_type || "");

        if (message.message_type === "gif") {
            return `<a class="chat-attachment-image chat-gif" href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${name}"></a>`;
        }

        if (message.message_type === "gesture") {

            const gesture =
                context.gestureFromMessage(message);

            if (!gesture) {
                return context.esc(message.original_name || "Gesture");
            }

            const gif =
                context.esc(context.mediaUrl(gesture.gif_path || gesture.gif_url || ""));

            const text =
                context.esc(gesture.text || gesture.name || message.original_name || "Gesture");

            return `<div class="chat-gesture"><a class="chat-attachment-image chat-gif chat-gesture-gif" href="${gif}" target="_blank" rel="noopener"><img src="${gif}" alt="${text}"></a><div class="chat-gesture-text">${text}</div></div>`;

        }

        if (message.message_type === "voice_note") {
            return `<div class="voice-note-player"><audio controls src="${url}"></audio></div>`;
        }

        if (message.message_type === "file") {

            if (mime.startsWith("image/")) {
                return `<a class="chat-attachment-image" href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${name}"></a>`;
            }

            const ext =
                (message.original_name || "file")
                    .split(".")
                    .pop()
                    .slice(0, 4)
                    .toUpperCase();

            return `<a class="chat-file" href="${url}" target="_blank" rel="noopener" download><span class="chat-file-icon">${context.esc(ext || "FILE")}</span><span><span class="chat-file-name">${name}</span><span class="chat-file-meta">${context.esc(message.mime_type || "Document")} &middot; ${context.formatBytes(message.file_size)}</span></span></a>`;

        }

        return `<div class="chat-text">${this.linkifiedTextHtml(message.content)}</div>${this.urlPreviewHtml(message.url_preview)}`;

    }

    /**
     * Renders reaction chips.
     *
     * @param {Object} message
     *
     * @returns {string}
     */
    renderReactions(message) {

        const context =
            this.#requireContext();

        const cfg =
            context.getConfig();

        const reactions =
            Array.isArray(message.reactions)
                ? message.reactions
                : [];

        if (!reactions.length) {
            return "";
        }

        const groups =
            new Map();

        reactions.forEach(reaction => {

            if (!groups.has(reaction.emoji)) {
                groups.set(
                    reaction.emoji,
                    []
                );
            }

            groups.get(reaction.emoji).push(reaction);

        });

        return `<div class="msg-reactions">${[...groups.entries()].map(([emoji, items]) => {

            const own =
                items.some(item => Number(item.participant_id) === cfg.myParticipantId);

            const avatars =
                items.map(item => `<img src="${context.esc(context.mediaUrl(item.avatar_url || cfg.avatarPresets.Default))}" alt="${context.esc(item.display_name || "User")}" title="${context.esc(item.display_name || "User")}">`).join("");

            return `<button class="reaction-chip${own ? " own" : ""}" type="button" data-msg-reaction="${context.esc(emoji)}"><span class="reaction-emoji">${context.esc(emoji)}</span><span class="reaction-avatars">${avatars}</span></button>`;

        }).join("")}</div>`;

    }

    /**
     * Linkifies message text.
     *
     * @param {string} text
     *
     * @returns {string}
     */
    linkifiedTextHtml(text) {

        const context =
            this.#requireContext();

        const raw =
            String(text || "");

        const parts =
            raw.split(/(https?:\/\/[^\s<>"']+)/gi);

        return parts.map(part => {

            if (!/^https?:\/\//i.test(part)) {
                return context.esc(part).replace(/\n/g, "<br>");
            }

            const clean =
                part.replace(/[.,!?)]}]+$/g, "");

            const suffix =
                part.slice(clean.length);

            return `<a class="chat-text-link" href="${context.esc(clean)}" target="_blank" rel="noopener noreferrer">${context.esc(clean)}</a>${context.esc(suffix)}`;

        }).join("");

    }

    /**
     * Renders URL preview markup.
     *
     * @param {Object} preview
     *
     * @returns {string}
     */
    urlPreviewHtml(preview) {

        const context =
            this.#requireContext();

        if (!preview || typeof preview !== "object" || !context.isHttpUrl(preview.url)) {
            return "";
        }

        const title =
            context.esc(preview.title || preview.provider || preview.host || preview.url);

        const description =
            context.esc(preview.description || "");

        const host =
            context.esc(preview.provider || preview.host || "");

        const image =
            context.isHttpUrl(preview.image_url)
                ? context.esc(preview.image_url)
                : "";

        if (preview.type === "player" && context.isHttpUrl(preview.embed_url)) {

            const providerClass =
                String(preview.provider || "")
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, "-");

            return `<div class="url-preview url-preview-player ${providerClass}">
      <div class="url-preview-host">${host}</div>
      <iframe src="${context.esc(preview.embed_url)}" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin"></iframe>
      ${title ? `<a class="url-preview-title" href="${context.esc(preview.url)}" target="_blank" rel="noopener noreferrer">${title}</a>` : ""}
    </div>`;

        }

        return `<a class="url-preview url-preview-summary" href="${context.esc(preview.url)}" target="_blank" rel="noopener noreferrer">
    ${image ? `<span class="url-preview-thumb"><img src="${image}" alt=""></span>` : ""}
    <span class="url-preview-copy">
      <span class="url-preview-host">${host}</span>
      ${title ? `<span class="url-preview-title">${title}</span>` : ""}
      ${description ? `<span class="url-preview-description">${description}</span>` : ""}
    </span>
  </a>`;

    }

    //--------------------------------------------------
    // Public Scroll API
    //--------------------------------------------------

    /**
     * Returns whether messages are near the bottom.
     *
     * @returns {boolean}
     */
    messagesNearBottom() {

        const messagesElement =
            this.#messagesElement();

        if (!messagesElement) {
            return true;
        }

        return messagesElement.scrollHeight -
            messagesElement.scrollTop -
            messagesElement.clientHeight <= 80;

    }

    /**
     * Returns whether newly appended messages should auto-scroll.
     *
     * @returns {boolean}
     */
    shouldAutoScrollMessages() {

        return this.#messagesPinnedToBottom ||
            this.messagesNearBottom();

    }

    /**
     * Scrolls the message viewport to the bottom.
     */
    scrollMessagesToBottom() {

        const messagesElement =
            this.#messagesElement();

        if (!messagesElement) {
            return;
        }

        messagesElement.scrollTop =
            messagesElement.scrollHeight;

        this.#messagesPinnedToBottom = true;

    }

    /**
     * Binds media load auto-scroll behavior to a row.
     *
     * @param {Element} row
     * @param {boolean} shouldStick
     */
    bindMessageAutoScroll(row, shouldStick) {

        if (!row || !shouldStick) {
            return;
        }

        row.querySelectorAll("img, video, audio").forEach(media => {

            const keepStuck = () => {

                if (this.#messagesPinnedToBottom || this.messagesNearBottom()) {
                    this.scrollMessagesToBottom();
                }

            };

            media.addEventListener(
                "load",
                keepStuck,
                { once: true }
            );

            media.addEventListener(
                "loadedmetadata",
                keepStuck,
                { once: true }
            );

            media.addEventListener(
                "canplay",
                keepStuck,
                { once: true }
            );

        });

    }

    /**
     * Synchronizes pinned state after scroll.
     */
    syncPinnedToBottom() {

        this.#messagesPinnedToBottom =
            this.messagesNearBottom();

    }

    /**
     * Scrolls to a message and flashes it.
     *
     * @param {number|string} messageId
     */
    jumpToMessage(messageId) {

        const context =
            this.#requireContext();

        const messagesElement =
            this.#messagesElement();

        if (!messagesElement) {
            return;
        }

        const row =
            messagesElement.querySelector(`[data-message-id="${context.CSS.escape(String(messageId))}"]`);

        if (!row) {
            return;
        }

        row.scrollIntoView({

            block:
                "center",

            behavior:
                "smooth"

        });

        row.classList.remove("message-reply-flash");

        void row.offsetWidth;

        row.classList.add("message-reply-flash");

        context.window.setTimeout(
            () => row.classList.remove("message-reply-flash"),
            1250
        );

    }

    /**
     * Animates visible room history clear rows.
     *
     * @param {Object} options
     * @param {Function} options.onRender
     */
    animateRoomHistoryClear({ onRender = null } = {}) {

        const context =
            this.#requireContext();

        const messagesElement =
            this.#messagesElement();

        if (!messagesElement) {
            return;
        }

        const rows =
            [...messagesElement.children].reverse();

        if (!rows.length) {

            if (typeof onRender === "function") {
                onRender();
            }

            return;

        }

        rows.forEach((row, index) => {

            row.style.maxHeight =
                `${row.offsetHeight}px`;

            row.style.animationDelay =
                `${index * 42}ms`;

            row.classList.add("message-wipe-out");

        });

        context.window.setTimeout(
            () => {

                if (typeof onRender === "function") {
                    onRender();
                }

            },
            rows.length * 42 + 520
        );

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns renderer diagnostic information.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ChatRuntime",

            build:
                "000022-B",

            configured:
                Boolean(this.#context),

            messagesPinnedToBottom:
                this.#messagesPinnedToBottom

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns configured rendering context.
     *
     * @returns {Object}
     */
    #requireContext() {

        if (!this.#context) {
            throw new Error("ChatMessageRenderer context has not been configured.");
        }

        return this.#context;

    }

    /**
     * Returns configured message container element.
     *
     * @returns {Element|null}
     */
    #messagesElement() {

        return this.#context?.messagesElement || null;

    }

}

export default ChatMessageRenderer;
