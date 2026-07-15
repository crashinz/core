/**
 * Pure deterministic three-host formation with a centered bottom unit.
 */
export const BottomCenterTrioFormation = Object.freeze({

    id: "bottom-center-trio",

    isApplicable({ normalMemberCount = 0 } = {}) {

        return Number(normalMemberCount) === 3;

    },

    layout({ units = [], anchor = null, rowSpacing = 0 } = {}) {

        if (units.length !== 3) {
            throw new TypeError("Bottom-Center Trio requires exactly three host units.");
        }

        const gap = Math.max(0, Number(rowSpacing || 0));
        const first = units[0];
        const second = units[1];
        const third = units[2];
        const firstX = Number(anchor?.x || 0);
        const firstY = Number(anchor?.y || 0);
        const secondX = firstX + Number(first.bounds.right) - Number(second.bounds.left);
        const secondY = firstY;
        const topLeft = Math.min(
            firstX + Number(first.bounds.left),
            secondX + Number(second.bounds.left)
        );
        const topRight = Math.max(
            firstX + Number(first.bounds.right),
            secondX + Number(second.bounds.right)
        );
        const topBottom = Math.max(
            firstY + Number(first.bounds.bottom),
            secondY + Number(second.bounds.bottom)
        );
        const thirdWidth = Number(third.bounds.right) - Number(third.bounds.left);
        const thirdX = topLeft + ((topRight - topLeft) - thirdWidth) / 2
            - Number(third.bounds.left);
        const thirdY = topBottom + gap - Number(third.bounds.top);

        return Object.freeze([
            Object.freeze({ participantId: Number(first.participantId), x: firstX, y: firstY }),
            Object.freeze({ participantId: Number(second.participantId), x: secondX, y: secondY }),
            Object.freeze({ participantId: Number(third.participantId), x: thirdX, y: thirdY })
        ]);

    }

});

export default BottomCenterTrioFormation;
