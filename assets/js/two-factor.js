(() => {
  'use strict';
  const base = document.body.dataset.appBase || '';
  const csrf = document.body.dataset.csrf || '';
  const status = document.getElementById('two-factor-status');
  if (!status) return;
  let state, dialog, contents, message, pending = false, codesText = '', codeList = [], mode = '';
  const el = (tag, text, attrs = {}) => {
    const node = document.createElement(tag); if (text) node.textContent = text;
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    return node;
  };
  async function request(body) {
    const response = await fetch(`${base}/api/two_factor.php`, body ? {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(body),
    } : {credentials: 'same-origin', cache: 'no-store'});
    const data = await response.json();
    if (!response.ok || data.error) throw new Error(data.error || 'Security request failed.');
    return data;
  }
  function render(data) {
    state = data;
    const emailStatus = document.getElementById('two-factor-email-status');
    emailStatus.textContent = data.recoveryPending
      ? `Email recovery is pending until ${new Date(data.recoveryReadyAt * 1000).toLocaleString()}. Cancel it if you did not request it.`
      : data.emailRecoveryEligible ? 'Delayed email recovery is available if you lose your authenticator. Normal authenticator/backup-code disabling still works.'
      : data.emailRecoveryEnrolled && !data.emailVerified ? 'Verify your current private account email to restore email recovery. Your authenticator and backup codes still work.'
      : !data.enabled && data.enrollmentEmailRequired ? 'This host requires a verified private account email for new 2FA enrollments. Keep access to it for optional lost-authenticator recovery. Normal authenticator/backup-code disabling still works.'
      : data.enabled ? 'This enrollment keeps its original recovery methods. Email recovery was not enabled for it.' : '';
    document.getElementById('two-factor-email-verify').hidden = !data.mailConfigured || data.emailVerified;
    document.getElementById('two-factor-email-cancel').hidden = !data.recoveryPending;
    status.textContent = !data.available ? 'The host must update its database before setting up 2FA.'
      : data.enabled ? `Enabled · ${data.backupCodesRemaining} backup codes remaining.` : 'Off · Two-factor authentication is optional.';
    document.getElementById('two-factor-setup').hidden = data.enabled;
    document.getElementById('two-factor-setup').disabled = !data.available;
    for (const id of ['two-factor-backup', 'two-factor-disable']) document.getElementById(id).hidden = !data.enabled;
    document.querySelectorAll('[data-two-factor-field]').forEach(label => {
      label.hidden = !data.enabled; label.querySelector('input').required = !!data.enabled;
    });
  }
  function showMessage(text, error = false) { message.textContent = text; message.classList.toggle('error', error); }
  function makeDialog() {
    if (dialog) return;
    dialog = el('dialog', '', {class: 'two-factor-dialog', id: 'two-factor-dialog', 'aria-labelledby': 'two-factor-title'});
    const header = el('div', '', {class: 'cc-popup-header'});
    header.append(el('h2', 'Two-factor authentication', {id: 'two-factor-title'}));
    const close = el('button', '×', {type:'button', class:'cc-popup-close', 'data-close':'', 'aria-label':'Close'});
    close.addEventListener('click', () => { if (!pending) dialog.close(); }); header.append(close);
    contents = el('div'); message = el('p', '', {class:'two-factor-message', role:'status', 'aria-live':'polite'});
    dialog.append(header, contents, message); document.body.append(dialog);
    // A dragged dialog must stay in bounds when the next setup step becomes taller.
    new ResizeObserver(() => window.CoreChatPopups?.reflow?.(dialog)).observe(dialog);
    dialog.addEventListener('close', () => {
      dialog.querySelectorAll('input[type=password],input[autocomplete=one-time-code]').forEach(input => input.value = '');
    });
    // The shared observer supplies drag, sticky header, outside/Escape close, focus and clamping.
  }
  function open() { makeDialog(); if (!dialog.open) dialog.showModal(); }
  function title(text) { document.getElementById('two-factor-title').textContent = text; showMessage(''); }
  function field(form, text, attrs) {
    const label = el('label', text), input = el('input', '', attrs); label.append(input); form.append(label); return input;
  }
  function button(text, action, parent, primary = false) {
    const b = el('button', text, {type:'button', class:`btn${primary ? ' btn-primary' : ''}`}); b.addEventListener('click', action); parent.append(b); return b;
  }
  function actions(parent) { const row = el('div', '', {class:'two-factor-actions'}); parent.append(row); return row; }
  async function perform(action) {
    if (pending) return;
    pending = true; dialog.dataset.popupBusy = 'true';
    const buttons = [...dialog.querySelectorAll('button')]; buttons.forEach(b => b.disabled = true);
    try { await action(); }
    catch (error) { showMessage(error.message, true); }
    finally { pending = false; dialog.dataset.popupBusy = 'false'; buttons.forEach(b => b.disabled = false); }
  }
  function credentials(action) {
    if (action === 'begin' && state?.enrollmentEmailRequired && !state.emailVerified) { emailVerification(); return; }
    open(); mode = action;
    title(action === 'begin' ? 'Set up two-factor authentication' : action === 'disable' ? 'Disable two-factor authentication' : 'Replace backup codes');
    contents.replaceChildren();
    const form = el('form'); contents.append(form);
    form.append(el('p', action === 'begin' ? 'Confirm your password to start. 2FA stays off until you verify a code from your authenticator.'
      : action === 'disable' ? 'This removes the authenticator requirement and invalidates all 2FA backup codes. Confirm your password and an authenticator or backup code.'
      : 'This invalidates all old backup codes and creates ten new ones. Confirm your password and an authenticator or backup code.'));
    const password = field(form, 'Current password', {name:'password', type:'password', autocomplete:'current-password', required:''});
    const code = action === 'begin' ? null : field(form, 'Authenticator or unused backup code', {name:'code', autocomplete:'one-time-code', maxlength:'32', required:'', spellcheck:'false'});
    const row = actions(form);
    row.append(el('button', action === 'begin' ? 'Continue' : action === 'disable' ? 'Disable 2FA' : 'Create new backup codes', {class:'btn btn-primary', type:'submit'}));
    button(action === 'begin' ? 'Skip for now' : 'Cancel', () => dialog.close(), row);
    form.addEventListener('submit', event => {
      event.preventDefault(); if (!form.reportValidity()) return;
      perform(async () => {
        const body = {action, password:password.value, code:code?.value || ''}; password.value = ''; if (code) code.value = '';
        const result = await request(body);
        if (action === 'begin') enrollment(result);
        else { render(result); if (action === 'backup_codes') backupCodes(result.backupCodes); else {
          codesText = ''; codeList = []; document.getElementById('two-factor-view-codes').hidden = true;
          dialog.close(); status.textContent = '2FA disabled. Authenticator and backup codes are no longer accepted.';
        } }
      });
    }); password.focus();
  }
  function emailVerification() {
    open(); mode = 'email'; title('Verify your private account email'); contents.replaceChildren();
    contents.append(el('p', 'A code will be sent to the private email in Account Security. Verify it before enabling 2FA with email recovery. If you change that address, verify the new address again.'));
    const form = el('form'); contents.append(form);
    const password = field(form, 'Current password', {type:'password',name:'password',autocomplete:'current-password',required:''});
    const code = field(form, 'Email verification code', {name:'email_code',autocomplete:'one-time-code',maxlength:'32',spellcheck:'false'});
    const row = actions(form);
    button('Send verification email', () => {
      if (!password.reportValidity()) return;
      perform(async () => { await request({action:'email_send',password:password.value}); showMessage('Email sent. Enter its code within 15 minutes. One request per 24 hours.'); });
    }, row);
    row.append(el('button','Verify email',{type:'submit',class:'btn btn-primary'}));
    button('Cancel',()=>dialog.close(),row);
    form.addEventListener('submit', e => {
      e.preventDefault(); if (!form.reportValidity() || !code.value.trim()) { showMessage('Enter the code from your email.',true); return; }
      perform(async () => { const data=await request({action:'email_confirm',password:password.value,code:code.value}); password.value='';code.value='';render(data);dialog.close();status.textContent='Email verified. You can now set up 2FA.'; });
    }); password.focus();
  }
  async function copy(text, input) {
    try { await navigator.clipboard.writeText(text); showMessage('Copied.'); }
    catch { input.focus(); input.select(); showMessage('Selected. Press Ctrl+C to copy.'); }
  }
  function enrollment(result) {
    mode = 'enrollment'; title('Connect your authenticator'); contents.replaceChildren();
    contents.append(el('p', 'In Aegis, add an account and scan this QR code, or enter the setup key manually. Use Time-based (TOTP), SHA-1, 6 digits, 30 seconds.'));
    try {
      const qr = qrcodegen.QrCode.encodeText(result.uri, qrcodegen.QrCode.Ecc.MEDIUM);
      const canvas = el('canvas', '', {'aria-label':'Scan this QR code in your authenticator', role:'img'}), scale = 5, border = 4;
      canvas.width = canvas.height = (qr.size + border * 2) * scale;
      const ctx = canvas.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0,0,canvas.width,canvas.height); ctx.fillStyle = '#000';
      for (let y=0;y<qr.size;y++) for(let x=0;x<qr.size;x++) if(qr.getModule(x,y)) ctx.fillRect((x+border)*scale,(y+border)*scale,scale,scale);
      contents.append(canvas);
    } catch { contents.append(el('p', 'QR code could not be displayed. Use the setup key below.')); }
    const key = field(contents, 'Setup key', {class:'two-factor-secret', readonly:'', 'aria-label':'Setup key'}); key.value = result.secret;
    button('Copy setup key', () => copy(result.secret, key), contents);
    const form = el('form'); contents.append(form);
    const code = field(form, 'Six-digit authenticator code', {name:'code', autocomplete:'one-time-code', inputmode:'numeric', pattern:'[0-9]{6}', maxlength:'6', required:''});
    const row = actions(form); row.append(el('button', 'Verify and enable 2FA', {type:'submit', class:'btn btn-primary'}));
    button('Skip for now', () => dialog.close(), row);
    button('Start setup again', () => credentials('begin'), row);
    form.addEventListener('submit', e => {
      e.preventDefault(); if (!form.reportValidity()) return;
      perform(async () => { const value = code.value; code.value = ''; const data = await request({action:'activate', code:value}); render(data); backupCodes(data.backupCodes); });
    });
  }
  function backupCodes(codes) {
    if (codes) codeList = codes;
    if (codes) codesText = `CoreChat two-factor authentication backup codes\nWebsite: ${location.origin}${base}/\nAccount: ${document.getElementById('account-two-factor').dataset.username || ''}\nCreated: ${new Date().toISOString()}\n\n${codes.join('\n')}\n\nEach code can be used once with your password. Keep this file private, preferably separately from your password. Replacing these codes invalidates this list. These are not your Private Chat Recovery Phrase.\n`;
    mode = 'codes'; open(); title('Save your backup codes'); contents.replaceChildren();
    contents.append(el('p', 'Keep these codes somewhere safe in case you lose access to your authenticator. Each code works once. This list remains available until you leave or reload this page; later you can create a replacement list.'));
    const text = el('textarea', '', {readonly:'', 'aria-label':'Backup codes', rows:'10', spellcheck:'false'}); text.value = codeList.join('\n'); contents.append(text);
    const row = actions(contents);
    button('Download backup codes (.txt)', () => {
      const url = URL.createObjectURL(new Blob([codesText], {type:'text/plain;charset=utf-8'}));
      const a = el('a', '', {href:url, download:'CoreChat-2FA-backup-codes.txt'}); dialog.append(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url), 60000);
    }, row, true);
    button('Copy backup codes', () => copy(codesText, text), row);
    button('Done', () => dialog.close(), row);
    document.getElementById('two-factor-view-codes').hidden = false;
  }
  document.getElementById('two-factor-setup').addEventListener('click', () => {
    if (mode === 'enrollment' && dialog) open(); else credentials('begin');
  });
  document.getElementById('two-factor-backup').addEventListener('click', () => credentials('backup_codes'));
  document.getElementById('two-factor-disable').addEventListener('click', () => credentials('disable'));
  document.getElementById('two-factor-email-verify').addEventListener('click',emailVerification);
  document.getElementById('two-factor-email-cancel').addEventListener('click', () => {
    open();title('Cancel email recovery');contents.replaceChildren();
    contents.append(el('p','Stop the pending recovery and keep your authenticator and backup codes active.'));
    button('Cancel recovery',()=>perform(async()=>{render(await request({action:'email_cancel'}));dialog.close();}),contents,true);
    button('Keep recovery request',()=>dialog.close(),contents);
  });
  document.getElementById('two-factor-view-codes').addEventListener('click', () => backupCodes());
  window.addEventListener('corechat-account-security-updated', () => request().then(render).catch(error => { status.textContent=error.message; }));
  request().then(data => {
    render(data);
    if (new URLSearchParams(location.search).get('setup2fa') === '1' && data.available && !data.enabled) credentials('begin');
  }).catch(error => { status.textContent = error.message; });
})();
