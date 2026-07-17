'use strict';

const APP_BASE = document.body?.dataset.appBase || '';
const CSRF_TOKEN = document.body?.dataset.csrf || '';
const IS_ADMIN = document.body?.dataset.isAdmin === 'true';
const appUrl = path => `${APP_BASE}${path}`;
const statusEl = document.getElementById('admin-page-status');
let settings = {};
let selectedIssueId = null;

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
}

function showStatus(message, error = false) {
  statusEl.textContent = message || '';
  statusEl.classList.toggle('error', error);
}

async function request(path, options = {}) {
  const response = await fetch(appUrl(path), { credentials: 'same-origin', ...options });
  const type = String(response.headers.get('content-type') || '');
  const data = type.includes('application/json') ? await response.json().catch(() => ({})) : {};
  if (!response.ok || data.error) throw new Error(data.error || 'Admin request failed.');
  return data;
}

function post(path, body) {
  return request(path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, body: JSON.stringify({ ...body, _csrf: CSRF_TOKEN }) });
}

function activateTab(name) {
  document.querySelectorAll('[data-admin-tab]').forEach(button => button.classList.toggle('active', button.dataset.adminTab === name));
  document.querySelectorAll('[data-admin-panel]').forEach(panel => panel.classList.toggle('active', panel.dataset.adminPanel === name));
}
document.querySelectorAll('[data-admin-tab]').forEach(button => button.addEventListener('click', () => activateTab(button.dataset.adminTab)));

async function loadIssues() {
  const filter = document.getElementById('issue-status-filter').value;
  const data = await request(`/api/runtime_issues.php?action=list${filter ? `&status=${encodeURIComponent(filter)}` : ''}`);
  const list = document.getElementById('issue-list');
  list.textContent = '';
  document.getElementById('issue-count').textContent = String((data.issues || []).length);
  for (const issue of data.issues || []) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'issue-list-item';
    button.innerHTML = `<strong>${esc(issue.title)}</strong><span>${esc(issue.component)} · ${esc(issue.status)}</span><small>${issue.occurrenceCount} occurrence${issue.occurrenceCount === 1 ? '' : 's'}</small>`;
    button.addEventListener('click', () => loadIssueDetail(issue.id));
    list.appendChild(button);
  }
  if (!list.children.length) list.innerHTML = '<p class="minor">No matching issues.</p>';
}

async function loadIssueDetail(issueId) {
  selectedIssueId = issueId;
  const data = await request(`/api/runtime_issues.php?action=detail&issue_id=${encodeURIComponent(issueId)}`);
  const issue = data.issue;
  const detail = document.getElementById('issue-detail');
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
    figure.querySelector('button').addEventListener('click', async () => { await post('/api/runtime_issues.php', { action: 'delete_screenshot', id: screenshot.publicId }); await loadIssueDetail(issueId); });
    screenshots.appendChild(figure);
  }
  if (!screenshots.children.length) screenshots.innerHTML = '<p class="minor">No screenshots.</p>';
  detail.querySelector('#issue-status-form').addEventListener('submit', async event => {
    event.preventDefault(); const form = event.currentTarget;
    try { await post('/api/runtime_issues.php', { action: 'update_status', issue_id: issueId, status: form.elements.status.value, reason: form.elements.reason.value, verification_reference: form.elements.verification_reference.value }); await Promise.all([loadIssues(), loadIssueDetail(issueId)]); showStatus('Issue status updated.'); } catch (error) { showStatus(error.message, true); }
  });
  const getBundle = () => request(`/api/runtime_issues.php?action=bundle&issue_id=${encodeURIComponent(issueId)}`);
  detail.querySelector('#issue-bundle-preview').addEventListener('click', async () => { const bundle = (await getBundle()).bundle; const pre = detail.querySelector('#issue-bundle'); pre.hidden = false; pre.textContent = JSON.stringify(bundle, null, 2); });
  detail.querySelector('#issue-bundle-export').addEventListener('click', async () => { const bundle = (await getBundle()).bundle; const url = URL.createObjectURL(new Blob([JSON.stringify(bundle, null, 2)], { type: 'application/json' })); const anchor = document.createElement('a'); anchor.href = url; anchor.download = `chatspace-issue-${issueId}.json`; anchor.click(); URL.revokeObjectURL(url); });
}
document.getElementById('issue-status-filter').addEventListener('change', loadIssues);

async function loadUsers() {
  const data = await request('/api/admin_users.php');
  const list = document.getElementById('admin-user-list'); list.textContent = '';
  for (const user of data.users || []) {
    const form = document.createElement('form'); form.className = 'admin-user-row';
    form.innerHTML = `<div><strong>${esc(user.display_name)}</strong><div class="minor">${esc(user.email)}</div></div><select name="role">${['user','guide','developer','admin'].map(role => `<option value="${role}"${role === user.role ? ' selected' : ''}>${role}</option>`).join('')}</select><input name="password" type="password" placeholder="New password"><button class="btn btn-primary" type="submit">Save</button><button class="btn btn-danger" type="button">Delete</button>`;
    form.addEventListener('submit', async event => { event.preventDefault(); try { await post('/api/admin_users.php', { action: 'update', id: user.id, role: form.elements.role.value, password: form.elements.password.value }); form.elements.password.value = ''; showStatus('User updated.'); await loadLogs(); } catch (error) { showStatus(error.message, true); } });
    form.querySelector('.btn-danger').addEventListener('click', async () => { if (confirm(`Delete ${user.display_name}?`)) { await post('/api/admin_users.php', { action: 'delete', id: user.id }); await loadUsers(); } });
    list.appendChild(form);
  }
}
document.getElementById('admin-user-create').addEventListener('submit', async event => { event.preventDefault(); const form = event.currentTarget; try { await post('/api/admin_users.php', { action: 'create', display_name: form.elements.display_name.value, email: form.elements.email.value, password: form.elements.password.value, role: form.elements.role.value }); form.reset(); await loadUsers(); showStatus('User created.'); } catch (error) { showStatus(error.message, true); } });

async function loadSettings() {
  const data = await request('/api/admin_system.php?action=settings'); settings = data.settings || {};
  for (const [name, value] of Object.entries(settings)) {
    for (const form of [document.getElementById('admin-settings-form'), document.getElementById('role-color-form'), document.getElementById('diagnostic-screenshot-form')]) {
      const input = form?.elements?.[name]; if (!input) continue;
      if (input.type === 'checkbox') input.checked = value === '1'; else input.value = value;
    }
  }
  applyRoleColors();
}

document.getElementById('admin-settings-form').addEventListener('submit', async event => {
  event.preventDefault(); const form = event.currentTarget; const body = { action: 'save_settings' };
  for (const element of form.elements) if (element.name) body[element.name] = element.type === 'checkbox' ? (element.checked ? 1 : 0) : element.value;
  try { const data = await post('/api/admin_system.php', body); settings = data.settings || settings; showStatus('Settings saved.'); } catch (error) { showStatus(error.message, true); }
});
document.getElementById('reset-size-policy').addEventListener('click', async () => { try { await post('/api/admin_system.php', { action: 'reset_avatar_size_policy' }); await loadSettings(); showStatus('Display size defaults restored.'); } catch (error) { showStatus(error.message, true); } });

function roleColorBody(action) { const form = document.getElementById('role-color-form'); const body = { action }; for (const element of form.elements) if (element.name) body[element.name] = element.value; return body; }
function applyRoleColors() { for (const role of ['admin','developer','guide','owner','user']) { document.documentElement.style.setProperty(`--role-${role}-bg`, settings[`role_color_${role}_bg`] || ''); document.documentElement.style.setProperty(`--role-${role}-text`, settings[`role_color_${role}_text`] || ''); } document.body.dataset.roleColorsMode = settings.role_colors_mode || 'enabled'; }
const appearanceControls = document.querySelectorAll('#role-color-form input, #role-color-form select, #role-color-form button, #diagnostic-screenshot-form input, #diagnostic-screenshot-form button');
if (!IS_ADMIN) appearanceControls.forEach(control => { control.disabled = true; });
document.getElementById('role-color-form').addEventListener('submit', async event => { event.preventDefault(); try { const data = await post('/api/admin_system.php', roleColorBody('save_role_colors')); settings = data.settings; applyRoleColors(); showStatus('Role colors saved.'); } catch (error) { showStatus(error.message, true); } });
document.getElementById('reset-role-colors').addEventListener('click', async () => { try { const data = await post('/api/admin_system.php', { action: 'reset_role_colors' }); settings = data.settings; await loadSettings(); showStatus('Role colors reset.'); } catch (error) { showStatus(error.message, true); } });
document.getElementById('diagnostic-screenshot-form').addEventListener('submit', async event => { event.preventDefault(); const form = event.currentTarget; try { const data = await post('/api/admin_system.php', { action: 'save_diagnostic_screenshots', diagnostic_screenshots_enabled: form.elements.diagnostic_screenshots_enabled.checked ? 1 : 0, diagnostic_screenshot_retention_days: form.elements.diagnostic_screenshot_retention_days.value }); settings = data.settings; showStatus('Screenshot policy saved.'); } catch (error) { showStatus(error.message, true); } });

async function loadLogs() { const data = await request('/api/admin_system.php?action=logs'); document.getElementById('admin-tool-logs').innerHTML = (data.logs || []).map(log => `<div class="admin-list-row"><div><strong>${esc(log.action)} · ${esc(log.actor_name)}</strong><div class="minor">${esc(log.created_at)} · ${esc(log.detail || '')}</div></div></div>`).join('') || '<p class="minor">No tool logs.</p>'; }
async function moderationList(action, elementId, undoAction) { const data = await request(`/api/admin_system.php?action=${action}`); const rows = data.blocks || data.ejections || []; const element = document.getElementById(elementId); element.textContent = ''; for (const row of rows) { const item = document.createElement('div'); item.className = 'admin-list-row'; const label = row.blocker_name ? `${row.blocker_name} blocked ${row.blocked_name}` : `${row.display_name}${row.room_name ? ` · ${row.room_name}` : ''}`; item.innerHTML = `<span>${esc(label)}</span><button class="btn btn-danger" type="button">Undo</button>`; item.querySelector('button').addEventListener('click', async () => { const body = row.blocker_name ? { action: undoAction, blocker_user_id: row.blocker_user_id, blocked_user_id: row.blocked_user_id } : { action: undoAction, id: row.id }; await post('/api/admin_system.php', body); await loadModeration(); }); element.appendChild(item); } if (!rows.length) element.innerHTML = '<p class="minor">None active.</p>'; }
async function loadModeration() { await Promise.all([moderationList('blocks','admin-blocks','remove_block'), moderationList('room_ejections','admin-room-ejections','undo_room_ejection'), moderationList('community_ejections','admin-community-ejections','undo_community_ejection')]); }

async function loadLinkIcons() { const data = await request('/api/admin_link_icons.php'); const list = document.getElementById('link-icon-list'); list.textContent = ''; for (const icon of data.icons || []) { const form = document.createElement('form'); form.className = 'admin-link-icon-row'; form.innerHTML = `<img src="${esc(appUrl(icon.file_path))}" alt=""><input name="label" value="${esc(icon.label)}" required><button class="btn btn-primary" type="submit">Save</button><button class="btn btn-danger" type="button"${icon.built_in ? ' disabled' : ''}>Delete</button>`; form.addEventListener('submit', async event => { event.preventDefault(); const body = new FormData(); body.append('_csrf', CSRF_TOKEN); body.append('action','update'); body.append('icon_name',icon.icon_name); body.append('label',form.elements.label.value); await request('/api/admin_link_icons.php',{method:'POST',headers:{'X-CSRF-Token':CSRF_TOKEN},body}); showStatus('Icon updated.'); }); form.querySelector('.btn-danger').addEventListener('click', async () => { const body = new FormData(); body.append('_csrf',CSRF_TOKEN); body.append('action','delete'); body.append('icon_name',icon.icon_name); await request('/api/admin_link_icons.php',{method:'POST',headers:{'X-CSRF-Token':CSRF_TOKEN},body}); await loadLinkIcons(); }); list.appendChild(form); } }
document.getElementById('link-icon-create').addEventListener('submit', async event => { event.preventDefault(); const form = event.currentTarget; const body = new FormData(form); body.append('_csrf',CSRF_TOKEN); body.append('action','create'); try { await request('/api/admin_link_icons.php',{method:'POST',headers:{'X-CSRF-Token':CSRF_TOKEN},body}); form.reset(); await loadLinkIcons(); showStatus('Icon added.'); } catch(error) { showStatus(error.message,true); } });

document.getElementById('admin-database-import').addEventListener('submit', async event => { event.preventDefault(); if (!confirm('Import this database or portable bundle?')) return; const body = new FormData(event.currentTarget); body.append('_csrf',CSRF_TOKEN); try { await request('/api/admin_database.php',{method:'POST',headers:{'X-CSRF-Token':CSRF_TOKEN},body}); showStatus('Import complete. Reloading…'); location.reload(); } catch(error) { showStatus(error.message,true); } });

Promise.all([loadIssues(), loadUsers(), loadSettings(), loadLogs(), loadModeration(), loadLinkIcons()]).catch(error => showStatus(error.message, true));
