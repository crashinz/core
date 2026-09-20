export async function openAvatarLibrary({ userId, base, applyFile, applyAsset, prepareFile, postForm, chooseFallback, kind = 'avatar' }) {
  const kindText = value => kind === 'nameplate' ? String(value).replace(/avatar/gi, word => word[0] === 'A' ? 'Nameplate' : 'nameplate') : String(value);
  const el = (tag, text = '') => { const item = document.createElement(tag); item.textContent = kindText(text); return item; };
  const dialog = el('dialog'); dialog.className = 'avatar-library-dialog';
  const title = el('h2', 'Choose avatar');
  title.id = kind + '-library-title-' + userId + '-' + Math.random().toString(36).slice(2);
  dialog.setAttribute('aria-labelledby', title.id);
  dialog.setAttribute('aria-modal', 'false');
  const titleBar = el('div'); titleBar.className = 'avatar-library-titlebar';
  titleBar.appendChild(title);
  const actions = el('div'); actions.className = 'avatar-library-actions';
  const local = el('button', 'Choose local image');
  const mine = el('button', 'My avatars');
  const privateAvatars = el('button', 'Private');
  const community = el('button', 'Community avatars');
  const folder = el('button', 'Upload folder to community'); folder.hidden = true;
  const close = el('button', 'Close');
  for (const button of [local, mine, privateAvatars, community, folder, close]) { button.type = 'button'; button.className = 'btn'; }
  actions.append(local, mine, privateAvatars, community, folder);
  titleBar.appendChild(close);
  const filters = el('div'); filters.className = 'avatar-library-actions';
  const sort = el('select'); sort.dataset.popupNoDraft = ''; sort.setAttribute('aria-label', kindText('Sort avatars'));
  for (const [value, label] of [['uploaded','Newest uploads'],['modified','Last modified'],['name','File name'],['oldest','Oldest uploads']]) { const option = el('option', label); option.value = value; sort.appendChild(option); }
  const section = el('select'); section.dataset.popupNoDraft = ''; section.setAttribute('aria-label', kindText('Avatar section'));
  const allSections = el('option', 'All sections'); allSections.value = ''; section.appendChild(allSections);
  const sectionNames = el('datalist'); sectionNames.id = `${kind}-library-sections-${userId}`;
  let knownSections = [];
  const sectionPickers = new Map();
  const fillSectionPicker = (picker, input) => {
    const placeholder = el('option', 'Choose saved section…'); placeholder.value = '';
    picker.replaceChildren(placeholder);
    for (const value of knownSections) { const option = el('option', value); option.value = value; picker.appendChild(option); }
    picker.value = knownSections.includes(input.value) ? input.value : '';
  };
  const existingSectionPicker = input => {
    const picker = el('select'); picker.setAttribute('aria-label', kindText('Saved avatar sections'));
    picker.dataset.popupNoDraft = '';
    Object.assign(picker.style, { width: '100%', minWidth: '0', maxWidth: '100%' });
    sectionPickers.set(picker, input); fillSectionPicker(picker, input);
    picker.addEventListener('change', () => { if (picker.value) { input.value = picker.value; input.dispatchEvent(new Event('input', { bubbles: true })); } });
    input.addEventListener('input', () => { picker.value = knownSections.includes(input.value) ? input.value : ''; });
    return picker;
  };
  const updateSections = values => {
    knownSections = [...new Set(values.filter(value => typeof value === 'string' && value))].sort((a,b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }));
    const selected = section.value;
    section.replaceChildren(allSections); sectionNames.replaceChildren();
    for (const value of knownSections) {
      const option = el('option', value); option.value = value; section.appendChild(option);
      const suggestion = el('option'); suggestion.value = value; sectionNames.appendChild(suggestion);
    }
    section.value = selected;
    for (const [picker,input] of sectionPickers) { if (!picker.isConnected && picker !== uploadSectionPicker) sectionPickers.delete(picker); else fillSectionPicker(picker,input); }
  };
  const uploadSection = el('input'); uploadSection.placeholder = 'Optional section'; uploadSection.maxLength = 80; uploadSection.setAttribute('list', sectionNames.id);
  uploadSection.id = sectionNames.id + '-upload';
  Object.assign(uploadSection.style, { width: '100%', minWidth: '0', maxWidth: '100%', boxSizing: 'border-box' });
  const uploadSectionLabel = el('label'); uploadSectionLabel.htmlFor = uploadSection.id; uploadSectionLabel.hidden = true;
  Object.assign(uploadSectionLabel.style, { flex: '1 1 12rem', minWidth: '0', maxWidth: '100%' });
  const uploadSectionTitle = el('span', 'Folder upload section'); uploadSectionTitle.style.display = 'block';
  const uploadSectionPicker = existingSectionPicker(uploadSection);
  uploadSectionLabel.append(uploadSectionTitle, uploadSectionPicker, uploadSection);
  filters.style.flexWrap = 'wrap';
  filters.append(sort, section, uploadSectionLabel, sectionNames);
  const explanation = el('p', 'Your uploads stay private unless you share them. Making an avatar private stops future library reuse; it cannot recall copies already chosen by others.');
  const status = el('p'); status.setAttribute('role', 'status');
  const gallery = el('div'); gallery.className = 'avatar-library-grid';
  const more = el('button', 'Load more'); more.type = 'button'; more.className = 'btn'; more.hidden = true;
  const folderInput = el('input'); folderInput.type = 'file'; folderInput.multiple = true; folderInput.hidden = true; folderInput.accept = 'image/*'; folderInput.setAttribute('webkitdirectory', '');
  dialog.append(titleBar, actions, filters, explanation, status, gallery, more, folderInput);
  let view = 'mine', page = 1, generation = 0, busy = false;
  const visualViewport = window.visualViewport;
  let dragState = null;
  const clampPicker = (left, top) => {
    if (!dialog.open || !dialog.isConnected) return;
    const viewportLeft = visualViewport?.offsetLeft || 0;
    const viewportTop = visualViewport?.offsetTop || 0;
    const width = visualViewport?.width || window.innerWidth;
    const height = visualViewport?.height || window.innerHeight;
    const inset = Math.min(8, Math.max(0, Math.min(width, height) / 4));
    dialog.style.maxWidth = Math.max(1, width - inset * 2) + 'px';
    dialog.style.maxHeight = Math.max(1, height - inset * 2) + 'px';
    const rect = dialog.getBoundingClientRect();
    const minimumLeft = viewportLeft + inset;
    const minimumTop = viewportTop + inset;
    const maximumLeft = Math.max(minimumLeft, viewportLeft + width - rect.width - inset);
    const maximumTop = Math.max(minimumTop, viewportTop + height - rect.height - inset);
    dialog.style.left = Math.min(maximumLeft, Math.max(minimumLeft, left ?? rect.left)) + 'px';
    dialog.style.top = Math.min(maximumTop, Math.max(minimumTop, top ?? rect.top)) + 'px';
  };
  const endDrag = event => {
    if (!dragState || (event && event.pointerId !== dragState.pointerId)) return;
    const pointerId = dragState.pointerId;
    dragState = null;
    titleBar.classList.remove('is-dragging');
    if (titleBar.hasPointerCapture(pointerId)) titleBar.releasePointerCapture(pointerId);
  };
  const viewportChanged = () => { endDrag(); clampPicker(); };
  // Expanding an editor or loading results can grow the picker after centering.
  const resizeObserver = new ResizeObserver(() => clampPicker());
  resizeObserver.observe(dialog);
  titleBar.addEventListener('pointerdown', event => {
    if (!event.isPrimary || event.button !== 0 || event.target.closest('button, input, select, textarea, a, [contenteditable], [role="button"]')) return;
    event.preventDefault();
    const rect = dialog.getBoundingClientRect();
    dragState = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, left: rect.left, top: rect.top };
    titleBar.setPointerCapture(event.pointerId);
    titleBar.classList.add('is-dragging');
  });
  titleBar.addEventListener('pointermove', event => {
    if (!dragState || event.pointerId !== dragState.pointerId) return;
    event.preventDefault();
    clampPicker(dragState.left + event.clientX - dragState.x, dragState.top + event.clientY - dragState.y);
  });
  titleBar.addEventListener('pointerup', endDrag);
  titleBar.addEventListener('pointercancel', endDrag);
  titleBar.addEventListener('lostpointercapture', endDrag);
  // Non-modal open/close state does not invoke native dialog focus restoration.
  const closePicker = () => {
    if (!dialog.open) return;
    dialog.removeAttribute('open');
    dialog.dispatchEvent(new Event('close'));
  };
  close.addEventListener('pointerdown', event => { if (event.button === 0) event.preventDefault(); });
  dialog.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    event.stopPropagation();
    if (!busy) closePicker();
  });

  const requestForm = async values => {
    const form = new FormData();
    form.append('kind', kind);
    for (const [key, value] of Object.entries(values)) {
      if (Array.isArray(value)) { for (const file of value) form.append(key + '[]', file, file.name); }
      else form.append(key, value);
    }
    return postForm(form);
  };
  const load = async (append = false) => {
    const current = ++generation;
    if (!append) { page = 1; gallery.replaceChildren(); }
    status.textContent = kindText('Loading avatars...'); more.disabled = true;
    try {
      const params = new URLSearchParams({ kind, view, page: String(page), sort: sort.value, section: section.value });
      const response = await fetch(`${base}/api/avatar_library.php?${params}`, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || kindText('Avatars could not be loaded.'));
      if (current !== generation || !dialog.isConnected) return;
      folder.hidden = !data.canPublishFolder;
      uploadSectionLabel.hidden = !data.canPublishFolder;
      updateSections(data.sections || []);
      for (const [button, key] of [[mine,'mine'],[privateAvatars,'private'],[community,'community']]) { button.classList.toggle('btn-primary', view === key); button.setAttribute('aria-pressed', String(view === key)); }
      for (const avatar of data.items) {
        const card = el('article');
        const image = el('img'); image.src = `${base}/api/avatar_library.php?action=image&kind=${kind}&id=${encodeURIComponent(avatar.id)}`; image.alt = kindText('Stored avatar'); image.loading = 'lazy';
        const select = el('button', 'Use avatar'); select.type = 'button'; select.className = 'btn';
        select.addEventListener('click', async () => {
          if (busy) return; busy = true; select.disabled = true;
          try {
            if (typeof applyAsset !== 'function') throw new Error('Library selection is unavailable.');
            await applyAsset(avatar.id);
            closePicker();
          } catch (error) { status.textContent = kindText(error.message); }
          finally { busy = false; select.disabled = false; }
        });
        const cardName = el('strong', avatar.name), cardSection = el('small', avatar.section || 'Unfiled');
        card.append(image, cardName, cardSection, select);
        if (avatar.canOrganize) {
          const edit = el('details'); edit.appendChild(el('summary', 'Name & section'));
          const name = el('input'); name.value = avatar.name; name.maxLength = 180; name.setAttribute('aria-label', kindText('Avatar file name'));
          const category = el('input'); category.value = avatar.section; category.maxLength = 80; category.placeholder = 'Section name'; category.setAttribute('list', sectionNames.id); category.setAttribute('aria-label', kindText('Avatar section name'));
          const save = el('button', 'Save name & section'); save.type = 'button'; save.className = 'btn';
          save.addEventListener('click', async () => {
            if (busy) return; busy = true; dialog.dataset.popupBusy = 'true'; save.disabled = true;
            try {
              const saved = await requestForm({ action: 'organize', id: avatar.id, name: name.value, section: category.value });
              const clean = value => value.replace(/[\x00-\x1f\x7f]/g, '').trim();
              avatar.name = typeof saved.name === 'string' ? saved.name : clean(name.value);
              avatar.section = typeof saved.section === 'string' ? saved.section : clean(category.value);
              name.value = avatar.name; category.value = avatar.section;
              cardName.textContent = avatar.name; cardSection.textContent = avatar.section || 'Unfiled';
              updateSections([...knownSections, avatar.section]);
              // Update this card only: preserve other unsaved edits and the current view.
              window.CoreChatPopups?.markSaved(dialog, [name, category, section]);
              status.textContent = 'Saved.';
            }
            catch (error) { status.textContent = kindText(error.message); }
            finally { busy = false; delete dialog.dataset.popupBusy; save.disabled = false; }
          });
          edit.append(name, existingSectionPicker(category), category, save); card.appendChild(edit);
        }
        if (avatar.mine) {
          const share = el('button', avatar.shared ? 'Make private' : 'Share with community'); share.type = 'button'; share.className = 'btn';
          share.addEventListener('click', async () => {
            if (busy) return;
            share.disabled = true;
            try {
              await requestForm({ action: 'share', id: avatar.id, shared: avatar.shared ? '0' : '1' });
              avatar.shared = !avatar.shared; share.textContent = avatar.shared ? 'Make private' : 'Share with community';
              status.textContent = avatar.shared ? kindText('Avatar shared with the community.') : kindText('Avatar is private. Existing copies are unchanged.');
            } catch (error) { status.textContent = kindText(error.message); }
            finally { share.disabled = false; }
          });
          card.appendChild(share);
        }
        if (avatar.mine || avatar.canRemoveFromCommunity) {
          const remove = el('button', avatar.mine ? 'Delete from library' : 'Remove from community');
          remove.type = 'button'; remove.className = 'btn';
          remove.addEventListener('click', async () => {
            if (busy) return;
            const message = avatar.mine
              ? 'Delete this avatar from your library and stop sharing it? Images already in use and copies already acquired by other people will remain available. No image files will be deleted.'
              : 'Remove this avatar from the community library? The owner\'s private copy, images already in use, and copies already acquired will remain available. No image files will be deleted.';
            if (!await window.CoreChatPopups.confirm(kindText(message))) return;
            busy = true; remove.disabled = true;
            try {
              await requestForm({ action: avatar.mine ? 'delete' : 'remove_community', id: avatar.id });
              await load();
              status.textContent = kindText(avatar.mine
                ? 'Avatar deleted from your library and unshared. Images already in use and existing copies are unchanged.'
                : 'Avatar removed from the community library. The owner\'s private copy and existing uses are unchanged.');
            } catch (error) { status.textContent = kindText(error.message); }
            finally { busy = false; remove.disabled = false; }
          });
          card.appendChild(remove);
        }
        gallery.appendChild(card);
      }
      window.CoreChatPopups?.markSaved(dialog);
      more.hidden = !data.hasMore; status.textContent = gallery.childElementCount ? '' : kindText('No avatars in this collection yet.');
    } catch (error) { if (current === generation) status.textContent = kindText(error.message); }
    finally { if (current === generation) { more.disabled = false; clampPicker(); } }
  };
  local.addEventListener('click', async () => {
    if (busy) return;
    if (!window.showOpenFilePicker) { closePicker(); chooseFallback(); return; }
    try {
      // The browser remembers a separate directory for this stable account-specific picker ID.
      const [handle] = await window.showOpenFilePicker({ id: `${kind}-user-${userId}`.slice(0, 32), startIn: 'pictures', multiple: false, types: [{ description: kindText('Avatar images'), accept: { 'image/*': ['.png', '.jpg', '.jpeg', '.gif', '.webp'] } }] });
      busy = true; status.textContent = kindText('Applying avatar...'); await applyFile(await handle.getFile()); closePicker();
    } catch (error) { if (error.name !== 'AbortError') status.textContent = kindText(error.message); }
    finally { busy = false; }
  });
  mine.addEventListener('click', () => { if (!busy) { view = 'mine'; load(); } });
  privateAvatars.addEventListener('click', () => { if (!busy) { view = 'private'; load(); } });
  community.addEventListener('click', () => { if (!busy) { view = 'community'; load(); } });
  sort.addEventListener('change', () => { if (!busy) load(); });
  section.addEventListener('change', () => { if (!busy) load(); });
  more.addEventListener('click', () => { if (!busy) { page++; load(true); } });
  folder.addEventListener('click', () => { if (!busy) folderInput.click(); });
  folderInput.addEventListener('change', async () => {
    if (busy) return;
    const files = [...folderInput.files].filter(file => /\.(gif|webp|png|jpe?g)$/i.test(file.name));
    if (!files.length) { status.textContent = 'No supported images found in that folder.'; return; }
    if (!await window.CoreChatPopups.confirm(kindText(`Publish ${files.length} images to the community avatar library? Your current avatar will not change.`))) return;
    busy = true; folder.disabled = true;
    let uploaded = 0, duplicates = 0; const failures = [];
    let batch = [], batchBytes = 0, blocked = false;
    const flush = async () => {
      if (!batch.length) return;
      const pending = batch; batch = []; batchBytes = 0;
      try {
        const response = await requestForm({ action: 'upload_shared_batch', avatars: pending.map(item => item.prepared), section: uploadSection.value });
        for (const [index, item] of pending.entries()) {
          const result = response.results?.find(result => result.index === index);
          if (result?.duplicate) duplicates++;
          else if (result?.ok) uploaded++;
          else failures.push(`${item.file.name}: ${kindText(result?.error || 'Upload did not complete.')}`);
        }
      } catch (error) {
        blocked = true;
        failures.push(`Publishing stopped: ${kindText(error.message)}. Remaining images were not uploaded.`);
      }
    };
    for (const [index, file] of files.entries()) {
      if (blocked) break;
      status.textContent = `Publishing ${index + 1}/${files.length}: ${file.name}`;
      try {
        const prepared = await prepareFile(file);
        // Bound each request by count and size without bypassing server policy.
        if (batch.length && (batch.length >= 10 || batchBytes + prepared.size > 1024 * 1024)) await flush();
        if (blocked) break;
        batch.push({ file, prepared }); batchBytes += prepared.size;
      } catch (error) { failures.push(`${file.name}: ${kindText(error.message)}`); }
    }
    if (!blocked) await flush();
    busy = false; folder.disabled = false; folderInput.value = '';
    view = 'mine'; await load();
    status.textContent = `${uploaded}/${files.length} images published. ${duplicates} already uploaded; skipped.${failures.length ? ' Failed: ' + failures.join('; ') : ''}`;
  });
  close.addEventListener('click', () => { if (!busy) closePicker(); });
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  dialog.addEventListener('close', () => {
    generation++;
    resizeObserver.disconnect();
    endDrag();
    window.removeEventListener('resize', viewportChanged);
    visualViewport?.removeEventListener('resize', viewportChanged);
    visualViewport?.removeEventListener('scroll', viewportChanged);
    dialog.remove();
  }, { once: true });
  document.body.appendChild(dialog);
  // Setting the native non-modal open state leaves the current chat focus untouched.
  dialog.setAttribute('open', '');
  clampPicker();
  const initialRect = dialog.getBoundingClientRect();
  clampPicker((visualViewport?.offsetLeft || 0) + ((visualViewport?.width || window.innerWidth) - initialRect.width) / 2,
    (visualViewport?.offsetTop || 0) + ((visualViewport?.height || window.innerHeight) - initialRect.height) / 2);
  window.addEventListener('resize', viewportChanged);
  visualViewport?.addEventListener('resize', viewportChanged);
  visualViewport?.addEventListener('scroll', viewportChanged);
  await load();
}
