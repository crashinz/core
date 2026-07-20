const PREFERRED_RISE_PX = 14;
const DURATION_MS = 1600;

export const LapBounce = Object.freeze({
    id: "lap_bounce",
    label: "Lap Bounce",
    durationMs: DURATION_MS,
    preferredRisePx: PREFERRED_RISE_PX,
    protectedHostFraction: 0.25,

    sample({ elapsedMs = 0, effectiveRisePx = 0 } = {}) {
        const phase = ((Math.max(0, Number(elapsedMs) || 0) % DURATION_MS) / DURATION_MS);
        const wave = Math.sin(Math.PI * phase);
        return Object.freeze({
            translateY: -Math.max(0, Number(effectiveRisePx) || 0) * wave * wave,
            rotateDegrees: 0
        });
    },

    envelope({ width = 1, height = 1, effectiveRisePx = 0 } = {}) {
        const rise = Math.max(0, Math.floor(Number(effectiveRisePx) || 0));
        return Object.freeze({
            offsetX: 0,
            offsetY: -rise,
            width: Math.max(1, Number(width) || 1),
            height: Math.max(1, Number(height) || 1) + rise
        });
    }
});

export default LapBounce;
