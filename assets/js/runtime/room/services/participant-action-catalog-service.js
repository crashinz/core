/******************************************************************************
 * ChatSpace Shared Participant-Action Catalog
 *
 * Owns stable public participant-action definitions and state-aware labels.
 * Action execution remains with the authoritative avatar, voice, and block
 * owners supplied by the composition root.
 ******************************************************************************/

export class ParticipantActionCatalogService {

    #runtime;

    #context = null;

    #resolutionCount = 0;

    #duplicateCount = 0;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    initialize() {
    }

    configure(context = {}) {
        this.#context = context;
    }

    destroy() {
        this.#context = null;
    }

    actionsFor(participant) {
        const viewer = this.#context?.getViewer?.() || null;
        const own = Number(viewer?.id || 0) === Number(participant?.id || 0);
        if (!participant || own) return Object.freeze([]);
        const visibility = this.#context?.getAvatarVisibility?.(participant) || {};
        const webcam = this.#context?.getWebcamPolicy?.(participant) || {};
        const blocked = Boolean(this.#context?.isBlocked?.(participant?.user_id));
        const webcamAllowed = this.#context?.webcamAllowed?.() !== false;
        const actions = [
            {
                id: "avatar.current-visibility",
                label: visibility.exact ? "Show this avatar" : "Hide this avatar until it changes",
                active: Boolean(visibility.exact),
                disabled: false,
                applicable: true
            },
            {
                id: "avatar.user-visibility",
                label: visibility.user ? "Show avatars from this user" : "Hide avatars from this user",
                active: Boolean(visibility.user),
                disabled: false,
                applicable: true
            },
            {
                id: "user.block",
                label: blocked ? "Unblock" : "Block",
                active: blocked,
                disabled: false,
                applicable: true,
                danger: !blocked
            },
            {
                id: "webcam.presentation",
                label: webcam.show === false ? "Show this webcam for me" : "Hide this webcam for me",
                active: webcam.show === false,
                disabled: !webcamAllowed || !webcam.webcamActive,
                applicable: true
            },
            {
                id: "webcam.receive",
                label: webcam.receive === false ? "Resume receiving this webcam" : "Stop receiving this webcam",
                active: webcam.receive === false,
                disabled: !webcamAllowed || !webcam.webcamActive,
                applicable: true
            }
        ];
        const unique = new Map();
        actions.forEach(action => {
            if (unique.has(action.id)) this.#duplicateCount += 1;
            unique.set(action.id, Object.freeze(action));
        });
        this.#resolutionCount += 1;
        return Object.freeze([...unique.values()]);
    }

    getDiagnostics() {
        return Object.freeze({
            owner: "RoomRuntime",
            service: "ParticipantActionCatalogService",
            actionDefinitionCount: 5,
            resolutionCount: this.#resolutionCount,
            duplicateCount: this.#duplicateCount
        });
    }
}

export default ParticipantActionCatalogService;
