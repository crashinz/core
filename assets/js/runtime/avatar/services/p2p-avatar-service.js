/******************************************************************************
 * ChatSpace P2P Avatar Service
 *
 * Owns authenticated current-avatar transfer over a WebRTC data channel.
 * Received bytes and object URLs exist only for the active room document.
 ******************************************************************************/

const AVATAR_MEDIA = "avatar";
const CHANNEL_LABEL = "chatspace-p2p-avatar-v1";
const CHUNK_BYTES = 32 * 1024;
const MAX_BUFFERED_BYTES = 256 * 1024;
const TRANSFER_TIMEOUT_MS = 30_000;
const TOKEN_CLOCK_SKEW_SECONDS = 5;
const ALLOWED_MIME = new Set(["image/gif", "image/webp"]);

export class P2PAvatarService {

    #runtime;

    #context = null;

    #policy = Object.freeze({ effectiveEnabled: false, iceServers: [] });

    #peers = new Map();

    #received = new Map();

    #orphanCandidates = new Map();

    #requesting = new Set();

    #destroyed = false;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    initialize() {
    }

    configure(context = {}) {
        this.#context = context;
        return this.getDiagnostics();
    }

    applyPolicy(policy = {}) {
        const effectiveEnabled = policy?.effectiveEnabled === true;
        const iceServers = effectiveEnabled && Array.isArray(policy?.iceServers)
            ? policy.iceServers.map(server => ({
                urls: Array.isArray(server?.urls)
                    ? server.urls.map(String)
                    : String(server?.urls || ""),
                ...(server?.username ? { username: String(server.username) } : {}),
                ...(server?.credential ? { credential: String(server.credential) } : {})
            })).filter(server => (
                (Array.isArray(server.urls) && server.urls.length > 0)
                || (!Array.isArray(server.urls) && server.urls !== "")
            ))
            : [];
        this.#policy = Object.freeze({
            effectiveEnabled,
            directFirst: policy?.directFirst !== false,
            relayAllowed: policy?.relayAllowed === true,
            iceServers: Object.freeze(iceServers),
            maxBytes: Math.max(1, Number(policy?.maxBytes || 5 * 1024 * 1024)),
            maxWidth: Math.max(1, Number(policy?.maxWidth || 4096)),
            maxHeight: Math.max(1, Number(policy?.maxHeight || 4096)),
            receivedStorage: "session-memory-only"
        });
        if (!effectiveEnabled) this.clearAll("policy-disabled");
        return this.getDiagnostics();
    }

    reconcileParticipant(participant) {
        if (this.#destroyed || !participant) return;
        const participantId = Number(participant.id || participant.participant_id || 0);
        const localId = Number(this.#context?.getConfig?.()?.myParticipantId || 0);
        if (!participantId || participantId === localId) return;

        const blocked = this.#context?.isBlocked?.(participant) === true;
        const hidden = this.#context?.isHidden?.(participant) === true;
        const projection = participant?.p2p_avatar || null;
        const identity = String(projection?.identity || "");
        const current = this.#received.get(participantId);

        if (blocked) {
            this.clearParticipant(participantId, "blocked");
            return;
        }
        if (hidden || !this.#policy.effectiveEnabled || !this.#validIdentity(identity)) {
            this.#clearViewerState(participantId, hidden ? "hidden" : "not-eligible");
            return;
        }
        if (current && current.identity !== identity) {
            this.#clearReceived(participantId, "identity-changed");
        }
        this.prefetchParticipant(participant).catch(error => this.#warn(error));
    }

    async prefetchParticipant(participant) {
        if (this.#destroyed || !this.#policy.effectiveEnabled) return false;
        const participantId = Number(participant?.id || participant?.participant_id || 0);
        const localId = Number(this.#context?.getConfig?.()?.myParticipantId || 0);
        let projection = participant?.p2p_avatar || {};
        let identity = String(projection.identity || "");
        let token = String(projection.authorization || "");
        if (!participantId || participantId === localId) return false;
        if (this.#context?.isBlocked?.(participant) || this.#context?.isHidden?.(participant)) return false;
        if (this.#received.get(participantId)?.identity === identity) return true;
        if (this.#requesting.has(participantId)) return false;

        let claims = this.#tokenClaims(token);
        if (!this.#validIdentity(identity) || !this.#claimsMatch(claims, {
            viewerParticipantId: localId,
            sourceParticipantId: participantId,
            identity
        })) {
            const refreshed = await this.#context?.refreshAuthorization?.(participantId);
            projection = refreshed?.p2pAvatar || {};
            identity = String(projection.identity || "");
            token = String(projection.authorization || "");
            claims = this.#tokenClaims(token);
            if (!this.#validIdentity(identity) || !this.#claimsMatch(claims, {
                viewerParticipantId: localId,
                sourceParticipantId: participantId,
                identity
            })) return false;
            this.#context?.onAuthorization?.(participantId, projection);
        }

        const activePeer = this.#peers.get(token);
        if (activePeer
            && !activePeer.closed
            && activePeer.role === "viewer"
            && activePeer.remoteId === participantId
            && activePeer.identity === identity) return true;

        this.#requesting.add(participantId);
        try {
            this.#closePeersFor(participantId, "viewer", "authorization-refreshed");
            const peer = this.#createPeer({
                token,
                role: "viewer",
                remoteId: participantId,
                identity,
                expectedWidth: Number(projection.width || 0),
                expectedHeight: Number(projection.height || 0)
            });
            const channel = peer.pc.createDataChannel(CHANNEL_LABEL, { ordered: true });
            this.#bindViewerChannel(peer, channel);
            const offer = await peer.pc.createOffer();
            await peer.pc.setLocalDescription(offer);
            await this.#sendDescription(peer, "offer", peer.pc.localDescription);
            return true;
        } catch (error) {
            this.#closePeersFor(participantId, "viewer", "request-failed");
            throw error;
        } finally {
            this.#requesting.delete(participantId);
        }
    }

    async handleSignal(signal = {}) {
        if (this.#destroyed || signal?.media !== AVATAR_MEDIA) return false;
        const remoteId = Number(signal.from_participant_id || 0);
        const localId = Number(this.#context?.getConfig?.()?.myParticipantId || 0);
        const type = String(signal.type || "");
        const token = String(signal?.data?.avatar_authorization || "");
        const claims = this.#tokenClaims(token);
        if (!remoteId || !localId || !claims) return false;

        if (type === "offer") {
            if (Number(claims.sp) !== localId || Number(claims.vp) !== remoteId) return false;
            return this.#acceptOffer(signal, claims, token);
        }
        if (type === "answer") {
            if (Number(claims.vp) !== localId || Number(claims.sp) !== remoteId) return false;
            const peer = this.#peers.get(token);
            if (!peer || peer.role !== "viewer" || peer.remoteId !== remoteId) return false;
            const description = this.#description("answer", signal?.data?.description);
            if (!description) return false;
            await peer.pc.setRemoteDescription(description);
            await this.#flushIncomingCandidates(peer);
            return true;
        }
        if (type === "ice") {
            if (![Number(claims.vp), Number(claims.sp)].includes(localId)
                || ![Number(claims.vp), Number(claims.sp)].includes(remoteId)
                || localId === remoteId) return false;
            const candidate = this.#candidate(signal?.data?.candidate);
            if (!candidate || (this.#isRelayCandidate(candidate) && !this.#policy.relayAllowed)) return false;
            const peer = this.#peers.get(token);
            if (!peer) {
                const queued = this.#orphanCandidates.get(token) || [];
                if (queued.length < 32) queued.push(candidate);
                this.#orphanCandidates.set(token, queued);
                return true;
            }
            if (!peer.pc.remoteDescription) peer.incomingCandidates.push(candidate);
            else await peer.pc.addIceCandidate(candidate);
            return true;
        }
        return false;
    }

    clearParticipant(participantId, reason = "participant-cleared") {
        const id = Number(participantId);
        if (!id) return;
        this.#clearReceived(id, reason);
        this.#closePeersFor(id, null, reason);
        this.#requesting.delete(id);
    }

    clearAll(reason = "clear-all") {
        for (const participantId of [...this.#received.keys()]) {
            this.#clearReceived(participantId, reason);
        }
        for (const peer of [...this.#peers.values()]) this.#closePeer(peer, reason);
        this.#orphanCandidates.clear();
        this.#requesting.clear();
    }

    destroy() {
        if (this.#destroyed) return;
        this.#destroyed = true;
        this.clearAll("service-destroyed");
        this.#context = null;
    }

    getDiagnostics() {
        return Object.freeze({
            owner: "AvatarRuntime",
            service: "P2PAvatarService",
            effectiveEnabled: this.#policy.effectiveEnabled === true,
            activePeerCount: this.#peers.size,
            receivedAvatarCount: this.#received.size,
            pendingRequestCount: this.#requesting.size,
            pendingCandidateSetCount: this.#orphanCandidates.size,
            receivedStorage: "session-memory-only",
            serverPayloadStorage: false
        });
    }

    async #acceptOffer(signal, claims, token) {
        const remoteId = Number(signal.from_participant_id);
        const identity = String(claims.aid || "");
        if (!this.#validIdentity(identity)) return false;
        const authorization = await this.#context?.authorizeSource?.(token);
        if (!authorization?.ok
            || String(authorization.identity || "") !== identity
            || Number(authorization.viewerParticipantId || 0) !== remoteId) return false;

        const prior = this.#peers.get(token);
        if (prior) this.#closePeer(prior, "offer-replaced");
        const peer = this.#createPeer({
            token,
            role: "source",
            remoteId,
            identity,
            authorization
        });
        peer.pc.ondatachannel = event => this.#bindSourceChannel(peer, event.channel);
        const description = this.#description("offer", signal?.data?.description);
        if (!description) {
            this.#closePeer(peer, "invalid-offer");
            return false;
        }
        await peer.pc.setRemoteDescription(description);
        await this.#flushIncomingCandidates(peer);
        const answer = await peer.pc.createAnswer();
        await peer.pc.setLocalDescription(answer);
        await this.#sendDescription(peer, "answer", peer.pc.localDescription);
        return true;
    }

    #createPeer(options) {
        const RTCPeerConnectionClass = this.#context?.window?.RTCPeerConnection;
        if (typeof RTCPeerConnectionClass !== "function") {
            throw new Error("Direct avatar synchronization is not supported by this browser.");
        }
        const pc = new RTCPeerConnectionClass({
            iceServers: this.#policy.iceServers,
            iceTransportPolicy: "all"
        });
        const peer = {
            ...options,
            pc,
            channel: null,
            outgoingCandidates: [],
            incomingCandidates: this.#orphanCandidates.get(options.token) || [],
            signalReady: false,
            header: null,
            chunks: [],
            receivedBytes: 0,
            closed: false,
            timeout: null
        };
        this.#orphanCandidates.delete(options.token);
        this.#peers.set(options.token, peer);
        pc.onicecandidate = event => {
            if (!event.candidate || peer.closed) return;
            const candidate = event.candidate.toJSON?.() || event.candidate;
            if (!peer.signalReady) peer.outgoingCandidates.push(candidate);
            else this.#sendCandidate(peer, candidate).catch(error => this.#warn(error));
        };
        pc.onconnectionstatechange = () => {
            if (["failed", "closed"].includes(pc.connectionState)) {
                this.#closePeer(peer, `connection-${pc.connectionState}`);
            }
        };
        peer.timeout = this.#context?.window?.setTimeout?.(
            () => this.#closePeer(peer, "transfer-timeout"),
            TRANSFER_TIMEOUT_MS
        );
        return peer;
    }

    async #sendDescription(peer, type, description) {
        await this.#context?.sendSignal?.(AVATAR_MEDIA, peer.remoteId, type, {
            kind: type,
            description: { type, sdp: String(description?.sdp || "") },
            avatar_authorization: peer.token
        });
        peer.signalReady = true;
        const candidates = peer.outgoingCandidates.splice(0).sort((left, right) =>
            Number(this.#isRelayCandidate(left)) - Number(this.#isRelayCandidate(right))
        );
        for (const candidate of candidates) await this.#sendCandidate(peer, candidate);
    }

    #sendCandidate(peer, candidate) {
        return this.#context?.sendSignal?.(AVATAR_MEDIA, peer.remoteId, "ice", {
            kind: "ice",
            candidate,
            avatar_authorization: peer.token
        });
    }

    async #flushIncomingCandidates(peer) {
        const candidates = peer.incomingCandidates.splice(0);
        for (const candidate of candidates) await peer.pc.addIceCandidate(candidate);
    }

    #bindViewerChannel(peer, channel) {
        peer.channel = channel;
        channel.binaryType = "arraybuffer";
        channel.onmessage = event => {
            this.#receiveViewerMessage(peer, event.data).catch(error => {
                this.#warn(error);
                this.#closePeer(peer, "payload-rejected");
            });
        };
        channel.onclose = () => {
            if (!peer.closed && !this.#received.has(peer.remoteId)) this.#closePeer(peer, "channel-closed");
        };
    }

    #bindSourceChannel(peer, channel) {
        if (channel.label !== CHANNEL_LABEL) {
            channel.close();
            this.#closePeer(peer, "channel-label-rejected");
            return;
        }
        peer.channel = channel;
        channel.binaryType = "arraybuffer";
        channel.onopen = () => this.#sendSourceAvatar(peer).catch(error => {
            this.#warn(error);
            this.#closePeer(peer, "source-send-failed");
        });
        channel.onclose = () => this.#closePeer(peer, "source-channel-closed");
    }

    async #sendSourceAvatar(peer) {
        const authorization = peer.authorization;
        const response = await this.#context?.fetch?.(String(authorization.sourceUrl || ""), {
            credentials: "same-origin",
            cache: "no-store"
        });
        if (!response?.ok) throw new Error("The current avatar could not be read.");
        const blob = await response.blob();
        const bytes = await blob.arrayBuffer();
        const identity = await this.#sha256(bytes);
        if (identity !== peer.identity
            || Number(blob.size) !== Number(authorization.size)
            || String(blob.type) !== String(authorization.mime)
            || !ALLOWED_MIME.has(blob.type)
            || blob.size < 1
            || blob.size > this.#policy.maxBytes) {
            throw new Error("The current avatar changed before synchronization completed.");
        }
        const chunks = Math.ceil(blob.size / CHUNK_BYTES);
        peer.channel.send(JSON.stringify({
            kind: "avatar-header",
            version: 1,
            identity,
            mime: blob.type,
            size: blob.size,
            width: Number(authorization.width),
            height: Number(authorization.height),
            chunks,
            chunkBytes: CHUNK_BYTES
        }));
        for (let offset = 0; offset < bytes.byteLength; offset += CHUNK_BYTES) {
            await this.#waitForBuffer(peer.channel);
            peer.channel.send(bytes.slice(offset, Math.min(bytes.byteLength, offset + CHUNK_BYTES)));
        }
    }

    async #receiveViewerMessage(peer, data) {
        if (peer.closed) return;
        if (typeof data === "string") {
            if (peer.header) throw new Error("Duplicate avatar transfer header.");
            const header = JSON.parse(data);
            if (header?.kind !== "avatar-header" || Number(header.version) !== 1
                || String(header.identity || "") !== peer.identity
                || !ALLOWED_MIME.has(String(header.mime || ""))
                || !Number.isInteger(Number(header.size))
                || Number(header.size) < 1
                || Number(header.size) > this.#policy.maxBytes
                || !Number.isInteger(Number(header.width))
                || !Number.isInteger(Number(header.height))
                || Number(header.width) < 1 || Number(header.width) > this.#policy.maxWidth
                || Number(header.height) < 1 || Number(header.height) > this.#policy.maxHeight
                || (peer.expectedWidth > 0 && Number(header.width) !== peer.expectedWidth)
                || (peer.expectedHeight > 0 && Number(header.height) !== peer.expectedHeight)
                || !Number.isInteger(Number(header.chunks))
                || Number(header.chunks) < 1
                || Number(header.chunks) > Math.ceil(this.#policy.maxBytes / CHUNK_BYTES)) {
                throw new Error("The received avatar header is invalid.");
            }
            peer.header = header;
            return;
        }
        if (!peer.header) throw new Error("Avatar bytes arrived before authorization metadata.");
        const chunk = data instanceof ArrayBuffer ? data : await data?.arrayBuffer?.();
        if (!(chunk instanceof ArrayBuffer) || chunk.byteLength < 1 || chunk.byteLength > CHUNK_BYTES) {
            throw new Error("The received avatar chunk is invalid.");
        }
        peer.receivedBytes += chunk.byteLength;
        if (peer.receivedBytes > Number(peer.header.size)) throw new Error("The received avatar is too large.");
        peer.chunks.push(chunk);
        if (peer.chunks.length < Number(peer.header.chunks)) return;
        if (peer.chunks.length !== Number(peer.header.chunks)
            || peer.receivedBytes !== Number(peer.header.size)) {
            throw new Error("The received avatar is incomplete.");
        }
        const blob = new Blob(peer.chunks, { type: String(peer.header.mime) });
        const bytes = await blob.arrayBuffer();
        if (await this.#sha256(bytes) !== peer.identity) throw new Error("The received avatar identity does not match.");
        await this.#validateImage(blob, Number(peer.header.width), Number(peer.header.height));

        const prior = this.#received.get(peer.remoteId);
        if (prior?.objectUrl) this.#context?.window?.URL?.revokeObjectURL?.(prior.objectUrl);
        const objectUrl = this.#context?.window?.URL?.createObjectURL?.(blob);
        if (!objectUrl) throw new Error("The received avatar could not be presented.");
        this.#received.set(peer.remoteId, Object.freeze({
            identity: peer.identity,
            blob,
            objectUrl
        }));
        peer.header = null;
        peer.chunks = [];
        peer.receivedBytes = 0;
        const accepted = this.#context?.onAvatarReady?.(peer.remoteId, objectUrl, peer.identity);
        if (accepted === false) {
            this.#clearReceived(peer.remoteId, "presentation-rejected");
        }
        this.#closePeer(peer, "transfer-complete");
    }

    async #validateImage(blob, expectedWidth, expectedHeight) {
        const createImageBitmap = this.#context?.window?.createImageBitmap;
        if (typeof createImageBitmap === "function") {
            const bitmap = await createImageBitmap(blob);
            try {
                if (bitmap.width !== expectedWidth || bitmap.height !== expectedHeight) {
                    throw new Error("The received avatar dimensions do not match.");
                }
            } finally {
                bitmap.close?.();
            }
            return;
        }
        const objectUrl = this.#context?.window?.URL?.createObjectURL?.(blob);
        if (!objectUrl) throw new Error("The received avatar could not be decoded.");
        try {
            await new Promise((resolve, reject) => {
                const image = new this.#context.window.Image();
                image.onload = () => image.naturalWidth === expectedWidth && image.naturalHeight === expectedHeight
                    ? resolve() : reject(new Error("The received avatar dimensions do not match."));
                image.onerror = () => reject(new Error("The received avatar could not be decoded."));
                image.src = objectUrl;
            });
        } finally {
            this.#context?.window?.URL?.revokeObjectURL?.(objectUrl);
        }
    }

    async #waitForBuffer(channel) {
        if (channel.bufferedAmount <= MAX_BUFFERED_BYTES) return;
        channel.bufferedAmountLowThreshold = MAX_BUFFERED_BYTES / 2;
        await new Promise((resolve, reject) => {
            const timeout = this.#context?.window?.setTimeout?.(
                () => reject(new Error("Avatar transfer buffering timed out.")),
                5_000
            );
            channel.addEventListener("bufferedamountlow", () => {
                this.#context?.window?.clearTimeout?.(timeout);
                resolve();
            }, { once: true });
        });
    }

    async #sha256(bytes) {
        const digest = await this.#context?.window?.crypto?.subtle?.digest?.("SHA-256", bytes);
        if (!(digest instanceof ArrayBuffer)) throw new Error("Avatar identity validation is unavailable.");
        return [...new Uint8Array(digest)].map(value => value.toString(16).padStart(2, "0")).join("");
    }

    #description(type, value) {
        if (!value || value.type !== type || typeof value.sdp !== "string" || !value.sdp.startsWith("v=0")) return null;
        return { type, sdp: value.sdp };
    }

    #candidate(value) {
        if (!value || typeof value.candidate !== "string" || value.candidate === "") return null;
        return {
            candidate: value.candidate,
            sdpMid: value.sdpMid ?? null,
            sdpMLineIndex: value.sdpMLineIndex ?? null,
            usernameFragment: value.usernameFragment ?? null
        };
    }

    #isRelayCandidate(value) {
        return /(?:^|\s)typ\s+relay(?:\s|$)/i.test(String(value?.candidate || ""));
    }

    #tokenClaims(token) {
        try {
            const [encoded, signature, extra] = String(token || "").split(".");
            if (!encoded || !signature || extra !== undefined || token.length > 2048) return null;
            const base64 = encoded.replace(/-/g, "+").replace(/_/g, "/")
                + "=".repeat((4 - encoded.length % 4) % 4);
            const claims = JSON.parse(this.#context.window.atob(base64));
            if (Number(claims?.v) !== 1
                || Number(claims?.exp) <= Math.floor(Date.now() / 1000) + TOKEN_CLOCK_SKEW_SECONDS
                || !this.#validIdentity(String(claims?.aid || ""))) return null;
            return claims;
        } catch {
            return null;
        }
    }

    #claimsMatch(claims, expected) {
        return Boolean(claims
            && Number(claims.vp) === Number(expected.viewerParticipantId)
            && Number(claims.sp) === Number(expected.sourceParticipantId)
            && String(claims.aid) === String(expected.identity));
    }

    #validIdentity(identity) {
        return /^[a-f0-9]{64}$/.test(String(identity || ""));
    }

    #clearViewerState(participantId, reason) {
        this.#clearReceived(participantId, reason);
        this.#closePeersFor(participantId, "viewer", reason);
        this.#requesting.delete(Number(participantId));
    }

    #clearReceived(participantId, reason) {
        const id = Number(participantId);
        const current = this.#received.get(id);
        if (!current) return;
        this.#context?.window?.URL?.revokeObjectURL?.(current.objectUrl);
        this.#received.delete(id);
        this.#context?.onAvatarCleared?.(id, current.identity, reason);
    }

    #closePeersFor(participantId, role, reason) {
        const id = Number(participantId);
        for (const peer of [...this.#peers.values()]) {
            if (peer.remoteId === id && (!role || peer.role === role)) this.#closePeer(peer, reason);
        }
    }

    #closePeer(peer, reason) {
        if (!peer || peer.closed) return;
        peer.closed = true;
        if (peer.timeout) this.#context?.window?.clearTimeout?.(peer.timeout);
        peer.channel?.close?.();
        peer.pc?.close?.();
        peer.outgoingCandidates = [];
        peer.incomingCandidates = [];
        peer.header = null;
        peer.chunks = [];
        peer.receivedBytes = 0;
        if (this.#peers.get(peer.token) === peer) this.#peers.delete(peer.token);
        this.#orphanCandidates.delete(peer.token);
        this.#context?.recordLifecycle?.({
            event: "p2p-avatar-peer-closed",
            role: peer.role,
            reason: String(reason || "closed")
        });
    }

    #warn(error) {
        this.#context?.warn?.(error);
    }
}

export default P2PAvatarService;
