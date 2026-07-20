const AMPLITUDE_DEGREES = 6;
const DURATION_MS = 2400;

export const LapDance = Object.freeze({
    id: "lap_dance",
    label: "Lap Dance",
    durationMs: DURATION_MS,
    amplitudeDegrees: AMPLITUDE_DEGREES,

    sample({ elapsedMs = 0 } = {}) {
        const phase = ((Math.max(0, Number(elapsedMs) || 0) % DURATION_MS) / DURATION_MS);
        return Object.freeze({
            translateY: 0,
            rotateDegrees: AMPLITUDE_DEGREES * Math.sin(2 * Math.PI * phase)
        });
    },

    envelope({ width = 1, height = 1 } = {}) {
        const renderedWidth = Math.max(1, Number(width) || 1);
        const renderedHeight = Math.max(1, Number(height) || 1);
        const radians = AMPLITUDE_DEGREES * Math.PI / 180;
        const envelopeWidth = Math.ceil(
            Math.abs(renderedWidth * Math.cos(radians))
            + Math.abs(renderedHeight * Math.sin(radians))
        );
        const envelopeHeight = Math.ceil(
            Math.abs(renderedWidth * Math.sin(radians))
            + Math.abs(renderedHeight * Math.cos(radians))
        );
        return Object.freeze({
            offsetX: -(envelopeWidth - renderedWidth) / 2,
            offsetY: -(envelopeHeight - renderedHeight) / 2,
            width: envelopeWidth,
            height: envelopeHeight
        });
    }
});

export default LapDance;
