/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * Build 000043 Part 2 - Runtime Diagnostics and Observability Cleanup
 ******************************************************************************/

export const RUNTIME_DIAGNOSTICS_SCHEMA_ID = "chatspace.runtime-diagnostics";
export const RUNTIME_DIAGNOSTICS_SCHEMA_VERSION = 1;

export const RUNTIME_DIAGNOSTICS_STANDARD_LIMITS = Object.freeze({
    signaling: 512,
    videoLifecycle: 512,
    videoAssignments: 256,
    audioAssignments: 256,
    playResults: 256,
    peers: 64,
    getUserMedia: 64,
    trackEvents: 256,
    general: 256
});

export const RUNTIME_DIAGNOSTICS_VERIFICATION_LIMITS = Object.freeze({
    signaling: 8192,
    videoLifecycle: 4096,
    videoAssignments: 1024,
    audioAssignments: 1024,
    playResults: 1024,
    peers: 1024,
    getUserMedia: 256,
    trackEvents: 1024,
    general: 512
});

const REDACTED = "[redacted]";
const MAX_STRING_LENGTH = 512;
const MAX_ARRAY_LENGTH = 64;
const MAX_OBJECT_KEYS = 64;
const MAX_DEPTH = 5;
const MAX_DETAILS_BYTES = 24576;
const SENSITIVE_KEY = /(?:authorization|cookie|csrf|password|secret|token|deviceid|groupid)/i;
const PRIVATE_PATH = /(?:[a-z]:\\|\/(?:users|home|tmp)\/)/i;

class RingBuffer {
    #capacity;
    #entries;
    #start = 0;
    #size = 0;
    #dropped = 0;

    constructor(capacity) {
        this.#capacity = Math.max(1, Number(capacity) || 1);
        this.#entries = new Array(this.#capacity);
    }

    push(value) {
        if (this.#size < this.#capacity) {
            this.#entries[(this.#start + this.#size) % this.#capacity] = value;
            this.#size += 1;
            return;
        }
        this.#entries[this.#start] = value;
        this.#start = (this.#start + 1) % this.#capacity;
        this.#dropped += 1;
    }

    snapshot() {
        return Array.from({ length: this.#size }, (_, index) =>
            this.#entries[(this.#start + index) % this.#capacity]
        );
    }

    clear() {
        this.#entries = new Array(this.#capacity);
        this.#start = 0;
        this.#size = 0;
        this.#dropped = 0;
    }

    get dropped() {
        return this.#dropped;
    }
}

export class RuntimeDiagnostics {
    #enabled;
    #mode;
    #limits;
    #buffers = new Map();
    #sequence = 0;
    #destroyed = false;

    constructor({ enabled = false, mode = "standard", limits = null } = {}) {
        this.#enabled = Boolean(enabled);
        this.#mode = this.#enabled && mode === "verification" ? "verification" : "standard";
        this.#limits = Object.freeze({
            ...(this.#mode === "verification"
                ? RUNTIME_DIAGNOSTICS_VERIFICATION_LIMITS
                : RUNTIME_DIAGNOSTICS_STANDARD_LIMITS),
            ...(limits || {})
        });
    }

    isEnabled() {
        return this.#enabled && !this.#destroyed;
    }

    record(category, event, details = {}) {
        if (!this.isEnabled()) return false;
        const channel = normalizeName(category, "general");
        const eventName = normalizeName(event, "event");
        const sanitized = sanitizeDetails(details);
        this.#buffer(channel).push(Object.freeze({
            sequence: ++this.#sequence,
            timestamp: Date.now(),
            category: channel,
            event: eventName,
            details: sanitized
        }));
        return true;
    }

    snapshot() {
        const channels = {};
        const dropped = {};
        if (this.isEnabled()) {
            for (const [name, buffer] of this.#buffers) {
                channels[name] = buffer.snapshot();
                dropped[name] = buffer.dropped;
            }
        }
        return Object.freeze({
            schemaId: RUNTIME_DIAGNOSTICS_SCHEMA_ID,
            schemaVersion: RUNTIME_DIAGNOSTICS_SCHEMA_VERSION,
            enabled: this.isEnabled(),
            mode: this.isEnabled() ? this.#mode : "disabled",
            generatedAt: Date.now(),
            limits: { ...this.#limits },
            dropped,
            channels
        });
    }

    export() {
        return JSON.stringify(this.snapshot());
    }

    clear() {
        for (const buffer of this.#buffers.values()) buffer.clear();
        this.#sequence = 0;
    }

    destroy() {
        if (this.#destroyed) return;
        this.clear();
        this.#buffers.clear();
        this.#enabled = false;
        this.#destroyed = true;
    }

    #buffer(channel) {
        if (!this.#buffers.has(channel)) {
            this.#buffers.set(
                channel,
                new RingBuffer(this.#limits[channel] || this.#limits.general)
            );
        }
        return this.#buffers.get(channel);
    }
}

export class RuntimeVerificationControls {
    #enabled;
    #controls = new Map();

    constructor(enabled = false) {
        this.#enabled = Boolean(enabled);
    }

    isEnabled() {
        return this.#enabled;
    }

    register(name, handler) {
        if (!this.#enabled || typeof handler !== "function") return false;
        this.#controls.set(normalizeName(name, "control"), handler);
        return true;
    }

    async invoke(name, ...args) {
        if (!this.#enabled) throw new Error("Runtime verification controls are disabled.");
        const handler = this.#controls.get(normalizeName(name, "control"));
        if (!handler) throw new Error(`Unknown runtime verification control: ${name}`);
        return handler(...args);
    }

    list() {
        return this.#enabled ? Array.from(this.#controls.keys()).sort() : [];
    }

    destroy() {
        this.#controls.clear();
        this.#enabled = false;
    }
}

export function installRuntimeDiagnostics({ globalObject = globalThis, enabled = false, mode = "standard", verificationControls = false } = {}) {
    const diagnostics = new RuntimeDiagnostics({ enabled, mode });
    const controls = new RuntimeVerificationControls(
        diagnostics.isEnabled() && verificationControls
    );
    let pageHideHandler = null;

    const installation = {
        diagnostics,
        controls,
        destroy() {
            controls.destroy();
            diagnostics.destroy();
            if (pageHideHandler && globalObject?.removeEventListener) {
                globalObject.removeEventListener("pagehide", pageHideHandler);
            }
            if (globalObject?.ChatRuntimeDiagnostics === diagnostics) {
                delete globalObject.ChatRuntimeDiagnostics;
            }
            if (globalObject?.ChatRuntimeVerificationControls === controls) {
                delete globalObject.ChatRuntimeVerificationControls;
            }
            pageHideHandler = null;
        }
    };

    if (!diagnostics.isEnabled()) return installation;

    Object.defineProperty(globalObject, "ChatRuntimeDiagnostics", {
        configurable: true,
        value: diagnostics
    });
    if (controls.isEnabled()) {
        Object.defineProperty(globalObject, "ChatRuntimeVerificationControls", {
            configurable: true,
            value: controls
        });
    }
    if (globalObject?.addEventListener) {
        pageHideHandler = () => installation.destroy();
        globalObject.addEventListener("pagehide", pageHideHandler, { once: true });
    }
    return installation;
}

function normalizeName(value, fallback) {
    const normalized = String(value || fallback)
        .trim()
        .replace(/[^a-zA-Z0-9_.:-]/g, "-")
        .slice(0, 96);
    return normalized || fallback;
}

function sanitizeDetails(details) {
    const sanitized = sanitizeValue(details, "", 0, new WeakSet());
    let bytes = 0;
    try {
        bytes = new TextEncoder().encode(JSON.stringify(sanitized)).length;
    } catch {
        return Object.freeze({ invalid: true });
    }
    if (bytes > MAX_DETAILS_BYTES) {
        return Object.freeze({ truncated: true, originalBytes: bytes });
    }
    return deepFreeze(sanitized);
}

function sanitizeValue(value, key, depth, seen) {
    if (SENSITIVE_KEY.test(key)) return REDACTED;
    if (key.toLowerCase() === "sdp") return "[redacted-sdp]";
    if (key.toLowerCase() === "candidate" && typeof value === "string") {
        return "[redacted-ice-candidate]";
    }
    if (value === null || typeof value === "boolean" || typeof value === "number") return value;
    if (typeof value === "string") {
        if (PRIVATE_PATH.test(value)) return "[redacted-path]";
        return value.length > MAX_STRING_LENGTH
            ? `${value.slice(0, MAX_STRING_LENGTH)}...[truncated]`
            : value;
    }
    if (typeof value === "undefined") return null;
    if (typeof value !== "object") return String(value).slice(0, MAX_STRING_LENGTH);
    if (depth >= MAX_DEPTH || seen.has(value)) return "[truncated-object]";
    seen.add(value);
    if (Array.isArray(value)) {
        const result = value.slice(0, MAX_ARRAY_LENGTH)
            .map(item => sanitizeValue(item, key, depth + 1, seen));
        if (value.length > MAX_ARRAY_LENGTH) result.push(`[${value.length - MAX_ARRAY_LENGTH} more]`);
        return result;
    }
    const result = {};
    for (const objectKey of Object.keys(value).slice(0, MAX_OBJECT_KEYS)) {
        result[objectKey] = sanitizeValue(value[objectKey], objectKey, depth + 1, seen);
    }
    if (Object.keys(value).length > MAX_OBJECT_KEYS) result.__truncatedKeys = true;
    return result;
}

function deepFreeze(value) {
    if (!value || typeof value !== "object" || Object.isFrozen(value)) return value;
    Object.freeze(value);
    Object.values(value).forEach(deepFreeze);
    return value;
}
