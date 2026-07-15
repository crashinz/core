/**
 * Pure deterministic row-major complete-host-unit grid formation.
 */
export const GridFormation = Object.freeze({

    id: "grid",

    isApplicable({ normalMemberCount = 0 } = {}) {

        return Number(normalMemberCount) >= 2;

    },

    layout({ units = [], anchor = null, rowSpacing = 0 } = {}) {

        if (units.length < 2) {
            throw new TypeError("Grid requires at least two host units.");
        }

        const gap = Math.max(0, Number(rowSpacing || 0));
        const columns = Math.ceil(Math.sqrt(units.length));
        const rows = Math.ceil(units.length / columns);
        const widths = units.map(unit => Number(unit.bounds.right) - Number(unit.bounds.left));
        const heights = units.map(unit => Number(unit.bounds.bottom) - Number(unit.bounds.top));
        const columnWidths = Array.from({ length: columns }, (_, column) =>
            Math.max(...units
                .map((unit, index) => index % columns === column ? widths[index] : 0))
        );
        const rowHeights = Array.from({ length: rows }, (_, row) =>
            Math.max(...units
                .map((unit, index) => Math.floor(index / columns) === row ? heights[index] : 0))
        );
        const first = units[0];
        const gridLeft = Number(anchor?.x || 0)
            + Number(first.bounds.left)
            - (columnWidths[0] - widths[0]) / 2;
        const gridTop = Number(anchor?.y || 0)
            + Number(first.bounds.top)
            - (rowHeights[0] - heights[0]) / 2;
        const columnLefts = [];
        const rowTops = [];
        let cursor = gridLeft;
        columnWidths.forEach(width => {
            columnLefts.push(cursor);
            cursor += width + gap;
        });
        cursor = gridTop;
        rowHeights.forEach(height => {
            rowTops.push(cursor);
            cursor += height + gap;
        });

        return Object.freeze(units.map((unit, index) => {
            const column = index % columns;
            const row = Math.floor(index / columns);
            return Object.freeze({
                participantId: Number(unit.participantId),
                x: columnLefts[column]
                    + (columnWidths[column] - widths[index]) / 2
                    - Number(unit.bounds.left),
                y: rowTops[row]
                    + (rowHeights[row] - heights[index]) / 2
                    - Number(unit.bounds.top)
            });
        }));

    }

});

export default GridFormation;
