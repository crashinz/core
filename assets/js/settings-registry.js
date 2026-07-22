(function () {
  'use strict';

  const safeId = value => String(value || '').replace(/[^A-Za-z0-9_-]+/g, '-');
  const valuesEqual = (left, right, type) => {
    if (type === 'boolean' || type === 'fixed') return Boolean(left) === Boolean(right);
    if (type === 'number') return Number(left) === Number(right);
    return String(left ?? '') === String(right ?? '');
  };
  const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = text;
    return node;
  };

  const SETTINGS_UNLOCK_WARNING = 'Settings changes apply immediately after unlocking. Disabling active optional capabilities may stop them and restore their exact baseline; re-enabling a stopped capability does not automatically restart it. Presets and bulk operations may change multiple settings. Mandatory safeguards, security protections, and stored user content remain unchanged.';

  class SettingsUnlockController {
    constructor(options) {
      this.mount = options.mount;
      this.activityRoot = options.activityRoot || this.mount?.parentElement || document;
      this.authorized = options.authorized !== false;
      this.inactivityMs = Math.max(1000, Number(options.inactivityMs || 300000));
      this.onLockChange = options.onLockChange || (() => {});
      this.locked = true;
      this.inactivityTimer = null;
      this.pointerStartX = null;
      this.pointerWidth = 0;
      this.keyboardActive = false;
      this.render();
      this.bind();
      this.updatePresentation();
      this.onLockChange(true, 'initial');
    }

    render() {
      if (!this.mount) return;
      this.mount.textContent = '';
      this.mount.classList.add('settings-unlock');
      const heading = element('div', 'settings-unlock-heading');
      const title = element('strong', '', 'Unlock settings changes');
      this.state = element('span', 'settings-unlock-state', 'Locked');
      heading.append(title, this.state);
      this.warning = element('p', 'settings-unlock-warning', SETTINGS_UNLOCK_WARNING);
      this.warning.id = `${this.mount.id || 'settings-unlock'}-warning`;
      const control = element('div', 'settings-unlock-control');
      this.slider = document.createElement('input');
      this.slider.className = 'settings-unlock-slider';
      this.slider.type = 'range';
      this.slider.min = '0';
      this.slider.max = '100';
      this.slider.step = '10';
      this.slider.value = '0';
      this.slider.setAttribute('aria-label', 'Slide to unlock settings changes');
      this.slider.setAttribute('aria-describedby', this.warning.id);
      this.slider.setAttribute('aria-valuetext', 'Locked. Slide fully to unlock.');
      this.slider.autocomplete = 'off';
      this.hint = element('span', 'settings-unlock-hint', 'Slide fully to unlock. Keyboard: press End, or use the arrow keys to reach 100%.');
      control.append(this.slider, this.hint);
      this.lockNow = element('button', 'btn settings-unlock-lock-now', 'Lock now');
      this.lockNow.type = 'button';
      this.lockNow.hidden = true;
      this.status = element('div', 'settings-unlock-status', 'Settings changes are locked.');
      this.status.setAttribute('role', 'status');
      this.status.setAttribute('aria-live', 'polite');
      this.mount.append(heading, this.warning, control, this.lockNow, this.status);
    }

    bind() {
      if (!this.slider) return;
      this.slider.addEventListener('pointerdown', event => {
        this.keyboardActive = false;
        this.pointerStartX = event.clientX;
        this.pointerWidth = Math.max(1, this.slider.getBoundingClientRect().width);
      });
      this.slider.addEventListener('pointerup', event => {
        const distance = this.pointerStartX === null ? 0 : event.clientX - this.pointerStartX;
        const deliberate = distance >= this.pointerWidth * 0.6 && Number(this.slider.value) >= 90;
        this.pointerStartX = null;
        if (deliberate) this.unlock('slide');
        else this.resetSlider();
      });
      this.slider.addEventListener('pointercancel', () => {
        this.pointerStartX = null;
        this.resetSlider();
      });
      this.slider.addEventListener('keydown', event => {
        this.keyboardActive = true;
        if (event.key === 'End') {
          event.preventDefault();
          this.slider.value = '100';
          this.unlock('keyboard');
        }
      });
      this.slider.addEventListener('input', () => {
        const value = Number(this.slider.value);
        this.slider.setAttribute('aria-valuetext', value >= 100 ? 'Ready to unlock.' : `Locked. ${value}% complete.`);
        if (this.keyboardActive && value >= 100) this.unlock('keyboard');
      });
      this.slider.addEventListener('blur', () => {
        this.keyboardActive = false;
        if (this.locked) this.resetSlider();
      });
      this.lockNow?.addEventListener('click', () => this.relock('Settings changes locked.'));
      for (const eventName of ['pointerdown', 'keydown', 'input']) {
        this.activityRoot?.addEventListener?.(eventName, () => this.noteActivity(), { passive: true });
      }
      window.addEventListener('pagehide', () => this.relock('', ''));
    }

    resetSlider() {
      if (!this.slider) return;
      this.slider.value = '0';
      this.slider.setAttribute('aria-valuetext', 'Locked. Slide fully to unlock.');
    }

    updatePresentation() {
      if (!this.slider) return;
      this.slider.disabled = !this.authorized || !this.locked;
      this.lockNow.hidden = this.locked;
      this.state.textContent = this.locked ? 'Locked' : 'Unlocked';
      this.mount.classList.toggle('is-unlocked', !this.locked);
      if (!this.authorized) {
        this.state.textContent = 'Locked — authorization required';
        this.hint.textContent = 'You are not authorized to change registry-backed settings.';
      } else {
        this.hint.textContent = this.locked
          ? 'Slide fully to unlock. Keyboard: press End, or use the arrow keys to reach 100%.'
          : 'Settings changes are temporarily unlocked for this presentation.';
      }
    }

    announce(message, type = '') {
      if (!this.status) return;
      this.status.textContent = message || '';
      this.status.className = `settings-unlock-status ${type}`.trim();
    }

    unlock(source = 'slide') {
      if (!this.authorized || !this.locked) return false;
      this.locked = false;
      this.clearTimer();
      this.updatePresentation();
      this.announce(`Settings changes unlocked by ${source === 'keyboard' ? 'keyboard' : 'slide'}.`, 'ok');
      this.onLockChange(false, source);
      this.noteActivity();
      return true;
    }

    relock(message = 'Settings changes locked.', reason = 'manual') {
      const changed = !this.locked;
      this.locked = true;
      this.clearTimer();
      this.resetSlider();
      this.updatePresentation();
      if (message) this.announce(message, reason === 'authorization' ? 'error' : '');
      if (changed) this.onLockChange(true, reason);
    }

    setAuthorized(authorized, message = '') {
      this.authorized = Boolean(authorized);
      if (!this.authorized) this.relock(message || 'You are no longer authorized to change these settings.', 'authorization');
      this.updatePresentation();
    }

    noteActivity() {
      if (this.locked) return;
      this.clearTimer();
      this.inactivityTimer = window.setTimeout(() => {
        this.relock('Settings changes locked after a period of inactivity.', 'inactivity');
      }, this.inactivityMs);
    }

    clearTimer() {
      if (this.inactivityTimer !== null) window.clearTimeout(this.inactivityTimer);
      this.inactivityTimer = null;
    }

    requireUnlocked() {
      if (!this.authorized) {
        this.announce('You are no longer authorized to change these settings.', 'error');
        return false;
      }
      if (this.locked) {
        this.announce('Unlock settings changes before changing registry-backed settings.', 'error');
        this.slider?.focus();
        return false;
      }
      this.noteActivity();
      return true;
    }

    isUnlocked() {
      return this.authorized && !this.locked;
    }
  }

  class SettingsRegistryUI {
    constructor(options) {
      this.container = options.container;
      this.searchInput = options.searchInput || null;
      this.filterInput = options.filterInput || null;
      this.readOnly = Boolean(options.readOnly);
      this.locked = options.locked !== false;
      this.onDraftChange = options.onDraftChange || (() => {});
      this.onEntryChange = options.onEntryChange || (() => {});
      this.onOperation = options.onOperation || null;
      this.registry = null;
      this.entries = [];
      this.entryMap = new Map();
      this.draft = new Map();
      this.touched = new Set();
      this.controls = new Map();
      this.query = '';
      this.filter = 'all';
      this.searchInput?.addEventListener('input', () => {
        this.query = this.searchInput.value.trim().toLocaleLowerCase();
        this.applySearchAndFilter();
      });
      this.filterInput?.addEventListener('change', () => {
        this.filter = this.filterInput.value || 'all';
        this.applySearchAndFilter();
      });
      if (options.registry) this.setRegistry(options.registry);
    }

    setRegistry(registry) {
      this.registry = registry;
      this.entries = Array.isArray(registry?.visibleEntries)
        ? registry.visibleEntries
        : (registry?.entries || []).filter(entry => entry.visibleOnSurface);
      this.entryMap = new Map(this.entries.map(entry => [entry.id, entry]));
      this.draft = new Map(this.entries.map(entry => [entry.id, entry.currentValue]));
      this.touched.clear();
      this.render();
      this.onDraftChange(this.getState());
    }

    readControlValue(entry, input) {
      if (entry.type === 'boolean') return input.checked;
      if (entry.type === 'number') return input.value === '' ? '' : Number(input.value);
      return input.value;
    }

    createControl(entry) {
      const id = `settings-registry-${safeId(entry.id)}`;
      if (entry.type === 'fixed') {
        const fixed = element('div', 'settings-fixed-value', 'Always enforced');
        fixed.setAttribute('role', 'status');
        return fixed;
      }
      if (entry.type === 'asset') {
        const input = document.createElement('input');
        input.type = 'file';
        input.name = 'community_logo';
        input.id = id;
        input.accept = 'image/jpeg,image/png,image/gif,image/webp';
        input.disabled = this.readOnly || this.locked;
        this.controls.set(entry.id, input);
        return input;
      }
      if (entry.type === 'boolean') {
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.id = id;
        input.name = `setting[${entry.id}]`;
        input.checked = Boolean(this.draft.get(entry.id));
        input.disabled = this.readOnly || this.locked;
        input.addEventListener('change', () => this.updateDraft(entry, input));
        this.controls.set(entry.id, input);
        return input;
      }
      if (entry.type === 'select') {
        const select = document.createElement('select');
        select.id = id;
        select.name = `setting[${entry.id}]`;
        for (const value of entry.allowedValues || []) {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = value.replaceAll('-', ' ').replace(/\b\w/g, match => match.toUpperCase());
          select.appendChild(option);
        }
        select.value = String(this.draft.get(entry.id) ?? '');
        select.disabled = this.readOnly || this.locked;
        select.addEventListener('change', () => this.updateDraft(entry, select));
        this.controls.set(entry.id, select);
        return select;
      }
      const input = document.createElement('input');
      input.id = id;
      input.name = `setting[${entry.id}]`;
      input.type = entry.type === 'secret' ? 'password' : (entry.type === 'color' ? 'color' : (entry.type === 'number' ? 'number' : 'text'));
      if (entry.minimum !== null && entry.minimum !== undefined) input.min = String(entry.minimum);
      if (entry.maximum !== null && entry.maximum !== undefined) input.max = String(entry.maximum);
      if (entry.step !== null && entry.step !== undefined) input.step = String(entry.step);
      if (entry.type === 'string' && entry.maximum) input.maxLength = Number(entry.maximum);
      input.value = entry.type === 'secret' ? '' : String(this.draft.get(entry.id) ?? '');
      if (entry.type === 'secret' && entry.hasStoredValue) input.placeholder = 'Stored — enter a new value to replace';
      input.disabled = this.readOnly || this.locked;
      input.addEventListener('input', () => this.updateDraft(entry, input));
      this.controls.set(entry.id, input);
      return input;
    }

    updateDraft(entry, input) {
      if (this.readOnly || this.locked) return;
      this.draft.set(entry.id, this.readControlValue(entry, input));
      this.touched.add(entry.id);
      const card = input.closest('[data-setting-id]');
      card?.classList.toggle('is-dirty', this.isDirty(entry));
      this.updateSummaries();
      this.applySearchAndFilter();
      this.onDraftChange(this.getState());
      this.onEntryChange(entry, this.draft.get(entry.id), this.getState());
    }

    isDirty(entry) {
      if (entry.type === 'asset' || entry.type === 'fixed') return false;
      if (entry.type === 'secret') return this.touched.has(entry.id) && String(this.draft.get(entry.id) || '') !== '';
      return !valuesEqual(this.draft.get(entry.id), entry.currentValue, entry.type);
    }

    changedValues() {
      const values = {};
      for (const entry of this.entries) if (this.isDirty(entry)) values[entry.id] = this.draft.get(entry.id);
      return values;
    }

    getValues() {
      const values = {};
      for (const entry of this.entries) {
        if (entry.type === 'fixed' || entry.type === 'asset' || entry.type === 'secret') continue;
        values[entry.id] = this.draft.get(entry.id);
      }
      for (const entry of this.entries.filter(item => item.type === 'secret')) {
        if (this.isDirty(entry)) values[entry.id] = this.draft.get(entry.id);
      }
      return values;
    }

    getState() {
      const changed = this.changedValues();
      return { changed, changedCount: Object.keys(changed).length, values: this.getValues(), compatibilityState: this.compatibilityState() };
    }

    entrySearchText(entry) {
      const category = (this.registry?.categories || []).find(item => item.id === entry.categoryId)?.label || '';
      return [entry.label, entry.description, entry.helpText, category, entry.subsectionLabel, entry.id, ...(entry.aliases || [])].join(' ').toLocaleLowerCase();
    }

    draftEnabled(entry) {
      const value = this.draft.get(entry.id);
      if (!entry.optional) return null;
      if (entry.type === 'boolean') return Boolean(value);
      if (entry.id === 'role_colors_mode') return value !== 'disabled';
      return null;
    }

    draftChangedFromDefault(entry) {
      if (entry.type === 'secret' && !this.touched.has(entry.id)) return Boolean(entry.changedFromDefault);
      return !valuesEqual(this.draft.get(entry.id), entry.defaultValue, entry.type);
    }

    matches(entry) {
      if (this.query && !this.entrySearchText(entry).includes(this.query)) return false;
      if (this.filter === 'enabled') return this.draftEnabled(entry) === true;
      if (this.filter === 'disabled') return this.draftEnabled(entry) === false;
      if (this.filter === 'changed') return this.draftChangedFromDefault(entry);
      if (this.filter === 'original') return Boolean(entry.originalRelevant);
      return true;
    }

    applySearchAndFilter() {
      for (const card of this.container.querySelectorAll('[data-setting-id]')) {
        const entry = this.entryMap.get(card.dataset.settingId);
        card.hidden = !entry || !this.matches(entry);
      }
      for (const subsection of this.container.querySelectorAll('[data-settings-subsection]')) {
        const visible = Array.from(subsection.querySelectorAll('[data-setting-id]')).some(card => !card.hidden);
        subsection.hidden = !visible;
      }
      for (const category of this.container.querySelectorAll('[data-settings-category]')) {
        const visible = Array.from(category.querySelectorAll('[data-setting-id]')).some(card => !card.hidden);
        category.hidden = !visible;
        if (visible && (this.query || this.filter !== 'all')) category.open = true;
      }
      let empty = this.container.querySelector('.settings-registry-empty');
      const any = Array.from(this.container.querySelectorAll('[data-setting-id]')).some(card => !card.hidden);
      if (!any && !empty) {
        empty = element('p', 'settings-registry-empty', 'No settings match this search and filter.');
        this.container.appendChild(empty);
      }
      if (empty) empty.hidden = any;
    }

    summaryFor(entries) {
      const changed = entries.filter(entry => this.draftChangedFromDefault(entry)).length;
      const optional = entries.filter(entry => entry.optional);
      const enabled = optional.filter(entry => this.draftEnabled(entry) === true).length;
      const parts = [`${changed} changed`];
      if (optional.length) parts.push(`${enabled} of ${optional.length} optional enabled`);
      return parts.join(' · ');
    }

    updateSummaries() {
      for (const node of this.container.querySelectorAll('[data-settings-summary]')) {
        const ids = String(node.dataset.settingIds || '').split(',').filter(Boolean);
        node.textContent = this.summaryFor(ids.map(id => this.entryMap.get(id)).filter(Boolean));
      }
    }

    operationButton(label, operation, details, className = 'btn') {
      const button = element('button', className, label);
      button.type = 'button';
      button.disabled = this.readOnly || this.locked;
      button.addEventListener('click', async () => {
        if (!this.onOperation) return;
        button.disabled = true;
        try { await this.onOperation(operation, details, this); } finally { button.disabled = this.readOnly || this.locked; }
      });
      return button;
    }

    renderEntry(entry) {
      const card = element('article', `settings-entry settings-entry-${safeId(entry.controlClass)}`);
      card.dataset.settingId = entry.id;
      card.dataset.settingType = entry.type;
      const heading = element('div', 'settings-entry-heading');
      const label = element('label', 'settings-entry-label', entry.label);
      if (entry.type !== 'fixed') label.htmlFor = `settings-registry-${safeId(entry.id)}`;
      heading.appendChild(label);
      const badges = element('div', 'settings-entry-badges');
      badges.appendChild(element('span', `settings-badge settings-badge-${safeId(entry.controlClass)}`, entry.controlClass === 'optional' ? 'Optional' : (entry.controlClass === 'mandatory-fixed' ? 'Mandatory' : 'Configurable')));
      if (entry.changedFromDefault) badges.appendChild(element('span', 'settings-badge settings-badge-changed', 'Changed'));
      if (entry.originalRelevant) badges.appendChild(element('span', 'settings-badge settings-badge-original', 'Original relevant'));
      heading.appendChild(badges);
      card.appendChild(heading);
      card.appendChild(element('p', 'settings-entry-description', entry.description));
      if (entry.helpText) card.appendChild(element('p', 'minor settings-entry-help', entry.helpText));
      if (entry.fixedReason) card.appendChild(element('p', 'minor settings-entry-fixed-reason', entry.fixedReason));
      const controlRow = element('div', 'settings-entry-control');
      controlRow.appendChild(this.createControl(entry));
      if (entry.safeToReset && entry.type !== 'asset' && entry.type !== 'fixed') controlRow.appendChild(this.operationButton('Reset', 'reset_setting', { setting_id: entry.id }, 'btn settings-entry-reset'));
      card.appendChild(controlRow);
      const meta = element('div', 'settings-entry-meta');
      meta.appendChild(element('code', '', entry.id));
      meta.appendChild(element('span', '', `Default: ${entry.type === 'boolean' ? (entry.defaultValue ? 'Enabled' : 'Disabled') : entry.defaultValue}`));
      card.appendChild(meta);
      return card;
    }

    setDraftValues(values) {
      if (this.readOnly || this.locked) return;
      for (const [id, value] of Object.entries(values || {})) {
        const entry = this.entryMap.get(id);
        if (!entry || entry.type === 'asset' || entry.type === 'fixed') continue;
        this.draft.set(id, value);
        this.touched.add(id);
        const control = this.controls.get(id);
        if (control) {
          if (entry.type === 'boolean') control.checked = Boolean(value);
          else control.value = String(value ?? '');
          control.closest('[data-setting-id]')?.classList.toggle('is-dirty', this.isDirty(entry));
        }
      }
      this.updateSummaries();
      this.applySearchAndFilter();
      this.onDraftChange(this.getState());
    }

    resetDraft(ids) {
      if (this.readOnly || this.locked) return;
      const values = {};
      for (const id of ids) {
        const entry = this.entryMap.get(id);
        if (entry?.safeToReset) values[id] = entry.defaultValue;
      }
      this.setDraftValues(values);
    }

    presetChanges(preset) {
      const changes = [];
      for (const entry of this.entries) {
        if (!entry.originalRelevant || !entry.originalValueAvailable || !entry.safeToReset) continue;
        const target = preset === 'original-compatible' ? entry.originalValue : entry.defaultValue;
        if (!valuesEqual(this.draft.get(entry.id), target, entry.type)) changes.push({ entry, from: this.draft.get(entry.id), to: target });
      }
      return changes;
    }

    compatibilityState() {
      const relevant = this.entries.filter(entry => entry.originalRelevant && entry.originalValueAvailable);
      if (relevant.length && relevant.every(entry => valuesEqual(this.draft.get(entry.id), entry.originalValue, entry.type))) return 'original-compatible';
      if (relevant.length && relevant.every(entry => valuesEqual(this.draft.get(entry.id), entry.defaultValue, entry.type))) return 'framework-default';
      return 'custom';
    }

    render() {
      this.container.textContent = '';
      this.controls.clear();
      const categories = [...(this.registry?.categories || [])].sort((a, b) => Number(a.order) - Number(b.order));
      for (const category of categories) {
        const categoryEntries = this.entries.filter(entry => entry.categoryId === category.id);
        if (!categoryEntries.length) continue;
        const details = element('details', 'settings-category');
        details.dataset.settingsCategory = category.id;
        details.open = true;
        const summary = element('summary', 'settings-category-summary');
        const title = element('span', 'settings-category-title', category.label);
        const counts = element('span', 'settings-category-counts');
        counts.dataset.settingsSummary = 'category';
        counts.dataset.settingIds = categoryEntries.map(entry => entry.id).join(',');
        counts.textContent = this.summaryFor(categoryEntries);
        summary.append(title, counts);
        details.appendChild(summary);
        const categoryActions = element('div', 'settings-scope-actions');
        if (categoryEntries.some(entry => entry.safeToReset)) {
          categoryActions.appendChild(this.operationButton('Reset Category', 'reset_category', { category_id: category.id }, 'btn'));
          details.appendChild(categoryActions);
        }
        const subsectionIds = [...new Set(categoryEntries.map(entry => entry.subsectionId))];
        for (const subsectionId of subsectionIds) {
          const sectionEntries = categoryEntries.filter(entry => entry.subsectionId === subsectionId);
          const section = element('section', 'settings-subsection');
          section.dataset.settingsSubsection = subsectionId;
          const header = element('div', 'settings-subsection-heading');
          const headerText = element('div');
          headerText.appendChild(element('h3', '', sectionEntries[0].subsectionLabel));
          const sectionCounts = element('span', 'minor');
          sectionCounts.dataset.settingsSummary = 'subsection';
          sectionCounts.dataset.settingIds = sectionEntries.map(entry => entry.id).join(',');
          sectionCounts.textContent = this.summaryFor(sectionEntries);
          headerText.appendChild(sectionCounts);
          header.appendChild(headerText);
          const actions = element('div', 'shared-form-actions');
          if (sectionEntries.some(entry => entry.bulkGroup === 'dances')) {
            actions.appendChild(this.operationButton('Enable All Dances', 'set_many', { values: Object.fromEntries(sectionEntries.map(entry => [entry.id, true])) }, 'btn'));
            actions.appendChild(this.operationButton('Disable All Dances', 'set_many', { values: Object.fromEntries(sectionEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
          }
          if (sectionEntries.some(entry => entry.bulkGroup === 'gesture-part-3')) {
            const gestureEntries = sectionEntries.filter(entry => entry.bulkGroup === 'gesture-part-3');
            actions.appendChild(this.operationButton('Enable All Gesture Features', 'set_many', { values: Object.fromEntries(gestureEntries.map(entry => [entry.id, true])) }, 'btn'));
            actions.appendChild(this.operationButton('Disable All Gesture Features', 'set_many', { values: Object.fromEntries(gestureEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
          }
          if (sectionEntries.some(entry => entry.safeToReset)) actions.appendChild(this.operationButton('Reset Subsection', 'reset_subsection', { category_id: category.id, subsection_id: subsectionId }, 'btn'));
          header.appendChild(actions);
          section.appendChild(header);
          const grid = element('div', 'settings-entry-grid');
          for (const entry of sectionEntries) grid.appendChild(this.renderEntry(entry));
          section.appendChild(grid);
          details.appendChild(section);
        }
        this.container.appendChild(details);
      }
      this.applySearchAndFilter();
    }

    setLocked(locked) {
      this.locked = Boolean(locked);
      for (const control of this.controls.values()) control.disabled = this.readOnly || this.locked;
      for (const button of this.container.querySelectorAll('button')) button.disabled = this.readOnly || this.locked;
      this.container.classList.toggle('is-settings-locked', this.locked);
    }
  }

  window.SettingsRegistryUI = SettingsRegistryUI;
  window.SettingsUnlockController = SettingsUnlockController;
})();
