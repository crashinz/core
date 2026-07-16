/**
 * Pure deterministic three-host formation with a centered top unit.
 */
export const TopCenterTrioFormation = Object.freeze({

    id: "top-center-trio",

    isApplicable({ normalMemberCount = 0 } = {}) {

        return Number(normalMemberCount) === 3;

    },

    layout({ units = [], anchor = null, rowSpacing = 0 } = {}) {

        if (units.length !== 3) {
            throw new TypeError("Top-Center Trio requires exactly three host units.");
        }

        const gap = Math.max(0, Number(rowSpacing || 0));
        const first = units[0];
        const second = units[1];
        const third = units[2];
        const firstX = Number(anchor?.x || 0);
        const firstY = Number(anchor?.y || 0);
        const lowerThirdOffset = Number(second.bounds.right) - Number(third.bounds.left);
        const lowerLeft = Math.min(
            Number(second.bounds.left),
            lowerThirdOffset + Number(third.bounds.left)
        );
        const lowerRight = Math.max(
            Number(second.bounds.right),
            lowerThirdOffset + Number(third.bounds.right)
        );
        const firstCenter = firstX
            + (Number(first.bounds.left) + Number(first.bounds.right)) / 2;
        const secondX = firstCenter - (lowerRight - lowerLeft) / 2 - lowerLeft;
        const thirdX = secondX + lowerThirdOffset;
        const lowerTop = firstY + Number(first.bounds.bottom) + gap;
        const lowerBoundsTop = Math.min(
            Number(second.bounds.top),
            Number(third.bounds.top)
        );
        const lowerY = lowerTop - lowerBoundsTop;

        return Object.freeze([
            Object.freeze({ participantId: Number(first.participantId), x: firstX, y: firstY }),
            Object.freeze({ participantId: Number(second.participantId), x: secondX, y: lowerY }),
            Object.freeze({ participantId: Number(third.participantId), x: thirdX, y: lowerY })
        ]);

    }

});

export default TopCenterTrioFormation;
