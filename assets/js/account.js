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

function render(data) {
  account = data;
  const profile = data.profile || {};
  const security = data.security || {};
  const state = data.status || {};
  const profileForm = document.getElementById('account-profile-form');
  profileForm.elements.username.value = profile.username || '';
  profileForm.elements.display_name.value = profile.displayName || '';
  profileForm.elements.location.value = profile.location || '';
  profileForm.elements.about.value = profile.about || '';
  profileForm.elements.visibility.value = profile.visibility || 'community';
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
  try {
    showStatus('Saving profile…');
    const data = await post('/api/account.php', { action: 'update_profile', username: form.elements.username.value, display_name: form.elements.display_name.value, location: form.elements.location.value, about: form.elements.about.value, visibility: form.elements.visibility.value });
    render(data); showStatus('Profile saved.');
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

request('/api/account.php').then(render).catch(error => showStatus(error.message, true));
