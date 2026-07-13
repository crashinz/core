/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * Owner: VoiceRuntime
 * Build: 000043 Part 5
 * Purpose: Own immutable device snapshots, enumeration generations,
 *          permission refresh, selection, sink routing, and devicechange.
 ******************************************************************************/

function freezeDevice(device) {
    return Object.freeze({
        deviceId: String(device?.deviceId || ""),
        label: String(device?.label || ""),
    });
}

function freezeDevices(devices) {
    return Object.freeze(devices.map(freezeDevice));
}

function safeError(error) {
    if (!error) return null;
    return Object.freeze({
        name: String(error.name || "Error").slice(0, 80),
        message: String(error.message || "Device refresh failed").slice(0, 240),
    });
}

function initialSnapshot() {
    return Object.freeze({
        generation: 0,
        completedGeneration: 0,
        reason: "initial",
        permissionState: "unknown",
        inputs: Object.freeze([]),
        outputs: Object.freeze([]),
        selectedInputId: "",
        selectedOutputId: "",
        sinkSelectionSupported: false,
        refreshing: false,
        completed: false,
        error: null,
    });
}

export class VoiceDeviceService {

    #context = null;
    #selectedInputDeviceId = "";
    #selectedOutputDeviceId = "";
    #refreshGeneration = 0;
    #completedGeneration = 0;
    #permissionGeneration = 0;
    #sinkGeneration = 0;
    #permissionState = "unknown";
    #snapshot = initialSnapshot();
    #subscribers = new Set();
    #deviceChangeListener = null;
    #destroyed = false;

    configure(context = {}) {
        if (this.#destroyed) return Object.freeze({ status: "destroyed" });
        this.#unbindDeviceChangeListener();
        this.#context = context;
        this.#bindDeviceChangeListener();
        this.#publish(this.#snapshot);
        return Object.freeze({ status: "configured" });
    }

    destroy() {
        if (this.#destroyed) return Object.freeze({ status: "duplicate" });
        this.#destroyed = true;
        this.#refreshGeneration += 1;
        this.#permissionGeneration += 1;
        this.#sinkGeneration += 1;
        this.#unbindDeviceChangeListener();
        this.#context = null;
        this.#subscribers.clear();
        this.#selectedInputDeviceId = "";
        this.#selectedOutputDeviceId = "";
        return Object.freeze({ status: "completed" });
    }

    get selectedOutputDeviceId() {
        return this.#selectedOutputDeviceId;
    }

    getSnapshot() {
        return this.#snapshot;
    }

    subscribe(listener) {
        if (this.#destroyed || typeof listener !== "function") return () => {};
        this.#subscribers.add(listener);
        listener(this.#snapshot);
        return () => this.#subscribers.delete(listener);
    }

    selectDevices({ inputId = "", outputId = "" } = {}) {
        if (this.#destroyed) return Object.freeze({ status: "destroyed" });
        const input = String(inputId || "");
        const output = String(outputId || "");
        this.#selectedInputDeviceId = this.#isAvailable(this.#snapshot.inputs, input) ? input : "";
        this.#selectedOutputDeviceId = this.#isAvailable(this.#snapshot.outputs, output) ? output : "";
        this.#publishSnapshot({ reason: "selection", error: null });
        return Object.freeze({
            status: "completed",
            selectedInputId: this.#selectedInputDeviceId,
            selectedOutputId: this.#selectedOutputDeviceId,
        });
    }

    captureSelectedOutputDevice() {
        return this.#destroyed ? "" : this.#selectedOutputDeviceId;
    }

    selectedInputConstraints() {
        if (this.#destroyed || !this.#selectedInputDeviceId) return true;
        return { deviceId: { exact: this.#selectedInputDeviceId } };
    }

    async populate(reason = "manual") {
        if (this.#destroyed) return Object.freeze({ status: "destroyed" });
        if (!this.#context?.canPopulateDevices?.()) {
            return Object.freeze({ status: "unavailable" });
        }

        const generation = ++this.#refreshGeneration;
        this.#publishSnapshot({
            generation,
            reason,
            refreshing: true,
            completed: false,
            error: null,
        });

        const mediaDevices = this.#context?.navigator?.mediaDevices;
        if (!mediaDevices?.enumerateDevices) {
            this.#completedGeneration = generation;
            this.#selectedInputDeviceId = "";
            this.#selectedOutputDeviceId = "";
            this.#permissionState = "unavailable";
            this.#publishSnapshot({
                generation,
                completedGeneration: generation,
                reason,
                inputs: Object.freeze([]),
                outputs: Object.freeze([]),
                sinkSelectionSupported: false,
                refreshing: false,
                completed: true,
                error: Object.freeze({
                    name: "NotSupportedError",
                    message: "Selectable audio devices are unavailable.",
                }),
            });
            return Object.freeze({ status: "unsupported", generation });
        }

        try {
            const devices = await mediaDevices.enumerateDevices();
            if (this.#destroyed || generation !== this.#refreshGeneration) {
                this.#recordDiagnostic({
                    event: "device-enumeration-stale-result-skipped",
                    reason,
                    refreshGeneration: generation,
                    activeRefreshGeneration: this.#refreshGeneration,
                });
                return Object.freeze({
                    status: this.#destroyed ? "destroyed" : "stale-generation",
                    generation,
                });
            }

            const inputs = freezeDevices(devices.filter(item => item.kind === "audioinput"));
            const outputs = freezeDevices(devices.filter(item => item.kind === "audiooutput"));
            const labelsAvailable = devices.some(item => Boolean(item.label));
            const sinkSelectionSupported =
                typeof this.#context?.HTMLMediaElement !== "undefined" &&
                "setSinkId" in this.#context.HTMLMediaElement.prototype;

            if (!this.#isAvailable(inputs, this.#selectedInputDeviceId)) {
                this.#selectedInputDeviceId = "";
            }
            if (!this.#isAvailable(outputs, this.#selectedOutputDeviceId)) {
                this.#selectedOutputDeviceId = "";
            }
            if (labelsAvailable && this.#permissionState !== "denied") {
                this.#permissionState = "granted";
            } else if (!labelsAvailable && this.#permissionState === "unknown") {
                this.#permissionState = "prompt";
            }
            this.#completedGeneration = generation;
            this.#publishSnapshot({
                generation,
                completedGeneration: generation,
                reason,
                inputs,
                outputs,
                sinkSelectionSupported,
                refreshing: false,
                completed: true,
                error: null,
            });
            this.#recordDiagnostic({
                event: "device-enumeration",
                reason,
                refreshGeneration: generation,
                selectedInputDeviceId: this.#selectedInputDeviceId || null,
                selectedOutputDeviceId: this.#selectedOutputDeviceId || null,
                audioInputCount: inputs.length,
                audioOutputCount: outputs.length,
                deviceLabelsAvailable: labelsAvailable,
                sinkSelectionSupported,
            });
            return Object.freeze({ status: "completed", generation, snapshot: this.#snapshot });
        } catch (error) {
            if (this.#destroyed || generation !== this.#refreshGeneration) {
                return Object.freeze({
                    status: this.#destroyed ? "destroyed" : "stale-generation",
                    generation,
                });
            }
            this.#completedGeneration = generation;
            this.#publishSnapshot({
                generation,
                completedGeneration: generation,
                reason,
                refreshing: false,
                completed: true,
                error: safeError(error),
            });
            throw error;
        }
    }

    async requestPermissionAndPopulate() {
        if (this.#destroyed) return Object.freeze({ status: "destroyed" });
        const permissionGeneration = ++this.#permissionGeneration;
        this.#refreshGeneration += 1;
        this.#permissionState = "requesting";
        this.#publishSnapshot({
            generation: this.#refreshGeneration,
            reason: "permission-request",
            refreshing: true,
            completed: false,
            error: null,
        });
        const mediaDevices = this.#context?.navigator?.mediaDevices;
        if (!mediaDevices?.getUserMedia) {
            this.#permissionState = "unavailable";
            return this.populate("permission-unavailable");
        }

        let permissionStream = null;
        try {
            if (!this.#context?.hasActiveVoiceStream?.()) {
                permissionStream = await mediaDevices.getUserMedia({ audio: true, video: false });
            }
            if (this.#destroyed || permissionGeneration !== this.#permissionGeneration) {
                return Object.freeze({
                    status: this.#destroyed ? "destroyed" : "stale-generation",
                });
            }
            this.#permissionState = "granted";
            return this.populate("after-explicit-permission");
        } catch (error) {
            if (!this.#destroyed && permissionGeneration === this.#permissionGeneration) {
                this.#permissionState = "denied";
                this.#publishSnapshot({
                    generation: this.#refreshGeneration,
                    reason: "permission-denied",
                    refreshing: false,
                    completed: true,
                    error: safeError(error),
                });
            }
            throw error;
        } finally {
            permissionStream?.getTracks?.().forEach(track => track.stop());
        }
    }

    async applyAudioOutput(audio) {
        if (this.#destroyed) return Object.freeze({ status: "destroyed" });
        if (!audio || typeof audio.setSinkId !== "function") {
            return Object.freeze({ status: "unsupported" });
        }
        const generation = ++this.#sinkGeneration;
        try {
            await audio.setSinkId(this.#selectedOutputDeviceId || "");
            return Object.freeze({
                status: this.#destroyed || generation !== this.#sinkGeneration
                    ? "stale-generation"
                    : "completed",
            });
        } catch (error) {
            this.#warn(error);
            return Object.freeze({ status: "failed" });
        }
    }

    getDiagnostics() {
        return Object.freeze({
            owner: "VoiceRuntime",
            build: "000043-part-5",
            configured: Boolean(this.#context),
            destroyed: this.#destroyed,
            selectedInputDeviceId: this.#selectedInputDeviceId ? "selected" : "default",
            selectedOutputDeviceId: this.#selectedOutputDeviceId ? "selected" : "default",
            refreshGeneration: this.#refreshGeneration,
            completedGeneration: this.#completedGeneration,
            snapshotGeneration: this.#snapshot.generation,
            permissionState: this.#permissionState,
            deviceChangeListening: this.#deviceChangeListener !== null,
            permissionGeneration: this.#permissionGeneration,
            sinkGeneration: this.#sinkGeneration,
        });
    }

    #isAvailable(devices, deviceId) {
        return !deviceId || devices.some(device => device.deviceId === deviceId);
    }

    #publishSnapshot(changes = {}) {
        this.#publish(Object.freeze({
            ...this.#snapshot,
            ...changes,
            permissionState: this.#permissionState,
            selectedInputId: this.#selectedInputDeviceId,
            selectedOutputId: this.#selectedOutputDeviceId,
        }));
    }

    #publish(snapshot) {
        this.#snapshot = snapshot;
        this.#context?.onSnapshot?.(snapshot);
        this.#subscribers.forEach(listener => listener(snapshot));
    }

    #bindDeviceChangeListener() {
        const mediaDevices = this.#context?.navigator?.mediaDevices;
        if (this.#deviceChangeListener || typeof mediaDevices?.addEventListener !== "function") return;
        this.#deviceChangeListener = () => {
            this.#recordDiagnostic({ event: "devicechange" });
            this.populate("devicechange").catch(error => this.#warn(error));
        };
        mediaDevices.addEventListener("devicechange", this.#deviceChangeListener);
    }

    #unbindDeviceChangeListener() {
        const mediaDevices = this.#context?.navigator?.mediaDevices;
        if (this.#deviceChangeListener && typeof mediaDevices?.removeEventListener === "function") {
            mediaDevices.removeEventListener("devicechange", this.#deviceChangeListener);
        }
        this.#deviceChangeListener = null;
    }

    #recordDiagnostic(entry) {
        this.#context?.recordDiagnostic?.(entry);
    }

    #warn(error) {
        this.#context?.warn?.(error);
    }

}

export default VoiceDeviceService;
