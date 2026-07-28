'use strict';

const APP_BASE = document.body?.dataset.appBase || '';
const CSRF_TOKEN = document.body?.dataset.csrf || '';
const appUrl = path => `${APP_BASE}${path}`;
const statusEl = document.getElementById('account-page-status');
let account = null;

async function request(path, options = {}) {
  const response = await fetch(appUrl(path), { credentials: 'same-origin', ...options });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.error) throw new Error(data.error || 'Account request failed.');
  return data;
}

function post(path, body) {
  return request(path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, body: JSON.stringify({ ...body, _csrf: CSRF_TOKEN }) });
}

function showStatus(message, error = false) {
  statusEl.textContent = message || '';
  statusEl.classList.toggle('error', error);
}

async function moderationProjection() {
  return request('/api/moderation.php?view=mine');
}

function unicodeLength(value) {
  return Array.from(String(value ?? '')).length;
}

function updateProfileCounters() {
  const form = document.getElementById('account-profile-form');
  const limits = account?.profile?.fieldLimits || {};
  form.querySelectorAll('[data-profile-counter]').forEach(counter => {
    const field = counter.dataset.profileCounter;
    const control = form.elements[field];
    const limit = Number(limits[field] || 0);
    const count = unicodeLength(control?.value);
    counter.textContent = limit > 0 ? `${count} / ${limit} characters` : `${count} characters`;
    counter.classList.toggle('error', limit > 0 && count > limit);
  });
}

function render(data) {
  account = data;
  const profile = data.profile || {};
  const security = data.security || {};
  const state = data.status || {};
  const moderation = data.moderation || {};
  const profileForm = document.getElementById('account-profile-form');
  profileForm.elements.username.value = profile.username || '';
  profileForm.elements.display_name.value = profile.displayName || '';
  profileForm.elements.name.value = profile.name || '';
  profileForm.elements.location.value = profile.location || '';
  profileForm.elements.about_me.value = profile.aboutMe || '';
  profileForm.elements.public_contact_email.value = profile.publicContactEmail || '';
  profileForm.elements.website.value = profile.website || '';
  profileForm.elements.interests.value = profile.interests || '';
  profileForm.elements.discord_username.value = profile.discordUsername || '';
  profileForm.elements.discord_visible.checked = Boolean(profile.discordVisible);
  profileForm.dataset.profileVersion = String(profile.profileVersion || 1);
  document.getElementById('account-profile-display-fallback').textContent = profile.displayName
    ? 'This public Display name is used throughout ordinary chat.'
    : `Not set — shown as ${profile.username || 'Username'}`;
  document.getElementById('account-profile-registered').textContent = profile.registeredAt || '';
  const shareLink = document.getElementById('account-profile-share-link');
  shareLink.href = profile.profileUrl || '#';
  shareLink.textContent = profile.profileUrl || 'Unavailable';
  const history = profile.previousDisplayNames || [];
  document.getElementById('account-profile-history').innerHTML = history.length
    ? history.map(entry => `<li><span>${escapeHtml(entry.displayName || '')}</span><small>${escapeHtml(entry.changedAt || '')}</small></li>`).join('')
    : '<li class="minor">None</li>';
  updateProfileCounters();
  document.getElementById('account-email-form').elements.email.value = security.email || '';
  document.getElementById('password-last-changed').textContent = security.passwordChangedAt ? `Last changed ${security.passwordChangedAt}` : 'Password change date is not available for this existing account.';
  document.getElementById('account-recovery-card').textContent = security.hasRecoveryCode ? `Recovery code configured (ending ${security.recoveryCodeSuffix || 'unknown'}).` : 'No Lost Access recovery code is configured.';
  const restriction = state.temporaryRestriction;
  document.getElementById('account-status-list').innerHTML = `
    <dt>Registered</dt><dd>${escapeHtml(state.registeredAt || '')}</dd>
    <dt>Role</dt><dd><span class="role-label role-${escapeHtml(state.role || 'user')}">${escapeHtml(state.role || 'user')}</span></dd>
    <dt>Access</dt><dd>${escapeHtml(state.trustState || '')}</dd>
    <dt>Installation Owner</dt><dd>${state.isInstallationOwner ? 'Yes' : 'No'}</dd>
    <dt>Restriction or suspension expiry</dt><dd>${escapeHtml(state.restrictionExpiresAt || (restriction ? (restriction.permanent ? 'Indefinite' : restriction.expiresAt || 'Under review') : 'None'))}</dd>
    <dt>Public reason</dt><dd>${escapeHtml(state.publicReason || 'None')}</dd>
    <dt>Policy</dt><dd>${escapeHtml(state.trustPolicyNote || '')}</dd>`;
  document.getElementById('account-capabilities').innerHTML = (state.capabilities || []).map(capability => `
    <span>${escapeHtml(capability.label || capability.id || '')}: ${capability.effectiveEnabled ? 'Enabled' : capability.available ? 'Not enabled' : 'Unavailable'}${capability.denialCode ? ` — ${escapeHtml(capability.denialCode)}` : ''}</span>
  `).join('');

  const trustState = String(state.trustState || '');
  document.getElementById('account-request-explanation').textContent = state.trustPolicyNote || '';
  document.getElementById('account-trusted-review-form').hidden = trustState !== 'pending-approval';
  document.getElementById('account-appeal-form').hidden = !['restricted', 'suspended'].includes(trustState);
  document.getElementById('account-capability-request-form').hidden = trustState !== 'trusted';
  const cases = moderation.cases || [];
  const activeCapabilityKeys = new Set(cases
    .filter(item => ['received', 'under-review'].includes(item.status))
    .flatMap(item => (item.items || []).filter(part => ['received', 'under-review'].includes(part.status)).map(part => part.item_key)));
  document.getElementById('account-capability-request-options').innerHTML = (state.capabilities || [])
    .filter(capability => capability.available && !capability.effectiveEnabled)
    .map(capability => `<label><input type="checkbox" name="capability_ids" value="${escapeHtml(capability.id)}" ${activeCapabilityKeys.has(capability.id) ? 'disabled' : ''}> ${escapeHtml(capability.label)}${activeCapabilityKeys.has(capability.id) ? ' — Under Review' : ''}</label>`)
    .join('') || '<p class="minor">No capabilities are currently available to request.</p>';
  document.getElementById('account-cases').innerHTML = cases.map(item => `
    <article class="admin-user-row">
      <strong>${escapeHtml(item.case_type || '')}</strong>
      <span>${escapeHtml(item.status || '')}</span>
      <small>Reference ${escapeHtml(item.public_id || '')}; updated ${escapeHtml(item.updated_at || '')}${item.public_reason ? `; ${escapeHtml(item.public_reason)}` : ''}</small>
      ${(item.items || []).map(part => `<small>${escapeHtml(part.item_key)}: ${escapeHtml(part.status)}</small>`).join('')}
    </article>`).join('') || '<p class="minor">No requests or appeals.</p>';
  document.getElementById('account-notices').innerHTML = (moderation.notices || []).map(item => `
    <article class="admin-user-row">
      <strong>${escapeHtml(item.notice_type || '')}</strong>
      <span>${escapeHtml(item.effective_at || '')}</span>
      <small>${escapeHtml(item.public_reason || 'No public reason was provided.')}${item.expires_at ? `; expires ${escapeHtml(item.expires_at)}` : ''}</small>
      ${(item.changedCapabilities || []).length ? `<small>Changed capabilities: ${escapeHtml(item.changedCapabilities.join(', '))}</small>` : ''}
    </article>`).join('') || '<p class="minor">No notices.</p>';
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
}

document.querySelectorAll('[data-account-tab]').forEach(button => button.addEventListener('click', () => {
  document.querySelectorAll('[data-account-tab]').forEach(item => item.classList.toggle('active', item === button));
  document.querySelectorAll('[data-account-panel]').forEach(panel => panel.classList.toggle('active', panel.dataset.accountPanel === button.dataset.accountTab));
}));

const accountQuery = new URLSearchParams(globalThis.location.search);
if (accountQuery.get('tab') === 'safety') {
  document.querySelector('[data-account-tab="safety"]')?.click();
  const reportForm = document.getElementById('account-report-form');
  if (reportForm) {
    reportForm.elements.reported_user_id.value = accountQuery.get('report_user_id') || '';
    reportForm.elements.origin_reference.value = accountQuery.get('report_reference') || '';
  }
}

document.getElementById('account-profile-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const requestId = globalThis.crypto?.randomUUID?.()
    || `profile-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  try {
    showStatus('Saving public profile...');
    const data = await post('/api/account.php', {
      action: 'update_profile',
      expected_version: Number(form.dataset.profileVersion || 1),
      request_id: requestId,
      display_name: form.elements.display_name.value,
      name: form.elements.name.value,
      location: form.elements.location.value,
      about_me: form.elements.about_me.value,
      public_contact_email: form.elements.public_contact_email.value,
      website: form.elements.website.value,
      interests: form.elements.interests.value,
      discord_username: form.elements.discord_username.value,
      discord_visible: form.elements.discord_visible.checked,
    });
    render(data);
    const update = data.profileUpdate || {};
    showStatus(update.noOp ? 'No public profile changes were needed.' : 'Public profile saved.');
  } catch (error) { showStatus(error.message, true); }
});

document.getElementById('account-profile-form').addEventListener('input', updateProfileCounters);

document.getElementById('account-profile-cancel').addEventListener('click', () => {
  if (account) render(account);
  showStatus('Unsaved public profile changes were cleared.');
});

function accountRequestId(prefix) {
  return globalThis.crypto?.randomUUID?.()
    || `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

document.getElementById('account-trusted-review-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    const data = await post('/api/account.php', {
      action: 'request_trusted_review',
      request_id: accountRequestId('trusted-review'),
      note: form.elements.note.value,
    });
    form.reset(); render(data); showStatus('Trusted Review request received.');
  } catch (error) { showStatus(error.message, true); }
});

document.getElementById('account-capability-select-all').addEventListener('click', () => {
  document.querySelectorAll('#account-capability-request-options input[type="checkbox"]:not(:disabled)')
    .forEach(input => { input.checked = true; });
});

document.getElementById('account-capability-clear').addEventListener('click', () => {
  document.querySelectorAll('#account-capability-request-options input[type="checkbox"]')
    .forEach(input => { input.checked = false; });
});

document.getElementById('account-capability-request-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const capabilityIds = Array.from(form.querySelectorAll('input[name="capability_ids"]:checked')).map(input => input.value);
  try {
    const data = await post('/api/account.php', {
      action: 'request_capabilities',
      request_id: accountRequestId('capability-request'),
      capability_ids: capabilityIds,
      note: form.elements.note.value,
    });
    form.reset(); render(data); showStatus('Capability request received.');
  } catch (error) { showStatus(error.message, true); }
});

document.getElementById('account-appeal-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    const data = await post('/api/account.php', {
      action: 'submit_appeal',
      request_id: accountRequestId('appeal'),
      note: form.elements.note.value,
      enforcement_reference: `trust-revision-${account?.status?.trustRevision || 1}`,
    });
    form.reset(); render(data); showStatus('Appeal received. Current enforcement remains active during review.');
  } catch (error) { showStatus(error.message, true); }
});

function renderSafety(data) {
  document.getElementById('account-reports').innerHTML = (data.reports || []).map(report => `
    <article class="admin-user-row"><strong>${escapeHtml(report.public_id)}</strong><span>${escapeHtml(report.status)}</span><small>${escapeHtml(report.updated_at || report.created_at || '')}</small></article>
  `).join('') || '<p class="minor">No reports submitted.</p>';
  document.getElementById('account-muted-users').innerHTML = (data.mutes || []).map(mute => `
    <article class="admin-user-row"><strong>${escapeHtml(mute.display_name || mute.username)}</strong><small>${escapeHtml((mute.scopes || []).join(', '))}${mute.expires_at ? `; until ${escapeHtml(mute.expires_at)}` : ''}</small><button class="btn" type="button" data-unmute-user="${Number(mute.muted_user_id)}">Unmute</button></article>
  `).join('') || '<p class="minor">No muted users.</p>';
  document.querySelectorAll('[data-unmute-user]').forEach(button => button.addEventListener('click', async () => {
    try {
      await post('/api/moderation.php', { action: 'unmute', target_user_id: Number(button.dataset.unmuteUser) });
      renderSafety(await moderationProjection());
      showStatus('User unmuted. Eligibility only was restored; no history or relationship state changed.');
    } catch (error) { showStatus(error.message, true); }
  }));
}

document.getElementById('account-report-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    const data = await post('/api/moderation.php', {
      action: 'report',
      origin_type: form.elements.origin_type.value,
      origin_reference: form.elements.origin_reference.value,
      reported_user_id: Number(form.elements.reported_user_id.value || 0),
      reason: form.elements.reason.value,
    });
    form.reset();
    renderSafety(await moderationProjection());
    showStatus(`Report received. Reference ${data.reference}.`);
  } catch (error) { showStatus(error.message, true); }
});

document.getElementById('account-mute-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    await post('/api/moderation.php', {
      action: 'mute',
      target_user_id: Number(form.elements.target_user_id.value),
      duration: form.elements.duration.value,
      scopes: ['text-bubbles', 'gestures-audio', 'notices-unread', 'voice', 'avatar-webcam-placeholder'],
    });
    form.reset();
    renderSafety(await moderationProjection());
    showStatus('User muted privately. Authoritative game actions, scores, and system state remain visible.');
  } catch (error) { showStatus(error.message, true); }
});
document.getElementById('account-email-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    showStatus('Updating email…');
    const data = await post('/api/account.php', { action: 'update_email', email: form.elements.email.value, current_password: form.elements.current_password.value });
    form.elements.current_password.value = ''; render(data); showStatus('Email updated.');
  } catch (error) { showStatus(error.message, true); }
});

document.getElementById('account-password-form').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    showStatus('Updating password…');
    await post('/api/account.php', { action: 'update_password', old_password: form.elements.old_password.value, new_password: form.elements.new_password.value, confirm_password: form.elements.confirm_password.value });
    form.reset(); showStatus('Password updated.');
    render(await request('/api/account.php'));
  } catch (error) { showStatus(error.message, true); }
});

document.getElementById('account-recovery-generate').addEventListener('click', async () => {
  if (!confirm('Create a new recovery code? Any previous code will stop working.')) return;
  try {
    const data = await post('/api/recovery.php', { action: 'generate' });
    document.getElementById('account-recovery-card').textContent = `Store this code safely: ${data.recovery_code}`;
    showStatus('Recovery code created.');
  } catch (error) { showStatus(error.message, true); }
});

const protectionDbName = 'corechat-message-protection-v1';
let privateChatProtection = null;
let pendingRecoveryPhrase = null;

function protectionBase64Url(bytes) {
  return btoa(String.fromCharCode(...new Uint8Array(bytes)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function protectionCanonical(value) {
  if (Array.isArray(value)) return value.map(protectionCanonical);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(Object.keys(value).sort().map(key => [key, protectionCanonical(value[key])]));
}

function protectionRandomId() {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return `device-${Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')}`;
}

function protectionDb() {
  return new Promise((resolve, reject) => {
    const requestOpen = indexedDB.open(protectionDbName, 1);
    requestOpen.onupgradeneeded = () => {
      requestOpen.result.createObjectStore('devices', { keyPath: 'deviceId' });
    };
    requestOpen.onerror = () => reject(requestOpen.error);
    requestOpen.onsuccess = () => resolve(requestOpen.result);
  });
}

async function protectionStoreDevice(device) {
  const db = await protectionDb();
  await new Promise((resolve, reject) => {
    const transaction = db.transaction('devices', 'readwrite');
    transaction.objectStore('devices').put(device);
    transaction.oncomplete = resolve;
    transaction.onerror = () => reject(transaction.error);
  });
  db.close();
}

async function protectionLocalDevices() {
  const db = await protectionDb();
  const devices = await new Promise((resolve, reject) => {
    const transaction = db.transaction('devices', 'readonly');
    const requestAll = transaction.objectStore('devices').getAll();
    requestAll.onsuccess = () => resolve(requestAll.result || []);
    requestAll.onerror = () => reject(requestAll.error);
  });
  db.close();
  return devices;
}

async function protectionGenerateDevice() {
  if (!globalThis.crypto?.subtle || !globalThis.indexedDB) {
    throw new Error('This browser does not provide the required Web Crypto and IndexedDB support.');
  }
  const deviceId = protectionRandomId();
  const encryption = await crypto.subtle.generateKey(
    { name: 'ECDH', namedCurve: 'P-256' },
    true,
    ['deriveKey', 'deriveBits']
  );
  const signing = await crypto.subtle.generateKey(
    { name: 'ECDSA', namedCurve: 'P-256' },
    true,
    ['sign', 'verify']
  );
  const record = {
    deviceId,
    label: `${navigator.platform || 'Browser'} — ${new Date().toLocaleDateString()}`,
    encryptionPrivateKey: encryption.privateKey,
    encryptionPublicKey: encryption.publicKey,
    signingPrivateKey: signing.privateKey,
    signingPublicKey: signing.publicKey,
    createdAt: new Date().toISOString(),
  };
  await protectionStoreDevice(record);
  return record;
}

async function protectionRegisterDevice() {
  const device = await protectionGenerateDevice();
  const result = await post('/api/message_protection.php', {
    action: 'register_device',
    deviceId: device.deviceId,
    label: device.label,
    encryptionPublicJwk: await crypto.subtle.exportKey('jwk', device.encryptionPublicKey),
    signingPublicJwk: await crypto.subtle.exportKey('jwk', device.signingPublicKey),
  });
  await loadPrivateChatProtection();
  showStatus(result.device.status === 'trusted'
    ? 'This first private-chat device is trusted.'
    : 'This device is pending approval from an existing trusted device.');
}

async function protectionApproveDevice(target) {
  const local = await protectionLocalDevices();
  const trustedIds = new Set((privateChatProtection?.devices || [])
    .filter(device => device.status === 'trusted')
    .map(device => device.deviceId));
  const approver = local.find(device => trustedIds.has(device.deviceId));
  if (!approver) throw new Error('Open this action on an existing trusted device.');
  const material = new TextEncoder().encode(JSON.stringify(protectionCanonical({
    accountId: Number(privateChatProtection?.userId || 0),
    deviceId: target.deviceId,
    encryptionPublicJwk: target.encryptionPublicJwk,
    fingerprint: target.fingerprint,
    revision: target.revision,
    signingPublicJwk: target.signingPublicJwk,
  })));
  const signature = await crypto.subtle.sign(
    { name: 'ECDSA', hash: 'SHA-256' },
    approver.signingPrivateKey,
    material
  );
  await post('/api/message_protection.php', {
    action: 'approve_device',
    requestId: crypto.randomUUID(),
    approverDeviceId: approver.deviceId,
    targetDeviceId: target.deviceId,
    expectedRevision: target.revision,
    signature: protectionBase64Url(signature),
  });
  await loadPrivateChatProtection();
  showStatus('Pending device approved.');
}

function protectionPhraseToken(value) {
  const starts = ['amber', 'brisk', 'calm', 'clear', 'dawn', 'ember', 'fern', 'gold'];
  const ends = ['arch', 'brook', 'cove', 'drift', 'field', 'grove', 'harbor', 'isle'];
  return `${starts[(value >> 3) & 7]}${ends[value & 7]}`;
}

function protectionGeneratePhrase() {
  const random = crypto.getRandomValues(new Uint8Array(18));
  const bits = Array.from(random, byte => byte.toString(2).padStart(8, '0')).join('');
  const words = [];
  for (let index = 0; index < 12; index += 1) {
    const value = parseInt(bits.slice(index * 12, (index + 1) * 12), 2);
    words.push(`${protectionPhraseToken(value >> 6)}-${protectionPhraseToken(value & 63)}`);
  }
  return words.join(' ');
}

async function protectionPrepareRecovery() {
  const local = await protectionLocalDevices();
  const trustedIds = new Set((privateChatProtection?.devices || [])
    .filter(device => device.status === 'trusted')
    .map(device => device.deviceId));
  const device = local.find(item => trustedIds.has(item.deviceId));
  if (!device) throw new Error('A trusted device is required to create recovery.');
  const phrase = protectionGeneratePhrase();
  const recovery = await crypto.subtle.generateKey(
    { name: 'ECDSA', namedCurve: 'P-256' },
    true,
    ['sign', 'verify']
  );
  const material = new TextEncoder().encode(JSON.stringify(protectionCanonical({
    deviceId: device.deviceId,
    encryptionPrivateJwk: await crypto.subtle.exportKey('jwk', device.encryptionPrivateKey),
    signingPrivateJwk: await crypto.subtle.exportKey('jwk', device.signingPrivateKey),
    recoveryPrivateJwk: await crypto.subtle.exportKey('jwk', recovery.privateKey),
    protocol: 'corechat-message-protection-v1',
  })));
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const nonce = crypto.getRandomValues(new Uint8Array(12));
  const phraseMaterial = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(phrase.toLowerCase().trim()),
    'PBKDF2',
    false,
    ['deriveKey']
  );
  const key = await crypto.subtle.deriveKey(
    { name: 'PBKDF2', hash: 'SHA-256', salt, iterations: 600000 },
    phraseMaterial,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt']
  );
  const sealed = new Uint8Array(await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: nonce, tagLength: 128 },
    key,
    material
  ));
  pendingRecoveryPhrase = {
    phrase,
    payload: {
      action: 'store_recovery',
      deviceId: device.deviceId,
      expectedRevision: Number(privateChatProtection?.recoveryRevision || 0),
      recoveryPublicJwk: await crypto.subtle.exportKey('jwk', recovery.publicKey),
      iterations: 600000,
      salt: protectionBase64Url(salt),
      nonce: protectionBase64Url(nonce),
      ciphertext: protectionBase64Url(sealed.slice(0, -16)),
      tag: protectionBase64Url(sealed.slice(-16)),
    },
  };
  document.getElementById('account-private-chat-recovery-output').textContent = phrase;
  document.getElementById('account-private-chat-recovery-create').textContent = 'I saved the phrase — Finish';
  showStatus('Write down the phrase exactly. It will not be shown again after you finish.');
}

async function protectionFinishRecovery() {
  if (!pendingRecoveryPhrase) return protectionPrepareRecovery();
  await post('/api/message_protection.php', pendingRecoveryPhrase.payload);
  pendingRecoveryPhrase = null;
  document.getElementById('account-private-chat-recovery-output').textContent = 'Recovery phrase hidden.';
  document.getElementById('account-private-chat-recovery-create').textContent = 'Create or Replace Recovery Phrase';
  await loadPrivateChatProtection();
  showStatus('Private Chat Recovery Phrase configured. CoreChat stored only its encrypted recovery envelope.');
}

function renderPrivateChatProtection(data) {
  privateChatProtection = data.account || {};
  document.getElementById('account-private-chat-warning').textContent = privateChatProtection.lostRecoveryWarning || '';
  document.getElementById('account-private-chat-recovery-state').textContent = privateChatProtection.recoveryConfigured
    ? `Recovery is configured; revision ${Number(privateChatProtection.recoveryRevision || 0)}.`
    : 'Recovery is not configured.';
  const devices = document.getElementById('account-private-chat-devices');
  devices.innerHTML = (privateChatProtection.devices || []).map(device => `
    <article class="admin-user-row">
      <strong>${escapeHtml(device.label)}</strong>
      <span>${escapeHtml(device.status)}</span>
      <small>${escapeHtml(device.fingerprint)}; revision ${Number(device.revision || 1)}</small>
      ${device.status === 'pending' ? `<button class="btn" type="button" data-approve-protection-device="${escapeHtml(device.deviceId)}">Approve</button>` : ''}
    </article>`).join('') || '<p class="minor">No private-chat devices registered.</p>';
  devices.querySelectorAll('[data-approve-protection-device]').forEach(button => {
    button.addEventListener('click', async () => {
      const target = privateChatProtection.devices.find(device => device.deviceId === button.dataset.approveProtectionDevice);
      try { await protectionApproveDevice(target); } catch (error) { showStatus(error.message, true); }
    });
  });
}

async function loadPrivateChatProtection() {
  const data = await request('/api/message_protection.php');
  renderPrivateChatProtection(data);
}

document.getElementById('account-private-chat-device-create')?.addEventListener('click', async () => {
  try { await protectionRegisterDevice(); } catch (error) { showStatus(error.message, true); }
});

document.getElementById('account-private-chat-recovery-create')?.addEventListener('click', async () => {
  try { await protectionFinishRecovery(); } catch (error) { showStatus(error.message, true); }
});

Promise.all([request('/api/account.php'), moderationProjection(), loadPrivateChatProtection()])
  .then(([data, safety]) => { render(data); renderSafety(safety); })
  .catch(error => showStatus(error.message, true));
