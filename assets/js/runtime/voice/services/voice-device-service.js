/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * Owner: VoiceRuntime
 * Build: 000043 Part 3
 * Purpose: Own voice device enumeration, permission refresh, selection, sink
 *          routing, and devicechange listener lifecycle.
 ******************************************************************************/

export class VoiceDeviceService {

    #context = null;

    #selectedOutputDeviceId = "";

    #refreshGeneration = 0;

    #deviceChangeListener = null;

    configure(context = {}) {

        this.#unbindDeviceChangeListener();
        this.#context = context;
        this.#bindDeviceChangeListener();

    }

    destroy() {

        this.#unbindDeviceChangeListener();
        this.#context = null;
        this.#selectedOutputDeviceId = "";
        this.#refreshGeneration = 0;

    }

    get selectedOutputDeviceId() {

        return this.#selectedOutputDeviceId;

    }

    captureSelectedOutputDevice() {

        this.#selectedOutputDeviceId =
            this.#context?.getOutputDeviceId?.() || "";

        return this.#selectedOutputDeviceId;

    }

    selectedInputConstraints() {

        const deviceId =
            this.#context?.getInputDeviceId?.() || "";

        if (!deviceId) return true;

        return {

            deviceId: {

                exact:
                    deviceId

            }

        };

    }

    async populate(reason = "manual") {

        if (!this.#context?.canPopulateDevices?.()) return;

        const refreshGeneration =
            ++this.#refreshGeneration;

        this.#context?.setDeviceStatus?.(
            "Loading audio devices...",
            "working"
        );

        const mediaDevices =
            this.#context?.navigator?.mediaDevices;

        if (!mediaDevices?.enumerateDevices) {

            this.#context?.setInputDeviceOptions?.(
                "<option value=\"\">Default microphone</option>"
            );

            this.#context?.setOutputDeviceOptions?.(
                "<option value=\"\">Default speaker</option>"
            );

            this.#context?.setOutputDeviceDisabled?.(
                true
            );

            this.#context?.setDeviceStatus?.(
                "Your browser does not expose selectable audio devices.",
                "error"
            );

            return;

        }

        const previousInput =
            this.#context?.getInputDeviceId?.() || "";

        const previousOutput =
            this.#context?.getOutputDeviceId?.() ||
            this.#selectedOutputDeviceId;

        const devices =
            await mediaDevices.enumerateDevices();

        if (refreshGeneration !== this.#refreshGeneration) {

            this.#recordDiagnostic({

                event:
                    "device-enumeration-stale-result-skipped",

                reason,

                refreshGeneration,

                activeRefreshGeneration:
                    this.#refreshGeneration

            });

            return;

        }

        const inputs =
            devices.filter(device => device.kind === "audioinput");

        const outputs =
            devices.filter(device => device.kind === "audiooutput");

        this.#recordDiagnostic({

            event:
                "device-enumeration",

            reason,

            selectedInputDeviceId:
                previousInput || null,

            selectedOutputDeviceId:
                previousOutput || null,

            audioInputCount:
                inputs.length,

            audioOutputCount:
                outputs.length,

            renderedInputOptionCount:
                inputs.length + 1,

            renderedOutputOptionCount:
                outputs.length + 1,

            refreshGeneration,

            deviceLabelsAvailable:
                devices.some(device => Boolean(device.label)),

            devices:
                devices.map(device => ({

                    kind:
                        device.kind || null,

                    hasDeviceId:
                        Boolean(device.deviceId),

                    hasLabel:
                        Boolean(device.label),

                    label:
                        device.label || null

                }))

        });

        this.#context?.setInputDeviceOptions?.([

            "<option value=\"\">Default microphone</option>",

            ...inputs.map((device, index) =>
                this.#context?.deviceOption?.(
                    device,
                    `Microphone ${index + 1}`
                ) || ""
            )

        ].join(""));

        this.#context?.setOutputDeviceOptions?.([

            "<option value=\"\">Default speaker</option>",

            ...outputs.map((device, index) =>
                this.#context?.deviceOption?.(
                    device,
                    `Speaker ${index + 1}`
                ) || ""
            )

        ].join(""));

        this.#context?.restoreInputDevice?.(
            previousInput
        );

        this.#context?.restoreOutputDevice?.(
            previousOutput
        );

        const outputUnsupported =
            typeof this.#context?.HTMLMediaElement === "undefined" ||
            !("setSinkId" in this.#context.HTMLMediaElement.prototype);

        this.#context?.setOutputDeviceDisabled?.(
            outputUnsupported
        );

        const labelsAvailable =
            devices.some(device => Boolean(device.label));

        this.#context?.setDevicePermissionRequired?.(
            !labelsAvailable
        );

        this.#context?.setDeviceStatus?.(
            !labelsAvailable ?
                "Microphone permission is required to list named devices." :
                outputUnsupported ?
                    "Speaker selection is not supported by this browser." :
                    "",
            !labelsAvailable || outputUnsupported ? "working" : ""
        );

    }

    async requestPermissionAndPopulate() {

        const mediaDevices =
            this.#context?.navigator?.mediaDevices;

        if (!mediaDevices?.getUserMedia) {

            return this.populate("permission-unavailable");

        }

        let permissionStream = null;

        try {

            if (!this.#context?.hasActiveVoiceStream?.()) {

                permissionStream =
                    await mediaDevices.getUserMedia({

                        audio:
                            true,

                        video:
                            false

                    });

            }

            await this.populate("after-explicit-permission");

        } finally {

            permissionStream?.getTracks?.().forEach(track => track.stop());

        }

    }

    async applyAudioOutput(audio) {

        if (!audio || typeof audio.setSinkId !== "function") return;

        try {

            await audio.setSinkId(
                this.#selectedOutputDeviceId || ""
            );

        } catch (error) {

            this.#warn(error);

        }

    }

    getDiagnostics() {

        return Object.freeze({

            owner:
                "VoiceRuntime",

            build:
                "000043-part-3",

            configured:
                Boolean(this.#context),

            selectedOutputDeviceId:
                this.#selectedOutputDeviceId ? "selected" : "default",

            refreshGeneration:
                this.#refreshGeneration,

            deviceChangeListening:
                this.#deviceChangeListener !== null

        });

    }

    #bindDeviceChangeListener() {

        const mediaDevices =
            this.#context?.navigator?.mediaDevices;

        if (
            this.#deviceChangeListener ||
            typeof mediaDevices?.addEventListener !== "function"
        ) {

            return;

        }

        this.#deviceChangeListener =
            () => {

                this.#recordDiagnostic({

                    event:
                        "devicechange"

                });

                this.populate("devicechange").catch(error =>
                    this.#warn(error)
                );

            };

        mediaDevices.addEventListener(
            "devicechange",
            this.#deviceChangeListener
        );

    }

    #unbindDeviceChangeListener() {

        const mediaDevices =
            this.#context?.navigator?.mediaDevices;

        if (
            this.#deviceChangeListener &&
            typeof mediaDevices?.removeEventListener === "function"
        ) {

            mediaDevices.removeEventListener(
                "devicechange",
                this.#deviceChangeListener
            );

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
