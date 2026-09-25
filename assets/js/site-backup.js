const appUrl = path => `${document.body?.dataset.appBase || ''}${path}`;

const panel = document.getElementById('site-backup-controls');
if (panel) {
  const exportForm = panel.querySelector('#site-backup-export');
  const importForm = panel.querySelector('#site-backup-import');
  const status = panel.querySelector('[data-backup-status]');
  const preview = panel.querySelector('[data-backup-preview]');
  const apply = panel.querySelector('[data-backup-apply]');
  let token = '';
  const invalidate = () => { token = ''; apply.hidden = true; preview.replaceChildren(); };
  importForm.addEventListener('change', event => {
    invalidate();
    if (event.target.name === 'archive') { const choices = importForm.querySelector('[data-import-sections]'); choices.querySelectorAll('label,input').forEach(el => el.remove()); choices.hidden = true; }
  });
  importForm.addEventListener('input', invalidate);
  const setStatus = text => { status.textContent = text; };
  exportForm.setAttribute('action', appUrl('/api/site_backup.php'));
  exportForm.querySelector('[name=mode]').addEventListener('change', () => {
    exportForm.querySelector('[data-export-sections]').hidden = exportForm.elements.mode.value === 'complete';
  });
  // Use a regular POST download: the password never enters a URL or browser history.
  exportForm.addEventListener('submit', async event => {
    event.preventDefault(); const button = exportForm.querySelector('[type=submit]'); button.disabled = true;
    try {
      setStatus('Preparing your encrypted backup…');
      const response = await fetch(appUrl('/api/site_backup.php'), { method: 'POST', body: new FormData(exportForm), credentials: 'same-origin' });
      if (!response.ok) { const data = await response.json(); throw new Error(data.error || 'Backup failed.'); }
      if (!response.headers.get('Content-Disposition')?.includes('attachment')) throw new Error('Sign in again before downloading a backup.');
      const blob = await response.blob(); const url = URL.createObjectURL(blob); const link = document.createElement('a');
      link.href = url; link.download = response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || 'CoreChat.corechat';
      link.click(); setTimeout(() => URL.revokeObjectURL(url), 30000); setStatus('Backup downloaded. Keep its password separately.');
    } catch (error) { setStatus(error.message); } finally { button.disabled = false; }
  });
  async function request(action) {
    const fd = new FormData(importForm); fd.set('action', action);
    if (action === 'apply') fd.set('token', token);
    const buttons = importForm.querySelectorAll('button'); buttons.forEach(b => { b.disabled = true; });
    try {
      setStatus(action === 'preview' ? 'Checking the archive, dependencies and destination…' : 'Creating a recovery backup and importing… Keep this window open.');
      const response = await fetch(appUrl('/api/site_backup.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await response.json();
      if (action === 'preview') {
        invalidate();
        const choices = importForm.querySelector('[data-import-sections]');
        if (data.mode === 'selective' && Array.isArray(data.groups) && !choices.querySelector('input')) {
          choices.hidden = false;
          const explicit = document.createElement('input'); explicit.type = 'hidden'; explicit.name = 'select_sections'; explicit.value = '1'; choices.append(explicit);
          for (const group of data.groups) {
            const label = document.createElement('label'); label.className = 'admin-export-choice';
            const box = document.createElement('input'); box.type = 'checkbox'; box.name = 'sections[]'; box.value = group; box.checked = true;
            const original = exportForm.querySelector(`input[value="${CSS.escape(group)}"]`);
            label.append(box, document.createTextNode(original?.parentElement?.textContent || group)); choices.append(label);
          }
        }
        if (!response.ok || data.error) throw new Error(data.error || 'Backup request failed.');
        token = data.token;
        const s = data.summary;
        for (const text of [
          `${s.mode === 'complete' ? 'Complete restore' : 'Selected content'}: ${s.sections.join(', ')}`,
          `Records: ${s.add} to add, ${s.update} to update, ${s.skip} to keep unchanged.`,
          `Files: ${s.filesAdd} to add, ${s.filesReplace} to replace, ${s.filesSkip} already match.`,
          ...(s.warnings || []),
          'A complete recovery backup will be kept privately on this server, encrypted with the password entered above.'
        ]) { const p = document.createElement('p'); p.textContent = text; preview.append(p); }
        apply.hidden = false; apply.textContent = data.mode === 'complete' ? 'Restore complete backup' : 'Import selected content';
        setStatus('Preview ready. Nothing has been imported.');
      } else {
        if (!response.ok || data.error) throw new Error(data.error || 'Backup request failed.');
        invalidate(); setStatus(data.signInRequired ? 'Restore complete. Sign in again to use the restored installation.' : 'Import complete. Existing login credentials were preserved.');
        const recovery = document.createElement('form'); recovery.method = 'post'; recovery.action = appUrl('/api/site_backup.php');
        for (const [name, value] of Object.entries({ action: 'recovery_download', recovery: data.recovery, _csrf: importForm.elements._csrf.value })) {
          const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; recovery.append(input);
        }
        if (!data.signInRequired) { const button = document.createElement('button'); button.className = 'btn'; button.textContent = 'Download pre-import recovery backup'; recovery.append(button); preview.append(recovery); }
        if (data.signInRequired) { const link = document.createElement('a'); link.href = appUrl('/login.php'); link.textContent = 'Sign in'; preview.append(link); }
      }
    } catch (error) { setStatus(error.message || 'Backup request failed.'); }
    finally { buttons.forEach(b => { b.disabled = false; }); }
  }
  importForm.addEventListener('submit', event => { event.preventDefault(); if (importForm.reportValidity()) request('preview'); });
  panel.querySelector('[data-recovery-list]').addEventListener('click', async () => {
    try {
      const fd = new FormData(); fd.set('action', 'recovery_list'); fd.set('_csrf', importForm.elements._csrf.value);
      const response = await fetch(appUrl('/api/site_backup.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await response.json(); if (!response.ok || data.error) throw new Error(data.error || 'Cannot list backups.');
      const holder = panel.querySelector('[data-recovery-items]'); holder.replaceChildren();
      for (const item of data.items) {
        const form = document.createElement('form'); form.method = 'POST'; form.action = appUrl('/api/site_backup.php');
        for (const [name, value] of Object.entries({ action: 'recovery_download', recovery: item.id, _csrf: importForm.elements._csrf.value })) {
          const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
        }
        const button = document.createElement('button'); button.className = 'btn'; button.textContent = `${item.createdAt} — ${(item.bytes / 1048576).toFixed(1)} MB`; form.append(button); holder.append(form);
      }
      if (!data.items.length) holder.textContent = 'No pre-import recovery backups yet.';
    } catch (error) { setStatus(error.message); }
  });
  apply.addEventListener('click', () => { if (token && importForm.reportValidity()) request('apply'); });
}
