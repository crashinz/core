/**
 * Server-authoritative private voice membership and admission client.
 * Media remains owned by VoiceMediaService; this service owns only membership,
 * invitations, requests, and the current private-call projection.
 */
export class PrivateVoiceMembershipService {
  #context = null;
  #snapshot = null;
  #timer = null;
  #refreshing = null;

  configure(context = {}) {
    this.#context = context;
    this.#snapshot = context.initialSnapshot || null;
    this.#publish();
    if (this.enabled()) this.startPolling(0);
  }

  destroy() {
    this.stopPolling();
    this.#context = null;
    this.#snapshot = null;
    this.#refreshing = null;
  }

  enabled() {
    const policy = this.#context?.getPolicy?.() || this.#context?.policy || {};
    return Boolean(policy?.privateVoice?.enabled);
  }

  snapshot() {
    return this.#snapshot;
  }

  activeVoiceContext() {
    const chatId = String(this.#snapshot?.activeChat?.id || '');
    return chatId
      ? Object.freeze({ type: 'private-voice', publicId: chatId })
      : Object.freeze({ type: 'room', publicId: null });
  }

  startPolling(delay = 0) {
    this.stopPolling();
    if (!this.enabled()) return;
    this.#timer = (this.#context?.setTimeout || setTimeout)(async () => {
      await this.refresh().catch(error => this.#context?.onError?.(error));
      this.startPolling(2000);
    }, delay);
  }

  stopPolling() {
    if (this.#timer === null) return;
    (this.#context?.clearTimeout || clearTimeout)(this.#timer);
    this.#timer = null;
  }

  refresh() {
    if (!this.enabled()) return Promise.resolve(null);
    if (this.#refreshing) return this.#refreshing;
    const config = this.#context?.getConfig?.() || {};
    const query = new URLSearchParams({
      session_id: config.sessionId || '',
      participant_id: config.myParticipantId || '',
      join_token: config.myJoinToken || '',
    });
    this.#refreshing = this.#context.getJson(`/api/private_voice.php?${query}`)
      .then(snapshot => {
        this.#snapshot = snapshot;
        this.#publish();
        return snapshot;
      })
      .finally(() => { this.#refreshing = null; });
    return this.#refreshing;
  }

  async action(action, details = {}) {
    if (!this.enabled()) throw new Error('Private Voice Chats are disabled for this installation.');
    const config = this.#context?.getConfig?.() || {};
    const response = await this.#context.apiPost('/api/private_voice.php', {
      action,
      session_id: config.sessionId,
      participant_id: config.myParticipantId,
      join_token: config.myJoinToken,
      request_id: details.request_id || this.#requestId(action),
      ...details,
    });
    this.#snapshot = response.snapshot || this.#snapshot;
    this.#publish();
    return response;
  }

  #requestId(action) {
    return `pv-${action}-${Date.now().toString(36)}-${crypto.getRandomValues(new Uint32Array(1))[0].toString(36)}`;
  }

  #publish() {
    this.#context?.onSnapshot?.(this.#snapshot, this.activeVoiceContext());
  }
}

export default PrivateVoiceMembershipService;
