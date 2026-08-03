const RESERVED_BINDINGS = new Set(['Space', 'Tab', 'Enter', 'Escape', 'Backspace', 'Delete', 'ContextMenu']);

function editableTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(target.closest('input, textarea, select, [contenteditable="true"], [role="dialog"], .message-composer, .settings-search'));
}

/** Coordinates optional transmission modes through the existing mute owner. */
export class VoiceTransmissionModeService {
  #context = null;
  #held = false;
  #mutedBeforeHold = true;
  #listeners = [];

  configure(context = {}) {
    this.destroy();
    this.#context = context;
    const documentRef = context.document || document;
    const windowRef = context.window || window;
    this.#listen(documentRef, 'keydown', event => this.#onKeyDown(event), true);
    this.#listen(documentRef, 'keyup', event => this.#onKeyUp(event), true);
    this.#listen(documentRef, 'visibilitychange', () => {
      if (documentRef.visibilityState !== 'visible') this.restoreSafeState('visibility-loss');
    });
    this.#listen(windowRef, 'blur', () => this.restoreSafeState('window-blur'));
    this.#listen(windowRef, 'pagehide', () => this.restoreSafeState('pagehide'));
    const hold = context.holdControl;
    if (hold) {
      for (const eventName of ['pointerdown', 'touchstart']) this.#listen(hold, eventName, event => this.#startHold(event));
      for (const eventName of ['pointerup', 'pointercancel', 'pointerleave', 'touchend', 'touchcancel']) this.#listen(hold, eventName, event => this.#endHold(event));
      this.#listen(hold, 'keydown', event => {
        if (['Enter', 'Space'].includes(event.code) && !event.repeat) this.#startHold(event);
      });
      this.#listen(hold, 'keyup', event => {
        if (['Enter', 'Space'].includes(event.code)) this.#endHold(event);
      });
    }
    this.render();
  }

  destroy() {
    this.restoreSafeState('destroy');
    for (const [target, name, listener, options] of this.#listeners) target.removeEventListener(name, listener, options);
    this.#listeners = [];
    this.#context = null;
  }

  policyEnabled() {
    return Boolean(this.#context?.getPolicy?.()?.transmissionModes?.enabled);
  }

  savedPreferences() {
    return this.#context?.getPreferences?.() || {};
  }

  effectiveMode() {
    if (!this.policyEnabled()) return 'voice-activation';
    const mode = String(this.savedPreferences().transmissionMode || 'voice-activation');
    return ['voice-activation', 'push-to-talk', 'push-to-mute'].includes(mode) ? mode : 'voice-activation';
  }

  binding() {
    const storage = this.#context?.storage;
    const code = storage?.getItem?.('corechat.voice.transmission-binding.v1') || '';
    return RESERVED_BINDINGS.has(code) ? '' : code;
  }

  initialMuted() {
    if (!this.policyEnabled()) return false;
    return Boolean(this.savedPreferences().alwaysMutedOnJoin) || this.effectiveMode() === 'push-to-talk';
  }

  restoreSafeState(reason = 'release') {
    if (!this.#held) return;
    this.#held = false;
    const mode = this.effectiveMode();
    const media = this.#context?.getMedia?.();
    if (mode === 'push-to-mute' && reason === 'release') media?.setMuted(this.#mutedBeforeHold);
    else media?.setMuted(true);
    this.render();
  }

  render() {
    const control = this.#context?.holdControl;
    if (!control) return;
    const mode = this.effectiveMode();
    const visible = this.policyEnabled() && ['push-to-talk', 'push-to-mute'].includes(mode);
    control.hidden = !visible;
    control.disabled = !visible || !this.#context?.getMedia?.()?.isJoined?.();
    control.classList.toggle('active', this.#held);
    control.setAttribute('aria-pressed', this.#held ? 'true' : 'false');
    control.textContent = mode === 'push-to-talk'
      ? (this.#held ? 'Talking — release to mute' : 'Hold to talk')
      : (this.#held ? 'Muted — release to restore' : 'Hold to mute');
    const status = this.#context?.statusControl;
    if (status) {
      const binding = this.binding();
      status.textContent = visible
        ? `${mode === 'push-to-talk' ? 'Push to talk' : 'Push to mute'}${binding ? ` · ${binding}` : ' · hold control only'}`
        : '';
      status.hidden = !visible;
    }
  }

  #startHold(event) {
    if (event?.repeat || this.#held || !this.policyEnabled()) return;
    const mode = this.effectiveMode();
    if (!['push-to-talk', 'push-to-mute'].includes(mode)) return;
    event?.preventDefault?.();
    const media = this.#context?.getMedia?.();
    if (!media?.isJoined?.()) return;
    this.#mutedBeforeHold = Boolean(media.isMuted?.());
    this.#held = true;
    media.setMuted(mode === 'push-to-mute');
    this.render();
  }

  #endHold(event) {
    if (!this.#held) return;
    event?.preventDefault?.();
    this.restoreSafeState('release');
  }

  #onKeyDown(event) {
    const binding = this.binding();
    if (!binding || event.code !== binding || editableTarget(event.target)) return;
    this.#startHold(event);
  }

  #onKeyUp(event) {
    if (event.code !== this.binding()) return;
    this.#endHold(event);
  }

  #listen(target, name, listener, options = false) {
    target?.addEventListener?.(name, listener, options);
    if (target) this.#listeners.push([target, name, listener, options]);
  }
}

export default VoiceTransmissionModeService;
