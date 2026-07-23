"use strict";

const DEFAULTS = Object.freeze({
    showAnimations: true,
    showText: true,
    playSounds: true,
    version: 0,
});

const CAPABILITY_DEFAULTS = Object.freeze({
    allowGestures: true,
    allowServerGestures: true,
    allowPersonalGestures: true,
    allowUserGestureMutation: true,
    allowGestureAudioDelivery: true,
    revision: 1,
    viewerUserId: 0,
});

function stableId(value) {
    const id = String(value || "").trim();
    return id.length <= 64 && /^[A-Za-z0-9._:-]+$/.test(id) ? id : "";
}

export class GesturePresentationService {
    #preferences;
    #hiddenIds;
    #hiddenSenderUserIds;
    #onChange;
    #capabilities;

    constructor(options = {}) {
        this.#preferences = { ...DEFAULTS };
        this.#hiddenIds = new Set();
        this.#hiddenSenderUserIds = new Set();
        this.#capabilities = { ...CAPABILITY_DEFAULTS };
        this.#onChange = typeof options.onChange === "function" ? options.onChange : () => {};
    }

    capabilities() {
        return Object.freeze({ ...this.#capabilities });
    }

    applyCapabilityProjection(projection = {}, reason = "capability-server") {
        const previousCapabilities = this.capabilities();
        const master = projection.allowGestures !== false;
        this.#capabilities = {
            allowGestures: master,
            allowServerGestures: master && projection.allowServerGestures !== false,
            allowPersonalGestures: master && projection.allowPersonalGestures !== false,
            allowUserGestureMutation: master && projection.allowUserGestureMutation !== false,
            allowGestureAudioDelivery: master && projection.allowGestureAudioDelivery !== false,
            revision: Math.max(1, Number(projection.revision || 1)),
            viewerUserId: Math.max(0, Number(projection.viewerUserId || this.#capabilities.viewerUserId || 0)),
        };
        const currentCapabilities = this.capabilities();
        if (JSON.stringify(previousCapabilities) !== JSON.stringify(currentCapabilities)) {
            const preferences = this.preferences();
            this.#onChange({
                previous: preferences,
                current: preferences,
                previousCapabilities,
                currentCapabilities,
                reason,
            });
        }
        return currentCapabilities;
    }

    preferences() {
        return Object.freeze({
            ...this.#preferences,
            hiddenIds: Object.freeze([...this.#hiddenIds]),
            hiddenSenderUserIds: Object.freeze([...this.#hiddenSenderUserIds]),
        });
    }

    applyServerProjection(projection = {}, reason = "server") {
        const previous = this.preferences();
        this.#preferences = {
            showAnimations: projection.show_animations !== false,
            showText: projection.show_text !== false,
            playSounds: projection.play_sounds !== false,
            version: Math.max(0, Number(projection.preference_version || 0)),
            serverSort: ["last_uploaded", "file_name", "custom"].includes(projection.server_sort)
                ? projection.server_sort
                : "last_uploaded",
            personalSort: ["last_uploaded", "file_name", "custom"].includes(projection.personal_sort)
                ? projection.personal_sort
                : "last_uploaded",
            serverOrderVersion: Math.max(0, Number(projection.server_order_version || 0)),
            personalOrderVersion: Math.max(0, Number(projection.personal_order_version || 0)),
            hiddenVersion: Math.max(0, Number(projection.hidden_version || 0)),
            senderVisibilityVersion: Math.max(0, Number(projection.sender_visibility_version || 0)),
        };
        this.#hiddenIds = new Set((projection.hidden_ids || []).map(stableId).filter(Boolean));
        this.#hiddenSenderUserIds = new Set(
            (projection.hidden_sender_user_ids || [])
                .map(value => Number(value))
                .filter(value => Number.isInteger(value) && value > 0)
        );
        const current = this.preferences();
        const comparable = value => JSON.stringify({
            ...value,
            hiddenIds: [...(value.hiddenIds || [])].sort(),
            hiddenSenderUserIds: [...(value.hiddenSenderUserIds || [])].sort((a, b) => a - b),
        });
        if (comparable(previous) !== comparable(current)) this.#onChange({ previous, current, reason });
        return current;
    }

    canonicalText(gesture) {
        const text = String(gesture?.text ?? "").trim();
        return text === "" ? "(Gesture)" : `(Gesture) ${text}`;
    }

    publicId(gesture) {
        return stableId(gesture?.public_id);
    }

    isHidden(gestureOrId) {
        const id = typeof gestureOrId === "object" ? this.publicId(gestureOrId) : stableId(gestureOrId);
        return id !== "" && this.#hiddenIds.has(id);
    }

    isSenderHidden(userId) {
        const id = Number(userId);
        return Number.isInteger(id) && id > 0 && this.#hiddenSenderUserIds.has(id);
    }

    applySenderVisibilityMutation(userId, hidden, version, reason = "sender-visibility-mutation") {
        const id = Number(userId);
        if (!Number.isInteger(id) || id < 1) throw new Error("A stable sender identity is required.");
        const previous = this.preferences();
        if (hidden) this.#hiddenSenderUserIds.add(id);
        else this.#hiddenSenderUserIds.delete(id);
        this.#preferences.senderVisibilityVersion = Math.max(0, Number(version || 0));
        const current = this.preferences();
        this.#onChange({ previous, current, reason, senderUserId: id, senderHidden: Boolean(hidden) });
        return current;
    }

    applyHiddenMutation(gestureOrId, hidden, version, reason = "hidden-mutation") {
        const id = typeof gestureOrId === "object" ? this.publicId(gestureOrId) : stableId(gestureOrId);
        if (id === "") throw new Error("A stable gesture identity is required.");
        const previous = this.preferences();
        if (hidden) this.#hiddenIds.add(id);
        else this.#hiddenIds.delete(id);
        this.#preferences.hiddenVersion = Math.max(0, Number(version || 0));
        const current = this.preferences();
        this.#onChange({ previous, current, reason });
        return current;
    }

    applyOrderVersion(scope, version) {
        if (scope === "server") {
            this.#preferences.serverOrderVersion = Math.max(0, Number(version || 0));
            this.#preferences.serverSort = "custom";
        } else if (scope === "personal") {
            this.#preferences.personalOrderVersion = Math.max(0, Number(version || 0));
            this.#preferences.personalSort = "custom";
        }
        return this.preferences();
    }

    messageModel(gesture, message = null) {
        const canonicalText = this.canonicalText(gesture);
        const publicId = this.publicId(gesture);
        const personal = Number(gesture?.owner_user_id || 0) === Number(this.#capabilities.viewerUserId || 0);
        const scopeAllowed = personal
            ? this.#capabilities.allowPersonalGestures
            : this.#capabilities.allowServerGestures;
        if (!this.#capabilities.allowGestures || !scopeAllowed) {
            return Object.freeze({
                publicId,
                individuallyHidden: false,
                senderMediaHidden: false,
                showAnimation: false,
                showText: true,
                canonicalText,
                hiddenText: !this.#capabilities.allowGestures
                    ? "Gesture media unavailable â€” gestures are disabled through shared Settings."
                    : `Gesture media unavailable â€” ${personal ? "Personal" : "Server"} Gestures are disabled through shared Settings.`,
                playSound: false,
            });
        }
        const individuallyHidden = publicId !== "" && this.#hiddenIds.has(publicId);
        if (individuallyHidden) {
            return Object.freeze({
                publicId,
                individuallyHidden: true,
                showAnimation: false,
                showText: this.#preferences.showText,
                canonicalText,
                hiddenText: "Gesture hidden — You chose to hide this gesture.",
                playSound: false,
            });
        }
        const senderUserId = Number(message?.user_id || 0);
        const senderMediaHidden = Number.isInteger(senderUserId)
            && senderUserId > 0
            && this.#hiddenSenderUserIds.has(senderUserId);
        if (senderMediaHidden) {
            return Object.freeze({
                publicId,
                individuallyHidden: false,
                senderMediaHidden: true,
                senderUserId,
                showAnimation: false,
                showText: true,
                canonicalText,
                hiddenText: "Gesture media hidden â€” You chose to hide gesture media from this user.",
                playSound: false,
            });
        }
        const animationAvailable = String(gesture?.gif_path || gesture?.gif_url || "").trim() !== "";
        const showAnimation = this.#preferences.showAnimations && animationAvailable;
        const showText = this.#preferences.showText;
        return Object.freeze({
            publicId,
            individuallyHidden: false,
            senderMediaHidden: false,
            showAnimation,
            showText,
            canonicalText,
            hiddenText: !showAnimation && !showText ? "(Gesture hidden)" : "",
            explanatoryText: animationAvailable && !this.#preferences.showAnimations && showText
                ? "Gesture animation hidden — Gesture animations are turned off in Chat Options."
                : "",
            playSound: this.#preferences.playSounds && this.#capabilities.allowGestureAudioDelivery,
        });
    }

    renderMessageHtml(gesture, context, message = null) {
        const model = this.messageModel(gesture, message);
        const esc = context.escapeHtml;
        if (model.individuallyHidden) {
            const canonical = model.showText
                ? `<div class="chat-gesture-text">${esc(model.canonicalText)}</div>`
                : "";
            const action = model.publicId
                ? `<button class="chat-gesture-show-again" type="button" data-gesture-show-again="${esc(model.publicId)}">Show again</button>`
                : "";
            return `<div class="chat-gesture chat-gesture-hidden" data-gesture-public-id="${esc(model.publicId)}"><div class="chat-gesture-hidden-notice">${esc(model.hiddenText)} ${action}</div>${canonical}</div>`;
        }
        const animation = model.showAnimation
            ? `<div class="chat-attachment-image chat-gif chat-gesture-gif" data-gesture-media><img src="${esc(context.mediaUrl(gesture?.gif_path || gesture?.gif_url || ""))}" alt="${esc(model.canonicalText)}" draggable="false"></div>`
            : "";
        const text = model.showText
            ? `<div class="chat-gesture-text">${esc(model.canonicalText)}</div>`
            : "";
        const explanation = model.explanatoryText
            ? `<div class="chat-gesture-explanation">${esc(model.explanatoryText)}</div>`
            : "";
        const hidden = model.hiddenText
            ? `<div class="chat-gesture-text">${esc(model.hiddenText)}</div>`
            : "";
        return `<div class="chat-gesture" data-gesture-public-id="${esc(model.publicId)}">${animation}${text}${explanation}${hidden}</div>`;
    }
}

export const gesturePresentationDefaults = DEFAULTS;
