const EPSILON = 1e-7;
const PRECISION = 1e6;

function pixel(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 0;
    return Math.round((number + Number.EPSILON) * PRECISION) / PRECISION;
}

function normalizeUnit(unit) {
    const bounds = unit?.bounds || {};
    return Object.freeze({
        participantId: Number(unit?.participantId),
        bounds: Object.freeze({
            left: pixel(bounds.left),
            top: pixel(bounds.top),
            right: pixel(bounds.right),
            bottom: pixel(bounds.bottom)
        })
    });
}

function unitWidth(unit) {
    return pixel(unit.bounds.right - unit.bounds.left);
}

function unitHeight(unit) {
    return pixel(unit.bounds.bottom - unit.bounds.top);
}

function proposalBounds(units, placements) {
    const placementById = new Map(
        placements.map(placement => [Number(placement.participantId), placement])
    );
    const boxes = units.map(unit => {
        const placement = placementById.get(unit.participantId);
        return {
            participantId: unit.participantId,
            left: pixel(placement.x + unit.bounds.left),
            top: pixel(placement.y + unit.bounds.top),
            right: pixel(placement.x + unit.bounds.right),
            bottom: pixel(placement.y + unit.bounds.bottom)
        };
    });
    return Object.freeze({
        boxes: Object.freeze(boxes),
        left: Math.min(...boxes.map(box => box.left)),
        top: Math.min(...boxes.map(box => box.top)),
        right: Math.max(...boxes.map(box => box.right)),
        bottom: Math.max(...boxes.map(box => box.bottom))
    });
}

function clampTranslation(bounds, stageWidth, stageHeight) {
    let x = 0;
    let y = 0;
    if (bounds.left < 0) x = -bounds.left;
    else if (bounds.right > stageWidth) x = stageWidth - bounds.right;
    if (bounds.top < 0) y = -bounds.top;
    else if (bounds.bottom > stageHeight) y = stageHeight - bounds.bottom;
    return Object.freeze({ x: pixel(x), y: pixel(y) });
}

function boxesOverlap(first, second) {
    return first.left < second.right - EPSILON
        && first.right > second.left + EPSILON
        && first.top < second.bottom - EPSILON
        && first.bottom > second.top + EPSILON;
}

function fitProposal(units, placements, stageWidth, stageHeight) {
    if (!Array.isArray(placements) || placements.length !== units.length) return null;
    const expected = new Set(units.map(unit => unit.participantId));
    const seen = new Set();
    const normalized = [];
    for (const placement of placements) {
        const participantId = Number(placement?.participantId);
        const x = pixel(placement?.x);
        const y = pixel(placement?.y);
        if (!expected.has(participantId) || seen.has(participantId)
            || !Number.isFinite(x) || !Number.isFinite(y)) {
            return null;
        }
        seen.add(participantId);
        normalized.push(Object.freeze({ participantId, x, y }));
    }
    const bounds = proposalBounds(units, normalized);
    if (bounds.right - bounds.left > stageWidth + EPSILON
        || bounds.bottom - bounds.top > stageHeight + EPSILON) {
        return null;
    }
    for (let first = 0; first < bounds.boxes.length; first += 1) {
        for (let second = first + 1; second < bounds.boxes.length; second += 1) {
            if (boxesOverlap(bounds.boxes[first], bounds.boxes[second])) return null;
        }
    }
    const translation = clampTranslation(bounds, stageWidth, stageHeight);
    const translated = normalized.map(placement => Object.freeze({
        participantId: placement.participantId,
        x: pixel(placement.x + translation.x),
        y: pixel(placement.y + translation.y)
    }));
    const translatedBounds = proposalBounds(units, translated);
    if (translatedBounds.left < -EPSILON || translatedBounds.top < -EPSILON
        || translatedBounds.right > stageWidth + EPSILON
        || translatedBounds.bottom > stageHeight + EPSILON) {
        return null;
    }
    return Object.freeze({
        placements: Object.freeze(translated),
        bounds: translatedBounds,
        translation,
        translationDistance: pixel(Math.abs(translation.x) + Math.abs(translation.y))
    });
}

function rowProposal(units, rows, anchor, gap) {
    const placements = [];
    let rowTop = pixel(anchor.y + units[0].bounds.top);
    rows.forEach((row, rowIndex) => {
        const rowUnits = row.map(index => units[index]);
        const rowHeight = Math.max(...rowUnits.map(unitHeight));
        let previousRight = null;
        row.forEach((unitIndex, columnIndex) => {
            const unit = units[unitIndex];
            const x = columnIndex === 0
                ? anchor.x
                : pixel(previousRight + gap - unit.bounds.left);
            const y = pixel(rowTop - unit.bounds.top);
            placements.push(Object.freeze({ participantId: unit.participantId, x, y }));
            previousRight = pixel(x + unit.bounds.right);
        });
        if (rowIndex < rows.length - 1) rowTop = pixel(rowTop + rowHeight + gap);
    });
    return Object.freeze(placements);
}

function greedyRows(units, stageWidth, anchor, gap) {
    const rows = [];
    let current = [];
    units.forEach((unit, index) => {
        const candidate = [...current, index];
        const proposal = rowProposal(units, [candidate], anchor, gap);
        const bounds = proposalBounds(units.filter((_, unitIndex) => candidate.includes(unitIndex)), proposal);
        if (current.length && bounds.right - bounds.left > stageWidth + EPSILON) {
            rows.push(current);
            current = [index];
        } else {
            current = candidate;
        }
    });
    if (current.length) rows.push(current);
    return Object.freeze(rows.map(row => Object.freeze(row)));
}

function fixedColumnRows(unitCount, columns) {
    const rows = [];
    for (let index = 0; index < unitCount; index += columns) {
        rows.push(Object.freeze(
            Array.from(
                { length: Math.min(columns, unitCount - index) },
                (_, offset) => index + offset
            )
        ));
    }
    return Object.freeze(rows);
}

function placementSignature(placements) {
    return placements
        .map(placement => `${placement.participantId}:${pixel(placement.x)}:${pixel(placement.y)}`)
        .join("|");
}

function compareScores(first, second) {
    for (let index = 0; index < first.length - 1; index += 1) {
        if (first[index] !== second[index]) return first[index] - second[index];
    }
    return String(first.at(-1)).localeCompare(String(second.at(-1)));
}

function candidateDiagnostic(mode, units, placements, stageWidth, stageHeight, fit, details = {}) {
    const expected = new Set(units.map(unit => unit.participantId));
    const normalized = Array.isArray(placements)
        ? placements.map(placement => Object.freeze({
            participantId: Number(placement?.participantId),
            x: pixel(placement?.x),
            y: pixel(placement?.y)
        }))
        : [];
    const ids = normalized.map(placement => placement.participantId);
    const placementsValid = normalized.length === units.length
        && new Set(ids).size === ids.length
        && ids.every(participantId => expected.has(participantId));
    if (!placementsValid) {
        return Object.freeze({
            mode,
            accepted: false,
            reason: "invalid-placements",
            ...details
        });
    }
    const bounds = proposalBounds(units, normalized);
    const width = pixel(bounds.right - bounds.left);
    const height = pixel(bounds.bottom - bounds.top);
    let overlapFree = true;
    for (let first = 0; first < bounds.boxes.length && overlapFree; first += 1) {
        for (let second = first + 1; second < bounds.boxes.length; second += 1) {
            if (boxesOverlap(bounds.boxes[first], bounds.boxes[second])) {
                overlapFree = false;
                break;
            }
        }
    }
    return Object.freeze({
        mode,
        accepted: Boolean(fit),
        reason: fit
            ? "fit"
            : !overlapFree
                ? "overlap"
                : width > stageWidth + EPSILON && height > stageHeight + EPSILON
                    ? "width-and-height-overflow"
                    : width > stageWidth + EPSILON
                        ? "width-overflow"
                        : height > stageHeight + EPSILON
                            ? "height-overflow"
                            : "translation-or-containment-failure",
        width,
        height,
        fitsWidth: width <= stageWidth + EPSILON,
        fitsHeight: height <= stageHeight + EPSILON,
        overlapFree,
        bounds: Object.freeze({
            left: bounds.left,
            top: bounds.top,
            right: bounds.right,
            bottom: bounds.bottom
        }),
        placementSignature: placementSignature(normalized),
        ...details
    });
}

function resolutionDiagnostics(units, width, height, anchor, gap, candidates, chosenMode) {
    return Object.freeze({
        stage: Object.freeze({ width, height }),
        anchor,
        rowSpacing: gap,
        unitOrder: Object.freeze(units.map(unit => unit.participantId)),
        unitBounds: Object.freeze(units.map(unit => Object.freeze({
            participantId: unit.participantId,
            left: unit.bounds.left,
            top: unit.bounds.top,
            right: unit.bounds.right,
            bottom: unit.bounds.bottom,
            width: unitWidth(unit),
            height: unitHeight(unit)
        }))),
        candidates: Object.freeze(Array.from(candidates)),
        chosenMode
    });
}

/**
 * Resolves one deterministic, stage-fitting layout for ordered complete host
 * units. The selected formation proposal remains authoritative whenever it
 * fits; wrapping is an internal resolution and never a persisted formation.
 */
export function resolveAvatarGroupLayout({
    units = [],
    basePlacements = [],
    stageWidth = 0,
    stageHeight = 0,
    rowSpacing = 0,
    anchor = null,
    allowCanvasExpansion = false
} = {}) {
    const normalizedUnits = Object.freeze(Array.from(units).map(normalizeUnit));
    const width = pixel(stageWidth);
    const height = pixel(stageHeight);
    const gap = Math.max(0, pixel(rowSpacing));
    const normalizedAnchor = Object.freeze({
        x: pixel(anchor?.x),
        y: pixel(anchor?.y)
    });
    const consideredCandidates = [];
    if (!normalizedUnits.length || width <= 0 || height <= 0) {
        return Object.freeze({
            valid: false,
            mode: "no-supported-arrangement",
            rowCount: 0,
            columnCount: 0,
            placements: Object.freeze([]),
            bounds: null,
            translation: Object.freeze({ x: 0, y: 0 }),
            canvasWidth: width,
            canvasHeight: height,
            diagnostics: resolutionDiagnostics(
                normalizedUnits,
                width,
                height,
                normalizedAnchor,
                gap,
                consideredCandidates,
                "no-supported-arrangement"
            )
        });
    }

    const selected = fitProposal(normalizedUnits, basePlacements, width, height);
    consideredCandidates.push(candidateDiagnostic(
        "selected-formation",
        normalizedUnits,
        basePlacements,
        width,
        height,
        selected
    ));
    if (selected) {
        return Object.freeze({
            valid: true,
            mode: "selected-formation",
            rowCount: 1,
            columnCount: normalizedUnits.length,
            canvasWidth: width,
            canvasHeight: height,
            diagnostics: resolutionDiagnostics(
                normalizedUnits,
                width,
                height,
                normalizedAnchor,
                gap,
                consideredCandidates,
                "selected-formation"
            ),
            ...selected
        });
    }

    const wrappedRows = greedyRows(normalizedUnits, width, normalizedAnchor, gap);
    const wrapped = fitProposal(
        normalizedUnits,
        rowProposal(normalizedUnits, wrappedRows, normalizedAnchor, gap),
        width,
        height
    );
    const wrappedPlacements = rowProposal(
        normalizedUnits,
        wrappedRows,
        normalizedAnchor,
        gap
    );
    consideredCandidates.push(candidateDiagnostic(
        "wrapped-row",
        normalizedUnits,
        wrappedPlacements,
        width,
        height,
        wrapped,
        Object.freeze({
            rows: wrappedRows,
            rowCount: wrappedRows.length,
            columnCount: Math.max(...wrappedRows.map(row => row.length))
        })
    ));
    if (wrapped) {
        return Object.freeze({
            valid: true,
            mode: "wrapped-row",
            rowCount: wrappedRows.length,
            columnCount: Math.max(...wrappedRows.map(row => row.length)),
            canvasWidth: width,
            canvasHeight: height,
            diagnostics: resolutionDiagnostics(
                normalizedUnits,
                width,
                height,
                normalizedAnchor,
                gap,
                consideredCandidates,
                "wrapped-row"
            ),
            ...wrapped
        });
    }

    const candidates = [];
    for (let columns = normalizedUnits.length; columns >= 1; columns -= 1) {
        const rows = fixedColumnRows(normalizedUnits.length, columns);
        const candidate = fitProposal(
            normalizedUnits,
            rowProposal(normalizedUnits, rows, normalizedAnchor, gap),
            width,
            height
        );
        const candidatePlacements = rowProposal(normalizedUnits, rows, normalizedAnchor, gap);
        const candidateWidth = candidate
            ? pixel(candidate.bounds.right - candidate.bounds.left)
            : null;
        const candidateHeight = candidate
            ? pixel(candidate.bounds.bottom - candidate.bounds.top)
            : null;
        const score = candidate
            ? Object.freeze([
                rows.length,
                candidate.translationDistance,
                candidateHeight,
                candidateWidth,
                -columns,
                placementSignature(candidate.placements)
            ])
            : null;
        consideredCandidates.push(candidateDiagnostic(
            "grid-fallback",
            normalizedUnits,
            candidatePlacements,
            width,
            height,
            candidate,
            Object.freeze({
                rows,
                rowCount: rows.length,
                columnCount: columns,
                score
            })
        ));
        if (!candidate) continue;
        candidates.push(Object.freeze({
            valid: true,
            mode: "grid-fallback",
            rowCount: rows.length,
            columnCount: columns,
            canvasWidth: width,
            canvasHeight: height,
            score,
            ...candidate
        }));
    }
    candidates.sort((first, second) => compareScores(first.score, second.score));
    if (candidates.length) {
        return Object.freeze({
            ...candidates[0],
            diagnostics: resolutionDiagnostics(
                normalizedUnits,
                width,
                height,
                normalizedAnchor,
                gap,
                consideredCandidates,
                "grid-fallback"
            )
        });
    }

    const wrappedProposal = wrappedPlacements;
    const wrappedBounds = proposalBounds(normalizedUnits, wrappedProposal);
    const requiredWidth = pixel(Math.max(width, wrappedBounds.right - wrappedBounds.left));
    const requiredHeight = pixel(Math.max(height, wrappedBounds.bottom - wrappedBounds.top));
    if (allowCanvasExpansion) {
        const expanded = fitProposal(
            normalizedUnits,
            wrappedProposal,
            requiredWidth,
            requiredHeight
        );
        consideredCandidates.push(candidateDiagnostic(
            "expanded-wrapped-canvas",
            normalizedUnits,
            wrappedProposal,
            requiredWidth,
            requiredHeight,
            expanded,
            Object.freeze({
                rowCount: wrappedRows.length,
                columnCount: Math.max(...wrappedRows.map(row => row.length))
            })
        ));
        if (expanded) {
            return Object.freeze({
                valid: true,
                mode: "expanded-wrapped-canvas",
                rowCount: wrappedRows.length,
                columnCount: Math.max(...wrappedRows.map(row => row.length)),
                canvasWidth: requiredWidth,
                canvasHeight: requiredHeight,
                diagnostics: resolutionDiagnostics(
                    normalizedUnits,
                    width,
                    height,
                    normalizedAnchor,
                    gap,
                    consideredCandidates,
                    "expanded-wrapped-canvas"
                ),
                ...expanded
            });
        }
    }

    return Object.freeze({
        valid: false,
        mode: "no-supported-arrangement",
        rowCount: wrappedRows.length,
        columnCount: Math.max(...wrappedRows.map(row => row.length)),
        placements: wrappedProposal,
        bounds: wrappedBounds,
        translation: Object.freeze({ x: 0, y: 0 }),
        canvasWidth: requiredWidth,
        canvasHeight: requiredHeight,
        diagnostics: resolutionDiagnostics(
            normalizedUnits,
            width,
            height,
            normalizedAnchor,
            gap,
            consideredCandidates,
            "no-supported-arrangement"
        )
    });
}

export const AvatarGroupLayoutPolicy = Object.freeze({
    resolve: resolveAvatarGroupLayout
});

export default AvatarGroupLayoutPolicy;
