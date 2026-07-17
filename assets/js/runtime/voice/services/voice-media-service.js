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
 * - Build 000043 Part 2 gates verification-only transport/RTP diagnostics
 *   through the generic RuntimeDiagnostics capability.
 * - Build 000043 Part 3 delegates device workflow to VoiceDeviceService.
 * - Build 000043 Part 4 adds explicit lifecycle generations, peer/resource
 *   scopes, idempotent cleanup, and executable invariants.
 ******************************************************************************/

import {

    VoiceDeviceService

} from "./voice-device-service.js";

import {

    VoiceLifecycleService

} from "./voice-lifecycle-service.js";

/**
 * @file voice-media-service.js
 *
 * Defines the Voice Media Service.
 */

//--------------------------------------------------
// Constants
//--------------------------------------------------

const DEFAULT_ICE_SERVERS = Object.freeze([

    Object.freeze({

        urls:
            "stun:stun.l.google.com:19302"

    })

]);

const SIGNAL_OUTCOME = Object.freeze({

    CONSUMED:
        "consumed",

    DEFERRED:
        "deferred",

    DUPLICATE:
        "duplicate",

    STALE_GENERATION:
        "stale-generation",

    TERMINAL_INVALID:
        "terminal-invalid",

    RETRYABLE_FAILURE:
        "retryable-failure"

});

const TERMINAL_SIGNAL_OUTCOMES = new Set([

    SIGNAL_OUTCOME.CONSUMED,
    SIGNAL_OUTCOME.DUPLICATE,
    SIGNAL_OUTCOME.STALE_GENERATION,
    SIGNAL_OUTCOME.TERMINAL_INVALID

]);

class VoiceNegotiationError extends Error {

    constructor(operation, message, details = {}) {

        super(message);
        this.name = "VoiceNegotiationError";
        this.operation = operation;
        this.details = details;

    }

}

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

    #lifecycle;

    #joinPromise = null;

    #leavePromise = null;

    #pollTimer = null;

    #muted = false;

    #deafened = false;

    #speaking = false;

    #devices;

    #analyserTimer = null;

    #audioContext = null;

    #analyser = null;

    #micSource = null;

    #voiceParticipants = [];

    #lastStatusSignature = "";

    #signalFetchCursor = 0;

    #peers = new Map();

    #peerSerial = 0;

    #negotiationSerial = 0;

    #renegotiationRequestSerial = 0;

    #signalInbox = new Map();

    #terminalSignalIds = new Map();

    #processingSignalIds = new Set();

    #drainingSignalInbox = false;

    #maxSignalInboxSize = 320;

    #maxTerminalSignalHistory = 640;

    #maxSignalAttempts = 3;

    #clientEpoch = null;

    #clientEpochRegistered = false;

    #remoteWebcamReadinessRequests = new Set();

    #pollInProgress = false;

    #pendingRemoteAudioTracks = new Map();

    #remoteAudioResources = new Map();

    #remoteVideoPresentations = new Map();

    #recoveryTimers = new Map();

    #mediaObjectIds = new WeakMap();

    #mediaObjectSerial = 0;

    #audioPlaybackStates = new WeakMap();

    #transportProbeDelays = Object.freeze([

        0,

        2000,

        5000

    ]);

    #transportProbeTimers = new Map();

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
        this.#devices = new VoiceDeviceService();
        this.#lifecycle = new VoiceLifecycleService();

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

        const transition =
            this.#lifecycle.destroy();

        if (transition.status === "duplicate") return transition;

        this.#recordLifecycleTransition(transition);

        this.stopPolling();
        this.#stopAnalyser();
        this.#stopVoiceStream();
        this.#removeAllAudioElements();
        this.#closeAllPeers();
        this.#clearRecoveryTimers();
        this.#devices.destroy();
        this.#voiceParticipants = [];
        this.#lastStatusSignature = "";
        this.#signalFetchCursor = 0;
        this.#signalInbox.clear();
        this.#terminalSignalIds.clear();
        this.#processingSignalIds.clear();
        this.#drainingSignalInbox = false;
        this.#clientEpoch = null;
        this.#clientEpochRegistered = false;
        this.#remoteWebcamReadinessRequests.clear();
        this.#pendingRemoteAudioTracks.clear();
        this.#remoteAudioResources.clear();
        this.#remoteVideoPresentations.clear();
        this.#muted = false;
        this.#deafened = false;
        this.#speaking = false;
        this.#joinPromise = null;
        this.#leavePromise = null;

        const invariantResult =
            this.verifyResourceInvariants("destroy");

        this.#context = null;

        return Object.freeze({
            ...transition,
            invariants: invariantResult
        });

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

        return this.#lifecycle.isJoined();

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
                this.#lifecycle.isJoined(),

            muted:
                this.#muted,

            deafened:
                this.#deafened,

            speaking:
                this.#speaking,

            selectedOutputDeviceId:
                this.#devices.selectedOutputDeviceId

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

        const transition =
            this.#lifecycle.activate();

        if (transition.status === "destroyed") return transition;

        this.#context = context;
        this.#clientEpoch =
            this.#clientEpoch || this.#createClientEpoch();
        this.#devices.configure({
            navigator: context.navigator,
            HTMLMediaElement: context.HTMLMediaElement,
            canPopulateDevices: context.canPopulateDevices,
            onSnapshot: context.onDeviceSnapshot,
            hasActiveVoiceStream: () => Boolean(this.#voiceStream),
            recordDiagnostic: entry => this.#recordAudioPathDiagnostic(entry),
            warn: error => this.#warn(error)
        });

        this.#recordLifecycleTransition(transition);

        return transition;

    }

    //--------------------------------------------------
    // Public Voice Workflow
    //--------------------------------------------------

    /**
     * Populates selectable voice input/output devices through host callbacks.
     *
     * @returns {Promise<void>}
     */
    async populateDevices(reason = "manual") {

        if (this.#lifecycle.isDestroyed()) return Object.freeze({ status: "destroyed" });

        return this.#devices.populate(reason);

    }

    /**
     * Requests microphone permission from an explicit host UI action, then
     * refreshes device labels without retaining a new capture stream.
     *
     * @returns {Promise<void>}
     */
    async requestDevicePermissionAndPopulate() {

        if (this.#lifecycle.isDestroyed()) return Object.freeze({ status: "destroyed" });

        return this.#devices.requestPermissionAndPopulate();

    }

    getDeviceSnapshot() {

        return this.#devices.getSnapshot();

    }

    selectDevices(selection) {

        if (this.#lifecycle.isDestroyed()) return Object.freeze({ status: "destroyed" });

        return this.#devices.selectDevices(selection);

    }

    /**
     * Joins room voice.
     *
     * @returns {Promise<void>}
     */
    join() {

        if (this.#joinPromise) return this.#joinPromise;

        const transition =
            this.#lifecycle.beginJoin();

        this.#recordLifecycleTransition(transition);

        if (transition.status !== "started") return transition;

        const operation =
            this.#runJoin(transition.token);

        const trackedOperation =
            operation.finally(() => {

                if (this.#joinPromise === trackedOperation) {

                    this.#joinPromise = null;

                }

            });

        this.#joinPromise =
            trackedOperation;

        return this.#joinPromise;

    }

    /**
     * Leaves room voice.
     *
     * @returns {Promise<void>}
     */
    leave() {

        if (this.#leavePromise) return this.#leavePromise;

        const transition =
            this.#lifecycle.beginLeave();

        this.#recordLifecycleTransition(transition);

        if (transition.status !== "started") return transition;

        const pendingJoin =
            this.#joinPromise;

        const operation =
            this.#runLeave(transition.token, pendingJoin);

        const trackedOperation =
            operation.finally(() => {

                if (this.#leavePromise === trackedOperation) {

                    this.#leavePromise = null;

                }

            });

        this.#leavePromise =
            trackedOperation;

        return this.#leavePromise;

    }

    /**
     * Updates local muted state.
     *
     * @param {boolean} muted
     */
    setMuted(muted) {

        if (this.#lifecycle.isDestroyed()) return Object.freeze({ status: "destroyed" });

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

        return this.getState();

    }

    /**
     * Updates local deafened state.
     *
     * @param {boolean} deafened
     */
    setDeafened(deafened) {

        if (this.#lifecycle.isDestroyed()) return Object.freeze({ status: "destroyed" });

        this.#deafened =
            Boolean(deafened);

        this.#setRemoteAudioDeafened();
        this.renderCurrentVoiceList();
        this.syncStatus(true);

        return this.getState();

    }

    /**
     * Synchronizes voice status with the server.
     *
     * @param {boolean} force
     *
     * @returns {Promise<void>}
     */
    syncStatus(force = false) {

        if (this.#lifecycle.isDestroyed()) return Promise.resolve({ status: "destroyed" });

        if (!this.#lifecycle.isJoined()) return Promise.resolve();

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
                client_epoch: this.#clientEpoch,
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

        if (this.#lifecycle.isDestroyed()) return Object.freeze({ status: "destroyed" });

        this.stopPolling();

        const setTimer =
            this.#context?.setTimeout || setTimeout;

        this.#pollTimer =
            setTimer(
                () => this.poll(),
                delay
            );

        return Object.freeze({ status: "scheduled", delay });

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

        if (this.#lifecycle.isDestroyed()) return false;

        return Boolean(
            this.#lifecycle.isJoined() ||
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

        if (this.#lifecycle.isDestroyed()) return Object.freeze({ status: "destroyed" });

        return this.#devices.applyAudioOutput(audio);

    }

    /**
     * Connects one remote participant media peer.
     *
     * @param {number|string} participantId
     *
     * @returns {Promise<void>}
     */
    async connectMediaPeer(participantId, options = {}) {

        if (this.#lifecycle.isDestroyed()) return SIGNAL_OUTCOME.STALE_GENERATION;

        const id =
            Number(participantId);

        if (
            !id ||
            id === Number(this.#config()?.myParticipantId) ||
            (!this.mediaActive() && !options.allowReceiveOnly)
        ) {

            return SIGNAL_OUTCOME.DEFERRED;

        }

        if (!this.#ownsOffer(id)) {

            const pc =
                this.#peers.get(id) || null;

            if (pc) {

                return this.#requestPeerNegotiation(
                    id,
                    pc,
                    options.reason || "connect-media-peer",
                    options
                );

            } else {

                const requestId =
                    this.#nextRenegotiationRequestId(id, null);

                this.#recordNegotiationDiagnostic({

                    event:
                        "renegotiation-request-accepted",

                    requestId,

                    mediaReason:
                        options.mediaReason || "initial-peer",

                    webcamOperation:
                        options.webcamOperation || null,

                    localParticipantId:
                        this.#localParticipantId(),

                    remoteParticipantId:
                        id,

                    authoritativeOffererParticipantId:
                        Math.min(this.#localParticipantId(), id),

                    role:
                        "answerer",

                    peerInstanceId:
                        null,

                    generation:
                        this.#clientEpoch

                });

                await this.#sendSignal(
                    id,
                    "renegotiate",
                    {

                        reason:
                            options.reason || "remote-offer-request",

                        media_reason:
                            options.mediaReason || "initial-peer",

                        webcam_operation:
                            options.webcamOperation || null,

                        request_id:
                            requestId,

                        generation:
                            this.#clientEpoch,

                        late_join_ready:
                            Boolean(options.lateJoinReady)

                    }
                );

                return SIGNAL_OUTCOME.CONSUMED;

            }

        }

        const existingPeer =
            this.#peers.get(id) || null;

        const pc =
            existingPeer || this.#createPeer(id, "offerer");

        if (!existingPeer && options.missingPeerRequest) {

            this.#recordNegotiationDiagnostic({

                event:
                    "peer-created-for-webcam-first-request",

                requestId:
                    options.requestId || null,

                signalId:
                    Number(options.signalId) || null,

                mediaReason:
                    options.mediaReason || null,

                webcamOperation:
                    options.webcamOperation || null,

                ...this.#peerSnapshot(pc)

            });

        }

        return this.#requestPeerNegotiation(
            id,
            pc,
            options.reason || "connect-media-peer",
            options
        );

    }

    /**
     * Connects media peers for all known participants.
     */
    async connectMediaPeers(options = {}) {

        if (this.#lifecycle.isDestroyed()) return SIGNAL_OUTCOME.STALE_GENERATION;

        if (!this.mediaActive()) return;

        await Promise.all(
            [...this.#participants().values()].map(person => {

                if (Number(person.id) === Number(this.#config()?.myParticipantId)) {

                    return Promise.resolve();

                }

                return this.connectMediaPeer(
                    person.id,
                    options
                );

            })
        );

    }

    /**
     * Renegotiates existing media peers.
     */
    async renegotiateMediaPeers(options = {}) {

        if (this.#lifecycle.isDestroyed()) return SIGNAL_OUTCOME.STALE_GENERATION;

        for (const [id, pc] of this.#peers) {

            await this.#requestPeerNegotiation(
                id,
                pc,
                options.reason || "renegotiate-media-peers",
                options
            );

        }

    }

    /**
     * Reconciles remote webcam presentation from the active canonical receiver.
     * Re-enabling a sender on an existing transceiver does not fire ontrack again.
     *
     * @param {number|string} participantId
     * @param {boolean} enabled
     * @param {string} reason
     *
     * @returns {boolean}
     */
    reconcileRemoteWebcamPresentation(participantId, enabled, reason = "participant-state") {

        if (this.#lifecycle.isDestroyed()) return false;

        const id =
            Number(participantId);

        if (!id || id === this.#localParticipantId()) return false;

        const pc =
            this.#peers.get(id) || null;

        if (!enabled) {

            this.#remoteVideoPresentations.delete(id);

            this.#recordVideoLifecycleDiagnostic({

                event:
                    "remote-video-removed",

                remoteParticipantId:
                    id,

                reason,

                ...(pc ? this.#peerSnapshot(pc) : {})

            });

            return false;

        }

        if (!pc || !this.#isActivePeer(pc)) return false;

        const transceiver =
            pc.__voiceTransceivers?.video || null;

        const track =
            transceiver?.receiver?.track || null;

        if (!track || track.readyState === "ended") return false;

        const stream =
            this.#canonicalRemoteVideoStream(pc, transceiver, track);

        const presentation =
            this.#remoteVideoPresentations.get(id);

        this.#recordVideoLifecycleDiagnostic({

            event:
                "remote-video-presentation-attach-requested",

            remoteParticipantId:
                id,

            reason,

            receiverIdentity:
                presentation?.receiverIdentity || null,

            remoteTrackId:
                track.id || null,

            requestedStreamIdentity:
                presentation?.streamIdentity || null,

            ...this.#peerSnapshot(pc)

        });

        this.#context?.attachParticipantVideo?.(
            id,
            stream,
            false,
            {
                source: `receiver-reconciliation:${reason}`,
                peerInstanceId: pc.__voicePeerInstanceId || null,
                generation: pc.__voiceGeneration || null,
                receiverIdentity: presentation?.receiverIdentity || null,
                streamIdentity: presentation?.streamIdentity || null,
                remoteTrackId: track.id || null
            }
        );

        this.#recordVideoLifecycleDiagnostic({

            event:
                "remote-video-attached",

            remoteParticipantId:
                id,

            reason,

            finalRemoteReceiverTrackId:
                track.id || null,

            finalVideoTransceiverDirection:
                transceiver.direction || null,

            ...this.#peerSnapshot(pc)

        });

        return true;

    }

    async reconcileRemoteWebcamReadiness(participantId, enabled, reason = "participant-state") {

        if (this.#lifecycle.isDestroyed()) return SIGNAL_OUTCOME.STALE_GENERATION;

        const id =
            Number(participantId);

        if (!id || id === this.#localParticipantId()) return SIGNAL_OUTCOME.DEFERRED;

        if (!enabled) {

            this.#remoteWebcamReadinessRequests.delete(id);
            return SIGNAL_OUTCOME.CONSUMED;

        }

        if (this.#peers.has(id)) return SIGNAL_OUTCOME.DUPLICATE;

        if (!this.#clientEpochRegistered) {

            this.#recordNegotiationDiagnostic({

                event:
                    "remote-webcam-readiness-deferred",

                reason,

                remoteParticipantId:
                    id,

                clientEpoch:
                    this.#clientEpoch

            });

            return SIGNAL_OUTCOME.DEFERRED;

        }

        if (this.#remoteWebcamReadinessRequests.has(id)) {

            return SIGNAL_OUTCOME.DUPLICATE;

        }

        this.#remoteWebcamReadinessRequests.add(id);

        this.#recordNegotiationDiagnostic({

            event:
                "remote-webcam-readiness-requested",

            reason,

            remoteParticipantId:
                id,

            clientEpoch:
                this.#clientEpoch,

            authoritativeOffererParticipantId:
                Math.min(this.#localParticipantId(), id),

            role:
                this.#ownsOffer(id) ? "offerer" : "answerer"

        });

        try {

            const outcome =
                await this.connectMediaPeer(id, {

                    allowReceiveOnly:
                        true,

                    reason:
                        "client-epoch-ready-remote-webcam",

                    mediaReason:
                        "webcam",

                    webcamOperation:
                        "late-join",

                    lateJoinReady:
                        true

                });

            if (outcome !== SIGNAL_OUTCOME.CONSUMED) {

                this.#remoteWebcamReadinessRequests.delete(id);

            }

            return outcome;

        } catch (error) {

            this.#remoteWebcamReadinessRequests.delete(id);
            this.#warn(error);
            return SIGNAL_OUTCOME.RETRYABLE_FAILURE;

        }

    }

    /**
     * Closes one media peer.
     *
     * @param {number|string} participantId
     */
    closePeer(participantId) {

        if (this.#lifecycle.isDestroyed()) return;

        const id =
            Number(participantId);

        if (!this.#peers.has(id)) return;

        const pc =
            this.#peers.get(id);

        this.#releasePeer(pc, "closePeer");

    }

    /**
     * Polls media signaling and voice participant state once.
     *
     * @returns {Promise<void>}
     */
    async poll() {

        if (this.#lifecycle.isDestroyed()) return Object.freeze({ status: "destroyed" });

        if (this.#pollInProgress) {

            this.#recordNegotiationDiagnostic({

                event:
                    "poll-skipped-overlap"

            });

            return;

        }

        const pollToken =
            this.#lifecycle.beginOperation("poll");

        if (!pollToken) return Object.freeze({ status: "destroyed" });

        this.#pollInProgress =
            true;

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
                        this.#signalFetchCursor,

                    client_epoch:
                        this.#clientEpoch,

                    join_token:
                        this.#config()?.myJoinToken

                });

            const data =
                await this.#context?.fetchMediaSignals?.(qs);

            if (!this.#lifecycle.isCurrent(pollToken)) {

                return this.#cancelledOperationResult(
                    "poll",
                    pollToken,
                    "fetch-media-signals"
                );

            }

            const clientEpochWasRegistered =
                this.#clientEpochRegistered;

            this.#clientEpochRegistered =
                Boolean(
                    data?.client_epoch &&
                    data.client_epoch === this.#clientEpoch
                );

            this.#renderVoiceList(
                data?.voice_participants || []
            );

            this.#stageFetchedSignals(
                data?.signals || [],
                data?.signal_errors || []
            );

            const highWaterSignalId =
                Number(data?.last_signal_id);

            if (highWaterSignalId > this.#signalFetchCursor) {

                this.#signalFetchCursor =
                    highWaterSignalId;

            }

            await this.#drainSignalInbox();

            if (
                !clientEpochWasRegistered &&
                this.#clientEpochRegistered &&
                !this.mediaActive()
            ) {

                await this.#reconcileKnownRemoteWebcams(
                    "client-epoch-registered"
                );

            }

        } catch (error) {

            this.#warn(
                error
            );

        } finally {

            this.#pollInProgress =
                false;

        }

        if (!this.#lifecycle.isDestroyed()) {

            this.startPolling(
                this.shouldPollFast() ? 800 : 2000
            );

        }

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
                this.#lifecycle.isJoined(),

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

            signalFetchCursor:
                this.#signalFetchCursor,

            clientEpochRegistered:
                this.#clientEpochRegistered,

            remoteWebcamReadinessRequestCount:
                this.#remoteWebcamReadinessRequests.size,

            processedSignalCount:
                this.#terminalSignalIds.size,

            pendingSignalCount:
                this.#signalInbox.size,

            processingSignalCount:
                this.#processingSignalIds.size,

            transportProbeTimerCount:
                Array.from(this.#transportProbeTimers.values())
                    .reduce((count, timers) => count + timers.size, 0),

            recoveryTimerCount:
                this.#recoveryTimers.size,

            remoteAudioResourceCount:
                this.#remoteAudioResources.size,

            pendingRemoteAudioCount:
                this.#pendingRemoteAudioTracks.size,

            devices:
                this.#devices.getDiagnostics(),

            polling:
                this.#pollTimer !== null,

            lifecycle:
                this.#lifecycle.getSnapshot(),

            joinOperationPending:
                this.#joinPromise !== null,

            leaveOperationPending:
                this.#leavePromise !== null

        });

    }

    /**
     * Returns an identity-aware snapshot of resources owned by voice.
     * Host-owned webcam capture and AvatarRenderer presentation are reported
     * separately and are not counted as voice-owned resources.
     */
    getResourceSnapshot() {

        const peers =
            Array.from(this.#peers.values());

        const deviceDiagnostics =
            this.#devices.getDiagnostics();

        return Object.freeze({

            lifecycle:
                this.#lifecycle.getSnapshot(),

            liveOwnedCaptureTrackCount:
                this.#voiceStream?.getTracks?.().filter(
                    track => track.readyState !== "ended"
                ).length || 0,

            activePeerConnectionCount:
                peers.filter(pc => pc.connectionState !== "closed").length,

            peerOperationCount:
                peers.filter(pc => pc.__voiceOperationTail).length,

            peerTrackListenerCount:
                peers.reduce(
                    (count, pc) => count + (pc.__voiceTrackListeners?.length || 0),
                    0
                ),

            remoteAudioElementCount:
                this.#context?.getAudioElements?.().length || 0,

            remoteAudioResourceCount:
                this.#remoteAudioResources.size,

            pendingRemoteAudioCount:
                this.#pendingRemoteAudioTracks.size,

            pendingSignalOperationCount:
                this.#signalInbox.size +
                this.#processingSignalIds.size +
                (this.#drainingSignalInbox ? 1 : 0),

            delayedProbeCount:
                Array.from(this.#transportProbeTimers.values())
                    .reduce((count, timers) => count + timers.size, 0),

            recoveryTimerCount:
                this.#recoveryTimers.size,

            pollTimerCount:
                this.#pollTimer === null ? 0 : 1,

            analyserIntervalCount:
                this.#analyserTimer === null ? 0 : 1,

            joinOperationCount:
                this.#joinPromise === null ? 0 : 1,

            leaveOperationCount:
                this.#leavePromise === null ? 0 : 1,

            activeDeviceListenerCount:
                deviceDiagnostics.deviceChangeListening ? 1 : 0,

            mutationCapablePublicOperationCount:
                this.#lifecycle.isDestroyed() ? 0 : 1,

            hostWebcam:
                Object.freeze({
                    ...(this.#context?.getWebcamLifecycleState?.() || {}),
                    ownership: "room-host"
                }),

            peers:
                Object.freeze(peers.map(pc => Object.freeze({
                    ...this.#peerSnapshot(pc),
                    associatedTransceiverCount:
                        pc.getTransceivers?.().filter(transceiver =>
                            transceiver === pc.__voiceTransceivers?.audio ||
                            transceiver === pc.__voiceTransceivers?.video
                        ).length || 0
                })))

        });

    }

    verifyResourceInvariants(phase = "active", options = {}) {

        const resources =
            this.getResourceSnapshot();

        const violations =
            [];

        for (const peer of resources.peers) {

            if (peer.connectionState !== "connected") continue;

            if (peer.transceivers.length !== 2) {
                violations.push(`peer:${peer.peerInstanceId}:transceiver-count`);
            }
            if (peer.associatedTransceiverCount !== 2) {
                violations.push(`peer:${peer.peerInstanceId}:associated-transceivers`);
            }
            if (String(peer.canonicalTransceiverMids.audio) !== "0") {
                violations.push(`peer:${peer.peerInstanceId}:audio-mid`);
            }
            if (String(peer.canonicalTransceiverMids.video) !== "1") {
                violations.push(`peer:${peer.peerInstanceId}:video-mid`);
            }

        }

        for (const resource of this.#remoteAudioResources.values()) {

            if (!this.#isRemoteAudioResourceCurrent(resource)) {
                violations.push(`remote-audio:${resource.participantId}:stale-owner`);
            }

        }

        if (phase === "leave") {

            if (resources.lifecycle.participationState !== "not-joined") {
                violations.push("leave:participation");
            }
            if (resources.liveOwnedCaptureTrackCount !== 0) {
                violations.push("leave:live-capture-tracks");
            }
            if (resources.remoteAudioElementCount !== 0) {
                violations.push("leave:remote-audio-elements");
            }
            if (resources.remoteAudioResourceCount !== 0) {
                violations.push("leave:remote-audio-resources");
            }
            if (resources.pendingRemoteAudioCount !== 0) {
                violations.push("leave:pending-remote-audio");
            }
            if (resources.analyserIntervalCount !== 0 || this.#speaking) {
                violations.push("leave:speaking-analyser");
            }

        }

        if (phase === "destroy") {

            const requiredZero = [
                "liveOwnedCaptureTrackCount",
                "activePeerConnectionCount",
                "peerOperationCount",
                "peerTrackListenerCount",
                "remoteAudioElementCount",
                "remoteAudioResourceCount",
                "pendingRemoteAudioCount",
                "pendingSignalOperationCount",
                "delayedProbeCount",
                "recoveryTimerCount",
                "pollTimerCount",
                "analyserIntervalCount",
                "joinOperationCount",
                "leaveOperationCount",
                "activeDeviceListenerCount",
                "mutationCapablePublicOperationCount"
            ];

            requiredZero.forEach(name => {
                if (resources[name] !== 0) violations.push(`destroy:${name}`);
            });

            if (resources.lifecycle.facadeState !== "destroyed") {
                violations.push("destroy:facade-state");
            }

        }

        const result =
            Object.freeze({
                ok: violations.length === 0,
                phase,
                violations: Object.freeze(violations),
                resources
            });

        this.#recordVideoLifecycleDiagnostic({
            event: "voice-resource-invariant",
            phase,
            ok: result.ok,
            violations
        });

        if (!result.ok && options.throwOnFailure) {

            throw new Error(
                `Voice resource invariant failed (${phase}): ${violations.join(", ")}`
            );

        }

        return result;

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    async #runJoin(token) {

        let acquiredStream = null;

        try {

            this.#devices.captureSelectedOutputDevice();

            const mediaDevices =
                this.#context?.navigator?.mediaDevices;

            this.#recordAudioPathDiagnostic({

                event:
                    "audio-source-acquire-start",

                operationGeneration:
                    token.generation,

                selectedInputDeviceId:
                    this.#context?.getInputDeviceId?.() || null,

                selectedOutputDeviceId:
                    this.#devices.selectedOutputDeviceId || null

            });

            acquiredStream =
                await mediaDevices.getUserMedia({

                    audio:
                        this.#devices.selectedInputConstraints(),

                    video:
                        false

                });

            if (!this.#lifecycle.isCurrent(token)) {

                this.#releaseMediaStream(acquiredStream);
                return this.#cancelledOperationResult(
                    "join",
                    token,
                    "microphone-acquisition"
                );

            }

            this.#voiceStream = acquiredStream;
            this.#recordLifecycleTransition(
                this.#lifecycle.markMicrophoneReady(token)
            );

            const audioTrack =
                this.#voiceStream?.getAudioTracks?.()[0] || null;

            this.#recordAudioPathDiagnostic({

                event:
                    "audio-source-acquired",

                operationGeneration:
                    token.generation,

                sourceType:
                    this.#audioSourceType(audioTrack),

                stream:
                    this.#streamSnapshot(this.#voiceStream),

                track:
                    this.#trackSnapshot(audioTrack),

                selectedInputDeviceId:
                    this.#context?.getInputDeviceId?.() || null

            });

            this.#muted = false;
            this.#deafened = false;
            this.#speaking = false;
            this.#recordLifecycleTransition(
                this.#lifecycle.completeJoin(token)
            );
            this.#attachPendingRemoteAudioTracks();
            this.#context?.updateToggleButton?.();

            await this.populateDevices("after-permission").catch(() => {});
            if (!this.#lifecycle.isCurrent(token)) {
                return this.#cancelledOperationResult(
                    "join",
                    token,
                    "post-permission-refresh"
                );
            }

            await this.#context?.apiPost?.(
                "/api/media_signal.php",
                {
                    action: "join",
                    media: "voice",
                    session_id: this.#config()?.sessionId,
                    participant_id: this.#config()?.myParticipantId,
                    client_epoch: this.#clientEpoch,
                    join_token: this.#config()?.myJoinToken
                }
            );

            if (!this.#lifecycle.isCurrent(token)) {
                return this.#cancelledOperationResult(
                    "join",
                    token,
                    "server-join"
                );
            }

            await this.syncStatus(true);
            this.#startAnalyser(token);
            this.#forEachAudioElement(audio => this.applyAudioOutput(audio));
            await this.connectMediaPeers();

            if (!this.#lifecycle.isCurrent(token)) {
                return this.#cancelledOperationResult(
                    "join",
                    token,
                    "peer-connect"
                );
            }

            this.startPolling(0);
            this.#context?.closeDeviceModal?.();
            this.populateDevices("after-join").catch(() => {});

            this.verifyResourceInvariants("active");

            return Object.freeze({
                status: "completed",
                operation: "join",
                generation: token.generation,
                readiness: "local-source-status-peers-polling"
            });

        } catch (error) {

            if (!this.#lifecycle.isCurrent(token)) {

                if (acquiredStream !== this.#voiceStream) {
                    this.#releaseMediaStream(acquiredStream);
                }

                return this.#cancelledOperationResult(
                    "join",
                    token,
                    "failure-after-cancellation"
                );

            }

            this.#recordLifecycleTransition(
                this.#lifecycle.failJoin(token)
            );
            this.#context?.updateToggleButton?.();
            this.#stopVoiceStream();
            this.#context?.setDeviceStatus?.(
                error.message || "Could not join voice chat.",
                "error"
            );

            return Object.freeze({
                status: "failed",
                operation: "join",
                generation: token.generation,
                errorName: error?.name || null
            });

        }

    }

    async #runLeave(token, pendingJoin) {

        let reconciliationError =
            null;

        this.#muted = false;
        this.#deafened = false;
        this.#speaking = false;
        this.#lastStatusSignature = "";
        this.#stopAnalyser();
        this.#context?.updateToggleButton?.();
        this.#removeAllAudioElements();
        this.#pendingRemoteAudioTracks.clear();
        this.#stopVoiceStream();

        await pendingJoin?.catch?.(() => {});

        if (!this.#lifecycle.isCurrent(token)) {
            return this.#cancelledOperationResult(
                "leave",
                token,
                "pending-join"
            );
        }

        try {

            if (this.#webcamStream()) {
                await this.renegotiateMediaPeers();
            } else {
                this.#closeAllPeers();
            }

        } catch (error) {

            reconciliationError = error;
            this.#recordVideoLifecycleDiagnostic({
                event: "voice-leave-peer-reconciliation-failed",
                operationGeneration: token.generation,
                errorName: error?.name || null,
                message: error?.message || String(error)
            });
            this.#warn(error);

        }

        if (!this.#lifecycle.isCurrent(token)) {
            return this.#cancelledOperationResult(
                "leave",
                token,
                "peer-cleanup"
            );
        }

        await this.#context?.apiPost?.(
            "/api/media_signal.php",
            {
                action: "leave",
                media: "voice",
                session_id: this.#config()?.sessionId,
                participant_id: this.#config()?.myParticipantId,
                client_epoch: this.#clientEpoch,
                join_token: this.#config()?.myJoinToken
            }
        ).catch(() => {});

        if (!this.#lifecycle.isCurrent(token)) {
            return this.#cancelledOperationResult(
                "leave",
                token,
                "server-leave"
            );
        }

        this.#voiceParticipants =
            this.#voiceParticipants.filter(participant =>
                Number(participant.id) !== Number(this.#config()?.myParticipantId)
            );

        this.#renderVoiceList(this.#voiceParticipants);
        this.#recordLifecycleTransition(
            this.#lifecycle.completeLeave(token)
        );
        this.startPolling(0);

        this.verifyResourceInvariants("leave");

        return Object.freeze({
            status: reconciliationError ? "completed-with-warning" : "completed",
            operation: "leave",
            generation: token.generation,
            readiness: "voice-resources-released",
            peerReconciliationCompleted: reconciliationError === null
        });

    }

    #releaseMediaStream(stream) {

        stream?.getTracks?.().forEach(track => {

            if (track.readyState !== "ended") track.stop();

        });

    }

    #cancelledOperationResult(operation, token, phase) {

        const status =
            this.#lifecycle.isDestroyed() ? "destroyed" : "stale-generation";

        this.#recordVideoLifecycleDiagnostic({
            event: "voice-operation-cancelled",
            operation,
            operationGeneration: token?.generation || null,
            phase,
            outcome: status
        });

        return Object.freeze({
            status,
            operation,
            generation: token?.generation || null,
            phase
        });

    }

    #recordLifecycleTransition(transition) {

        if (!transition) return;

        this.#recordVideoLifecycleDiagnostic({
            event: "voice-lifecycle-transition",
            operation: transition.operation || null,
            outcome: transition.status || null,
            previous: transition.previous || null,
            current: transition.current || this.#lifecycle.getSnapshot()
        });

    }

    async #reconcileKnownRemoteWebcams(reason) {

        for (const participant of this.#participants().values()) {

            const id =
                Number(participant?.id);

            if (
                !id ||
                id === this.#localParticipantId() ||
                !Boolean(participant.webcam_enabled || participant.webcam_path)
            ) {

                continue;

            }

            await this.reconcileRemoteWebcamReadiness(
                id,
                true,
                reason
            );

        }

    }

    #config() {

        return this.#context?.getConfig?.() || {};

    }

    #roomId() {

        return this.#config()?.roomPublicId || this.#config()?.roomId || null;

    }

    #participants() {

        return this.#context?.getParticipants?.() || new Map();

    }

    #webcamStream() {

        return this.#context?.getWebcamStream?.() || null;

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

        this.#forEachAudioElement(audio => {
            this.#prepareAudioElementRemoval(
                audio,
                "remove-all-remote-audio"
            );
        });
        this.#remoteAudioResources.clear();
        this.#context?.removeAllAudioElements?.();

    }

    #stopVoiceStream() {

        if (!this.#voiceStream) return;

        this.#releaseMediaStream(this.#voiceStream);
        this.#voiceStream = null;

    }

    #closeAllPeers() {

        for (const pc of Array.from(this.#peers.values())) {

            this.#releasePeer(pc, "closeAllPeers");

        }

        this.#processingSignalIds.clear();
        this.#drainingSignalInbox = false;
        this.#pollInProgress = false;
        this.#pendingRemoteAudioTracks.clear();
        this.#remoteAudioResources.clear();
        this.#remoteVideoPresentations.clear();
        this.#remoteWebcamReadinessRequests.clear();

    }

    #releasePeer(pc, reason, options = {}) {

        if (!pc || pc.__voiceLifecycleState === "closed") {

            return Object.freeze({ status: "duplicate", reason });

        }

        const participantId =
            Number(pc.__voiceRemoteParticipantId);

        const wasActive =
            this.#peers.get(participantId) === pc;

        const snapshot =
            this.#peerSnapshot(pc);

        pc.__voiceLifecycleState =
            options.replacing ? "replacing" : "closing";

        pc.__voiceOperationGeneration =
            Number(pc.__voiceOperationGeneration || 0) + 1;

        if (wasActive) {

            this.#peers.delete(participantId);

        }

        this.#clearRecoveryTimer(participantId);
        this.#clearTransportProbeTimers(pc);
        this.#removePeerTrackListeners(pc);

        pc.ontrack = null;
        pc.onicecandidate = null;
        pc.onconnectionstatechange = null;
        pc.oniceconnectionstatechange = null;
        pc.onicegatheringstatechange = null;
        pc.onnegotiationneeded = null;
        pc.__voiceDeferredTrackEvents = [];
        pc.__voicePendingIceSignals = [];
        pc.__voiceAdoptingRemoteDescription = false;
        pc.__voicePendingNegotiation = false;
        pc.__voicePendingRenegotiationRequest = null;

        const pendingAudio =
            this.#pendingRemoteAudioTracks.get(participantId) || null;

        if (!pendingAudio || pendingAudio.peer === pc) {

            this.#pendingRemoteAudioTracks.delete(participantId);

        }

        const videoPresentation =
            this.#remoteVideoPresentations.get(participantId) || null;

        if (!videoPresentation || videoPresentation.peer === pc) {

            this.#remoteVideoPresentations.delete(participantId);

        }

        this.#remoteWebcamReadinessRequests.delete(participantId);
        this.#releaseRemoteAudioResource(participantId, reason, pc);

        if (options.detachVideo) {

            this.#context?.detachParticipantVideo?.(participantId);

        }

        try {

            if (pc.connectionState !== "closed") pc.close();

        } catch (error) {

            this.#warn(error);

        }

        pc.__voiceLifecycleState =
            "closed";

        this.#recordVideoLifecycleDiagnostic({

            event:
                "peer-close",

            reason,

            remoteParticipantId:
                participantId,

            invalidatedBeforeClose:
                wasActive,

            previous:
                snapshot,

            ...this.#peerSnapshot(pc)

        });

        return Object.freeze({

            status:
                "completed",

            reason,

            participantId,

            peerInstanceId:
                pc.__voicePeerInstanceId || null

        });

    }

    #listenToPeerTrack(pc, track, eventName, handler, options = undefined) {

        if (!pc || !track?.addEventListener) return;

        const guardedHandler =
            event => {

                if (!this.#isActivePeer(pc)) return;
                handler(event);

            };

        track.addEventListener(eventName, guardedHandler, options);
        pc.__voiceTrackListeners.push({
            track,
            eventName,
            handler: guardedHandler,
            options
        });

    }

    #removePeerTrackListeners(pc) {

        for (const listener of pc?.__voiceTrackListeners || []) {

            listener.track?.removeEventListener?.(
                listener.eventName,
                listener.handler,
                listener.options
            );

        }

        if (pc) pc.__voiceTrackListeners = [];

    }

    #schedulePeerRecovery(participantId, failedPeer) {

        this.#clearRecoveryTimer(participantId);

        const setTimer =
            this.#context?.setTimeout || setTimeout;

        let timerId = null;

        timerId = setTimer(() => {

            const active =
                this.#recoveryTimers.get(participantId);

            if (active?.timerId !== timerId) return;

            this.#recoveryTimers.delete(participantId);

            if (
                this.#lifecycle.isDestroyed() ||
                this.#peers.has(participantId) ||
                !this.mediaActive()
            ) {

                return;

            }

            this.connectMediaPeer(participantId).catch(error =>
                this.#recordCriticalFailure(null, error, {

                    operation:
                        "rebuild-failed-peer",

                    remoteParticipantId:
                        participantId

                })
            );

        }, 0);

        this.#recoveryTimers.set(participantId, {
            timerId,
            failedPeerInstanceId: failedPeer?.__voicePeerInstanceId || null
        });

    }

    #clearRecoveryTimer(participantId) {

        const entry =
            this.#recoveryTimers.get(Number(participantId));

        if (!entry) return;

        const clearTimer =
            this.#context?.clearTimeout || clearTimeout;

        clearTimer(entry.timerId);
        this.#recoveryTimers.delete(Number(participantId));

    }

    #clearRecoveryTimers() {

        for (const participantId of Array.from(this.#recoveryTimers.keys())) {

            this.#clearRecoveryTimer(participantId);

        }

    }

    #warn(error) {

        this.#context?.warn?.(
            error
        );

    }

    #createClientEpoch() {

        const cryptoObject =
            this.#context?.window?.crypto || globalThis.crypto;

        if (typeof cryptoObject?.randomUUID === "function") {

            return cryptoObject.randomUUID();

        }

        return [

            Date.now().toString(36),
            Math.random().toString(36).slice(2),
            Math.random().toString(36).slice(2)

        ].join("-");

    }

    #recordCriticalFailure(pc, error, extra = {}) {

        const details =
            error instanceof VoiceNegotiationError ? error.details : {};

        this.#recordNegotiationDiagnostic({

            event:
                "critical-peer-operation-failed",

            operation:
                extra.operation || error?.operation || "unknown",

            errorName:
                error?.name || null,

            message:
                error?.message || String(error),

            localGeneration:
                pc?.__voiceGeneration || null,

            remoteGeneration:
                pc?.__voiceRemotePeerInstanceId || null,

            ...this.#peerSnapshot(pc),
            ...details,
            ...extra

        });

        this.#warn(error);

    }

    #recoverFailedPeer(pc, operation, error) {

        if (!pc) return;

        this.#recordCriticalFailure(pc, error, { operation });

        const participantId =
            Number(pc.__voiceRemoteParticipantId);

        if (this.#isActivePeer(pc)) {

            pc.__voiceLifecycleState =
                "failed";

            this.#releasePeer(pc, "peer-replacement", {
                replacing: true,
                detachVideo: true
            });

            if (this.mediaActive() && this.#ownsOffer(participantId)) {

                this.#schedulePeerRecovery(participantId, pc);

            }

        }

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

    #startAnalyser(operationToken = null) {

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

            const sourceTrack =
                this.#voiceStream.getAudioTracks?.()[0] || null;

            const setIntervalFn =
                this.#context?.setInterval || setInterval;

            this.#analyserTimer =
                setIntervalFn(
                    () => {

                        if (
                            this.#lifecycle.isDestroyed() ||
                            (
                                operationToken &&
                                !this.#lifecycle.isCurrent(operationToken)
                            ) ||
                            this.#voiceStream?.getAudioTracks?.()[0] !== sourceTrack
                        ) {

                            this.#stopAnalyser();
                            return;

                        }

                        this.#detectSpeaking(samples);

                    },
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

    #localMediaTrackByKind(kind) {

        return this.#localMediaTracks().find(track =>
            track.kind === kind
        ) || null;

    }

    #reserveOffererTransceivers(pc) {

        if (!pc || pc.__voiceTransceivers) {

            return pc?.__voiceTransceivers || null;

        }

        pc.__voiceTransceivers = {

            audio:
                null,

            video:
                null,

            fallback:
                false

        };

        if (typeof pc.addTransceiver !== "function") {

            pc.__voiceTransceivers.fallback =
                true;

            this.#recordVideoLifecycleDiagnostic({

                event:
                    "transceiver-reservation-unavailable",

                ...this.#peerSnapshot(pc)

            });

            throw new VoiceNegotiationError(
                "reserve-offerer-transceivers",
                "RTCRtpTransceiver support is required for deterministic media negotiation.",
                this.#peerSnapshot(pc)
            );

        }

        pc.__voiceTransceivers.audio =
            pc.addTransceiver(
                "audio",
                {

                    direction:
                        "recvonly"

                }
            );

        this.#recordVideoLifecycleDiagnostic({

            event:
                "transceiver-reserved",

            mediaKind:
                "audio",

            transceiver:
                this.#transceiverSnapshot(
                    pc.__voiceTransceivers.audio,
                    0
                ),

            ...this.#peerSnapshot(pc)

        });

        pc.__voiceTransceivers.video =
            pc.addTransceiver(
                "video",
                {

                    direction:
                        "recvonly"

                }
            );

        this.#recordVideoLifecycleDiagnostic({

            event:
                "transceiver-reserved",

            mediaKind:
                "video",

            transceiver:
                this.#transceiverSnapshot(
                    pc.__voiceTransceivers.video,
                    1
                ),

            ...this.#peerSnapshot(pc)

        });

        return pc.__voiceTransceivers;

    }

    async #syncPeerLocalTracks(pc) {

        if (!pc) return;

        const transceivers =
            pc.__voiceTransceivers || null;

        if (!transceivers) {

            throw new VoiceNegotiationError(
                "sync-local-tracks",
                "Canonical peer transceivers are not established.",
                this.#peerSnapshot(pc)
            );

        }

        if (transceivers && !transceivers.fallback) {

            await this.#syncTransceiverTrack(
                pc,
                "audio",
                this.#localMediaTrackByKind("audio")
            );

            await this.#syncTransceiverTrack(
                pc,
                "video",
                this.#localMediaTrackByKind("video")
            );

            return;

        }

        const desired =
            this.#localMediaTracks();

        for (const track of desired) {

            const sender =
                pc.getSenders().find(item =>
                    item.track?.kind === track.kind
                );

            if (sender) {

                if (sender.track !== track) {

                    this.#recordVideoLifecycleDiagnostic({

                        event:
                            "replaceTrack-called",

                        peerInstanceId:
                            pc.__voicePeerInstanceId || null,

                        generation:
                            pc.__voiceGeneration || null,

                        previousTrack:
                            this.#trackSnapshot(sender.track),

                        nextTrack:
                            this.#trackSnapshot(track),

                        sender:
                            this.#senderSnapshot(sender)

                    });

                    sender.replaceTrack(track).then(() => {

                        if (!this.#isActivePeer(pc)) return;

                        this.#recordVideoLifecycleDiagnostic({

                            event:
                                "replaceTrack-complete",

                            peerInstanceId:
                                pc.__voicePeerInstanceId || null,

                            generation:
                                pc.__voiceGeneration || null,

                            sender:
                                this.#senderSnapshot(sender)

                        });

                    }).catch(error => {

                        if (!this.#isActivePeer(pc)) return;

                        this.#recordVideoLifecycleDiagnostic({

                            event:
                                "replaceTrack-failed",

                            peerInstanceId:
                                pc.__voicePeerInstanceId || null,

                            generation:
                                pc.__voiceGeneration || null,

                            message:
                                error?.message || String(error)

                        });

                    });

                }

            } else {

                const stream =
                    track.kind === "video" ?
                        this.#webcamStream() :
                        this.#voiceStream;

                if (stream) {

                    this.#recordVideoLifecycleDiagnostic({

                        event:
                            "addTrack-called",

                        peerInstanceId:
                            pc.__voicePeerInstanceId || null,

                        generation:
                            pc.__voiceGeneration || null,

                        track:
                            this.#trackSnapshot(track),

                        streamTrackCount:
                            stream.getTracks?.().length || 0

                    });

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

                this.#recordVideoLifecycleDiagnostic({

                    event:
                        "removeTrack-called",

                    peerInstanceId:
                        pc.__voicePeerInstanceId || null,

                    generation:
                        pc.__voiceGeneration || null,

                    sender:
                        this.#senderSnapshot(sender),

                    track:
                        this.#trackSnapshot(sender.track)

                });

                pc.removeTrack(
                    sender
                );

            }

        });

    }

    async #syncTransceiverTrack(pc, kind, track) {

        const transceiver =
            pc?.__voiceTransceivers?.[kind] || null;

        const sender =
            transceiver?.sender || null;

        if (!transceiver || !sender) return;

        const nextDirection =
            track ? "sendrecv" : "recvonly";

        if (transceiver.direction !== nextDirection) {

            const previousDirection =
                transceiver.direction || null;

            transceiver.direction =
                nextDirection;

            this.#recordVideoLifecycleDiagnostic({

                event:
                    "transceiver-direction-updated",

                mediaKind:
                    kind,

                previousDirection,

                nextDirection,

                ...this.#peerSnapshot(pc)

            });

        }

        if (sender.track === track) return;

        this.#recordVideoLifecycleDiagnostic({

            event:
                "replaceTrack-called",

            mediaKind:
                kind,

            sourceType:
                kind === "audio" ? this.#audioSourceType(track) : null,

            peerInstanceId:
                pc.__voicePeerInstanceId || null,

            generation:
                pc.__voiceGeneration || null,

            lifecycleState:
                pc.__voiceLifecycleState || null,

            operationGeneration:
                Number(pc.__voiceOperationGeneration || 0),

            trackListenerCount:
                pc.__voiceTrackListeners?.length || 0,

            previousTrack:
                this.#trackSnapshot(sender.track),

            nextTrack:
                this.#trackSnapshot(track),

            sender:
                this.#senderSnapshot(sender),

            transceiver:
                this.#transceiverSnapshot(
                    transceiver,
                    this.#transceiverIndex(
                        pc,
                        transceiver
                    )
                )

        });

        try {

            await sender.replaceTrack(track);

            if (!this.#isActivePeer(pc)) {

                return SIGNAL_OUTCOME.STALE_GENERATION;

            }

            this.#recordVideoLifecycleDiagnostic({

                event:
                "replaceTrack-complete",

                mediaKind:
                    kind,

                sourceType:
                    kind === "audio" ? this.#audioSourceType(sender.track) : null,

                peerInstanceId:
                    pc.__voicePeerInstanceId || null,

                generation:
                    pc.__voiceGeneration || null,

                sender:
                    this.#senderSnapshot(sender),

                ...this.#peerSnapshot(pc)

            });

            if (kind === "audio") {

                this.#recordAudioPathDiagnostic({

                    event:
                        "audio-sender-track-confirmed",

                    remoteParticipantId:
                        pc.__voiceRemoteParticipantId || null,

                    peerInstanceId:
                        pc.__voicePeerInstanceId || null,

                    generation:
                        pc.__voiceGeneration || null,

                    negotiationId:
                        pc.__voicePendingLocalOffer || null,

                    transceiverIndex:
                        this.#transceiverIndex(
                            pc,
                            transceiver
                        ),

                    trackId:
                        sender.track?.id || null,

                    sourceType:
                        this.#audioSourceType(sender.track),

                    intendedTrack:
                        this.#trackSnapshot(track),

                    sender:
                        this.#senderSnapshot(sender),

                    senderTrackMatchesIntended:
                        sender.track === track

                });

                this.#scheduleTransportRtpProbe(
                    pc,
                    "after-sender-confirmation",
                    {

                        transceiverIndex:
                            this.#transceiverIndex(
                                pc,
                                transceiver
                            ),

                        trackId:
                            sender.track?.id || null

                    }
                );

            }

        } catch (error) {

            this.#recordVideoLifecycleDiagnostic({

                event:
                    "replaceTrack-failed",

                mediaKind:
                    kind,

                peerInstanceId:
                    pc.__voicePeerInstanceId || null,

                generation:
                    pc.__voiceGeneration || null,

                message:
                    error?.message || String(error),

                ...this.#peerSnapshot(pc)

            });

            throw new VoiceNegotiationError(
                "replace-track",
                `Failed to attach the local ${kind} track to its canonical transceiver.`,
                {

                    cause:
                        error?.message || String(error),

                    mediaKind:
                        kind,

                    ...this.#peerSnapshot(pc)

                }
            );

        }

    }

    #enqueuePeerOperation(pc, operation, callback) {

        if (!pc) {

            return Promise.reject(new VoiceNegotiationError(
                operation,
                "Peer operation requested without an active peer."
            ));

        }

        const operationGeneration =
            Number(pc.__voiceOperationGeneration || 0);

        const isCurrent =
            () => Boolean(
                this.#isActivePeer(pc) &&
                Number(pc.__voiceOperationGeneration || 0) === operationGeneration
            );

        const run = async () => {

            if (!isCurrent()) {

                this.#recordNegotiationDiagnostic({

                    event:
                        "peer-operation-stale-generation-skipped",

                    operation,

                    activePeerInstanceId:
                        this.#peers.get(Number(pc.__voiceRemoteParticipantId))?.__voicePeerInstanceId || null,

                    ...this.#peerSnapshot(pc)

                });

                return SIGNAL_OUTCOME.STALE_GENERATION;

            }

            this.#recordNegotiationDiagnostic({

                event:
                    "peer-operation-start",

                operation,

                ...this.#peerSnapshot(pc)

            });

            const result =
                await callback(Object.freeze({
                    generation: operationGeneration,
                    isCurrent
                }));

            if (!isCurrent()) {

                this.#recordNegotiationDiagnostic({
                    event: "peer-operation-completion-stale-generation-skipped",
                    operation,
                    operationGeneration,
                    ...this.#peerSnapshot(pc)
                });

                return SIGNAL_OUTCOME.STALE_GENERATION;

            }

            this.#recordNegotiationDiagnostic({

                event:
                    "peer-operation-complete",

                operation,

                ...this.#peerSnapshot(pc)

            });

            return result;

        };

        const result =
            pc.__voiceOperationTail.then(run, run);

        pc.__voiceOperationTail =
            result.catch(() => {});

        return result;

    }

    async #requestPeerNegotiation(participantId, pc, reason, options = {}) {

        if (!pc) return SIGNAL_OUTCOME.DEFERRED;

        pc.__voicePendingNegotiation =
            true;

        return this.#enqueuePeerOperation(
            pc,
            `negotiate:${reason}`,
            async () => {

                await this.#establishLocalTrackOwnership(pc);

                if (!this.#ownsOffer(participantId)) {

                    await this.#requestOffererRenegotiation(
                        participantId,
                        pc,
                        reason,
                        options
                    );

                    pc.__voicePendingNegotiation =
                        false;

                    return SIGNAL_OUTCOME.CONSUMED;

                }

                if (pc.signalingState !== "stable") {

                    return SIGNAL_OUTCOME.DEFERRED;

                }

                await this.#makePeerOffer(participantId, pc);
                pc.__voicePendingNegotiation =
                    false;

                return SIGNAL_OUTCOME.CONSUMED;

            }
        );

    }

    async #requestOffererRenegotiation(participantId, pc, reason, options = {}) {

        const requestSignature =
            this.#localMediaNegotiationSignature(pc);

        const pendingRequest =
            pc.__voicePendingRenegotiationRequest || null;

        if (pendingRequest?.signature === requestSignature) {

            this.#recordNegotiationDiagnostic({

                event:
                    "renegotiation-request-coalesced",

                requestId:
                    pendingRequest.requestId,

                mediaReason:
                    options.mediaReason || reason,

                webcamOperation:
                    options.webcamOperation || null,

                authoritativeOffererParticipantId:
                    Number(participantId),

                ...this.#peerSnapshot(pc)

            });

            return SIGNAL_OUTCOME.DUPLICATE;

        }

        const requestId =
            this.#nextRenegotiationRequestId(participantId, pc);

        pc.__voicePendingRenegotiationRequest = {

            requestId,

            signature:
                requestSignature

        };

        this.#recordNegotiationDiagnostic({

            event:
                "renegotiation-request-accepted",

            requestId,

            mediaReason:
                options.mediaReason || reason,

            webcamOperation:
                options.webcamOperation || null,

            authoritativeOffererParticipantId:
                Number(participantId),

            finalVideoTransceiverDirection:
                pc.__voiceTransceivers?.video?.direction || null,

            finalLocalSenderTrackId:
                pc.__voiceTransceivers?.video?.sender?.track?.id || null,

            ...this.#peerSnapshot(pc)

        });

        try {

            await this.#sendSignal(
                participantId,
                "renegotiate",
                {

                    reason,

                    media_reason:
                        options.mediaReason || reason,

                    webcam_operation:
                        options.webcamOperation || null,

                    request_id:
                        requestId,

                    media_signature:
                        requestSignature,

                    peer_instance_id:
                        pc.__voicePeerInstanceId,

                    generation:
                        pc.__voiceGeneration,

                    target_peer_instance_id:
                        pc.__voiceRemotePeerInstanceId || null

                },
                {

                    generation:
                        pc.__voiceGeneration,

                    peerInstanceId:
                        pc.__voicePeerInstanceId,

                    targetPeerInstanceId:
                        pc.__voiceRemotePeerInstanceId || null

                }
            );

        } catch (error) {

            if (pc.__voicePendingRenegotiationRequest?.requestId === requestId) {

                pc.__voicePendingRenegotiationRequest =
                    null;

            }

            throw error;

        }

        return SIGNAL_OUTCOME.CONSUMED;

    }

    async #establishLocalTrackOwnership(pc) {

        if (!pc.__voiceTransceivers) {

            if (pc.__voiceRole !== "offerer") {

                throw new VoiceNegotiationError(
                    "establish-local-track-ownership",
                    "Answerer transceivers must be adopted from the remote offer before local tracks are attached.",
                    this.#peerSnapshot(pc)
                );

            }

            this.#reserveOffererTransceivers(pc);

        }

        await this.#syncPeerLocalTracks(pc);

    }

    async #makePeerOffer(participantId, pc = this.#peers.get(Number(participantId))) {

        if (
            !pc ||
            pc.signalingState !== "stable" ||
            !this.#ownsOffer(participantId)
        ) {

            this.#recordNegotiationDiagnostic({

                event:
                    "offer-skipped",

                remoteParticipantId:
                    Number(participantId),

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                signalingState:
                    pc?.signalingState || null,

                ownsOffer:
                    this.#ownsOffer(participantId)

            });

            return;

        }

        pc.__makingOffer =
            true;

        pc.__voiceLifecycleState =
            "offering";

        const negotiationId =
            this.#nextNegotiationId(
                participantId,
                pc
            );

        try {

            await this.#establishLocalTrackOwnership(pc);

            this.#recordNegotiationDiagnostic({

                event:
                    "create-offer-before",

                remoteParticipantId:
                    Number(participantId),

                peerInstanceId:
                    pc.__voicePeerInstanceId,

                generation:
                    pc.__voiceGeneration,

                negotiationId,

                signalingState:
                    pc.signalingState,

                connectionState:
                    pc.connectionState,

                iceConnectionState:
                    pc.iceConnectionState

            });

            const offer =
                await pc.createOffer();

            if (!this.#isActivePeer(pc)) return SIGNAL_OUTCOME.STALE_GENERATION;

            this.#recordNegotiationDiagnostic({

                event:
                    "create-offer-after",

                remoteParticipantId:
                    Number(participantId),

                peerInstanceId:
                    pc.__voicePeerInstanceId,

                generation:
                    pc.__voiceGeneration,

                negotiationId,

                signalingState:
                    pc.signalingState

            });

            await pc.setLocalDescription(
                offer
            );

            if (!this.#isActivePeer(pc)) return SIGNAL_OUTCOME.STALE_GENERATION;

            this.#reconcileCanonicalTransceivers(
                pc,
                pc.localDescription,
                "local-offer"
            );

            this.#recordNegotiationDiagnostic({

                event:
                    "set-local-offer-after",

                remoteParticipantId:
                    Number(participantId),

                peerInstanceId:
                    pc.__voicePeerInstanceId,

                generation:
                    pc.__voiceGeneration,

                negotiationId,

                signalingState:
                    pc.signalingState

            });

            pc.__voicePendingLocalOffer =
                negotiationId;

            this.#scheduleTransportRtpProbe(
                pc,
                "after-local-offer",
                {

                    negotiationId

                }
            );

            await this.#sendSignal(
                participantId,
                "offer",
                pc.localDescription,
                {

                    negotiationId,

                    generation:
                        pc.__voiceGeneration,

                    peerInstanceId:
                        pc.__voicePeerInstanceId,

                    targetPeerInstanceId:
                        pc.__voiceRemotePeerInstanceId || null

                }
            );

            if (this.#isActivePeer(pc)) {

                pc.__voiceLifecycleState =
                    "connecting";

            }

        } catch (error) {

            this.#recoverFailedPeer(pc, "create-local-offer", error);
            throw error;

        } finally {

            pc.__makingOffer =
                false;

        }

    }

    #createPeer(id, role) {

        if (this.#peers.has(id)) {

            return this.#peers.get(id);

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
            this.#isPolitePeer(id);

        pc.__voiceRole =
            role;

        pc.__voicePeerInstanceId =
            this.#nextPeerInstanceId(id);

        pc.__voiceRemoteParticipantId =
            id;

        pc.__voiceGeneration =
            pc.__voicePeerInstanceId;

        pc.__voiceLifecycleState =
            "creating";

        pc.__voiceOperationGeneration =
            1;

        pc.__voiceTrackListeners =
            [];

        pc.__voicePendingLocalOffer =
            null;

        pc.__voiceAppliedRemoteAnswer =
            null;

        pc.__voiceRemotePeerInstanceId =
            null;

        pc.__voiceOperationTail =
            Promise.resolve();

        pc.__voicePendingNegotiation =
            false;

        pc.__voicePendingRenegotiationRequest =
            null;

        pc.__voicePendingIceSignals =
            [];

        pc.__voiceAdoptingRemoteDescription =
            false;

        pc.__voiceDeferredTrackEvents =
            [];

        pc.__voiceTransceivers =
            null;

        this.#peers.set(
            id,
            pc
        );

        this.#remoteWebcamReadinessRequests.delete(id);

        this.#recordVideoLifecycleDiagnostic({

            event:
                "peer-created",

            remoteParticipantId:
                id,

            ...this.#peerSnapshot(pc)

        });

        pc.ontrack =
            event => this.#handlePeerTrack(id, event, pc);

        pc.onicecandidate =
            event => {

                if (!this.#isActivePeer(pc)) return;

                if (event.candidate?.candidate === "") {

                    this.#recordNegotiationDiagnostic({

                        event:
                            "ice-gathering-complete",

                        remoteParticipantId:
                            id,

                        peerInstanceId:
                            pc.__voicePeerInstanceId,

                        generation:
                            pc.__voiceGeneration,

                        remotePeerInstanceId:
                            pc.__voiceRemotePeerInstanceId || null

                    });

                    return;

                }

                if (event.candidate) {

                    this.#recordNegotiationDiagnostic({

                        event:
                            "ice-candidate-sent",

                        remoteParticipantId:
                            id,

                        peerInstanceId:
                            pc.__voicePeerInstanceId,

                        generation:
                            pc.__voiceGeneration,

                        remotePeerInstanceId:
                            pc.__voiceRemotePeerInstanceId || null,

                        candidate:
                            this.#iceCandidateSnapshot(event.candidate)

                    });

                    this.#sendSignal(
                        id,
                        "ice",
                        event.candidate,
                        {

                            generation:
                                pc.__voiceGeneration,

                            peerInstanceId:
                                pc.__voicePeerInstanceId,

                            targetPeerInstanceId:
                                pc.__voiceRemotePeerInstanceId || null

                        }
                    ).catch(error => {

                        this.#recordCriticalFailure(
                            pc,
                            error,
                            {

                                operation:
                                    "send-ice-candidate"

                            }
                        );

                    });

                }

            };

        pc.onconnectionstatechange =
            () => {

                if (!this.#isActivePeer(pc)) return;

                if (pc.connectionState === "connected") {

                    pc.__voiceLifecycleState = "connected";

                } else if (pc.connectionState === "failed") {

                    pc.__voiceLifecycleState = "failed";

                } else if (pc.connectionState === "connecting") {

                    pc.__voiceLifecycleState = "connecting";

                }

                this.#recordTransportStateDiagnostic(
                    pc,
                    "connectionstatechange"
                );

                this.#scheduleTransportRtpProbe(
                    pc,
                    `connection-${pc.connectionState || "unknown"}`
                );

            };

        pc.oniceconnectionstatechange =
            () => {

                if (!this.#isActivePeer(pc)) return;

                this.#recordTransportStateDiagnostic(
                    pc,
                    "iceconnectionstatechange"
                );

                this.#scheduleTransportRtpProbe(
                    pc,
                    `ice-${pc.iceConnectionState || "unknown"}`
                );

            };

        pc.onicegatheringstatechange =
            () => {

                if (!this.#isActivePeer(pc)) return;

                this.#recordTransportStateDiagnostic(
                    pc,
                    "icegatheringstatechange"
                );

            };

        pc.onnegotiationneeded =
            () => {

                if (!this.#isActivePeer(pc)) return;

                pc.__voicePendingNegotiation =
                    true;

                this.#requestPeerNegotiation(
                    id,
                    pc,
                    "onnegotiationneeded"
                ).catch(error => this.#recordCriticalFailure(
                    pc,
                    error,
                    {

                        operation:
                            "onnegotiationneeded"

                    }
                ));

            };

        return pc;

    }

    async #acceptOfferSignal(signal) {

        const from =
            Number(signal.from_participant_id);

        const description =
            this.#incomingSessionDescription("offer", signal.data);

        if (!description) return SIGNAL_OUTCOME.TERMINAL_INVALID;

        let pc =
            this.#peers.get(from) || null;

        if (!pc) {

            pc =
                this.#createPeer(from, "answerer");

        }

        if (!this.#signalTargetsPeer(pc, signal)) {

            return SIGNAL_OUTCOME.STALE_GENERATION;

        }

        return this.#enqueuePeerOperation(
            pc,
            `accept-offer:${signal.id}`,
            async () => {

                pc.__voiceLifecycleState =
                    "answering";

                const offerCollision =
                    pc.__makingOffer || pc.signalingState !== "stable";

                if (offerCollision) {

                    if (!pc.__polite) {

                        return SIGNAL_OUTCOME.STALE_GENERATION;

                    }

                    try {

                        await pc.setLocalDescription({ type: "rollback" });

                        if (!this.#isActivePeer(pc)) {

                            return SIGNAL_OUTCOME.STALE_GENERATION;

                        }

                    } catch (error) {

                        this.#recoverFailedPeer(pc, "rollback-collision", error);

                        throw new VoiceNegotiationError(
                            "rollback-collision",
                            "Failed to roll back a colliding local offer.",
                            {

                                cause:
                                    error?.message || String(error),

                                signalId:
                                    signal.id,

                                ...this.#peerSnapshot(pc)

                            }
                        );

                    }

                }

                try {

                    pc.__voiceAdoptingRemoteDescription =
                        true;

                    await pc.setRemoteDescription(description);

                    if (!this.#isActivePeer(pc)) {

                        return SIGNAL_OUTCOME.STALE_GENERATION;

                    }

                    pc.__voiceRemotePeerInstanceId =
                        signal.data?.peer_instance_id || null;

                    this.#reconcileCanonicalTransceivers(
                        pc,
                        pc.remoteDescription,
                        "remote-offer"
                    );

                    pc.__voiceAdoptingRemoteDescription =
                        false;

                    this.#drainDeferredTrackEvents(pc);

                    await this.#syncPeerLocalTracks(pc);

                    if (!this.#isActivePeer(pc)) {

                        return SIGNAL_OUTCOME.STALE_GENERATION;

                    }

                    const completedRequest =
                        pc.__voicePendingRenegotiationRequest || null;

                    if (completedRequest) {

                        this.#recordNegotiationDiagnostic({

                            event:
                                "renegotiation-request-offer-received",

                            requestId:
                                completedRequest.requestId,

                            offerNegotiationId:
                                signal.data?.negotiation_id || null,

                            finalVideoTransceiverDirection:
                                pc.__voiceTransceivers?.video?.direction || null,

                            finalLocalSenderTrackId:
                                pc.__voiceTransceivers?.video?.sender?.track?.id || null,

                            finalRemoteReceiverTrackId:
                                pc.__voiceTransceivers?.video?.receiver?.track?.id || null,

                            ...this.#peerSnapshot(pc)

                        });

                        pc.__voicePendingRenegotiationRequest =
                            null;

                    }

                    this.#recordNegotiationDiagnostic({

                        event:
                            "set-remote-offer-after",

                        signalId:
                            signal.id,

                        negotiationId:
                            signal.data?.negotiation_id || null,

                        ...this.#peerSnapshot(pc)

                    });

                    const answer =
                        await pc.createAnswer();

                    if (!this.#isActivePeer(pc)) {

                        return SIGNAL_OUTCOME.STALE_GENERATION;

                    }

                    await pc.setLocalDescription(answer);

                    if (!this.#isActivePeer(pc)) {

                        return SIGNAL_OUTCOME.STALE_GENERATION;

                    }

                    this.#reconcileCanonicalTransceivers(
                        pc,
                        pc.localDescription,
                        "local-answer"
                    );

                    await this.#sendSignal(
                        from,
                        "answer",
                        pc.localDescription,
                        {

                            negotiationId:
                                signal.data?.negotiation_id || null,

                            generation:
                                pc.__voiceGeneration,

                            peerInstanceId:
                                pc.__voicePeerInstanceId,

                            targetPeerInstanceId:
                                signal.data?.peer_instance_id || null

                        }
                    );

                    this.#reattachRemoteAudioFromPeer(
                        from,
                        "local-answer-complete"
                    );

                    if (this.#isActivePeer(pc)) {

                        pc.__voiceLifecycleState =
                            "connecting";

                    }

                    this.#scheduleTransportRtpProbe(
                        pc,
                        "after-local-answer",
                        {

                            signalId:
                                signal.id

                        }
                    );

                    return SIGNAL_OUTCOME.CONSUMED;

                } catch (error) {

                    pc.__voiceAdoptingRemoteDescription =
                        false;
                    this.#recoverFailedPeer(pc, "accept-remote-offer", error);
                    throw error;

                }

            }
        );

    }

    async #acceptAnswerSignal(signal) {

        const from =
            Number(signal.from_participant_id);

        const pc =
            this.#peers.get(from) || null;

        if (!pc || pc.__voiceRole !== "offerer") {

            return SIGNAL_OUTCOME.STALE_GENERATION;

        }

        if (!this.#signalTargetsPeer(pc, signal)) {

            return SIGNAL_OUTCOME.STALE_GENERATION;

        }

        const description =
            this.#incomingSessionDescription("answer", signal.data);

        if (!description) return SIGNAL_OUTCOME.TERMINAL_INVALID;

        return this.#enqueuePeerOperation(
            pc,
            `accept-answer:${signal.id}`,
            async () => {

                if (!this.#signalMatchesPeerGeneration(pc, signal)) {

                    return SIGNAL_OUTCOME.STALE_GENERATION;

                }

                if (
                    pc.__voiceAppliedRemoteAnswer === signal.data?.negotiation_id &&
                    signal.data?.negotiation_id
                ) {

                    return SIGNAL_OUTCOME.DUPLICATE;

                }

                if (pc.signalingState !== "have-local-offer") {

                    return pc.__voicePendingLocalOffer ?
                        SIGNAL_OUTCOME.DEFERRED :
                        SIGNAL_OUTCOME.STALE_GENERATION;

                }

                if (
                    pc.__voicePendingLocalOffer &&
                    signal.data?.negotiation_id &&
                    pc.__voicePendingLocalOffer !== signal.data.negotiation_id
                ) {

                    return SIGNAL_OUTCOME.STALE_GENERATION;

                }

                try {

                    await pc.setRemoteDescription(description);

                    if (!this.#isActivePeer(pc)) {

                        return SIGNAL_OUTCOME.STALE_GENERATION;

                    }

                    pc.__voiceRemotePeerInstanceId =
                        signal.data?.peer_instance_id || null;

                    this.#reconcileCanonicalTransceivers(
                        pc,
                        pc.remoteDescription,
                        "remote-answer"
                    );

                    this.#recordNegotiationDiagnostic({

                        event:
                            "set-remote-answer-after",

                        signalId:
                            signal.id,

                        negotiationId:
                            signal.data?.negotiation_id || null,

                        ...this.#peerSnapshot(pc)

                    });

                    pc.__voiceAppliedRemoteAnswer =
                        signal.data?.negotiation_id || signal.id;

                    pc.__voicePendingLocalOffer =
                        null;

                    this.#reattachRemoteAudioFromPeer(
                        from,
                        "remote-answer-complete"
                    );

                    this.#scheduleTransportRtpProbe(
                        pc,
                        "after-remote-answer",
                        {

                            signalId:
                                signal.id

                        }
                    );

                    this.#schedulePendingPeerNegotiation(pc);

                    return SIGNAL_OUTCOME.CONSUMED;

                } catch (error) {

                    this.#recoverFailedPeer(pc, "accept-remote-answer", error);
                    throw error;

                }

            }
        );

    }

    async #acceptIceSignal(signal) {

        const from =
            Number(signal.from_participant_id);

        const pc =
            this.#peers.get(from) || null;

        if (!pc) return SIGNAL_OUTCOME.DEFERRED;

        if (!this.#signalTargetsPeer(pc, signal)) {

            return SIGNAL_OUTCOME.STALE_GENERATION;

        }

        if (
            !pc.remoteDescription ||
            !pc.__voiceRemotePeerInstanceId
        ) {

            return SIGNAL_OUTCOME.DEFERRED;

        }

        if (!this.#signalMatchesPeerGeneration(pc, signal)) {

            return SIGNAL_OUTCOME.STALE_GENERATION;

        }

        const candidate =
            this.#incomingIceCandidate(signal.data);

        if (!candidate) return SIGNAL_OUTCOME.TERMINAL_INVALID;

        return this.#enqueuePeerOperation(
            pc,
            `accept-ice:${signal.id}`,
            async () => {

                if (!pc.remoteDescription) return SIGNAL_OUTCOME.DEFERRED;

                try {

                    await pc.addIceCandidate(candidate);

                    if (!this.#isActivePeer(pc)) {

                        return SIGNAL_OUTCOME.STALE_GENERATION;

                    }

                    this.#recordNegotiationDiagnostic({

                        event:
                            "ice-candidate-applied",

                        signalId:
                            signal.id,

                        ...this.#peerSnapshot(pc)

                    });

                    return SIGNAL_OUTCOME.CONSUMED;

                } catch (error) {

                    throw new VoiceNegotiationError(
                        "add-ice-candidate",
                        "Failed to apply a generation-matched ICE candidate.",
                        {

                            cause:
                                error?.message || String(error),

                            signalId:
                                signal.id,

                            ...this.#peerSnapshot(pc)

                        }
                    );

                }

            }
        );

    }

    #signalTargetsPeer(pc, signal) {

        const targetPeerInstanceId =
            signal?.data?.target_peer_instance_id || null;

        if (!targetPeerInstanceId) return true;

        return targetPeerInstanceId === pc?.__voicePeerInstanceId;

    }

    #schedulePendingPeerNegotiation(pc) {

        if (!pc?.__voicePendingNegotiation || pc.signalingState !== "stable") return;

        Promise.resolve().then(() => this.#requestPeerNegotiation(
            pc.__voiceRemoteParticipantId,
            pc,
            "pending-after-stable"
        )).catch(error => this.#recordCriticalFailure(
            pc,
            error,
            { operation: "pending-after-stable" }
        ));

    }

    #parseSdpMediaSections(description) {

        const sdp =
            description?.sdp;

        if (typeof sdp !== "string") return [];

        const sections =
            [];

        let current =
            null;

        for (const line of sdp.replace(/\r\n/g, "\n").split("\n")) {

            if (line.startsWith("m=")) {

                current = {

                    kind:
                        line.slice(2).split(/\s+/)[0],

                    index:
                        sections.length,

                    mid:
                        null

                };

                sections.push(current);

            } else if (current && line.startsWith("a=mid:")) {

                current.mid =
                    line.slice(6).trim();

            }

        }

        return sections;

    }

    #reconcileCanonicalTransceivers(pc, description, source) {

        const sections =
            this.#parseSdpMediaSections(description).filter(section =>
                section.kind === "audio" || section.kind === "video"
            );

        const transceivers =
            pc.getTransceivers();

        const next = {

            audio:
                null,

            video:
                null,

            fallback:
                false

        };

        for (const kind of ["audio", "video"]) {

            const mediaSections =
                sections.filter(section => section.kind === kind);

            if (mediaSections.length !== 1 || mediaSections[0].mid === null) {

                throw new VoiceNegotiationError(
                    "reconcile-transceivers",
                    `Expected exactly one assigned ${kind} SDP media section.`,
                    {

                        source,

                        sections,

                        ...this.#peerSnapshot(pc)

                    }
                );

            }

            const matches =
                transceivers.filter(transceiver =>
                    String(transceiver.mid) === String(mediaSections[0].mid)
                );

            if (matches.length !== 1) {

                throw new VoiceNegotiationError(
                    "reconcile-transceivers",
                    `The ${kind} SDP mid does not identify one browser transceiver.`,
                    {

                        source,

                        mediaSection:
                            mediaSections[0],

                        matchingTransceiverCount:
                            matches.length,

                        ...this.#peerSnapshot(pc)

                    }
                );

            }

            next[kind] =
                matches[0];

            const previous =
                pc.__voiceTransceivers?.[kind] || null;

            if (
                previous &&
                previous !== matches[0] &&
                previous.mid !== null
            ) {

                throw new VoiceNegotiationError(
                    "reconcile-transceivers",
                    `Canonical ${kind} transceiver identity changed after negotiation.`,
                    {

                        source,

                        previousMid:
                            previous.mid,

                        nextMid:
                            matches[0].mid,

                        ...this.#peerSnapshot(pc)

                    }
                );

            }

        }

        const canonical =
            new Set([next.audio, next.video]);

        const unassociated =
            transceivers.filter(transceiver =>
                !canonical.has(transceiver) &&
                (
                    transceiver.mid !== null ||
                    transceiver.sender?.track ||
                    transceiver.receiver?.track?.readyState === "live"
                )
            );

        if (unassociated.length > 0) {

            throw new VoiceNegotiationError(
                "reconcile-transceivers",
                "Additional active transceivers are not associated with the canonical SDP media sections.",
                {

                    source,

                    unassociatedCount:
                        unassociated.length,

                    ...this.#peerSnapshot(pc)

                }
            );

        }

        pc.__voiceTransceivers =
            next;

        this.#recordNegotiationDiagnostic({

            event:
                "canonical-transceivers-reconciled",

            source,

            sections,

            audioIdentityVerified:
                pc.getTransceivers().includes(next.audio),

            videoIdentityVerified:
                pc.getTransceivers().includes(next.video),

            ...this.#peerSnapshot(pc)

        });

        return next;

    }

    #drainDeferredTrackEvents(pc) {

        const events =
            pc.__voiceDeferredTrackEvents.splice(0);

        for (const event of events) {

            this.#handlePeerTrack(
                pc.__voiceRemoteParticipantId,
                event,
                pc
            );

        }

    }

    #handlePeerTrack(id, event, eventPeer = null) {

        const track =
            event.track;

        const pc =
            eventPeer ||
            this.#peers.get(
                Number(id)
            );

        if (!this.#isActivePeer(pc)) {

            this.#recordAudioPathDiagnostic({

                event:
                    "ontrack-stale-peer-skipped",

                remoteParticipantId:
                    Number(id),

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                activePeerInstanceId:
                    this.#peers.get(Number(id))?.__voicePeerInstanceId || null,

                track:
                    this.#trackSnapshot(track)

            });

            return;

        }

        if (pc.__voiceAdoptingRemoteDescription && !pc.__voiceTransceivers) {

            pc.__voiceDeferredTrackEvents.push(event);

            this.#recordVideoLifecycleDiagnostic({

                event:
                    "ontrack-deferred-for-transceiver-reconciliation",

                remoteParticipantId:
                    Number(id),

                track:
                    this.#trackSnapshot(track),

                ...this.#peerSnapshot(pc)

            });

            return;

        }

        const canonicalTransceiver =
            pc.__voiceTransceivers?.[track.kind] || null;

        if (
            !event.transceiver ||
            event.transceiver !== canonicalTransceiver ||
            event.transceiver.mid === null ||
            !pc.getTransceivers().includes(event.transceiver)
        ) {

            const error =
                new VoiceNegotiationError(
                    "remote-track-ownership",
                    `Remote ${track.kind} track arrived on a non-canonical transceiver.`,
                    {

                        eventTransceiverMid:
                            event.transceiver?.mid ?? null,

                        canonicalTransceiverMid:
                            canonicalTransceiver?.mid ?? null,

                        sameObject:
                            event.transceiver === canonicalTransceiver,

                        ...this.#peerSnapshot(pc)

                    }
                );

            this.#recoverFailedPeer(pc, "remote-track-ownership", error);
            return;

        }

        this.#recordVideoLifecycleDiagnostic({

            event:
                "remote-track-canonical-ownership-verified",

            remoteParticipantId:
                Number(id),

            mediaKind:
                track.kind,

            transceiverMid:
                event.transceiver.mid,

            eventTransceiverIsCanonical:
                event.transceiver === canonicalTransceiver,

            senderIsCanonical:
                event.transceiver.sender === canonicalTransceiver.sender,

            receiverIsCanonical:
                event.transceiver.receiver === canonicalTransceiver.receiver,

            ...this.#peerSnapshot(pc)

        });

        this.#recordVideoLifecycleDiagnostic({

            event:
                "ontrack",

            remoteParticipantId:
                Number(id),

            track:
                this.#trackSnapshot(track),

            streams:
                event.streams?.map(stream => ({

                    trackCount:
                        stream.getTracks?.().length || 0,

                    videoTrackCount:
                        stream.getVideoTracks?.().length || 0,

                    audioTrackCount:
                        stream.getAudioTracks?.().length || 0

                })) || [],

            ...this.#peerSnapshot(pc)

        });

        if (track.kind === "video") {

            this.#listenToPeerTrack(
                pc,
                track,
                "mute",
                () => this.#recordVideoLifecycleDiagnostic({

                    event:
                        "remote-video-track-mute",

                    remoteParticipantId:
                        Number(id),

                    track:
                        this.#trackSnapshot(track),

                    ...this.#peerSnapshot(pc)

                })
            );

            this.#listenToPeerTrack(
                pc,
                track,
                "unmute",
                () => this.#recordVideoLifecycleDiagnostic({

                    event:
                        "remote-video-track-unmute",

                    remoteParticipantId:
                        Number(id),

                    track:
                        this.#trackSnapshot(track),

                    ...this.#peerSnapshot(pc)

                })
            );

            this.#listenToPeerTrack(
                pc,
                track,
                "ended",
                () => this.#recordVideoLifecycleDiagnostic({

                    event:
                        "remote-video-track-ended",

                    remoteParticipantId:
                        Number(id),

                    reason:
                        "track-ended-event",

                    track:
                        this.#trackSnapshot(track),

                    ...this.#peerSnapshot(pc)

                })
            );

            const stream =
                this.#canonicalRemoteVideoStream(
                    pc,
                    canonicalTransceiver,
                    track,
                    event.streams?.[0] || null
                );

            const presentation =
                this.#remoteVideoPresentations.get(Number(id));

            this.#context?.attachParticipantVideo?.(
                id,
                stream,
                false,
                {
                    source: "canonical-ontrack",
                    peerInstanceId: pc.__voicePeerInstanceId || null,
                    generation: pc.__voiceGeneration || null,
                    receiverIdentity: presentation?.receiverIdentity || null,
                    streamIdentity: presentation?.streamIdentity || null,
                    remoteTrackId: track.id || null
                }
            );

            this.#recordVideoLifecycleDiagnostic({

                event:
                    "attachParticipantVideo-dispatched",

                remoteParticipantId:
                    Number(id),

                track:
                    this.#trackSnapshot(track),

                streamTrackCount:
                    stream.getTracks?.().length || 0,

                receiverIdentity:
                    presentation?.receiverIdentity || null,

                streamIdentity:
                    presentation?.streamIdentity || null,

                ...this.#peerSnapshot(pc)

            });

            this.#listenToPeerTrack(
                pc,
                track,
                "ended",
                () => {

                    if (!this.#isActivePeer(pc)) return;

                    this.#recordVideoLifecycleDiagnostic({

                        event:
                            "detachParticipantVideo-dispatched",

                        remoteParticipantId:
                            Number(id),

                        reason:
                            "remote-video-track-ended",

                        track:
                            this.#trackSnapshot(track),

                        ...this.#peerSnapshot(pc)

                    });

                    this.#context?.detachParticipantVideo?.(id);

                }
            );

            return;

        }

        if (track.kind !== "audio") return;

        this.#listenToPeerTrack(
            pc,
            track,
            "ended",
            () => {

                const pending =
                    this.#pendingRemoteAudioTracks.get(Number(id));

                if (pending?.track === track && pending?.peer === pc) {

                    this.#pendingRemoteAudioTracks.delete(Number(id));

                }

                this.#releaseRemoteAudioResource(
                    Number(id),
                    "remote-audio-track-ended",
                    pc,
                    track
                );

            },
            { once: true }
        );

        if (!this.#lifecycle.isJoined()) {

            this.#pendingRemoteAudioTracks.set(
                Number(id),
                {

                    track,

                    streams:
                        event.streams || [],

                    peer:
                        pc,

                    peerInstanceId:
                        pc.__voicePeerInstanceId,

                    generation:
                        pc.__voiceGeneration,

                    receiverTrackId:
                        track.id || null,

                    transceiver:
                        event.transceiver

                }
            );

            this.#recordAudioPathDiagnostic({

                event:
                    "remote-audio-pending-unjoined",

                remoteParticipantId:
                    Number(id),

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                trackId:
                    track.id || null,

                track:
                    this.#trackSnapshot(track),

                streams:
                    event.streams?.map(stream => this.#streamSnapshot(stream)) || []

            });

            return;

        }

        this.#attachRemoteAudioTrack(
            id,
            track,
            event.streams || [],
            pc,
            event.transceiver
        );

    }

    #attachPendingRemoteAudioTracks() {

        this.#pendingRemoteAudioTracks.forEach((entry, id) => {

            if (
                !this.#isActivePeer(entry.peer) ||
                entry.peer.__voicePeerInstanceId !== entry.peerInstanceId ||
                entry.peer.__voiceGeneration !== entry.generation ||
                entry.peer.__voiceTransceivers?.audio !== entry.transceiver ||
                entry.transceiver?.receiver?.track !== entry.track ||
                entry.receiverTrackId !== (entry.track?.id || null) ||
                entry.track?.readyState === "ended"
            ) {

                this.#recordAudioPathDiagnostic({

                    event:
                        "pending-remote-audio-stale-skipped",

                    remoteParticipantId:
                        Number(id),

                    peerInstanceId:
                        entry.peerInstanceId || null,

                    generation:
                        entry.generation || null

                });

                return;

            }

            this.#attachRemoteAudioTrack(
                id,
                entry.track,
                entry.streams || [],
                entry.peer,
                entry.transceiver
            );

        });

        this.#pendingRemoteAudioTracks.clear();

    }

    #reattachRemoteAudioFromPeer(participantId, reason) {

        if (!this.#lifecycle.isJoined()) return;

        const id =
            Number(participantId);

        const pc =
            this.#peers.get(id) || null;

        const transceiver =
            pc?.__voiceTransceivers?.audio || null;

        const track =
            transceiver?.receiver?.track || null;

        if (
            !this.#isActivePeer(pc) ||
            !transceiver ||
            !track ||
            track.readyState === "ended"
        ) {

            this.#recordAudioPathDiagnostic({

                event:
                    "remote-audio-reattach-deferred",

                reason,

                remoteParticipantId:
                    id,

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                receiverTrackId:
                    track?.id || null,

                receiverTrackState:
                    track?.readyState || null

            });

            return;

        }

        this.#recordAudioPathDiagnostic({

            event:
                "remote-audio-reattach-preserved-peer",

            reason,

            remoteParticipantId:
                id,

            peerInstanceId:
                pc.__voicePeerInstanceId || null,

            generation:
                pc.__voiceGeneration || null,

            receiverTrackId:
                track.id || null,

            receiverTrackState:
                track.readyState,

            receiverTrackMuted:
                Boolean(track.muted)

        });

        this.#attachRemoteAudioTrack(
            id,
            track,
            [],
            pc,
            transceiver
        );

    }

    #attachRemoteAudioTrack(id, track, streams = [], eventPeer = null, transceiver = null) {

        const pc =
            eventPeer || this.#peers.get(Number(id));

        if (
            !this.#isActivePeer(pc) ||
            pc.__voiceTransceivers?.audio !== transceiver
        ) {

            this.#recordAudioPathDiagnostic({

                event:
                    "remote-audio-attachment-stale-skipped",

                remoteParticipantId:
                    Number(id),

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                transceiverMid:
                    transceiver?.mid ?? null

            });

            return;

        }

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

        const existingResource =
            this.#remoteAudioResources.get(Number(id)) || null;

        if (
            existingResource &&
            (
                existingResource.peer !== pc ||
                existingResource.track !== track ||
                existingResource.transceiver !== transceiver
            )
        ) {

            this.#releaseRemoteAudioResource(
                Number(id),
                "remote-track-replacement",
                existingResource.peer,
                existingResource.track,
                { preserveElement: true }
            );

        }

        const nextStream =
            new MediaStreamClass([track]);

        if (audio.srcObject && audio.srcObject !== nextStream) {
            this.#markAudioPlaybackInterruption(
                audio,
                "remote-track-replacement"
            );
        }

        audio.srcObject = nextStream;

        const resource = {
            participantId: Number(id),
            peer: pc,
            peerInstanceId: pc.__voicePeerInstanceId || null,
            generation: pc.__voiceGeneration || null,
            transceiver,
            receiverTrackId: track.id || null,
            track,
            audio,
            stream: nextStream
        };

        this.#remoteAudioResources.set(Number(id), resource);
        this.#pendingRemoteAudioTracks.delete(Number(id));

        this.#recordAudioPathDiagnostic({

            event:
                "remote-audio-srcObject-set",

            remoteParticipantId:
                Number(id),

            peerInstanceId:
                pc?.__voicePeerInstanceId || null,

            generation:
                pc?.__voiceGeneration || null,

            negotiationId:
                pc?.__voicePendingLocalOffer || null,

            trackId:
                track.id || null,

            track:
                this.#trackSnapshot(track),

            audioElementId:
                audio.id || null,

            muted:
                audio.muted,

            volume:
                audio.volume,

            sinkId:
                audio.sinkId || null,

            srcObject:
                this.#streamSnapshot(audio.srcObject)

        });

        this.applyAudioOutput(
            audio
        ).then(() => {

            if (!this.#isRemoteAudioResourceCurrent(resource)) return;

            this.#recordAudioPathDiagnostic({

                event:
                    "remote-audio-output-applied",

                remoteParticipantId:
                    Number(id),

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                trackId:
                    track.id || null,

                sinkId:
                    audio.sinkId || null

            });

        });

        const playSequence =
            this.#beginAudioPlayback(audio);

        audio.play?.().then(() => {

            if (!this.#isRemoteAudioResourceCurrent(resource)) {

                this.#finishAudioPlayback(audio, playSequence);
                return;

            }

            this.#finishAudioPlayback(
                audio,
                playSequence
            );

            this.#recordAudioPathDiagnostic({

                event:
                    "remote-audio-play-resolved",

                remoteParticipantId:
                    Number(id),

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                trackId:
                    track.id || null,

                paused:
                    audio.paused,

                muted:
                    audio.muted,

                volume:
                    audio.volume,

                sinkId:
                    audio.sinkId || null

            });

        }).catch(error => {

            const interruptionReason =
                this.#finishAudioPlayback(
                    audio,
                    playSequence
                );

            const intentionalAbort =
                error?.name === "AbortError" && Boolean(interruptionReason);

            if (!this.#isRemoteAudioResourceCurrent(resource) && intentionalAbort) {

                return;

            }

            this.#recordAudioPathDiagnostic({

                event:
                    intentionalAbort
                        ? "remote-audio-play-aborted-intentionally"
                        : "remote-audio-play-rejected",

                remoteParticipantId:
                    Number(id),

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                trackId:
                    track.id || null,

                message:
                    error?.message || String(error),

                name:
                    error?.name || null,

                interruptionReason

            });

            if (!intentionalAbort) {
                this.#context?.warn?.(error);
            }

        });

        this.#scheduleTransportRtpProbe(
            pc,
            "after-remote-audio-attach",
            {

                remoteParticipantId:
                    Number(id),

                trackId:
                    track.id || null

            }
        );

    }

    #sendSignal(toId, type, data, metadata = {}) {

        const payload =
            this.#outgoingSignalPayload(
                type,
                data,
                metadata
            );

        if (!payload) {

            this.#warn(
                new Error(`Skipped malformed outgoing ${type} media signal.`)
            );

            return Promise.resolve();

        }

        return this.#context?.apiPost?.(
            "/api/media_signal.php",
            {
                action:
                    "signal",

                media:
                    this.#signalMedia(),

                session_id:
                    this.#config()?.sessionId,

                participant_id:
                    this.#config()?.myParticipantId,

                client_epoch:
                    this.#clientEpoch,

                to_id:
                    toId,

                join_token:
                    this.#config()?.myJoinToken,

                type,

                data:
                    payload
            }
        )?.then(result => {

            this.#recordNegotiationDiagnostic({

                event:
                    "signal-persisted",

                signalId:
                    Number(result?.signal_id) || null,

                signalKind:
                    type,

                senderParticipantId:
                    this.#localParticipantId(),

                recipientParticipantId:
                    Number(toId),

                senderEpoch:
                    this.#clientEpoch,

                recipientEpoch:
                    result?.recipient_epoch || null,

                negotiationId:
                    payload.negotiation_id || null,

                peerInstanceId:
                    payload.peer_instance_id || null,

                generation:
                    payload.generation || null

            });

            return result;

        });

    }

    #stageFetchedSignals(signals, signalErrors) {

        const staged = [

            ...signals.map(signal => ({ signal, serverError: null })),
            ...signalErrors.map(serverError => ({

                signal: {

                    id:
                        serverError.id,

                    media:
                        serverError.media,

                    from_participant_id:
                        serverError.from_participant_id,

                    type:
                        serverError.type

                },

                serverError

            }))

        ].sort((left, right) =>
            Number(left.signal?.id) - Number(right.signal?.id)
        );

        for (const entry of staged) {

            const signalId =
                Number(entry.signal?.id);

            if (!signalId) continue;

            if (this.#terminalSignalIds.has(signalId)) {

                this.#recordNegotiationDiagnostic({

                    event:
                        "signal-terminal-duplicate-observed",

                    signalId,

                    signalKind:
                        entry.signal?.type || null

                });

                continue;

            }

            if (!this.#signalInbox.has(signalId)) {

                if (this.#signalInbox.size >= this.#maxSignalInboxSize) {

                    const error =
                        new VoiceNegotiationError(
                            "stage-signal",
                            "Voice signal inbox capacity was exceeded.",
                            {

                                signalId,

                                inboxSize:
                                    this.#signalInbox.size

                            }
                        );

                    this.#recordCriticalFailure(null, error);
                    this.#rememberTerminalSignal(
                        signalId,
                        SIGNAL_OUTCOME.TERMINAL_INVALID
                    );
                    continue;

                }

                this.#signalInbox.set(signalId, {

                    signal:
                        entry.signal,

                    serverError:
                        entry.serverError,

                    attempts:
                        0,

                    outcome:
                        null

                });

            }

        }

    }

    async #drainSignalInbox() {

        if (this.#drainingSignalInbox) return;

        const drainToken =
            this.#lifecycle.beginOperation("signal-drain");

        if (!drainToken) return;

        this.#drainingSignalInbox =
            true;

        try {

            let madeProgress =
                true;

            while (madeProgress) {

                madeProgress =
                    false;

                const entries =
                    [...this.#signalInbox.entries()].sort(
                        ([leftId], [rightId]) => leftId - rightId
                    );

                for (const [signalId, entry] of entries) {

                    if (this.#processingSignalIds.has(signalId)) continue;

                    this.#processingSignalIds.add(signalId);
                    entry.attempts += 1;

                    let outcome =
                        SIGNAL_OUTCOME.RETRYABLE_FAILURE;

                    try {

                        if (entry.serverError) {

                            this.#recordNegotiationDiagnostic({

                                event:
                                    "server-signal-error",

                                signalId,

                                signalKind:
                                    entry.signal?.type || null,

                                serverError:
                                    entry.serverError

                            });

                            outcome =
                                SIGNAL_OUTCOME.TERMINAL_INVALID;

                        } else {

                            outcome =
                                await this.#handleSignal(entry.signal);

                            if (!this.#lifecycle.isCurrent(drainToken)) {

                                return;

                            }

                        }

                    } catch (error) {

                        this.#recordCriticalFailure(
                            this.#peers.get(Number(entry.signal?.from_participant_id)) || null,
                            error,
                            {

                                signalId,

                                signalKind:
                                    entry.signal?.type || null,

                                attempts:
                                    entry.attempts

                            }
                        );

                        outcome =
                            error instanceof VoiceNegotiationError ?
                                SIGNAL_OUTCOME.TERMINAL_INVALID :
                                SIGNAL_OUTCOME.RETRYABLE_FAILURE;

                    } finally {

                        this.#processingSignalIds.delete(signalId);

                    }

                    entry.outcome =
                        outcome;

                    this.#recordNegotiationDiagnostic({

                        event:
                            "signal-processing-outcome",

                        signalId,

                        signalKind:
                            entry.signal?.type || null,

                        outcome,

                        attempts:
                            entry.attempts

                    });

                    if (TERMINAL_SIGNAL_OUTCOMES.has(outcome)) {

                        this.#signalInbox.delete(signalId);
                        this.#rememberTerminalSignal(signalId, outcome);
                        madeProgress =
                            true;

                    } else if (
                        outcome === SIGNAL_OUTCOME.RETRYABLE_FAILURE &&
                        entry.attempts >= this.#maxSignalAttempts
                    ) {

                        this.#signalInbox.delete(signalId);
                        this.#rememberTerminalSignal(
                            signalId,
                            SIGNAL_OUTCOME.TERMINAL_INVALID
                        );
                        madeProgress =
                            true;

                    }

                }

            }

        } finally {

            this.#drainingSignalInbox =
                false;

        }

    }

    #rememberTerminalSignal(signalId, outcome) {

        this.#terminalSignalIds.set(
            Number(signalId),
            outcome
        );

        while (this.#terminalSignalIds.size > this.#maxTerminalSignalHistory) {

            const oldestId =
                this.#terminalSignalIds.keys().next().value;

            this.#terminalSignalIds.delete(oldestId);

        }

    }

    async #handleSignal(signal) {

        if (this.#lifecycle.isDestroyed()) {

            return SIGNAL_OUTCOME.STALE_GENERATION;

        }

        const from =
            Number(signal.from_participant_id);

        if (!from || from === Number(this.#config()?.myParticipantId)) {

            return SIGNAL_OUTCOME.CONSUMED;

        }

        const remote =
            this.#participants().get(
                Number(from)
            );

        const remoteHasWebcam =
            Boolean(remote?.webcam_enabled || remote?.webcam_path);

        const signalHasVideo =
            signal.media === "webcam" ||
            signal.data?.chatspace_media === "video";

        const shouldHandleMedia =
            Boolean(
                this.#lifecycle.isJoined() ||
                this.#webcamStream() ||
                remoteHasWebcam ||
                signalHasVideo
            );

        if (!shouldHandleMedia && signal.type !== "leave") {

            return SIGNAL_OUTCOME.DEFERRED;

        }

        if (signal.type === "leave") {

            this.#removeAudioElement(
                from,
                "remote-leave"
            );

            this.#pendingRemoteAudioTracks.delete(
                from
            );

            if (this.#peers.has(from) && !remoteHasWebcam) {

                const leavingPeer =
                    this.#peers.get(from);

                this.#recordVideoLifecycleDiagnostic({

                    event:
                        "detachParticipantVideo-dispatched",

                    remoteParticipantId:
                        from,

                    reason:
                        "remote-leave-no-webcam",

                    ...this.#peerSnapshot(leavingPeer)

                });

                this.#context?.detachParticipantVideo?.(
                    from
                );

                this.#releasePeer(
                    leavingPeer,
                    "remote-leave-no-webcam"
                );

            }

            return SIGNAL_OUTCOME.CONSUMED;

        }

        if (signal.type === "join") {

            if (!this.#lifecycle.isJoined() && !this.#webcamStream()) {

                return SIGNAL_OUTCOME.DEFERRED;

            }

            await this.connectMediaPeer(from);

            return SIGNAL_OUTCOME.CONSUMED;

        }

        if (signal.type === "offer") {

            return this.#acceptOfferSignal(signal);

        }

        if (signal.type === "renegotiate") {

            if (!this.#ownsOffer(from)) {

                return SIGNAL_OUTCOME.STALE_GENERATION;

            }

            let pc =
                this.#peers.get(from) || null;

            const requestId =
                signal.data?.request_id || null;

            const requestGeneration =
                signal.data?.generation || null;

            const senderEpoch =
                signal.sender_epoch || null;

            const lateJoinReady =
                Boolean(signal.data?.late_join_ready);

            if (
                pc &&
                lateJoinReady &&
                !signal.data?.target_peer_instance_id &&
                pc.signalingState === "have-local-offer" &&
                !pc.__voiceRemotePeerInstanceId
            ) {

                this.#recordNegotiationDiagnostic({

                    event:
                        "late-join-stale-offer-replaced",

                    requestId,

                    signalId:
                        Number(signal.id) || null,

                    requestGeneration,

                    senderEpoch,

                    ...this.#peerSnapshot(pc)

                });

                this.#releasePeer(
                    pc,
                    "late-join-stale-offer-replaced",
                    { replacing: true }
                );
                pc = null;

            }

            if (
                !pc &&
                (
                    signal.data?.target_peer_instance_id ||
                    (
                        requestGeneration &&
                        senderEpoch &&
                        requestGeneration !== senderEpoch
                    )
                )
            ) {

                this.#recordNegotiationDiagnostic({

                    event:
                        "renegotiation-request-rejected-stale",

                    requestId,

                    signalId:
                        Number(signal.id) || null,

                    mediaReason:
                        signal.data?.media_reason || signal.data?.reason || null,

                    webcamOperation:
                        signal.data?.webcam_operation || null,

                    requestGeneration,

                    senderEpoch,

                    targetPeerInstanceId:
                        signal.data?.target_peer_instance_id || null,

                    authoritativeOffererParticipantId:
                        this.#localParticipantId()

                });

                return SIGNAL_OUTCOME.STALE_GENERATION;

            }

            if (!pc) {

                this.#recordNegotiationDiagnostic({

                    event:
                        "renegotiation-request-received-no-peer",

                    requestId,

                    signalId:
                        Number(signal.id) || null,

                    mediaReason:
                        signal.data?.media_reason || signal.data?.reason || null,

                    webcamOperation:
                        signal.data?.webcam_operation || null,

                    requestGeneration,

                    senderEpoch,

                    requestingParticipantId:
                        from,

                    authoritativeOffererParticipantId:
                        this.#localParticipantId()

                });

            }

            if (
                pc &&
                (
                    !this.#signalTargetsPeer(pc, signal) ||
                    !this.#signalMatchesPeerGeneration(pc, signal)
                )
            ) {

                this.#recordNegotiationDiagnostic({

                    event:
                        "renegotiation-request-rejected-stale",

                    requestId,

                    signalId:
                        Number(signal.id) || null,

                    mediaReason:
                        signal.data?.media_reason || signal.data?.reason || null,

                    webcamOperation:
                        signal.data?.webcam_operation || null,

                    authoritativeOffererParticipantId:
                        this.#localParticipantId(),

                    ...this.#peerSnapshot(pc)

                });

                return SIGNAL_OUTCOME.STALE_GENERATION;

            }

            const coalesced =
                Boolean(pc?.__voicePendingNegotiation);

            this.#recordNegotiationDiagnostic({

                event:
                    coalesced ?
                        "renegotiation-request-coalesced" :
                        "renegotiation-request-accepted",

                requestId,

                signalId:
                    Number(signal.id) || null,

                mediaReason:
                    signal.data?.media_reason || signal.data?.reason || null,

                webcamOperation:
                    signal.data?.webcam_operation || null,

                requestingParticipantId:
                    from,

                authoritativeOffererParticipantId:
                    this.#localParticipantId(),

                ...(pc ? this.#peerSnapshot(pc) : {})

            });

            if (!coalesced) {

                if (!pc) {

                    this.#recordNegotiationDiagnostic({

                        event:
                            "missing-peer-creation-scheduled",

                        requestId,

                        signalId:
                            Number(signal.id) || null,

                        requestingParticipantId:
                            from,

                        authoritativeOffererParticipantId:
                            this.#localParticipantId()

                    });

                }

                try {

                    const outcome =
                        await this.connectMediaPeer(from, {

                            reason:
                                "remote-renegotiation-request",

                            mediaReason:
                                signal.data?.media_reason || signal.data?.reason || null,

                            webcamOperation:
                                signal.data?.webcam_operation || null,

                            allowReceiveOnly:
                                true,

                            missingPeerRequest:
                                !pc,

                            requestId,

                            signalId:
                                Number(signal.id) || null

                        });

                    const activePeer =
                        this.#peers.get(from) || null;

                    const scheduledDeferredRequest =
                        outcome === SIGNAL_OUTCOME.DEFERRED &&
                        Boolean(activePeer?.__voicePendingNegotiation);

                    const effectiveOutcome =
                        scheduledDeferredRequest ?
                            SIGNAL_OUTCOME.CONSUMED :
                            outcome;

                    this.#recordNegotiationDiagnostic({

                        event:
                            scheduledDeferredRequest ?
                                "renegotiation-request-scheduled" :
                            outcome === SIGNAL_OUTCOME.DEFERRED ?
                                "renegotiation-request-deferred" :
                                "renegotiation-request-completed",

                        requestId,

                        signalId:
                            Number(signal.id) || null,

                        outcome:
                            effectiveOutcome,

                        operationOutcome:
                            outcome,

                        ...(activePeer ?
                            this.#peerSnapshot(activePeer) :
                            {

                                remoteParticipantId:
                                    from

                            })

                    });

                    return effectiveOutcome;

                } catch (error) {

                    this.#recordNegotiationDiagnostic({

                        event:
                            "renegotiation-request-failed",

                        requestId,

                        signalId:
                            Number(signal.id) || null,

                        message:
                            error?.message || String(error),

                        ...(this.#peers.get(from) ?
                            this.#peerSnapshot(this.#peers.get(from)) :
                            {

                                remoteParticipantId:
                                    from

                            })

                    });

                    throw error;

                }

            }

            return SIGNAL_OUTCOME.DUPLICATE;

        }

        if (signal.type === "answer") {

            return this.#acceptAnswerSignal(signal);

        }

        if (signal.type === "ice") {

            return this.#acceptIceSignal(signal);

        }

        return SIGNAL_OUTCOME.TERMINAL_INVALID;

    }

    #signalMedia() {

        return this.#webcamStream() ? "webcam" : "voice";

    }

    #localParticipantId() {

        return Number(
            this.#config()?.myParticipantId
        );

    }

    #ownsOffer(remoteParticipantId) {

        const localId =
            this.#localParticipantId();

        const remoteId =
            Number(remoteParticipantId);

        return Boolean(
            localId &&
            remoteId &&
            localId < remoteId
        );

    }

    #isPolitePeer(remoteParticipantId) {

        const localId =
            this.#localParticipantId();

        const remoteId =
            Number(remoteParticipantId);

        return Boolean(
            localId &&
            remoteId &&
            localId > remoteId
        );

    }

    #isActivePeer(pc) {

        if (
            this.#lifecycle.isDestroyed() ||
            !pc?.__voiceRemoteParticipantId ||
            ["closing", "closed", "replacing"].includes(
                pc.__voiceLifecycleState
            )
        ) {

            return false;

        }

        return this.#peers.get(
            Number(pc.__voiceRemoteParticipantId)
        ) === pc;

    }

    #nextPeerInstanceId(remoteParticipantId) {

        this.#peerSerial +=
            1;

        return [

            "peer",

            this.#localParticipantId() || "local",

            Number(remoteParticipantId) || "remote",

            this.#peerSerial

        ].join("-");

    }

    #nextNegotiationId(remoteParticipantId, pc) {

        this.#negotiationSerial +=
            1;

        return [

            "neg",

            pc?.__voicePeerInstanceId || this.#localParticipantId() || "local",

            Number(remoteParticipantId) || "remote",

            this.#negotiationSerial

        ].join("-");

    }

    #nextRenegotiationRequestId(remoteParticipantId, pc) {

        this.#renegotiationRequestSerial +=
            1;

        return [

            "reneg-request",

            pc?.__voicePeerInstanceId || this.#localParticipantId() || "local",

            Number(remoteParticipantId) || "remote",

            this.#renegotiationRequestSerial

        ].join("-");

    }

    #localMediaNegotiationSignature(pc) {

        return ["audio", "video"].map(kind => {

            const transceiver =
                pc?.__voiceTransceivers?.[kind] || null;

            return [

                kind,

                transceiver?.direction || "missing",

                transceiver?.sender?.track?.id || "none"

            ].join(":");

        }).join("|");

    }

    #signalMatchesPeerGeneration(pc, signal) {

        const remotePeerInstanceId =
            signal?.data?.peer_instance_id || null;

        if (
            pc?.__voiceRemotePeerInstanceId &&
            remotePeerInstanceId &&
            pc.__voiceRemotePeerInstanceId !== remotePeerInstanceId
        ) {

            this.#recordNegotiationDiagnostic({

                event:
                    "signal-stale-remote-peer-skipped",

                signalId:
                    Number(signal?.id) || null,

                signalKind:
                    signal?.type || null,

                remoteParticipantId:
                    Number(signal?.from_participant_id) || null,

                peerInstanceId:
                    pc.__voicePeerInstanceId || null,

                generation:
                    pc.__voiceGeneration || null,

                expectedRemotePeerInstanceId:
                    pc.__voiceRemotePeerInstanceId,

                remotePeerInstanceId

            });

            return false;

        }

        return true;

    }

    #outgoingSignalPayload(type, data, metadata = {}) {

        if (type === "offer" || type === "answer") {

            const description =
                this.#sessionDescription(type, data);

            if (!description) return null;

            this.#recordSignalDiagnostic(
                "outgoing",
                type,
                description
            );

            return {

                kind:
                    type,

                description,

                negotiation_id:
                    metadata.negotiationId || null,

                generation:
                    metadata.generation || null,

                peer_instance_id:
                    metadata.peerInstanceId || null,

                target_peer_instance_id:
                    metadata.targetPeerInstanceId || null

            };

        }

        if (type === "ice") {

            const candidate =
                this.#iceCandidate(data);

            if (!candidate) return null;

            return {

                kind:
                    "ice",

                candidate,

                generation:
                    metadata.generation || null,

                peer_instance_id:
                    metadata.peerInstanceId || null,

                target_peer_instance_id:
                    metadata.targetPeerInstanceId || null

            };

        }

        return data;

    }

    #incomingSessionDescription(expectedType, data) {

        const payload =
            data && typeof data === "object" ? data : null;

        const description =
            payload?.description || payload;

        const normalized =
            this.#sessionDescription(
                expectedType,
                description
            );

        if (!normalized) {

            this.#warn(
                new Error(`Rejected malformed incoming ${expectedType} media signal.`)
            );

            this.#recordSignalDiagnostic(
                "incoming-rejected",
                expectedType,
                data
            );

            return null;

        }

        this.#recordSignalDiagnostic(
            "incoming",
            expectedType,
            normalized
        );

        return normalized;

    }

    #sessionDescription(expectedType, value) {

        if (!value || typeof value !== "object") return null;

        const type =
            value.type;

        const sdp =
            value.sdp;

        if (
            type !== expectedType ||
            typeof sdp !== "string" ||
            !this.#looksLikeSdp(sdp)
        ) {

            return null;

        }

        return {

            type,

            sdp

        };

    }

    #looksLikeSdp(sdp) {

        if (typeof sdp !== "string" || !sdp) return false;

        const normalized =
            sdp.replace(/\r\n/g, "\n").replace(/\r/g, "\n");

        if (!normalized.startsWith("v=0")) return false;

        if (
            !sdp.includes("\n") &&
            (sdp.includes("\\n") || sdp.includes("\\r\\n"))
        ) {

            return false;

        }

        const trimmed =
            sdp.trim();

        return trimmed !== "[object Object]" && !trimmed.startsWith("{");

    }

    #iceCandidate(value) {

        if (!value || typeof value !== "object") return null;

        const source =
            typeof value.toJSON === "function" ?
                value.toJSON() :
                value.candidate && typeof value.candidate === "object" ?
                    value.candidate :
                    value;

        if (!source || typeof source.candidate !== "string" || !source.candidate) {

            return null;

        }

        return {

            candidate:
                source.candidate,

            sdpMid:
                source.sdpMid ?? null,

            sdpMLineIndex:
                source.sdpMLineIndex ?? null,

            usernameFragment:
                source.usernameFragment ?? null

        };

    }

    #incomingIceCandidate(data) {

        const candidate =
            this.#iceCandidate(data);

        if (!candidate) {

            this.#warn(
                new Error("Rejected malformed incoming ice media signal.")
            );

            return null;

        }

        return candidate;

    }

    #recordSignalDiagnostic(direction, type, value) {

        const description =
            value?.description || value;

        const sdp =
            description?.sdp;

        this.#context?.recordVoiceSignalDiagnostic?.({

            direction,

            type,

            descriptionType:
                description?.type || null,

            sdpType:
                typeof sdp,

            sdpLength:
                typeof sdp === "string" ? sdp.length : null,

            firstLine:
                typeof sdp === "string" ?
                    sdp.replace(/\r\n/g, "\n").replace(/\r/g, "\n").split("\n")[0] :
                    null,

            startsWithV0:
                typeof sdp === "string" ?
                    sdp.replace(/\r\n/g, "\n").replace(/\r/g, "\n").startsWith("v=0") :
                    false,

            hasRealNewline:
                typeof sdp === "string" ? sdp.includes("\n") : false,

            hasLiteralBackslashNewline:
                typeof sdp === "string" ?
                    (sdp.includes("\\n") || sdp.includes("\\r\\n")) :
                    false

        });

    }

    #trackSnapshot(track) {

        if (!track) return null;

        return {

            id:
                track.id || null,

            kind:
                track.kind || null,

            label:
                track.label || null,

            readyState:
                track.readyState || null,

            enabled:
                track.enabled ?? null,

            muted:
                track.muted ?? null

        };

    }

    #streamSnapshot(stream) {

        if (!stream) return null;

        const tracks =
            typeof stream.getTracks === "function" ?
                stream.getTracks() :
                [];

        return {

            id:
                stream.id || null,

            trackCount:
                tracks.length,

            audioTrackCount:
                tracks.filter(track => track.kind === "audio").length,

            videoTrackCount:
                tracks.filter(track => track.kind === "video").length,

            tracks:
                tracks.map(track => this.#trackSnapshot(track))

        };

    }

    #audioSourceType(track = this.#localMediaTrackByKind("audio")) {

        const hint =
            String(this.#context?.getVoiceSourceHint?.() || "").toLowerCase();

        if (hint.includes("fake")) return "fake-wav";

        const label =
            String(track?.label || "").toLowerCase();

        if (
            label.includes("fake") ||
            label.includes("wav") ||
            label.includes("file")
        ) {

            return "fake-wav";

        }

        return "real-microphone";

    }

    #scheduleTransportRtpProbe(pc, reason, extra = {}) {

        if (!this.#context?.isRuntimeDiagnosticsEnabled?.()) return;

        if (!pc || typeof pc.getStats !== "function") return;

        const delays =
            (
                pc.connectionState === "connected" ||
                pc.iceConnectionState === "connected" ||
                pc.iceConnectionState === "completed"
            ) ?
                this.#transportProbeDelays :
                [0];

        const setTimer =
            this.#context?.setTimeout || setTimeout;

        delays.forEach(delay => {

            let timerId = null;

            timerId = setTimer(
                () => {
                    this.#forgetTransportProbeTimer(pc, timerId);
                    return this.#recordTransportRtpProbe(
                        pc,
                        reason,
                        delay,
                        extra
                    );
                },
                delay
            );

            if (!this.#transportProbeTimers.has(pc)) {
                this.#transportProbeTimers.set(pc, new Set());
            }
            this.#transportProbeTimers.get(pc).add(timerId);

        });

    }

    #forgetTransportProbeTimer(pc, timerId) {

        const timers =
            this.#transportProbeTimers.get(pc);

        if (!timers) return;

        timers.delete(timerId);

        if (timers.size === 0) {
            this.#transportProbeTimers.delete(pc);
        }

    }

    #clearTransportProbeTimers(pc = null) {

        const clearTimer =
            this.#context?.clearTimeout || clearTimeout;

        const entries =
            pc ?
                [[pc, this.#transportProbeTimers.get(pc)]] :
                Array.from(this.#transportProbeTimers.entries());

        entries.forEach(([peer, timers]) => {
            timers?.forEach(timerId => clearTimer(timerId));
            this.#transportProbeTimers.delete(peer);
        });

    }

    async #recordTransportRtpProbe(pc, reason, delay, extra = {}) {

        if (!this.#isActivePeer(pc)) {

            this.#recordAudioPathDiagnostic({

                event:
                    "transport-rtp-probe-stale-peer-skipped",

                reason,

                delay,

                remoteParticipantId:
                    pc?.__voiceRemoteParticipantId || null,

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                activePeerInstanceId:
                    this.#peers.get(Number(pc?.__voiceRemoteParticipantId))?.__voicePeerInstanceId || null

            });

            return;

        }

        try {

            const report =
                await pc.getStats();

            if (!this.#isActivePeer(pc)) {

                this.#recordAudioPathDiagnostic({
                    event: "transport-rtp-probe-completion-stale-peer-skipped",
                    reason,
                    delay,
                    remoteParticipantId: pc?.__voiceRemoteParticipantId || null,
                    peerInstanceId: pc?.__voicePeerInstanceId || null,
                    generation: pc?.__voiceGeneration || null
                });
                return;

            }

            const summary =
                this.#summarizePeerStats(
                    pc,
                    report
                );

            this.#recordAudioPathDiagnostic({

                event:
                    "transport-rtp-probe",

                reason,

                delay,

                remoteParticipantId:
                    pc.__voiceRemoteParticipantId || null,

                peerInstanceId:
                    pc.__voicePeerInstanceId || null,

                generation:
                    pc.__voiceGeneration || null,

                remotePeerInstanceId:
                    pc.__voiceRemotePeerInstanceId || null,

                negotiationId:
                    pc.__voicePendingLocalOffer || pc.__voiceAppliedRemoteAnswer || null,

                signalingState:
                    pc.signalingState || null,

                connectionState:
                    pc.connectionState || null,

                iceConnectionState:
                    pc.iceConnectionState || null,

                iceGatheringState:
                    pc.iceGatheringState || null,

                ...summary,

                ...extra

            });

        } catch (error) {

            this.#recordAudioPathDiagnostic({

                event:
                    "transport-rtp-probe-failed",

                reason,

                delay,

                remoteParticipantId:
                    pc?.__voiceRemoteParticipantId || null,

                peerInstanceId:
                    pc?.__voicePeerInstanceId || null,

                generation:
                    pc?.__voiceGeneration || null,

                message:
                    error?.message || String(error)

            });

        }

    }

    #summarizePeerStats(pc, report) {

        const stats =
            Array.from(report.values ? report.values() : report);

        const codecById =
            new Map(
                stats
                    .filter(stat => stat.type === "codec")
                    .map(stat => [stat.id, stat])
            );

        const remoteInboundById =
            new Map(
                stats
                    .filter(stat => stat.type === "remote-inbound-rtp")
                    .map(stat => [stat.id, stat])
            );

        const candidateById =
            new Map(
                stats
                    .filter(stat =>
                        stat.type === "local-candidate" ||
                        stat.type === "remote-candidate"
                    )
                    .map(stat => [stat.id, stat])
            );

        const transport =
            stats.find(stat => stat.type === "transport") || null;

        const selectedCandidatePair =
            stats.find(stat =>
                stat.type === "candidate-pair" &&
                (
                    stat.selected ||
                    stat.nominated ||
                    stat.id === transport?.selectedCandidatePairId
                )
            ) || null;

        const audioElement =
            this.#context?.getAudioElement?.(
                pc.__voiceRemoteParticipantId
            ) || null;

        return {

            transport:
                this.#transportStatsSnapshot(
                    transport,
                    selectedCandidatePair,
                    candidateById
                ),

            outboundAudio:
                stats
                    .filter(stat =>
                        stat.type === "outbound-rtp" &&
                        (stat.kind === "audio" || stat.mediaType === "audio")
                    )
                    .map(stat => this.#outboundAudioStatsSnapshot(
                        stat,
                        codecById,
                        remoteInboundById
                    )),

            inboundAudio:
                stats
                    .filter(stat =>
                        stat.type === "inbound-rtp" &&
                        (stat.kind === "audio" || stat.mediaType === "audio")
                    )
                    .map(stat => this.#inboundAudioStatsSnapshot(
                        stat,
                        codecById
                    )),

            audioTransceiver:
                this.#transceiverSnapshot(
                    pc.__voiceTransceivers?.audio || null,
                    this.#transceiverIndex(
                        pc,
                        pc.__voiceTransceivers?.audio || null
                    )
                ),

            remoteAudioElement:
                this.#audioElementSnapshot(
                    audioElement
                )

        };

    }

    #senderSnapshot(sender) {

        if (!sender) return null;

        return {

            track:
                this.#trackSnapshot(sender.track),

            transportState:
                sender.transport?.state || null

        };

    }

    #transportStatsSnapshot(transport, candidatePair, candidateById) {

        return {

            dtlsState:
                transport?.dtlsState || null,

            iceRole:
                transport?.iceRole || null,

            iceLocalUsernameFragment:
                transport?.iceLocalUsernameFragment || null,

            selectedCandidatePairId:
                transport?.selectedCandidatePairId || candidatePair?.id || null,

            bytesSent:
                transport?.bytesSent ?? candidatePair?.bytesSent ?? null,

            bytesReceived:
                transport?.bytesReceived ?? candidatePair?.bytesReceived ?? null,

            selectedCandidatePair:
                this.#candidatePairSnapshot(
                    candidatePair,
                    candidateById
                )

        };

    }

    #candidatePairSnapshot(candidatePair, candidateById) {

        if (!candidatePair) return null;

        return {

            id:
                candidatePair.id || null,

            state:
                candidatePair.state || null,

            selected:
                candidatePair.selected ?? null,

            nominated:
                candidatePair.nominated ?? null,

            writable:
                candidatePair.writable ?? null,

            bytesSent:
                candidatePair.bytesSent ?? null,

            bytesReceived:
                candidatePair.bytesReceived ?? null,

            currentRoundTripTime:
                candidatePair.currentRoundTripTime ?? null,

            availableOutgoingBitrate:
                candidatePair.availableOutgoingBitrate ?? null,

            localCandidate:
                this.#candidateSnapshot(
                    candidateById.get(candidatePair.localCandidateId)
                ),

            remoteCandidate:
                this.#candidateSnapshot(
                    candidateById.get(candidatePair.remoteCandidateId)
                )

        };

    }

    #candidateSnapshot(candidate) {

        if (!candidate) return null;

        return {

            id:
                candidate.id || null,

            candidateType:
                candidate.candidateType || null,

            protocol:
                candidate.protocol || null,

            address:
                candidate.address || candidate.ip || null,

            port:
                candidate.port ?? null,

            relayProtocol:
                candidate.relayProtocol || null

        };

    }

    #iceCandidateSnapshot(candidate) {

        if (!candidate) return null;

        const value =
            typeof candidate.toJSON === "function" ?
                candidate.toJSON() :
                candidate;

        return {

            candidate:
                typeof value.candidate === "string" ?
                    value.candidate.slice(0, 120) :
                    null,

            sdpMid:
                value.sdpMid ?? null,

            sdpMLineIndex:
                value.sdpMLineIndex ?? null,

            usernameFragment:
                value.usernameFragment || null

        };

    }

    #outboundAudioStatsSnapshot(stat, codecById, remoteInboundById) {

        const codec =
            codecById.get(stat.codecId) || null;

        const remoteInbound =
            remoteInboundById.get(stat.remoteId) || null;

        return {

            id:
                stat.id || null,

            ssrc:
                stat.ssrc ?? null,

            active:
                stat.active ?? null,

            packetsSent:
                stat.packetsSent ?? null,

            bytesSent:
                stat.bytesSent ?? null,

            totalPacketSendDelay:
                stat.totalPacketSendDelay ?? null,

            audioLevel:
                stat.audioLevel ?? null,

            totalAudioEnergy:
                stat.totalAudioEnergy ?? null,

            codecMimeType:
                codec?.mimeType || null,

            remoteInbound:
                remoteInbound ? {

                    packetsLost:
                        remoteInbound.packetsLost ?? null,

                    roundTripTime:
                        remoteInbound.roundTripTime ?? null,

                    fractionLost:
                        remoteInbound.fractionLost ?? null

                } : null

        };

    }

    #inboundAudioStatsSnapshot(stat, codecById) {

        const codec =
            codecById.get(stat.codecId) || null;

        return {

            id:
                stat.id || null,

            ssrc:
                stat.ssrc ?? null,

            packetsReceived:
                stat.packetsReceived ?? null,

            bytesReceived:
                stat.bytesReceived ?? null,

            packetsLost:
                stat.packetsLost ?? null,

            jitter:
                stat.jitter ?? null,

            audioLevel:
                stat.audioLevel ?? null,

            totalAudioEnergy:
                stat.totalAudioEnergy ?? null,

            codecMimeType:
                codec?.mimeType || null

        };

    }

    #audioElementSnapshot(audioElement) {

        if (!audioElement) return null;

        const stream =
            audioElement.srcObject || null;

        return {

            id:
                audioElement.id || null,

            paused:
                audioElement.paused ?? null,

            muted:
                audioElement.muted ?? null,

            volume:
                audioElement.volume ?? null,

            sinkId:
                audioElement.sinkId || null,

            srcObject:
                this.#streamSnapshot(stream)

        };

    }

    #beginAudioPlayback(audioElement) {

        let state = this.#audioPlaybackStates.get(audioElement);
        if (!state) {
            state = {
                sequence: 0,
                pending: new Set(),
                interruptions: new Map()
            };
            this.#audioPlaybackStates.set(audioElement, state);
        }

        state.sequence += 1;
        state.pending.add(state.sequence);
        return state.sequence;

    }

    #markAudioPlaybackInterruption(audioElement, reason) {

        const state = this.#audioPlaybackStates.get(audioElement);
        if (!state) return;
        state.pending.forEach(sequence => {
            state.interruptions.set(sequence, String(reason));
        });

    }

    #finishAudioPlayback(audioElement, sequence) {

        const state = this.#audioPlaybackStates.get(audioElement);
        if (!state) return null;
        const reason = state.interruptions.get(sequence) || null;
        state.pending.delete(sequence);
        state.interruptions.delete(sequence);
        return reason;

    }

    #prepareAudioElementRemoval(audioElement, reason) {

        if (!audioElement) return;
        this.#markAudioPlaybackInterruption(audioElement, reason);
        audioElement.pause?.();
        audioElement.srcObject = null;

    }

    #isRemoteAudioResourceCurrent(resource) {

        if (!resource) return false;

        return Boolean(
            this.#remoteAudioResources.get(resource.participantId) === resource &&
            this.#isActivePeer(resource.peer) &&
            resource.peer.__voicePeerInstanceId === resource.peerInstanceId &&
            resource.peer.__voiceGeneration === resource.generation &&
            resource.peer.__voiceTransceivers?.audio === resource.transceiver &&
            resource.transceiver?.receiver?.track === resource.track &&
            resource.track?.id === resource.receiverTrackId &&
            resource.track?.readyState !== "ended" &&
            resource.audio?.srcObject === resource.stream
        );

    }

    #releaseRemoteAudioResource(
        participantId,
        reason,
        expectedPeer = null,
        expectedTrack = null,
        options = {}
    ) {

        const id =
            Number(participantId);

        const resource =
            this.#remoteAudioResources.get(id) || null;

        if (
            resource &&
            (
                (expectedPeer && resource.peer !== expectedPeer) ||
                (expectedTrack && resource.track !== expectedTrack)
            )
        ) {

            return Object.freeze({ status: "stale-generation", reason });

        }

        const audioElement =
            resource?.audio || this.#context?.getAudioElement?.(id) || null;

        this.#prepareAudioElementRemoval(audioElement, reason);

        if (resource) {

            this.#remoteAudioResources.delete(id);

            this.#recordAudioPathDiagnostic({
                event: "remote-audio-resource-released",
                reason,
                remoteParticipantId: id,
                peerInstanceId: resource.peerInstanceId,
                generation: resource.generation,
                receiverTrackId: resource.receiverTrackId
            });

        }

        if (!options.preserveElement) {

            this.#context?.removeAudioElement?.(id);

        }

        return Object.freeze({ status: "completed", reason });

    }

    #removeAudioElement(participantId, reason) {

        return this.#releaseRemoteAudioResource(
            participantId,
            reason
        );

    }

    #transceiverIndex(pc, transceiver) {

        if (
            !pc ||
            !transceiver ||
            typeof pc.getTransceivers !== "function"
        ) {

            return null;

        }

        const transceivers =
            pc.getTransceivers();

        const index =
            transceivers.indexOf(
                transceiver
            );

        return index >= 0 ? index : null;

    }

    #transceiverSnapshot(transceiver, index = null) {

        if (!transceiver) return null;

        return {

            index,

            mid:
                transceiver.mid || null,

            direction:
                transceiver.direction || null,

            currentDirection:
                transceiver.currentDirection || null,

            sender:
                this.#senderSnapshot(transceiver.sender),

            senderTrack:
                this.#trackSnapshot(transceiver.sender?.track),

            receiverTrack:
                this.#trackSnapshot(transceiver.receiver?.track)

        };

    }

    #peerSnapshot(pc) {

        if (!pc) return {};

        const senders =
            typeof pc.getSenders === "function" ?
                pc.getSenders() :
                [];

        const transceivers =
            typeof pc.getTransceivers === "function" ?
                pc.getTransceivers() :
                [];

        return {

            remoteParticipantId:
                pc.__voiceRemoteParticipantId || null,

            role:
                pc.__voiceRole || null,

            peerInstanceId:
                pc.__voicePeerInstanceId || null,

            generation:
                pc.__voiceGeneration || null,

            remotePeerInstanceId:
                pc.__voiceRemotePeerInstanceId || null,

            pendingNegotiationId:
                pc.__voicePendingLocalOffer || null,

            appliedRemoteAnswer:
                pc.__voiceAppliedRemoteAnswer || null,

            signalingState:
                pc.signalingState || null,

            connectionState:
                pc.connectionState || null,

            iceConnectionState:
                pc.iceConnectionState || null,

            senders:
                senders.map(sender => this.#senderSnapshot(sender)),

            transceivers:
                transceivers.map((transceiver, index) =>
                    this.#transceiverSnapshot(
                        transceiver,
                        index
                    )
                ),

            reservedTransceiverKinds:
                [
                    pc.__voiceTransceivers?.audio ? "audio" : null,
                    pc.__voiceTransceivers?.video ? "video" : null
                ].filter(Boolean),

            canonicalTransceiverMids: {

                audio:
                    pc.__voiceTransceivers?.audio?.mid ?? null,

                video:
                    pc.__voiceTransceivers?.video?.mid ?? null

            }

        };

    }

    #recordNegotiationDiagnostic(entry = {}) {

        this.#context?.recordVoiceSignalDiagnostic?.({

            category:
                "negotiation",

            roomId:
                this.#roomId(),

            localParticipantId:
                this.#localParticipantId() || null,

            sourceType:
                this.#audioSourceType(),

            timestamp:
                Date.now(),

            processedSignalCount:
                this.#terminalSignalIds.size,

            processingSignalCount:
                this.#processingSignalIds.size,

            pendingSignalCount:
                this.#signalInbox.size,

            ...entry

        });

    }

    #recordAudioPathDiagnostic(entry = {}) {

        this.#context?.recordVoiceSignalDiagnostic?.({

            category:
                "audio-path",

            roomId:
                this.#roomId(),

            localParticipantId:
                this.#localParticipantId() || null,

            sourceType:
                entry.sourceType || this.#audioSourceType(),

            timestamp:
                Date.now(),

            ...entry

        });

    }

    #recordTransportStateDiagnostic(pc, event) {

        this.#recordAudioPathDiagnostic({

            event,

            remoteParticipantId:
                pc?.__voiceRemoteParticipantId || null,

            peerInstanceId:
                pc?.__voicePeerInstanceId || null,

            generation:
                pc?.__voiceGeneration || null,

            remotePeerInstanceId:
                pc?.__voiceRemotePeerInstanceId || null,

            signalingState:
                pc?.signalingState || null,

            connectionState:
                pc?.connectionState || null,

            iceConnectionState:
                pc?.iceConnectionState || null,

            iceGatheringState:
                pc?.iceGatheringState || null,

            activePeer:
                this.#isActivePeer(pc)

        });

    }

    #recordVideoLifecycleDiagnostic(entry = {}) {

        this.#context?.recordVoiceLifecycleDiagnostic?.({

            category:
                "video-lifecycle",

            roomId:
                this.#roomId(),

            localParticipantId:
                this.#localParticipantId() || null,

            ...entry

        });

    }

    #canonicalRemoteVideoStream(pc, transceiver, track, candidateStream = null) {

        const participantId =
            Number(pc?.__voiceRemoteParticipantId);

        const receiver =
            transceiver?.receiver || null;

        const existing =
            this.#remoteVideoPresentations.get(participantId) || null;

        if (
            existing?.peer === pc &&
            existing?.receiver === receiver &&
            existing?.track === track &&
            existing?.stream?.getVideoTracks?.().includes(track)
        ) {

            return existing.stream;

        }

        const MediaStreamClass =
            this.#context?.window?.MediaStream || globalThis.MediaStream;

        const stream =
            candidateStream?.getVideoTracks?.().includes(track)
                ? candidateStream
                : new MediaStreamClass([track]);

        const presentation = {

            peer:
                pc,

            receiver,

            track,

            stream,

            receiverIdentity:
                this.#mediaObjectIdentity(receiver, "receiver"),

            streamIdentity:
                this.#mediaObjectIdentity(stream, "stream")

        };

        this.#remoteVideoPresentations.set(
            participantId,
            presentation
        );

        return stream;

    }

    #mediaObjectIdentity(value, prefix) {

        if (!value || (typeof value !== "object" && typeof value !== "function")) {

            return null;

        }

        if (!this.#mediaObjectIds.has(value)) {

            this.#mediaObjectSerial += 1;
            this.#mediaObjectIds.set(
                value,
                `${prefix}-${this.#mediaObjectSerial}`
            );

        }

        return this.#mediaObjectIds.get(value);

    }

}

export default VoiceMediaService;
