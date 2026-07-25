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
  const profileForm = document.getElementById('account-profile-form');
  profileForm.elements.username.value = profile.username || '';
  profileForm.elements.display_name.value = profile.displayName || '';
  profileForm.elements.name.value = profile.name || '';
  profileForm.elements.location.value = profile.location || '';
  profileForm.elements.about_me.value = profile.aboutMe || '';
  profileForm.elements.public_contact_email.value = profile.publicContactEmail || '';
  profileForm.elements.website.value = profile.website || '';
  profileForm.elements.interests.value = profile.interests || '';
  profileForm.dataset.profileVersion = String(profile.profileVersion || 1);
  document.getElementById('account-profile-display-fallback').textContent = profile.displayName
    ? 'This public Display name is used throughout ordinary chat.'
    : `Not set — shown as ${profile.username || 'Username'}`;
  document.getElementById('account-profile-registered').textContent = profile.registeredAt || '';
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
    <dt>Temporary restriction</dt><dd>${restriction ? escapeHtml(restriction.permanent ? 'Permanent' : `Until ${restriction.expiresAt || 'reviewed'}`) : 'None'}</dd>
    <dt>Policy</dt><dd>${escapeHtml(state.trustPolicyNote || '')}</dd>`;
  document.getElementById('account-capabilities').innerHTML = (state.capabilities || []).map(value => `<span>${escapeHtml(String(value).replaceAll('_', ' '))}</span>`).join('');
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
}

document.querySelectorAll('[data-account-tab]').forEach(button => button.addEventListener('click', () => {
  document.querySelectorAll('[data-account-tab]').forEach(item => item.classList.toggle('active', item === button));
  document.querySelectorAll('[data-account-panel]').forEach(panel => panel.classList.toggle('active', panel.dataset.accountPanel === button.dataset.accountTab));
}));

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

request('/api/account.php').then(render).catch(error => showStatus(error.message, true));
