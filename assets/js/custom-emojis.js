import { positionPickerMenu } from './core/picker-menu-position.js';

export class CustomEmojiPicker {
  constructor({ root, appUrl, upload, onSelect }) {
    this.root = root;
    this.appUrl = appUrl;
    this.upload = upload;
    this.onSelect = onSelect;
    this.emojis = [];
    this.visibleCount = 48;
    this.policy = { maxBytes: 5 * 1024 * 1024, maxWidth: 512, maxHeight: 512 };
    this.pending = null;
    this.busy = false;
    this.canManage = false;
    this.build();
  }

  element(tag, className = '', text = '') {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = text;
    return element;
  }

  build() {
    this.root.classList.add('custom-emoji-picker');
    const toolbar = this.element('div', 'custom-emoji-toolbar');
    this.search = this.element('input');
    this.search.type = 'search';
    this.search.placeholder = 'Search custom emojis';
    this.search.setAttribute('aria-label', 'Search custom emojis');
    this.search.addEventListener('input', () => { this.visibleCount = 48; this.render(); });
    const refresh = this.element('button', 'btn', 'Refresh');
    refresh.type = 'button';
    refresh.addEventListener('click', () => this.activate());
    toolbar.append(this.search, refresh);

    this.manager = this.element('details', 'custom-emoji-manager');
    this.manager.hidden = true;
    this.manager.append(this.element('summary', '', 'Add custom emoji (admin)'));
    const form = this.element('form', 'custom-emoji-upload');
    const nameLabel = this.element('label', '', 'Name');
    this.nameInput = this.element('input');
    this.nameInput.type = 'text';
    this.nameInput.required = true;
    this.nameInput.maxLength = 32;
    this.nameInput.pattern = '[a-z0-9_-]{1,32}';
    this.nameInput.placeholder = 'happy-cat';
    this.nameInput.autocomplete = 'off';
    nameLabel.append(this.nameInput);
    const fileLabel = this.element('label', '', 'Image');
    this.fileInput = this.element('input');
    this.fileInput.type = 'file';
    this.fileInput.required = true;
    this.fileInput.accept = 'image/png,image/gif,image/webp,image/jpeg';
    this.fileInput.addEventListener('change', () => {
      const file = this.fileInput.files?.[0];
      if (file && !this.nameInput.value.trim()) {
        this.nameInput.value = file.name.replace(/\.[^.]+$/, '').toLowerCase()
          .replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32);
      }
    });
    fileLabel.append(this.fileInput);
    this.uploadButton = this.element('button', 'btn btn-primary', 'Upload emoji');
    this.uploadButton.type = 'submit';
    this.guidance = this.element('p', 'minor');
    form.append(nameLabel, fileLabel, this.uploadButton, this.guidance);
    form.addEventListener('submit', event => { event.preventDefault(); this.submit(); });
    this.manager.append(form);
    this.status = this.element('p', 'minor custom-emoji-status');
    this.status.setAttribute('role', 'status');
    this.status.setAttribute('aria-live', 'polite');
    this.grid = this.element('div', 'custom-emoji-grid');
    this.grid.setAttribute('aria-label', 'Custom emojis');
    this.more = this.element('button', 'btn custom-emoji-more', 'Load more');
    this.more.type = 'button';
    this.more.hidden = true;
    this.more.addEventListener('click', () => { this.visibleCount += 48; this.render(); });
    this.root.replaceChildren(toolbar, this.manager, this.status, this.grid, this.more);
    this.menu = this.element('div', 'gesture-action-menu custom-emoji-action-menu');
    this.menu.id = 'custom-emoji-action-menu';
    this.menu.hidden = true;
    this.menu.setAttribute('role', 'menu');
    this.menu.setAttribute('aria-label', 'Custom emoji actions');
    document.body.append(this.menu);
    document.addEventListener('pointerdown', event => {
      if (!this.menu.contains(event.target)) this.closeMenu();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !this.menu.hidden) {
        event.preventDefault(); event.stopImmediatePropagation(); this.closeMenu(true);
      }
    }, true);
    this.updateGuidance();
  }

  closeMenu(restoreFocus = false) {
    this.menu.hidden = true;
    if (restoreFocus) this.menuTarget?.focus();
    this.menuTarget = null;
  }

  openMenu(emoji, target, x, y) {
    if (!this.canManage || this.busy) return;
    this.menuTarget = target;
    const rename = this.element('button', '', 'Rename emoji');
    rename.type = 'button'; rename.setAttribute('role', 'menuitem');
    rename.addEventListener('click', event => {
      event.stopPropagation(); this.closeMenu(); this.rename(emoji, target);
    });
    const remove = this.element('button', '', 'Delete emoji');
    remove.type = 'button'; remove.setAttribute('role', 'menuitem');
    remove.addEventListener('click', event => {
      event.stopPropagation(); this.closeMenu(); this.remove(emoji, target);
    });
    this.menu.replaceChildren(rename, remove);
    this.menu.hidden = false;
    positionPickerMenu(this.menu, x, y);
    rename.focus({ preventScroll: true });
  }

  async rename(emoji, target) {
    if (!this.canManage || this.busy) return;
    const answer = await window.CoreChatPopups.prompt(`Rename :${emoji.name}: (1-32 lowercase letters, numbers, hyphens or underscores):`, emoji.name);
    if (answer === null || answer.trim() === emoji.name) { target.focus(); return; }
    const name = answer.trim();
    if (!/^[a-z0-9_-]{1,32}$/.test(name)) {
      this.status.textContent = 'Use a name of 1-32 lowercase letters, digits, underscores or hyphens.';
      target.focus(); return;
    }
    this.busy = true; this.uploadButton.disabled = true;
    this.status.textContent = `Renaming :${emoji.name}:...`;
    try {
      const form = new FormData();
      form.append('action', 'rename'); form.append('id', emoji.id); form.append('name', name);
      form.append('expected_version', String(emoji.version || 1));
      const data = await this.upload(this.appUrl('/api/custom_emojis.php'), form);
      this.applyCatalog(data);
      this.status.textContent = `Renamed :${emoji.name}: to :${name}:. Existing messages keep their image.`;
      this.search.focus();
    } catch (error) {
      this.status.textContent = error.message || 'The emoji could not be renamed.';
      target.focus();
    } finally { this.busy = false; this.uploadButton.disabled = false; }
  }

  async remove(emoji, target) {
    if (!this.canManage || this.busy) return;
    if (!await window.CoreChatPopups.confirm(`Delete :${emoji.name}: from the custom emoji picker? Existing messages will keep their image.`)) {
      target.focus(); return;
    }
    this.busy = true; this.uploadButton.disabled = true;
    this.status.textContent = `Deleting :${emoji.name}:...`;
    try {
      const form = new FormData(); form.append('action', 'delete'); form.append('id', emoji.id);
      const data = await this.upload(this.appUrl('/api/custom_emojis.php'), form);
      this.applyCatalog(data);
      this.status.textContent = `Deleted :${emoji.name}: from the picker. Existing messages keep their image.`;
      this.search.focus();
    } catch (error) {
      this.status.textContent = error.message || 'The emoji could not be deleted.';
      target.focus();
    } finally { this.busy = false; this.uploadButton.disabled = false; }
  }

  updateGuidance() {
    this.guidance.textContent = `PNG, GIF, WebP or JPEG. Maximum ${this.policy.maxBytes / 1024 / 1024} MB and ${this.policy.maxWidth} x ${this.policy.maxHeight} pixels. Animation is preserved. Names use lowercase letters, numbers, hyphens or underscores. Uploaded emojis are available to all members.`;
  }

  applyCatalog(data) {
    this.emojis = (Array.isArray(data.emojis) ? data.emojis : []).filter(emoji =>
      /^[a-f0-9]{32}$/.test(String(emoji.id)) && /^[a-z0-9_-]{1,32}$/.test(String(emoji.name)));
    this.canManage = data.canManage === true;
    this.manager.hidden = !this.canManage;
    for (const key of ['maxBytes', 'maxWidth', 'maxHeight']) {
      if (Number.isFinite(Number(data[key])) && Number(data[key]) > 0) this.policy[key] = Number(data[key]);
    }
    this.updateGuidance();
    this.render();
  }

  async activate() {
    if (this.pending || this.busy) return this.pending;
    this.status.textContent = 'Loading custom emojis...';
    this.pending = (async () => {
      try {
        const response = await fetch(this.appUrl('/api/custom_emojis.php'), { credentials: 'same-origin', cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Custom emojis could not be loaded.');
        this.applyCatalog(data);
        this.status.textContent = this.emojis.length ? 'Select an emoji to insert it into your message.' + (this.canManage ? ' Right-click an emoji to rename or delete it (keyboard: Shift+F10).' : '') : 'No custom emojis yet. An admin can add the first one.';
      } catch (error) {
        this.status.textContent = error.message || 'Custom emojis could not be loaded. Use Refresh to try again.';
      } finally {
        this.pending = null;
      }
    })();
    return this.pending;
  }

  render() {
    this.closeMenu();
    const query = this.search.value.trim().toLowerCase();
    const entries = this.emojis.filter(emoji => emoji.name.includes(query))
      .sort((a, b) => a.name.localeCompare(b.name));
    const fragment = document.createDocumentFragment();
    for (const emoji of entries.slice(0, this.visibleCount)) {
      const button = this.element('button', 'custom-emoji-choice');
      button.type = 'button';
      button.title = `:${emoji.name}:`;
      button.setAttribute('aria-label', `Insert ${emoji.name} emoji`);
      const image = this.element('img');
      image.src = this.appUrl(`/api/custom_emojis.php?action=image&id=${emoji.id}`);
      image.alt = `:${emoji.name}:`;
      image.width = 36;
      image.height = 36;
      image.loading = 'lazy';
      image.decoding = 'async';
      button.append(image, this.element('span', '', emoji.name));
      if (this.canManage) {
        button.title += ' — Right-click to rename or delete';
        button.setAttribute('aria-haspopup', 'menu');
        button.addEventListener('contextmenu', event => {
          event.preventDefault(); event.stopPropagation();
          this.openMenu(emoji, button, event.clientX, event.clientY);
        });
        button.addEventListener('keydown', event => {
          if (event.key !== 'ContextMenu' && !(event.shiftKey && event.key === 'F10')) return;
          event.preventDefault(); event.stopPropagation();
          const rect = button.getBoundingClientRect(); this.openMenu(emoji, button, rect.left, rect.bottom);
        });
      }
      button.addEventListener('click', () => {
        try { this.onSelect(emoji); }
        catch (error) { this.status.textContent = error.message || 'This emoji could not be inserted.'; }
      });
      fragment.append(button);
    }
    if (!entries.length && query) fragment.append(this.element('p', 'minor', 'No matching custom emojis.'));
    this.grid.replaceChildren(fragment);
    this.more.hidden = entries.length <= this.visibleCount;
  }

  async dimensions(file) {
    const url = URL.createObjectURL(file);
    try {
      return await new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
        image.onerror = () => reject(new Error('Custom emoji image could not be read. Choose a supported image file.'));
        image.src = url;
      });
    } finally { URL.revokeObjectURL(url); }
  }

  async submit() {
    if (this.busy || this.manager.hidden) return;
    const file = this.fileInput.files?.[0];
    const name = this.nameInput.value.trim().toLowerCase();
    this.busy = true;
    this.uploadButton.disabled = true;
    try {
      if (!/^[a-z0-9_-]{1,32}$/.test(name)) throw new Error('Custom emoji names must use 1 to 32 lowercase letters, numbers, hyphens or underscores.');
      if (!file || !['image/png', 'image/gif', 'image/webp', 'image/jpeg'].includes(file.type)) throw new Error('Choose a PNG, GIF, WebP or JPEG custom emoji image.');
      if (!file.size || file.size > this.policy.maxBytes) throw new Error(`Custom emoji images must be no larger than ${this.policy.maxBytes / 1024 / 1024} MB.`);
      const size = await this.dimensions(file);
      if (size.width > this.policy.maxWidth || size.height > this.policy.maxHeight) throw new Error(`Custom emojis must fit within ${this.policy.maxWidth} x ${this.policy.maxHeight} pixels. Resize the image before uploading.`);
      const form = new FormData();
      form.append('action', 'upload');
      form.append('name', name);
      form.append('file', file);
      this.status.textContent = 'Uploading custom emoji...';
      const data = await this.upload(this.appUrl('/api/custom_emojis.php'), form);
      this.applyCatalog(data);
      this.nameInput.value = '';
      this.fileInput.value = '';
      this.status.textContent = data.emoji?.duplicate
        ? `Already uploaded as :${data.emoji.name}:. Select that emoji below to use it.`
        : `:${name}: is ready for members to use.`;
    } catch (error) {
      this.status.textContent = error.message || 'Custom emoji upload failed.';
    } finally {
      this.busy = false;
      this.uploadButton.disabled = false;
    }
  }
}
