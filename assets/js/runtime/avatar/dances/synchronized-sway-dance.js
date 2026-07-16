export const SynchronizedSwayDance = Object.freeze({
    id: "synchronized-sway",
    label: "Synchronized Sway",
    durationMs: 2400,
    offset({ elapsedMs = 0 } = {}) {
        const phase = (Math.max(0, Number(elapsedMs)) % 2400) / 2400;
        return Object.freeze({
            x: Math.sin(phase * Math.PI * 2) * 18,
            y: 0
        });
    }
});

export default SynchronizedSwayDance;
