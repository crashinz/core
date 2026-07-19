/******************************************************************************
 * ChatSpace Avatar Visibility Service
 *
 * Owns the current viewer's exact-avatar and account-wide hidden-avatar
 * projection. Persistence and authoritative avatar identity remain server-side.
 ******************************************************************************/

const EXACT_SCOPE = "avatar";
const USER_SCOPE = "user";

export class AvatarVisibilityService {

    #runtime;

    #context = null;

    #version = 1;

    #entries = [];

    #exactTargets = new Set();

    #userTargets = new Set();

    #pending = new Set();

    #changeCount = 0;

    #configured = false;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    initialize() {
    }

    configure(context = {}) {
        this.#context = context;
        return this.snapshot();
    }

    destroy() {
        this.#context = null;
        this.#entries = [];
        this.#exactTargets.clear();
        this.#userTargets.clear();
        this.#pending.clear();
        this.#configured = false;
    }

    applyServerProjection(projection = {}, reason = "server-projection") {
        const version = Math.max(1, Number(projection?.version || 1));
        const entries = Array.isArray(projection?.entries)
            ? projection.entries.filter(entry => (
                Number.isInteger(Number(entry?.targetUserId))
                && Number(entry.targetUserId) > 0
                && [EXACT_SCOPE, USER_SCOPE].includes(String(entry?.scope || ""))
            )).map(entry => Object.freeze({
                id: Number(entry.id || 0),
                targetUserId: Number(entry.targetUserId),
                displayName: String(entry.displayName || "User"),
                scope: String(entry.scope),
                notice: String(entry.notice || "")
            }))
            : [];
        const signature = JSON.stringify([version, entries]);
        const previous = JSON.stringify([this.#version, this.#entries]);
        if (signature === previous) return false;
        this.#version = version;
        this.#configured = true;
        this.#entries = entries;
        this.#exactTargets = new Set(entries.filter(entry => entry.scope === EXACT_SCOPE).map(entry => entry.targetUserId));
        this.#userTargets = new Set(entries.filter(entry => entry.scope === USER_SCOPE).map(entry => entry.targetUserId));
        this.#changeCount += 1;
        this.#context?.onChange?.({ reason, version, entryCount: entries.length });
        return true;
    }

    snapshot() {
        return Object.freeze({
            version: this.#version,
            entries: Object.freeze(this.#entries.slice())
        });
    }

    isExactHidden(userId) {
        return this.#exactTargets.has(Number(userId));
    }

    isUserHidden(userId) {
        return this.#userTargets.has(Number(userId));
    }

    effectiveFor(subject, { own = false } = {}) {
        const userId = Number(subject?.user_id ?? subject?.targetUserId ?? subject?.id ?? 0);
        if (own || userId <= 0) {
            return Object.freeze({ hidden: false, exact: false, user: false, scope: null, notice: null });
        }
        const user = this.isUserHidden(userId);
        const exact = this.isExactHidden(userId);
        const serverHidden = !this.#configured && Boolean(subject?.avatar_hidden);
        const scope = user ? USER_SCOPE : exact ? EXACT_SCOPE : serverHidden
            ? String(subject?.avatar_hidden_scope || EXACT_SCOPE) : null;
        const hidden = Boolean(scope);
        const notice = hidden
            ? (scope === USER_SCOPE
                ? "Avatar hidden — You chose to hide avatars from this user."
                : "Avatar hidden — You chose to hide this avatar until it changes.")
            : null;
        return Object.freeze({ hidden, exact, user, scope, notice });
    }

    async setExactHidden(participant, hidden) {
        return this.#mutate(participant, hidden ? "hide_avatar" : "show_avatar");
    }

    async setUserHidden(participant, hidden) {
        return this.#mutate(participant, hidden ? "hide_user" : "show_user");
    }

    async showAll() {
        return this.#mutate(null, "show_all");
    }

    getDiagnostics() {
        return Object.freeze({
            owner: "AvatarRuntime",
            service: "AvatarVisibilityService",
            version: this.#version,
            exactPreferenceCount: this.#exactTargets.size,
            userPreferenceCount: this.#userTargets.size,
            pendingOperationCount: this.#pending.size,
            changeCount: this.#changeCount
        });
    }

    async #mutate(participant, action) {
        const userId = Number(participant?.user_id || 0);
        if (action !== "show_all" && (!Number.isInteger(userId) || userId <= 0)) return false;
        const operationKey = `${action}:${userId}`;
        if (this.#pending.has(operationKey) || typeof this.#context?.mutate !== "function") return false;
        this.#pending.add(operationKey);
        try {
            const result = await this.#context.mutate({
                action,
                target_user_id: userId || undefined,
                expected_version: this.#version
            });
            this.#context?.onMutationResult?.(result);
            this.applyServerProjection(result?.preferences || {}, `mutation:${action}`);
            return true;
        } finally {
            this.#pending.delete(operationKey);
        }
    }
}

export default AvatarVisibilityService;
