(() => {
  'use strict';
  if (window.CoreChatRecentAuthentication) return;
  const base = String(document.body.dataset.appBase || '').replace(/\/$/, '');
  const stylesheet = document.createElement('link');
  stylesheet.rel = 'stylesheet';
  stylesheet.href = `${base}/assets/css/recent-authentication.css?v=20260916-retry`;
  document.head.append(stylesheet);
  let dialog;
  let previousFocus;
  let pending = false;
  const staleWarning = 'Please sign in again before performing this sensitive action.';
  const warningSelector = '[role="alert"], [role="status"], .admin-form-status, .error, [id$="-error"], [id$="-status"], [id$="-msg"]';
  const retryButtons = new Map();
  function refreshRetryButtons() {
    for (const [container, button] of retryButtons) {
      if (!container.isConnected || !container.textContent.includes(staleWarning) || !container.contains(button)) {
        button.remove();
        retryButtons.delete(container);
      }
    }
    document.querySelectorAll(warningSelector).forEach(container => {
      if (container.closest('.recent-authentication-dialog') || !container.textContent.includes(staleWarning)) return;
      // Put the action on the innermost warning, not each matching ancestor.
      if ([...container.querySelectorAll(warningSelector)].some(child => child.textContent.includes(staleWarning))) return;
      if (retryButtons.has(container)) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'recent-authentication-retry';
      button.dataset.confirmIdentity = '';
      button.textContent = 'Confirm Identity';
      container.append(button);
      retryButtons.set(container, button);
    });
  }
  // Error messages are rendered after the request raises the authentication event.
  // Watch warning regions so asynchronous Admin and chat errors get the same retry.
  const warningObserver = new MutationObserver(records => {
    if (records.some(record => {
      const element = record.target.nodeType === Node.ELEMENT_NODE ? record.target : record.target.parentElement;
      return element?.closest(warningSelector) || [...record.addedNodes].some(node =>
        node.nodeType === Node.ELEMENT_NODE && (node.matches(warningSelector) || node.querySelector(warningSelector)));
    })) refreshRetryButtons();
  });
  warningObserver.observe(document.body, {childList: true, subtree: true, characterData: true});
  refreshRetryButtons();
  function open() {
    if (dialog?.open) return;
    previousFocus = document.activeElement;
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.className = 'recent-authentication-dialog';
      dialog.setAttribute('aria-labelledby', 'recent-authentication-title');
      dialog.innerHTML = `<form>
        <h2 id="recent-authentication-title">Confirm your identity</h2>
        <p>For sensitive changes, confirm your password again. You will stay signed in and in your room.</p>
        <label for="recent-authentication-password">Current password</label>
        <input id="recent-authentication-password" name="password" type="password" autocomplete="current-password" required>
        <p class="recent-authentication-message" role="status" aria-live="polite"></p>
        <div class="recent-authentication-actions"><button type="button" data-close>Cancel</button><button type="submit">Confirm Identity</button></div>
      </form>`;
      document.body.append(dialog);
      const form = dialog.querySelector('form');
      const password = dialog.querySelector('input');
      const message = dialog.querySelector('[role="status"]');
      const submit = dialog.querySelector('[type="submit"]');
      const close = dialog.querySelector('[data-close]');
      close.addEventListener('click', () => { if (!pending) dialog.close(); });
      dialog.addEventListener('cancel', event => { if (pending) event.preventDefault(); });
      dialog.addEventListener('close', () => {
        password.value = '';
        if (previousFocus?.isConnected) previousFocus.focus();
      });
      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending || !form.reportValidity()) return;
        pending = true;
        submit.disabled = true;
        close.disabled = true;
        message.textContent = 'Confirming...';
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
          const csrf = document.body.dataset.csrf || '';
          const requestBody = JSON.stringify({password: password.value, _csrf: csrf});
          password.value = '';
          const response = await fetch(`${base}/api/session_lock.php`, {
            method: 'POST', credentials: 'same-origin', signal: controller.signal,
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: requestBody,
          });
          const text = await response.text();
          let data;
          try { data = JSON.parse(text); } catch {
            throw new Error('The server could not confirm your identity. Please try again.');
          }
          if (!response.ok || data.error || !data.ok) {
            throw new Error(response.status === 401
              ? 'Your sign-in session has expired. Password confirmation cannot restore an expired session.'
              : String(data.error || 'Unable to confirm your identity. Please try again.'));
          }
          for (const button of retryButtons.values()) button.remove();
          retryButtons.clear();
          document.querySelectorAll(warningSelector).forEach(container => {
            const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
            let node;
            let cleared = false;
            while ((node = walker.nextNode())) {
              if (node.nodeValue.trim() === staleWarning) {
                node.nodeValue = '';
                cleared = true;
              }
            }
            // Clear only the obsolete warning, preserving other messages and controls.
            // Do not permanently hide the live region: future errors must still appear.
            if (cleared && !container.textContent.trim()
                && !container.querySelector('button, input, select, textarea, a[href], img, video, audio, canvas')) {
              container.replaceChildren();
              container.classList.remove('error');
              if (container.getAttribute('role') === 'alert') container.setAttribute('role', 'status');
            }
          });
          message.textContent = 'Identity confirmed. Close this dialog and try your action again.';
          password.hidden = true;
          password.required = false;
          dialog.querySelector('label').hidden = true;
          submit.hidden = true;
          close.textContent = 'Close';
          close.disabled = false;
          close.focus();
        } catch (error) {
          message.textContent = error.name === 'AbortError'
            ? 'Confirmation timed out. Please try again.' : error.message;
          password.focus();
        } finally {
          clearTimeout(timeout);
          pending = false;
          submit.disabled = false;
          close.disabled = false;
        }
      });
    }
    dialog.querySelector('form').reset();
    const password = dialog.querySelector('input');
    password.hidden = false;
    password.required = true;
    dialog.querySelector('label').hidden = false;
    dialog.querySelector('[type="submit"]').hidden = false;
    dialog.querySelector('[data-close]').textContent = 'Cancel';
    dialog.querySelector('[role="status"]').textContent = '';
    dialog.showModal();
    password.focus();
  }
  window.CoreChatRecentAuthentication = Object.freeze({open});
  window.addEventListener('corechat:reauthentication-required', open);
  document.addEventListener('click', event => {
    if (event.target.closest?.('[data-confirm-identity]')) {
      event.preventDefault();
      open();
    }
  });
})();
