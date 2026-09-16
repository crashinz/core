// View-only avatar synchronization shared by first-party game presentations.
const appRoot = new URL('../', import.meta.url);
const bindings = new Map();
let timer = 0;
const generic = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#111b24"/><circle cx="32" cy="23" r="12" fill="#91a5ad"/><path d="M11 60c2-15 10-23 21-23s19 8 21 23" fill="#91a5ad"/></svg>');

function mediaUrl(value) {
  const url = String(value || '');
  if (!url || url.startsWith('preset:')) return '';
  if (url.startsWith('/assets/')) return new URL(url.slice(1), appRoot).href;
  try {
    const parsed = new URL(url, appRoot);
    return ['https:', 'http:', 'blob:', 'data:'].includes(parsed.protocol) ? parsed.href : '';
  } catch { return ''; }
}

function synchronize(image, binding) {
  let room = null;
  if (Number(binding.member?.userId) > 0 && window.parent !== window) {
    try { room = window.parent.coreChatGameAvatar?.(Number(binding.member.userId)) || null; }
    catch { /* Standalone/cross-origin games keep their server projection. */ }
  }
  const hidden = room?.hidden === true || binding.member?.avatarHidden === true;
  let candidate = hidden ? binding.fallback
    : mediaUrl(room?.url) || mediaUrl(binding.member?.avatarUrl) || binding.fallback;
  if (binding.rectangularFallback && candidate === binding.originalFallback) candidate = binding.fallback;
  if (candidate === binding.requested) return;
  binding.requested = candidate;
  image.src = candidate;
}

export function bindGameAvatar(image, member, { rectangularFallback = false } = {}) {
  const originalFallback = mediaUrl(member?.avatarFallbackUrl);
  const binding = { member, originalFallback, rectangularFallback, fallback: rectangularFallback ? generic : originalFallback || generic, requested: null };
  bindings.set(image, binding);
  image.draggable = false;
  image.addEventListener('error', () => {
    if (image.src === generic) return;
    if (image.src !== binding.fallback) image.src = binding.fallback;
    else image.src = generic;
  });
  synchronize(image, binding);
  if (!timer) timer = window.setInterval(() => {
    for (const [node, current] of bindings) {
      if (!node.isConnected) bindings.delete(node);
      else synchronize(node, current);
    }
    if (!bindings.size) { clearInterval(timer); timer = 0; }
  }, 750);
  return image;
}

window.addEventListener('pagehide', () => { clearInterval(timer); timer = 0; bindings.clear(); });
