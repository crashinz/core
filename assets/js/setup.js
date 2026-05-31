'use strict';

const dbRadios = document.querySelectorAll('.setup-db-radio');
const mysqlFields = document.getElementById('setup-mysql-fields');
const CSRF_TOKEN = document.body.dataset.csrf || '';
const APP_BASE = document.body.dataset.appBase || '';

function appUrl(path) {
  if (!path) return APP_BASE || '/';
  if (/^(?:https?:)?\/\//.test(path) || path.startsWith('data:') || path.startsWith('blob:')) return path;
  if (!path.startsWith('/')) return path;
  if (APP_BASE && path.startsWith(`${APP_BASE}/`)) return path;
  return `${APP_BASE}${path}`;
}

function updateSetupDbChoice() {
  const selected = document.querySelector('.setup-db-radio:checked')?.value || 'sqlite';
  if (mysqlFields) mysqlFields.hidden = selected !== 'mysql';
  document.querySelectorAll('.setup-choice-card').forEach(card => {
    const input = card.closest('.setup-choice')?.querySelector('.setup-db-radio');
    card.classList.toggle('active', input?.checked);
  });
}

dbRadios.forEach(radio => radio.addEventListener('change', updateSetupDbChoice));
updateSetupDbChoice();

function setSetupError(message) {
  let box = document.querySelector('.setup-alert-error');
  if (!box) {
    box = document.createElement('div');
    box.className = 'setup-alert setup-alert-error';
    document.querySelector('.setup-steps')?.after(box);
  }
  box.textContent = message;
}

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

function uploadSetupBackup(form, progressEl) {
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
    xhr.open('POST', appUrl('/setup.php'));
    xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(formData);
  });
}

document.querySelectorAll('[data-setup-mode]').forEach(btn => {
  btn.addEventListener('click', () => {
    const mode = btn.dataset.setupMode || 'create';
    document.querySelectorAll('[data-setup-mode]').forEach(item => item.classList.toggle('active', item === btn));
    document.querySelectorAll('.setup-mode-panel').forEach(panel => panel.classList.toggle('active', panel.id === `setup-mode-${mode}`));
  });
});

const setupAvatar = document.getElementById('setup-avatar');
const setupAvatarName = document.getElementById('setup-avatar-name');
setupAvatar?.addEventListener('change', () => {
  const file = setupAvatar.files && setupAvatar.files[0];
  if (setupAvatarName) setupAvatarName.textContent = file ? file.name : 'No file selected';
});

const setupCommunityLogo = document.getElementById('setup-community-logo');
const setupCommunityLogoName = document.getElementById('setup-community-logo-name');
setupCommunityLogo?.addEventListener('change', () => {
  const file = setupCommunityLogo.files && setupCommunityLogo.files[0];
  if (setupCommunityLogoName) setupCommunityLogoName.textContent = file ? file.name : 'No file selected';
});

const setupAdminForm = setupAvatar?.closest('form');
setupAdminForm?.addEventListener('submit', async event => {
  const file = setupAvatar?.files?.[0];
  if (!file || !window.ChatSpaceAvatar) return;
  event.preventDefault();
  const submit = setupAdminForm.querySelector('button[type="submit"]');
  const originalText = submit?.textContent || '';
  if (submit) {
    submit.disabled = true;
    submit.textContent = 'Optimizing...';
  }
  try {
    const prepared = await window.ChatSpaceAvatar.prepareAvatarFile(file);
    window.ChatSpaceAvatar.replaceInputFile(setupAvatar, prepared);
    if (setupAvatarName) setupAvatarName.textContent = prepared.name;
    HTMLFormElement.prototype.submit.call(setupAdminForm);
  } catch (err) {
    let box = document.querySelector('.setup-alert-error');
    if (!box) {
      box = document.createElement('div');
      box.className = 'setup-alert setup-alert-error';
      document.querySelector('.setup-steps')?.after(box);
    }
    box.textContent = err.message || 'Could not prepare avatar.';
    if (submit) {
      submit.disabled = false;
      submit.textContent = originalText;
    }
  }
});

const setupBackupFile = document.getElementById('setup-backup-file');
const setupBackupName = document.getElementById('setup-backup-name');
const setupRestoreForm = document.getElementById('setup-restore-form');
const setupBackupProgress = document.getElementById('setup-backup-progress');

setupBackupFile?.addEventListener('change', () => {
  const file = setupBackupFile.files && setupBackupFile.files[0];
  if (setupBackupName) setupBackupName.textContent = file ? file.name : 'No file selected';
  resetUploadProgress(setupBackupProgress);
});

setupRestoreForm?.addEventListener('submit', async event => {
  event.preventDefault();
  try {
    const xhr = await uploadSetupBackup(setupRestoreForm, setupBackupProgress);
    const data = JSON.parse(xhr.responseText || '{}');
    if (data.error) throw new Error(data.error);
    setUploadProgress(setupBackupProgress, 100, 'Import complete.');
    window.location.href = data.redirect || appUrl('/setup.php?done=1&restored=1');
  } catch (err) {
    let message = err.message || 'Backup import failed.';
    try {
      const parsed = JSON.parse(message);
      if (parsed.error) message = parsed.error;
    } catch (_err) {}
    setUploadProgress(setupBackupProgress, 100, 'Import failed.');
    setSetupError(message);
  }
});
