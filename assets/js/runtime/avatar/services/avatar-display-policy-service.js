/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * Build 000044 Part 8
 * Owner: Avatar Runtime
 * Purpose: Consume server-authoritative avatar and webcam display policy.
 ******************************************************************************/

const FALLBACK_POLICY = Object.freeze({
    revision: 1,
    avatarDisplayMaxPx: 200,
    webcamDisplayMaxWidthPx: 200,
    webcamDisplayMaxHeightPx: 200,
    avatarUploadMaxWidthPx: 250,
    avatarUploadMaxHeightPx: 250
});

const MIN_DISPLAY_SIZE = 42;

const WEBCAM_DISPLAY_PRESETS = Object.freeze({
    small: Object.freeze({ width: 120, height: 120 }),
    medium: Object.freeze({ width: 160, height: 160 }),
    large: Object.freeze({ width: 200, height: 200 })
});

export class AvatarDisplayPolicyService {

    #runtime;
    #policy = FALLBACK_POLICY;
    #configurationCount = 0;
    #stalePolicyCount = 0;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    initialize() {
        this.#policy = FALLBACK_POLICY;
        this.#configurationCount = 0;
        this.#stalePolicyCount = 0;
    }

    destroy() {
        this.initialize();
    }

    configure(policy = {}) {
        const normalized = this.#normalizePolicy(policy);
        if (normalized.revision < this.#policy.revision) {
            this.#stalePolicyCount += 1;
            return false;
        }
        const changed = Object.keys(FALLBACK_POLICY).some(
            key => Number(normalized[key]) !== Number(this.#policy[key])
        );
        this.#policy = Object.freeze(normalized);
        if (changed) this.#configurationCount += 1;
        return changed;
    }

    policy() {
        return this.#policy;
    }

    effectiveAvatarMaxEdge(participant = {}) {
        return this.#boundedPreference(
            participant.avatar_display_size_px,
            this.#policy.avatarDisplayMaxPx
        );
    }

    effectiveWebcamBox(participant = {}) {
        const width = this.#preferenceOrCap(
            participant.webcam_display_width_px,
            this.#policy.webcamDisplayMaxWidthPx
        );
        const height = this.#preferenceOrCap(
            participant.webcam_display_height_px,
            this.#policy.webcamDisplayMaxHeightPx
        );
        return this.#fitWithinWebcamMaximum(width, height);
    }

    minimumDisplaySize() {
        return MIN_DISPLAY_SIZE;
    }

    webcamDisplayPresets() {
        return WEBCAM_DISPLAY_PRESETS;
    }

    webcamPreferenceChoice(participant = {}) {
        const width = Number.parseInt(participant.webcam_display_width_px, 10);
        const height = Number.parseInt(participant.webcam_display_height_px, 10);
        if (!Number.isFinite(width) || !Number.isFinite(height)) return "match";
        const preset = Object.entries(WEBCAM_DISPLAY_PRESETS).find(([, dimensions]) => (
            width === dimensions.width && height === dimensions.height
        ));
        return preset?.[0] || "custom";
    }

    resolveWebcamDisplayChoice(choice, options = {}) {
        const normalizedChoice = ["match", "small", "medium", "large", "custom"]
            .includes(choice) ? choice : "custom";
        let source;
        if (normalizedChoice === "match") {
            source = options.avatarDimensions || {};
        } else if (WEBCAM_DISPLAY_PRESETS[normalizedChoice]) {
            source = WEBCAM_DISPLAY_PRESETS[normalizedChoice];
        } else {
            source = {
                width: options.width,
                height: options.height
            };
        }
        return this.resolveWebcamDisplaySize(source.width, source.height, {
            choice: normalizedChoice
        });
    }

    resolveWebcamDisplaySize(widthValue, heightValue, options = {}) {
        const width = Number(widthValue);
        const height = Number(heightValue);
        if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
            return Object.freeze({
                ok: false,
                code: "WEBCAM_DISPLAY_SIZE_INVALID",
                error: "Enter a valid webcam width and height."
            });
        }

        const maximumWidth = this.#policy.webcamDisplayMaxWidthPx;
        const maximumHeight = this.#policy.webcamDisplayMaxHeightPx;
        const minimumScale = Math.max(
            1,
            MIN_DISPLAY_SIZE / width,
            MIN_DISPLAY_SIZE / height
        );
        const maximumScale = Math.min(
            1,
            maximumWidth / width,
            maximumHeight / height
        );
        if (minimumScale > maximumScale && minimumScale > 1 && maximumScale < 1) {
            return Object.freeze({
                ok: false,
                code: "WEBCAM_DISPLAY_ASPECT_RATIO_UNAVAILABLE",
                error: `That aspect ratio cannot fit between ${MIN_DISPLAY_SIZE} px and the ${maximumWidth} x ${maximumHeight} px community maximum.`
            });
        }

        const scale = minimumScale > 1 ? minimumScale : maximumScale;
        const widthResolved = Math.max(1, Math.round(width * scale));
        const heightResolved = Math.max(1, Math.round(height * scale));
        if (
            widthResolved < MIN_DISPLAY_SIZE ||
            heightResolved < MIN_DISPLAY_SIZE ||
            widthResolved > maximumWidth ||
            heightResolved > maximumHeight
        ) {
            return Object.freeze({
                ok: false,
                code: "WEBCAM_DISPLAY_ASPECT_RATIO_UNAVAILABLE",
                error: `That aspect ratio cannot fit between ${MIN_DISPLAY_SIZE} px and the ${maximumWidth} x ${maximumHeight} px community maximum.`
            });
        }

        const resolution = {
            ok: true,
            choice: options.choice || "custom",
            sourceWidth: Math.round(width),
            sourceHeight: Math.round(height),
            width: widthResolved,
            height: heightResolved,
            adjusted: widthResolved !== Math.round(width) || heightResolved !== Math.round(height)
        };
        if (resolution.choice === "custom" && resolution.adjusted) {
            return Object.freeze({
                ok: false,
                code: "WEBCAM_DISPLAY_SIZE_OUT_OF_RANGE",
                error: `Custom width and height must each be from ${MIN_DISPLAY_SIZE} px through the ${maximumWidth} x ${maximumHeight} px community maximum.`
            });
        }
        return Object.freeze(resolution);
    }

    renderedConstraints(participant = {}, options = {}) {
        const baseSize = Math.max(1, Number(options.baseSize || 150));
        const lapInitiator = Boolean(options.lapInitiator);
        const webcam = Boolean(options.webcam);
        const avatarMaxEdge = this.effectiveAvatarMaxEdge(participant);
        const lapMaxEdge = Math.max(
            1,
            Math.round(Math.min(baseSize, avatarMaxEdge) * 0.5)
        );

        if (webcam) {
            const box = this.effectiveWebcamBox(participant);
            if (!lapInitiator) {
                return Object.freeze({
                    kind: "webcam",
                    width: box.width,
                    height: box.height
                });
            }
            const scale = Math.min(1, lapMaxEdge / Math.max(box.width, box.height, 1));
            return Object.freeze({
                kind: "webcam",
                width: Math.max(1, Math.round(box.width * scale)),
                height: Math.max(1, Math.round(box.height * scale))
            });
        }

        return Object.freeze({
            kind: "avatar",
            maxEdge: lapInitiator
                ? Math.min(avatarMaxEdge, lapMaxEdge)
                : avatarMaxEdge
        });
    }

    webcamSizeMatchCandidates(relationship = null, participants = [], currentParticipantId = 0) {
        if (!relationship || relationship.status !== "active") return Object.freeze([]);
        const participantMap = new Map(
            Array.from(participants || []).map(participant => [Number(participant.id), participant])
        );
        const candidates = Array.from(relationship.members || [])
            .filter(member => (
                member.status === "active" &&
                member.relationshipRole === "normal" &&
                Number(member.participantId) !== Number(currentParticipantId)
            ))
            .map(member => participantMap.get(Number(member.participantId)))
            .filter(Boolean)
            .map(participant => Object.freeze({
                participantId: Number(participant.id),
                displayName: String(participant.display_name || "Participant"),
                ...this.effectiveWebcamBox(participant)
            }));
        return Object.freeze(candidates);
    }

    getDiagnostics() {
        return Object.freeze({
            owner: "AvatarDisplayPolicyService",
            build: "000044 Part 8",
            policyRevision: this.#policy.revision,
            configurationCount: this.#configurationCount,
            stalePolicyCount: this.#stalePolicyCount
        });
    }

    #normalizePolicy(policy) {
        const revision = Math.max(1, Number.parseInt(policy.revision, 10) || 1);
        return {
            revision,
            avatarDisplayMaxPx: this.#positiveInt(
                policy.avatarDisplayMaxPx,
                FALLBACK_POLICY.avatarDisplayMaxPx
            ),
            webcamDisplayMaxWidthPx: this.#positiveInt(
                policy.webcamDisplayMaxWidthPx,
                FALLBACK_POLICY.webcamDisplayMaxWidthPx
            ),
            webcamDisplayMaxHeightPx: this.#positiveInt(
                policy.webcamDisplayMaxHeightPx,
                FALLBACK_POLICY.webcamDisplayMaxHeightPx
            ),
            avatarUploadMaxWidthPx: this.#positiveInt(
                policy.avatarUploadMaxWidthPx,
                FALLBACK_POLICY.avatarUploadMaxWidthPx
            ),
            avatarUploadMaxHeightPx: this.#positiveInt(
                policy.avatarUploadMaxHeightPx,
                FALLBACK_POLICY.avatarUploadMaxHeightPx
            )
        };
    }

    #positiveInt(value, fallback) {
        const parsed = Number.parseInt(value, 10);
        return Number.isFinite(parsed) && parsed >= MIN_DISPLAY_SIZE ? parsed : fallback;
    }

    #boundedPreference(value, cap) {
        const parsed = Number.parseInt(value, 10);
        if (!Number.isFinite(parsed) || parsed < MIN_DISPLAY_SIZE) return cap;
        return Math.min(cap, parsed);
    }

    #preferenceOrCap(value, cap) {
        const parsed = Number.parseInt(value, 10);
        return Number.isFinite(parsed) && parsed >= MIN_DISPLAY_SIZE ? parsed : cap;
    }

    #fitWithinWebcamMaximum(width, height) {
        const scale = Math.min(
            1,
            this.#policy.webcamDisplayMaxWidthPx / Math.max(width, 1),
            this.#policy.webcamDisplayMaxHeightPx / Math.max(height, 1)
        );
        return Object.freeze({
            width: Math.max(1, Math.round(width * scale)),
            height: Math.max(1, Math.round(height * scale))
        });
    }
}

export default AvatarDisplayPolicyService;
