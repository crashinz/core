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
        return Object.freeze({
            width: this.#boundedPreference(
                participant.webcam_display_width_px,
                this.#policy.webcamDisplayMaxWidthPx
            ),
            height: this.#boundedPreference(
                participant.webcam_display_height_px,
                this.#policy.webcamDisplayMaxHeightPx
            )
        });
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
}

export default AvatarDisplayPolicyService;
