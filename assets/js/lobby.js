'use strict';

const APP_BASE = document.body?.dataset.appBase || '';
const CSRF_TOKEN = document.body?.dataset.csrf || '';
const CAN_ADMIN_SETTINGS_MUTATE = document.body?.dataset.isAdmin === 'true';
const IS_INSTALLATION_OWNER = document.body?.dataset.isInstallationOwner === 'true';

function appUrl(path) {
  if (!path) return APP_BASE || '/';
  if (/^(?:https?:)?\/\//.test(path) || path.startsWith('data:') || path.startsWith('blob:')) return path;
  if (!path.startsWith('/')) return path;
  if (APP_BASE && path.startsWith(`${APP_BASE}/`)) return path;
  return `${APP_BASE}${path}`;
}

const backgroundInput = document.getElementById('room-background-input');
const backgroundName = document.getElementById('room-background-name');
const createRoomForm = document.getElementById('create-room-form');
const createRoomProgress = document.getElementById('room-upload-progress');
const createTabButtons = [...document.querySelectorAll('[data-create-tab]')];
const roomImportUrl = document.getElementById('room-import-url');
const roomImportPreviewBtn = document.getElementById('room-import-preview');
const roomImportStatus = document.getElementById('room-import-status');
const roomImportPreviewCard = document.getElementById('room-import-preview-card');
const roomGrid = document.getElementById('room-grid');
const lobbyRoomIds = new Set([...document.querySelectorAll('.room-card[data-room-id]')].map(card => card.dataset.roomId));
let lobbyPollTimer = null;
let currentImportPreview = null;

if (backgroundInput && backgroundName) {
  backgroundInput.addEventListener('change', () => {
    const file = backgroundInput.files && backgroundInput.files[0];
    backgroundName.textContent = file ? file.name : 'No file selected';
  });
}

function setRoomCreateTab(tab) {
  createTabButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.createTab === tab));
  document.getElementById('room-create-manual')?.classList.toggle('active', tab === 'manual');
  document.getElementById('room-create-import')?.classList.toggle('active', tab === 'import');
}

createTabButtons.forEach(btn => {
  btn.addEventListener('click', () => setRoomCreateTab(btn.dataset.createTab || 'manual'));
});

const lobbyMenu = document.getElementById('lobby-menu');
const lobbyMenuBtn = document.getElementById('lobby-menu-btn');

lobbyMenuBtn?.addEventListener('click', e => {
  e.stopPropagation();
  lobbyMenu.classList.toggle('visible');
});

document.addEventListener('click', e => {
  if (!lobbyMenu || lobbyMenu.contains(e.target) || e.target === lobbyMenuBtn) return;
  lobbyMenu.classList.remove('visible');
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') lobbyMenu?.classList.remove('visible');
});

const lobbyRoomEditModal = document.getElementById('lobby-room-edit-modal');
const lobbyRoomEditForm = document.getElementById('lobby-room-edit-form');
const lobbyRoomEditName = document.getElementById('lobby-room-edit-name');
const lobbyRoomEditId = document.getElementById('lobby-room-edit-id');
const lobbyRoomEditBackground = document.getElementById('lobby-room-edit-background');
const lobbyRoomEditBackgroundName = document.getElementById('lobby-room-edit-background-name');
const lobbyRoomEditProgress = document.getElementById('lobby-room-edit-upload-progress');
const lobbyRoomEjectionList = document.getElementById('lobby-room-ejection-list');
const lobbyRoomEditPreview = document.getElementById('lobby-room-edit-preview');
const lobbyRoomDeleteModal = document.getElementById('lobby-room-delete-modal');
const lobbyToast = document.getElementById('lobby-toast');
const passwordModal = document.getElementById('password-modal');
const passwordForm = document.getElementById('password-form');
const passwordStatus = document.getElementById('password-status');
const recoveryModal = document.getElementById('recovery-modal');
const recoveryCard = document.getElementById('recovery-card');
const recoveryStatus = document.getElementById('recovery-status');
const recoveryGenerate = document.getElementById('recovery-generate');
const recoveryOpen = document.getElementById('recovery-open');
const recoveryClose = document.getElementById('recovery-close');

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

function redirectToLogin() {
  window.location.href = appUrl('/login.php');
}

function roomCardSelector(id) {
  if (window.CSS?.escape) return `.room-card[data-room-id="${CSS.escape(id)}"]`;
  return `.room-card[data-room-id="${String(id).replace(/"/g, '\\"')}"]`;
}

function roomVideoPlaceholder(room) {
  return room?.video_without_thumb ? '<div class="room-video-placeholder">Video Room</div>' : '';
}

function roomCardHtml(room) {
  const bg = room.tile_background_url ? ` style="background-image:url('${esc(room.tile_background_url)}')"` : '';
  const edit = room.can_edit
    ? `<button class="btn btn-primary room-edit-open" type="button" data-room-id="${esc(room.public_id)}" data-room-name="${esc(room.name)}" data-room-bg="${esc(room.background_url || '')}" data-room-thumb="${esc(room.thumb_url || '')}" data-room-mime="${esc(room.background_mime || '')}">Edit</button>`
    : '';
  return `<div class="room-card-media"${bg}>${roomVideoPlaceholder(room)}</div>
    <div class="room-card-body">
      <h2 class="room-card-name">${esc(room.name)}</h2>
      <div class="minor room-card-meta"><span class="room-card-count">${Number(room.online_count || 0)}</span> online · made by <span class="room-card-owner">${esc(room.owner_name)}</span></div>
      <p class="room-card-actions">
        <a class="btn btn-primary" href="${esc(room.enter_url)}">Enter</a>
        ${edit}
      </p>
    </div>`;
}

function roomCardFor(room, animate = false) {
  const card = document.createElement('article');
  card.className = `room-card${animate ? ' room-card-entering' : ''}`;
  card.dataset.roomId = room.public_id;
  card.innerHTML = roomCardHtml(room);
  if (animate) {
    requestAnimationFrame(() => card.classList.add('show'));
    window.setTimeout(() => card.classList.remove('room-card-entering', 'show'), 520);
  }
  return card;
}

function updateRoomCard(card, room) {
  if (!card) return;
  const name = card.querySelector('.room-card-name');
  const count = card.querySelector('.room-card-count');
  const owner = card.querySelector('.room-card-owner');
  const enter = card.querySelector('.room-card-actions a');
  const edit = card.querySelector('.room-edit-open');
  if (name && name.textContent !== room.name) name.textContent = room.name;
  if (count && count.textContent !== String(Number(room.online_count || 0))) count.textContent = String(Number(room.online_count || 0));
  if (owner && owner.textContent !== room.owner_name) owner.textContent = room.owner_name;
  if (enter) enter.href = room.enter_url;
  if (edit) {
    edit.dataset.roomId = room.public_id;
    edit.dataset.roomName = room.name;
    edit.dataset.roomBg = room.background_url || '';
    edit.dataset.roomThumb = room.thumb_url || '';
    edit.dataset.roomMime = room.background_mime || '';
  }
}

function insertRoomCard(room, animate = true) {
  if (!roomGrid || !room?.public_id || lobbyRoomIds.has(room.public_id)) return null;
  const card = roomCardFor(room, animate);
  const firstRoom = roomGrid.querySelector('.room-card[data-room-id]');
  roomGrid.insertBefore(card, firstRoom || null);
  lobbyRoomIds.add(room.public_id);
  return card;
}

function removeMissingRoomCards(activeIds) {
  document.querySelectorAll('.room-card[data-room-id]').forEach(card => {
    const id = card.dataset.roomId;
    if (activeIds.has(id)) return;
    lobbyRoomIds.delete(id);
    card.classList.add('room-card-leaving');
    window.setTimeout(() => card.remove(), 260);
  });
}

function applyLobbyRooms(rooms = []) {
  const activeIds = new Set();
  rooms.forEach(room => {
    activeIds.add(room.public_id);
    const existing = document.querySelector(roomCardSelector(room.public_id));
    if (existing) updateRoomCard(existing, room);
    else insertRoomCard(room, true);
  });
  removeMissingRoomCards(activeIds);
}

async function pollLobbyRooms() {
  clearTimeout(lobbyPollTimer);
  try {
    const resp = await fetch(appUrl('/api/lobby_rooms.php'), { cache: 'no-store' });
    if (resp.status === 401) {
      redirectToLogin();
      return;
    }
    const data = await resp.json().catch(() => ({}));
    if (data.redirect_url) {
      window.location.href = data.redirect_url;
      return;
    }
    if (!resp.ok || data.error) throw new Error(data.error || 'Could not refresh lobby.');
    applyLobbyRooms(data.rooms || []);
  } catch (err) {
    console.warn(err);
  }
  lobbyPollTimer = window.setTimeout(pollLobbyRooms, 7000);
}

function parseServerDate(value) {
  if (!value) return null;
  const date = new Date(String(value).replace(' ', 'T') + 'Z');
  return Number.isNaN(date.getTime()) ? null : date;
}

function relativeTimeLabel(date) {
  if (!date) return '';
  const seconds = Math.round((date.getTime() - Date.now()) / 1000);
  const units = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ];
  const formatter = new Intl.RelativeTimeFormat([], { numeric: 'auto' });
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size) return formatter.format(Math.round(seconds / size), unit);
  }
  return formatter.format(seconds, 'second');
}

function adminCreatedOn(value) {
  const date = parseServerDate(value);
  if (!date) return 'Unknown';
  const absolute = date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  return `${absolute} <span>${esc(relativeTimeLabel(date))}</span>`;
}

function adminCreatedOnText(value) {
  const date = parseServerDate(value);
  if (!date) return 'Unknown';
  const absolute = date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  return `${absolute} (${relativeTimeLabel(date)})`;
}

function setLobbyRoomPreview(path, mime = '') {
  if (!lobbyRoomEditPreview) return;
  const safePath = path ? esc(path) : '';
  const safeMime = esc(mime || '');
  if (!safePath) {
    lobbyRoomEditPreview.innerHTML = '<div class="room-edit-preview-empty">No background selected</div>';
    return;
  }
  if (String(mime || '').startsWith('video/')) {
    lobbyRoomEditPreview.innerHTML = `<video muted loop playsinline preload="metadata"><source src="${safePath}" type="${safeMime}"></video>`;
    return;
  }
  lobbyRoomEditPreview.innerHTML = `<img src="${safePath}" alt="Current room background">`;
}

async function lobbyApiPost(url, body) {
  const payload = Object.assign({}, body || {}, { _csrf: CSRF_TOKEN });
  const resp = await fetch(appUrl(url), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify(payload),
  });
  const data = await resp.json().catch(() => ({}));
  if (!resp.ok || data.error) throw new Error(data.error || 'Request failed');
  return data;
}

function importSectionThumb(section) {
  if (!section || section.type !== 'image' || !section.src) return '';
  return `<img src="${esc(section.src)}" alt="${esc(section.alt || 'Imported room image')}">`;
}

function setImportStatus(message, busy = false) {
  if (!roomImportStatus) return;
  roomImportStatus.innerHTML = message
    ? `<span class="${busy ? 'spinner-inline' : ''}"></span><span>${esc(message)}</span>`
    : '';
}

function renderRoomImportPreview(preview) {
  if (!roomImportPreviewCard) return;
  currentImportPreview = preview;
  const images = (preview.sections || []).filter(section => section.type === 'image').slice(0, 3);
  const text = (preview.sections || []).find(section => section.type === 'text')?.text || '';
  const defaultName = preview.title || (new URL(preview.source_url || roomImportUrl?.value || window.location.href)).hostname.replace(/^www\./, '') || 'Imported Room';
  roomImportPreviewCard.hidden = false;
  roomImportPreviewCard.innerHTML = `
    <div class="room-import-preview-head">
      <strong>${esc(defaultName)}</strong>
      <span>${esc((preview.music || []).length)} audio source${(preview.music || []).length === 1 ? '' : 's'}</span>
    </div>
    <div class="room-import-preview-images">${images.map(importSectionThumb).join('') || '<div class="room-import-preview-empty">No images found</div>'}</div>
    ${text ? `<p>${esc(text.length > 180 ? `${text.slice(0, 180)}...` : text)}</p>` : ''}
    <label>Room name<input id="room-import-name" value="${esc(defaultName)}"></label>
    <div class="room-import-actions">
      <button class="btn btn-primary" id="room-import-accept" type="button">Accept Import</button>
      <button class="btn" id="room-import-cancel" type="button">Cancel</button>
    </div>`;
  document.getElementById('room-import-cancel')?.addEventListener('click', () => {
    currentImportPreview = null;
    roomImportPreviewCard.hidden = true;
    roomImportPreviewCard.innerHTML = '';
    setImportStatus('');
  });
  document.getElementById('room-import-accept')?.addEventListener('click', acceptRoomImport);
}

async function previewRoomImport() {
  const url = roomImportUrl?.value.trim() || '';
  if (!url) {
    setImportStatus('Enter a URL first.');
    return;
  }
  if (roomImportPreviewBtn) roomImportPreviewBtn.disabled = true;
  if (roomImportPreviewCard) roomImportPreviewCard.hidden = true;
  setImportStatus('Collecting room assets...', true);
  try {
    const data = await lobbyApiPost('/api/room_import.php', { action: 'preview', url });
    renderRoomImportPreview(data.preview || {});
    setImportStatus('Preview ready.');
  } catch (err) {
    currentImportPreview = null;
    setImportStatus(err.message || 'Import preview failed.');
  } finally {
    if (roomImportPreviewBtn) roomImportPreviewBtn.disabled = false;
  }
}

async function acceptRoomImport() {
  const url = roomImportUrl?.value.trim() || currentImportPreview?.source_url || '';
  const name = document.getElementById('room-import-name')?.value.trim() || currentImportPreview?.title || '';
  const acceptBtn = document.getElementById('room-import-accept');
  if (acceptBtn) acceptBtn.disabled = true;
  setImportStatus('Copying assets into ChatSpace...', true);
  try {
    const data = await lobbyApiPost('/api/room_import.php', { action: 'create', url, name });
    if (data.room) insertRoomCard(data.room, true);
    roomImportUrl.value = '';
    currentImportPreview = null;
    if (roomImportPreviewCard) {
      roomImportPreviewCard.hidden = true;
      roomImportPreviewCard.innerHTML = '';
    }
    setImportStatus('Imported room created.');
    window.setTimeout(() => setImportStatus(''), 1800);
  } catch (err) {
    setImportStatus(err.message || 'Import failed.');
  } finally {
    if (acceptBtn) acceptBtn.disabled = false;
  }
}

roomImportPreviewBtn?.addEventListener('click', previewRoomImport);
roomImportUrl?.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    e.preventDefault();
    previewRoomImport();
  }
});

function setUploadProgress(progressEl, pct, message) {
  if (!progressEl) return;
  const bounded = Math.max(0, Math.min(100, Math.round(pct)));
  progressEl.classList.add('open');
  const bar = progressEl.querySelector('.upload-progress-bar');
  const pctEl = progressEl.querySelector('.upload-progress-pct');
  const msgEl = progressEl.querySelector('.upload-progress-msg');
  if (bar) bar.style.width = `${bounded}%`;
  if (pctEl) pctEl.textContent = `${bounded}%`;
  if (msgEl) msgEl.textContent = message || (bounded >= 100 ? 'Processing...' : 'Uploading...');
}

function resetUploadProgress(progressEl) {
  if (!progressEl) return;
  progressEl.classList.remove('open');
  setUploadProgress(progressEl, 0, 'Waiting...');
  progressEl.classList.remove('open');
}

function videoThumbnailBlob(file) {
  return new Promise((resolve) => {
    if (!file || !String(file.type || '').startsWith('video/')) {
      resolve(null);
      return;
    }
    const video = document.createElement('video');
    const url = URL.createObjectURL(file);
    const cleanup = () => URL.revokeObjectURL(url);
    video.muted = true;
    video.preload = 'metadata';
    video.playsInline = true;
    video.addEventListener('loadeddata', () => {
      try {
        video.currentTime = Math.min(1, Math.max(0, (video.duration || 1) / 4));
      } catch (err) {
        cleanup();
        resolve(null);
      }
    }, { once: true });
    video.addEventListener('seeked', () => {
      try {
        const canvas = document.createElement('canvas');
        const width = 720;
        const ratio = video.videoWidth ? video.videoHeight / video.videoWidth : 9 / 16;
        canvas.width = width;
        canvas.height = Math.max(1, Math.round(width * ratio));
        canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(blob => {
          cleanup();
          resolve(blob);
        }, 'image/jpeg', 0.82);
      } catch (err) {
        cleanup();
        resolve(null);
      }
    }, { once: true });
    video.addEventListener('error', () => {
      cleanup();
      resolve(null);
    }, { once: true });
    video.src = url;
  });
}

async function roomBackgroundFormData(form) {
  const fd = new FormData(form);
  if (!fd.has('_csrf')) fd.append('_csrf', CSRF_TOKEN);
  const file = fd.get('background');
  const thumb = await videoThumbnailBlob(file);
  if (thumb) fd.append('background_thumb', thumb, 'background-thumb.jpg');
  return fd;
}

function uploadFormWithProgress(form, url, progressEl) {
  return new Promise(async (resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const submitBtn = form.querySelector('button[type="submit"]');
    const previousDisabled = submitBtn ? submitBtn.disabled : false;
    if (submitBtn) submitBtn.disabled = true;
    setUploadProgress(progressEl, 0, 'Uploading...');
    let formData;
    try {
      formData = await roomBackgroundFormData(form);
    } catch (err) {
      if (submitBtn) submitBtn.disabled = previousDisabled;
      reject(err);
      return;
    }

    xhr.upload.addEventListener('progress', event => {
      if (!event.lengthComputable) {
        setUploadProgress(progressEl, 5, 'Uploading...');
        return;
      }
      const pct = (event.loaded / event.total) * 100;
      setUploadProgress(progressEl, pct, pct >= 100 ? 'Processing...' : 'Uploading...');
    });

    xhr.addEventListener('load', () => {
      setUploadProgress(progressEl, 100, 'Processing...');
      if (submitBtn) submitBtn.disabled = previousDisabled;
      if (xhr.status >= 200 && xhr.status < 400) {
        resolve(xhr);
        return;
      }
      reject(new Error(xhr.responseText || 'Upload failed'));
    });

    xhr.addEventListener('error', () => {
      if (submitBtn) submitBtn.disabled = previousDisabled;
      reject(new Error('Upload failed. The file may be too large or the connection may have dropped.'));
    });

    xhr.addEventListener('abort', () => {
      if (submitBtn) submitBtn.disabled = previousDisabled;
      reject(new Error('Upload canceled.'));
    });

    xhr.open('POST', url);
    xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
    xhr.send(formData);
  });
}

function uploadPlainFormWithProgress(form, url, progressEl) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const submitBtn = form.querySelector('button[type="submit"]');
    const previousDisabled = submitBtn ? submitBtn.disabled : false;
    if (submitBtn) submitBtn.disabled = true;
    setUploadProgress(progressEl, 0, 'Uploading...');

    xhr.upload.addEventListener('progress', event => {
      if (!event.lengthComputable) {
        setUploadProgress(progressEl, 5, 'Uploading...');
        return;
      }
      const pct = (event.loaded / event.total) * 100;
      setUploadProgress(progressEl, pct, pct >= 100 ? 'Processing import...' : 'Uploading import...');
    });

    xhr.addEventListener('load', () => {
      setUploadProgress(progressEl, 100, 'Processing import...');
      if (submitBtn) submitBtn.disabled = previousDisabled;
      if (xhr.status >= 200 && xhr.status < 400) {
        resolve(xhr);
        return;
      }
      reject(new Error(xhr.responseText || 'Import failed'));
    });

    xhr.addEventListener('error', () => {
      if (submitBtn) submitBtn.disabled = previousDisabled;
      reject(new Error('Import failed. The file may be too large or the connection may have dropped.'));
    });

    xhr.addEventListener('abort', () => {
      if (submitBtn) submitBtn.disabled = previousDisabled;
      reject(new Error('Import canceled.'));
    });

    const formData = new FormData(form);
    if (!formData.has('_csrf')) formData.append('_csrf', CSRF_TOKEN);
    xhr.open('POST', url);
    xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
    xhr.send(formData);
  });
}

async function loadLobbyRoomEjections(roomPublicId) {
  if (!lobbyRoomEjectionList || !roomPublicId) return;
  lobbyRoomEjectionList.innerHTML = '<div class="minor">Loading...</div>';
  try {
    const qs = new URLSearchParams({ action: 'ejections', room_public_id: roomPublicId });
    const data = await fetch(appUrl('/api/room_admin.php?' + qs)).then(r => r.json());
    lobbyRoomEjectionList.innerHTML = '';
    if (!(data.ejections || []).length) {
      lobbyRoomEjectionList.innerHTML = '<div class="minor">No active kicks.</div>';
      return;
    }
    (data.ejections || []).forEach(ejection => {
      const row = document.createElement('div');
      row.className = 'ejection-row';
      const duration = ejection.permanent ? 'Permanent' : `${ejection.duration_minutes} minutes`;
      row.innerHTML = `<div><strong>${esc(ejection.display_name)}</strong><div class="minor">${esc(duration)} · by ${esc(ejection.ejected_by_name)}</div></div><button class="btn btn-danger" type="button">Delete</button>`;
      row.querySelector('button').addEventListener('click', async () => {
        await lobbyApiPost('/api/room_admin.php', { action: 'ejection_delete', room_public_id: roomPublicId, id: ejection.id });
        await loadLobbyRoomEjections(roomPublicId);
      });
      lobbyRoomEjectionList.appendChild(row);
    });
  } catch (err) {
    lobbyRoomEjectionList.innerHTML = `<div class="minor">${esc(err.message || 'Could not load kicked users.')}</div>`;
  }
}

createRoomForm?.addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const resp = await uploadFormWithProgress(createRoomForm, appUrl('/api/lobby_rooms.php'), createRoomProgress);
    const data = JSON.parse(resp.responseText || '{}');
    if (data.error) throw new Error(data.error);
    if (data.room) insertRoomCard(data.room, true);
    else applyLobbyRooms(data.rooms || []);
    createRoomForm.reset();
    if (backgroundName) backgroundName.textContent = 'No file selected';
    window.setTimeout(() => resetUploadProgress(createRoomProgress), 650);
  } catch (err) {
    alert(err.message || err);
    resetUploadProgress(createRoomProgress);
  }
});

roomGrid?.addEventListener('click', e => {
  const btn = e.target.closest('.room-edit-open');
  if (!btn) return;
  lobbyRoomEditId.value = btn.dataset.roomId || '';
  lobbyRoomEditName.value = btn.dataset.roomName || '';
  lobbyRoomEditBackground.value = '';
  lobbyRoomEditBackgroundName.textContent = 'No file selected';
  resetUploadProgress(lobbyRoomEditProgress);
  setLobbyRoomPreview(btn.dataset.roomBg || '', btn.dataset.roomMime || '');
  if (String(btn.dataset.roomMime || '').startsWith('video/') && btn.dataset.roomThumb) {
    lobbyRoomEditPreview.innerHTML = `<img src="${esc(btn.dataset.roomThumb)}" alt="Current room background thumbnail">`;
  }
  lobbyRoomEditModal.classList.add('open');
  loadLobbyRoomEjections(lobbyRoomEditId.value);
});

document.getElementById('lobby-room-edit-close')?.addEventListener('click', () => {
  lobbyRoomEditModal.classList.remove('open');
});

document.getElementById('lobby-room-delete-open')?.addEventListener('click', () => {
  lobbyRoomDeleteModal?.classList.add('open');
});

function closeLobbyRoomDeleteModal() {
  lobbyRoomDeleteModal?.classList.remove('open');
}

document.getElementById('lobby-room-delete-close')?.addEventListener('click', closeLobbyRoomDeleteModal);
document.getElementById('lobby-room-delete-cancel')?.addEventListener('click', closeLobbyRoomDeleteModal);

document.getElementById('lobby-room-delete-confirm')?.addEventListener('click', async e => {
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('room_public_id', lobbyRoomEditId.value);
    fd.append('_csrf', CSRF_TOKEN);
    const resp = await fetch(appUrl('/api/room_admin.php'), { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: fd });
    const data = await resp.json();
    if (!resp.ok || data.error) throw new Error(data.error || 'Room delete failed');
    window.location.href = appUrl('/lobby.php?room_deleted=1');
  } catch (err) {
    alert(err.message || err);
    btn.disabled = false;
  }
});

document.getElementById('lobby-ejection-understand')?.addEventListener('click', () => {
  document.getElementById('lobby-ejection-modal')?.classList.remove('open');
});

if (new URLSearchParams(window.location.search).get('room_deleted') === '1' && lobbyToast) {
  lobbyToast.hidden = false;
  lobbyToast.classList.add('show');
  const clean = new URL(window.location.href);
  clean.searchParams.delete('room_deleted');
  window.history.replaceState({}, document.title, clean.toString());
}

pollLobbyRooms();

document.getElementById('lobby-toast-close')?.addEventListener('click', () => {
  lobbyToast?.classList.remove('show');
  if (lobbyToast) lobbyToast.hidden = true;
});

lobbyRoomEditBackground?.addEventListener('change', () => {
  const file = lobbyRoomEditBackground.files && lobbyRoomEditBackground.files[0];
  lobbyRoomEditBackgroundName.textContent = file ? file.name : 'No file selected';
  if (file) setLobbyRoomPreview(URL.createObjectURL(file), file.type);
});

lobbyRoomEditForm?.addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const fdAction = lobbyRoomEditForm.querySelector('input[name="action"]');
    if (!fdAction) {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'action';
      hidden.value = 'update';
      lobbyRoomEditForm.appendChild(hidden);
    }
    const resp = await uploadFormWithProgress(lobbyRoomEditForm, appUrl('/api/room_admin.php'), lobbyRoomEditProgress);
    const data = JSON.parse(resp.responseText || '{}');
    if (data.error) throw new Error(data.error);
    window.location.reload();
  } catch (err) {
    alert(err.message || 'Room update failed');
    resetUploadProgress(lobbyRoomEditProgress);
  }
});

function closePasswordModal() {
  passwordModal?.classList.remove('open');
  passwordForm?.reset();
  if (passwordStatus) {
    passwordStatus.textContent = '';
    passwordStatus.className = 'password-status';
  }
}

document.getElementById('password-open')?.addEventListener('click', () => {
  lobbyMenu?.classList.remove('visible');
  passwordModal?.classList.add('open');
  document.getElementById('password-old')?.focus();
});

document.getElementById('password-close')?.addEventListener('click', closePasswordModal);
document.getElementById('password-cancel')?.addEventListener('click', closePasswordModal);

passwordForm?.addEventListener('submit', async e => {
  e.preventDefault();
  if (passwordStatus) {
    passwordStatus.textContent = '';
    passwordStatus.className = 'password-status';
  }
  const form = e.currentTarget;
  try {
    await lobbyApiPost('/api/account.php', {
      action: 'update_password',
      old_password: form.old_password.value,
      new_password: form.new_password.value,
      confirm_password: form.confirm_password.value,
    });
    if (passwordStatus) {
      passwordStatus.textContent = 'Password updated.';
      passwordStatus.classList.add('ok');
    }
    form.reset();
  } catch (err) {
    if (passwordStatus) {
      passwordStatus.textContent = err.message || 'Could not update password.';
      passwordStatus.classList.add('error-text');
    }
  }
});

function setRecoveryStatus(message = '', type = '') {
  if (!recoveryStatus) return;
  recoveryStatus.textContent = message;
  recoveryStatus.className = `password-status ${type}`.trim();
}

function renderRecoveryStatus(data) {
  if (!recoveryCard) return;
  const code = data.recovery_code || data.masked_code || '';
  recoveryCard.innerHTML = data.has_code
    ? `<div class="recovery-code">${esc(code)}</div><div class="minor">${data.recovery_code ? 'Copy this code to a safe place. It will not be shown again after you close this window.' : 'A recovery code already exists. Only the last segment can be shown.'}</div>`
    : '<div class="minor">No Lost Access recovery code has been generated for this account.</div>';
  if (recoveryGenerate) recoveryGenerate.textContent = data.has_code ? 'Recreate Recovery Code' : 'Create Recovery Code';
}

async function loadRecoveryStatus() {
  setRecoveryStatus('');
  if (recoveryCard) recoveryCard.innerHTML = '<div class="minor">Checking recovery status...</div>';
  const data = await lobbyApiPost('/api/recovery.php', { action: 'status' });
  renderRecoveryStatus(data);
}

function closeRecoveryModal() {
  recoveryModal?.classList.remove('open');
  setRecoveryStatus('');
  recoveryOpen?.focus();
}

recoveryOpen?.addEventListener('click', async () => {
  lobbyMenu?.classList.remove('visible');
  recoveryModal?.classList.add('open');
  recoveryClose?.focus();
  try {
    await loadRecoveryStatus();
  } catch (err) {
    setRecoveryStatus(err.message || 'Could not load recovery status.', 'error-text');
  }
});

recoveryClose?.addEventListener('click', closeRecoveryModal);
document.getElementById('recovery-cancel')?.addEventListener('click', closeRecoveryModal);
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && recoveryModal?.classList.contains('open')) {
    event.preventDefault();
    closeRecoveryModal();
  }
});

recoveryGenerate?.addEventListener('click', async () => {
  const recreate = recoveryGenerate.textContent.includes('Recreate');
  if (recreate && !confirm('Recreate your recovery code? The old code will stop working.')) return;
  recoveryGenerate.disabled = true;
  setRecoveryStatus('Generating...', 'working');
  try {
    const data = await lobbyApiPost('/api/recovery.php', { action: 'generate' });
    renderRecoveryStatus(data);
    setRecoveryStatus('Recovery code generated. Copy it now and keep it somewhere safe.', 'ok');
  } catch (err) {
    setRecoveryStatus(err.message || 'Could not generate recovery code.', 'error-text');
  } finally {
    recoveryGenerate.disabled = false;
  }
});

const adminModal = document.getElementById('admin-modal');
const adminUsers = document.getElementById('admin-users');
const adminAccountSearch = document.getElementById('admin-account-search');
const adminAccountSearchStatus = document.getElementById('admin-account-search-status');
const adminModerationCases = document.getElementById('admin-moderation-cases');
const adminModerationUsers = document.getElementById('admin-moderation-users');
const adminModerationSearch = document.getElementById('admin-moderation-user-search');
let adminModerationPage = 1;
const adminToolLogs = document.getElementById('admin-tool-logs');
const adminBlocks = document.getElementById('admin-blocks');
const adminRoomEjections = document.getElementById('admin-room-ejections');
const adminCommunityEjections = document.getElementById('admin-community-ejections');
const adminSettings = document.getElementById('lobby-admin-settings-registry-form');
let adminSettingsRegistry = null;
let adminSettingsRegistryUI = null;
let adminSettingsUnlock = null;
let adminProfileLimitConfirmation = null;
let adminDatabaseCompatibilityConfirmation = null;
let adminModerationTrustConfirmation = null;
let adminRetentionConfirmation = null;
let adminOwnerTransferConfirmation = null;
let adminInstallationOwner = null;
const adminOwnerTransferForm = document.getElementById('admin-owner-transfer-form');
const adminNetworkPolicyForm = document.getElementById('admin-network-policy-form');
let adminNetworkPolicy = null;
let adminNetworkBanPreview = null;
const adminGestureCatalog = document.getElementById('admin-gesture-catalog');
const adminGesturePager = document.getElementById('admin-gesture-pager');
const adminGestureStatus = document.getElementById('admin-gesture-status');
const adminGestureSearch = document.getElementById('admin-gesture-search');
const adminGestureSort = document.getElementById('admin-gesture-sort');
const adminGestureState = {
  page: 1,
  pages: 1,
  total: 0,
  loading: false,
  request: 0,
  features: {},
  query: '',
  sort: 'last_uploaded',
  dirtyRows: new Set(),
};
let adminGestureSearchTimer = 0;
const adminGestureChannel = typeof BroadcastChannel === 'function'
  ? new BroadcastChannel('chatspace-gesture-catalog')
  : null;
const adminSettingsSyncKey = `chatspace.admin-settings.revision:${document.body.dataset.appBase || '/'}`;
const adminSettingsChannel = typeof BroadcastChannel === 'function'
  ? new BroadcastChannel(adminSettingsSyncKey)
  : null;
let adminSettingsSyncPending = false;
const adminDbExport = document.getElementById('admin-db-export');
const adminUserExportLabel = document.getElementById('admin-user-export-label');
const adminDbRestore = document.getElementById('admin-db-restore');
const adminDbImportProgress = document.getElementById('admin-db-import-progress');
const adminLinkIcons = document.getElementById('admin-link-icons');
const adminLinkIconCreate = document.getElementById('admin-link-icon-create');
const adminCounts = {
  users: document.getElementById('admin-user-count'),
  logs: document.getElementById('admin-log-count'),
  moderation: document.getElementById('admin-moderation-count'),
  linkIcons: document.getElementById('admin-link-icon-count'),
  summaryUsers: document.getElementById('admin-summary-users'),
  summaryModeration: document.getElementById('admin-summary-moderation'),
  healthWarnings: document.getElementById('admin-health-warning-count'),
};
const adminModerationTotals = { blocks: 0, roomEjections: 0, communityEjections: 0 };
let adminSystemHealth = null;
let capacityProfileImpact = null;
let diagnosticPolicyTimer = null;
let activeManageUsersSection = window.sessionStorage?.getItem(`chatspace.manage-users.section:${document.body.dataset.appBase || '/'}`) || 'accounts';

function applyAdminProgrammaticHeadingFocus(heading) {
  window.applyProgrammaticHeadingFocus(heading);
}

function focusAdminOwnedHeading(selector) {
  window.requestAnimationFrame(() => {
    const adminBox = document.querySelector('#admin-modal .admin-box');
    const adminMain = document.querySelector('#admin-modal .admin-main');
    const heading = document.querySelector(selector);
    if (adminBox) adminBox.scrollTop = 0;
    if (adminMain) adminMain.scrollTop = 0;
    applyAdminProgrammaticHeadingFocus(heading);
  });
}

function showManageUsersSection(sectionId, pushHistory = true) {
  const valid = ['accounts', 'add-account', 'roles-requests', 'account-actions', 'installation-owner'];
  if (!valid.includes(sectionId)) return false;
  if (!document.querySelector(`[data-manage-users-panel="${sectionId}"]`)) return false;
  activeManageUsersSection = sectionId;
  window.sessionStorage?.setItem(`chatspace.manage-users.section:${document.body.dataset.appBase || '/'}`, sectionId);
  document.querySelectorAll('[data-manage-users-panel]').forEach(panel => {
    panel.hidden = panel.dataset.manageUsersPanel !== sectionId;
  });
  document.querySelectorAll('[data-manage-users-section]').forEach(control => {
    const active = control.dataset.manageUsersSection === sectionId;
    control.classList.toggle('active', active);
    control.setAttribute('aria-current', active ? 'page' : 'false');
  });
  const selector = document.getElementById('admin-manage-users-selector');
  if (selector) selector.value = sectionId;
  if (pushHistory) {
    const url = new URL(window.location.href);
    url.hash = `manage-users-${sectionId}`;
    window.history.pushState({ manageUsersSection: sectionId }, '', url);
  }
  focusAdminOwnedHeading(`[data-manage-users-panel="${sectionId}"] h3`);
  return true;
}

function restoreManageUsersSection(sectionId) {
  if (showManageUsersSection(sectionId, false)) return true;
  const url = new URL(window.location.href);
  url.hash = 'manage-users-accounts';
  window.history.replaceState({ manageUsersSection: 'accounts' }, '', url);
  return showManageUsersSection('accounts', false);
}

const adminExactDestinations = {
  'moderation-users': {
    section: 'moderation',
    fragment: 'actions-users',
    target: '#admin-moderation-user-search',
  },
};

function focusAdminExactDestination(targetSelector) {
  window.requestAnimationFrame(() => {
    const adminMain = document.querySelector('#admin-modal .admin-main');
    const target = document.querySelector(targetSelector);
    const panel = target?.closest('.admin-panel');
    const heading = panel?.querySelector('h3');
    if (adminMain && panel) {
      const mainRect = adminMain.getBoundingClientRect();
      const panelRect = panel.getBoundingClientRect();
      adminMain.scrollTop = Math.max(0, adminMain.scrollTop + panelRect.top - mainRect.top - 12);
    }
    applyAdminProgrammaticHeadingFocus(heading);
  });
}

function showAdminExactDestination(destinationId, pushHistory = true) {
  const destination = adminExactDestinations[destinationId];
  if (!destination || !showAdminSection(destination.section)) return false;
  if (pushHistory) {
    const url = new URL(window.location.href);
    url.hash = destination.fragment;
    window.history.pushState({ adminDestination: destinationId }, '', url);
  }
  focusAdminExactDestination(destination.target);
  return true;
}

function restoreAdminLocationFromHash() {
  const hash = String(window.location.hash || '').replace(/^#/, '');
  const adminDestination = Object.keys(adminExactDestinations)
    .find(id => adminExactDestinations[id].fragment === hash);
  if (adminDestination) return showAdminExactDestination(adminDestination, false);
  if (!hash.startsWith('manage-users-')) return false;
  const sectionId = hash.replace(/^manage-users-/, '');
  if (!showAdminSection('users')) return false;
  return restoreManageUsersSection(sectionId);
}

function initializeAdminInformationArchitecture() {
  const usersSection = document.getElementById('admin-section-users');
  if (!usersSection || document.getElementById('admin-manage-users-navigation')) return;
  const navigation = document.createElement('nav');
  navigation.id = 'admin-manage-users-navigation';
  navigation.className = 'admin-secondary-navigation';
  navigation.setAttribute('aria-label', 'Manage Users sections');
  const selectLabel = document.createElement('label');
  selectLabel.className = 'admin-secondary-selector-label';
  selectLabel.textContent = 'Manage Users section';
  const selector = document.createElement('select');
  selector.id = 'admin-manage-users-selector';
  selector.className = 'admin-secondary-selector';
  selector.setAttribute('aria-label', 'Manage Users section');
  selectLabel.appendChild(selector);
  const list = document.createElement('div');
  list.className = 'admin-secondary-nav-list';
  const definitions = [
    ['accounts', 'Accounts'],
    ['add-account', 'Add Account'],
    ['roles-requests', 'Roles & Requests'],
    ['account-actions', 'Account Actions'],
    ['installation-owner', 'Installation Owner'],
  ].filter(([id]) => id !== 'installation-owner' || document.getElementById('admin-owner-panel'));
  for (const [id, label] of definitions) {
    const option = document.createElement('option');
    option.value = id;
    option.textContent = label;
    selector.appendChild(option);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'admin-secondary-nav-item';
    button.dataset.manageUsersSection = id;
    const text = document.createElement('span');
    text.textContent = label;
    button.appendChild(text);
    if (id === 'accounts' || id === 'roles-requests') {
      const count = document.createElement('span');
      count.className = 'admin-nav-count';
      count.dataset.manageUsersCount = id;
      count.textContent = '0';
      button.appendChild(count);
    }
    button.addEventListener('click', () => showManageUsersSection(id));
    list.appendChild(button);
  }
  selector.addEventListener('change', () => showManageUsersSection(selector.value));
  navigation.append(selectLabel, list);

  const content = document.createElement('div');
  content.className = 'admin-secondary-content';
  const panelDefinitions = [
    ['accounts', 'Accounts', document.getElementById('admin-accounts-panel')],
    ['add-account', 'Add Account', document.getElementById('admin-add-account-panel')],
    ['roles-requests', 'Roles & Requests', document.getElementById('admin-roles-requests-panel')],
    ['account-actions', 'Account Actions', null],
    ['installation-owner', 'Installation Owner', document.getElementById('admin-owner-panel')],
  ].filter(([id]) => id !== 'installation-owner' || document.getElementById('admin-owner-panel'));
  for (const [id, label, existing] of panelDefinitions) {
    const panel = document.createElement('section');
    panel.className = 'admin-secondary-panel';
    panel.dataset.manageUsersPanel = id;
    const heading = document.createElement('h3');
    heading.textContent = label;
    panel.appendChild(heading);
    if (existing) panel.appendChild(existing);
    if (id === 'account-actions') {
      const copy = document.createElement('p');
      copy.className = 'minor';
      copy.textContent = 'Use non-destructive suspension and session revocation when an account needs immediate restriction. Account deletion is not available here.';
      const link = document.createElement('button');
      link.type = 'button';
      link.className = 'btn';
      link.textContent = 'Open Moderation Actions';
      link.dataset.adminJump = 'moderation';
      link.dataset.adminDestination = 'moderation-users';
      panel.append(copy, link);
    }
    content.appendChild(panel);
  }

  const firstContent = usersSection.querySelector('.admin-section-sub')?.nextSibling;
  usersSection.insertBefore(navigation, firstContent);
  usersSection.insertBefore(content, navigation.nextSibling);

  const moderationPanel = document.getElementById('admin-moderation-user-search')?.closest('.admin-panel');
  const moderationSection = document.getElementById('admin-section-moderation');
  if (moderationPanel && moderationSection) moderationSection.appendChild(moderationPanel);
  const networkPanel = document.getElementById('admin-network-privacy-panel');
  const networkDestination = document.getElementById('system-health-network-privacy-destination');
  if (networkPanel && networkDestination) networkDestination.appendChild(networkPanel);
  if (!networkPanel && networkDestination) networkDestination.hidden = true;
  const retentionPanel = document.getElementById('admin-retention-panel');
  const retentionDestination = document.getElementById('admin-settings-retention-destination');
  if (retentionPanel && retentionDestination) retentionDestination.appendChild(retentionPanel);

  const syncCounts = () => {
    const accountTarget = document.querySelector('[data-manage-users-count="accounts"]');
    const requestTarget = document.querySelector('[data-manage-users-count="roles-requests"]');
    const accountCount = adminCounts.users?.textContent || '0';
    const requestCount = String(adminModerationCases?.querySelectorAll('.admin-user-row, [data-case-id]').length || 0);
    if (accountTarget && accountTarget.textContent !== accountCount) accountTarget.textContent = accountCount;
    if (requestTarget && requestTarget.textContent !== requestCount) requestTarget.textContent = requestCount;
  };
  new MutationObserver(syncCounts).observe(usersSection, { childList: true, subtree: true, characterData: true });
  syncCounts();
  if (activeManageUsersSection === 'installation-owner' && !document.getElementById('admin-owner-panel')) activeManageUsersSection = 'accounts';
  showManageUsersSection(activeManageUsersSection, false);
}

window.addEventListener('popstate', event => {
  const hash = String(window.location.hash || '').replace(/^#/, '');
  const adminDestination = event.state?.adminDestination
    || Object.keys(adminExactDestinations).find(id => adminExactDestinations[id].fragment === hash);
  if (adminDestination) {
    showAdminExactDestination(adminDestination, false);
    return;
  }
  const value = event.state?.manageUsersSection || String(window.location.hash || '').replace(/^#manage-users-/, '');
  if (value) {
    showAdminSection('users');
    restoreManageUsersSection(value);
  }
});
initializeAdminInformationArchitecture();

function setAdminCount(el, value) {
  if (el) el.textContent = String(value);
}

function refreshAdminModerationCount() {
  const total = adminModerationTotals.blocks + adminModerationTotals.roomEjections + adminModerationTotals.communityEjections;
  setAdminCount(adminCounts.moderation, total);
  setAdminCount(adminCounts.summaryModeration, total);
}

function setAdminFormStatus(form, message, type = '') {
  const status = form?.querySelector?.('.admin-row-status, .admin-form-status');
  if (!status) return;
  status.textContent = message || '';
  status.className = `${status.classList.contains('admin-form-status') ? 'admin-form-status' : 'admin-row-status'} ${type}`.trim();
}

function showAdminSection(id) {
  const active = document.querySelector('.admin-section.active')?.id || '';
  const leavingGestures = active === 'admin-section-gestures' && id !== 'gestures';
  if (leavingGestures && !confirmAdminGestureDiscard(`Opening the ${id} Admin section`)) return false;
  if (leavingGestures) clearAdminGestureDirtyState();
  document.querySelectorAll('.admin-section').forEach(section => {
    section.classList.toggle('active', section.id === `admin-section-${id}`);
  });
  document.querySelectorAll('.admin-nav-item[data-admin-section]').forEach(btn => {
    const selected = btn.dataset.adminSection === id;
    btn.classList.toggle('active', selected);
    btn.setAttribute('aria-current', selected ? 'page' : 'false');
  });
  focusAdminOwnedHeading(`#admin-section-${id} .admin-section-title`);
  if (id === 'gestures') loadAdminGestures().catch(error => setAdminGestureStatus(error.message || 'Gesture catalog could not be loaded.', 'error'));
  if (id === 'system-health') loadAdminSystemHealth().catch(error => setSystemHealthStatus(error.message || 'System Health could not be loaded.', 'error'));
  return true;
}

document.addEventListener('click', e => {
  const nav = e.target.closest('.admin-nav-item[data-admin-section]');
  if (nav) {
    showAdminSection(nav.dataset.adminSection);
    return;
  }
  const jump = e.target.closest('[data-admin-jump]');
  if (jump) {
    if (jump.dataset.adminDestination) {
      showAdminExactDestination(jump.dataset.adminDestination);
      return;
    }
    if (!showAdminSection(jump.dataset.adminJump)) return;
    const settingsView = jump.dataset.settingsView;
    if (settingsView) {
      window.requestAnimationFrame(() => adminSettingsRegistryUI?.selectView?.(settingsView));
    }
  }
});

async function adminRequest(body) {
  const payload = Object.assign({}, body || {}, { _csrf: CSRF_TOKEN });
  const resp = await fetch(appUrl('/api/admin_users.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify(payload),
  });
  const data = await resp.json().catch(() => ({}));
  if (!resp.ok || data.error) throw new Error(data.error || 'Admin request failed');
  return data;
}

async function adminSystemRequest(body) {
  const payload = Object.assign({}, body || {}, { _csrf: CSRF_TOKEN });
  const resp = await fetch(appUrl('/api/admin_system.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify(payload),
  });
  const data = await resp.json().catch(() => ({}));
  if (resp.redirected || resp.status === 401 || resp.status === 403) {
    adminSettingsUnlock?.setAuthorized(false, 'You are no longer authorized to change these settings.');
  }
  if (!resp.ok || data.error) {
    const error = new Error(data.error || 'Admin request failed');
    error.data = data;
    throw error;
  }
  return data;
}

async function adminNetworkRequest(body = null) {
  const options = { cache: 'no-store' };
  if (body !== null) {
    options.method = 'POST';
    options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN };
    options.body = JSON.stringify({ ...body, _csrf: CSRF_TOKEN });
  }
  const response = await fetch(appUrl('/api/admin_network.php'), options);
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.error) {
    const error = new Error(data.error || 'Network privacy request failed.');
    error.data = data;
    throw error;
  }
  return data;
}

function setAdminNetworkStatus(message, type = '') {
  const status = document.getElementById('admin-network-policy-status');
  if (!status) return;
  status.textContent = message || '';
  status.className = `admin-form-status ${type}`.trim();
}

function renderAdminNetworkPolicy(data) {
  adminNetworkPolicy = data.transportPolicy || adminNetworkPolicy;
  if (!adminNetworkPolicy) return;
  const hsts = document.getElementById('admin-network-hsts');
  if (hsts) hsts.checked = Boolean(adminNetworkPolicy.hstsDeploymentVerified);
  const current = document.getElementById('admin-network-trusted-proxies-current');
  if (current) {
    const masked = adminNetworkPolicy.trustedProxiesMasked || [];
    current.textContent = masked.length
      ? `${Number(adminNetworkPolicy.trustedProxyCount || masked.length)} private trusted-proxy entr${masked.length === 1 ? 'y' : 'ies'} configured: ${masked.join(', ')}.`
      : 'No private trusted proxies are configured.';
  }

  const contextSelect = document.getElementById('admin-network-context');
  if (contextSelect) {
    const prior = contextSelect.value;
    contextSelect.replaceChildren(new Option('Choose an account or activity', ''));
    for (const context of data.contexts || []) {
      const multiple = context.observedForMultipleAccounts
        ? ` · ${Number(context.affectedAccountCount)} affected accounts`
        : ' · one affected account';
      const option = new Option(
        `${context.owner} · ${context.context} · ${context.manualBanStatus}${multiple}`,
        context.id
      );
      contextSelect.appendChild(option);
    }
    if (Array.from(contextSelect.options).some(option => option.value === prior)) {
      contextSelect.value = prior;
    }
    contextSelect.disabled = !data.manualBanPolicy?.enabled;
  }

  const list = document.getElementById('admin-network-bans');
  if (list) {
    list.replaceChildren();
    for (const ban of data.bans || []) {
      const article = document.createElement('article');
      article.className = 'admin-user-row';
      const title = document.createElement('strong');
      title.textContent = `${ban.status} manual ban`;
      const summary = document.createElement('span');
      summary.textContent = `${ban.reason} · created by ${ban.creator} at ${ban.createdAt}`
        + `${ban.expiresAt ? ` · expires ${ban.expiresAt}` : ' · Permanent'}`
        + ` · ${Number(ban.affectedAccountCount)} affected account${Number(ban.affectedAccountCount) === 1 ? '' : 's'}`;
      const accounts = document.createElement('small');
      accounts.textContent = (ban.affectedAccounts || [])
        .map(account => `${account.displayName}${account.username ? ` (@${account.username})` : ''}`)
        .join(', ') || 'No retained account identity remains.';
      article.append(title, summary, accounts);
      if (ban.status === 'active') {
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-danger';
        remove.textContent = 'Remove Ban';
        remove.addEventListener('click', async () => {
          if (!adminSettingsUnlock?.requireUnlocked()) return;
          const reason = window.prompt('Reason for removing this manual network ban:') || '';
          if (!reason.trim()) return;
          if (!window.confirm('Remove this manual network ban after reviewing the reason?')) return;
          try {
            await adminNetworkRequest({
              action: 'remove_manual_ban',
              banId: ban.id,
              reason,
              requestId: crypto.randomUUID(),
              confirmed: true,
            });
            await Promise.all([loadAdminNetworkPolicy(), loadAdminLogs()]);
          } catch (error) {
            setAdminNetworkStatus(error.message || 'The manual ban could not be removed.', 'error');
          }
        });
        article.appendChild(remove);
      }
      list.appendChild(article);
    }
    if (!list.children.length) {
      list.innerHTML = '<p class="minor">No active or expired manual network bans.</p>';
    }
  }
}

async function loadAdminNetworkPolicy() {
  if (!IS_INSTALLATION_OWNER || !adminNetworkPolicyForm) return;
  const data = await adminNetworkRequest();
  renderAdminNetworkPolicy(data);
}

async function adminRetentionRequest(payload) {
  const response = await fetch(appUrl('/api/admin_retention.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify({ ...payload, _csrf: CSRF_TOKEN }),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.error) throw new Error(data.error || 'Retention request failed.');
  return data;
}

async function adminRetentionProjection() {
  const response = await fetch(appUrl('/api/admin_retention.php'), { cache: 'no-store' });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.error) throw new Error(data.error || 'Retention policy could not load.');
  return data;
}

function renderAdminRetention(data) {
  const retention = data.retention || {};
  const list = document.getElementById('admin-retention-policies');
  if (list) {
    const domainSelect = document.querySelector('#admin-retention-form select[name="domain"]');
    const domainOptions = domainSelect ? Array.from(domainSelect.options) : [];
    list.innerHTML = (retention.policies || []).map(policy => `
      <article class="admin-user-row">
        <strong>${esc(domainOptions.find(option => option.value === String(policy.domain))?.textContent || 'Other retained data')}</strong>
        <span>${policy.keepForever ? 'Keep forever' : `${Number(policy.days)} days`}</span>
        <small>Revision ${Number(policy.revision || 1)}</small>
      </article>`).join('');
  }
  const disclosure = document.getElementById('admin-retention-backup-disclosure');
  if (disclosure) disclosure.textContent = retention.backupDisclosure || '';
}

async function loadAdminRetention() {
  const panel = document.getElementById('admin-retention-panel');
  if (!panel) return;
  const data = await adminRetentionProjection();
  renderAdminRetention(data);
}

document.getElementById('admin-retention-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const status = document.getElementById('admin-retention-status');
  const confirmation = document.getElementById('admin-retention-confirmation');
  try {
    status.textContent = 'Calculating bounded preview...';
    const payload = {
      action: 'preview',
      domain: form.elements.domain.value,
      days: Number(form.elements.days.value),
      keepForever: form.elements.keep_forever.checked,
    };
    const result = await adminRetentionRequest(payload);
    adminRetentionConfirmation = {
      ...payload,
      expectedRevision: Number((await adminRetentionProjection()).retention.policies
        .find(policy => policy.domain === payload.domain)?.revision || 0),
      preview: result.preview,
    };
    document.getElementById('admin-retention-preview').textContent =
      `${result.preview.estimatedEligibleItems} currently eligible items. `
      + `${result.preview.keepForever ? 'Future expiry will be disabled.' : `New retention: ${result.preview.days} days.`}`;
    confirmation.hidden = false;
    status.textContent = 'Review the exact estimate and backup disclosure before confirming.';
    document.getElementById('admin-retention-confirm')?.focus();
  } catch (error) {
    status.textContent = error.message || 'Retention preview failed.';
    confirmation.hidden = true;
    adminRetentionConfirmation = null;
  }
});

document.getElementById('admin-retention-confirm')?.addEventListener('click', async () => {
  if (!adminRetentionConfirmation) return;
  const status = document.getElementById('admin-retention-status');
  try {
    let result = await adminRetentionRequest({
      action: 'request_change',
      requestId: crypto.randomUUID(),
      domain: adminRetentionConfirmation.domain,
      days: adminRetentionConfirmation.days,
      keepForever: adminRetentionConfirmation.keepForever,
      expectedRevision: adminRetentionConfirmation.expectedRevision,
      confirmed: true,
    });
    let request = result.request;
    while (['preparing', 'running', 'interrupted'].includes(request.status)) {
      result = await adminRetentionRequest({
        action: 'continue',
        requestId: request.requestId,
        batchSize: 100,
      });
      request = result.request;
    }
    status.textContent = `Retention change complete. ${request.deletedMessages || 0} messages and ${request.deletedEvidence || 0} evidence items expired.`;
    document.getElementById('admin-retention-confirmation').hidden = true;
    adminRetentionConfirmation = null;
    await Promise.all([loadAdminRetention(), loadAdminLogs()]);
  } catch (error) {
    status.textContent = error.message || 'Retention change failed safely.';
  }
});

document.getElementById('admin-retention-cancel')?.addEventListener('click', () => {
  adminRetentionConfirmation = null;
  document.getElementById('admin-retention-confirmation').hidden = true;
  document.getElementById('admin-retention-status').textContent = 'Retention change canceled before mutation.';
});

adminNetworkPolicyForm?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!IS_INSTALLATION_OWNER || !adminSettingsUnlock?.requireUnlocked()) return;
  const payload = {
    action: 'update_transport_policy',
    expectedRevision: Number(adminNetworkPolicy?.revision || 0),
    hstsDeploymentVerified: Boolean(document.getElementById('admin-network-hsts')?.checked),
  };
  setAdminNetworkStatus('Saving private network policy...', 'working');
  try {
    const data = await adminNetworkRequest(payload);
    adminNetworkPolicy = data.transportPolicy || adminNetworkPolicy;
    await loadAdminNetworkPolicy();
    setAdminNetworkStatus('HSTS readiness saved. Private proxy values remain outside browser surfaces.', 'ok');
    await loadAdminLogs();
  } catch (error) {
    setAdminNetworkStatus(error.message || 'Network policy could not be saved.', 'error');
  }
});

document.getElementById('admin-network-ban-form')?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!IS_INSTALLATION_OWNER || !adminSettingsUnlock?.requireUnlocked()) return;
  const durationValue = document.getElementById('admin-network-ban-duration')?.value || '';
  const confirmation = document.getElementById('admin-network-ban-confirmation');
  const status = document.getElementById('admin-network-ban-status');
  try {
    const data = await adminNetworkRequest({
      action: 'preview_manual_ban',
      contextId: document.getElementById('admin-network-context')?.value || '',
      reason: document.getElementById('admin-network-ban-reason')?.value || '',
      durationMinutes: durationValue === 'permanent' ? null : Number(durationValue),
      permanent: durationValue === 'permanent',
    });
    adminNetworkBanPreview = data.preview;
    document.getElementById('admin-network-ban-preview').textContent =
      `${data.preview.contextOwner}. ${Number(data.preview.affectedAccountCount)} affected account`
      + `${Number(data.preview.affectedAccountCount) === 1 ? '' : 's'}. `
      + `${data.preview.permanent ? 'Permanent restriction.' : `${Number(data.preview.durationMinutes)} minutes.`} `
      + data.preview.warning;
    const accounts = document.getElementById('admin-network-ban-accounts');
    accounts.replaceChildren();
    for (const account of data.preview.affectedAccounts || []) {
      const item = document.createElement('li');
      item.textContent = `${account.displayName}${account.username ? ` (@${account.username})` : ''} · ${account.role}`;
      accounts.appendChild(item);
    }
    confirmation.hidden = false;
    status.textContent = 'Review every affected account, the shared-network warning, duration, and reason before confirming.';
    document.getElementById('admin-network-ban-confirm')?.focus();
  } catch (error) {
    adminNetworkBanPreview = null;
    confirmation.hidden = true;
    status.textContent = error.message || 'The manual-ban impact preview could not be prepared.';
  }
});

document.getElementById('admin-network-ban-confirm')?.addEventListener('click', async () => {
  if (!adminNetworkBanPreview) return;
  if (!IS_INSTALLATION_OWNER || !adminSettingsUnlock?.requireUnlocked()) return;
  const button = document.getElementById('admin-network-ban-confirm');
  button.disabled = true;
  try {
    await adminNetworkRequest({
      action: 'apply_manual_ban',
      previewId: adminNetworkBanPreview.previewId,
      impactSha256: adminNetworkBanPreview.impactSha256,
      requestId: crypto.randomUUID(),
      confirmed: true,
    });
    adminNetworkBanPreview = null;
    document.getElementById('admin-network-ban-confirmation').hidden = true;
    document.getElementById('admin-network-ban-reason').value = '';
    document.getElementById('admin-network-ban-status').textContent =
      'Manual network ban applied. Matching active sessions were reconciled server-side.';
    await Promise.all([loadAdminNetworkPolicy(), loadAdminLogs()]);
  } catch (error) {
    document.getElementById('admin-network-ban-status').textContent =
      error.message || 'The manual ban was not applied.';
  } finally {
    button.disabled = false;
  }
});

document.getElementById('admin-network-ban-cancel')?.addEventListener('click', () => {
  adminNetworkBanPreview = null;
  document.getElementById('admin-network-ban-confirmation').hidden = true;
  document.getElementById('admin-network-ban-status').textContent =
    'Manual network ban canceled before mutation.';
});

async function uploadPrivateSiteBrandingAsset(entry, file) {
  if (!adminSettingsUnlock?.requireUnlocked()) return;
  try {
    const data = new FormData();
    data.append('_csrf', CSRF_TOKEN);
    data.append('expected_revision', String(adminSettingsRegistry?.revision || 0));
    data.append('community_logo', file, file.name);
    adminSettingsUnlock.announce('Uploading and validating the private community logo...', 'working');
    const response = await fetch(appUrl('/api/private_site_branding.php'), {
      method: 'POST',
      body: data,
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.error || result.ok === false) {
      throw new Error(result.error || 'The private community logo could not be saved.');
    }
    adminSettingsRegistry = result.registry || adminSettingsRegistry;
    adminSettingsRegistryUI?.setRegistry(adminSettingsRegistry);
    announceAdminSettingsRevision(adminSettingsRegistry.revision);
    renderLobbyAdminSettingsCompatibility();
    adminSettingsUnlock.announce(`${entry.label} updated with a validated private asset.`, 'ok');
  } catch (error) {
    adminSettingsUnlock.announce(error.message || 'The private community logo could not be saved.', 'error');
  }
}

async function adminLinkIconRequest(formData) {
  if (formData && !formData.has('_csrf')) formData.append('_csrf', CSRF_TOKEN);
  const resp = await fetch(appUrl('/api/admin_link_icons.php'), { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: formData });
  const data = await resp.json().catch(() => ({}));
  if (!resp.ok || data.error) throw new Error(data.error || 'Link icon request failed');
  return data;
}

async function loadAdminUsers() {
  if (!adminUsers) return;
  const data = await fetch(appUrl('/api/admin_users.php')).then(r => r.json());
  adminUsers.innerHTML = '';
  setAdminCount(adminCounts.users, (data.users || []).length);
  setAdminCount(adminCounts.summaryUsers, (data.users || []).length);
  adminInstallationOwner = data.installationOwner || null;
  if (adminOwnerTransferForm) {
    const current = document.getElementById('admin-owner-current');
    const revision = document.getElementById('admin-owner-revision');
    if (current) {
      current.textContent = adminInstallationOwner
        ? `Current Installation Owner: ${adminInstallationOwner.displayName} (@${adminInstallationOwner.username}).`
        : 'Installation Owner details are unavailable to this account.';
    }
    if (revision) {
      revision.textContent = adminInstallationOwner
        ? `Ownership revision: ${Number(adminInstallationOwner.revision || 1)}.`
        : 'Ownership revision is unavailable to this account.';
    }
    const select = adminOwnerTransferForm.elements.new_owner_id;
    const candidates = (data.users || []).filter(user =>
      user.role === 'admin' && Number(user.id) !== Number(adminInstallationOwner?.userId || 0)
    );
    select.innerHTML = candidates.length
      ? `<option value="">Choose an Administrator</option>${candidates.map(user =>
          `<option value="${Number(user.id)}">${esc(user.display_name)} (@${esc(user.username)})</option>`
        ).join('')}`
      : '<option value="">No other Administrator is available</option>';
    select.disabled = !candidates.length;
  }
  (data.users || []).forEach(user => {
    const row = document.createElement('form');
    row.className = 'admin-user-row';
    row.dataset.accountSearch = [
      user.display_name,
      user.username,
      user.email,
      user.role,
      user.trust_state,
    ].filter(Boolean).join(' ').toLocaleLowerCase();
    row.innerHTML = `<div><strong>${esc(user.display_name)}</strong><div class="minor">@${esc(user.username || '')} | ${esc(user.email)}</div><div class="minor">Trust: ${esc(user.trust_state || 'pending-approval')}</div><div class="admin-created-meta"><span>Created On</span><strong>${adminCreatedOn(user.created_at)}</strong></div></div>
      <select name="role">
        <option value="user">User</option>
        <option value="moderator">Moderator</option>
        <option value="guide">Guide</option>
        <option value="developer">Developer</option>
        <option value="admin">Admin</option>
      </select>
      <input name="password" type="password" placeholder="New password">
      <div class="admin-user-actions">
        <button class="btn btn-primary" type="submit">Save</button>
        <button class="btn" type="button" disabled title="Account deletion is not available here. Use Actions for suspension or session revocation.">Delete unavailable</button>
      </div>
      <div class="admin-row-status" aria-live="polite"></div>`;
    row.querySelector('select').value = user.role || 'user';
    row.querySelector('select').addEventListener('change', () => {
      row.dataset.accountSearch = [
        user.display_name,
        user.username,
        user.email,
        row.elements.role.value,
        user.trust_state,
      ].filter(Boolean).join(' ').toLocaleLowerCase();
      applyAdminAccountSearch();
    });
    row.addEventListener('submit', async e => {
      e.preventDefault();
      const saveBtn = row.querySelector('button[type="submit"]');
      saveBtn.disabled = true;
      setAdminFormStatus(row, 'Saving...', 'working');
      try {
        await adminRequest({ action: 'update', id: user.id, role: row.elements.role.value, password: row.elements.password.value });
        row.elements.password.value = '';
        setAdminFormStatus(row, 'Saved.', 'ok');
        await loadAdminLogs();
      } catch (err) {
        setAdminFormStatus(row, err.message || 'Save failed.', 'error');
      } finally {
        saveBtn.disabled = false;
      }
    });
    adminUsers.appendChild(row);
  });
  applyAdminAccountSearch();
  if (adminModerationCases) {
    adminModerationCases.innerHTML = '';
    (data.moderationCases || []).forEach(item => {
      const row = document.createElement('form');
      row.className = 'admin-user-row admin-moderation-case-row';
      const plainLabel = value => String(value || '').split('-').filter(Boolean)
        .map(word => word.charAt(0).toLocaleUpperCase() + word.slice(1)).join(' ');
      const caseLabel = plainLabel(item.case_type);
      const statusLabel = plainLabel(item.status);
      const itemControls = (item.items || []).map(part => {
        const itemLabel = plainLabel(part.item_key);
        return `<div class="admin-case-item">
          <label class="admin-case-item-choice">
            <input type="checkbox" name="case_item" value="${esc(part.item_key)}">
            <span>${esc(itemLabel)} <span class="minor">(${esc(plainLabel(part.status))})</span></span>
          </label>
          <label class="admin-create-field">
            <span>Decision for ${esc(itemLabel)}</span>
            <select data-case-item="${esc(part.item_key)}">
              <option value="">No decision</option>
              <option value="approved">Approve selected</option>
              <option value="denied">Deny selected</option>
              <option value="modified">Modify selected</option>
              <option value="closed">Close selected</option>
            </select>
          </label>
          <details class="admin-technical-details">
            <summary>Technical details</summary>
            <div>Capability ID: ${esc(part.item_key)}</div>
          </details>
        </div>`;
      }).join('');
      row.innerHTML = `<div class="admin-case-summary"><strong>${esc(caseLabel)} — ${esc(item.display_name || item.username)}</strong>
          <div class="minor">Status: ${esc(statusLabel)}</div>
          <details class="admin-technical-details">
            <summary>Technical details</summary>
            <div>Reference: ${esc(item.public_id)}</div>
            <div>Revision: ${Number(item.revision || 1)}</div>
          </details>
        </div>
        ${itemControls ? `<div class="admin-case-items">${itemControls}</div>` : ''}
        <label class="admin-create-field">
          <span>Case status</span>
          <select name="status">
            <option value="under-review">Under Review</option>
            <option value="approved">Approved</option>
            <option value="denied">Denied</option>
            <option value="modified">Modified</option>
            <option value="closed">Closed</option>
          </select>
        </label>
        <label class="admin-create-field">
          <span>Reason shown to the member</span>
          <input name="public_reason" maxlength="500">
        </label>
        <label class="admin-create-field admin-case-private-note">
          <span>Private internal note</span>
          <textarea name="internal_note" maxlength="2000"></textarea>
        </label>
        <div class="admin-create-actions"><button class="btn btn-primary" type="submit">Record Decision</button></div>
        <div class="admin-row-status" aria-live="polite"></div>`;
      row.addEventListener('submit', async event => {
        event.preventDefault();
        const decisions = {};
        row.querySelectorAll('[data-case-item]').forEach(select => {
          const selected = row.querySelector(`input[name="case_item"][value="${CSS.escape(select.dataset.caseItem)}"]`)?.checked;
          if (selected && select.value) decisions[select.dataset.caseItem] = select.value;
        });
        try {
          setAdminFormStatus(row, 'Saving decision...', 'working');
          await adminRequest({
            action: 'decide_moderation_case',
            case_public_id: item.public_id,
            expected_revision: Number(item.revision || 1),
            request_id: globalThis.crypto?.randomUUID?.() || `case-${Date.now()}-${Math.random().toString(16).slice(2)}`,
            status: row.elements.status.value,
            public_reason: row.elements.public_reason.value,
            internal_note: row.elements.internal_note.value,
            item_decisions: decisions,
          });
          await loadAdminUsers();
          await loadAdminLogs();
        } catch (error) {
          setAdminFormStatus(row, error.message || 'Decision failed.', 'error');
        }
      });
      adminModerationCases.appendChild(row);
    });
    if (!(data.moderationCases || []).length) {
      adminModerationCases.innerHTML = '<p class="minor">No active Trusted Review, capability request, or appeal cases.</p>';
    }
  }
}

function applyAdminAccountSearch() {
  if (!adminUsers) return;
  const query = String(adminAccountSearch?.value || '').trim().toLocaleLowerCase();
  const rows = Array.from(adminUsers.querySelectorAll('.admin-user-row'));
  let visible = 0;
  rows.forEach(row => {
    const matches = query === '' || String(row.dataset.accountSearch || '').includes(query);
    row.hidden = !matches;
    if (matches) visible += 1;
  });
  if (adminAccountSearchStatus) {
    adminAccountSearchStatus.textContent = query
      ? (visible === 0
          ? 'No accounts match this search.'
          : `Showing ${visible} of ${rows.length} accounts.`)
      : `${rows.length} accounts.`;
  }
}

adminAccountSearch?.addEventListener('input', applyAdminAccountSearch);

adminOwnerTransferForm?.addEventListener('submit', event => {
  event.preventDefault();
  const status = document.getElementById('admin-owner-transfer-status');
  const confirmation = document.getElementById('admin-owner-transfer-confirmation');
  const target = adminOwnerTransferForm.elements.new_owner_id.selectedOptions[0];
  const newOwnerId = Number(adminOwnerTransferForm.elements.new_owner_id.value || 0);
  const reason = String(adminOwnerTransferForm.elements.reason.value || '').trim();
  if (!adminInstallationOwner || !newOwnerId || reason.length < 3) {
    status.textContent = 'Choose another Administrator and enter a reviewable reason.';
    confirmation.hidden = true;
    adminOwnerTransferConfirmation = null;
    return;
  }
  adminOwnerTransferConfirmation = {
    newOwnerId,
    expectedRevision: Number(adminInstallationOwner.revision || 0),
    reason,
  };
  document.getElementById('admin-owner-transfer-preview').textContent =
    `Transfer Installation Owner authority from ${adminInstallationOwner.displayName} (@${adminInstallationOwner.username}) to ${target.textContent}. The transfer is atomic and both accounts remain Administrators.`;
  confirmation.hidden = false;
  status.textContent = 'Review the exact ownership transfer before confirming.';
  document.getElementById('admin-owner-transfer-confirm')?.focus();
});

document.getElementById('admin-owner-transfer-confirm')?.addEventListener('click', async () => {
  if (!adminOwnerTransferConfirmation) return;
  const status = document.getElementById('admin-owner-transfer-status');
  try {
    status.textContent = 'Transferring Installation Owner authority atomically...';
    await adminRequest({
      action: 'transfer_installation_owner',
      request_id: globalThis.crypto?.randomUUID?.() || `owner-transfer-${Date.now()}-${Math.random().toString(16).slice(2)}`,
      new_owner_id: adminOwnerTransferConfirmation.newOwnerId,
      expected_revision: adminOwnerTransferConfirmation.expectedRevision,
      reason: adminOwnerTransferConfirmation.reason,
      confirmed: true,
    });
    adminOwnerTransferConfirmation = null;
    document.getElementById('admin-owner-transfer-confirmation').hidden = true;
    status.textContent = 'Installation Owner authority transferred. This account no longer has owner-only access.';
    await Promise.all([loadAdminUsers(), loadAdminLogs()]);
  } catch (error) {
    status.textContent = error.message || 'Ownership transfer failed safely.';
  }
});

document.getElementById('admin-owner-transfer-cancel')?.addEventListener('click', () => {
  adminOwnerTransferConfirmation = null;
  document.getElementById('admin-owner-transfer-confirmation').hidden = true;
  document.getElementById('admin-owner-transfer-status').textContent =
    'Ownership transfer canceled before mutation.';
});

async function loadAdminModerationUsers() {
  if (!adminModerationUsers || !adminModerationSearch) return;
  const params = new URLSearchParams({
    view: 'users',
    search: adminModerationSearch.elements.search.value,
    sort: adminModerationSearch.elements.sort.value,
    page: String(adminModerationPage),
    per_page: adminModerationSearch.elements.per_page.value,
  });
  const response = await fetch(appUrl(`/api/moderation.php?${params}`));
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.error) {
    adminModerationUsers.innerHTML = `<p class="minor">${esc(data.error || 'Moderation users are unavailable.')}</p>`;
    return;
  }
  document.getElementById('admin-moderation-page').textContent = `Page ${data.page} of ${Math.max(1, Math.ceil(data.total / data.perPage))}`;
  document.getElementById('admin-moderation-prev').disabled = data.page <= 1;
  document.getElementById('admin-moderation-next').disabled = data.page * data.perPage >= data.total;
  adminModerationUsers.innerHTML = '';
  (data.users || []).forEach(target => {
    const row = document.createElement('form');
    row.className = 'admin-user-row admin-moderation-user-row';
    row.innerHTML = `<div><strong>${esc(target.display_name || target.username)}</strong><small>@${esc(target.username)}; ${target.online ? 'Online' : 'Offline'}; ${esc(target.trust_state)}</small></div>
      <label class="admin-moderation-field"><span>Action</span><select name="action_type"><option value="warn">Warn</option><option value="temporarily-restrict">Temporarily Restrict</option><option value="suspend-account">Suspend Account</option><option value="undo-eligible-restriction">Undo Eligible Restriction</option></select></label>
      <label class="admin-moderation-field"><span>Duration</span><select name="duration"><option value="60">1 hour</option><option value="1440">24 hours</option><option value="10080">7 days</option><option value="43200">30 days</option><option value="indefinite">Indefinite suspension</option></select></label>
      <label class="admin-moderation-field admin-moderation-field-wide"><span>Public reason</span><input name="public_reason" maxlength="500" placeholder="Required public reason"></label>
      <label class="admin-moderation-field admin-moderation-field-wide"><span>Private note</span><textarea name="internal_note" maxlength="2000" placeholder="Optional private internal note"></textarea></label>
      <button class="btn btn-primary" type="submit">Open Moderation Action</button>
      <div class="admin-row-status" aria-live="polite"></div>`;
    row.addEventListener('submit', async event => {
      event.preventDefault();
      try {
        setAdminFormStatus(row, 'Applying moderation action...', 'working');
        await lobbyApiPost('/api/moderation.php', {
          action: 'moderate',
          action_type: row.elements.action_type.value,
          target_user_id: Number(target.id),
          expected_revision: Number(target.trust_revision || 1),
          duration: row.elements.duration.value,
          public_reason: row.elements.public_reason.value,
          internal_note: row.elements.internal_note.value,
          request_id: globalThis.crypto?.randomUUID?.() || `moderate-${Date.now()}-${Math.random().toString(16).slice(2)}`,
        });
        setAdminFormStatus(row, 'Action applied.', 'ok');
        await loadAdminModerationUsers();
        await loadAdminLogs();
      } catch (error) {
        setAdminFormStatus(row, error.message || 'Moderation action failed.', 'error');
      }
    });
    adminModerationUsers.appendChild(row);
  });
}

adminModerationSearch?.addEventListener('submit', event => {
  event.preventDefault();
  adminModerationPage = 1;
  loadAdminModerationUsers();
});
document.getElementById('admin-moderation-prev')?.addEventListener('click', () => {
  adminModerationPage = Math.max(1, adminModerationPage - 1);
  loadAdminModerationUsers();
});
document.getElementById('admin-moderation-next')?.addEventListener('click', () => {
  adminModerationPage += 1;
  loadAdminModerationUsers();
});

async function loadAdminSettings() {
  if (!adminSettings) return;
  const data = await fetch(appUrl('/api/admin_system.php?action=settings')).then(r => r.json());
  if (data.error) throw new Error(data.error);
  adminSettingsRegistry = data.settingsRegistry;
  clearLobbyAdminProfileLimitConfirmation();
  if (!adminSettingsUnlock) {
    adminSettingsUnlock = new window.SettingsUnlockController({
      mount: document.getElementById('lobby-admin-settings-unlock'),
      activityRoot: document.getElementById('admin-section-settings'),
      authorized: CAN_ADMIN_SETTINGS_MUTATE,
      onLockChange: locked => {
        adminSettingsRegistryUI?.setLocked(locked);
        syncLobbyAdminSettingsMutationLocks();
      },
    });
  }
  if (!adminSettingsRegistryUI) {
    adminSettingsRegistryUI = new window.SettingsRegistryUI({
      container: document.getElementById('lobby-admin-settings-registry'),
      searchInput: document.getElementById('lobby-admin-settings-search'),
      filterInput: document.getElementById('lobby-admin-settings-filter'),
      categoryNavigation: true,
      navigationLabel: 'Settings section',
      sessionKey: `chatspace.admin-settings.section:${document.body.dataset.appBase || '/'}`,
      onViewChange: view => {
        const destination = document.getElementById('admin-settings-retention-destination');
        if (destination) destination.hidden = view !== 'moderation-privacy-security';
      },
      readOnly: !CAN_ADMIN_SETTINGS_MUTATE,
      locked: !adminSettingsUnlock.isUnlocked(),
      onOperation: handleLobbyAdminSettingsOperation,
      onAssetChange: uploadPrivateSiteBrandingAsset,
      onDraftChange: state => {
        clearLobbyAdminProfileLimitConfirmation();
        const summary = document.getElementById('lobby-admin-settings-dirty-summary');
        const save = document.getElementById('lobby-admin-settings-save');
        if (summary) {
          summary.textContent = state.invalidCount
            ? `${state.changedCount} unsaved change${state.changedCount === 1 ? '' : 's'}; ${state.invalidCount} setting field${state.invalidCount === 1 ? ' needs' : 's need'} attention`
            : (state.changedCount ? `${state.changedCount} unsaved change${state.changedCount === 1 ? '' : 's'}` : 'No unsaved changes');
        }
        if (save) save.disabled = !adminSettingsUnlock?.isUnlocked() || state.changedCount === 0 || !state.valid;
        renderLobbyAdminSettingsCompatibility();
      },
      onEntryChange: (entry, value) => {
        const rendered = entry.type === 'boolean' ? (value ? 'enabled' : 'disabled') : `changed to ${value}`;
        adminSettingsUnlock?.announce(`${entry.label} ${rendered}; save to apply.`, 'ok');
      },
    });
  }
  adminSettingsRegistryUI.setRegistry(adminSettingsRegistry);
  adminSettingsRegistryUI.setLocked(!adminSettingsUnlock.isUnlocked());
  syncLobbyAdminSettingsMutationLocks();
  renderLobbyAdminSettingsCompatibility();
  renderAdminGestureFeatureSummary();
  if (document.getElementById('admin-section-gestures')?.classList.contains('active')) {
    loadAdminGestures().catch(error => setAdminGestureStatus(error.message || 'Gesture catalog could not be loaded.', 'error'));
  }
}

function adminGestureFeatureEntries() {
  return (adminSettingsRegistry?.entries || []).filter(entry => (
    /^gesture_part[34]_/.test(String(entry.id || ''))
    || String(entry.bulkGroup || '') === 'gesture-capability'
  ));
}

function adminGestureCatalogEnabled() {
  return Boolean(adminGestureFeatureEntries().find(entry => entry.id === 'gesture_part3_admin_catalog')?.currentValue);
}

function renderAdminGestureFeatureSummary() {
  const target = document.getElementById('admin-gesture-feature-summary');
  const entries = adminGestureFeatureEntries();
  if (target) {
    const part3 = entries.filter(entry => String(entry.id).startsWith('gesture_part3_'));
    const part4 = entries.filter(entry => String(entry.id).startsWith('gesture_part4_'));
    const capabilities = entries.filter(entry => String(entry.bulkGroup || '') === 'gesture-capability');
    const enabled3 = Number(adminSettingsRegistry?.summaries?.gesturePart3EnabledCount ?? part3.filter(entry => entry.currentValue === true).length);
    const total3 = Number(adminSettingsRegistry?.summaries?.gesturePart3TotalCount ?? part3.length);
    const enabled4 = Number(adminSettingsRegistry?.summaries?.gesturePart4EnabledCount ?? part4.filter(entry => entry.currentValue === true).length);
    const total4 = Number(adminSettingsRegistry?.summaries?.gesturePart4TotalCount ?? part4.length);
    const enabledCapabilities = Number(adminSettingsRegistry?.summaries?.gestureCapabilityEffectiveCount ?? capabilities.filter(entry => entry.effectiveValue === true).length);
    const totalCapabilities = Number(adminSettingsRegistry?.summaries?.gestureCapabilityTotalCount ?? capabilities.length);
    target.replaceChildren();
    if (entries.length) {
      const rows = [
        `Gesture availability: ${enabledCapabilities} of ${totalCapabilities} features enabled`,
        `Browsing and organization: ${enabled3} of ${total3} features enabled`,
        `Creation, packages, and media: ${enabled4} of ${total4} features enabled`,
      ];
      for (const text of rows) {
        const row = document.createElement('div');
        row.className = 'admin-gesture-feature-row';
        row.textContent = text;
        target.appendChild(row);
      }
    } else {
      target.textContent = 'Gesture settings are unavailable.';
    }
  }
  const enabled = adminGestureCatalogEnabled();
  if (adminGestureSearch) adminGestureSearch.disabled = !enabled;
  if (adminGestureSort) adminGestureSort.disabled = !enabled;
  if (!enabled && adminGestureCatalog) {
    if (hasDirtyAdminGestures()) {
      setAdminGestureStatus('The Admin gesture catalog was disabled elsewhere. Unsaved row drafts remain visible until you confirm a refresh or leave this section.', 'error');
      return;
    }
    adminGestureCatalog.replaceChildren();
    adminGesturePager?.replaceChildren();
    setAdminCount(document.getElementById('admin-gesture-count'), 0);
    setAdminGestureStatus('The Admin gesture catalog is disabled through shared Settings.', 'ok');
  }
}

function setAdminGestureStatus(message, type = '') {
  if (!adminGestureStatus) return;
  adminGestureStatus.replaceChildren();
  adminGestureStatus.className = `minor ${type}`.trim();
  if (type !== 'error') {
    adminGestureStatus.textContent = message || '';
    return;
  }
  const copy = document.createElement('span');
  copy.textContent = 'Server Gestures could not be loaded. Check the connection and try again.';
  const retry = document.createElement('button');
  retry.type = 'button';
  retry.className = 'btn';
  retry.textContent = 'Retry';
  retry.addEventListener('click', () => {
    loadAdminGestures().catch(error => setAdminGestureStatus(error.message || 'Gesture catalog request failed.', 'error'));
  });
  const technical = document.createElement('details');
  const summary = document.createElement('summary');
  summary.textContent = 'Technical details';
  const reason = document.createElement('p');
  reason.textContent = String(message || 'The request did not complete.');
  technical.append(summary, reason);
  adminGestureStatus.append(copy, retry, technical);
}

function healthElement(tag, className = '', text = '') {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== '') node.textContent = text;
  return node;
}

function setSystemHealthStatus(message, type = '') {
  const status = document.getElementById('system-health-summary');
  if (!status) return;
  status.textContent = message || '';
  status.className = `admin-panel system-health-banner ${type}`.trim();
}

function formatHealthBytes(value) {
  const bytes = Number(value);
  if (!Number.isFinite(bytes) || bytes < 0) return 'Unavailable';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1048576) return `${Math.round(bytes / 1024)} KiB`;
  if (bytes < 1073741824) return `${(bytes / 1048576).toFixed(1)} MiB`;
  return `${(bytes / 1073741824).toFixed(1)} GiB`;
}

function renderHealthMetric(label, value, detail = '', state = '') {
  const card = healthElement('article', `system-health-card ${state}`.trim());
  card.append(healthElement('span', 'system-health-card-label', label), healthElement('strong', '', String(value)));
  if (detail) card.appendChild(healthElement('small', '', detail));
  return card;
}

function renderAdminSystemHealth() {
  const health = adminSystemHealth;
  if (!health) return;
  for (const id of [
    'capacity-profile-review',
    'capacity-profile-apply',
    'diagnostic-policy-mode',
    'diagnostic-policy-review',
    'diagnostic-policy-apply',
    'diagnostic-cleanup-preview',
    'diagnostic-cleanup-run',
  ]) {
    const control = document.getElementById(id);
    if (control && !CAN_ADMIN_SETTINGS_MUTATE) control.disabled = true;
  }
  const capacity = health.capacity || {};
  const warnings = Array.isArray(capacity.warnings) ? capacity.warnings : [];
  setAdminCount(adminCounts.healthWarnings, warnings.length);
  adminCounts.healthWarnings?.setAttribute('aria-label', `${warnings.length} health warning${warnings.length === 1 ? '' : 's'}`);
  setSystemHealthStatus(
    warnings.length
      ? `${warnings.length} measured capacity warning${warnings.length === 1 ? '' : 's'}; mandatory safety remains enforced.`
      : 'No measured capacity target is currently exceeded. Mandatory safety remains enforced.',
    warnings.length ? 'warning' : 'ok'
  );

  const metrics = document.getElementById('system-health-metrics');
  if (metrics) {
    metrics.textContent = '';
    metrics.append(
      renderHealthMetric('Active rooms', health.activity?.activeRooms ?? 0, `Target ${capacity.values?.capacity_active_rooms_target ?? '—'}`, capacity.utilization?.activeRooms?.warning ? 'warning' : ''),
      renderHealthMetric('Active users', health.activity?.activeUsers ?? 0, `Target ${capacity.values?.capacity_active_users_target ?? '—'}`, capacity.utilization?.activeUsers?.warning ? 'warning' : ''),
      renderHealthMetric('Active participants', health.activity?.activeParticipants ?? 0, `Target ${capacity.values?.capacity_active_participants_target ?? '—'}`, capacity.utilization?.activeParticipants?.warning ? 'warning' : ''),
      renderHealthMetric('Database', formatHealthBytes(health.storage?.database?.bytes), health.build?.migration?.engine || 'Unknown engine'),
      renderHealthMetric(
        'Diagnostic issues',
        health.diagnostics?.issueCounts?.total ?? 0,
        `${health.diagnostics?.issueCounts?.unresolved ?? 0} unresolved · ${health.diagnostics?.issueCounts?.recurrences ?? 0} recurrences · ${health.diagnostics?.issueCounts?.regressed ?? 0} regressed · ${health.diagnostics?.issueCounts?.held ?? 0} held`
      ),
      renderHealthMetric(
        'Transport',
        health.operationalSignals?.activeTransport || 'polling',
        `${health.operationalSignals?.configuredTransportModeLabel || 'Polling only — Default'} · Polling remains the permanent fallback`
      )
    );
  }

  const currentProfile = document.getElementById('capacity-profile-current');
  if (currentProfile) currentProfile.textContent = `${capacity.selectedProfileLabel || 'Custom'} · revision ${capacity.revision || 1}`;
  const values = document.getElementById('capacity-values');
  if (values) {
    values.textContent = '';
    for (const [id, definition] of Object.entries(capacity.definitions || {})) {
      const card = healthElement('article', 'capacity-value-card');
      card.append(
        healthElement('strong', '', definition.label || id),
        healthElement('span', '', `${capacity.values?.[id] ?? '—'} ${definition.unit || ''}`.trim()),
        healthElement('small', '', `Measured ${capacity.profiles?.[0]?.values?.[id] ?? '—'} · hard bound ${definition.minimum}–${definition.maximum}`)
      );
      values.appendChild(card);
    }
  }

  const capabilityRoot = document.getElementById('system-health-capabilities');
  if (capabilityRoot) {
    capabilityRoot.textContent = '';
    const capabilities = health.hostCapabilities || {};
    const facts = [
      ['Runtime', `PHP ${capabilities.runtime?.phpVersion || 'unknown'}`],
      ['Database', `${capabilities.runtime?.database?.engine || 'Unknown'} ${capabilities.runtime?.database?.version || ''}`.trim()],
      ['HTTPS', capabilities.transportSecurity?.httpsActive ? 'Active' : 'Unavailable'],
      ['Trusted proxy ownership', capabilities.transportSecurity?.forwardedHeadersSafelyOwned ? 'Safe' : 'Unproven'],
      ['SSE', capabilities.streaming?.sseEligibilityLabel || 'Unknown'],
      ['WebSocket/WSS', capabilities.persistentProcess?.wssEligible ? 'Eligible' : 'Unsupported'],
      ['Configured transport', health.operationalSignals?.configuredTransportModeLabel || 'Polling only — Default'],
      ['Selected transport', health.operationalSignals?.selectedTransport || 'polling'],
      ['Disk', capabilities.storage?.disk?.bucket || 'Unknown'],
      ['Fallback', capabilities.transport?.fallbackReason || 'Polling'],
    ];
    for (const [label, value] of facts) {
      const row = healthElement('div', 'system-health-capability');
      row.append(healthElement('strong', '', label), healthElement('span', '', value));
      capabilityRoot.appendChild(row);
    }
  }

  renderDiagnosticPolicy(health.diagnostics?.policy || {}, health.maintenance?.runtimeDiagnostics || {});
}

function renderDiagnosticPolicy(policy, retention) {
  const select = document.getElementById('diagnostic-policy-mode');
  if (select) select.value = policy.effectiveMode || 'errors-only';
  const summary = document.getElementById('diagnostic-retention-summary');
  if (summary) {
    const counts = retention.counts || {};
    summary.textContent = `${counts.routine || 0} routine · ${counts.security || 0} security-relevant · ${counts.holds || 0} holds · ${counts.expired || 0} eligible for bounded cleanup`;
  }
  window.clearInterval(diagnosticPolicyTimer);
  diagnosticPolicyTimer = null;
  const countdown = document.getElementById('diagnostic-verbose-countdown');
  if (!countdown) return;
  if (!policy.verboseActive || !policy.verboseUntil) {
    countdown.hidden = true;
    countdown.textContent = '';
    return;
  }
  const render = () => {
    const remaining = Math.max(0, Math.floor((Date.parse(policy.verboseUntil) - Date.now()) / 1000));
    countdown.hidden = false;
    countdown.textContent = `Verbose ${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')} remaining`;
    if (remaining <= 0) {
      window.clearInterval(diagnosticPolicyTimer);
      diagnosticPolicyTimer = null;
      loadAdminSystemHealth().catch(() => {});
    }
  };
  render();
  diagnosticPolicyTimer = window.setInterval(render, 1000);
}

async function loadAdminSystemHealth() {
  if (!document.getElementById('admin-section-system-health')) return;
  const response = await fetch(appUrl('/api/admin_system.php?action=system_health'));
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.error) throw new Error(data.error || 'System Health could not be loaded.');
  adminSystemHealth = data.systemHealth;
  renderAdminSystemHealth();
}

document.getElementById('capacity-profile-review')?.addEventListener('click', async () => {
  const status = document.getElementById('capacity-profile-status');
  try {
    const profile = document.getElementById('capacity-profile-select')?.value || '';
    const response = await fetch(appUrl(`/api/admin_system.php?action=capacity_profile_preview&profile=${encodeURIComponent(profile)}`));
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.error) throw new Error(data.error || 'Capacity impact could not be reviewed.');
    capacityProfileImpact = data.impactPreview;
    const panel = document.getElementById('capacity-profile-impact');
    const title = document.getElementById('capacity-profile-impact-title');
    const list = document.getElementById('capacity-profile-impact-list');
    if (title) title.textContent = `${capacityProfileImpact.profileLabel} exact impact`;
    if (list) {
      list.textContent = '';
      if (!capacityProfileImpact.changes?.length) list.appendChild(healthElement('li', '', 'All values already match this measured profile.'));
      for (const change of capacityProfileImpact.changes || []) {
        list.appendChild(healthElement('li', '', `${change.label}: ${change.currentValue} → ${change.proposedValue}. ${change.impact}`));
      }
    }
    if (panel) panel.hidden = false;
    if (status) status.textContent = 'Exact impact ready for deliberate review.';
  } catch (error) {
    if (status) status.textContent = error.message || 'Capacity impact could not be reviewed.';
  }
});

document.getElementById('capacity-profile-cancel')?.addEventListener('click', () => {
  capacityProfileImpact = null;
  const panel = document.getElementById('capacity-profile-impact');
  if (panel) panel.hidden = true;
});

document.getElementById('capacity-profile-apply')?.addEventListener('click', async () => {
  const status = document.getElementById('capacity-profile-status');
  if (!capacityProfileImpact) return;
  try {
    const result = await adminSystemRequest({
      action: 'apply_capacity_profile',
      profile: capacityProfileImpact.profileId,
      expected_revision: capacityProfileImpact.expectedRevision,
      request_id: settingsRegistryRequestId(),
      confirmed: 1,
    });
    capacityProfileImpact = null;
    document.getElementById('capacity-profile-impact').hidden = true;
    if (status) status.textContent = result.idempotent ? 'Profile was already applied.' : 'Measured capacity profile applied.';
    await Promise.all([loadAdminSystemHealth(), loadAdminSettings(), loadAdminLogs()]);
  } catch (error) {
    if (status) status.textContent = error.message || 'Capacity profile could not be applied.';
  }
});

document.getElementById('diagnostic-policy-review')?.addEventListener('click', () => {
  const selected = document.getElementById('diagnostic-policy-mode')?.value || 'errors-only';
  const copy = document.getElementById('diagnostic-policy-impact-copy');
  if (copy) {
    copy.textContent = selected === 'verbose'
      ? 'Verbose diagnostics lasts exactly 60 minutes and then returns to the immediately preceding finite mode. Repeated requests do not extend the lease.'
      : selected === 'off'
        ? 'Off stops new optional client collection. It does not delete retained evidence or disable security, Tool Logs, local Developer Tools, or required automated checks.'
        : `Apply ${selected.replaceAll('-', ' ')} with bounded sampling, redaction, secure storage, and retention.`;
  }
  const panel = document.getElementById('diagnostic-policy-impact');
  if (panel) panel.hidden = false;
});

document.getElementById('diagnostic-policy-cancel')?.addEventListener('click', () => {
  const panel = document.getElementById('diagnostic-policy-impact');
  if (panel) panel.hidden = true;
});

document.getElementById('diagnostic-policy-apply')?.addEventListener('click', async () => {
  const status = document.getElementById('diagnostic-policy-status');
  try {
    const result = await adminSystemRequest({
      action: 'update_diagnostic_policy',
      mode: document.getElementById('diagnostic-policy-mode')?.value || 'errors-only',
      expected_revision: adminSystemHealth?.diagnostics?.policy?.revision,
      request_id: settingsRegistryRequestId(),
      confirmed: 1,
    });
    document.getElementById('diagnostic-policy-impact').hidden = true;
    if (status) status.textContent = result.idempotent ? 'Diagnostic mode was already effective.' : 'Diagnostic collection mode updated.';
    await Promise.all([loadAdminSystemHealth(), loadAdminSettings(), loadAdminLogs()]);
  } catch (error) {
    if (status) status.textContent = error.message || 'Diagnostic policy could not be updated.';
  }
});

document.getElementById('diagnostic-cleanup-preview')?.addEventListener('click', async () => {
  const status = document.getElementById('diagnostic-policy-status');
  try {
    const response = await fetch(appUrl('/api/runtime_issues.php?action=retention'));
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.error) throw new Error(data.error || 'Retention impact could not be reviewed.');
    const preview = data.impactPreview || {};
    if (status) status.textContent = `${preview.eligibleIssueCount || 0} issue records are eligible; the next bounded batch is at most ${preview.nextBatchMaximum || 0}. Holds and investigations remain preserved.`;
    const run = document.getElementById('diagnostic-cleanup-run');
    if (run) run.hidden = false;
  } catch (error) {
    if (status) status.textContent = error.message || 'Retention impact could not be reviewed.';
  }
});

document.getElementById('diagnostic-cleanup-run')?.addEventListener('click', async () => {
  const status = document.getElementById('diagnostic-policy-status');
  try {
    const result = await adminIssuePost({ action: 'cleanup', confirmed: 1 });
    if (status) status.textContent = `Bounded cleanup passed: scanned ${result.scanned || 0}, cleaned ${result.deleted || 0}.`;
    document.getElementById('diagnostic-cleanup-run').hidden = true;
    await Promise.all([loadAdminSystemHealth(), loadAdminIssues(), loadAdminLogs()]);
  } catch (error) {
    if (status) status.textContent = error.message || 'Bounded cleanup failed.';
  }
});

function hasDirtyAdminGestures() {
  return adminGestureState.dirtyRows.size > 0;
}

function confirmAdminGestureDiscard(reason) {
  if (!hasDirtyAdminGestures()) return true;
  const count = adminGestureState.dirtyRows.size;
  return window.confirm(
    `${count} Server Gesture row${count === 1 ? ' has' : 's have'} unsaved changes. `
    + `${reason} will discard them. Continue?`
  );
}

function clearAdminGestureDirtyState() {
  adminGestureState.dirtyRows.clear();
}

function setAdminGestureRowResult(row, message, type = '') {
  const target = row?.querySelector?.('.admin-gesture-row-status');
  if (!target) return;
  target.textContent = message || '';
  target.className = `admin-gesture-row-status ${type}`.trim();
}

function refreshAdminGestureRowDirtyState(row) {
  if (!row) return false;
  const dirty = [...row.querySelectorAll('[data-gesture-field]')]
    .some(control => control.value !== control.defaultValue);
  const publicId = String(row.dataset.gesturePublicId || '');
  if (dirty) adminGestureState.dirtyRows.add(publicId);
  else adminGestureState.dirtyRows.delete(publicId);
  row.classList.toggle('dirty', dirty);
  row.querySelector('[data-admin-gesture-save]')?.toggleAttribute('disabled', !dirty);
  if (dirty) {
    if (!['conflict', 'error', 'saving'].includes(row.dataset.resultState || '')) {
      setAdminGestureRowResult(row, 'Unsaved changes.', 'dirty');
    }
  } else if (row.dataset.resultState !== 'success') {
    setAdminGestureRowResult(row, 'No unsaved changes.');
  }
  return dirty;
}

function adminGestureCell(role, text, className = '', label = '') {
  const cell = document.createElement('div');
  cell.setAttribute('role', role);
  if (className) cell.className = className;
  if (label) cell.dataset.label = label;
  cell.textContent = String(text ?? '');
  return cell;
}

function adminGestureEditorCell(item, field, label, multiline = false) {
  const cell = adminGestureCell('cell', '', 'admin-gesture-editor-cell', label);
  const control = document.createElement(multiline ? 'textarea' : 'input');
  if (!multiline) control.type = 'text';
  control.maxLength = field === 'text' ? 180 : 120;
  control.value = String(item[field] ?? '');
  control.defaultValue = control.value;
  control.dataset.gestureField = field;
  control.setAttribute('aria-label', `${label} for ${item.catalog_filename}`);
  control.addEventListener('input', () => {
    const row = control.closest('.admin-gesture-row');
    if (row) {
      row.dataset.resultState = '';
      refreshAdminGestureRowDirtyState(row);
    }
  });
  control.addEventListener('invalid', () => {
    setAdminGestureRowResult(
      control.closest('.admin-gesture-row'),
      `${label} is invalid; review the highlighted field.`,
      'error'
    );
  });
  cell.appendChild(control);
  return cell;
}

function adminGesturePackageCell(item) {
  const media = Object.keys(item.package?.media || {});
  const cell = adminGestureCell('cell', '', 'admin-gesture-package', 'Package');
  const packageStatus = String(item.package_status || item.package?.status || 'unknown').toLowerCase();
  const status = document.createElement('strong');
  status.textContent = ({
    valid: 'Ready',
    missing: 'Package information unavailable',
    invalid: 'Needs review',
    'legacy-unverified': 'Not yet verified',
  })[packageStatus] || 'Needs review';
  const contents = document.createElement('span');
  contents.textContent = media.length
    ? `Includes ${media.map(name => name.replaceAll('_', ' ')).join(', ')}`
    : 'No media summary available.';
  const original = document.createElement('span');
  original.textContent = `Package file: ${item.original_filename || 'Unavailable'}`;
  const technical = document.createElement('details');
  const technicalSummary = document.createElement('summary');
  technicalSummary.textContent = 'Technical details';
  const technicalStatus = document.createElement('span');
  technicalStatus.textContent = `Internal status: ${packageStatus}`;
  const technicalVersion = document.createElement('span');
  technicalVersion.textContent = `Package version: ${Number(item.package_version || item.package?.version || 0)}`;
  const technicalGeneration = document.createElement('span');
  technicalGeneration.textContent = `Package generation: ${Number(item.package_generation || item.package?.generation || 0)}`;
  const compatibility = document.createElement('span');
  compatibility.textContent = `Compatibility: ${item.package?.compatibility || (item.legacy_metadata ? 'legacy' : 'native')}`;
  const identity = document.createElement('span');
  identity.textContent = `Stable ID: ${item.public_id || 'unknown'}`;
  technical.append(technicalSummary, technicalStatus, technicalVersion, technicalGeneration, compatibility, identity);
  cell.append(status, contents, original, technical);
  return cell;
}

async function adminGestureRequest(body) {
  const response = await fetch(appUrl('/api/admin_gestures.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify({ ...body, _csrf: CSRF_TOKEN }),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.error) {
    const error = new Error(data.error || 'Admin gesture update failed.');
    error.code = data.error_code || '';
    error.authoritative = data.authoritative || null;
    throw error;
  }
  return data;
}

async function saveAdminGestureRow(row, item, button) {
  if (!refreshAdminGestureRowDirtyState(row)) return;
  const changes = {};
  row.querySelectorAll('[data-gesture-field]').forEach(control => { changes[control.dataset.gestureField] = control.value; });
  button.disabled = true;
  row.classList.add('saving');
  row.dataset.resultState = 'saving';
  setAdminGestureRowResult(row, 'Saving changes…', 'working');
  setAdminGestureStatus(`Saving ${item.catalog_filename}…`, 'working');
  try {
    const result = await adminGestureRequest({
      action: 'update_metadata',
      public_id: item.public_id,
      expected_version: Number(row.dataset.gestureVersion),
      request_key: `gesture-admin-metadata-${Date.now().toString(36)}-${crypto.getRandomValues(new Uint32Array(1))[0].toString(36)}`,
      changes,
    });
    const saved = result.gesture || {};
    row.dataset.gestureVersion = String(saved.version ?? (Number(row.dataset.gestureVersion) + 1));
    row.querySelectorAll('[data-gesture-field]').forEach(control => {
      const savedValue = String(saved[control.dataset.gestureField] ?? control.value);
      control.value = savedValue;
      control.defaultValue = savedValue;
    });
    adminGestureState.dirtyRows.delete(String(item.public_id));
    row.classList.remove('dirty', 'conflict');
    row.classList.add('success');
    row.dataset.resultState = 'success';
    setAdminGestureRowResult(row, 'Saved. Creator credit and upload information were unchanged.', 'success');
    adminGestureChannel?.postMessage({ type: 'gesture-saved', gesturePublicId: item.public_id });
    setAdminGestureStatus('Server Gesture metadata saved without changing publication or ownership.', 'ok');
  } catch (error) {
    row.classList.remove('success');
    row.classList.toggle('conflict', error.code === 'GESTURE_VERSION_CONFLICT');
    row.dataset.resultState = error.code === 'GESTURE_VERSION_CONFLICT' ? 'conflict' : 'error';
    if (error.code === 'GESTURE_VERSION_CONFLICT') {
      const review = row.querySelector('[data-admin-gesture-review]');
      if (review) {
        review.hidden = false;
        review.dataset.authoritativeVersion = String(error.authoritative?.version ?? '');
      }
      setAdminGestureRowResult(row, 'Conflict: this draft is preserved. Review the latest authoritative row before retrying.', 'conflict');
      setAdminGestureStatus('This gesture changed elsewhere. Your local draft was preserved for review and was not overwritten.', 'error');
    } else {
      setAdminGestureRowResult(row, `Not saved: ${error.message}`, 'error');
      setAdminGestureStatus(error.message, 'error');
    }
  } finally {
    row.classList.remove('saving');
    button.disabled = !refreshAdminGestureRowDirtyState(row);
  }
}

function openAdminGestureEditor(item) {
  const editor = window.open(appUrl(`/gesture_editor.php?id=${encodeURIComponent(item.public_id)}&admin=1`), `chatspace-admin-gesture-${item.public_id}`, 'popup,width=1080,height=820,resizable=yes,scrollbars=yes');
  if (!editor) setAdminGestureStatus('Allow this site to open the gesture package manager, then try again.', 'error');
  else editor.focus();
}

function renderAdminGestureCatalog(data) {
  if (!adminGestureCatalog || !adminGesturePager) return;
  clearAdminGestureDirtyState();
  adminGestureCatalog.replaceChildren();
  const header = document.createElement('div');
  header.className = 'admin-gesture-row admin-gesture-header';
  header.setAttribute('role', 'row');
  header.append(
    adminGestureCell('columnheader', 'File name'),
    adminGestureCell('columnheader', 'Title'),
    adminGestureCell('columnheader', 'Gesture text'),
    adminGestureCell('columnheader', 'Creator credit'),
    adminGestureCell('columnheader', 'Uploaded by'),
    adminGestureCell('columnheader', 'Package'),
    adminGestureCell('columnheader', 'Actions')
  );
  adminGestureCatalog.appendChild(header);
  (data.items || []).forEach(item => {
    const row = document.createElement('div');
    row.className = 'admin-gesture-row';
    row.setAttribute('role', 'row');
    row.dataset.gesturePublicId = item.public_id;
    row.dataset.gestureVersion = String(item.version);
    row.dataset.resultState = '';
    const uploaded = adminGestureCell(
      'cell',
      `${item.uploaded_by || 'Unknown uploader'} · ${adminCreatedOnText(item.last_uploaded_at)}`,
      'admin-gesture-uploaded',
      'Uploaded by'
    );
    const actions = adminGestureCell('cell', '', 'admin-gesture-actions', 'Actions');
    const save = document.createElement('button');
    save.type = 'button';
    save.className = 'btn btn-primary';
    save.textContent = 'Save Gesture';
    save.dataset.adminGestureSave = '';
    save.disabled = true;
    save.addEventListener('click', () => saveAdminGestureRow(row, item, save));
    const manage = document.createElement('button');
    manage.type = 'button'; manage.className = 'btn'; manage.textContent = 'Manage package';
    manage.disabled = adminGestureState.features.admin_package_inspection === false;
    manage.addEventListener('click', () => openAdminGestureEditor(item));
    const download = document.createElement('a');
    download.className = 'btn'; download.textContent = 'Download package';
    download.setAttribute('role', 'button');
    if (adminGestureState.features.admin_package_inspection === false) download.setAttribute('aria-disabled', 'true');
    else download.href = appUrl(`/api/gesture_packages.php?action=download&admin=1&id=${encodeURIComponent(item.public_id)}&request_id=admin-${Date.now().toString(36)}`);
    const review = document.createElement('button');
    review.type = 'button';
    review.className = 'btn';
    review.textContent = 'Review latest';
    review.dataset.adminGestureReview = '';
    review.hidden = true;
    review.addEventListener('click', () => {
      if (!confirmAdminGestureDiscard('Reviewing the latest authoritative row')) return;
      loadAdminGestures({ allowDiscard: true }).catch(error => setAdminGestureStatus(error.message, 'error'));
    });
    const status = document.createElement('p');
    status.className = 'admin-gesture-row-status';
    status.setAttribute('aria-live', 'polite');
    status.textContent = 'No unsaved changes.';
    actions.append(save, review, manage, download, status);
    row.append(
      adminGestureEditorCell(item, 'catalog_filename', 'Safe catalog file name'),
      adminGestureEditorCell(item, 'title', 'Gesture title'),
      adminGestureEditorCell(item, 'text', 'Gesture text', true),
      adminGestureEditorCell(item, 'creator_credit', 'Creator credit'),
      uploaded,
      adminGesturePackageCell(item),
      actions
    );
    adminGestureCatalog.appendChild(row);
  });
  if (!(data.items || []).length) {
    const empty = adminGestureCell('row', 'No Server Gestures match this search.', 'admin-gesture-empty');
    adminGestureCatalog.appendChild(empty);
  }

  adminGesturePager.replaceChildren();
  const previous = document.createElement('button');
  previous.type = 'button';
  previous.className = 'btn';
  previous.textContent = 'Previous';
  previous.disabled = adminGestureState.page <= 1;
  previous.addEventListener('click', () => {
    if (!confirmAdminGestureDiscard('Changing pages')) return;
    adminGestureState.page = Math.max(1, adminGestureState.page - 1);
    loadAdminGestures({ allowDiscard: true }).catch(error => setAdminGestureStatus(error.message, 'error'));
  });
  const previousSeparator = document.createElement('span');
  previousSeparator.className = 'admin-gesture-pager-separator';
  previousSeparator.setAttribute('aria-hidden', 'true');
  previousSeparator.textContent = '—';
  const label = document.createElement('span');
  label.textContent = `Page ${adminGestureState.page} of ${adminGestureState.pages}`;
  const nextSeparator = previousSeparator.cloneNode(true);
  const next = document.createElement('button');
  next.type = 'button';
  next.className = 'btn';
  next.textContent = 'Next';
  next.disabled = adminGestureState.page >= adminGestureState.pages;
  next.addEventListener('click', () => {
    if (!confirmAdminGestureDiscard('Changing pages')) return;
    adminGestureState.page = Math.min(adminGestureState.pages, adminGestureState.page + 1);
    loadAdminGestures({ allowDiscard: true }).catch(error => setAdminGestureStatus(error.message, 'error'));
  });
  adminGesturePager.append(previous, previousSeparator, label, nextSeparator, next);
}

async function loadAdminGestures({ allowDiscard = false, reason = 'Refreshing the Server Gesture catalog' } = {}) {
  if (!adminGestureCatalog || !adminSettingsRegistry) return false;
  if (!allowDiscard && !confirmAdminGestureDiscard(reason)) return false;
  renderAdminGestureFeatureSummary();
  const limitsSummary = document.getElementById('admin-summary-limits');
  if (limitsSummary) {
    const limitCount = adminSettingsRegistryUI.entries.filter(entry => adminSettingsRegistryUI.isLimitEntry(entry)).length;
    limitsSummary.textContent = `${limitCount} limits`;
  }
  if (!adminGestureCatalogEnabled()) {
    if (hasDirtyAdminGestures()) {
      clearAdminGestureDirtyState();
      renderAdminGestureFeatureSummary();
    }
    return false;
  }
  const request = ++adminGestureState.request;
  adminGestureState.loading = true;
  setAdminGestureStatus('Loading Server Gesture metadata and package catalog...', 'working');
  try {
    const params = new URLSearchParams({
      q: adminGestureSearch?.value || '',
      page: String(adminGestureState.page),
      sort: adminGestureSort?.value || 'last_uploaded',
    });
    const response = await fetch(appUrl(`/api/admin_gestures.php?${params.toString()}`), { headers: { Accept: 'application/json' } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.error) throw new Error(data.error || 'Gesture catalog request failed.');
    if (request !== adminGestureState.request) return;
    adminGestureState.page = Math.max(1, Number(data.page || 1));
    adminGestureState.pages = Math.max(1, Number(data.pages || 1));
    adminGestureState.total = Math.max(0, Number(data.total || 0));
    adminGestureState.features = data.features || {};
    adminGestureState.query = adminGestureSearch?.value || '';
    adminGestureState.sort = adminGestureSort?.value || 'last_uploaded';
    setAdminCount(document.getElementById('admin-gesture-count'), adminGestureState.total);
    renderAdminGestureCatalog(data);
    setAdminGestureStatus(`${adminGestureState.total} Server Gesture${adminGestureState.total === 1 ? '' : 's'}; 50 rows per page.`, 'ok');
    return true;
  } finally {
    if (request === adminGestureState.request) adminGestureState.loading = false;
  }
}

async function receiveAdminSettingsRevision(revision) {
  const incoming = Number(revision || 0);
  if (!adminSettingsRegistry || incoming <= Number(adminSettingsRegistry.revision || 0) || adminSettingsSyncPending) return;
  const discardedDraftCount = Object.keys(adminSettingsRegistryUI?.changedValues?.() || {}).length;
  adminSettingsSyncPending = true;
  try {
    await loadAdminSettings();
    setCanonicalAdminStatus(discardedDraftCount
      ? `Settings refreshed from another Admin tab; ${discardedDraftCount} unsaved local draft change${discardedDraftCount === 1 ? ' was' : 's were'} cleared to prevent a stale write.`
      : 'Settings refreshed from another Admin tab.', 'ok');
  } catch (error) {
    setCanonicalAdminStatus(error.message || 'Updated settings could not be refreshed.', 'error');
  } finally {
    adminSettingsSyncPending = false;
  }
}

function announceAdminSettingsRevision(revision) {
  const message = { revision: Number(revision || 0), nonce: `${Date.now()}-${Math.random()}` };
  adminSettingsChannel?.postMessage(message);
  try { localStorage.setItem(adminSettingsSyncKey, JSON.stringify(message)); } catch (_) {}
}

adminSettingsChannel?.addEventListener('message', event => receiveAdminSettingsRevision(event.data?.revision));
window.addEventListener('storage', event => {
  if (event.key !== adminSettingsSyncKey || !event.newValue) return;
  try { receiveAdminSettingsRevision(JSON.parse(event.newValue).revision); } catch (_) {}
});

async function loadAdminLinkIcons() {
  if (!adminLinkIcons) return;
  const data = await fetch(appUrl('/api/admin_link_icons.php')).then(r => r.json());
  adminLinkIcons.innerHTML = '';
  setAdminCount(adminCounts.linkIcons, (data.icons || []).length);
  if (!(data.icons || []).length) {
    adminLinkIcons.innerHTML = '<div class="minor">No link icons available.</div>';
    return;
  }
  (data.icons || []).forEach(icon => {
    const row = document.createElement('form');
    row.className = 'admin-link-icon-row';
    row.innerHTML = `<img src="${esc(appUrl(icon.file_path))}" alt="">
      <div><strong>${esc(icon.icon_name)}</strong><div class="minor">${icon.built_in ? 'Built-in' : 'Custom'}</div></div>
      <input name="label" value="${esc(icon.label)}" required>
      <button class="btn btn-primary" type="submit">Save</button>
      <button class="btn btn-danger" type="button"${icon.built_in ? ' disabled' : ''}>Delete</button>
      <div class="admin-row-status" aria-live="polite"></div>`;
    row.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('icon_name', icon.icon_name);
      fd.append('label', row.elements.label.value);
      setAdminFormStatus(row, 'Saving...', 'working');
      try {
        await adminLinkIconRequest(fd);
        setAdminFormStatus(row, 'Saved.', 'ok');
        await loadAdminLogs();
      } catch (err) {
        setAdminFormStatus(row, err.message || 'Save failed.', 'error');
      }
    });
    row.querySelector('.btn-danger')?.addEventListener('click', async () => {
      if (!confirm(`Delete ${icon.label}? Existing pairs using it will switch to Plus.`)) return;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('icon_name', icon.icon_name);
      try {
        await adminLinkIconRequest(fd);
        await loadAdminLinkIcons();
        await loadAdminLogs();
      } catch (err) {
        setAdminFormStatus(row, err.message || 'Delete failed.', 'error');
      }
    });
    adminLinkIcons.appendChild(row);
  });
}

function adminRow(main, detail, buttonText, onClick) {
  const row = document.createElement('div');
  row.className = 'admin-list-row';
  row.innerHTML = `<div><strong>${esc(main)}</strong><div class="minor">${esc(detail || '')}</div></div>${buttonText ? `<button class="btn btn-danger" type="button">${esc(buttonText)}</button>` : ''}`;
  if (buttonText) row.querySelector('button').addEventListener('click', onClick);
  return row;
}

async function loadAdminLogs() {
  if (!adminToolLogs) return;
  const data = await fetch(appUrl('/api/admin_system.php?action=logs')).then(r => r.json());
  adminToolLogs.innerHTML = '';
  setAdminCount(adminCounts.logs, (data.logs || []).length);
  if (!(data.logs || []).length) {
    adminToolLogs.innerHTML = '<div class="minor">No tool logs yet.</div>';
    return;
  }
  (data.logs || []).forEach(log => {
    adminToolLogs.appendChild(adminRow(`${log.action} · ${log.actor_name}`, `${log.target_name || 'No target'} ${log.room_name ? '· ' + log.room_name : ''} · ${log.created_at}${log.detail ? ' · ' + log.detail : ''}`));
  });
}

async function loadAdminBlocks() {
  if (!adminBlocks) return;
  const data = await fetch(appUrl('/api/admin_system.php?action=blocks')).then(r => r.json());
  adminBlocks.innerHTML = '';
  adminModerationTotals.blocks = (data.blocks || []).length;
  refreshAdminModerationCount();
  if (!(data.blocks || []).length) {
    adminBlocks.innerHTML = '<div class="minor">No active user blocks.</div>';
    return;
  }
  (data.blocks || []).forEach(block => {
    adminBlocks.appendChild(adminRow(`${block.blocker_name} blocked ${block.blocked_name}`, block.created_at, 'Remove Block', async () => {
      await adminSystemRequest({ action: 'remove_block', blocker_user_id: block.blocker_user_id, blocked_user_id: block.blocked_user_id });
      await loadAdminBlocks();
      await loadAdminLogs();
    }));
  });
}

async function loadAdminCommunityEjections() {
  if (!adminCommunityEjections) return;
  const data = await fetch(appUrl('/api/admin_system.php?action=community_ejections')).then(r => r.json());
  adminCommunityEjections.innerHTML = '';
  adminModerationTotals.communityEjections = (data.ejections || []).length;
  refreshAdminModerationCount();
  if (!(data.ejections || []).length) {
    adminCommunityEjections.innerHTML = '<div class="minor">No active community ejections.</div>';
    return;
  }
  (data.ejections || []).forEach(ejection => {
    const duration = ejection.permanent ? 'Forever' : `Until ${new Date(String(ejection.expires_at).replace(' ', 'T') + 'Z').toLocaleString()}`;
    adminCommunityEjections.appendChild(adminRow(ejection.display_name, `${duration} · by ${ejection.ejected_by_name}${ejection.reason ? ' · ' + ejection.reason : ''}`, 'Undo', async () => {
      await adminSystemRequest({ action: 'undo_community_ejection', id: ejection.id });
      await loadAdminCommunityEjections();
      await loadAdminLogs();
    }));
  });
}

async function loadAdminRoomEjections() {
  if (!adminRoomEjections) return;
  const data = await fetch(appUrl('/api/admin_system.php?action=room_ejections')).then(r => r.json());
  adminRoomEjections.innerHTML = '';
  adminModerationTotals.roomEjections = (data.ejections || []).length;
  refreshAdminModerationCount();
  if (!(data.ejections || []).length) {
    adminRoomEjections.innerHTML = '<div class="minor">No active room kicks.</div>';
    return;
  }
  (data.ejections || []).forEach(ejection => {
    const duration = ejection.permanent ? 'Permanent' : `Until ${new Date(String(ejection.expires_at).replace(' ', 'T') + 'Z').toLocaleString()}`;
    adminRoomEjections.appendChild(adminRow(`${ejection.display_name} · ${ejection.room_name}`, `${duration} · by ${ejection.ejected_by_name}`, 'Undo', async () => {
      await adminSystemRequest({ action: 'undo_room_ejection', id: ejection.id });
      await loadAdminRoomEjections();
      await loadAdminLogs();
    }));
  });
}

function setCanonicalAdminStatus(message, type = '') {
  const status = document.getElementById('admin-canonical-status');
  if (!status) return;
  status.textContent = message || '';
  status.className = `admin-form-status ${type}`.trim();
}

async function adminIssueRequest(path, options = {}) {
  const response = await fetch(appUrl(path), { credentials: 'same-origin', ...options });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.error) {
    const error = new Error(data.error || 'Runtime issue request failed.');
    error.data = data;
    error.status = response.status;
    throw error;
  }
  return data;
}

function adminIssuePost(body) {
  return adminIssueRequest('/api/runtime_issues.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify({ ...body, _csrf: CSRF_TOKEN }),
  });
}

async function legacyLoadAdminIssues() {
  const filterInput = document.getElementById('issue-status-filter');
  const list = document.getElementById('issue-list');
  const badge = document.getElementById('issue-count');
  if (!filterInput || !list || !badge) return;
  const filter = filterInput.value;
  const data = await adminIssueRequest(`/api/runtime_issues.php?action=list${filter ? `&status=${encodeURIComponent(filter)}` : ''}`);
  const countData = filter ? await adminIssueRequest('/api/runtime_issues.php?action=list') : data;
  list.textContent = '';
  const issueCount = (countData.issues || []).length;
  badge.textContent = String(issueCount);
  badge.setAttribute('aria-label', `${issueCount} issue${issueCount === 1 ? '' : 's'}`);
  for (const issue of data.issues || []) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'issue-list-item';
    button.innerHTML = `<strong>${esc(issue.title)}</strong><span>${esc(issue.component)} · ${esc(issue.status)}</span><small>${issue.occurrenceCount} occurrence${issue.occurrenceCount === 1 ? '' : 's'}</small>`;
    button.addEventListener('click', () => loadAdminIssueDetail(issue.id));
    list.appendChild(button);
  }
  if (!list.children.length) list.innerHTML = '<p class="minor">No matching issues.</p>';
}

async function legacyLoadAdminIssueDetail(issueId) {
  const data = await adminIssueRequest(`/api/runtime_issues.php?action=detail&issue_id=${encodeURIComponent(issueId)}`);
  const issue = data.issue;
  const detail = document.getElementById('issue-detail');
  if (!detail) return;
  detail.innerHTML = `
    <header><div><span class="issue-severity severity-${esc(issue.severity)}">${esc(issue.severity)}</span><h2>${esc(issue.title)}</h2></div><code>${esc(issue.fingerprint)}</code></header>
    <dl><dt>Owner</dt><dd>${esc(issue.component)}</dd><dt>Code</dt><dd>${esc(issue.errorCode)}</dd><dt>Status</dt><dd>${esc(issue.status)}</dd><dt>Seen</dt><dd>${esc(issue.firstSeenAt)} to ${esc(issue.lastSeenAt)}</dd></dl>
    <form id="issue-status-form" class="shared-form compact-form">
      <label>Status <select name="status">${['new','confirmed','investigating','fixed-pending-verification','resolved','expected','ignored','regressed'].map(value => `<option value="${value}"${value === issue.status ? ' selected' : ''}>${value.replaceAll('-', ' ')}</option>`).join('')}</select></label>
      <label>Reason <input name="reason" maxlength="512"></label>
      <label>Verification reference <input name="verification_reference" maxlength="191"></label>
      <button class="btn btn-primary" type="submit">Update Status</button>
    </form>
    <div class="shared-form-actions"><button class="btn" id="issue-bundle-preview" type="button">Preview Support Bundle</button><button class="btn" id="issue-bundle-export" type="button">Export Support Bundle</button></div>
    <h3>Occurrences</h3><div id="issue-occurrences"></div>
    <h3>Resolution History</h3><div id="issue-history"></div>
    <h3>Censored Screenshots</h3><div id="issue-screenshots" class="issue-screenshots"></div>
    <pre id="issue-bundle" hidden></pre>`;
  const occurrences = detail.querySelector('#issue-occurrences');
  for (const occurrence of data.occurrences || []) {
    const panel = document.createElement('details');
    panel.innerHTML = `<summary>${esc(occurrence.createdAt)} · occurrence ${occurrence.id}</summary><pre></pre>`;
    panel.querySelector('pre').textContent = JSON.stringify(occurrence.evidence, null, 2);
    occurrences.appendChild(panel);
  }
  detail.querySelector('#issue-history').innerHTML = (data.history || []).map(row => `<div class="issue-history-row"><strong>${esc(row.fromStatus || 'created')} → ${esc(row.toStatus)}</strong><span>${esc(row.actorName)} · ${esc(row.createdAt)}</span><p>${esc(row.reason || row.verificationReference || '')}</p></div>`).join('') || '<p class="minor">No status changes.</p>';
  const screenshots = detail.querySelector('#issue-screenshots');
  for (const screenshot of data.screenshots || []) {
    const figure = document.createElement('figure');
    figure.innerHTML = `<img src="${esc(appUrl(`/api/runtime_issues.php?action=screenshot&id=${encodeURIComponent(screenshot.publicId)}`))}" alt="Locally censored diagnostic schematic"><figcaption>${screenshot.width}×${screenshot.height} · ${screenshot.byteSize} bytes</figcaption><button class="btn btn-danger" type="button">Delete</button>`;
    figure.querySelector('button').addEventListener('click', async () => {
      await adminIssuePost({ action: 'delete_screenshot', id: screenshot.publicId });
      await loadAdminIssueDetail(issueId);
    });
    screenshots.appendChild(figure);
  }
  if (!screenshots.children.length) screenshots.innerHTML = '<p class="minor">No screenshots.</p>';
  detail.querySelector('#issue-status-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await adminIssuePost({ action: 'update_status', issue_id: issueId, status: form.elements.status.value, reason: form.elements.reason.value, verification_reference: form.elements.verification_reference.value });
      await Promise.all([loadAdminIssues(), loadAdminIssueDetail(issueId)]);
      setCanonicalAdminStatus('Issue status updated.', 'ok');
    } catch (error) {
      setCanonicalAdminStatus(error.message, 'error');
    }
  });
  const getBundle = () => adminIssueRequest(`/api/runtime_issues.php?action=bundle&issue_id=${encodeURIComponent(issueId)}`);
  detail.querySelector('#issue-bundle-preview').addEventListener('click', async () => {
    const bundle = (await getBundle()).bundle;
    const pre = detail.querySelector('#issue-bundle');
    pre.hidden = false;
    pre.textContent = JSON.stringify(bundle, null, 2);
  });
  detail.querySelector('#issue-bundle-export').addEventListener('click', async () => {
    const bundle = (await getBundle()).bundle;
    const url = URL.createObjectURL(new Blob([JSON.stringify(bundle, null, 2)], { type: 'application/json' }));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `chatspace-issue-${issueId}.json`;
    anchor.click();
    URL.revokeObjectURL(url);
  });
}

let adminIssuePage = 1;
let adminIssueCapabilities = null;
let adminIssueSelectedId = 0;

function issueOperationId(prefix) {
  return `${prefix}-${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
}

function issueDownloadArtifact(artifact, filename) {
  const url = URL.createObjectURL(new Blob([JSON.stringify(artifact, null, 2)], { type: 'application/json' }));
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 0);
}

function issueSetListStatus(message, type = '') {
  const status = document.getElementById('issue-list-status');
  if (!status) return;
  status.textContent = message || '';
  status.className = `minor ${type}`.trim();
}

async function loadAdminIssues() {
  const statusInput = document.getElementById('issue-status-filter');
  const severityInput = document.getElementById('issue-severity-filter');
  const list = document.getElementById('issue-list');
  const badge = document.getElementById('issue-count');
  const pager = document.getElementById('issue-pager');
  const totals = document.getElementById('issue-grouped-totals');
  if (!statusInput || !severityInput || !list || !badge || !pager || !totals) return;
  list.replaceChildren();
  pager.replaceChildren();
  issueSetListStatus('Loading runtime issues...', 'working');
  try {
    if (!adminIssueCapabilities) {
      const config = await adminIssueRequest('/api/runtime_issues.php?action=config');
      adminIssueCapabilities = config.capabilities || {};
    }
    const params = new URLSearchParams({
      action: 'list',
      page: String(adminIssuePage),
      per_page: '25',
    });
    if (statusInput.value) params.set('status', statusInput.value);
    if (severityInput.value) params.set('severity', severityInput.value);
    const data = await adminIssueRequest(`/api/runtime_issues.php?${params.toString()}`);
    const issueCount = Number(data.totals?.all ?? data.total ?? 0);
    badge.textContent = String(issueCount);
    badge.setAttribute('aria-label', `${issueCount} issue${issueCount === 1 ? '' : 's'}`);
    totals.replaceChildren();
    for (const [label, value] of [
      ['All', data.totals?.all || 0],
      ['New', data.totals?.byStatus?.new || 0],
      ['Investigating', data.totals?.byStatus?.investigating || 0],
      ['Regressed', data.totals?.byStatus?.regressed || 0],
      ['Critical', data.totals?.bySeverity?.critical || 0],
    ]) {
      const item = document.createElement('span');
      item.textContent = `${label}: ${value}`;
      totals.appendChild(item);
    }
    for (const issue of data.issues || []) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'issue-list-item';
      button.classList.toggle('active', issue.id === adminIssueSelectedId);
      button.innerHTML = `<strong>${esc(issue.title)}</strong><span>${esc(issue.component)} · ${esc(issue.status)}</span><small>${issue.occurrenceCount} occurrence${issue.occurrenceCount === 1 ? '' : 's'} · ${issue.recurrenceCount} recurrence${issue.recurrenceCount === 1 ? '' : 's'}</small>`;
      button.addEventListener('click', () => loadAdminIssueDetail(issue.id));
      list.appendChild(button);
    }
    if (!list.children.length) {
      const empty = document.createElement('p');
      empty.className = 'minor';
      empty.textContent = 'No runtime issues match the selected filters.';
      list.appendChild(empty);
    }
    const previous = document.createElement('button');
    previous.type = 'button';
    previous.className = 'btn';
    previous.textContent = 'Previous';
    previous.disabled = Number(data.page || 1) <= 1;
    previous.addEventListener('click', () => {
      adminIssuePage = Math.max(1, adminIssuePage - 1);
      loadAdminIssues();
    });
    const page = document.createElement('span');
    page.textContent = `Page ${data.page || 1} of ${data.pageCount || 1}`;
    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'btn';
    next.textContent = 'Next';
    next.disabled = Number(data.page || 1) >= Number(data.pageCount || 1);
    next.addEventListener('click', () => {
      adminIssuePage += 1;
      loadAdminIssues();
    });
    pager.append(previous, page, next);
    issueSetListStatus(`${data.total || 0} matching issue${Number(data.total || 0) === 1 ? '' : 's'}.`, 'ok');
  } catch (error) {
    issueSetListStatus(error.message || 'Runtime issues could not be loaded.', 'error');
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn';
    retry.textContent = 'Retry';
    retry.addEventListener('click', loadAdminIssues);
    list.appendChild(retry);
  }
}

async function issuePreviewAndExport(issueId, kind, output) {
  const result = await adminIssueRequest(`/api/runtime_issues.php?action=export_preview&issue_id=${encodeURIComponent(issueId)}&kind=${encodeURIComponent(kind)}`);
  const preview = result.preview;
  output.hidden = false;
  output.textContent = `Includes:\n- ${(preview.includes || []).join('\n- ')}\n\nExcludes:\n- ${(preview.excludes || []).join('\n- ')}`;
  return preview;
}

async function issueExportReviewed(issueId, kind, preview) {
  const result = await adminIssuePost({
    action: 'export',
    issue_id: issueId,
    kind,
    request_id: issueOperationId(kind),
    preview_token: preview.previewToken,
  });
  const suffix = kind === 'support-bundle' ? 'support-bundle' : 'hosted-pending-handoff';
  issueDownloadArtifact(result.artifact, `chatspace-issue-${issueId}-${suffix}.json`);
  return result;
}

async function loadAdminIssueDetail(issueId) {
  const detail = document.getElementById('issue-detail');
  if (!detail) return;
  detail.innerHTML = '<p class="minor">Loading issue details...</p>';
  try {
    const data = await adminIssueRequest(`/api/runtime_issues.php?action=detail&issue_id=${encodeURIComponent(issueId)}`);
    const issue = data.issue;
    adminIssueSelectedId = issue.id;
    const confirmedCopy = issue.status === 'confirmed'
      ? '<p class="issue-acknowledgement">Confirmed means this issue has been acknowledged; it does not mean the issue is fixed.</p>'
      : '';
    detail.innerHTML = `
      <header><div><span class="issue-severity severity-${esc(issue.severity)}">${esc(issue.severity)}</span><h2>${esc(issue.title)}</h2></div><code>${esc(issue.fingerprint)}</code></header>
      ${confirmedCopy}
      <dl class="issue-summary-grid">
        <dt>Component</dt><dd>${esc(issue.component)}</dd>
        <dt>Code</dt><dd>${esc(issue.errorCode)}</dd>
        <dt>State</dt><dd>${esc(issue.status)}</dd>
        <dt>First seen</dt><dd>${esc(issue.firstSeenAt)}</dd>
        <dt>Last seen</dt><dd>${esc(issue.lastSeenAt)}</dd>
        <dt>Occurrences</dt><dd>${issue.occurrenceCount}</dd>
        <dt>Recurrences</dt><dd>${issue.recurrenceCount} across ${issue.recurrenceGeneration} generation${issue.recurrenceGeneration === 1 ? '' : 's'}</dd>
      </dl>
      <form id="issue-status-form" class="shared-form compact-form">
        <label>State <select name="status">${(data.statusCatalog || []).map(item => `<option value="${esc(item.id)}"${item.id === issue.status ? ' selected' : ''}>${esc(item.label)}</option>`).join('')}</select></label>
        <label>Reason <input name="reason" maxlength="512"></label>
        <label>Verification reference <input name="verification_reference" maxlength="191"></label>
        <button class="btn btn-primary" type="submit">Update State</button>
      </form>
      <section class="issue-retention"><h3>Evidence Retention</h3><p id="issue-retention-summary"></p><div class="shared-form-actions" id="issue-retention-actions"></div></section>
      <section><h3>Privacy-safe Exports</h3><div class="shared-form-actions"><button class="btn" id="issue-support-preview" type="button">Preview Support Bundle</button><button class="btn" id="issue-support-export" type="button" disabled>Export Support Bundle</button><button class="btn" id="issue-handoff-preview" type="button">Preview Hosted-pending Handoff</button><button class="btn" id="issue-handoff-export" type="button" disabled>Export Hosted-pending Handoff</button></div><pre id="issue-export-preview" hidden></pre></section>
      <section><h3>Occurrences and Context</h3><div id="issue-occurrences"></div></section>
      <section><h3>Resolution History</h3><div id="issue-history"></div></section>
      <section><h3>Censored Screenshots</h3><div id="issue-screenshots" class="issue-screenshots"></div></section>
      <section class="issue-danger-zone"><h3>Delete Diagnostic Evidence</h3><p>Issue identity, aggregate counts, minimal fingerprint, state, and history are preserved.</p><button class="btn btn-danger" id="issue-delete-preview" type="button">Review Exact Impact</button><div id="issue-delete-confirmation" class="settings-impact-confirmation" hidden></div></section>`;

    const occurrences = detail.querySelector('#issue-occurrences');
    for (const occurrence of data.occurrences || []) {
      const panel = document.createElement('details');
      const summary = document.createElement('summary');
      summary.textContent = `${occurrence.createdAt} · occurrence ${occurrence.id}`;
      const context = document.createElement('dl');
      context.className = 'issue-summary-grid';
      const pairs = [
        ['Server context', JSON.stringify(occurrence.context || {})],
        ['Client build hint', occurrence.clientBuildHint || 'Not supplied'],
        ['Client request correlation', occurrence.clientRequestCorrelation || 'Not supplied'],
        [
          'Privacy-bounded network context',
          occurrence.networkContext
            ? `${occurrence.networkContext.owner}. Address retained or displayed: no. ${occurrence.networkContext.notice}`
            : 'Not available',
        ],
      ];
      for (const [label, value] of pairs) {
        const dt = document.createElement('dt');
        const dd = document.createElement('dd');
        dt.textContent = label;
        dd.textContent = value;
        context.append(dt, dd);
      }
      const evidence = document.createElement('pre');
      evidence.textContent = JSON.stringify(occurrence.evidence || {}, null, 2);
      panel.append(summary, context, evidence);
      occurrences.appendChild(panel);
    }
    if (!occurrences.children.length) occurrences.innerHTML = '<p class="minor">Detailed occurrence evidence has been removed; aggregate counts remain.</p>';

    detail.querySelector('#issue-history').innerHTML = (data.history || []).map(row => `<div class="issue-history-row"><strong>${esc(row.fromStatus || 'created')} → ${esc(row.toStatus)}</strong><span>${esc(row.actorName)} · ${esc(row.createdAt)}</span><p>${esc(row.reason || row.verificationReference || '')}</p></div>`).join('') || '<p class="minor">No state changes.</p>';
    const screenshots = detail.querySelector('#issue-screenshots');
    for (const screenshot of data.screenshots || []) {
      const figure = document.createElement('figure');
      figure.innerHTML = `<img src="${esc(appUrl(`/api/runtime_issues.php?action=screenshot&id=${encodeURIComponent(screenshot.publicId)}`))}" alt="Locally censored diagnostic schematic"><figcaption>${screenshot.width}×${screenshot.height} · ${screenshot.byteSize} bytes · ${esc(screenshot.createdAt)}</figcaption><button class="btn btn-danger" type="button">Delete Screenshot</button>`;
      figure.querySelector('button').addEventListener('click', async () => {
        await adminIssuePost({ action: 'delete_screenshot', id: screenshot.publicId });
        await loadAdminIssueDetail(issueId);
      });
      screenshots.appendChild(figure);
    }
    if (!screenshots.children.length) screenshots.innerHTML = '<p class="minor">No censored screenshots are retained.</p>';

    detail.querySelector('#issue-status-form').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      try {
        await adminIssuePost({
          action: 'update_status',
          issue_id: issueId,
          status: form.elements.status.value,
          reason: form.elements.reason.value,
          verification_reference: form.elements.verification_reference.value,
          expected_revision: issue.revision,
        });
        await Promise.all([loadAdminIssues(), loadAdminIssueDetail(issueId)]);
        setCanonicalAdminStatus('Issue state updated.', 'ok');
      } catch (error) {
        setCanonicalAdminStatus(error.message, 'error');
      }
    });

    const retentionSummary = detail.querySelector('#issue-retention-summary');
    const retention = data.retention || {};
    retentionSummary.textContent = retention.holdActive
      ? `Safety hold active${retention.holdReason ? `: ${retention.holdReason}` : '.'}`
      : `Retention class: ${retention.class || 'standard'}. No safety hold is active.`;
    const retentionActions = detail.querySelector('#issue-retention-actions');
    if (adminIssueCapabilities?.evidence) {
      const reason = document.createElement('input');
      reason.placeholder = retention.holdActive ? 'Reason for releasing hold' : 'Reason for safety hold';
      reason.maxLength = 500;
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = retention.holdActive ? 'btn' : 'btn btn-danger';
      toggle.textContent = retention.holdActive ? 'Release Hold' : 'Apply Safety Hold';
      toggle.addEventListener('click', async () => {
        await adminIssuePost({ action: 'set_retention_hold', issue_id: issueId, active: !retention.holdActive, reason: reason.value, expected_revision: issue.revision });
        await loadAdminIssueDetail(issueId);
      });
      retentionActions.append(reason, toggle);
    }

    const exportPreview = detail.querySelector('#issue-export-preview');
    for (const [kind, previewId, exportId] of [
      ['support-bundle', '#issue-support-preview', '#issue-support-export'],
      ['hosted-pending-handoff', '#issue-handoff-preview', '#issue-handoff-export'],
    ]) {
      let preview = null;
      const previewButton = detail.querySelector(previewId);
      const exportButton = detail.querySelector(exportId);
      previewButton.addEventListener('click', async () => {
        preview = await issuePreviewAndExport(issueId, kind, exportPreview);
        exportButton.disabled = false;
      });
      exportButton.addEventListener('click', async () => {
        if (!preview) return;
        await issueExportReviewed(issueId, kind, preview);
        preview = null;
        exportButton.disabled = true;
      });
    }

    detail.querySelector('#issue-delete-preview').addEventListener('click', async () => {
      const result = await adminIssueRequest(`/api/runtime_issues.php?action=deletion_preview&issue_id=${encodeURIComponent(issueId)}`);
      const preview = result.preview;
      const confirmation = detail.querySelector('#issue-delete-confirmation');
      confirmation.replaceChildren();
      confirmation.hidden = false;
      const copy = document.createElement('p');
      copy.textContent = preview.eligible
        ? `This removes ${preview.impact.occurrences} detailed occurrences and ${preview.impact.screenshots} screenshots while preserving issue identity, aggregate counts, minimal fingerprint, state, and history.`
        : 'Evidence cannot be deleted while a safety hold or active investigation preserves it.';
      confirmation.appendChild(copy);
      if (preview.eligible) {
        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'btn btn-danger';
        confirm.textContent = 'Confirm Evidence Deletion';
        confirm.addEventListener('click', async () => {
          await adminIssuePost({
            action: 'delete_evidence',
            issue_id: issueId,
            expected_revision: preview.revision,
            fingerprint: preview.fingerprint,
            request_id: issueOperationId('evidence-delete'),
            confirmation_id: preview.confirmationId,
            confirmed: 1,
          });
          await Promise.all([loadAdminIssues(), loadAdminIssueDetail(issueId)]);
        });
        confirmation.appendChild(confirm);
      }
    });
    await loadAdminIssues();
  } catch (error) {
    detail.innerHTML = '<p class="error-text">Issue details could not be loaded.</p><button class="btn" type="button">Retry</button>';
    detail.querySelector('button')?.addEventListener('click', () => loadAdminIssueDetail(issueId));
    setCanonicalAdminStatus(error.message || 'Issue details could not be loaded.', 'error');
  }
}

document.getElementById('issue-status-filter')?.addEventListener('change', () => {
  adminIssuePage = 1;
  loadAdminIssues();
});
document.getElementById('issue-severity-filter')?.addEventListener('change', () => {
  adminIssuePage = 1;
  loadAdminIssues();
});

async function loadAdminDashboard() {
  await Promise.all([
    loadAdminUsers(),
    loadAdminModerationUsers(),
    loadAdminSettings(),
    loadAdminLogs(),
    loadAdminBlocks(),
    loadAdminRoomEjections(),
    loadAdminCommunityEjections(),
    loadAdminLinkIcons(),
    loadAdminIssues(),
    loadAdminNetworkPolicy(),
    loadAdminRetention(),
    loadAdminSystemHealth(),
  ]);
}

async function openCanonicalAdmin() {
  lobbyMenu?.classList.remove('visible');
  adminModal.classList.add('open');
  if (!restoreAdminLocationFromHash()) showAdminSection('overview');
  await loadAdminDashboard();
}

document.getElementById('admin-open')?.addEventListener('click', openCanonicalAdmin);

document.getElementById('admin-gesture-open-settings')?.addEventListener('click', () => {
  if (!showAdminSection('settings')) return;
  const search = document.getElementById('lobby-admin-settings-search');
  if (!search) return;
  search.value = 'gesture';
  search.dispatchEvent(new Event('input', { bubbles: true }));
  search.focus();
});

adminGestureSearch?.addEventListener('input', () => {
  window.clearTimeout(adminGestureSearchTimer);
  adminGestureSearchTimer = window.setTimeout(() => {
    if (!confirmAdminGestureDiscard('Searching the Server Gesture catalog')) {
      adminGestureSearch.value = adminGestureState.query;
      return;
    }
    adminGestureState.page = 1;
    loadAdminGestures({ allowDiscard: true }).catch(error => setAdminGestureStatus(error.message || 'Gesture search failed.', 'error'));
  }, 180);
});

adminGestureSort?.addEventListener('change', () => {
  if (!confirmAdminGestureDiscard('Sorting the Server Gesture catalog')) {
    adminGestureSort.value = adminGestureState.sort;
    return;
  }
  adminGestureState.page = 1;
  loadAdminGestures({ allowDiscard: true }).catch(error => setAdminGestureStatus(error.message || 'Gesture sort failed.', 'error'));
});

adminGestureChannel?.addEventListener('message', event => {
  if (event.data?.type !== 'gesture-saved' || !document.getElementById('admin-section-gestures')?.classList.contains('active')) return;
  loadAdminGestures().catch(error => setAdminGestureStatus(error.message || 'Updated gesture catalog could not be refreshed.', 'error'));
});

window.addEventListener('message', event => {
  if (event.origin !== window.location.origin || event.data?.type !== 'chatspace-gesture-saved') return;
  loadAdminGestures().catch(error => setAdminGestureStatus(error.message || 'Updated gesture catalog could not be refreshed.', 'error'));
});

window.addEventListener('pagehide', () => adminGestureChannel?.close(), { once: true });

document.getElementById('admin-close')?.addEventListener('click', () => {
  if (!confirmAdminGestureDiscard('Closing Admin')) return;
  clearAdminGestureDirtyState();
  adminSettingsUnlock?.relock('Settings changes locked because the Admin interface closed.', 'closure');
  const params = new URLSearchParams(window.location.search);
  if (params.get('admin') === '1' && params.get('return') === 'room') {
    window.close();
    window.setTimeout(() => {
      setCanonicalAdminStatus('Close this Admin tab to return to the still-running room. No rejoin is required.', 'ok');
    }, 200);
    return;
  }
  adminModal.classList.remove('open');
  if (params.get('admin') === '1') history.replaceState({}, '', appUrl('/lobby.php'));
});

window.addEventListener('beforeunload', event => {
  if (!hasDirtyAdminGestures()) return;
  event.preventDefault();
  event.returnValue = '';
});

if (new URLSearchParams(window.location.search).get('admin') === '1') {
  openCanonicalAdmin().catch(error => setCanonicalAdminStatus(error.message, 'error'));
}

document.getElementById('admin-create')?.addEventListener('submit', async e => {
  e.preventDefault();
  const form = e.currentTarget;
  const submit = form.querySelector('button[type="submit"]');
  submit.disabled = true;
  setAdminFormStatus(form, 'Creating...', 'working');
  try {
    await adminRequest({
      action: 'create',
      username: form.elements.username.value,
      display_name: form.elements.display_name.value,
      email: form.elements.email.value,
      password: form.elements.password.value,
      role: form.elements.role.value,
    });
    form.reset();
    setAdminFormStatus(form, 'User created.', 'ok');
    await loadAdminUsers();
    await loadAdminLogs();
  } catch (err) {
    setAdminFormStatus(form, err.message || 'Create failed.', 'error');
  } finally {
    submit.disabled = false;
  }
});

function lobbyAdminCompatibilityLabel(state) {
  return ({ 'original-compatible': 'Original ChatSpace values', 'framework-default': 'Recommended defaults', custom: 'Custom values' })[state] || 'Custom values';
}

function renderLobbyAdminSettingsCompatibility() {
  const target = document.getElementById('lobby-admin-settings-compatibility-state');
  if (!target || !adminSettingsRegistryUI) return;
  const state = adminSettingsRegistryUI.compatibilityState();
  const changed = Number(adminSettingsRegistry?.summaries?.changedFromDefaultCount || 0);
  target.textContent = `${lobbyAdminCompatibilityLabel(state)} · ${changed} saved setting${changed === 1 ? '' : 's'} changed from default`;
}

function syncLobbyAdminSettingsMutationLocks() {
  const unlocked = Boolean(adminSettingsUnlock?.isUnlocked());
  const resetOptional = document.getElementById('lobby-admin-settings-reset-optional');
  if (resetOptional) {
    resetOptional.disabled = !unlocked;
    adminSettingsRegistryUI?.applyControlLockSemantics(resetOptional);
  }
  const save = document.getElementById('lobby-admin-settings-save');
  const changed = Object.keys(adminSettingsRegistryUI?.changedValues?.() || {}).length;
  const valid = adminSettingsRegistryUI?.getState?.().valid !== false;
  if (save) {
    save.disabled = !unlocked || changed === 0 || !valid;
    adminSettingsRegistryUI?.applyControlLockSemantics(save);
  }
  document.querySelectorAll('#lobby-admin-settings-preset-review [data-settings-mutation]').forEach(control => {
    control.disabled = !unlocked;
    adminSettingsRegistryUI?.applyControlLockSemantics(control);
  });
}

function clearLobbyAdminProfileLimitConfirmation() {
  adminProfileLimitConfirmation = null;
  const panel = document.getElementById('lobby-admin-profile-limit-confirmation');
  const list = document.getElementById('lobby-admin-profile-limit-impact-list');
  if (panel) panel.hidden = true;
  if (list) list.replaceChildren();
}

function settingsRegistryRequestId() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  const bytes = new Uint8Array(16);
  globalThis.crypto?.getRandomValues?.(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function clearLobbyAdminDatabaseCompatibilityConfirmation() {
  adminDatabaseCompatibilityConfirmation = null;
  const panel = document.getElementById('lobby-admin-database-compatibility-confirmation');
  if (panel) panel.hidden = true;
}

function clearLobbyAdminModerationTrustConfirmation() {
  adminModerationTrustConfirmation = null;
  const panel = document.getElementById('lobby-admin-moderation-trust-confirmation');
  if (panel) panel.hidden = true;
}

function showLobbyAdminModerationTrustConfirmation(operation, details, policy) {
  const panel = document.getElementById('lobby-admin-moderation-trust-confirmation');
  const impact = document.getElementById('lobby-admin-moderation-trust-impact');
  if (!panel || !impact) throw new Error('Moderation and Trust impact review is unavailable.');
  adminModerationTrustConfirmation = {
    operation,
    details: {
      ...details,
      values: details.values ? { ...details.values } : details.values,
    },
  };
  const activeCount = Number(policy?.activeOptionalStateCount || 0);
  impact.textContent = `Disabling optional Moderation and Trust workflows will stop ${activeCount} active optional state${activeCount === 1 ? '' : 's'} while preserving mandatory safety, cases, evidence, restrictions, suspensions, retention, and Tool Logs.`;
  panel.hidden = false;
  document.getElementById('lobby-admin-moderation-trust-confirm')?.focus();
  const message = 'Review and confirm the active optional Moderation and Trust workflows that will stop.';
  setAdminFormStatus(adminSettings, message, 'working');
  adminSettingsUnlock?.announce(message, 'working');
}

function showLobbyAdminDatabaseCompatibilityConfirmation(operation, details) {
  const panel = document.getElementById('lobby-admin-database-compatibility-confirmation');
  if (!panel) throw new Error('Compatibility-enforcement risk review is unavailable.');
  adminDatabaseCompatibilityConfirmation = {
    operation,
    details: {
      ...details,
      values: details.values ? { ...details.values } : details.values,
    },
  };
  panel.hidden = false;
  document.getElementById('lobby-admin-database-compatibility-confirm')?.focus();
  const message = 'Review and confirm the risk of disabling proactive database/release compatibility enforcement.';
  setAdminFormStatus(adminSettings, message, 'working');
  adminSettingsUnlock?.announce(message, 'working');
}

function showLobbyAdminProfileLimitConfirmation(operation, details, impacts) {
  const panel = document.getElementById('lobby-admin-profile-limit-confirmation');
  const list = document.getElementById('lobby-admin-profile-limit-impact-list');
  if (!panel || !list) throw new Error('Profile-limit impact review is unavailable.');
  adminProfileLimitConfirmation = {
    operation,
    details: {
      ...details,
      values: details.values ? { ...details.values } : details.values,
    },
  };
  list.replaceChildren();
  for (const impact of impacts || []) {
    const item = document.createElement('li');
    const count = Number(impact.recordsAboveProposedLimit || 0);
    item.textContent = `${impact.label}: ${impact.currentLimit} → ${impact.proposedLimit}; `
      + `${count} existing record${count === 1 ? '' : 's'} above the proposed limit.`;
    list.appendChild(item);
  }
  panel.hidden = false;
  document.getElementById('lobby-admin-profile-limit-confirm')?.focus();
  const message = 'Review the existing profile records affected by the proposed lower limit.';
  setAdminFormStatus(adminSettings, message, 'working');
  adminSettingsUnlock?.announce(message, 'working');
}

function lobbyAdminSettingsFailureMessage(error, fallback) {
  if (error?.data?.code === 'SETTINGS_REGISTRY_STALE') return 'Settings changed elsewhere. Refresh and try again.';
  if (error?.data?.code === 'SETTINGS_REGISTRY_AUTHORIZATION_REQUIRED' || /Admin(?:istrator)? required|authorization/i.test(error?.message || '')) {
    adminSettingsUnlock?.setAuthorized(false, 'You are no longer authorized to change these settings.');
    return 'You are no longer authorized to change these settings.';
  }
  return error?.message || fallback;
}

function lobbyAdminSettingsSuccessMessage(result, operation, details) {
  if (result.idempotent || Number(result.changedSettingCount || 0) === 0) return 'No settings needed to be changed.';
  if (operation === 'apply_preset') return details.preset === 'original-compatible'
    ? 'Original ChatSpace values applied.'
    : 'Recommended defaults applied.';
  if (operation === 'reset_all_optional') return 'All optional settings reset to defaults.';
  if (operation === 'reset_category') {
    const label = adminSettingsRegistry?.categories?.find(category => category.id === details.category_id)?.label || 'Category';
    return `${label} reset to defaults.`;
  }
  if (operation === 'reset_subsection') {
    const entry = adminSettingsRegistryUI?.entries?.find(item => item.categoryId === details.category_id && item.subsectionId === details.subsection_id);
    return `${entry?.subsectionLabel || 'Subsection'} reset to defaults.`;
  }
  if (operation === 'reset_setting') {
    const entry = adminSettingsRegistryUI?.entryMap?.get(details.setting_id);
    return `${entry?.label || 'Setting'} reset to defaults.`;
  }
  const ids = result.changedSettingIds || [];
  const danceIds = ids.filter(id => id.startsWith('avatar_dance.'));
  if (danceIds.length > 1 && danceIds.length === ids.length) {
    const enabled = Object.values(details.values || {}).some(Boolean);
    return `All dances ${enabled ? 'enabled' : 'disabled'}.`;
  }
  const gestureIds = ids.filter(id => id.startsWith('gesture_part3_'));
  if (gestureIds.length > 1 && gestureIds.length === ids.length) {
    const enabled = Object.values(details.values || {}).some(Boolean);
    return `All browsing and organization features ${enabled ? 'enabled' : 'disabled'}.`;
  }
  const packageIds = ids.filter(id => id.startsWith('gesture_part4_'));
  if (packageIds.length > 1 && packageIds.length === ids.length) {
    const enabled = Object.values(details.values || {}).some(Boolean);
    return `All creation, package, and media features ${enabled ? 'enabled' : 'disabled'}.`;
  }
  const capabilityIds = ids.filter(id => [
    'allow_gestures',
    'allow_server_gestures',
    'allow_personal_gestures',
    'allow_user_gesture_mutation',
    'allow_gesture_audio_delivery',
  ].includes(id));
  if (capabilityIds.length > 1 && capabilityIds.length === ids.length) {
    const enabled = Object.values(details.values || {}).some(Boolean);
    return `All gesture capabilities ${enabled ? 'enabled' : 'disabled'}.`;
  }
  if (ids.length === 1) {
    const entry = adminSettingsRegistryUI?.entryMap?.get(ids[0]);
    const value = details.values?.[ids[0]];
    if (entry && typeof value === 'boolean') return `${entry.label} ${value ? 'enabled' : 'disabled'}.`;
    if (entry) return `${entry.label} changed.`;
  }
  return `${Number(result.changedSettingCount || ids.length)} settings changed.`;
}

async function mutateLobbyAdminSettings(operation, details = {}) {
  if (!adminSettingsRegistry) return null;
  if (!adminSettingsUnlock?.requireUnlocked()) return null;
  if (!CAN_ADMIN_SETTINGS_MUTATE) throw new Error('Administrator access is required to change settings.');
  details = { ...details };
  if (!details.request_id) details.request_id = settingsRegistryRequestId();
  const compatibilityPolicyId = 'database_release_compatibility_enforcement';
  const touchesCompatibilityPolicy = Object.prototype.hasOwnProperty.call(details.values || {}, compatibilityPolicyId)
    || (operation === 'reset_setting' && details.setting_id === compatibilityPolicyId);
  if (touchesCompatibilityPolicy && details.expected_database_compatibility_revision === undefined) {
    details.expected_database_compatibility_revision = Number(
      adminSettingsRegistryUI?.entryMap?.get(compatibilityPolicyId)?.ownerRevision ?? 0
    );
  }
  const moderationTrustId = 'moderation_trust_optional_core_enabled';
  const touchesModerationTrust = Object.prototype.hasOwnProperty.call(details.values || {}, moderationTrustId)
    || (operation === 'reset_setting' && details.setting_id === moderationTrustId)
    || operation === 'apply_preset';
  if (touchesModerationTrust && details.expected_moderation_trust_revision === undefined) {
    details.expected_moderation_trust_revision = Number(
      adminSettingsRegistryUI?.entryMap?.get(moderationTrustId)?.ownerRevision ?? 0
    );
  }
  const operationalCapacityIds = new Set(
    (adminSettingsRegistry?.entries || [])
      .filter(entry => entry.owner === 'operational_capacity_policy' && entry.type === 'number')
      .map(entry => entry.id)
  );
  const touchesOperationalCapacity = Object.keys(details.values || {}).some(id => operationalCapacityIds.has(id))
    || (operation === 'reset_setting' && operationalCapacityIds.has(details.setting_id));
  if (touchesOperationalCapacity && details.expected_operational_capacity_revision === undefined) {
    const firstId = [...operationalCapacityIds][0];
    details.expected_operational_capacity_revision = Number(
      adminSettingsRegistryUI?.entryMap?.get(firstId)?.ownerRevision ?? 0
    );
  }
  const diagnosticPolicyId = 'runtime_diagnostic_collection_mode';
  const touchesDiagnosticPolicy = Object.prototype.hasOwnProperty.call(details.values || {}, diagnosticPolicyId)
    || (operation === 'reset_setting' && details.setting_id === diagnosticPolicyId);
  if (touchesDiagnosticPolicy && details.expected_runtime_diagnostic_revision === undefined) {
    details.expected_runtime_diagnostic_revision = Number(
      adminSettingsRegistryUI?.entryMap?.get(diagnosticPolicyId)?.ownerRevision ?? 0
    );
  }
  if (['reset_subsection', 'reset_category', 'reset_all_optional', 'apply_preset'].includes(operation)) details.confirmed = 1;
  const capacityId = 'avatar_relationship_max_regular_links';
  let capacityTarget;
  let capacityImpactMessage = '';
  if (Object.prototype.hasOwnProperty.call(details.values || {}, capacityId)) capacityTarget = Number(details.values[capacityId]);
  if (operation === 'reset_setting' && details.setting_id === capacityId) capacityTarget = Number(adminSettingsRegistryUI?.entryMap?.get(capacityId)?.defaultValue);
  if (operation === 'reset_subsection' && details.category_id === 'avatar-interactions' && details.subsection_id === 'relationships') capacityTarget = Number(adminSettingsRegistryUI?.entryMap?.get(capacityId)?.defaultValue);
  if (operation === 'reset_category' && details.category_id === 'avatar-interactions') capacityTarget = Number(adminSettingsRegistryUI?.entryMap?.get(capacityId)?.defaultValue);
  if (Number.isFinite(capacityTarget) && !details.capacity_confirmed) {
    const response = await fetch(appUrl(`/api/admin_system.php?action=relationship_capacity_impact&value=${encodeURIComponent(capacityTarget)}`));
    const impact = await response.json().catch(() => ({}));
    if (!response.ok || impact.error) throw new Error(impact.error || 'Relationship limit could not be checked.');
    const affected = Number(impact.relationshipsAboveProposedLimit || 0);
    if (impact.isLowering && affected > 0) {
      details = { ...details, capacity_confirmed: 1 };
      capacityImpactMessage = `${affected} existing relationship${affected === 1 ? '' : 's'} will remain valid above the new limit and cannot accept new regular links until below it.`;
      adminSettingsUnlock.announce(capacityImpactMessage, 'ok');
    }
  }
  let result;
  try {
    result = await adminSystemRequest({
      action: 'update_settings_registry',
      operation,
      expected_revision: adminSettingsRegistry.revision,
      ...details,
    });
  } catch (error) {
    if (error?.data?.code === 'DATABASE_COMPATIBILITY_DISABLE_CONFIRMATION_REQUIRED'
        && !details.database_compatibility_confirmed) {
      showLobbyAdminDatabaseCompatibilityConfirmation(operation, details);
      return null;
    }
    if (error?.data?.code === 'PROFILE_LIMIT_CONFIRMATION_REQUIRED'
        && !details.profile_limits_confirmed) {
      showLobbyAdminProfileLimitConfirmation(
        operation,
        details,
        error.data.profileLimitImpacts || []
      );
      return null;
    }
    if (error?.data?.code === 'MODERATION_TRUST_DISABLE_IMPACT_CONFIRMATION_REQUIRED'
        && !details.moderation_trust_impact_confirmed) {
      showLobbyAdminModerationTrustConfirmation(
        operation,
        details,
        error.data.moderationTrustPolicy || {}
      );
      return null;
    }
    if (error?.data?.code === 'NETWORK_MANUAL_BANS_DISABLE_CONFIRMATION_REQUIRED'
        && !details.network_manual_bans_disable_confirmed) {
      const activeCount = Number(error.data.networkModerationPolicy?.activeBanCount || 0);
      const confirmed = window.confirm(
        `Disabling Manual Network Bans stops enforcement for ${activeCount} active ban`
        + `${activeCount === 1 ? '' : 's'}. Review and confirm this impact.`
      );
      if (!confirmed) return null;
      return mutateLobbyAdminSettings(operation, {
        ...details,
        network_manual_bans_disable_confirmed: 1,
      });
    }
    throw error;
  }
  clearLobbyAdminProfileLimitConfirmation();
  clearLobbyAdminDatabaseCompatibilityConfirmation();
  clearLobbyAdminModerationTrustConfirmation();
  adminSettingsRegistry = result.registry || result.settingsRegistry || adminSettingsRegistry;
  adminSettingsRegistryUI.setRegistry(adminSettingsRegistry);
  renderLobbyAdminSettingsCompatibility();
  renderAdminGestureFeatureSummary();
  announceAdminSettingsRevision(adminSettingsRegistry.revision);
  const stopped = Number(result.stoppedActiveCapabilityCount || 0);
  let message = lobbyAdminSettingsSuccessMessage(result, operation, details);
  if (stopped) message = `${message.replace(/\.$/, '')}; ${stopped} active optional state${stopped === 1 ? '' : 's'} stopped safely.`;
  if (capacityImpactMessage) message = `${message} ${capacityImpactMessage}`;
  setAdminFormStatus(adminSettings, message, 'ok');
  adminSettingsUnlock.announce(message, 'ok');
  await loadAdminLogs();
  return result;
}

async function handleLobbyAdminSettingsOperation(operation, details) {
  if (!adminSettingsUnlock?.requireUnlocked()) return;
  try {
    if (operation === 'set_many') return await mutateLobbyAdminSettings('set_many', details);
    if (operation === 'reset_setting') return await mutateLobbyAdminSettings('reset_setting', details);
    if (operation === 'reset_subsection') return await mutateLobbyAdminSettings('reset_subsection', { ...details, confirmed: 1 });
    if (operation === 'reset_category') return await mutateLobbyAdminSettings('reset_category', { ...details, confirmed: 1 });
  } catch (error) {
    await loadAdminSettings().catch(() => {});
    const message = lobbyAdminSettingsFailureMessage(error, 'Settings operation failed.');
    setAdminFormStatus(adminSettings, message, 'error');
    adminSettingsUnlock?.announce(message, 'error');
  }
}

adminSettings?.addEventListener('submit', async event => {
  event.preventDefault();
  if (!adminSettingsUnlock?.requireUnlocked()) return;
  const values = adminSettingsRegistryUI?.changedValues() || {};
  if (!Object.keys(values).length) return;
  const submit = document.getElementById('lobby-admin-settings-save');
  if (submit) submit.disabled = true;
  setAdminFormStatus(adminSettings, 'Saving settings...', 'working');
  try {
    await mutateLobbyAdminSettings('set_many', { values });
  } catch (error) {
    await loadAdminSettings().catch(() => {});
    const message = lobbyAdminSettingsFailureMessage(error, 'Settings failed to save.');
    setAdminFormStatus(adminSettings, message, 'error');
    adminSettingsUnlock?.announce(message, 'error');
  }
});

document.getElementById('lobby-admin-profile-limit-confirm')?.addEventListener('click', async event => {
  const pending = adminProfileLimitConfirmation;
  if (!pending || !adminSettingsUnlock?.requireUnlocked()) return;
  const confirmButton = event.currentTarget;
  confirmButton.disabled = true;
  try {
    await mutateLobbyAdminSettings(pending.operation, {
      ...pending.details,
      profile_limits_confirmed: 1,
    });
  } catch (error) {
    await loadAdminSettings().catch(() => {});
    const message = lobbyAdminSettingsFailureMessage(error, 'Profile limits failed to save.');
    setAdminFormStatus(adminSettings, message, 'error');
    adminSettingsUnlock?.announce(message, 'error');
  } finally {
    confirmButton.disabled = false;
  }
});

document.getElementById('lobby-admin-profile-limit-cancel')?.addEventListener('click', () => {
  clearLobbyAdminProfileLimitConfirmation();
  syncLobbyAdminSettingsMutationLocks();
  const message = 'Profile-limit change was not applied; your draft remains available.';
  setAdminFormStatus(adminSettings, message, 'ok');
  adminSettingsUnlock?.announce(message, 'ok');
});

document.getElementById('lobby-admin-database-compatibility-confirm')?.addEventListener('click', async event => {
  const pending = adminDatabaseCompatibilityConfirmation;
  if (!pending || !adminSettingsUnlock?.requireUnlocked()) return;
  const confirmButton = event.currentTarget;
  confirmButton.disabled = true;
  try {
    await mutateLobbyAdminSettings(pending.operation, {
      ...pending.details,
      database_compatibility_confirmed: 1,
    });
  } catch (error) {
    const message = lobbyAdminSettingsFailureMessage(error, 'Compatibility enforcement failed to save.');
    setAdminFormStatus(adminSettings, message, 'error');
    adminSettingsUnlock?.announce(message, 'error');
  } finally {
    confirmButton.disabled = false;
  }
});

document.getElementById('lobby-admin-database-compatibility-cancel')?.addEventListener('click', () => {
  clearLobbyAdminDatabaseCompatibilityConfirmation();
  syncLobbyAdminSettingsMutationLocks();
  const message = 'Compatibility-enforcement change was not applied; your draft remains available.';
  setAdminFormStatus(adminSettings, message, 'ok');
  adminSettingsUnlock?.announce(message, 'ok');
  document.getElementById('lobby-admin-settings-save')?.focus();
});

document.getElementById('lobby-admin-moderation-trust-confirm')?.addEventListener('click', async event => {
  const pending = adminModerationTrustConfirmation;
  if (!pending || !adminSettingsUnlock?.requireUnlocked()) return;
  const confirmButton = event.currentTarget;
  confirmButton.disabled = true;
  try {
    await mutateLobbyAdminSettings(pending.operation, {
      ...pending.details,
      moderation_trust_impact_confirmed: 1,
    });
  } catch (error) {
    const message = lobbyAdminSettingsFailureMessage(error, 'Moderation and Trust failed to save.');
    setAdminFormStatus(adminSettings, message, 'error');
    adminSettingsUnlock?.announce(message, 'error');
  } finally {
    confirmButton.disabled = false;
  }
});

document.getElementById('lobby-admin-moderation-trust-cancel')?.addEventListener('click', () => {
  clearLobbyAdminModerationTrustConfirmation();
  syncLobbyAdminSettingsMutationLocks();
  const message = 'Moderation and Trust change was not applied; your draft remains available.';
  setAdminFormStatus(adminSettings, message, 'ok');
  adminSettingsUnlock?.announce(message, 'ok');
  document.getElementById('lobby-admin-settings-save')?.focus();
});

function showLobbyAdminPresetReview(preset) {
  const review = document.getElementById('lobby-admin-settings-preset-review');
  const changes = adminSettingsRegistryUI?.presetChanges(preset) || [];
  if (!review) return;
  review.textContent = '';
  review.hidden = false;
  const heading = document.createElement('h4');
  heading.textContent = preset === 'original-compatible' ? 'Original-compatible review' : 'Framework-default review';
  review.appendChild(heading);
  const list = document.createElement('ul');
  if (!changes.length) {
    const item = document.createElement('li');
    item.textContent = 'No setting changes are required.';
    list.appendChild(item);
  } else {
    for (const change of changes) {
      const item = document.createElement('li');
      const format = (entry, value) => entry.type === 'boolean' ? (value ? 'Enabled' : 'Disabled') : String(value);
      item.textContent = `${change.entry.label}: ${format(change.entry, change.from)} → ${format(change.entry, change.to)}`;
      list.appendChild(item);
    }
  }
  review.appendChild(list);
  for (const difference of adminSettingsRegistry?.compatibility?.unavoidableDifferences || []) {
    const note = document.createElement('p');
    note.className = 'minor';
    note.textContent = difference;
    review.appendChild(note);
  }
  if (!changes.length || !CAN_ADMIN_SETTINGS_MUTATE) return;
  const apply = document.createElement('button');
  apply.type = 'button';
  apply.className = 'btn btn-primary';
  apply.dataset.settingsMutation = 'preset';
  apply.disabled = !adminSettingsUnlock?.isUnlocked();
  apply.textContent = preset === 'original-compatible' ? 'Apply Original ChatSpace Values' : 'Restore Recommended Defaults';
  apply.addEventListener('click', async () => {
    apply.disabled = true;
    try {
      if (!adminSettingsUnlock?.requireUnlocked()) return;
      await mutateLobbyAdminSettings('apply_preset', { preset, confirmed: 1 });
      review.hidden = true;
    } catch (error) {
      await loadAdminSettings().catch(() => {});
      const message = lobbyAdminSettingsFailureMessage(error, 'Preset could not be applied.');
      setAdminFormStatus(adminSettings, message, 'error');
      adminSettingsUnlock?.announce(message, 'error');
    } finally {
      apply.disabled = false;
    }
  });
  review.appendChild(apply);
}

document.getElementById('lobby-admin-settings-original-preview')?.addEventListener('click', () => showLobbyAdminPresetReview('original-compatible'));
document.getElementById('lobby-admin-settings-framework-preview')?.addEventListener('click', () => showLobbyAdminPresetReview('framework-default'));
document.getElementById('lobby-admin-settings-reset-optional')?.addEventListener('click', async () => {
  if (!CAN_ADMIN_SETTINGS_MUTATE || !adminSettingsUnlock?.requireUnlocked()) return;
  try {
    await mutateLobbyAdminSettings('reset_all_optional', { confirmed: 1 });
  } catch (error) {
    await loadAdminSettings().catch(() => {});
    const message = lobbyAdminSettingsFailureMessage(error, 'Optional settings could not be reset.');
    setAdminFormStatus(adminSettings, message, 'error');
    adminSettingsUnlock?.announce(message, 'error');
  }
});

function syncAdminExportUserMode() {
  if (!adminDbExport?.users || !adminDbExport?.gestures) return;
  if (!adminDbExport.users.checked) adminDbExport.gestures.checked = false;
  adminDbExport.gestures.disabled = !adminDbExport.users.checked;
  if (adminUserExportLabel) adminUserExportLabel.textContent = adminDbExport.gestures.checked ? 'User Data + Gestures' : 'User Data';
}

adminDbExport?.gestures?.addEventListener('change', () => {
  if (adminDbExport.gestures.checked) adminDbExport.users.checked = true;
  syncAdminExportUserMode();
});

adminDbExport?.users?.addEventListener('change', syncAdminExportUserMode);
syncAdminExportUserMode();

adminDbExport?.addEventListener('submit', e => {
  e.preventDefault();
  const users = adminDbExport.users.checked;
  const gestures = users && adminDbExport.gestures.checked;
  const rooms = adminDbExport.rooms.checked;
  const settings = adminDbExport.settings.checked;
  if (!users && !rooms && !settings) {
    alert('Select at least one export section.');
    return;
  }
  const qs = new URLSearchParams({
    action: 'export_bundle',
    users: users ? '1' : '0',
    gestures: gestures ? '1' : '0',
    rooms: rooms ? '1' : '0',
    settings: settings ? '1' : '0',
  });
  window.location.href = appUrl(`/api/admin_database.php?${qs}`);
});

adminDbRestore?.database?.addEventListener('change', () => {
  const file = adminDbRestore.database.files && adminDbRestore.database.files[0];
  document.getElementById('admin-db-restore-name').textContent = file ? file.name : 'No file selected';
  resetUploadProgress(adminDbImportProgress);
});

adminDbRestore?.addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const xhr = await uploadPlainFormWithProgress(adminDbRestore, appUrl('/api/admin_database.php'), adminDbImportProgress);
    const data = JSON.parse(xhr.responseText || '{}');
    if (data.error) throw new Error(data.error);
    setUploadProgress(adminDbImportProgress, 100, 'Import complete. Reloading...');
    window.location.reload();
  } catch (err) {
    let message = err.message || 'Database import failed';
    try {
      const parsed = JSON.parse(message);
      if (parsed.error) message = parsed.error;
    } catch (_err) {}
    setUploadProgress(adminDbImportProgress, 100, 'Import failed.');
    alert(message);
  }
});

adminLinkIconCreate?.icon?.addEventListener('change', () => {
  const file = adminLinkIconCreate.icon.files && adminLinkIconCreate.icon.files[0];
  document.getElementById('admin-link-icon-file-name').textContent = file ? file.name : 'No file selected';
});

adminLinkIconCreate?.addEventListener('submit', async e => {
  e.preventDefault();
  const form = e.currentTarget;
  const submit = form.querySelector('button[type="submit"]');
  submit.disabled = true;
  setAdminFormStatus(form, 'Adding icon...', 'working');
  try {
    const fd = new FormData(form);
    fd.append('action', 'create');
    await adminLinkIconRequest(fd);
    form.reset();
    document.getElementById('admin-link-icon-file-name').textContent = 'No file selected';
    setAdminFormStatus(form, 'Icon added.', 'ok');
    await loadAdminLinkIcons();
    await loadAdminLogs();
  } catch (err) {
    setAdminFormStatus(form, err.message || 'Could not add icon.', 'error');
  } finally {
    submit.disabled = false;
  }
});
