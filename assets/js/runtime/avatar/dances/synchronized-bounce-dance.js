export const SynchronizedBounceDance = Object.freeze({
    id: "synchronized-bounce",
    label: "Synchronized Bounce",
    durationMs: 1600,
    offset({ elapsedMs = 0 } = {}) {
        const phase = (Math.max(0, Number(elapsedMs)) % 1600) / 1600;
        return Object.freeze({
            x: 0,
            y: Math.sin(phase * Math.PI * 2) * 14
        });
    }
});

export default SynchronizedBounceDance;
