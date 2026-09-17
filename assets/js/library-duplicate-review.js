(() => {
  'use strict';
  const panel = document.getElementById('admin-library-duplicates');
  if (!panel || document.body.dataset.isAdmin !== 'true') return;
  const base = document.body.dataset.appBase || '';
  const csrf = document.body.dataset.csrf || '';
  const status = panel.querySelector('[data-duplicate-status]');
  const results = panel.querySelector('[data-duplicate-results]');
  const scan = panel.querySelector('[data-duplicate-scan]');
  const cancel = panel.querySelector('[data-duplicate-cancel]');
  const filter = panel.querySelector('select');
  const pager = panel.querySelector('[data-duplicate-pages]');
  const kinds = { avatar: 'Avatars', nameplate: 'Nameplates', gesture: 'Gestures', emoji: 'Custom emojis' };
  let groups = new Map(), page = 0, controller = null, busy = false, complete = false;
  const el = (tag, text, className) => { const n = document.createElement(tag); if (text) n.textContent = text; if (className) n.className = className; return n; };
  const button = (text, action) => { const b = el('button', text, 'btn'); b.type = 'button'; b.addEventListener('click', action); return b; };
  async function request(path, data, form = false, signal) {
    const options = { credentials: 'same-origin', cache: 'no-store', signal, headers: {} };
    if (data) {
      options.method = 'POST'; options.headers['X-CSRF-Token'] = csrf;
      options.headers['Content-Type'] = form ? 'application/x-www-form-urlencoded;charset=UTF-8' : 'application/json';
      options.body = form ? new URLSearchParams(data) : JSON.stringify(data);
    }
    const response = await fetch(base + path, options);
    const payload = await response.json();
    if (!response.ok || payload.error) {
      if (payload.reauthentication_required || payload.code === 'RECENT_AUTHENTICATION_REQUIRED') window.CoreChatRecentAuthentication?.open();
      throw new Error(payload.error || 'The library request failed.');
    }
    return payload;
  }
  function controls() { scan.disabled = busy; filter.disabled = busy; cancel.hidden = !controller; }
  function matching() { return [...groups.values()].filter(g => g.items.length > 1); }
  function render() {
    results.replaceChildren(); pager.replaceChildren();
    const list = matching(); page = Math.min(page, Math.max(0, Math.ceil(list.length / 4) - 1));
    for (const group of list.slice(page * 4, page * 4 + 4)) {
      const box = el('details', '', 'duplicate-group'); box.open = true;
      box.append(el('summary', `${kinds[group.items[0].kind]} · ${group.items.length} matching entries`));
      const entries = el('div', '', 'duplicate-entries');
      for (const item of group.items.slice(0, group.limit || 6)) {
        const row = el('div', '', 'duplicate-entry');
        const keep = el('input'); keep.type = 'radio'; keep.name = 'keep-' + item.kind + item.fingerprint; keep.checked = group.keep === item.id;
        keep.disabled = busy || !complete; keep.setAttribute('aria-label', 'Keep ' + item.name);
        keep.addEventListener('change', () => { group.keep = item.id; render(); results.querySelectorAll('input[type=radio]').forEach(n => { if (n.name === keep.name && n.checked) n.focus(); }); });
        const label = el('label', '', 'duplicate-keep'); label.append(keep, el('span', 'Keep'));
        const image = el('img'); image.alt = ''; image.src = item.preview || ''; image.loading = 'lazy'; image.className = item.kind === 'nameplate' ? 'duplicate-nameplate' : '';
        const meta = el('div', '', 'duplicate-meta'); meta.append(el('strong', item.name), el('small', `${item.owner} · ${item.scope}`));
        if (item.text) meta.append(el('small', item.text));
        if (item.audio) { const audio = el('audio'); audio.controls = true; audio.preload = 'none'; audio.src = item.audio; audio.setAttribute('aria-label', 'Sound for ' + item.name); meta.append(audio); }
        const remove = button(item.action === 'remove_community' ? 'Remove shared' : 'Delete', () => removeItem(group, item, remove));
        remove.setAttribute('aria-label', (item.action === 'remove_community' ? 'Remove shared ' : 'Delete ') + item.name);
        remove.disabled = busy || !complete || item.id === group.keep;
        row.append(label, image, meta, remove); entries.append(row);
      }
      box.append(entries);
      if (group.items.length > (group.limit || 6)) box.append(button('Show more copies', () => { group.limit = (group.limit || 6) + 6; render(); }));
      results.append(box);
    }
    if (list.length > 4) {
      const prev = button('Previous', () => { page--; render(); pager.querySelector('button')?.focus(); }); prev.disabled = page === 0 || busy;
      const next = button('Next', () => { page++; render(); pager.querySelector('button:last-child')?.focus(); }); next.disabled = (page + 1) * 4 >= list.length || busy;
      pager.append(prev, el('span', `Page ${page + 1} of ${Math.ceil(list.length / 4)}`), next);
    }
  }
  async function removeItem(group, item, trigger) {
    const keep = group.items.find(x => x.id === group.keep);
    if (busy || !complete || !keep || keep.id === item.id) return;
    const consequence = item.kind === 'gesture' ? 'This removes the gesture from its catalog. Older messages may no longer play it.'
      : item.action === 'remove_community' ? 'This removes the shared listing. The uploader keeps their private copy; chosen images stay in use.'
      : item.kind === 'emoji' ? 'Existing messages keep their emoji image.' : 'This removes the library listing. Images already in use stay unchanged.';
    if (!await window.CoreChatPopups.confirm(`Delete "${item.name}" (${item.owner}) and keep "${keep.name}" (${keep.owner})?\n\n${consequence}`)) { trigger.focus(); return; }
    busy = true; controls(); render(); status.textContent = 'Checking both copies…';
    try {
      const review = {target:{id:item.id,snapshot:item.snapshot},keep:{id:keep.id,snapshot:keep.snapshot}};
      await request('/api/library_duplicates.php', {kind:item.kind,...review});
      if (item.kind === 'gesture') {
        await request(item.action === 'admin-delete' ? '/api/admin_gestures.php' : '/api/library_duplicates.php', {action:item.action === 'admin-delete' ? 'delete' : 'delete_personal',public_id:item.id,expected_version:item.version,request_key:crypto.randomUUID(),duplicate_review:review});
      } else if (item.kind === 'emoji') {
        await request('/api/custom_emojis.php', {action:'delete',id:item.id,duplicate_review:JSON.stringify(review)}, true);
      } else {
        await request('/api/avatar_library.php', {action:item.action,kind:item.kind,id:item.id,duplicate_review:JSON.stringify(review)}, true);
      }
      group.items = group.items.filter(x => x.id !== item.id);
      status.textContent = `Removed "${item.name}". Kept "${keep.name}". ${matching().length} duplicate groups remain.`;
    } catch (error) { complete = false; status.textContent = `${error.message} Scan again to refresh the review.`; }
    finally { busy = false; controls(); render(); scan.focus(); }
  }
  scan.addEventListener('click', async () => {
    if (busy) return;
    busy = true; complete = false; groups = new Map(); page = 0; controller = new AbortController(); controls(); render();
    let count = 0, skipped = 0;
    try {
      for (const kind of filter.value === 'all' ? Object.keys(kinds) : [filter.value]) {
        let after = '';
        while (true) {
          status.textContent = `Scanning ${kinds[kind].toLowerCase()}… ${count} entries checked.`;
          const data = await request(`/api/library_duplicates.php?${new URLSearchParams({kind,after})}`, null, false, controller.signal);
          count += data.scanned; skipped += data.skipped;
          for (const item of data.items) {
            const key = kind + ':' + item.fingerprint;
            if (!groups.has(key)) groups.set(key, {items:[],keep:item.id});
            const group = groups.get(key); if (!group.items.some(x => x.id === item.id)) group.items.push(item);
          }
          if (data.done) break;
          if (after === data.after) throw new Error('The scan could not advance.');
          after = data.after;
        }
      }
      complete = true;
      status.textContent = `${count} entries checked. ${matching().length ? matching().length + ' duplicate groups found. Choose which copy to keep.' : 'No exact duplicates found.'}${skipped ? ' ' + skipped + ' entries skipped: missing, unreadable or larger than the 64 MB scan limit.' : ''}`;
    } catch (error) { status.textContent = error.name === 'AbortError' ? 'Scan cancelled. Nothing was deleted. Scan again for complete results.' : `Scan incomplete: ${error.message}`; }
    finally { controller = null; busy = false; controls(); render(); scan.focus(); }
  });
  cancel.addEventListener('click', () => controller?.abort());
  window.addEventListener('pagehide', () => controller?.abort());
})();
