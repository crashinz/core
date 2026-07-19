/******************************************************************************
 * ChatSpace Webcam Viewer Policy Service
 *
 * Owns the current viewer's webcam presentation and receive preferences.
 * Installation and account values are server projections; participant
 * overrides are intentionally browser-local and keyed by stable user id.
 ******************************************************************************/

const OVERRIDE_STORAGE_KEY = "chatspace.webcamParticipantOverrides.v1";

function booleanValue(value, fallback) {
    return typeof value === "boolean" ? value : fallback;
}

export class WebcamViewerPolicyService {

    #runtime;

    #storage = null;

    #onChange = null;

    #allowWebcamUse = true;

    #capabilityRevision = 1;

    #showWebcams = true;

    #receiveWebcams = true;

    #preferenceVersion = 1;

    #overrides = new Map();

    #changeCount = 0;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    initialize() {
    }

    configure({ storage = null, onChange = null } = {}) {
        this.#storage = storage;
        this.#onChange = typeof onChange === "function" ? onChange : null;
        this.#loadOverrides();
        return this.snapshot();
    }

    destroy() {
        this.#onChange = null;
        this.#storage = null;
        this.#overrides.clear();
    }

    applyServerProjection({ capability = {}, preferences = {} } = {}, reason = "server-projection") {
        const previous = this.snapshot();
        this.#allowWebcamUse = booleanValue(capability.allowWebcamUse, true);
        this.#capabilityRevision = Math.max(1, Number(capability.revision || 1));
        this.#showWebcams = booleanValue(preferences.showWebcams, true);
        this.#receiveWebcams = booleanValue(preferences.receiveWebcams, true);
        this.#preferenceVersion = Math.max(1, Number(preferences.version || 1));
        const current = this.snapshot();
        if (JSON.stringify(previous) !== JSON.stringify(current)) {
            this.#notify(reason, null, {
                capabilityChanged: previous.capability.allowWebcamUse !== current.capability.allowWebcamUse,
                presentationChanged: previous.preferences.showWebcams !== current.preferences.showWebcams,
                receiveChanged: previous.preferences.receiveWebcams !== current.preferences.receiveWebcams
            });
        }
        return current;
    }

    applyCapability(capability = {}, reason = "installation-capability") {
        return this.applyServerProjection({
            capability,
            preferences: this.preferences()
        }, reason);
    }

    applyPreferences(preferences = {}, reason = "account-preferences") {
        return this.applyServerProjection({
            capability: this.capability(),
            preferences
        }, reason);
    }

    capability() {
        return Object.freeze({
            allowWebcamUse: this.#allowWebcamUse,
            revision: this.#capabilityRevision
        });
    }

    preferences() {
        return Object.freeze({
            showWebcams: this.#showWebcams,
            receiveWebcams: this.#receiveWebcams,
            version: this.#preferenceVersion
        });
    }

    snapshot() {
        return Object.freeze({
            capability: this.capability(),
            preferences: this.preferences(),
            overrideCount: this.#overrides.size
        });
    }

    effectiveFor(participant, { own = false } = {}) {
        const webcamActive = Boolean(participant?.webcam_enabled || participant?.webcam_path);
        if (own) {
            const active = this.#allowWebcamUse && webcamActive;
            return Object.freeze({
                webcamActive,
                show: active,
                receive: active,
                override: null,
                reason: active ? "own-preview" : "webcam-unavailable"
            });
        }
        const userId = Number(participant?.user_id);
        const override = userId > 0 ? this.#overrides.get(userId) || null : null;
        const receivePreference = typeof override?.receive === "boolean"
            ? override.receive
            : this.#receiveWebcams;
        const showPreference = typeof override?.show === "boolean"
            ? override.show
            : this.#showWebcams;
        const receive = Boolean(
            this.#allowWebcamUse &&
            receivePreference &&
            webcamActive
        );
        const show = Boolean(
            receive &&
            showPreference
        );
        let reason = "visible";
        if (!this.#allowWebcamUse) reason = "installation-disabled";
        else if (!webcamActive) reason = "participant-webcam-inactive";
        else if (override?.receive === false) reason = "participant-receive-disabled";
        else if (!receivePreference) reason = "global-receive-disabled";
        else if (override?.show === false) reason = "participant-presentation-hidden";
        else if (!showPreference) reason = "global-presentation-hidden";
        else if (override?.receive === true || override?.show === true) reason = "participant-override-visible";
        return Object.freeze({
            webcamActive,
            show,
            receive,
            override: override ? Object.freeze({ ...override }) : null,
            reason
        });
    }

    overrideFor(userId) {
        const override = this.#overrides.get(Number(userId)) || null;
        return override ? Object.freeze({ ...override }) : null;
    }

    setParticipantPresentation(userId, show) {
        return this.#setOverride(userId, { show: Boolean(show) }, "participant-presentation");
    }

    setParticipantReceive(userId, receive) {
        return this.#setOverride(userId, { receive: Boolean(receive) }, "participant-receive");
    }

    resetParticipantOverrides(reason = "account-reset", { notify = true } = {}) {
        if (!this.#overrides.size) return false;
        this.#overrides.clear();
        this.#persistOverrides();
        if (notify) {
            this.#notify(reason, null, { presentationChanged: true, receiveChanged: true });
        }
        return true;
    }

    getDiagnostics() {
        return Object.freeze({
            owner: "VoiceRuntime",
            service: "WebcamViewerPolicyService",
            allowWebcamUse: this.#allowWebcamUse,
            capabilityRevision: this.#capabilityRevision,
            showWebcams: this.#showWebcams,
            receiveWebcams: this.#receiveWebcams,
            preferenceVersion: this.#preferenceVersion,
            participantOverrideCount: this.#overrides.size,
            changeCount: this.#changeCount,
            storageKey: OVERRIDE_STORAGE_KEY
        });
    }

    #setOverride(userId, changes, reason) {
        const id = Number(userId);
        if (!Number.isInteger(id) || id <= 0) return false;
        const current = this.#overrides.get(id) || {};
        const next = { ...current, ...changes };
        const previousJson = JSON.stringify(current);
        const nextJson = JSON.stringify(next);
        if (previousJson === nextJson) return false;
        if (Object.keys(next).length) this.#overrides.set(id, next);
        else this.#overrides.delete(id);
        this.#persistOverrides();
        this.#notify(reason, id, {
            presentationChanged: Object.hasOwn(changes, "show"),
            receiveChanged: Object.hasOwn(changes, "receive")
        });
        return true;
    }

    #loadOverrides() {
        this.#overrides.clear();
        try {
            const decoded = JSON.parse(this.#storage?.getItem?.(OVERRIDE_STORAGE_KEY) || "{}");
            if (!decoded || typeof decoded !== "object" || Array.isArray(decoded)) return;
            Object.entries(decoded).forEach(([key, value]) => {
                const userId = Number(key);
                if (!Number.isInteger(userId) || userId <= 0 || !value || typeof value !== "object") return;
                const override = {};
                if (typeof value.show === "boolean") override.show = value.show;
                if (typeof value.receive === "boolean") override.receive = value.receive;
                if (Object.keys(override).length) this.#overrides.set(userId, override);
            });
        } catch {
            this.#overrides.clear();
        }
    }

    #persistOverrides() {
        if (!this.#storage?.setItem) return;
        const payload = {};
        this.#overrides.forEach((value, userId) => { payload[userId] = value; });
        try {
            if (Object.keys(payload).length) {
                this.#storage.setItem(OVERRIDE_STORAGE_KEY, JSON.stringify(payload));
            } else {
                this.#storage.removeItem?.(OVERRIDE_STORAGE_KEY);
            }
        } catch {
            // Browser-local persistence is best effort; the active policy remains valid.
        }
    }

    #notify(reason, userId = null, changes = {}) {
        this.#changeCount += 1;
        this.#onChange?.(Object.freeze({
            reason,
            userId,
            changes: Object.freeze({ ...changes }),
            snapshot: this.snapshot()
        }));
    }
}

export default WebcamViewerPolicyService;
