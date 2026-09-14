// Server timestamps must not be compared directly with the viewer's wall clock.
let anchor = null;

export function animationMonotonicNow() {
    return globalThis.performance?.now?.() ?? Date.now();
}

export function observeAnimationServerDate(value) {
    const serverMs = Date.parse(String(value || ''));
    if (!Number.isFinite(serverMs)) return;
    const localMs = animationMonotonicNow();
    // HTTP Date has one-second precision. Retain a stable midpoint anchor
    // across polls instead of introducing visible per-response jitter.
    const midpoint = serverMs + 500;
    const estimated = anchor ? anchor.serverMs + localMs - anchor.localMs : midpoint;
    if (!anchor || Math.abs(midpoint - estimated) > 5000) {
        anchor = {serverMs: midpoint, localMs, uncertaintyMs: 500};
    }
}

export function serverEpochNow({conservative = false} = {}) {
    if (!anchor) return null;
    return anchor.serverMs + animationMonotonicNow() - anchor.localMs
        - (conservative ? anchor.uncertaintyMs : 0);
}

export function parseServerTimestamp(value) {
    if (typeof value === 'number') return value;
    const text = String(value || '').trim();
    if (!text) return NaN;
    const normalized = text.replace(' ', 'T');
    return Date.parse(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized) ? normalized : `${normalized}Z`);
}

export function serverDeadlineExpired(value) {
    const now = serverEpochNow({conservative: true});
    const deadline = parseServerTimestamp(value);
    // Never discard retained data solely because an unsynchronized local clock
    // says a server deadline has passed. Server responses still own permission.
    return now !== null && Number.isFinite(deadline) && deadline <= now;
}

export function animationElapsedMs(startedAtMs, observedAtMs) {
    const localMs = animationMonotonicNow();
    const fallback = Math.max(0, localMs - observedAtMs);
    if (!anchor) return fallback;
    const elapsed = anchor.serverMs + localMs - anchor.localMs - startedAtMs;
    // This projection is already active, not a scheduled future action.
    return Number.isFinite(elapsed) && elapsed >= 0 ? elapsed : fallback;
}
