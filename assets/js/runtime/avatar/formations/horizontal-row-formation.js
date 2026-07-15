/**
 * Pure deterministic compatibility formation for the accepted Part 5 row.
 */
export const HorizontalRowFormation = Object.freeze({

    id: "horizontal-row",

    isApplicable({ normalMemberCount = 0 } = {}) {

        return Number(normalMemberCount) >= 1;

    },

    layout({ units = [], anchor = null, rowSpacing = 0 } = {}) {

        const gap = Math.max(0, Number(rowSpacing || 0));
        const anchorX = Number(anchor?.x || 0);
        const anchorY = Number(anchor?.y || 0);
        let previousUnitRight = null;

        return Object.freeze(
            Array.from(units).map((unit, index) => {

                const x = index === 0
                    ? anchorX
                    : Number(previousUnitRight) + gap - Number(unit.bounds.left);

                previousUnitRight = x + Number(unit.bounds.right);

                return Object.freeze({
                    participantId: Number(unit.participantId),
                    x,
                    y: anchorY
                });

            })
        );

    }

});

export default HorizontalRowFormation;
