/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * Owner: Framework Core
 * Build: 000043 Part 5
 * Purpose: Own active-room JSON transport and structured response failures.
 ******************************************************************************/

import { observeAnimationServerDate } from './animation-server-clock.js?v=20260913-r2';

const ERROR_CODES = Object.freeze({
    AUTH_REDIRECT: "AUTH_REDIRECT",
    SESSION_UNAVAILABLE: "SESSION_UNAVAILABLE",
    CSRF_REJECTED: "CSRF_REJECTED",
    HTML_RESPONSE: "HTML_RESPONSE",
    INVALID_CONTENT_TYPE: "INVALID_CONTENT_TYPE",
    INVALID_JSON: "INVALID_JSON",
    EMPTY_RESPONSE: "EMPTY_RESPONSE",
    API_CONTRACT_ERROR: "API_CONTRACT_ERROR",
    REQUEST_TIMEOUT: "REQUEST_TIMEOUT",
    REQUEST_ABORTED: "REQUEST_ABORTED",
    NETWORK_ERROR: "NETWORK_ERROR",
    HTTP_ERROR: "HTTP_ERROR",
});

const JSON_CONTENT_TYPE = /^(?:application|text)\/(?:[a-z0-9.+-]*\+)?json(?:\s*;|$)/i;
const HTML_CONTENT_TYPE = /^(?:text\/html|application\/xhtml\+xml)(?:\s*;|$)/i;
const HTML_PREFIX = /^\s*(?:<!doctype\s+html|<html\b)/i;
const AUTH_PATH = /\/(?:login|setup)\.php(?:$|[?#])/i;
const CSRF_TEXT = /\bcsrf\b|cross[- ]site request forgery/i;
let sharedCooldownTimer = null;
let sharedCooldownUntil = 0;
const LOOPBACK_REQUEST_LOCK = "corechat-loopback-single-worker-http";
const LOOPBACK_HOST = /^(?:127(?:\.\d{1,3}){3}|localhost)$/i;

function gameSessionReadRequestBinding(input, method, baseUrl = globalThis.location?.href) {
  try {
    if (String(method).toUpperCase() !== 'GET') return null;
    const base = new URL(baseUrl);
    const url = new URL(typeof input?.url === 'string' ? input.url : String(input || ''), base);
    if (!['http:', 'https:'].includes(base.protocol) || url.origin !== base.origin
      || !/\/api\/game_framework\.php$/i.test(url.pathname)) return null;
    for (const key of ['action', 'game_session_id', 'public_id', 'session_id', 'participant_id']) {
      if (url.searchParams.getAll(key).length > 1) return null;
    }
    if (url.searchParams.get('action') !== 'session') return null;
    const gameId = url.searchParams.get('game_session_id');
    const publicId = url.searchParams.get('public_id');
    if (gameId !== null && publicId !== null && gameId.trim() !== publicId.trim()) return null;
    const lobbyCode = (gameId ?? publicId ?? '').trim();
    if (!/^[A-Za-z0-9_-]{1,160}$/.test(lobbyCode)) return null;
    const roomId = url.searchParams.get('session_id') || '';
    if (!/^[A-Za-z0-9_-]{1,160}$/.test(roomId)) return null;
    const participantId = url.searchParams.get('participant_id');
    if (participantId !== null && !/^[1-9][0-9]*$/.test(participantId)) return null;
    return Object.freeze({ kind: 'game-session-read-v1', lobbyCode });
  } catch { return null; }
}

function withLoopbackRequestLock(callback) {
    const host = String(globalThis.location?.hostname || "");
    const locks = globalThis.navigator?.locks;
    if (!LOOPBACK_HOST.test(host) || typeof locks?.request !== "function") {
        return callback();
    }
    return locks.request(LOOPBACK_REQUEST_LOCK, { mode: "exclusive" }, callback);
}

function showSharedCooldown(seconds, message) {
    const duration = Math.max(1, Math.ceil(Number(seconds) || 1));
    sharedCooldownUntil = Math.max(sharedCooldownUntil, Date.now() + duration * 1000);
    let status = document.getElementById("runtime-request-cooldown");
    if (!status) {
        status = document.createElement("div");
        status.id = "runtime-request-cooldown";
        status.className = "runtime-request-cooldown";
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        document.body.appendChild(status);
    }
    status.dataset.message = safeMessage(message, "Please wait before trying again.");
    const update = () => {
        const remaining = Math.max(0, Math.ceil((sharedCooldownUntil - Date.now()) / 1000));
        if (remaining <= 0) {
            status.remove();
            sharedCooldownTimer = null;
            sharedCooldownUntil = 0;
            return;
        }
        status.textContent = `${status.dataset.message} ${remaining} second${remaining === 1 ? "" : "s"} remaining.`;
        sharedCooldownTimer = setTimeout(update, 250);
    };
    if (sharedCooldownTimer !== null) clearTimeout(sharedCooldownTimer);
    update();
}

function safeCause(error) {
    if (!error) return null;
    const name = String(error.name || "Error").slice(0, 80);
    // JSON.parse messages can contain a verbatim excerpt of the response body.
    const message = name === "SyntaxError" ? "Response parsing failed."
        : safeMessage(error.message, "Request failed").slice(0, 240);
    return `${safeMessage(name)}: ${message}`;
}

function safeMessage(value, fallback = "Room request failed.") {
    const message = String(value || fallback)
        .replace(/https?:\/\/\S+/gi, "[url]")
        .replace(/[a-z]:\\(?:users|windows|temp)\\\S+/gi, "[private-path]")
        .replace(/\/(?:users|home|tmp)\/\S+/gi, "[private-path]")
        .replace(/((?:cookie|authorization|password|secret|csrf|token)\s*[:=]\s*)\S+/gi, "$1[redacted]")
        .slice(0, 320);
    return message || fallback;
}

function freezeRequestContext(provider) {
    try {
        const value = typeof provider === "function" ? provider() : provider;
        if (!value || typeof value !== "object" || Array.isArray(value)) return null;
        const generation = input => Number.isSafeInteger(input) && input >= 0 ? input : null;
        const purpose = ["terminal-session-probe", "game-session-read", "game-options-read", "game-records-read"]
            .includes(value.requestPurpose) ? value.requestPurpose : "unknown";
        return Object.freeze({
            gameType: typeof value.gameType === "string" && /^[a-z0-9][a-z0-9_-]{0,63}$/i.test(value.gameType)
                ? value.gameType : null,
            requestPurpose: purpose,
            requestOrdinal: generation(value.requestOrdinal),
            loadGeneration: generation(value.loadGeneration),
            openGeneration: generation(value.openGeneration),
            ownerCurrentAtOutcome: typeof value.ownerCurrentAtOutcome === "boolean" ? value.ownerCurrentAtOutcome : null,
            sameGameAtOutcome: typeof value.sameGameAtOutcome === "boolean" ? value.sameGameAtOutcome : null,
        });
    } catch {
        return null;
    }
}

function freezeDetails(details) {
    return Object.freeze({
        operation: details.operation || "room-request",
        endpointCategory: details.endpointCategory || "room-api",
        method: details.method || "GET",
        status: Number.isFinite(details.status) ? details.status : null,
        redirected: Boolean(details.redirected),
        contentType: details.contentType || null,
        recoverable: Boolean(details.recoverable),
        retryAfter: Number.isFinite(details.retryAfter) ? details.retryAfter : null,
        causeSummary: details.causeSummary || null,
        requestContext: freezeRequestContext(details.requestContext),
    });
}

export class RuntimeRequestError extends Error {

    constructor(code, message, details = {}) {
        super(message || "Room request failed.");
        this.name = "RuntimeRequestError";
        this.code = code;
        this.details = freezeDetails(details);
    }

}

export class RuntimeRequestClient {

    #resolveUrl;
    #csrfToken;
    #fetch;
    #defaultTimeoutMs;
    #onFailure;
    #lifecycleSignal;

    constructor({
        resolveUrl = path => path,
        csrfToken = "",
        fetchImpl = globalThis.fetch?.bind(globalThis),
        defaultTimeoutMs = 30000,
        onFailure = null,
        lifecycleSignal = null,
    } = {}) {
        if (typeof fetchImpl !== "function") {
            throw new TypeError("RuntimeRequestClient requires fetch().");
        }
        this.#resolveUrl = resolveUrl;
        this.#csrfToken = csrfToken;
        this.#fetch = fetchImpl;
        this.#defaultTimeoutMs = defaultTimeoutMs;
        this.#onFailure = onFailure;
        this.#lifecycleSignal = lifecycleSignal;
    }

    getJson(path, options = {}) {
        return this.requestJson(path, { ...options, method: "GET" });
    }

    postJson(path, body = {}, options = {}) {
        const payload = { ...(body || {}) };
        if (!("_csrf" in payload)) payload._csrf = this.#csrfToken;
        return this.requestJson(path, {
            ...options,
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": this.#csrfToken,
                ...(options.headers || {}),
            },
            body: JSON.stringify(payload),
        });
    }

    postForm(path, formData, options = {}) {
        if (formData && !formData.has("_csrf")) {
            formData.append("_csrf", this.#csrfToken);
        }
        return this.requestJson(path, {
            ...options,
            method: "POST",
            headers: {
                "X-CSRF-Token": this.#csrfToken,
                ...(options.headers || {}),
            },
            body: formData,
        });
    }

    async requestJson(path, options = {}) {
        const { onRequestStart = null, ...requestOptions } = options;
        return withLoopbackRequestLock(() => {
            if (typeof onRequestStart === "function") onRequestStart();
            return this.#requestJsonUnlocked(path, requestOptions);
        });
    }

    async #requestJsonUnlocked(path, options = {}) {
        const method = String(options.method || "GET").toUpperCase();
        const context = {
            operation: options.operation || "room-request",
            endpointCategory: options.endpointCategory || "room-api",
            method,
            status: null,
            redirected: false,
            contentType: null,
            recoverable: method === "GET",
            retryAfter: null,
            causeSummary: null,
            requestContext: options.requestContext,
        };
        const controller = new AbortController();
        const callerSignal = options.signal || null;
        const lifecycleSignal = this.#lifecycleSignal;
        const timeoutMs = options.timeoutMs === undefined
            ? this.#defaultTimeoutMs
            : Number(options.timeoutMs);
        let timedOut = false;
        const abortFromCaller = () => controller.abort(callerSignal?.reason);
        const abortFromLifecycle = () => controller.abort(lifecycleSignal?.reason);
        if (callerSignal?.aborted) abortFromCaller();
        else callerSignal?.addEventListener?.("abort", abortFromCaller, { once: true });
        if (lifecycleSignal?.aborted) abortFromLifecycle();
        else lifecycleSignal?.addEventListener?.("abort", abortFromLifecycle, { once: true });
        const timeoutId = Number.isFinite(timeoutMs) && timeoutMs > 0
            ? setTimeout(() => {
                timedOut = true;
                controller.abort();
            }, timeoutMs)
            : null;
        let rejectAborted;
        const interrupted = new Promise((resolve, reject) => { rejectAborted = reject; });
        const onAborted = () => rejectAborted(new Error("Request interrupted."));
        controller.signal.addEventListener("abort", onAborted, { once: true });

        try {
            if (controller.signal.aborted) throw new Error("Request interrupted.");
            const resolvedRequestUrl = this.#resolveUrl(path);
            const response = await Promise.race([interrupted, this.#fetch(resolvedRequestUrl, {
                ...options,
                method,
                signal: controller.signal,
                operation: undefined,
                endpointCategory: undefined,
                shouldReportFailure: undefined,
                requestContext: undefined,
                timeoutMs: undefined,
            })]);
            context.status = Number(response.status);
            context.redirected = Boolean(response.redirected);
            context.contentType = String(response.headers?.get?.("content-type") || "")
                .trim()
                .slice(0, 120) || null;
            const retryAfterHeader = Number.parseInt(String(response.headers?.get?.("retry-after") || ""), 10);
            if (Number.isFinite(retryAfterHeader) && retryAfterHeader > 0) context.retryAfter = retryAfterHeader;

            if (context.redirected || AUTH_PATH.test(String(response.url || ""))) {
                throw this.#error("AUTH_REDIRECT", "Authentication is required to continue.", context);
            }

            if (response.status === 401) {
                throw this.#error("SESSION_UNAVAILABLE", "The room session is unavailable.", context);
            }
            const text = await Promise.race([interrupted, response.text()]);
            const trimmed = text.trim();
            if (HTML_CONTENT_TYPE.test(context.contentType || "") || HTML_PREFIX.test(trimmed)) {
                throw this.#error("HTML_RESPONSE", "The room API returned an HTML response.", context);
            }
            if (!JSON_CONTENT_TYPE.test(context.contentType || "")) {
                throw this.#error("INVALID_CONTENT_TYPE", "The room API did not return JSON.", context);
            }
            if (!trimmed) {
                throw this.#error("EMPTY_RESPONSE", "The room API returned an empty response.", context);
            }

            let data;
            try {
                data = JSON.parse(text);
            } catch (error) {
                context.causeSummary = safeCause(error);
                throw this.#error("INVALID_JSON", "The room API returned invalid JSON.", context);
            }

            if (data?.reauthentication_required === true && typeof window !== "undefined") {
                window.dispatchEvent(new Event("corechat:reauthentication-required"));
            }
            observeAnimationServerDate(response.headers.get('Date'));
            const applicationMessage = data && typeof data === "object"
                ? String(data.error || data.message || "")
                : "";
            const safeApplicationMessage = safeMessage(applicationMessage, "Room API request failed.");
            if (response.status === 429) {
                const payloadRetryAfter = Number(data?.retry_after);
                const retryAfter = Number.isFinite(payloadRetryAfter) && payloadRetryAfter > 0
                    ? Math.ceil(payloadRetryAfter)
                    : (context.retryAfter || 1);
                context.retryAfter = retryAfter;
                showSharedCooldown(retryAfter, safeApplicationMessage);
            }
            if (response.status === 403 && CSRF_TEXT.test(applicationMessage)) {
                throw this.#error("CSRF_REJECTED", safeApplicationMessage, context);
            }
            if (!response.ok) {
                const responseError = this.#error("HTTP_ERROR", applicationMessage
                    ? safeApplicationMessage
                    : `Room API request failed (${response.status}).`, context);
                Object.defineProperty(responseError, "responsePayload", {
                    value: data && typeof data === "object" && !Array.isArray(data) ? data : null,
                    enumerable: false,
                    configurable: false,
                    writable: false,
                });
                Object.defineProperty(responseError, "gameSessionReadBinding", {
                    value: gameSessionReadRequestBinding(resolvedRequestUrl, method),
                    enumerable: false,
                    configurable: false,
                    writable: false,
                });
                throw responseError;
            }
            if (!data || typeof data !== "object" || Array.isArray(data)) {
                throw this.#error("API_CONTRACT_ERROR", "The room API returned an invalid response contract.", context);
            }
            if (data.error) {
                throw this.#error("API_CONTRACT_ERROR", safeApplicationMessage, context);
            }
            if (typeof options.validate === "function" && !options.validate(data)) {
                throw this.#error("API_CONTRACT_ERROR", "The room API response did not match its contract.", context);
            }
            return data;
        } catch (error) {
            if (error instanceof RuntimeRequestError) {
                let shouldReportFailure = true;
                if (typeof options.shouldReportFailure === "function") {
                    try {
                        shouldReportFailure = options.shouldReportFailure(error) !== false;
                    } catch (_predicateError) {
                        shouldReportFailure = true;
                    }
                }
                if (shouldReportFailure) this.#onFailure?.(error);
                throw error;
            }
            context.causeSummary = safeCause(error);
            const requestError = timedOut
                ? this.#error("REQUEST_TIMEOUT", "The room request timed out.", context)
                : callerSignal?.aborted || lifecycleSignal?.aborted
                    ? this.#error("REQUEST_ABORTED", "The room request was cancelled.", context)
                    : this.#error("NETWORK_ERROR", "The room request could not reach the server.", context);
            this.#onFailure?.(requestError);
            throw requestError;
        } finally {
            if (timeoutId !== null) clearTimeout(timeoutId);
            controller.signal.removeEventListener("abort", onAborted);
            callerSignal?.removeEventListener?.("abort", abortFromCaller);
            lifecycleSignal?.removeEventListener?.("abort", abortFromLifecycle);
        }
    }

    #error(code, message, context) {
        if (!(code in ERROR_CODES)) throw new Error(`Unknown request error code: ${code}`);
        return new RuntimeRequestError(ERROR_CODES[code], message, context);
    }

}

export { ERROR_CODES as RuntimeRequestErrorCodes };
export default RuntimeRequestClient;
