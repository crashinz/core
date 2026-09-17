import { observeAnimationServerDate, serverEpochNow, parseServerTimestamp } from '../../core/animation-server-clock.js?v=20260913-r2';

function stableRequestId() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return `live-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

function utcTimestamp(value) {
  return parseServerTimestamp(value);
}

export class LiveWebsiteRoomRuntime {
  constructor() {
    this.destroyed = false;
    this.projection = null;
    this.sharedTargetUrl = '';
    this.pendingOfferId = 0;
    this.appUrl = path => path;
    this.csrfToken = '';
    this.elements = {};
  }

  start({ projection, appUrl, csrfToken, registerPollingJob, participant, onMusicPlaylist }) {
    this.appUrl = appUrl;
    this.csrfToken = csrfToken;
    this.participant = participant || {};
    this.onMusicPlaylist = onMusicPlaylist;
    this.musicTarget = '';
    this.musicRetryTimer = 0;
    this.musicAttempts = 0;
    this.elements = {
      layer: document.getElementById('live-website-room-layer'),
      frame: document.getElementById('live-website-room-frame'),
      domain: document.getElementById('live-website-room-domain'),
      stage: document.getElementById('room-stage'),
      roomActionButton: document.getElementById('room-action-btn'),
      ownerControls: document.getElementById('live-website-room-owner-controls'),
      url: document.getElementById('live-website-room-url'),
      makeOfficial: document.getElementById('live-website-room-make-official'),
      status: document.getElementById('live-website-room-status'),
      choice: document.getElementById('live-website-navigation-choice'),
      choiceCopy: document.getElementById('live-website-navigation-copy'),
      choiceStatus: document.getElementById('live-website-navigation-status'),
      follow: document.getElementById('live-website-navigation-follow'),
      stay: document.getElementById('live-website-navigation-stay'),
    };
    if (!this.elements.layer || !this.elements.frame) return;
    this.sharedTargetUrl = this.elements.frame.getAttribute('src') || '';
    this.elements.ownerControls?.addEventListener('submit', event => {
      event.preventDefault();
      this.navigateTogether();
    });
    this.elements.makeOfficial?.addEventListener('click', () => this.makeOfficial());
    this.elements.follow?.addEventListener('click', () => this.chooseNavigation('follow'));
    this.elements.stay?.addEventListener('click', () => this.chooseNavigation('stay'));
    this.render(projection);
    registerPollingJob({ id: 'live-website-room-refresh', run: () => this.refresh(), interval: 2000 });
  }

  destroy() {
    this.destroyed = true;
    clearTimeout(this.musicRetryTimer);
    this.elements.stage?.classList.remove('live-website-room-active');
    if (this.elements.frame) this.elements.frame.src = 'about:blank';
  }

  async request(payload = null) {
    const roomPublicId = this.projection?.roomPublicId || document.body.dataset.roomId || '';
    const options = { credentials: 'same-origin', cache: 'no-store' };
    let url = this.appUrl(`/api/live_website_rooms.php?room_public_id=${encodeURIComponent(roomPublicId)}`);
    if (payload) {
      url = this.appUrl('/api/live_website_rooms.php');
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken };
      options.body = JSON.stringify({ ...payload, room_public_id: roomPublicId });
    }
    const response = await fetch(url, options);
    observeAnimationServerDate(response.headers.get('Date'));
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.error) {
      const error = new Error(data.error || `Request failed (${response.status})`);
      error.code = data.code || '';
      throw error;
    }
    return data;
  }

  setStatus(message, kind = '') {
    if (!this.elements.status) return;
    this.elements.status.textContent = message || '';
    this.elements.status.dataset.kind = kind;
  }

  render(projection) {
    if (!projection || this.destroyed) return;
    this.projection = projection;
    this.elements.layer.hidden = false;
    const activatingStage = !this.elements.stage?.classList.contains('live-website-room-active');
    this.elements.stage?.classList.add('live-website-room-active');
    if (activatingStage && this.elements.stage) {
      this.elements.stage.scrollLeft = 0;
      this.elements.stage.scrollTop = 0;
    }
    if (this.elements.domain) this.elements.domain.textContent = projection.targetHost || 'Live website';
    this.elements.ownerControls.hidden = !projection.isOwner;
    if (this.elements.makeOfficial) this.elements.makeOfficial.hidden = !projection.canMakeOfficial;
    if (projection.canMakeOfficial && this.elements.roomActionButton) this.elements.roomActionButton.hidden = false;
    if (projection.isOwner && this.elements.url && !this.elements.url.matches(':focus')) this.elements.url.value = projection.targetUrl || '';
    if (projection.targetUrl && projection.targetUrl !== this.sharedTargetUrl) {
      this.sharedTargetUrl = projection.targetUrl;
      this.elements.frame.src = projection.targetUrl;
    }
    this.renderPendingNavigation(projection.pendingNavigation || null);
    if (projection.targetUrl && this.musicTarget !== projection.targetUrl) {
      this.musicTarget = projection.targetUrl;
      this.musicAttempts = 0;
      clearTimeout(this.musicRetryTimer);
      void this.loadMusic(this.musicTarget);
    }
  }

  async loadMusic(target) {
    if (this.destroyed || target !== this.musicTarget) return;
    this.musicAttempts += 1;
    try {
      const result = await this.request({ action: 'music', ...this.participant });
      if (this.destroyed || target !== this.musicTarget) return;
      if (result.pending && this.musicAttempts < 8) {
        this.musicRetryTimer = setTimeout(() => this.loadMusic(target), 10000);
        return;
      }
      this.onMusicPlaylist?.(Array.isArray(result.playlist) ? result.playlist : []);
    } catch (error) {
      if (!this.destroyed && target === this.musicTarget) this.setStatus('YouTube controls could not be loaded. ' + error.message, 'error');
    }
  }

  renderPendingNavigation(offer) {
    if (!this.elements.choice) return;
    if (!offer) {
      this.pendingOfferId = 0;
      this.elements.choice.hidden = true;
      return;
    }
    this.pendingOfferId = Number(offer.offerId || 0);
    const serverNow = serverEpochNow();
    if (serverNow === null) {
      this.elements.choiceCopy.textContent = `Follow into ${offer.destinationName || 'the new website room'}, or stay here. Awaiting server timing.`;
      this.elements.choice.hidden = false;
      return;
    }
    const seconds = Math.max(0, Math.ceil((utcTimestamp(offer.expiresAt) - serverNow) / 1000));
    const minutes = Math.max(1, Math.ceil(seconds / 60));
    this.elements.choiceCopy.textContent = `Follow into ${offer.destinationName || 'the new website room'}, or stay here. The first person who stays becomes this room's creator. About ${minutes} minute${minutes === 1 ? '' : 's'} remain.`;
    this.elements.choice.hidden = false;
  }

  async refresh() {
    if (this.destroyed || !this.projection?.roomPublicId) return;
    try {
      const data = await this.request();
      this.render(data.liveWebsiteRoom);
    } catch (error) {
      if (error.code === 'LIVE_WEBSITE_ROOM_NOT_FOUND') this.setStatus('This temporary room has closed.', 'error');
    }
  }

  async navigateTogether() {
    const url = this.elements.url?.value.trim() || '';
    if (!url) {
      this.setStatus('Enter an HTTPS website URL first.', 'error');
      return;
    }
    this.setStatus('Checking the destination...', 'busy');
    try {
      const data = await this.request({ action: 'navigate', url, expected_version: Number(this.projection.navigationVersion || 0), request_id: stableRequestId() });
      if (!data.navigation?.enterUrl) throw new Error('The destination room did not return an entry URL.');
      window.location.href = data.navigation.enterUrl;
    } catch (error) {
      this.setStatus(error.message || 'Shared navigation failed.', 'error');
      await this.refresh();
    }
  }

  async chooseNavigation(choice) {
    if (!this.pendingOfferId) return;
    this.elements.follow.disabled = true;
    this.elements.stay.disabled = true;
    if (this.elements.choiceStatus) this.elements.choiceStatus.textContent = choice === 'follow' ? 'Following...' : 'Staying here...';
    try {
      const data = await this.request({ action: 'choose_navigation', offer_id: this.pendingOfferId, choice });
      if (choice === 'follow' && data.navigation?.enterUrl) {
        window.location.href = data.navigation.enterUrl;
        return;
      }
      this.elements.choice.hidden = true;
      this.pendingOfferId = 0;
      await this.refresh();
      this.setStatus('You stayed in this room.', 'success');
    } catch (error) {
      if (this.elements.choiceStatus) this.elements.choiceStatus.textContent = error.message || 'Choice failed.';
    } finally {
      this.elements.follow.disabled = false;
      this.elements.stay.disabled = false;
    }
  }

  async makeOfficial() {
    if (!this.projection?.canMakeOfficial) return;
    if (!await window.CoreChatPopups.confirm('Keep this Live Website Room as a permanent room? The temporary room will remain separate until it expires.')) return;
    this.elements.makeOfficial.disabled = true;
    this.setStatus('Creating the permanent Live Website Room...', 'busy');
    try {
      const data = await this.request({ action: 'make_official', expected_version: Number(this.projection.navigationVersion || 0) });
      if (!data.successor?.enterUrl) throw new Error('The official room did not return an entry URL.');
      window.location.href = data.successor.enterUrl;
    } catch (error) {
      this.setStatus(error.message || 'Could not make the room official.', 'error');
      await this.refresh();
    } finally {
      this.elements.makeOfficial.disabled = false;
    }
  }
}
