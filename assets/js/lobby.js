'use strict';

const APP_BASE = document.body?.dataset.appBase || '';
const CSRF_TOKEN = document.body?.dataset.csrf || '';
const CAN_ADMIN_SETTINGS_MUTATE = document.body?.dataset.isAdmin === 'true';

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
}

document.getElementById('recovery-open')?.addEventListener('click', async () => {
  lobbyMenu?.classList.remove('visible');
  recoveryModal?.classList.add('open');
  try {
    await loadRecoveryStatus();
  } catch (err) {
    setRecoveryStatus(err.message || 'Could not load recovery status.', 'error-text');
  }
});

document.getElementById('recovery-close')?.addEventListener('click', closeRecoveryModal);
document.getElementById('recovery-cancel')?.addEventListener('click', closeRecoveryModal);

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
const adminToolLogs = document.getElementById('admin-tool-logs');
const adminBlocks = document.getElementById('admin-blocks');
const adminRoomEjections = document.getElementById('admin-room-ejections');
const adminCommunityEjections = document.getElementById('admin-community-ejections');
const adminSettings = document.getElementById('lobby-admin-settings-registry-form');
let adminSettingsRegistry = null;
let adminSettingsRegistryUI = null;
let adminSettingsUnlock = null;
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
};
const adminModerationTotals = { blocks: 0, roomEjections: 0, communityEjections: 0 };

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
  document.querySelectorAll('.admin-section').forEach(section => {
    section.classList.toggle('active', section.id === `admin-section-${id}`);
  });
  document.querySelectorAll('.admin-nav-item[data-admin-section]').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.adminSection === id);
  });
}

document.addEventListener('click', e => {
  const nav = e.target.closest('.admin-nav-item[data-admin-section]');
  if (nav) {
    showAdminSection(nav.dataset.adminSection);
    return;
  }
  const jump = e.target.closest('[data-admin-jump]');
  if (jump) showAdminSection(jump.dataset.adminJump);
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
  (data.users || []).forEach(user => {
    const row = document.createElement('form');
    row.className = 'admin-user-row';
    row.innerHTML = `<div><strong>${esc(user.display_name)}</strong><div class="minor">${esc(user.email)}</div><div class="admin-created-meta"><span>Created On</span><strong>${adminCreatedOn(user.created_at)}</strong></div></div>
      <select name="role">
        <option value="user">User</option>
        <option value="guide">Guide</option>
        <option value="developer">Developer</option>
        <option value="admin">Admin</option>
      </select>
      <input name="password" type="password" placeholder="New password">
      <button class="btn btn-primary" type="submit">Save</button>
      <button class="btn btn-danger" type="button">Delete</button>
      <div class="admin-row-status" aria-live="polite"></div>`;
    row.querySelector('select').value = user.role || 'user';
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
    row.querySelector('.btn-danger').addEventListener('click', async () => {
      if (!confirm(`Delete ${user.display_name}?`)) return;
      await adminRequest({ action: 'delete', id: user.id });
      await loadAdminUsers();
    });
    adminUsers.appendChild(row);
  });
}

async function loadAdminSettings() {
  if (!adminSettings) return;
  const data = await fetch(appUrl('/api/admin_system.php?action=settings')).then(r => r.json());
  if (data.error) throw new Error(data.error);
  adminSettingsRegistry = data.settingsRegistry;
  if (!adminSettingsUnlock) {
    adminSettingsUnlock = new window.SettingsUnlockController({
      mount: document.getElementById('lobby-admin-settings-unlock'),
      activityRoot: document.getElementById('admin-section-settings'),
      authorized: CAN_ADMIN_SETTINGS_MUTATE,
      onLockChange: locked => {
        adminSettingsRegistryUI?.setLocked(locked);
        if (locked && adminSettingsRegistryUI?.registry) adminSettingsRegistryUI.setRegistry(adminSettingsRegistry);
        syncLobbyAdminSettingsMutationLocks();
      },
    });
  }
  if (!adminSettingsRegistryUI) {
    adminSettingsRegistryUI = new window.SettingsRegistryUI({
      container: document.getElementById('lobby-admin-settings-registry'),
      searchInput: document.getElementById('lobby-admin-settings-search'),
      filterInput: document.getElementById('lobby-admin-settings-filter'),
      readOnly: !CAN_ADMIN_SETTINGS_MUTATE,
      locked: !adminSettingsUnlock.isUnlocked(),
      onOperation: handleLobbyAdminSettingsOperation,
      onDraftChange: state => {
        const summary = document.getElementById('lobby-admin-settings-dirty-summary');
        const save = document.getElementById('lobby-admin-settings-save');
        if (summary) summary.textContent = state.changedCount ? `${state.changedCount} unsaved change${state.changedCount === 1 ? '' : 's'}` : 'No unsaved changes';
        if (save) save.disabled = !adminSettingsUnlock?.isUnlocked() || state.changedCount === 0;
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
  if (!response.ok || data.error) throw new Error(data.error || 'Runtime issue request failed.');
  return data;
}

function adminIssuePost(body) {
  return adminIssueRequest('/api/runtime_issues.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify({ ...body, _csrf: CSRF_TOKEN }),
  });
}

async function loadAdminIssues() {
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

async function loadAdminIssueDetail(issueId) {
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

document.getElementById('issue-status-filter')?.addEventListener('change', loadAdminIssues);

async function loadAdminDashboard() {
  await Promise.all([
    loadAdminUsers(),
    loadAdminSettings(),
    loadAdminLogs(),
    loadAdminBlocks(),
    loadAdminRoomEjections(),
    loadAdminCommunityEjections(),
    loadAdminLinkIcons(),
    loadAdminIssues(),
  ]);
}

async function openCanonicalAdmin() {
  lobbyMenu?.classList.remove('visible');
  adminModal.classList.add('open');
  showAdminSection('overview');
  await loadAdminDashboard();
}

document.getElementById('admin-open')?.addEventListener('click', openCanonicalAdmin);

document.getElementById('admin-close')?.addEventListener('click', () => {
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
  return ({ 'original-compatible': 'Original-author compatible', 'framework-default': 'Framework default', custom: 'Custom' })[state] || 'Custom';
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
  if (resetOptional) resetOptional.disabled = !unlocked;
  const save = document.getElementById('lobby-admin-settings-save');
  const changed = Object.keys(adminSettingsRegistryUI?.changedValues?.() || {}).length;
  if (save) save.disabled = !unlocked || changed === 0;
  document.querySelectorAll('#lobby-admin-settings-preset-review [data-settings-mutation]').forEach(control => {
    control.disabled = !unlocked;
  });
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
    ? 'Original-author compatible settings applied.'
    : 'Framework default settings applied.';
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
  if (['reset_subsection', 'reset_category', 'reset_all_optional', 'apply_preset'].includes(operation)) details.confirmed = 1;
  if (operation === 'set_many') details.dance_disable_all_confirmed = 1;
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
  const result = await adminSystemRequest({
    action: 'update_settings_registry',
    operation,
    expected_revision: adminSettingsRegistry.revision,
    ...details,
  });
  adminSettingsRegistry = result.registry || result.settingsRegistry || adminSettingsRegistry;
  adminSettingsRegistryUI.setRegistry(adminSettingsRegistry);
  renderLobbyAdminSettingsCompatibility();
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
    if (operation === 'set_many_confirmed') {
      return await mutateLobbyAdminSettings('set_many', { ...details, dance_disable_all_confirmed: 1 });
    }
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
  apply.textContent = preset === 'original-compatible' ? 'Apply Original-compatible Preset' : 'Restore Framework Defaults';
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
