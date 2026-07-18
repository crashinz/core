/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * Owner: Framework Core
 * Build: 000043 Part 5
 * Purpose: Own active-room JSON transport and structured response failures.
 ******************************************************************************/

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

function safeCause(error) {
    if (!error) return null;
    const name = String(error.name || "Error").slice(0, 80);
    const message = String(error.message || "Request failed")
        .replace(/https?:\/\/\S+/gi, "[url]")
        .replace(/(?:cookie|authorization|password|token)\s*[:=]\s*\S+/gi, "$1=[redacted]")
        .slice(0, 240);
    return `${name}: ${message}`;
}

function safeMessage(value, fallback = "Room request failed.") {
    const message = String(value || fallback)
        .replace(/https?:\/\/\S+/gi, "[url]")
        .replace(/[a-z]:\\(?:users|windows|temp)\\\S+/gi, "[private-path]")
        .replace(/\/(?:users|home|tmp)\/\S+/gi, "[private-path]")
        .replace(/((?:cookie|authorization|password|token)\s*[:=]\s*)\S+/gi, "$1[redacted]")
        .slice(0, 320);
    return message || fallback;
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
        causeSummary: details.causeSummary || null,
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
        const method = String(options.method || "GET").toUpperCase();
        const context = {
            operation: options.operation || "room-request",
            endpointCategory: options.endpointCategory || "room-api",
            method,
            status: null,
            redirected: false,
            contentType: null,
            recoverable: method === "GET",
            causeSummary: null,
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

        try {
            const response = await this.#fetch(this.#resolveUrl(path), {
                ...options,
                method,
                signal: controller.signal,
                operation: undefined,
                endpointCategory: undefined,
                timeoutMs: undefined,
            });
            context.status = Number(response.status);
            context.redirected = Boolean(response.redirected);
            context.contentType = String(response.headers?.get?.("content-type") || "")
                .trim()
                .slice(0, 120) || null;

            if (context.redirected || AUTH_PATH.test(String(response.url || ""))) {
                throw this.#error("AUTH_REDIRECT", "Authentication is required to continue.", context);
            }

            const text = await response.text();
            const trimmed = text.trim();

            if (response.status === 401) {
                throw this.#error("SESSION_UNAVAILABLE", "The room session is unavailable.", context);
            }
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

            const applicationMessage = data && typeof data === "object"
                ? String(data.error || data.message || "")
                : "";
            const safeApplicationMessage = safeMessage(applicationMessage, "Room API request failed.");
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
                this.#onFailure?.(error);
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
