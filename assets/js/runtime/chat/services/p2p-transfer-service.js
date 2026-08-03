import {IncrementalSha256, crc32Start, crc32Update, crc32Finish, safeRelativeZipPaths, storedZipSize, buildStoredZip} from './p2p-transfer-primitives.js';
import {P2PLocalTransferStorage} from './p2p-local-transfer-storage.js';

/******************************************************************************
 * Authenticated direct file and explicit gesture transfer service.
 *
 * Owns browser payload movement and its bounded account-to-account signaling.
 * p2p_transfer.php owns consent, authorization, privacy-safe metadata, and
 * truthful server-authoritative status; room signaling remains separate.
 ******************************************************************************/

const TERMINAL = new Set(['completed', 'failed', 'declined', 'cancelled']);
const HIGH_WATER = 4 * 1024 * 1024;
const LOW_WATER = 512 * 1024;
const CHUNK_BYTES = 64 * 1024;
const MAX_RESUME_ATTEMPTS = 3;
const serverTime = value => Date.parse(/[zZ]|[+-]\d\d:\d\d$/.test(String(value || ''))
  ? String(value) : `${String(value || '').replace(' ', 'T')}Z`);

export class P2PTransferService {
  #context = null;
  #timer = null;
  #polling = false;
  #offers = new Map();
  #sources = new Map();
  #resumeStates = new Map();
  #peers = new Map();
  #announced = new Set();
  #processedSignals = new Set();
  #previewAttempts = new Set();
  #destroyed = false;
  #localStorage = new P2PLocalTransferStorage();

  configure(context = {}) {
    this.#context = context;
  }

  start() {
    if (this.#destroyed || this.#timer) return;
    const config = this.#context?.getConfig?.() || {};
    const accountId = Number(config.myUserId || config.userId || 0);
    this.#localStorage.initialize(accountId)
      .then(() => this.#restoreDurableStates())
      .catch(error => this.#status(null, 'storage-unavailable', error.message));
    this.poll().catch(error => this.#handlePollFailure(error));
    this.#timer = this.#context?.setInterval?.(() => {
      this.poll().catch(error => this.#handlePollFailure(error));
    }, 1500) || setInterval(() => this.poll().catch(error => this.#handlePollFailure(error)), 1500);
  }

  destroy(reason = 'navigation') {
    this.#destroyed = true;
    if (this.#timer) (this.#context?.clearInterval || clearInterval)(this.#timer);
    this.#timer = null;
    for (const peer of this.#peers.values()) this.#closePeer(peer, reason);
    this.#peers.clear();
    this.#offers.clear();
    this.#localStorage.destroy().catch(() => {});
  }

  async explicitLogout() {
    for (const offer of this.#offers.values()) {
      if (!TERMINAL.has(offer.status)) await this.#update(offer.id, 'cancel').catch(() => {});
    }
    await this.#localStorage.explicitLogout();
    this.destroy('logout');
  }

  async #handlePollFailure(error) {
    const status = Number(error?.details?.status || error?.status || 0);
    const authorizationEnded = ['AUTH_REDIRECT', 'SESSION_UNAVAILABLE'].includes(String(error?.code || ''))
      || status === 401 || status === 403;
    if (!authorizationEnded) {
      this.#status(null, 'failed', error?.message || 'Transfer status could not be loaded.');
      return;
    }
    for (const [offerId, offer] of this.#offers) {
      if (TERMINAL.has(offer.status)) continue;
      this.#closePeer(this.#peers.get(offerId), 'authorization-ended');
      this.#status(offer, 'failed', 'The transfer is no longer authorized. Partial transfer data was cleared.');
    }
    await this.#localStorage.explicitLogout().catch(() => {});
    this.destroy('authorization-ended');
  }

  policy() {
    return this.#context?.getPolicy?.() || {};
  }

  async storageCapabilities(offer) {
    const required = Math.ceil(Number(offer?.size || 0) * (Number(offer?.fileCount || 1) > 1 ? 2.2 : 1.1));
    return await this.#localStorage.assertCapacity(required);
  }

  async prepareDirectToDisk(offer) {
    if (!offer) throw new Error('The direct-to-device transfer is unavailable.');
    if (Number(offer.fileCount || 1) > 1) {
      return await this.#localStorage.prepareDirectBatch(
        offer.id,
        offer.manifest,
        `CoreChat-transfer-${String(offer.id || '').slice(-8)}.zip`
      );
    }
    const file = offer.manifest?.files?.[0] || {};
    return await this.#localStorage.prepareDirectSink(
      offer.id,
      0,
      Number(file.size || offer.size || 0),
      String(file.safeName || offer.safeName || 'download.bin').split('/').pop()
    );
  }

  async createOffer({recipientParticipantId, kind = 'file', file, files = null, relayOnly = false}) {
    const rawSelected = Array.isArray(files) && files.length ? files : [file];
    const selected = rawSelected.map(item => item instanceof File
      ? {file: item, handle: null, relativePath: item.webkitRelativePath || item.relativePath || item.name}
      : {file: item?.file, handle: item?.handle || null, relativePath: item?.relativePath || item?.file?.webkitRelativePath || item?.file?.name});
    if (!selected.length || selected.length > 20 || selected.some(item => !(item.file instanceof File) || item.file.size <= 0)) {
      throw new Error('Choose between 1 and 20 files to send.');
    }
    if (['gesture', 'avatar'].includes(kind) && selected.length !== 1) {
      throw new Error(kind === 'avatar' ? 'Send one avatar at a time.' : 'Send one gesture at a time.');
    }
    const safeNames = safeRelativeZipPaths(selected.map(item => item.relativePath));
    const config = this.#context?.getConfig?.() || {};
    const epoch = this.#context?.getClientEpoch?.();
    if (!epoch) throw new Error('The direct-transfer connection is still starting. Try again shortly.');
    const sourceFiles = [];
    for (let index = 0; index < selected.length; index++) {
      const selectedFile = selected[index].file;
      const detection = await this.#detectFile(selectedFile);
      const detectedType = kind === 'avatar' ? 'avatar' : detection.category;
      if (kind === 'avatar') {
        const supported = new Set(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        if (detection.category !== 'image' || !supported.has(String(detection.detectedMime || ''))) {
          throw new Error('Choose a decoded JPEG, PNG, GIF, or WebP avatar image.');
        }
        if (!Number.isInteger(Number(selectedFile.avatarWidth))
          || !Number.isInteger(Number(selectedFile.avatarHeight))) {
          throw new Error('The avatar must be prepared through the normal avatar image checks.');
        }
      }
      const inspection = await this.#inspectFile(selectedFile, detection, kind, selected.length > 1);
      sourceFiles.push({
        file: selectedFile,
        safeName: safeNames[index],
        detectedType,
        detection,
        inspection,
        contentSha256: await this.#sha256(selectedFile),
        identity: this.#fileIdentity(selectedFile),
        handle: selected[index].handle,
      });
    }
    const manifestInput = sourceFiles.map(source => ({
      name: source.safeName,
      relative_path: source.safeName,
      size: source.file.size,
      declared_mime: source.file.type || 'application/octet-stream',
      detected_type: source.detectedType,
      detected_mime: source.detection.detectedMime,
      preview_available: kind === 'avatar' || kind === 'gesture' || source.detectedType === 'image',
      risk_class: source.inspection.riskClass,
      risk_detail: source.inspection.riskDetail,
      archive_encrypted: source.inspection.archiveEncrypted,
      archive_active_content: source.inspection.archiveActiveContent,
      archive_suspicious_paths: source.inspection.archiveSuspiciousPaths,
      archive_extreme_ratio: source.inspection.archiveExtremeRatio,
      image_width: kind === 'avatar' ? Number(source.file.avatarWidth || 0) : null,
      image_height: kind === 'avatar' ? Number(source.file.avatarHeight || 0) : null,
    }));
    const data = await this.#context.apiPost('/api/p2p_transfer.php', {
      action: 'offer',
      request_id: crypto.randomUUID(),
      session_id: config.sessionId,
      participant_id: config.myParticipantId,
      join_token: config.myJoinToken,
      recipient_participant_id: Number(recipientParticipantId),
      sender_epoch: epoch,
      kind,
      files: manifestInput,
      relay_only: Boolean(relayOnly),
    });
    if (Number(data.offer.fileCount) !== sourceFiles.length) throw new Error('The server returned a different batch manifest.');
    const contentSha256 = await this.#aggregateSha(data.offer.manifestSha256, sourceFiles.map(source => source.contentSha256));
    this.#sources.set(data.offer.id, {files: sourceFiles, contentSha256});
    this.#resumeStates.set(data.offer.id, {
      role: 'sender', contentSha256, lastAcknowledgedOffset: 0, attempts: 0,
      paused: false, cancelledFiles: new Set(),
      accountId: Number(config.myUserId || config.userId || 0),
      sourceIdentities: sourceFiles.map(source => ({
        safeName: source.safeName, size: source.file.size,
        type: source.file.type || 'application/octet-stream',
        lastModified: Number(source.file.lastModified || 0), contentSha256: source.contentSha256,
      })),
      sourceHandles: sourceFiles.map(source => source.handle || null),
      expiresAt: data.offer.expiresAt,
    });
    this.#offers.set(data.offer.id, data.offer);
    await this.#saveDurableState(data.offer.id);
    this.#status(data.offer, 'offered', 'Waiting for the recipient to accept.');
    return data.offer;
  }

  async respond(offerId, accept) {
    const config = this.#context?.getConfig?.() || {};
    const pending = this.#offers.get(offerId);
    let storageMode = 'auto';
    if (accept && pending) {
      const required = Math.ceil(Number(pending.size) * (Number(pending.fileCount) > 1 ? 2.2 : 1.1));
      const capacity = await this.#localStorage.assertCapacity(required);
      storageMode = capacity.mode;
      if (storageMode === 'direct') {
        const confirmed = await this.#context?.confirmDirectToDisk?.(pending, 'Download directly to this device requires this tab to remain open and cannot resume after refresh, browser closure, or crash.');
        if (!confirmed) throw new Error('Choose supported browser storage or explicitly select Download directly to this device.');
        if (Number(pending.fileCount) > 1) storageMode = 'direct-batch';
      }
    }
    this.#closePeer(this.#peers.get(offerId), 'preview-finished', true);
    let data;
    try {
      data = await this.#context.apiPost('/api/p2p_transfer.php', {
      action: accept ? 'accept' : 'decline',
      offer_id: offerId,
      session_id: config.sessionId,
      participant_id: config.myParticipantId,
      join_token: config.myJoinToken,
      });
    } catch (error) {
      if (String(storageMode).startsWith('direct')) await this.#localStorage.cleanupAttempt(offerId).catch(() => {});
      throw error;
    }
    this.#offers.set(offerId, data.offer);
    if (!accept) {
      await this.#localStorage.cleanupAttempt(offerId).catch(() => {});
      this.#clearTransferState(offerId);
    } else {
      const configAccount = this.#context?.getConfig?.() || {};
      this.#resumeStates.set(offerId, {
        role: 'receiver', accountId: Number(configAccount.myUserId || configAccount.userId || 0),
        contentSha256: '', receivedBytes: 0, attempts: 0, paused: false,
        currentFileIndex: 0, currentFileOffset: 0, results: [], cancelledFiles: new Set(),
        storageMode, directLive: String(storageMode).startsWith('direct'), expiresAt: data.offer.expiresAt,
      });
      await this.#saveDurableState(offerId);
    }
    this.#status(data.offer, data.offer.status, accept ? 'Accepted. Establishing the connection…' : 'Declined.');
    return data.offer;
  }

  async cancel(offerId, scope = 'batch') {
    const peer = this.#peers.get(offerId);
    const state = this.#resumeStates.get(offerId);
    if (scope === 'current' && Number(this.#offers.get(offerId)?.fileCount || 1) > 1) {
      const fileIndex = Number(state?.currentFileIndex ?? peer?.currentFileIndex ?? -1);
      if (fileIndex < 0) throw new Error('No batch file is currently active.');
      state?.cancelledFiles?.add(fileIndex);
      peer?.cancelledFiles?.add(fileIndex);
      peer?.channel?.send(JSON.stringify({kind: 'control', action: 'cancel-current', fileIndex}));
      await this.#update(offerId, 'cancel-current', {file_index: fileIndex});
      await this.#cancelCurrentLocal(offerId, fileIndex);
      this.#status(this.#offers.get(offerId), 'file-cancelled', `File ${fileIndex + 1} was cancelled. Continuing the batch.`);
      return;
    }
    try { peer?.channel?.send(JSON.stringify({kind: 'control', action: 'cancel-batch'})); } catch {}
    await this.#update(offerId, 'cancel');
    this.#closePeer(peer, 'cancelled');
    await this.#localStorage.cleanupAttempt(offerId).catch(() => {});
    this.#clearTransferState(offerId);
  }

  async pause(offerId, paused = true) {
    const state = this.#resumeStates.get(offerId);
    const peer = this.#peers.get(offerId);
    if (!state || !peer || peer.closed) throw new Error('No active transfer is available.');
    const updated = await this.#update(offerId, paused ? 'pause' : 'resume');
    state.paused = paused;
    state.pauseStartedAt = paused ? performance.now() : null;
    peer.paused = paused;
    if (peer.progress) peer.progress.lastAt = performance.now();
    peer.channel?.send(JSON.stringify({kind: 'control', action: paused ? 'pause' : 'resume'}));
    if (!paused) for (const resolve of peer.pauseWaiters.splice(0)) resolve();
    this.#status(updated, paused ? 'paused' : 'transferring', paused ? 'Paused after the last confirmed chunk.' : 'Transfer resumed. Calculating…');
  }

  async pauseAll(offerId, paused = true) {
    return await this.pause(offerId, paused);
  }

  async resumeTransfer(offerId) {
    const offer = this.#offers.get(offerId);
    const state = this.#resumeStates.get(offerId) || await this.#restoreOneState(offerId);
    if (!offer || !state || TERMINAL.has(offer.status) || serverTime(offer.expiresAt) <= Date.now()) {
      throw new Error('The retained transfer is no longer available. Create a new offer.');
    }
    if (state.role === 'sender') {
      if (!this.#sources.has(offerId)) {
        this.#context?.onReselectRequired?.(offer);
        throw new Error('Choose the original file or folder again to resume this transfer.');
      }
      this.#status(offer, 'resumable', 'Waiting for the recipient to confirm its retained offset.');
      return offer;
    }
    if (String(state.storageMode || '').startsWith('direct') && !state.directLive) throw new Error('Direct-to-device transfers cannot resume after refresh, browser closure, or crash.');
    await this.#requestResume(offer, state);
    return offer;
  }

  async reselectSources(offerId, files) {
    const offer = this.#offers.get(offerId);
    const state = this.#resumeStates.get(offerId) || await this.#restoreOneState(offerId);
    const selected = Array.from(files || []);
    if (!offer || !state || state.role !== 'sender' || selected.length !== Number(offer.fileCount)) {
      throw new Error('Choose the exact original file or folder selection.');
    }
    const paths = safeRelativeZipPaths(selected.map(file => file.webkitRelativePath || file.relativePath || file.name));
    const expected = Array.isArray(state.sourceIdentities) ? state.sourceIdentities : [];
    const sources = [];
    for (let index = 0; index < selected.length; index++) {
      const file = selected[index];
      const contentSha256 = await this.#sha256(file);
      const identity = expected[index] || {};
      if (paths[index] !== identity.safeName || file.size !== Number(identity.size)
        || (file.type || 'application/octet-stream') !== String(identity.type || 'application/octet-stream')
        || contentSha256 !== String(identity.contentSha256 || '')) {
        throw new Error('The selected source does not exactly match the accepted transfer. No file was substituted.');
      }
      const detection = await this.#detectFile(file);
      sources.push({
        file, safeName: paths[index], detectedType: detection.category, detection,
        inspection: await this.#inspectFile(file, detection, offer.kind, selected.length > 1),
        contentSha256, identity: this.#fileIdentity(file),
      });
    }
    const aggregate = await this.#aggregateSha(offer.manifestSha256, sources.map(source => source.contentSha256));
    if (aggregate !== state.contentSha256) throw new Error('The selected sources do not match the accepted transfer manifest.');
    this.#sources.set(offerId, {files: sources, contentSha256: aggregate});
    await this.#saveDurableState(offerId);
    if (offer.status === 'accepted') await this.#beginSender(offer);
    else this.#status(offer, 'resumable', 'Original sources verified. Waiting for the recipient resume request.');
    return offer;
  }

  async report(offerId, reason) {
    const config = this.#context?.getConfig?.() || {};
    const data = await this.#context.apiPost('/api/p2p_transfer.php', {
      action: 'report',
      offer_id: offerId,
      session_id: config.sessionId,
      participant_id: config.myParticipantId,
      join_token: config.myJoinToken,
      reason,
    });
    return data.offer;
  }

  async requestPreview(offerId) {
    const offer = this.#offers.get(offerId);
    if (!offer?.previewAvailable || offer.status !== 'offered') throw new Error('A preview is not available for this offer.');
    const updated = await this.#update(offerId, 'preview-request');
    this.#status(updated, 'preview-requested', 'Preview requested. The full transfer remains blocked until you accept it.');
    return updated;
  }

  async poll() {
    if (this.#polling || this.#destroyed) return;
    this.#polling = true;
    try {
      const config = this.#context?.getConfig?.() || {};
      if (!Number(config.myUserId || config.userId || 0)) return;
      const data = await this.#context.apiGet('/api/p2p_transfer.php');
      const validDurableIds = [];
      const seenOfferIds = new Set();
      for (const offer of data.offers || []) {
        seenOfferIds.add(offer.id);
        const previous = this.#offers.get(offer.id);
        this.#offers.set(offer.id, offer);
        if (offer.acceptRequired && !this.#announced.has(offer.id)) {
          this.#announced.add(offer.id);
          this.#context?.onIncomingOffer?.(offer);
        }
        if (offer.actorIsSender && offer.status === 'offered' && offer.previewRequested && offer.authorization
          && !this.#peers.has(offer.id) && !this.#previewAttempts.has(offer.id)) {
          if (this.#sources.has(offer.id)) {
            this.#previewAttempts.add(offer.id);
            await this.#beginPreviewSender(offer).catch(error => this.#status(offer, 'preview-unavailable', error.message));
          } else {
            this.#status(offer, 'preview-unavailable', 'The sender no longer has the local source needed to create a preview.');
          }
        }
        if (!TERMINAL.has(offer.status)) validDurableIds.push(offer.id);
        const state = this.#resumeStates.get(offer.id);
        if (state) {
          state.expiresAt = offer.expiresAt;
          state.cancelledFiles ||= new Set();
          for (const fileIndex of offer.cancelledFiles || []) state.cancelledFiles.add(Number(fileIndex));
          await this.#saveDurableState(offer.id);
        }
        const activePeer = this.#peers.get(offer.id);
        if (offer.actorIsSender && offer.status === 'accepted' && offer.authorization && activePeer?.previewOnly) {
          this.#closePeer(activePeer, 'preview-finished', true);
        }
        if (offer.actorIsSender && offer.status === 'accepted' && offer.authorization && !this.#peers.has(offer.id)) {
          if (this.#sources.has(offer.id)) await this.#beginSender(offer);
          else {
            this.#status(offer, 'resume-source-required', 'Choose the original file or folder again to continue this accepted transfer.');
            this.#context?.onReselectRequired?.(offer);
          }
        }
        if (!offer.actorIsSender && offer.authorization && !this.#peers.has(offer.id)
          && state && state.storageMode !== 'direct' && /^[A-F0-9]{64}$/.test(String(state.contentSha256 || ''))
          && Number(state.receivedBytes || 0) >= 0
          && ['connecting','transferring','paused'].includes(offer.status)
          && (!state.resumeRequestedAt || Date.now() - Number(state.resumeRequestedAt) > 15000)) {
          state.resumeRequestedAt = Date.now();
          await this.#requestResume(offer, state).catch(error => this.#status(offer, 'resumable', error.message));
        }
        if (!previous || previous.status !== offer.status || previous.finalStatus !== offer.finalStatus) {
          this.#status(offer, offer.status, offer.statusReason || offer.finalStatus || '');
        }
        if (TERMINAL.has(offer.status)) {
          this.#closePeer(this.#peers.get(offer.id), offer.status);
          await this.#localStorage.cleanupAttempt(offer.id).catch(() => {});
          this.#clearTransferState(offer.id);
        }
      }
      for (const [offerId, previous] of this.#offers) {
        if (seenOfferIds.has(offerId) || TERMINAL.has(previous.status)) continue;
        this.#closePeer(this.#peers.get(offerId), 'authorization-ended');
        await this.#localStorage.cleanupAttempt(offerId).catch(() => {});
        this.#clearTransferState(offerId);
        this.#offers.delete(offerId);
        this.#status(previous, 'failed', 'The transfer is no longer authorized.');
      }
      for (const signal of data.signals || []) {
        if (this.#processedSignals.has(Number(signal.id))) continue;
        const handled = await this.handleSignal(signal);
        if (!handled) continue;
        this.#processedSignals.add(Number(signal.id));
        if (this.#processedSignals.size > 500) this.#processedSignals.delete(this.#processedSignals.values().next().value);
        await this.#context.apiPost('/api/p2p_transfer.php', {action: 'signal-ack', signal_id: Number(signal.id)});
      }
      await this.#localStorage.cleanupInvalid(validDurableIds).catch(() => {});
    } finally {
      this.#polling = false;
    }
  }

  async handleSignal(signal) {
    let data = signal?.data || {};
    const offerId = String(signal?.transferId || data.transfer_id || '');
    let offer = this.#offers.get(offerId);
    if (!offer && offerId) {
      await this.poll();
      offer = this.#offers.get(offerId);
    }
    if (!offer || !offer.authorization) return false;
    if (signal.type === 'resume-request') {
      if (!offer.actorIsSender || !data.resumeAuthorization) return false;
      const source = this.#sources.get(offerId);
      const state = this.#resumeStates.get(offerId);
      const resumeOffset = Number(data.resumeOffset);
      if (!source || !state || !Array.isArray(source.files)
        || source.files.some(entry => !(entry.file instanceof File) || entry.identity !== this.#fileIdentity(entry.file))
        || !Number.isSafeInteger(resumeOffset)
        || resumeOffset < 0
        || resumeOffset >= Number(offer.size)
        || resumeOffset < Number(state.lastAcknowledgedOffset || 0)) {
        await this.#fail(this.#peers.get(offerId) || {offer, closed: false}, new Error('The retained local file does not match the partial transfer. Create a new offer.'));
        return true;
      }
      const verified = await this.#update(offerId, 'resume-verify', {
        resume_authorization: data.resumeAuthorization,
        content_sha256: source.contentSha256,
      });
      if (Number(verified.resumeOffset) !== resumeOffset) throw new Error('The resume offset was not authorized.');
      state.lastAcknowledgedOffset = resumeOffset;
      state.attempts = Math.max(Number(state.attempts || 0), 1);
      await this.#beginSender(verified, data.resumeAuthorization, resumeOffset);
      return true;
    }
    data = await this.#openSignal(offer, String(signal.type || ''), data.sealed);
    const signalPreviewOnly = Boolean(data.previewOnly);
    if (signalPreviewOnly && offer.status !== 'offered') return true;
    let peer = this.#peers.get(offerId);
    if (peer && peer.previewOnly !== signalPreviewOnly) return true;
    const resumeOffset = Number(data.resumeOffset || data.resume_offset || 0);
    if (!peer) peer = this.#createPeer(offer, offer.authorization, false, resumeOffset, Boolean(data.resuming), signalPreviewOnly);
    if (signal.type === 'offer') {
      if (resumeOffset !== peer.resumeOffset) throw new Error('The resume offset changed during negotiation.');
      await peer.pc.setRemoteDescription(data.description);
      const answer = await peer.pc.createAnswer();
      await peer.pc.setLocalDescription(answer);
      await this.#signalDescription(peer, 'answer', {description: peer.pc.localDescription});
    } else if (signal.type === 'answer') {
      await peer.pc.setRemoteDescription(data.description);
    } else if (signal.type === 'ice') {
      await peer.pc.addIceCandidate(data.candidate);
    }
    return true;
  }

  async #restoreDurableStates() {
    const states = await this.#localStorage.listStates();
    for (const record of states) {
      if (serverTime(record.expiresAt) <= Date.now()) {
        await this.#localStorage.cleanupAttempt(record.id);
        continue;
      }
      this.#resumeStates.set(record.id, {
        ...record,
        cancelledFiles: new Set(Array.isArray(record.cancelledFiles) ? record.cancelledFiles.map(Number) : []),
        results: Array.isArray(record.results) ? record.results : [],
      });
      if (record.role === 'sender') await this.#restoreSenderSources(record).catch(() => {});
    }
  }

  async #restoreOneState(offerId) {
    const record = await this.#localStorage.loadState(offerId);
    if (!record) return null;
    const state = {
      ...record,
      cancelledFiles: new Set(Array.isArray(record.cancelledFiles) ? record.cancelledFiles.map(Number) : []),
      results: Array.isArray(record.results) ? record.results : [],
    };
    this.#resumeStates.set(offerId, state);
    if (record.role === 'sender') await this.#restoreSenderSources(record).catch(() => {});
    return state;
  }

  async #restoreSenderSources(record) {
    if (!Array.isArray(record.sourceHandles) || !Array.isArray(record.sourceIdentities)
      || record.sourceHandles.length !== record.sourceIdentities.length || !record.sourceHandles.length) return false;
    const sources = [];
    for (let index = 0; index < record.sourceHandles.length; index++) {
      const handle = record.sourceHandles[index];
      const expected = record.sourceIdentities[index];
      if (!handle || typeof handle.queryPermission !== 'function' || await handle.queryPermission({mode: 'read'}) !== 'granted') return false;
      const file = await handle.getFile();
      const contentSha256 = await this.#sha256(file);
      if (file.size !== Number(expected.size) || (file.type || 'application/octet-stream') !== String(expected.type)
        || contentSha256 !== String(expected.contentSha256 || '')) return false;
      const detection = await this.#detectFile(file);
      sources.push({
        file, handle, safeName: String(expected.safeName), detectedType: detection.category,
        inspection: null, detection, contentSha256, identity: this.#fileIdentity(file),
      });
    }
    this.#sources.set(record.id, {files: sources, contentSha256: String(record.contentSha256 || '')});
    return true;
  }

  async #saveDurableState(offerId) {
    const state = this.#resumeStates.get(offerId);
    const offer = this.#offers.get(offerId);
    if (!state || !offer) return;
    if (String(state.storageMode || '').startsWith('direct')) return;
    const config = this.#context?.getConfig?.() || {};
    await this.#localStorage.saveState({
      id: offerId,
      accountId: Number(state.accountId || config.myUserId || config.userId || 0),
      role: state.role,
      expiresAt: String(offer.expiresAt || state.expiresAt || ''),
      manifestSha256: String(offer.manifestSha256 || ''),
      contentSha256: String(state.contentSha256 || ''),
      metadata: state.metadata ? {
        id: String(state.metadata.id || offerId),
        manifestSha256: String(state.metadata.manifestSha256 || ''),
        fileCount: Number(state.metadata.fileCount || 0),
        totalSize: Number(state.metadata.totalSize || 0),
        transferKind: String(state.metadata.transferKind || ''),
        contentSha256: String(state.metadata.contentSha256 || ''),
        fileSha256: Array.isArray(state.metadata.fileSha256) ? state.metadata.fileSha256.map(String) : [],
        resume: true,
      } : null,
      receivedBytes: Number(state.receivedBytes || 0),
      lastAcknowledgedOffset: Number(state.lastAcknowledgedOffset || 0),
      currentFileIndex: Number(state.currentFileIndex || 0),
      currentFileOffset: Number(state.currentFileOffset || 0),
      paused: Boolean(state.paused),
      storageMode: String(state.storageMode || 'auto'),
      sourceIdentities: Array.isArray(state.sourceIdentities) ? state.sourceIdentities : [],
      sourceHandles: Array.isArray(state.sourceHandles) ? state.sourceHandles : [],
      cancelledFiles: [...(state.cancelledFiles || [])].map(Number),
      results: (state.results || []).map(result => ({
        fileIndex: Number(result.fileIndex), status: String(result.status || ''),
        reason: String(result.reason || ''), name: String(result.name || ''),
        size: Number(result.size || 0), crc32: Number(result.crc32 || 0),
      })),
    });
  }

  async #beginSender(offer, resumeAuthorization = null, resumeOffset = 0) {
    const source = this.#sources.get(offer.id);
    if (!source || !Array.isArray(source.files)
      || source.files.some(entry => !(entry.file instanceof File) || entry.identity !== this.#fileIdentity(entry.file))) {
      throw new Error('The selected local file is no longer available. Choose it again.');
    }
    if (!resumeAuthorization) offer = await this.#update(offer.id, 'connecting');
    const existing = this.#peers.get(offer.id);
    if (existing) this.#closePeer(existing, 'retry', true);
    const peer = this.#createPeer(offer, resumeAuthorization || offer.authorization, true, resumeOffset, Boolean(resumeAuthorization), false);
    peer.source = source;
    peer.contentSha256 = source.contentSha256;
    const channel = peer.pc.createDataChannel('corechat-transfer-v1', {ordered: true});
    this.#configureChannel(peer, channel);
    const description = await peer.pc.createOffer();
    await peer.pc.setLocalDescription(description);
    await this.#signalDescription(peer, 'offer', {description: peer.pc.localDescription, resuming: peer.resuming});
  }

  async #beginPreviewSender(offer) {
    const source = this.#sources.get(offer.id);
    if (!source?.files?.length || source.files.length !== 1) throw new Error('A bounded preview is not available for this offer.');
    const existing = this.#peers.get(offer.id);
    if (existing) this.#closePeer(existing, 'preview-retry', true);
    const peer = this.#createPeer(offer, offer.authorization, true, 0, false, true);
    peer.source = source;
    const channel = peer.pc.createDataChannel('corechat-transfer-preview-v1', {ordered: true});
    this.#configureChannel(peer, channel);
    const description = await peer.pc.createOffer();
    await peer.pc.setLocalDescription(description);
    await this.#signalDescription(peer, 'offer', {description: peer.pc.localDescription, previewOnly: true});
  }

  #createPeer(offer, authorization, sender, resumeOffset = 0, resuming = false, previewOnly = false) {
    const policy = this.policy();
    const pc = new RTCPeerConnection({iceServers: policy.iceServers || []});
    const remoteParticipantId = Number(sender ? offer.recipient.participantId : offer.sender.participantId);
    const retained = this.#resumeStates.get(offer.id);
    if (resuming) {
      const retainedOffset = sender ? Number(retained?.lastAcknowledgedOffset || 0) : Number(retained?.receivedBytes || 0);
      if (!retained || retainedOffset !== resumeOffset || Number(retained.attempts || 0) > MAX_RESUME_ATTEMPTS) {
        throw new Error('The retained partial transfer is unavailable. Create a new offer.');
      }
    }
    const peer = {
      offer, authorization, sender, pc, remoteParticipantId, channel: null,
      chunks: [],
      receivedBytes: sender ? 0 : Number(retained?.receivedBytes || 0),
      metadata: sender ? null : (retained?.metadata || null), expectedChunk: null,
      source: null, contentSha256: sender ? retained?.contentSha256 : retained?.contentSha256,
      lastAcknowledgedOffset: sender ? Number(retained?.lastAcknowledgedOffset || 0) : 0,
      highestSentOffset: sender ? Number(retained?.lastAcknowledgedOffset || 0) : 0,
      acknowledgementWaiters: [], resumeOffsetWaiters: [], fileResultWaiters: new Map(), resumeOffset, resuming, previewOnly,
      pauseWaiters: [], paused: Boolean(retained?.paused),
      currentFileIndex: Number(retained?.currentFileIndex || 0),
      currentFileOffset: Number(retained?.currentFileOffset || 0),
      currentSink: retained?.currentSink || null,
      currentDigest: retained?.currentDigest || null,
      currentCrc: retained?.currentCrc ?? crc32Start(),
      currentSamplePrefix: retained?.currentSamplePrefix || new Uint8Array(0),
      currentSampleTail: retained?.currentSampleTail || new Uint8Array(0),
      results: retained?.results || [],
      cancelledFiles: retained?.cancelledFiles || new Set(),
      progress: this.#newProgress(Number(offer.size), Number(resumeOffset || 0)),
      receiveQueue: Promise.resolve(), pendingLocalCandidates: [], descriptionSignaled: false,
      recovering: false, closed: false,
    };
    this.#peers.set(offer.id, peer);
    pc.onicecandidate = event => {
      if (!event.candidate) return;
      const candidate = event.candidate.toJSON();
      if (!peer.descriptionSignaled) {
        peer.pendingLocalCandidates.push(candidate);
        return;
      }
      this.#signal(peer, 'ice', {candidate}).catch(error => this.#fail(peer, error));
    };
    pc.ondatachannel = event => this.#configureChannel(peer, event.channel);
    pc.onconnectionstatechange = () => {
      if (pc.connectionState === 'connected') this.#connectionEstablished(peer).catch(error => this.#fail(peer, error));
      if (['failed','disconnected'].includes(pc.connectionState)) this.#recover(peer, new Error('Direct connection could not be established.'));
    };
    return peer;
  }

  #configureChannel(peer, channel) {
    peer.channel = channel;
    channel.binaryType = 'arraybuffer';
    channel.bufferedAmountLowThreshold = LOW_WATER;
    channel.onopen = () => {
      if (peer.sender) (peer.previewOnly ? this.#sendPreview(peer) : this.#sendFile(peer)).catch(error => {
        if (error?.message === 'Direct connection could not be established.') this.#recover(peer, error);
        else this.#fail(peer, error);
      });
    };
    channel.onmessage = event => {
      peer.receiveQueue = peer.receiveQueue
        .then(() => this.#receive(peer, event.data))
        .catch(error => this.#fail(peer, error));
    };
    channel.onerror = () => this.#recover(peer, new Error('Direct connection could not be established.'));
  }

  async #connectionEstablished(peer) {
    if (peer.previewOnly) {
      this.#status(peer.offer, 'preview-connecting', 'Preparing a bounded local preview. The full transfer is not authorized.');
      return;
    }
    const retained = this.#resumeStates.get(peer.offer.id);
    if (retained) {
      retained.attempts = 0;
      retained.resumeRequestedAt = 0;
      await this.#saveDurableState(peer.offer.id).catch(() => {});
    }
    const connection = await this.#connectionType(peer.pc);
    if (peer.offer.actorIsSender && (!peer.resuming || peer.offer.status === 'connecting')) {
      await this.#update(peer.offer.id, connection === 'relayed' ? 'relayed' : 'direct');
    }
    const prefix = peer.resuming ? `Resumed at ${peer.resumeOffset} bytes. ` : '';
    this.#status(peer.offer, 'transferring', prefix + (connection === 'relayed' ? 'Relayed connection' : 'Direct connection'));
  }

  async #connectionType(pc) {
    const stats = await pc.getStats();
    const entries = new Map();
    stats.forEach(entry => entries.set(entry.id, entry));
    for (const entry of entries.values()) {
      if (entry.type !== 'candidate-pair' || !entry.nominated || entry.state !== 'succeeded') continue;
      const local = entries.get(entry.localCandidateId);
      const remote = entries.get(entry.remoteCandidateId);
      if (local?.candidateType === 'relay' || remote?.candidateType === 'relay') return 'relayed';
    }
    return 'direct';
  }

  async #sanitizePreviewImage(blob) {
    const bitmap = await createImageBitmap(blob);
    try {
      const scale = Math.min(1, 320 / bitmap.width, 320 / bitmap.height);
      const width = Math.max(1, Math.floor(bitmap.width * scale));
      const height = Math.max(1, Math.floor(bitmap.height * scale));
      let output;
      if (typeof OffscreenCanvas === 'function') {
        const canvas = new OffscreenCanvas(width, height);
        const context = canvas.getContext('2d', {alpha: false});
        context.fillStyle = '#ffffff'; context.fillRect(0, 0, width, height);
        context.drawImage(bitmap, 0, 0, width, height);
        output = await canvas.convertToBlob({type: 'image/jpeg', quality: 0.76});
      } else {
        const canvas = document.createElement('canvas');
        canvas.width = width; canvas.height = height;
        const context = canvas.getContext('2d', {alpha: false});
        context.fillStyle = '#ffffff'; context.fillRect(0, 0, width, height);
        context.drawImage(bitmap, 0, 0, width, height);
        output = await new Promise((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('The preview could not be sanitized.')), 'image/jpeg', 0.76));
      }
      if (!(output instanceof Blob) || output.size <= 0 || output.size > 128 * 1024) throw new Error('The sanitized preview exceeds the bounded preview size.');
      return output;
    } finally {
      bitmap.close?.();
    }
  }

  async #sendPreview(peer) {
    const entry = peer.source?.files?.[0];
    if (!entry) throw new Error('The local source needed for this preview is unavailable.');
    let previewKind = 'text';
    let previewText = '';
    let previewBlob = null;
    if (entry.detection?.category === 'image') {
      previewKind = 'image';
      previewBlob = await this.#sanitizePreviewImage(entry.file);
    } else if (peer.offer.kind === 'gesture') {
      const gesture = JSON.parse(await entry.file.text());
      previewText = `${String(gesture?.title || 'Gesture').slice(0, 120)}${gesture?.text ? ` â€” ${String(gesture.text).slice(0, 240)}` : ''}`;
      const animation = String(gesture?.animation || '');
      if (/^data:image\/(?:gif|png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/i.test(animation) && animation.length <= 512 * 1024) {
        previewBlob = await this.#sanitizePreviewImage(await (await fetch(animation)).blob());
        previewKind = 'image';
      }
    } else {
      throw new Error('A safe preview is not available for this file.');
    }
    peer.channel.send(JSON.stringify({kind: 'preview-metadata', previewKind, size: Number(previewBlob?.size || 0), text: previewText}));
    if (previewBlob) peer.channel.send(await previewBlob.arrayBuffer());
    else peer.channel.send(JSON.stringify({kind: 'preview-complete'}));
    this.#status(peer.offer, 'preview-sent', 'A bounded preview was sent. The full transfer remains blocked until acceptance.');
  }

  async #receivePreview(peer, payload) {
    if (typeof payload === 'string') {
      const message = JSON.parse(payload);
      if (message.kind === 'preview-metadata') {
        const size = Number(message.size || 0);
        if (!['image','text'].includes(String(message.previewKind || '')) || size < 0 || size > 128 * 1024 || String(message.text || '').length > 500) {
          throw new Error('The bounded preview metadata is invalid.');
        }
        peer.previewMetadata = {kind: String(message.previewKind), size, text: String(message.text || '')};
        if (size === 0) {
          this.#context?.onPreview?.({offer: peer.offer, kind: 'text', text: peer.previewMetadata.text});
          peer.channel.send(JSON.stringify({kind: 'preview-received'}));
        }
      } else if (message.kind === 'preview-received' && peer.sender) {
        this.#closePeer(peer, 'preview-finished', true);
      } else if (message.kind !== 'preview-complete') {
        throw new Error('The bounded preview state is invalid.');
      }
      return;
    }
    if (peer.sender || peer.previewMetadata?.kind !== 'image' || Number(peer.previewMetadata.size) <= 0) throw new Error('The bounded preview payload is unexpected.');
    const bytes = payload instanceof ArrayBuffer ? payload : await payload.arrayBuffer();
    if (bytes.byteLength !== Number(peer.previewMetadata.size) || bytes.byteLength > 128 * 1024) throw new Error('The bounded preview size is invalid.');
    const sanitized = await this.#sanitizePreviewImage(new Blob([bytes], {type: 'image/jpeg'}));
    this.#context?.onPreview?.({offer: peer.offer, kind: 'image', blob: sanitized, text: peer.previewMetadata.text});
    peer.channel.send(JSON.stringify({kind: 'preview-received'}));
    this.#status(peer.offer, 'preview-ready', 'Preview ready. Accept or decline the full transfer separately.');
  }

  async #sendFile(peer) {
    const source = peer.source;
    const files = source.files;
    peer.channel.send(JSON.stringify({
      kind: 'batch-metadata', id: peer.offer.id, manifestSha256: peer.offer.manifestSha256,
      fileCount: files.length, totalSize: Number(peer.offer.size), transferKind: peer.offer.kind,
      contentSha256: peer.contentSha256, fileSha256: files.map(entry => entry.contentSha256),
      resume: peer.resuming,
    }));
    let startOffset = 0;
    if (peer.resuming) {
      startOffset = await this.#waitForResumeOffset(peer);
      if (startOffset !== peer.resumeOffset) throw new Error('The receiver resume offset changed. Create a new offer.');
      this.#status(peer.offer, 'resuming', `Resuming from ${startOffset} of ${peer.offer.size} bytes`);
    }
    const start = this.#positionForOffset(files, startOffset);
    for (let fileIndex = start.fileIndex; fileIndex < files.length; fileIndex++) {
      const entry = files[fileIndex];
      const fileStart = this.#filePrefixBytes(files, fileIndex);
      peer.currentFileIndex = fileIndex;
      const senderState = this.#resumeStates.get(peer.offer.id);
      if (senderState) senderState.currentFileIndex = fileIndex;
      if (senderState?.cancelledFiles?.has(fileIndex)) {
        peer.channel.send(JSON.stringify({kind: 'file-skipped', fileIndex, status: 'cancelled'}));
        peer.lastAcknowledgedOffset = fileStart + entry.file.size;
        peer.highestSentOffset = peer.lastAcknowledgedOffset;
        continue;
      }
      let fileOffset = fileIndex === start.fileIndex ? start.fileOffset : 0;
      peer.channel.send(JSON.stringify({
        kind: 'file-metadata', fileIndex, aggregateOffset: fileStart + fileOffset,
        fileOffset, name: entry.safeName, size: entry.file.size,
        type: entry.file.type || 'application/octet-stream', detectedType: entry.detectedType,
        contentSha256: entry.contentSha256,
      }));
      while (fileOffset < entry.file.size) {
        if (peer.closed) return;
        await this.#waitWhilePaused(peer);
        if (senderState?.cancelledFiles?.has(fileIndex)) break;
        if (peer.channel.bufferedAmount > HIGH_WATER) await new Promise(resolve => peer.channel.addEventListener('bufferedamountlow', resolve, {once: true}));
        const chunk = await entry.file.slice(fileOffset, fileOffset + CHUNK_BYTES).arrayBuffer();
        const aggregateOffset = fileStart + fileOffset;
        peer.channel.send(JSON.stringify({kind: 'chunk', fileIndex, fileOffset, aggregateOffset, size: chunk.byteLength}));
        peer.channel.send(chunk);
        fileOffset += chunk.byteLength;
        const sent = fileStart + fileOffset;
        peer.highestSentOffset = sent;
        if (fileOffset === entry.file.size || fileOffset % (1024 * 1024) === 0) await this.#waitForAcknowledgement(peer, sent);
        this.#emitProgress(peer, sent, fileIndex, fileOffset, entry.file.size, 'sent');
      }
      if (senderState?.cancelledFiles?.has(fileIndex)) {
        peer.channel.send(JSON.stringify({kind: 'file-skipped', fileIndex, status: 'cancelled'}));
        peer.lastAcknowledgedOffset = fileStart + entry.file.size;
        peer.highestSentOffset = peer.lastAcknowledgedOffset;
        continue;
      }
      peer.channel.send(JSON.stringify({kind: 'file-complete', fileIndex}));
      const result = await this.#waitForFileResult(peer, fileIndex);
      if (result.status === 'failed') this.#status(peer.offer, 'file-failed', `${entry.safeName}: ${result.reason || 'Integrity verification failed.'}`);
    }
    peer.channel.send(JSON.stringify({kind: 'batch-complete'}));
    this.#status(peer.offer, 'transferring', 'Files sent. Waiting for recipient integrity verification and local output.');
  }

  async #receive(peer, payload) {
    if (peer.previewOnly) return await this.#receivePreview(peer, payload);
    if (typeof payload === 'string') {
      if (payload.length > 65536) throw new Error('The transfer control message exceeded its bounded size.');
      const message = JSON.parse(payload);
      if (message.kind === 'batch-metadata') {
        if (message.id !== peer.offer.id
          || Number(message.totalSize) !== Number(peer.offer.size)
          || Number(message.fileCount) !== Number(peer.offer.fileCount)
          || String(message.manifestSha256 || '') !== String(peer.offer.manifestSha256 || '')
          || String(message.transferKind || '') !== String(peer.offer.kind || '')
          || !Array.isArray(message.fileSha256)
          || message.fileSha256.length !== Number(peer.offer.fileCount)
          || message.fileSha256.some(value => !/^[A-F0-9]{64}$/.test(String(value || '')))
          || !/^[A-F0-9]{64}$/.test(String(message.contentSha256 || ''))) {
          throw new Error('Transfer metadata did not match the accepted offer.');
        }
        if (peer.metadata) {
          if (!peer.resuming
            || peer.metadata.contentSha256 !== message.contentSha256
            || Number(peer.receivedBytes) !== Number(peer.resumeOffset)
            || peer.metadata.contentSha256 !== message.contentSha256
            || message.resume !== true) {
            throw new Error('The retained partial transfer did not match the resumed file.');
          }
        } else if (peer.resuming || message.resume === true || peer.receivedBytes !== 0) {
          throw new Error('The retained partial transfer is unavailable. Create a new offer.');
        }
        peer.metadata = message;
        peer.contentSha256 = message.contentSha256;
        const retained = this.#resumeStates.get(peer.offer.id) || {};
        this.#resumeStates.set(peer.offer.id, {
          ...retained, role: 'receiver', contentSha256: message.contentSha256,
          metadata: message, receivedBytes: peer.receivedBytes,
          attempts: Number(retained.attempts || 0), paused: Boolean(retained.paused),
          results: peer.results, cancelledFiles: peer.cancelledFiles,
        });
        await this.#saveDurableState(peer.offer.id);
        if (peer.resuming) {
          peer.channel.send(JSON.stringify({kind: 'resume-offset', offset: peer.receivedBytes}));
        }
      } else if (message.kind === 'file-metadata') {
        await this.#beginReceiveFile(peer, message);
      } else if (message.kind === 'chunk') {
        const aggregateOffset = Number(message.aggregateOffset);
        const fileOffset = Number(message.fileOffset);
        const fileIndex = Number(message.fileIndex);
        const size = Number(message.size);
        if (!peer.metadata || peer.sender || peer.expectedChunk
          || fileIndex !== peer.currentFileIndex
          || fileOffset !== peer.currentFileOffset
          || aggregateOffset !== peer.receivedBytes
          || size <= 0 || size > CHUNK_BYTES) {
          throw new Error('Transfer chunk metadata was invalid.');
        }
        peer.expectedChunk = {aggregateOffset, fileOffset, fileIndex, size};
      } else if (message.kind === 'ack') {
        if (!peer.sender) throw new Error('Transfer acknowledgement was invalid.');
        const offset = Number(message.offset);
        if (!Number.isSafeInteger(offset)
          || offset <= peer.lastAcknowledgedOffset
          || offset > peer.highestSentOffset
          || offset > Number(peer.offer.size)) {
          throw new Error('Transfer acknowledgement offset was invalid.');
        }
        peer.lastAcknowledgedOffset = offset;
        const senderState = this.#resumeStates.get(peer.offer.id);
        if (senderState) senderState.lastAcknowledgedOffset = offset;
        await this.#saveDurableState(peer.offer.id);
        this.#resolveAcknowledgements(peer);
      } else if (message.kind === 'resume-offset') {
        if (!peer.sender || !peer.resuming || peer.resumeOffset < 0 || Number(message.offset) !== peer.resumeOffset) {
          throw new Error('The receiver resume offset was invalid.');
        }
        for (const waiter of peer.resumeOffsetWaiters.splice(0)) waiter.resolve(peer.resumeOffset);
      } else if (message.kind === 'file-complete') {
        await this.#completeReceiveFile(peer, Number(message.fileIndex));
      } else if (message.kind === 'file-result') {
        if (!peer.sender) throw new Error('The file result was invalid.');
        this.#resolveFileResult(peer, message);
      } else if (message.kind === 'file-skipped') {
        await this.#skipReceiveFile(peer, Number(message.fileIndex), String(message.status || 'cancelled'));
      } else if (message.kind === 'batch-complete') {
        await this.#completeReceiveBatch(peer);
      } else if (message.kind === 'verified') {
        if (!peer.sender) throw new Error('Transfer verification was invalid.');
        this.#resumeStates.delete(peer.offer.id);
        this.#sources.delete(peer.offer.id);
        await this.#localStorage.cleanupAttempt(peer.offer.id).catch(() => {});
        this.#status(peer.offer, 'completed', 'Recipient verified the complete file.');
      } else if (message.kind === 'control') {
        await this.#receiveControl(peer, message);
      }
      return;
    }
    const chunk = payload instanceof ArrayBuffer ? payload : await payload.arrayBuffer();
    if (peer.sender || !peer.expectedChunk || chunk.byteLength !== peer.expectedChunk.size) throw new Error('The received transfer chunk was unexpected.');
    const bytes = new Uint8Array(chunk);
    await peer.currentSink.write(peer.currentFileOffset, bytes);
    peer.currentDigest.update(bytes);
    peer.currentCrc = crc32Update(peer.currentCrc, bytes);
    if (peer.currentSamplePrefix.byteLength < 4096) {
      const take = bytes.subarray(0, Math.min(bytes.byteLength, 4096 - peer.currentSamplePrefix.byteLength));
      const next = new Uint8Array(peer.currentSamplePrefix.byteLength + take.byteLength);
      next.set(peer.currentSamplePrefix);
      next.set(take, peer.currentSamplePrefix.byteLength);
      peer.currentSamplePrefix = next;
    }
    const tailInput = new Uint8Array(peer.currentSampleTail.byteLength + bytes.byteLength);
    tailInput.set(peer.currentSampleTail);
    tailInput.set(bytes, peer.currentSampleTail.byteLength);
    peer.currentSampleTail = tailInput.byteLength > 65557 ? tailInput.slice(tailInput.byteLength - 65557) : tailInput;
    peer.currentFileOffset += chunk.byteLength;
    peer.receivedBytes += chunk.byteLength;
    if (peer.receivedBytes > Number(peer.offer.size)) throw new Error('The received file exceeded the accepted size.');
    peer.expectedChunk = null;
    const receiverState = this.#resumeStates.get(peer.offer.id);
    if (receiverState) {
      receiverState.receivedBytes = peer.receivedBytes;
      receiverState.currentFileIndex = peer.currentFileIndex;
      receiverState.currentFileOffset = peer.currentFileOffset;
      receiverState.currentSink = peer.currentSink;
      receiverState.currentDigest = peer.currentDigest;
      receiverState.currentCrc = peer.currentCrc;
      receiverState.currentSamplePrefix = peer.currentSamplePrefix;
      receiverState.currentSampleTail = peer.currentSampleTail;
    }
    peer.channel.send(JSON.stringify({kind: 'ack', offset: peer.receivedBytes}));
    await this.#saveDurableState(peer.offer.id);
    const file = peer.offer.manifest.files[peer.currentFileIndex];
    this.#emitProgress(peer, peer.receivedBytes, peer.currentFileIndex, peer.currentFileOffset, Number(file.size), 'received');
  }

  #filePrefixBytes(files, fileIndex) {
    let total = 0;
    for (let index = 0; index < fileIndex; index++) total += Number(files[index]?.file?.size ?? files[index]?.size ?? 0);
    return total;
  }

  #positionForOffset(files, offset) {
    let remaining = Number(offset);
    if (!Number.isSafeInteger(remaining) || remaining < 0) throw new Error('The resume offset is invalid.');
    for (let fileIndex = 0; fileIndex < files.length; fileIndex++) {
      const size = Number(files[fileIndex]?.file?.size ?? files[fileIndex]?.size ?? 0);
      if (remaining < size) return {fileIndex, fileOffset: remaining};
      remaining -= size;
    }
    if (remaining === 0) return {fileIndex: files.length, fileOffset: 0};
    throw new Error('The resume offset exceeded the accepted manifest.');
  }

  async #aggregateSha(manifestSha256, hashes) {
    const material = `${String(manifestSha256 || '').toUpperCase()}\n${hashes.map(value => String(value).toUpperCase()).join('\n')}`;
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(material));
    return [...new Uint8Array(digest)].map(value => value.toString(16).padStart(2, '0')).join('').toUpperCase();
  }

  #newProgress(totalBytes, completedBytes = 0) {
    const now = performance.now();
    return {
      totalBytes, completedBytes, startedAt: now, lastAt: now, lastBytes: completedBytes,
      activeMilliseconds: 0, rateBytesPerSecond: null, lastEmissionAt: 0,
    };
  }

  #emitProgress(peer, aggregateBytes, fileIndex, fileBytes, fileTotalBytes, direction) {
    const now = performance.now();
    const progress = peer.progress || (peer.progress = this.#newProgress(Number(peer.offer.size), aggregateBytes));
    const elapsed = Math.max(0, now - progress.lastAt);
    const delta = Math.max(0, Number(aggregateBytes) - progress.lastBytes);
    if (!peer.paused && elapsed > 0) {
      progress.activeMilliseconds += elapsed;
      if (delta > 0) {
        const instant = delta / (elapsed / 1000);
        progress.rateBytesPerSecond = progress.rateBytesPerSecond === null ? instant : (progress.rateBytesPerSecond * 0.75 + instant * 0.25);
      }
    }
    progress.lastAt = now;
    progress.lastBytes = Number(aggregateBytes);
    progress.completedBytes = Number(aggregateBytes);
    if (now - progress.lastEmissionAt < 500 && aggregateBytes < progress.totalBytes) return;
    progress.lastEmissionAt = now;
    const remaining = Math.max(0, progress.totalBytes - progress.completedBytes);
    const etaSeconds = progress.rateBytesPerSecond && progress.rateBytesPerSecond > 0
      ? Math.max(0, Math.round(remaining / progress.rateBytesPerSecond)) : null;
    const detail = progress.rateBytesPerSecond === null
      ? 'Calculating...'
      : `${Math.round(progress.rateBytesPerSecond)} bytes/s${etaSeconds === null ? '' : `, about ${etaSeconds} seconds remaining`}`;
    this.#context?.onStatus?.({
      offer: peer.offer, state: peer.paused ? 'paused' : 'transferring', detail,
      progress: {
        direction, fileIndex, fileBytes: Number(fileBytes), fileTotalBytes: Number(fileTotalBytes),
        aggregateBytes: progress.completedBytes, aggregateTotalBytes: progress.totalBytes,
        filePercent: fileTotalBytes > 0 ? Math.min(100, Number(fileBytes) / Number(fileTotalBytes) * 100) : 0,
        aggregatePercent: progress.totalBytes > 0 ? Math.min(100, progress.completedBytes / progress.totalBytes * 100) : 0,
        rateBytesPerSecond: progress.rateBytesPerSecond, etaSeconds,
      },
    });
  }

  async #waitWhilePaused(peer) {
    if (!peer.paused) return;
    await new Promise(resolve => peer.pauseWaiters.push(resolve));
  }

  async #waitForFileResult(peer, fileIndex) {
    return await new Promise((resolve, reject) => {
      const timeout = setTimeout(() => {
        peer.fileResultWaiters.delete(fileIndex);
        reject(new Error('Direct connection could not be established.'));
      }, 30000);
      peer.fileResultWaiters.set(fileIndex, result => {
        clearTimeout(timeout);
        resolve(result);
      });
    });
  }

  #resolveFileResult(peer, message) {
    const fileIndex = Number(message.fileIndex);
    const resolve = peer.fileResultWaiters.get(fileIndex);
    if (!resolve && peer.cancelledFiles.has(fileIndex) && String(message.status || '') === 'cancelled') return;
    if (!resolve || !['completed','failed','cancelled'].includes(String(message.status || ''))) {
      throw new Error('The file result was invalid.');
    }
    peer.fileResultWaiters.delete(fileIndex);
    resolve({status: String(message.status), reason: String(message.reason || '')});
  }

  async #beginReceiveFile(peer, message) {
    const fileIndex = Number(message.fileIndex);
    const manifestFile = peer.offer.manifest?.files?.[fileIndex];
    const fileOffset = Number(message.fileOffset);
    const aggregateOffset = Number(message.aggregateOffset);
    const prefix = this.#filePrefixBytes(peer.offer.manifest.files, fileIndex);
    if (!peer.metadata || peer.sender || !manifestFile || fileIndex < 0
      || String(message.name || '') !== String(manifestFile.safeName || '')
      || Number(message.size) !== Number(manifestFile.size)
      || String(message.type || '') !== String(manifestFile.declaredMime || '')
      || String(message.detectedType || '') !== String(manifestFile.detectedType || '')
      || String(message.contentSha256 || '') !== String(peer.metadata.fileSha256?.[fileIndex] || '')
      || !Number.isSafeInteger(fileOffset) || fileOffset < 0 || fileOffset >= Number(manifestFile.size)
      || aggregateOffset !== prefix + fileOffset || aggregateOffset !== Number(peer.receivedBytes)) {
      throw new Error('The file metadata did not match the accepted manifest.');
    }
    const retained = this.#resumeStates.get(peer.offer.id) || {};
    const mode = String(retained.storageMode || 'auto');
    const sink = await this.#localStorage.createSink(peer.offer.id, fileIndex, Number(manifestFile.size), {
      resume: fileOffset > 0, mode, suggestedName: String(manifestFile.safeName || 'download.bin').split('/').pop(),
    });
    if (Number(sink.written || 0) !== fileOffset) {
      await sink.cancel().catch(() => {});
      throw new Error('The retained local file did not match the receiver-confirmed offset.');
    }
    const digest = new IncrementalSha256();
    let crc = crc32Start();
    let samplePrefix = new Uint8Array(0);
    let sampleTail = new Uint8Array(0);
    if (fileOffset > 0) {
      const prefixBlob = (await sink.prefix()).slice(0, fileOffset);
      samplePrefix = new Uint8Array(await prefixBlob.slice(0, 4096).arrayBuffer());
      sampleTail = new Uint8Array(await prefixBlob.slice(Math.max(0, prefixBlob.size - 65557)).arrayBuffer());
      const reader = prefixBlob.stream().getReader();
      while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        digest.update(value);
        crc = crc32Update(crc, value);
      }
    }
    peer.currentFileIndex = fileIndex;
    peer.currentFileOffset = fileOffset;
    peer.currentSink = sink;
    peer.currentDigest = digest;
    peer.currentCrc = crc;
    peer.currentSamplePrefix = samplePrefix;
    peer.currentSampleTail = sampleTail;
    retained.currentFileIndex = fileIndex;
    retained.currentFileOffset = fileOffset;
    retained.storageMode = sink.mode;
    retained.currentSamplePrefix = samplePrefix;
    retained.currentSampleTail = sampleTail;
    this.#resumeStates.set(peer.offer.id, retained);
    await this.#saveDurableState(peer.offer.id);
  }

  async #completeReceiveFile(peer, fileIndex) {
    const manifestFile = peer.offer.manifest?.files?.[fileIndex];
    if (peer.sender || !manifestFile || fileIndex !== peer.currentFileIndex || peer.expectedChunk
      || peer.currentFileOffset !== Number(manifestFile.size) || !peer.currentSink || !peer.currentDigest) {
      throw new Error('The file completion state was invalid.');
    }
    const actualSha = peer.currentDigest.hex();
    const expectedSha = String(peer.metadata.fileSha256?.[fileIndex] || '');
    if (actualSha !== expectedSha) {
      await peer.currentSink.cancel().catch(() => {});
      const failed = {fileIndex, name: manifestFile.safeName, size: Number(manifestFile.size), status: 'failed', reason: 'Integrity verification failed.'};
      peer.results.push(failed);
      peer.channel.send(JSON.stringify({kind: 'file-result', fileIndex, status: 'failed', reason: failed.reason}));
      this.#status(peer.offer, 'file-failed', `${manifestFile.safeName}: ${failed.reason}`);
    } else {
      const finalized = await peer.currentSink.finalize(Number(manifestFile.size));
      let verifiedContent = false;
      try {
        const received = new File([finalized.blob instanceof Blob ? finalized.blob : peer.currentSamplePrefix], String(manifestFile.safeName), {type: String(manifestFile.declaredMime || 'application/octet-stream')});
        const archiveEvidence = finalized.mode === 'direct-batch' ? this.#tailEvidence(Number(manifestFile.size), peer.currentSampleTail) : null;
        const detection = await this.#detectFile(received);
        const inspection = await this.#inspectFile(received, detection, peer.offer.kind, Number(peer.offer.fileCount) > 1, archiveEvidence);
        const detectedTypeMatches = peer.offer.kind === 'avatar'
          ? String(manifestFile.detectedType || '') === 'avatar' && detection.category === 'image'
          : detection.category === String(manifestFile.detectedType || '');
        verifiedContent = detectedTypeMatches
          && detection.detectedMime === String(manifestFile.detectedMime || '')
          && inspection.riskClass === String(manifestFile.riskClass || '')
          && Boolean(inspection.archiveEncrypted) === Boolean(manifestFile.archive?.encrypted)
          && Boolean(inspection.archiveActiveContent) === Boolean(manifestFile.archive?.activeContent)
          && Boolean(inspection.archiveSuspiciousPaths) === Boolean(manifestFile.archive?.suspiciousPaths)
          && Boolean(inspection.archiveExtremeRatio) === Boolean(manifestFile.archive?.extremeRatio);
      } catch {
        verifiedContent = false;
      }
      if (!verifiedContent) {
        await finalized.cleanup?.().catch?.(() => {});
        const failed = {fileIndex, name: manifestFile.safeName, size: Number(manifestFile.size), status: 'failed', reason: 'Detected content did not match the accepted manifest.'};
        peer.results.push(failed);
        peer.channel.send(JSON.stringify({kind: 'file-result', fileIndex, status: 'failed', reason: failed.reason}));
        this.#status(peer.offer, 'file-failed', `${manifestFile.safeName}: ${failed.reason}`);
      } else {
        await finalized.commit?.();
        const completed = {
          fileIndex, name: String(manifestFile.safeName), size: Number(manifestFile.size),
          status: 'completed', crc32: crc32Finish(peer.currentCrc), blob: finalized.blob instanceof Blob ? finalized.blob : null,
          storageMode: finalized.mode, cleanup: finalized.cleanup,
        };
        peer.results.push(completed);
        peer.channel.send(JSON.stringify({kind: 'file-result', fileIndex, status: 'completed'}));
      }
    }
    peer.currentSink = null;
    peer.currentDigest = null;
    peer.currentCrc = crc32Start();
    peer.currentSamplePrefix = new Uint8Array(0);
    peer.currentSampleTail = new Uint8Array(0);
    const retained = this.#resumeStates.get(peer.offer.id);
    if (retained) {
      retained.results = peer.results;
      retained.currentFileIndex = fileIndex + 1;
      retained.currentFileOffset = 0;
      retained.currentSink = null;
      retained.currentDigest = null;
      retained.currentSamplePrefix = new Uint8Array(0);
      retained.currentSampleTail = new Uint8Array(0);
      await this.#saveDurableState(peer.offer.id);
    }
  }

  async #skipReceiveFile(peer, fileIndex, status) {
    const manifestFile = peer.offer.manifest?.files?.[fileIndex];
    if (!manifestFile || fileIndex < 0) throw new Error('The skipped file identity was invalid.');
    if (peer.results.some(result => Number(result.fileIndex) === fileIndex)) return;
    if (peer.currentFileIndex === fileIndex && peer.currentSink) await peer.currentSink.cancel().catch(() => {});
    else if (String(this.#resumeStates.get(peer.offer.id)?.storageMode || '') === 'direct-batch') this.#localStorage.skipDirectBatchEntry(peer.offer.id, fileIndex);
    peer.cancelledFiles.add(fileIndex);
    peer.receivedBytes = this.#filePrefixBytes(peer.offer.manifest.files, fileIndex) + Number(manifestFile.size);
    peer.results.push({fileIndex, name: manifestFile.safeName, size: Number(manifestFile.size), status: status === 'failed' ? 'failed' : 'cancelled'});
    peer.currentSink = null;
    peer.currentDigest = null;
    peer.currentSamplePrefix = new Uint8Array(0);
    peer.currentSampleTail = new Uint8Array(0);
    peer.currentFileIndex = fileIndex + 1;
    peer.currentFileOffset = 0;
    const retained = this.#resumeStates.get(peer.offer.id);
    if (retained) {
      retained.receivedBytes = peer.receivedBytes;
      retained.currentFileIndex = peer.currentFileIndex;
      retained.currentFileOffset = 0;
      retained.currentSink = null;
      retained.currentDigest = null;
      retained.currentSamplePrefix = new Uint8Array(0);
      retained.currentSampleTail = new Uint8Array(0);
      retained.cancelledFiles = peer.cancelledFiles;
      retained.results = peer.results;
      await this.#saveDurableState(peer.offer.id);
    }
  }

  async #completeReceiveBatch(peer) {
    if (peer.sender || peer.expectedChunk || peer.currentSink) throw new Error('The batch completion state was invalid.');
    const retained = this.#resumeStates.get(peer.offer.id) || {};
    const directBatch = String(retained.storageMode || '') === 'direct-batch';
    for (const result of peer.results) {
      if (directBatch) continue;
      if (result.status !== 'completed' || result.blob instanceof Blob) continue;
      const sink = await this.#localStorage.createSink(peer.offer.id, Number(result.fileIndex), Number(result.size), {
        resume: true, mode: retained.storageMode || 'auto', suggestedName: String(result.name || 'download.bin').split('/').pop(),
      });
      if (Number(sink.written || 0) !== Number(result.size)) throw new Error('A retained completed file is unavailable.');
      const finalized = await sink.finalize(Number(result.size));
      result.blob = finalized.blob;
      result.cleanup = finalized.cleanup;
    }
    const successful = peer.results.filter(result => result.status === 'completed' && (directBatch || result.blob instanceof Blob));
    if (!successful.length) throw new Error('No file in this transfer passed integrity verification.');
    let output;
    let name;
    let outputCleanup = null;
    if (directBatch) {
      const finalized = await this.#localStorage.finalizeDirectBatch(peer.offer.id);
      output = finalized.blob;
      outputCleanup = finalized.cleanup;
      name = `CoreChat-transfer-${peer.offer.id.slice(-8)}.zip`;
    } else if (Number(peer.offer.fileCount) === 1) {
      output = successful[0].blob;
      name = successful[0].name;
    } else {
      const entries = successful.map(result => ({name: result.name, size: result.size, crc32: result.crc32, blob: result.blob}));
      const zipBytes = storedZipSize(entries);
      const zipSink = await this.#localStorage.createSink(`${peer.offer.id}-zip`, 0, zipBytes, {
        mode: retained.storageMode === 'direct' ? 'direct' : 'auto', suggestedName: `CoreChat-transfer-${peer.offer.id.slice(-8)}.zip`,
      });
      const finalized = await buildStoredZip(entries, zipSink);
      output = finalized.blob;
      outputCleanup = finalized.cleanup;
      name = `CoreChat-transfer-${peer.offer.id.slice(-8)}.zip`;
    }
    await this.#update(peer.offer.id, 'complete');
    peer.channel.send(JSON.stringify({kind: 'verified', completed: successful.length, total: Number(peer.offer.fileCount)}));
    this.#status(peer.offer, 'completed', `${successful.length} of ${peer.offer.fileCount} files verified locally.`);
    const savedDirect = directBatch || successful.some(result => String(result.storageMode || '').startsWith('direct'));
    let released = false;
    const release = async () => {
      if (released) return;
      released = true;
      for (const result of successful) await result.cleanup?.().catch?.(() => {});
      await outputCleanup?.().catch?.(() => {});
      await this.#localStorage.cleanupAttempt(peer.offer.id).catch(() => {});
    };
    if (this.#context?.onReceived) {
      await this.#context.onReceived({offer: peer.offer, blob: output, name, kind: peer.offer.kind, results: peer.results, savedDirect, release});
    } else {
      await release();
    }
    this.#resumeStates.delete(peer.offer.id);
  }

  async #receiveControl(peer, message) {
    const action = String(message.action || '');
    const state = this.#resumeStates.get(peer.offer.id);
    if (action === 'pause' || action === 'resume') {
      peer.paused = action === 'pause';
      if (peer.progress) peer.progress.lastAt = performance.now();
      if (state) state.paused = peer.paused;
      if (!peer.paused) for (const resolve of peer.pauseWaiters.splice(0)) resolve();
      await this.#saveDurableState(peer.offer.id).catch(() => {});
      this.#status(peer.offer, peer.paused ? 'paused' : 'transferring', peer.paused ? 'Paused after the last confirmed chunk.' : 'Transfer resumed. Calculating...');
      return;
    }
    if (action === 'cancel-current') {
      const fileIndex = Number(message.fileIndex);
      if (peer.sender) {
        peer.cancelledFiles.add(fileIndex);
        state?.cancelledFiles?.add(fileIndex);
        await this.#saveDurableState(peer.offer.id).catch(() => {});
      } else {
        await this.#skipReceiveFile(peer, fileIndex, 'cancelled');
        peer.channel.send(JSON.stringify({kind: 'file-result', fileIndex, status: 'cancelled'}));
      }
      return;
    }
    if (action === 'cancel-batch') {
      if (peer.currentSink) await peer.currentSink.cancel().catch(() => {});
      await this.#localStorage.cleanupAttempt(peer.offer.id).catch(() => {});
      this.#closePeer(peer, 'cancelled');
      this.#clearTransferState(peer.offer.id);
      this.#status(peer.offer, 'cancelled', 'The transfer was cancelled.');
      return;
    }
    throw new Error('The transfer control was invalid.');
  }

  async #cancelCurrentLocal(offerId, fileIndex) {
    const state = this.#resumeStates.get(offerId);
    const peer = this.#peers.get(offerId);
    state?.cancelledFiles?.add(fileIndex);
    peer?.cancelledFiles?.add(fileIndex);
    if (peer && !peer.sender && peer.currentFileIndex === fileIndex && peer.currentSink) {
      await peer.currentSink.cancel().catch(() => {});
      peer.currentSink = null;
    }
    await this.#saveDurableState(offerId).catch(() => {});
  }

  async #waitForAcknowledgement(peer, expectedOffset) {
    if (peer.lastAcknowledgedOffset >= expectedOffset) return;
    await new Promise((resolve, reject) => {
      const waiter = {expectedOffset, resolve, reject, timeout: null};
      waiter.timeout = setTimeout(() => {
        peer.acknowledgementWaiters = peer.acknowledgementWaiters.filter(candidate => candidate !== waiter);
        reject(new Error('Direct connection could not be established.'));
      }, 20000);
      peer.acknowledgementWaiters.push(waiter);
    });
  }

  #resolveAcknowledgements(peer) {
    const waiting = peer.acknowledgementWaiters.splice(0);
    for (const waiter of waiting) {
      if (peer.lastAcknowledgedOffset >= waiter.expectedOffset) {
        clearTimeout(waiter.timeout);
        waiter.resolve();
      } else {
        peer.acknowledgementWaiters.push(waiter);
      }
    }
  }

  async #waitForResumeOffset(peer) {
    return await new Promise((resolve, reject) => {
      const waiter = {resolve, reject, timeout: null};
      waiter.timeout = setTimeout(() => {
        peer.resumeOffsetWaiters = peer.resumeOffsetWaiters.filter(candidate => candidate !== waiter);
        reject(new Error('Direct connection could not be established.'));
      }, 20000);
      peer.resumeOffsetWaiters.push(waiter);
    });
  }

  async #sha256(blob) {
    const digest = new IncrementalSha256();
    const reader = blob.stream().getReader();
    while (true) {
      const {done, value} = await reader.read();
      if (done) break;
      digest.update(value);
    }
    return digest.hex();
  }

  #decodeBase64Url(value) {
    const normalized = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - normalized.length % 4) % 4);
    const binary = atob(padded);
    return Uint8Array.from(binary, character => character.charCodeAt(0));
  }

  #encodeBase64Url(value) {
    let binary = '';
    for (const byte of new Uint8Array(value)) binary += String.fromCharCode(byte);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  }

  async #signalCryptoKey(offer) {
    const raw = this.#decodeBase64Url(offer?.signalKey);
    if (raw.byteLength !== 32) throw new Error('Protected transfer signaling is unavailable.');
    return await crypto.subtle.importKey('raw', raw, {name: 'AES-GCM'}, false, ['encrypt','decrypt']);
  }

  async #sealSignal(offer, type, payload) {
    const key = await this.#signalCryptoKey(offer);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const additionalData = new TextEncoder().encode(`${offer.id}\0${type}`);
    const plaintext = new TextEncoder().encode(JSON.stringify({
      ...payload,
      resumeOffset: Number(payload?.resumeOffset ?? payload?.resume_offset ?? 0),
    }));
    if (plaintext.byteLength <= 1 || plaintext.byteLength > 220000) throw new Error('The transfer signal is too large.');
    const ciphertext = await crypto.subtle.encrypt({name: 'AES-GCM', iv, additionalData}, key, plaintext);
    return {v: 1, iv: this.#encodeBase64Url(iv), ciphertext: this.#encodeBase64Url(ciphertext)};
  }

  async #openSignal(offer, type, sealed) {
    if (!sealed || Number(sealed.v) !== 1) throw new Error('The protected transfer signal is invalid.');
    const key = await this.#signalCryptoKey(offer);
    const iv = this.#decodeBase64Url(sealed.iv);
    const ciphertext = this.#decodeBase64Url(sealed.ciphertext);
    if (iv.byteLength !== 12 || ciphertext.byteLength < 17 || ciphertext.byteLength > 225000) {
      throw new Error('The protected transfer signal is invalid.');
    }
    const additionalData = new TextEncoder().encode(`${offer.id}\0${type}`);
    let plaintext;
    try {
      plaintext = await crypto.subtle.decrypt({name: 'AES-GCM', iv, additionalData}, key, ciphertext);
    } catch {
      throw new Error('The protected transfer signal could not be authenticated.');
    }
    const data = JSON.parse(new TextDecoder('utf-8', {fatal: true}).decode(plaintext));
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('The protected transfer signal is invalid.');
    return data;
  }

  async #signal(peer, type, payload) {
    const protectedPayload = await this.#sealSignal(peer.offer, type, {
      ...payload,
      resumeOffset: Number(peer.resumeOffset || 0),
      previewOnly: Boolean(peer.previewOnly),
    });
    return await this.#context.apiPost('/api/p2p_transfer.php', {
      action: 'signal',
      offer_id: peer.offer.id,
      signal_type: type,
      transfer_authorization: peer.authorization,
      sealed: protectedPayload,
    });
  }

  async #signalDescription(peer, type, payload) {
    await this.#signal(peer, type, payload);
    peer.descriptionSignaled = true;
    const pending = peer.pendingLocalCandidates.splice(0);
    for (const candidate of pending) await this.#signal(peer, 'ice', {candidate});
  }

  async #update(offerId, action, extras = {}) {
    const config = this.#context?.getConfig?.() || {};
    const data = await this.#context.apiPost('/api/p2p_transfer.php', {
      action,
      offer_id: offerId,
      session_id: config.sessionId,
      participant_id: config.myParticipantId,
      join_token: config.myJoinToken,
      ...extras,
    });
    this.#offers.set(offerId, data.offer);
    return data.offer;
  }

  async #fail(peer, error) {
    if (!peer || peer.terminalFailed) return;
    peer.terminalFailed = true;
    const message = error?.message || 'Direct connection could not be established.';
    if (peer.previewOnly) {
      this.#status(peer.offer, 'preview-unavailable', message);
      this.#closePeer(peer, 'preview-failed', true);
      return;
    }
    await this.#update(peer.offer.id, 'fail', {reason: message === 'Direct connection could not be established.' ? 'ice-failed' : message}).catch(() => {});
    this.#status(peer.offer, 'failed', message);
    this.#closePeer(peer, 'failed');
    await this.#localStorage.cleanupAttempt(peer.offer.id).catch(() => {});
    this.#clearTransferState(peer.offer.id);
  }

  async #recover(peer, error) {
    if (!peer || peer.closed || peer.recovering) return;
    peer.recovering = true;
    const state = this.#resumeStates.get(peer.offer.id);
    const expiry = serverTime(peer.offer.expiresAt);
    const directLive = String(state?.storageMode || '').startsWith('direct') && Boolean(state?.directLive);
    const durableValid = state
      && Number(state.attempts || 0) < MAX_RESUME_ATTEMPTS
      && Number.isFinite(expiry)
      && expiry > Date.now()
      && (peer.sender
        ? this.#sources.get(peer.offer.id)?.files?.every(entry => entry.file instanceof File)
        : (directLive || !String(state.storageMode || '').startsWith('direct')) && /^[A-F0-9]{64}$/.test(String(state.contentSha256 || ''))
          && Number(state.receivedBytes || 0) >= 0
          && Number(state.receivedBytes || 0) < Number(peer.offer.size));
    if (!durableValid) {
      this.#closePeer(peer, 'resume-wait', true);
      this.#status(peer.offer, 'resumable', error?.message || 'The transfer is interrupted. Use Resume Transfer when the exact local state is available.');
      return;
    }
    if (peer.sender) state.lastAcknowledgedOffset = peer.lastAcknowledgedOffset;
    this.#closePeer(peer, 'retry', true);
    if (peer.sender) {
      await this.#saveDurableState(peer.offer.id).catch(() => {});
      this.#status(peer.offer, 'resumable', 'Connection interrupted. Waiting for the receiver to confirm its retained offset.');
      return;
    }
    try {
      await this.#requestResume(peer.offer, state);
    } catch (resumeError) {
      await this.#saveDurableState(peer.offer.id).catch(() => {});
      this.#status(peer.offer, 'resumable', resumeError?.message || error?.message || 'The transfer is interrupted. Use Resume Transfer to try again.');
    }
  }

  async #requestResume(offer, state) {
    const offset = Number(state.receivedBytes || 0);
    if (!state.contentSha256
      || !Number.isSafeInteger(offset)
      || offset < 0
      || offset >= Number(offer.size)) {
      throw new Error('The retained partial transfer can no longer be resumed. Create a new offer.');
    }
    state.attempts = Number(state.attempts || 0) + 1;
    if (state.attempts > MAX_RESUME_ATTEMPTS) {
      state.attempts = 0;
      throw new Error('Automatic reconnect paused. Use Resume Transfer to try again without extending the fixed deadline.');
    }
    const authorized = await this.#update(offer.id, 'resume-authorize', {
      resume_offset: offset,
      content_sha256: state.contentSha256,
    });
    if (!authorized.resumeAuthorization || Number(authorized.resumeOffset) !== offset || !authorized.authorization) {
      throw new Error('The server did not authorize the retained partial transfer.');
    }
    await this.#context.apiPost('/api/p2p_transfer.php', {
      action: 'signal',
      offer_id: offer.id,
      signal_type: 'resume-request',
      transfer_authorization: authorized.authorization,
      resume_authorization: authorized.resumeAuthorization,
      resume_offset: offset,
    });
    this.#status(offer, 'resuming', `Connection interrupted. Reconnecting from ${offset} of ${offer.size} bytes.`);
  }

  #closePeer(peer, reason, preserve = false) {
    if (!peer || peer.closed) return;
    peer.closed = true;
    for (const waiter of peer.acknowledgementWaiters || []) {
      clearTimeout(waiter.timeout);
      waiter.reject(new Error('Direct connection could not be established.'));
    }
    for (const waiter of peer.resumeOffsetWaiters || []) {
      clearTimeout(waiter.timeout);
      waiter.reject(new Error('Direct connection could not be established.'));
    }
    peer.acknowledgementWaiters = [];
    peer.resumeOffsetWaiters = [];
    for (const resolve of peer.fileResultWaiters?.values?.() || []) resolve({status: 'failed', reason: 'Direct connection could not be established.'});
    peer.fileResultWaiters?.clear?.();
    try { peer.channel?.close(); } catch {}
    try { peer.pc?.close(); } catch {}
    if (this.#peers.get(peer.offer.id) === peer) this.#peers.delete(peer.offer.id);
    if (!preserve && TERMINAL.has(reason)) this.#clearTransferState(peer.offer.id);
  }

  #clearTransferState(offerId) {
    this.#sources.delete(offerId);
    this.#resumeStates.delete(offerId);
    this.#announced.delete(offerId);
  }

  #fileIdentity(file) {
    return `${file.name}\u0000${file.size}\u0000${file.type}\u0000${file.lastModified}`;
  }

  #status(offer, state, detail) {
    this.#context?.onStatus?.({offer, state, detail});
  }

  async #detectFile(file) {
    const declaredMime = String(file.type || 'application/octet-stream').toLowerCase();
    const extension = String(file.name || '').split('.').pop()?.toLowerCase() || '';
    const bytes = new Uint8Array(await file.slice(0, Math.min(file.size, 4096)).arrayBuffer());
    const starts = (...values) => values.every((value, index) => bytes[index] === value);
    const ascii = new TextDecoder('utf-8', {fatal: false}).decode(bytes).trimStart().slice(0, 256).toLowerCase();
    let detectedMime = 'application/octet-stream';
    let category = 'other';
    let activeContent = false;
    if (starts(0x89,0x50,0x4e,0x47,0x0d,0x0a,0x1a,0x0a)) { detectedMime = 'image/png'; category = 'image'; }
    else if (starts(0xff,0xd8,0xff)) { detectedMime = 'image/jpeg'; category = 'image'; }
    else if (starts(0x47,0x49,0x46,0x38)) { detectedMime = 'image/gif'; category = 'image'; }
    else if (starts(0x52,0x49,0x46,0x46) && String.fromCharCode(...bytes.slice(8, 12)) === 'WEBP') { detectedMime = 'image/webp'; category = 'image'; }
    else if (starts(0x25,0x50,0x44,0x46,0x2d)) { detectedMime = 'application/pdf'; category = 'document'; }
    else if (starts(0x50,0x4b,0x03,0x04) || starts(0x50,0x4b,0x05,0x06) || starts(0x50,0x4b,0x07,0x08)) {
      const office = {
        docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        odt: 'application/vnd.oasis.opendocument.text',
      };
      detectedMime = office[extension] || 'application/zip';
      category = office[extension] ? 'document' : 'archive';
    }
    else if (starts(0xd0,0xcf,0x11,0xe0,0xa1,0xb1,0x1a,0xe1)) { detectedMime = 'application/x-ole-storage'; category = 'document'; }
    else if (starts(0x4f,0x67,0x67,0x53)) { detectedMime = 'audio/ogg'; category = 'audio'; }
    else if (starts(0x52,0x49,0x46,0x46) && String.fromCharCode(...bytes.slice(8, 12)) === 'WAVE') { detectedMime = 'audio/wav'; category = 'audio'; }
    else if (starts(0x49,0x44,0x33) || (bytes[0] === 0xff && (bytes[1] & 0xe0) === 0xe0)) { detectedMime = 'audio/mpeg'; category = 'audio'; }
    else if (bytes.length >= 12 && String.fromCharCode(...bytes.slice(4, 8)) === 'ftyp') { detectedMime = 'video/mp4'; category = 'video'; }
    else if (starts(0x1a,0x45,0xdf,0xa3)) { detectedMime = 'video/webm'; category = 'video'; }
    else if (starts(0x4d,0x5a) || starts(0x7f,0x45,0x4c,0x46)) { detectedMime = 'application/x-executable'; activeContent = true; }
    else if (/^(?:<!doctype\s+html|<html\b|<script\b|<svg\b|<\?php\b|#!\s*\/)/i.test(ascii)) {
      detectedMime = ascii.startsWith('<svg') ? 'image/svg+xml' : (ascii.startsWith('#!') ? 'text/x-shellscript' : 'text/html');
      activeContent = true;
    }
    else if (declaredMime.startsWith('image/')) { detectedMime = declaredMime; category = 'image'; }
    else if (declaredMime.startsWith('audio/')) { detectedMime = declaredMime; category = 'audio'; }
    else if (declaredMime.startsWith('video/')) { detectedMime = declaredMime; category = 'video'; }
    else if (['zip','7z','rar','tar','gz'].includes(extension)) { detectedMime = declaredMime; category = 'archive'; }
    else if (['pdf','doc','docx','rtf','txt','odt','xls','xlsx','ppt','pptx'].includes(extension)) { detectedMime = declaredMime; category = 'document'; }
    const generic = new Set(['', 'application/octet-stream', 'binary/octet-stream']);
    const signatureMismatch = !generic.has(declaredMime) && detectedMime !== 'application/octet-stream' && declaredMime !== detectedMime;
    return {category, detectedMime, declaredMime, activeContent, signatureMismatch};
  }

  async #inspectFile(file, detection, kind, insideBatch = false, archiveEvidence = null) {
    const detectedType = detection.category;
    const name = String(file.name || 'file');
    const extension = name.split('.').pop()?.toLowerCase() || '';
    const mime = String(file.type || '').toLowerCase();
    const blockedExtensions = new Set(['exe','com','bat','cmd','ps1','vbs','js','jar','msi','scr','lnk','url','desktop','app','apk','html','htm','xhtml','svg']);
    const blockedMimes = new Set(['text/html','application/xhtml+xml','image/svg+xml','application/x-msdownload','application/x-msdos-program','application/javascript','text/javascript']);
    if (kind !== 'gesture' && !insideBatch && (blockedExtensions.has(extension) || blockedMimes.has(mime) || detection.activeContent)) {
      throw new Error('This standalone file type is blocked by policy.');
    }
    const deceptive = /[\u202A-\u202E\u2066-\u2069]/u.test(name) || name.split('.').filter(Boolean).length > 2;
    const activeInsideBatch = insideBatch && (blockedExtensions.has(extension) || blockedMimes.has(mime) || detection.activeContent);
    let riskClass = (deceptive || activeInsideBatch || detection.signatureMismatch) ? 'Potentially dangerous' : (/^(image|audio|video)$/.test(detectedType) ? 'Low risk' : (detectedType === 'document' ? 'Use caution' : 'Cannot be inspected'));
    let riskDetail = (deceptive || activeInsideBatch)
      ? (activeInsideBatch ? 'Active content is transferred only inside the generated archive. Not scanned for malware.' : 'The filename may be deceptive. Review it carefully. Not scanned for malware.')
      : (detection.signatureMismatch ? 'The detected content does not match the declared file type. Not scanned for malware.' : (riskClass === 'Low risk' ? 'A bounded local preview may be available. Not scanned for malware.' : 'This file cannot be proven safe. Not scanned for malware.'));
    let archiveEncrypted = false;
    let archiveActiveContent = false;
    let archiveSuspiciousPaths = false;
    let archiveExtremeRatio = false;
    if (detectedType === 'archive') {
      riskClass = 'Use caution';
      riskDetail = 'Archive contents are never extracted or executed automatically. Not scanned for malware.';
      if (extension === 'zip') {
        const zip = await this.#inspectZipCentralDirectory(archiveEvidence || file);
        archiveEncrypted = zip.encrypted;
        archiveActiveContent = zip.activeContent;
        archiveSuspiciousPaths = zip.suspiciousPaths;
        archiveExtremeRatio = zip.extremeRatio;
        if (!zip.inspectable) {
          riskClass = 'Cannot be inspected';
          riskDetail = zip.encrypted
            ? 'Encrypted archive — contents cannot be inspected. Not scanned for malware.'
            : 'Archive metadata could not be inspected. Not scanned for malware.';
        } else if (zip.activeContent || zip.suspiciousPaths || zip.extremeRatio) {
          riskClass = 'Potentially dangerous';
          riskDetail = 'Archive metadata contains active content, suspicious paths, or an extreme compression ratio. Not scanned for malware.';
        }
      }
    }
    return {riskClass, riskDetail, archiveEncrypted, archiveActiveContent, archiveSuspiciousPaths, archiveExtremeRatio};
  }

  async #inspectZipCentralDirectory(file) {
    const tailStart = Math.max(0, file.size - 65557);
    const tail = new Uint8Array(await file.slice(tailStart).arrayBuffer());
    const tailView = new DataView(tail.buffer, tail.byteOffset, tail.byteLength);
    let eocd = -1;
    for (let offset = tail.length - 22; offset >= 0; offset--) {
      if (tailView.getUint32(offset, true) !== 0x06054b50) continue;
      const commentLength = tailView.getUint16(offset + 20, true);
      if (offset + 22 + commentLength === tail.length) { eocd = offset; break; }
    }
    if (eocd < 0) return {inspectable: false, encrypted: false, activeContent: false, suspiciousPaths: false, extremeRatio: false};
    const disk = tailView.getUint16(eocd + 4, true);
    const centralDisk = tailView.getUint16(eocd + 6, true);
    const diskEntries = tailView.getUint16(eocd + 8, true);
    const entries = tailView.getUint16(eocd + 10, true);
    const centralSize = tailView.getUint32(eocd + 12, true);
    const centralOffset = tailView.getUint32(eocd + 16, true);
    const eocdAbsolute = tailStart + eocd;
    if (disk !== 0 || centralDisk !== 0 || diskEntries !== entries || entries === 0xffff
      || entries > 10000 || centralSize > 16 * 1024 * 1024
      || centralOffset + centralSize !== eocdAbsolute || centralOffset + centralSize > file.size) {
      return {inspectable: false, encrypted: false, activeContent: false, suspiciousPaths: false, extremeRatio: true};
    }
    const bytes = new Uint8Array(await file.slice(centralOffset, centralOffset + centralSize).arrayBuffer());
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const decoder = new TextDecoder('utf-8', {fatal: false});
    const activeExtensions = new Set(['exe','com','bat','cmd','ps1','vbs','js','jar','msi','scr','lnk','html','htm','xhtml','svg']);
    let encrypted = false;
    let activeContent = false;
    let suspiciousPaths = false;
    let compressed = 0;
    let uncompressed = 0;
    let offset = 0;
    const seenPaths = new Set();
    for (let index = 0; index < entries; index++) {
      if (offset + 46 > bytes.length || view.getUint32(offset, true) !== 0x02014b50) return {inspectable: false, encrypted, activeContent, suspiciousPaths, extremeRatio: true};
      const flags = view.getUint16(offset + 8, true);
      const compressedSize = view.getUint32(offset + 20, true);
      const uncompressedSize = view.getUint32(offset + 24, true);
      const nameLength = view.getUint16(offset + 28, true);
      const extraLength = view.getUint16(offset + 30, true);
      const commentLength = view.getUint16(offset + 32, true);
      const next = offset + 46 + nameLength + extraLength + commentLength;
      if (next > bytes.length) return {inspectable: false, encrypted, activeContent, suspiciousPaths, extremeRatio: true};
      const path = decoder.decode(bytes.subarray(offset + 46, offset + 46 + nameLength)).replaceAll('\\', '/');
      const segments = path.split('/');
      const entryExtension = path.split('.').pop()?.toLowerCase() || '';
      encrypted ||= Boolean(flags & 1);
      activeContent ||= activeExtensions.has(entryExtension);
      const pathKey = path.normalize('NFKC').toLocaleLowerCase('en-US');
      suspiciousPaths ||= path.startsWith('/') || /^[A-Za-z]:\//.test(path)
        || !path || path.length > 512 || segments.some(segment => !segment || segment === '.' || segment === '..'
          || segment.length > 180 || /[:*?"<>|\u0000-\u001f\u007f]/u.test(segment)
          || /[. ]$/u.test(segment) || /^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/iu.test(segment)
          || /[\u202A-\u202E\u2066-\u2069]/u.test(segment))
        || seenPaths.has(pathKey);
      seenPaths.add(pathKey);
      compressed += compressedSize;
      uncompressed += uncompressedSize;
      if (!Number.isSafeInteger(compressed) || !Number.isSafeInteger(uncompressed)) return {inspectable: false, encrypted, activeContent, suspiciousPaths: true, extremeRatio: true};
      offset = next;
    }
    if (offset !== bytes.length) return {inspectable: false, encrypted, activeContent, suspiciousPaths: true, extremeRatio: true};
    return {inspectable: !encrypted, encrypted, activeContent, suspiciousPaths, extremeRatio: compressed === 0 ? uncompressed > 0 : uncompressed / compressed > 100};
  }

  #tailEvidence(size, tail) {
    const bytes = tail instanceof Uint8Array ? tail : new Uint8Array(0);
    const total = Number(size);
    const base = Math.max(0, total - bytes.byteLength);
    return {
      size: total,
      slice(start = 0, end = total) {
        const from = Math.max(base, Number(start));
        const to = Math.min(total, Number(end));
        if (to <= from) return new Blob([]);
        return new Blob([bytes.slice(from - base, to - base)]);
      },
    };
  }
}

export default P2PTransferService;
