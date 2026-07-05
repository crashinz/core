/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      voice-media-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Voice Runtime
 *
 * Purpose:
 *      Owns voice state, device coordination, WebRTC peer coordination, media
 *      signaling, speaking detection, and voice polling.
 *
 * Build:
 *      000027
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000027
 * - Introduced VoiceMediaService.
 * - Transferred voice/WebRTC/media signaling ownership from room.js.
 ******************************************************************************/

/**
 * @file voice-media-service.js
 *
 * Defines the Voice Media Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Constants
//--------------------------------------------------

const DEFAULT_ICE_SERVERS = Object.freeze([

    Object.freeze({

        urls:
            "stun:stun.l.google.com:19302"

    })

]);

//--------------------------------------------------
// Voice Media Service
//--------------------------------------------------

/**
 * Owns voice media state and WebRTC signaling coordination.
 */
export class VoiceMediaService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #context = null;

    #voiceStream = null;

    #joined = false;

    #pollTimer = null;

    #muted = false;

    #deafened = false;

    #speaking = false;

    #selectedOutputDeviceId = "";

    #analyserTimer = null;

    #audioContext = null;

    #analyser = null;

    #micSource = null;

    #voiceParticipants = [];

    #lastStatusSignature = "";

    #lastSignalId = 0;

    #peers = new Map();

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Voice Media Service.
     *
     * @param {VoiceRuntime} runtime
     *        Owning Voice Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Initializes the service.
     */
    initialize() {

    }

    /**
     * Releases runtime-owned voice media resources.
     */
    destroy() {

        this.stopPolling();
        this.#stopAnalyser();
        this.#stopVoiceStream();
        this.#removeAllAudioElements();
        this.#closeAllPeers();
        this.#context = null;
        this.#voiceParticipants = [];
        this.#lastStatusSignature = "";
        this.#lastSignalId = 0;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Voice Runtime.
     *
     * @returns {VoiceRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    /**
     * Whether the current participant is joined to voice.
     *
     * @returns {boolean}
     */
    isJoined() {

        return this.#joined;

    }

    /**
     * Whether the local microphone is muted.
     *
     * @returns {boolean}
     */
    isMuted() {

        return this.#muted;

    }

    /**
     * Whether remote voice audio is deafened.
     *
     * @returns {boolean}
     */
    isDeafened() {

        return this.#deafened;

    }

    /**
     * Whether local speaking detection is currently active.
     *
     * @returns {boolean}
     */
    isSpeaking() {

        return this.#speaking;

    }

    /**
     * Returns current voice state for presentation callbacks.
     *
     * @returns {Object}
     */
    getState() {

        return Object.freeze({

            joined:
                this.#joined,

            muted:
                this.#muted,

            deafened:
                this.#deafened,

            speaking:
                this.#speaking,

            selectedOutputDeviceId:
                this.#selectedOutputDeviceId

        });

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures host callbacks and browser dependencies.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Voice Workflow
    //--------------------------------------------------

    /**
     * Populates selectable voice input/output devices through host callbacks.
     *
     * @returns {Promise<void>}
     */
    async populateDevices() {

        if (!this.#context?.canPopulateDevices?.()) return;

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

        const inputs =
            devices.filter(device => device.kind === "audioinput");

        const outputs =
            devices.filter(device => device.kind === "audiooutput");

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

        this.#context?.setDeviceStatus?.(
            outputUnsupported ? "Speaker selection is not supported by this browser." : "",
            outputUnsupported ? "working" : ""
        );

    }

    /**
     * Joins room voice.
     *
     * @returns {Promise<void>}
     */
    async join() {

        if (this.#joined) return;

        try {

            this.#selectedOutputDeviceId =
                this.#context?.getOutputDeviceId?.() || "";

            const mediaDevices =
                this.#context?.navigator?.mediaDevices;

            this.#voiceStream =
                await mediaDevices.getUserMedia({

                    audio:
                        this.#selectedAudioConstraints(),

                    video:
                        false

                });

            this.#muted = false;
            this.#deafened = false;
            this.#speaking = false;
            this.#joined = true;

            this.#context?.updateToggleButton?.();

            await this.#context?.apiPost?.(
                "/api/media_signal.php",
                {
                    action: "join",
                    media: "voice",
                    session_id: this.#config()?.sessionId,
                    participant_id: this.#config()?.myParticipantId,
                    join_token: this.#config()?.myJoinToken
                }
            );

            await this.syncStatus(true);
            this.#startAnalyser();
            this.#forEachAudioElement(audio => this.applyAudioOutput(audio));
            this.connectMediaPeers();
            this.startPolling(0);
            this.#context?.closeDeviceModal?.();
            this.populateDevices().catch(() => {});

        } catch (error) {

            this.#joined = false;
            this.#context?.updateToggleButton?.();
            this.#stopVoiceStream();
            this.#context?.setDeviceStatus?.(
                error.message || "Could not join voice chat.",
                "error"
            );

        }

    }

    /**
     * Leaves room voice.
     *
     * @returns {Promise<void>}
     */
    async leave() {

        if (!this.#joined) return;

        this.#joined = false;
        this.#muted = false;
        this.#deafened = false;
        this.#speaking = false;
        this.#lastStatusSignature = "";
        this.#stopAnalyser();
        this.#context?.updateToggleButton?.();
        this.#removeAllAudioElements();
        this.#stopVoiceStream();

        if (this.#webcamStream()) {

            this.renegotiateMediaPeers();

        } else {

            this.#closeAllPeers();

        }

        await this.#context?.apiPost?.(
            "/api/media_signal.php",
            {
                action: "leave",
                media: "voice",
                session_id: this.#config()?.sessionId,
                participant_id: this.#config()?.myParticipantId,
                join_token: this.#config()?.myJoinToken
            }
        ).catch(() => {});

        this.#voiceParticipants =
            this.#voiceParticipants.filter(participant =>
                Number(participant.id) !== Number(this.#config()?.myParticipantId)
            );

        this.#renderVoiceList(
            this.#voiceParticipants
        );

        this.startPolling(0);

    }

    /**
     * Updates local muted state.
     *
     * @param {boolean} muted
     */
    setMuted(muted) {

        this.#muted =
            Boolean(muted);

        if (this.#voiceStream) {

            this.#voiceStream.getAudioTracks().forEach(track => {

                track.enabled =
                    !this.#muted;

            });

        }

        if (this.#muted && this.#speaking) {

            this.#speaking = false;

        }

        this.renderCurrentVoiceList();
        this.syncStatus(true);

    }

    /**
     * Updates local deafened state.
     *
     * @param {boolean} deafened
     */
    setDeafened(deafened) {

        this.#deafened =
            Boolean(deafened);

        this.#setRemoteAudioDeafened();
        this.renderCurrentVoiceList();
        this.syncStatus(true);

    }

    /**
     * Synchronizes voice status with the server.
     *
     * @param {boolean} force
     *
     * @returns {Promise<void>}
     */
    syncStatus(force = false) {

        if (!this.#joined) return Promise.resolve();

        const signature =
            `${this.#muted ? 1 : 0}:${this.#deafened ? 1 : 0}:${this.#speaking ? 1 : 0}`;

        if (!force && signature === this.#lastStatusSignature) {

            return Promise.resolve();

        }

        this.#lastStatusSignature =
            signature;

        return this.#context?.apiPost?.(
            "/api/media_signal.php",
            {
                action: "status",
                media: "voice",
                session_id: this.#config()?.sessionId,
                participant_id: this.#config()?.myParticipantId,
                join_token: this.#config()?.myJoinToken,
                muted: this.#muted,
                deafened: this.#deafened,
                speaking: this.#speaking
            }
        ).catch(() => {}) ?? Promise.resolve();

    }

    /**
     * Renders the current voice participant list.
     */
    renderCurrentVoiceList() {

        this.#renderVoiceList(
            this.#voiceParticipants
        );

    }

    //--------------------------------------------------
    // Public Media / Peer Workflow
    //--------------------------------------------------

    /**
     * Starts or restarts media signaling polling.
     *
     * @param {number} delay
     */
    startPolling(delay = 0) {

        this.stopPolling();

        const setTimer =
            this.#context?.setTimeout || setTimeout;

        this.#pollTimer =
            setTimer(
                () => this.poll(),
                delay
            );

    }

    /**
     * Stops media signaling polling.
     */
    stopPolling() {

        if (this.#pollTimer === null) return;

        const clearTimer =
            this.#context?.clearTimeout || clearTimeout;

        clearTimer(
            this.#pollTimer
        );

        this.#pollTimer = null;

    }

    /**
     * Whether any local media track is active.
     *
     * @returns {boolean}
     */
    mediaActive() {

        return Boolean(
            this.#joined ||
            this.#webcamStream()
        );

    }

    /**
     * Whether media polling should use the fast interval.
     *
     * @returns {boolean}
     */
    shouldPollFast() {

        return Boolean(
            this.mediaActive() ||
            [...this.#participants().values()].some(person =>
                Boolean(person.webcam_enabled || person.webcam_path)
            )
        );

    }

    /**
     * Applies selected audio output to an element.
     *
     * @param {HTMLAudioElement} audio
     *
     * @returns {Promise<void>}
     */
    async applyAudioOutput(audio) {

        if (!audio || typeof audio.setSinkId !== "function") return;

        try {

            await audio.setSinkId(
                this.#selectedOutputDeviceId || ""
            );

        } catch (error) {

            this.#warn(
                error
            );

        }

    }

    /**
     * Connects one remote participant media peer.
     *
     * @param {number|string} participantId
     *
     * @returns {Promise<void>}
     */
    async connectMediaPeer(participantId) {

        const id =
            Number(participantId);

        if (
            !id ||
            id === Number(this.#config()?.myParticipantId) ||
            !this.mediaActive()
        ) {

            return;

        }

        const pc =
            await this.#getPeer(
                id,
                false
            );

        await this.#makePeerOffer(
            id,
            pc
        );

    }

    /**
     * Connects media peers for all known participants.
     */
    connectMediaPeers() {

        if (!this.mediaActive()) return;

        this.#participants().forEach(person => {

            if (Number(person.id) !== Number(this.#config()?.myParticipantId)) {

                this.connectMediaPeer(
                    person.id
                );

            }

        });

    }

    /**
     * Renegotiates existing media peers.
     */
    renegotiateMediaPeers() {

        this.#peers.forEach((pc, id) => {

            this.#syncPeerLocalTracks(
                pc
            );

            this.#makePeerOffer(
                id,
                pc
            );

        });

    }

    /**
     * Closes one media peer.
     *
     * @param {number|string} participantId
     */
    closePeer(participantId) {

        const id =
            Number(participantId);

        if (!this.#peers.has(id)) return;

        this.#peers.get(id).close();
        this.#peers.delete(id);

    }

    /**
     * Polls media signaling and voice participant state once.
     *
     * @returns {Promise<void>}
     */
    async poll() {

        try {

            const qs =
                new URLSearchParams({

                    media:
                        "all",

                    session_id:
                        this.#config()?.sessionId,

                    participant_id:
                        this.#config()?.myParticipantId,

                    after:
                        this.#lastSignalId,

                    join_token:
                        this.#config()?.myJoinToken

                });

            const data =
                await this.#context?.fetchMediaSignals?.(qs);

            this.#renderVoiceList(
                data?.voice_participants || []
            );

            for (const signal of data?.signals || []) {

                await this.#handleSignal(
                    signal
                );

            }

        } catch (error) {

            this.#warn(
                error
            );

        }

        this.startPolling(
            this.shouldPollFast() ? 800 : 2000
        );

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns voice media diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "VoiceRuntime",

            build:
                "000027",

            configured:
                Boolean(this.#context),

            joined:
                this.#joined,

            muted:
                this.#muted,

            deafened:
                this.#deafened,

            speaking:
                this.#speaking,

            voiceParticipantCount:
                this.#voiceParticipants.length,

            peerCount:
                this.#peers.size,

            lastSignalId:
                this.#lastSignalId,

            polling:
                this.#pollTimer !== null

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #config() {

        return this.#context?.getConfig?.() || {};

    }

    #participants() {

        return this.#context?.getParticipants?.() || new Map();

    }

    #webcamStream() {

        return this.#context?.getWebcamStream?.() || null;

    }

    #selectedAudioConstraints() {

        const deviceId =
            this.#context?.getInputDeviceId?.() || "";

        if (!deviceId) return true;

        return {

            deviceId:
                {
                    exact:
                        deviceId
                }

        };

    }

    #renderVoiceList(list) {

        this.#voiceParticipants =
            Array.isArray(list) ? list : [];

        this.#context?.renderVoiceList?.(
            this.#voiceParticipants,
            this.getState()
        );

    }

    #setRemoteAudioDeafened() {

        this.#forEachAudioElement(audio => {

            audio.muted =
                this.#deafened;

        });

    }

    #forEachAudioElement(callback) {

        const elements =
            this.#context?.getAudioElements?.() || [];

        elements.forEach(
            callback
        );

    }

    #removeAllAudioElements() {

        this.#context?.removeAllAudioElements?.();

    }

    #stopVoiceStream() {

        if (!this.#voiceStream) return;

        this.#voiceStream.getTracks().forEach(track => {

            track.stop();

        });

        this.#voiceStream = null;

    }

    #closeAllPeers() {

        for (const pc of this.#peers.values()) {

            pc.close();

        }

        this.#peers.clear();

    }

    #warn(error) {

        this.#context?.warn?.(
            error
        );

    }

    #stopAnalyser() {

        if (this.#analyserTimer !== null) {

            const clearIntervalFn =
                this.#context?.clearInterval || clearInterval;

            clearIntervalFn(
                this.#analyserTimer
            );

        }

        this.#analyserTimer = null;

        try {

            this.#micSource?.disconnect();

        } catch {

        }

        this.#micSource = null;
        this.#analyser = null;

        if (this.#audioContext) {

            this.#audioContext.close().catch(() => {});
            this.#audioContext = null;

        }

    }

    #startAnalyser() {

        this.#stopAnalyser();

        if (!this.#voiceStream) return;

        try {

            const AudioContextClass =
                this.#context?.window?.AudioContext ||
                this.#context?.window?.webkitAudioContext;

            if (!AudioContextClass) return;

            this.#audioContext =
                new AudioContextClass();

            this.#analyser =
                this.#audioContext.createAnalyser();

            this.#analyser.fftSize =
                512;

            this.#micSource =
                this.#audioContext.createMediaStreamSource(
                    this.#voiceStream
                );

            this.#micSource.connect(
                this.#analyser
            );

            const samples =
                new Uint8Array(
                    this.#analyser.fftSize
                );

            const setIntervalFn =
                this.#context?.setInterval || setInterval;

            this.#analyserTimer =
                setIntervalFn(
                    () => this.#detectSpeaking(samples),
                    140
                );

        } catch {

        }

    }

    #detectSpeaking(samples) {

        if (!this.#analyser) return;

        this.#analyser.getByteTimeDomainData(
            samples
        );

        let sum =
            0;

        for (let index = 0; index < samples.length; index += 1) {

            const value =
                (samples[index] - 128) / 128;

            sum +=
                value * value;

        }

        const rms =
            Math.sqrt(sum / samples.length);

        const speaking =
            Boolean(!this.#muted && rms > 0.045);

        if (speaking === this.#speaking) return;

        this.#speaking =
            speaking;

        this.renderCurrentVoiceList();
        this.syncStatus();

    }

    #localMediaTracks() {

        const webcamStream =
            this.#webcamStream();

        return [

            ...(this.#voiceStream ? this.#voiceStream.getAudioTracks() : []),

            ...(webcamStream ? webcamStream.getVideoTracks() : [])

        ].filter(track => track.readyState !== "ended");

    }

    #syncPeerLocalTracks(pc) {

        if (!pc) return;

        const desired =
            this.#localMediaTracks();

        for (const track of desired) {

            const sender =
                pc.getSenders().find(item =>
                    item.track?.kind === track.kind
                );

            if (sender) {

                if (sender.track !== track) {

                    sender.replaceTrack(track).catch(() => {});

                }

            } else {

                const stream =
                    track.kind === "video" ?
                        this.#webcamStream() :
                        this.#voiceStream;

                if (stream) {

                    pc.addTrack(
                        track,
                        stream
                    );

                }

            }

        }

        pc.getSenders().forEach(sender => {

            if (!sender.track) return;

            if (!desired.some(track => track.kind === sender.track.kind)) {

                pc.removeTrack(
                    sender
                );

            }

        });

    }

    async #makePeerOffer(participantId, pc = this.#peers.get(Number(participantId))) {

        if (!pc || pc.__makingOffer || pc.signalingState !== "stable") return;

        pc.__makingOffer =
            true;

        try {

            this.#syncPeerLocalTracks(
                pc
            );

            const offer =
                await pc.createOffer();

            await pc.setLocalDescription(
                offer
            );

            await this.#sendSignal(
                participantId,
                "offer",
                pc.localDescription
            );

        } catch (error) {

            this.#warn(
                error
            );

        } finally {

            pc.__makingOffer =
                false;

        }

    }

    async #getPeer(id, polite = false) {

        if (this.#peers.has(id)) {

            const existing =
                this.#peers.get(id);

            if (polite) {

                existing.__polite =
                    true;

            }

            this.#syncPeerLocalTracks(
                existing
            );

            return existing;

        }

        const RTCPeerConnectionClass =
            this.#context?.window?.RTCPeerConnection ||
            globalThis.RTCPeerConnection;

        const pc =
            new RTCPeerConnectionClass({

                iceServers:
                    DEFAULT_ICE_SERVERS

            });

        pc.__polite =
            polite;

        this.#peers.set(
            id,
            pc
        );

        this.#syncPeerLocalTracks(
            pc
        );

        pc.ontrack =
            event => this.#handlePeerTrack(id, event);

        pc.onicecandidate =
            event => {

                if (event.candidate) {

                    this.#sendSignal(
                        id,
                        "ice",
                        event.candidate
                    );

                }

            };

        pc.onnegotiationneeded =
            async () => {

                if (polite) return;

                await this.#makePeerOffer(
                    id,
                    pc
                );

            };

        return pc;

    }

    #handlePeerTrack(id, event) {

        const track =
            event.track;

        if (track.kind === "video") {

            const MediaStreamClass =
                this.#context?.window?.MediaStream ||
                globalThis.MediaStream;

            const stream =
                event.streams?.[0] ||
                new MediaStreamClass([track]);

            this.#context?.attachParticipantVideo?.(
                id,
                stream
            );

            track.addEventListener(
                "ended",
                () => this.#context?.detachParticipantVideo?.(id)
            );

            return;

        }

        if (track.kind !== "audio" || !this.#joined) return;

        const MediaStreamClass =
            this.#context?.window?.MediaStream ||
            globalThis.MediaStream;

        const audio =
            this.#context?.getOrCreateAudioElement?.(
                id
            );

        if (!audio) return;

        audio.muted =
            this.#deafened;

        audio.srcObject =
            event.streams?.[0] ||
            new MediaStreamClass([track]);

        this.applyAudioOutput(
            audio
        );

    }

    #mediaSignalData(data) {

        if (!data || typeof data !== "object") return data;

        return Object.assign(
            {},
            data,
            {
                chatspace_media:
                    this.#webcamStream() ? "video" : "voice"
            }
        );

    }

    #sendSignal(toId, type, data) {

        const payload =
            this.#mediaSignalData(
                data
            );

        return this.#context?.apiPost?.(
            "/api/media_signal.php",
            {
                action:
                    "signal",

                media:
                    payload?.chatspace_media === "video" ? "webcam" : "voice",

                session_id:
                    this.#config()?.sessionId,

                participant_id:
                    this.#config()?.myParticipantId,

                to_id:
                    toId,

                join_token:
                    this.#config()?.myJoinToken,

                type,

                data:
                    payload
            }
        );

    }

    async #handleSignal(signal) {

        this.#lastSignalId =
            Math.max(
                this.#lastSignalId,
                signal.id
            );

        const from =
            Number(signal.from_participant_id);

        if (!from || from === Number(this.#config()?.myParticipantId)) return;

        const remote =
            this.#participants().get(
                Number(from)
            );

        const remoteHasWebcam =
            Boolean(remote?.webcam_enabled || remote?.webcam_path);

        const signalHasVideo =
            signal.data?.chatspace_media === "video";

        const shouldHandleMedia =
            Boolean(
                this.#joined ||
                this.#webcamStream() ||
                remoteHasWebcam ||
                signalHasVideo
            );

        if (!shouldHandleMedia && signal.type !== "leave") return;

        if (signal.type === "leave") {

            this.#context?.removeAudioElement?.(
                from
            );

            if (this.#peers.has(from) && !remoteHasWebcam) {

                this.#context?.detachParticipantVideo?.(
                    from
                );

                this.#peers.get(from).close();
                this.#peers.delete(from);

            }

            return;

        }

        const pc =
            await this.#getPeer(
                from,
                signal.type === "offer"
            );

        if (signal.type === "join") {

            if (!this.#joined && !this.#webcamStream()) return;

            const offer =
                await pc.createOffer();

            await pc.setLocalDescription(
                offer
            );

            await this.#sendSignal(
                from,
                "offer",
                pc.localDescription
            );

        }

        if (signal.type === "offer") {

            this.#syncPeerLocalTracks(
                pc
            );

            const offerCollision =
                pc.__makingOffer ||
                pc.signalingState !== "stable";

            if (offerCollision) {

                if (!pc.__polite) return;

                await pc.setLocalDescription({

                    type:
                        "rollback"

                }).catch(() => {});

            }

            await pc.setRemoteDescription(
                signal.data
            );

            const answer =
                await pc.createAnswer();

            await pc.setLocalDescription(
                answer
            );

            await this.#sendSignal(
                from,
                "answer",
                pc.localDescription
            );

        }

        if (signal.type === "answer") {

            await pc.setRemoteDescription(
                signal.data
            );

        }

        if (signal.type === "ice") {

            await pc.addIceCandidate(
                signal.data
            ).catch(() => {});

        }

    }

}

export default VoiceMediaService;
