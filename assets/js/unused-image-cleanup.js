(() => {
  'use strict';
  const panel = document.getElementById('admin-unused-images');
  if (!panel || document.body.dataset.isAdmin !== 'true') return;
  const base = document.body.dataset.appBase || '', csrf = document.body.dataset.csrf || '';
  const find = key => panel.querySelector(`[data-cleanup-${key}]`);
  const status = find('status'), results = find('results'), pages = find('pages'), filter = find('kind');
  let items = [], selected = new Set(), mode = 'scan', busy = false, controller = null, page = 0, complete = false;
  let batchActive = false, stopRequested = false;
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
  const button = (text, action) => { const b = node('button', text); b.className = 'btn'; b.type = 'button'; b.addEventListener('click', action); return b; };
  const bytes = n => n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(1)} MB`;
  async function confirmAction(label, chosen, consequence) {
    const box = node('section'); box.className = 'cleanup-confirm'; box.setAttribute('role', 'region'); box.setAttribute('aria-label', 'Confirm cleanup action'); box.tabIndex = -1;
    box.append(node('strong', `${label}: ${chosen.length} selected ${chosen.length === 1 ? 'file' : 'files'} · ${bytes(chosen.reduce((total, item) => total + item.bytes, 0))}?`));
    const list = node('ul'); for (const item of chosen) list.append(node('li', item.name)); box.append(list, node('p', consequence));
    const actions = node('div'); actions.className = 'cleanup-toolbar'; box.append(actions); results.before(box);
    return new Promise(resolve => {
      const finish = value => { box.remove(); resolve(value); };
      actions.append(button('Cancel', () => finish(false)), button(label, () => finish(true)));
      box.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); finish(false); } });
      box.focus(); box.scrollIntoView({ block: 'nearest' });
    });
  }
  async function request(data, post = false, signal) {
    const options = { credentials: 'same-origin', cache: 'no-store', signal };
    if (post) Object.assign(options, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(data) });
    const response = await fetch(`${base}/api/unused_images.php${post ? '' : '?' + new URLSearchParams(data)}`, options);
    const value = await response.json();
    if (!response.ok || value.error) {
      if (value.reauthentication_required || value.code === 'RECENT_AUTHENTICATION_REQUIRED') window.CoreChatRecentAuthentication?.open();
      throw new Error(value.error || 'The review could not finish.');
    }
    return value;
  }
  function controls() {
    find('scan').disabled = busy; find('trash').disabled = busy; filter.disabled = busy;
    find('cancel').hidden = !controller;
    const chosen = items.filter(item => selected.has(item.id || item.path));
    find('selection').textContent = `${chosen.length} ${chosen.length === 1 ? 'file' : 'files'} selected · ${bytes(chosen.reduce((total, item) => total + item.bytes, 0))}`;
    find('select-page').disabled = busy || !complete || !items.length;
    find('select-all').disabled = busy || !complete || !items.length;
    find('select-all').textContent = complete ? `Select all results (${items.length})` : 'Select all results';
    find('clear').disabled = busy || !selected.size;
    find('stop').hidden = !batchActive;
    find('stop').disabled = stopRequested;
    find('stop').textContent = stopRequested ? 'Stopping after current file…' : 'Stop after current file';
    for (const action of ['move','restore','purge']) { const b = find(action); b.hidden = action === 'move' ? mode !== 'scan' : mode !== 'trash'; b.disabled = busy || !complete || !selected.size; }
  }
  function render() {
    controls(); results.replaceChildren(); pages.replaceChildren(); page = Math.min(page, Math.max(0, Math.ceil(items.length / 8) - 1));
    for (const item of items.slice(page * 8, page * 8 + 8)) {
      const key = item.id || item.path, row = node('div'); row.className = 'cleanup-row';
      const check = node('input'); check.type = 'checkbox'; check.checked = selected.has(key); check.disabled = busy || !complete; check.setAttribute('aria-label', `Select ${item.name}`);
      check.addEventListener('change', () => { if (check.checked) selected.add(key); else selected.delete(key); controls(); });
      const img = node('img'); img.alt = ''; img.loading = 'lazy'; img.className = item.kind === 'nameplate' ? 'cleanup-nameplate' : '';
      if (item.previewable !== false) img.src = `${base}/api/unused_images.php?${new URLSearchParams(mode === 'trash' ? { action: 'preview', id: item.id } : { action: 'preview', path: item.path, snapshot: item.snapshot })}`;
      if (item.previewable === false) img.hidden = true;
      img.addEventListener('error', () => { img.hidden = true; });
      const meta = node('div'); meta.className = 'cleanup-meta';meta.append(node('strong', item.name), node('small', `${item.kind} · ${bytes(item.bytes)}`), node('small', mode === 'trash' ? `Moved to trash ${new Date(item.movedAt).toLocaleString()}` : item.reason));
      row.append(check, img, meta); results.append(row);
    }
    if (items.length > 8) {
      const prev = button('Previous', () => { page--;render(); pages.querySelector('button')?.focus(); }); prev.disabled = page === 0 || busy;
      const next = button('Next', () => { page++;render(); pages.querySelector('button:last-child')?.focus(); }); next.disabled = (page + 1) * 8 >= items.length || busy;
      pages.append(prev, node('span', `Page ${page + 1} of ${Math.ceil(items.length / 8)}`), next);
    }
  }
  async function scan(trash = false) {
    if (busy) return; mode = trash ? 'trash' : 'scan'; items = []; selected.clear(); page = 0; complete = false; busy = true; controller = new AbortController(); status.textContent = trash ? 'Loading recoverable trash…' : 'Checking saved libraries and retained references…'; render();
    let after = '', checked = 0, protectedCount = 0, skipped = 0, unsupported = 0;
    try {
      do {
        const data = await request({ action: mode, kind: filter.value, after }, false, controller.signal);
        items.push(...data.items); checked += data.checked || 0; protectedCount += data.protected || 0; skipped += data.skipped || 0; unsupported = data.unsupported || 0; after = data.after;
        status.textContent = trash ? `${items.length} trash entries loaded…` : `${checked} of ${data.total} checked · ${items.length} candidates · ${protectedCount} protected · ${skipped + data.unsupported} recent or unverifiable`;
        render(); if (data.done) break;
      } while (!controller.signal.aborted);
      complete = true;
      status.textContent = trash ? `${items.length} items in recoverable trash. Space is freed only by permanent deletion.` : `Scan complete: ${items.length} cleanup candidates (${bytes(items.reduce((n, item) => n + item.bytes, 0))}). ${protectedCount} protected; ${skipped + unsupported} recent or unverifiable files skipped. Nothing was changed.`;
    } catch (error) { status.textContent = error.name === 'AbortError' ? 'Scan cancelled. Nothing was changed. Scan again to enable actions.' : error.message; }
    finally { busy = false; controller = null; render(); }
  }
  async function act(action) {
    if (busy || !complete || !selected.size) return;
    const chosen = items.filter(item => selected.has(item.id || item.path));
    const label = action === 'move' ? 'Move to recoverable trash' : action === 'restore' ? 'Restore' : 'Permanently delete';
    busy = true; render();
    const accepted = await confirmAction(label, chosen, action === 'purge' ? 'This cannot be undone. Newly referenced files will be skipped.' : action === 'move' ? 'Usage and file contents will be checked again. You can restore them from trash.' : 'An existing file with the same name will never be overwritten.');
    if (!accepted) { busy = false; render(); find(action).focus(); return; }
    let done = 0, attempted = 0; const failures = [];
    batchActive = true; stopRequested = false; controls();
    status.textContent = `Processing 0 of ${chosen.length} selected files…`;
    find('stop').focus(); find('stop').scrollIntoView({ block: 'nearest' });
    for (const item of chosen) {
      if (stopRequested) break;
      status.textContent = `Processing ${attempted + 1} of ${chosen.length}: ${item.name} · ${done} completed · ${failures.length} skipped or failed`;
      try {
        await request(action === 'move' ? { action: 'trash', path: item.path, snapshot: item.snapshot } : { action, id: item.id, confirmation: 'permanently-delete-selected' }, true);
        done++; selected.delete(item.id || item.path); items = items.filter(x => x !== item);
      } catch (error) { failures.push(`${item.name}: ${error.message}`); }
      attempted++; controls();
    }
    batchActive = false; busy = false; render();
    const remaining = chosen.length - attempted;
    status.textContent = `${remaining ? 'Stopped. ' : ''}${done} completed · ${failures.length} skipped or failed · ${remaining} not processed. ${remaining || failures.length ? 'Remaining files stay selected for review or retry. ' : 'No other files were changed. '}${failures.slice(0, 5).join(' ')}${failures.length > 5 ? ` (${failures.length - 5} additional failures; these files remain selected.)` : ''}`;
    status.tabIndex = -1; status.focus();
  }
  find('scan').addEventListener('click', () => scan()); find('trash').addEventListener('click', () => scan(true));
  find('cancel').addEventListener('click', () => controller?.abort());
  find('stop').addEventListener('click', () => { if (batchActive) { stopRequested = true; controls(); } });
  function selectItems(all) {
    if (busy || !complete) return;
    for (const item of all ? items : items.slice(page * 8, page * 8 + 8)) selected.add(item.id || item.path);
    render(); find(all ? 'select-all' : 'select-page').focus();
  }
  find('select-page').addEventListener('click', () => selectItems(false));
  find('select-all').addEventListener('click', () => selectItems(true));
  find('clear').addEventListener('click', () => { if (!busy) { selected.clear(); render(); find('select-page').focus(); } });
  filter.addEventListener('change', () => {
    if (busy) return;
    items = []; selected.clear(); page = 0; complete = false; mode = 'scan';
    status.textContent = 'Folder selection changed. Find unused files to review this category.'; render();
  });
  for (const action of ['move','restore','purge']) find(action).addEventListener('click', () => act(action));
  controls();
})();
