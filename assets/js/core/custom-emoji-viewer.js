// One presentation overlay per document, shared by all chat channels.
const viewers = new WeakMap();

export function installCustomEmojiViewer(doc = document) {
  const existing = viewers.get(doc);
  if (existing) { existing.users++; return () => release(doc, existing); }
  const state = { users: 1, dialog: null, restoreFocus: null };
  const close = () => {
    const dialog = state.dialog;
    if (!dialog) return;
    state.dialog = null;
    dialog.close();
    dialog.remove();
    state.restoreFocus?.();
    state.restoreFocus = null;
  };
  const open = trigger => {
    if (state.dialog) return;
    const dialog = doc.createElement('dialog');
    dialog.className = 'custom-emoji-viewer';
    dialog.tabIndex = -1;
    dialog.setAttribute('aria-label', `Full-size emoji ${trigger.alt || ''}`);
    const content = doc.createElement('div');
    content.className = 'custom-emoji-viewer-content';
    const image = doc.createElement('img');
    image.src = trigger.currentSrc || trigger.src;
    image.alt = trigger.alt || 'Custom emoji';
    image.className = 'custom-emoji-viewer-image';
    const hint = doc.createElement('p');
    hint.className = 'custom-emoji-viewer-hint';
    hint.textContent = 'Click anywhere or press Escape to close';
    const button = doc.createElement('button');
    button.type = 'button';
    button.className = 'btn';
    button.textContent = 'Close';
    content.append(image, hint, button);
    dialog.append(content);
    dialog.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      close();
    });
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    image.addEventListener('error', () => { hint.textContent = 'Image unavailable. Click anywhere or press Escape to close'; });
    const row = trigger.closest('[data-message-id]');
    const container = row?.parentElement;
    const index = row ? [...row.querySelectorAll('img.chat-custom-emoji')].indexOf(trigger) : -1;
    state.restoreFocus = () => {
      // Polling may replace the message row while its preview is open.
      const currentRow = row && [...(container?.children || [])].find(item => item.dataset.messageId === row.dataset.messageId);
      const replacement = currentRow?.querySelectorAll('img.chat-custom-emoji')[index];
      const target = trigger.isConnected ? trigger : replacement || doc.getElementById('chat-input');
      target?.focus({ preventScroll: true });
    };
    state.dialog = dialog;
    doc.body.append(dialog);
    dialog.showModal();
    // Keep the opening Space key from activating a newly focused Close button.
    dialog.focus({ preventScroll: true });
  };
  state.click = event => {
    const trigger = event.target.closest?.('img.chat-custom-emoji');
    if (!trigger || event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
    event.preventDefault();
    event.stopPropagation();
    open(trigger);
  };
  state.keydown = event => {
    if (!['Enter', ' '].includes(event.key) || !event.target.matches?.('img.chat-custom-emoji')) return;
    event.preventDefault();
    event.stopPropagation();
    open(event.target);
  };
  state.close = close;
  doc.addEventListener('click', state.click, true);
  doc.addEventListener('keydown', state.keydown, true);
  viewers.set(doc, state);
  return () => release(doc, state);
}

function release(doc, state) {
  if (--state.users > 0) return;
  state.close();
  doc.removeEventListener('click', state.click, true);
  doc.removeEventListener('keydown', state.keydown, true);
  viewers.delete(doc);
}
