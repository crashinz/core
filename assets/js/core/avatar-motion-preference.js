export const AVATAR_MOTION_CHANGED = 'corechat:avatar-motion-changed';
const choices = ['system', 'on', 'reduced'];
let preference = 'on';
let initialized = false;
const storageKey = () => `corechat:avatar-motion:${globalThis.document?.body?.dataset.appBase || ''}`;

export function getAvatarMotionPreference() {
  if (!initialized) {
    initialized = true;
    try {
      const saved = globalThis.localStorage?.getItem(storageKey());
      if (choices.includes(saved)) preference = saved;
    } catch { /* Keep the owner-requested animations-on default. */ }
  }
  return preference;
}

function initializeControls() {
  const controls = document.getElementById('avatar-motion-options');
  if (!controls) return;
  const status = document.getElementById('avatar-motion-status');
  const query = window.matchMedia?.('(prefers-reduced-motion: reduce)');
  function render(saved = true) {
    const selected = getAvatarMotionPreference();
    controls.querySelectorAll('[data-avatar-motion]').forEach(button => {
      const active = button.dataset.avatarMotion === selected;
      button.setAttribute('aria-pressed', String(active));
      button.classList.toggle('btn-primary', active);
    });
    const effect = selected === 'on' ? 'Avatar dances and lap animations are on.'
      : selected === 'reduced' ? 'Avatar dances and lap animations are reduced.'
      : `Following your device: ${query?.matches ? 'reduced motion' : 'animations on'}.`;
    status.textContent = `${effect} ${saved
      ? 'This preference applies only in this browser for this site.'
      : 'Browser storage is unavailable; this choice applies until you reload.'}`;
  }
  controls.addEventListener('click', event => {
    const button = event.target.closest('[data-avatar-motion]');
    if (!button || !controls.contains(button) || !choices.includes(button.dataset.avatarMotion)) return;
    initialized = true;
    preference = button.dataset.avatarMotion;
    let saved = true;
    try { window.localStorage.setItem(storageKey(), preference); } catch { saved = false; }
    render(saved);
    window.dispatchEvent(new Event(AVATAR_MOTION_CHANGED));
  });
  window.addEventListener('storage', event => {
    if (event.key !== storageKey() && event.key !== null) return;
    preference = choices.includes(event.newValue) ? event.newValue : 'on';
    initialized = true;
    render();
    window.dispatchEvent(new Event(AVATAR_MOTION_CHANGED));
  });
  query?.addEventListener?.('change', () => render());
  render();
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeControls, {once: true});
  } else initializeControls();
}
