// One in-message expansion per document, shared by all chat channels.
const viewers = new WeakMap();

export function installCustomEmojiViewer(doc = document) {
  const existing = viewers.get(doc);
  if (existing) { existing.users++; return () => release(doc, existing); }
  const state = { users: 1, active: null };
  const mark = (image, expanded) => {
    image.classList.toggle('chat-custom-emoji-expanded', expanded);
    image.setAttribute('aria-expanded', String(expanded));
    image.setAttribute('aria-label', `${expanded ? 'Collapse' : 'View full-size'} ${image.alt || 'custom emoji'}`);
  };
  const close = () => {
    if (state.active) mark(state.active.image, false);
    state.active = null;
    observer.disconnect();
  };
  // Polling can replace a row. Keep the expansion attached to its message,
  // never recreate it elsewhere in the page or follow it into another channel.
  const observer = new MutationObserver(() => {
    const active = state.active;
    if (!active || active.image.isConnected) return;
    const row = [...(active.container?.children || [])].find(item => item.dataset.messageId === active.messageId);
    const replacement = row?.querySelectorAll('img.chat-custom-emoji')[active.index];
    if (!replacement || replacement.src !== active.src) { close(); return; }
    active.image = replacement;
    mark(replacement, true);
  });
  const open = image => {
    const row = image.closest('[data-message-id]');
    state.active = { image, src: image.src, container: row?.parentElement,
      messageId: row?.dataset.messageId,
      index: row ? [...row.querySelectorAll('img.chat-custom-emoji')].indexOf(image) : -1 };
    mark(image, true);
    observer.observe(doc.body, { childList: true, subtree: true });
  };
  state.click = event => {
    if (event.button !== 0) return;
    const image = event.target.closest?.('img.chat-custom-emoji');
    const wasActive = image && image === state.active?.image;
    close();
    if (!image || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
    event.preventDefault();
    event.stopPropagation();
    if (!wasActive) open(image);
  };
  state.keydown = event => {
    if (event.key === 'Escape' && state.active) {
      event.preventDefault();
      const image = state.active.image;
      close();
      image.focus({ preventScroll: true });
      return;
    }
    if (!['Enter', ' '].includes(event.key) || !event.target.matches?.('img.chat-custom-emoji')) return;
    event.preventDefault();
    event.stopPropagation();
    const wasActive = event.target === state.active?.image;
    close();
    if (!wasActive) open(event.target);
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
