import { HorizontalRowFormation } from "../formations/horizontal-row-formation.js";
import { BottomCenterTrioFormation } from "../formations/bottom-center-trio-formation.js";
import { GridFormation } from "../formations/grid-formation.js";

const DEFAULT_FORMATION = "horizontal-row";
const APPROVED_FORMATIONS = Object.freeze([
    DEFAULT_FORMATION,
    "bottom-center-trio",
    "grid"
]);

/**
 * Owns AvatarRuntime's finite first-party formation strategy registry.
 */
export class AvatarFormationService {

    #runtime;
    #registry = new Map();
    #layoutCount = 0;
    #fallbackCount = 0;
    #failureCount = 0;
    #lastResolution = null;

    constructor(runtime) {

        this.#runtime = runtime;

    }

    initialize() {

        this.#register(HorizontalRowFormation);
        this.#register(BottomCenterTrioFormation);
        this.#register(GridFormation);

    }

    destroy() {

        this.#registry.clear();
        this.#lastResolution = null;

    }

    get approvedFormationIds() {

        return APPROVED_FORMATIONS;

    }

    resolve(selectedFormation, { normalMemberCount = 0 } = {}) {

        const selected = APPROVED_FORMATIONS.includes(String(selectedFormation || ""))
            ? String(selectedFormation)
            : DEFAULT_FORMATION;
        const selectedStrategy = this.#registry.get(selected);
        const applicable = Boolean(
            selectedStrategy?.isApplicable?.({ normalMemberCount })
        );
        const effective = applicable ? selected : DEFAULT_FORMATION;
        const fallbackReason = effective === selected
            ? null
            : selectedStrategy
                ? "formation-inapplicable"
                : "formation-unavailable";

        return Object.freeze({ selected, effective, fallbackReason });

    }

    layout({
        selectedFormation = DEFAULT_FORMATION,
        units = [],
        anchor = null,
        rowSpacing = 0
    } = {}) {

        const immutableUnits = Object.freeze(
            Array.from(units).map(unit => Object.freeze({
                participantId: Number(unit.participantId),
                width: Number(unit.width),
                height: Number(unit.height),
                bounds: Object.freeze({
                    left: Number(unit.bounds?.left),
                    top: Number(unit.bounds?.top),
                    right: Number(unit.bounds?.right),
                    bottom: Number(unit.bounds?.bottom)
                })
            }))
        );
        const resolution = this.resolve(selectedFormation, {
            normalMemberCount: immutableUnits.length
        });

        try {
            const proposal = this.#registry.get(resolution.effective)?.layout?.({
                units: immutableUnits,
                anchor: Object.freeze({
                    x: Number(anchor?.x || 0),
                    y: Number(anchor?.y || 0)
                }),
                rowSpacing: Math.max(0, Number(rowSpacing || 0))
            });
            const placements = this.#validateProposal(proposal, immutableUnits);

            this.#layoutCount += 1;
            if (resolution.fallbackReason) this.#fallbackCount += 1;
            this.#lastResolution = Object.freeze({
                ...resolution,
                normalMemberCount: immutableUnits.length
            });

            return Object.freeze({ ...resolution, placements });
        } catch (error) {
            this.#failureCount += 1;
            const fallback = this.#registry.get(DEFAULT_FORMATION).layout({
                units: immutableUnits,
                anchor: Object.freeze({
                    x: Number(anchor?.x || 0),
                    y: Number(anchor?.y || 0)
                }),
                rowSpacing: Math.max(0, Number(rowSpacing || 0))
            });
            const placements = this.#validateProposal(fallback, immutableUnits);

            this.#layoutCount += 1;
            this.#fallbackCount += 1;
            this.#lastResolution = Object.freeze({
                selected: resolution.selected,
                effective: DEFAULT_FORMATION,
                fallbackReason: "formation-contract-failure",
                normalMemberCount: immutableUnits.length,
                error: String(error?.message || error)
            });

            return Object.freeze({
                selected: resolution.selected,
                effective: DEFAULT_FORMATION,
                fallbackReason: "formation-contract-failure",
                placements
            });
        }

    }

    getDiagnostics() {

        return Object.freeze({
            approvedFormationIds: APPROVED_FORMATIONS,
            registeredFormationIds: Object.freeze(Array.from(this.#registry.keys())),
            layoutCount: this.#layoutCount,
            fallbackCount: this.#fallbackCount,
            failureCount: this.#failureCount,
            lastResolution: this.#lastResolution
        });

    }

    #register(strategy) {

        const id = String(strategy?.id || "");
        if (!APPROVED_FORMATIONS.includes(id)
            || typeof strategy?.layout !== "function"
            || typeof strategy?.isApplicable !== "function") {
            throw new TypeError("Invalid avatar formation strategy.");
        }
        this.#registry.set(id, strategy);

    }

    #validateProposal(proposal, units) {

        if (!Array.isArray(proposal) || proposal.length !== units.length) {
            throw new TypeError("Formation returned an incomplete proposal.");
        }

        const expected = new Set(units.map(unit => unit.participantId));
        const seen = new Set();
        const placements = proposal.map(placement => {
            const participantId = Number(placement?.participantId);
            const x = Number(placement?.x);
            const y = Number(placement?.y);
            if (!expected.has(participantId) || seen.has(participantId)
                || !Number.isFinite(x) || !Number.isFinite(y)) {
                throw new TypeError("Formation returned an invalid proposal.");
            }
            seen.add(participantId);
            return Object.freeze({ participantId, x, y });
        });

        return Object.freeze(placements);

    }

}

export default AvatarFormationService;
