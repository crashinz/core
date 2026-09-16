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
  const applyProgrammaticHeadingFocus = heading => {
    if (!heading) return;
    document.querySelectorAll('[data-admin-programmatic-heading]').forEach(prior => {
      if (prior !== heading) prior.removeAttribute('data-admin-programmatic-heading');
    });
    heading.setAttribute('data-admin-programmatic-heading', 'true');
    if (!/^H[1-6]$/.test(heading.tagName)) {
      heading.setAttribute('role', 'heading');
      heading.setAttribute('aria-level', '2');
    }
    heading.tabIndex = -1;
    heading.focus({ preventScroll: true });
  };
  window.applyProgrammaticHeadingFocus = applyProgrammaticHeadingFocus;
  const normalizeHex = value => {
    const normalized = String(value || '').trim().toUpperCase();
    return /^#[0-9A-F]{6}$/.test(normalized) ? normalized : '';
  };
  const relativeLuminance = value => {
    const hex = normalizeHex(value);
    if (!hex) return null;
    const channels = [1, 3, 5].map(offset => {
      const channel = Number.parseInt(hex.slice(offset, offset + 2), 16) / 255;
      return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });
    return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
  };
  const contrastRatio = (background, foreground) => {
    const left = relativeLuminance(background);
    const right = relativeLuminance(foreground);
    if (left === null || right === null) return null;
    return (Math.max(left, right) + 0.05) / (Math.min(left, right) + 0.05);
  };
  const COMMON_COLORS = [
    ['Black', '#000000'],
    ['White', '#FFFFFF'],
    ['Slate', '#334155'],
    ['Gray', '#6B7280'],
    ['Red', '#B91C1C'],
    ['Orange', '#C2410C'],
    ['Gold', '#A16207'],
    ['Green', '#15803D'],
    ['Teal', '#0F766E'],
    ['Blue', '#1D4ED8'],
    ['Indigo', '#4338CA'],
    ['Purple', '#7E22CE'],
  ];

  const SETTINGS_UNLOCK_WARNING = 'Changes remain pending until you choose Save Changes. Turning off an active optional feature may stop it and restore its saved baseline; turning it back on does not automatically restart it. Presets and bulk actions may change multiple settings. Safeguards, security protections, and stored user content remain unchanged.';

  class SettingsUnlockController {
    constructor(options) {
      this.mount = options.mount;
      this.activityRoot = options.activityRoot || this.mount?.parentElement || document;
      this.authorized = options.authorized !== false;
      this.inactivityMs = Math.max(1000, Number(options.inactivityMs || 300000));
      this.onLockChange = options.onLockChange || (() => {});
      this.locked = true;
      this.inactivityTimer = null;
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
      this.unlockButton = element('button', 'btn btn-primary settings-unlock-button', 'Unlock settings changes');
      this.unlockButton.type = 'button';
      this.unlockButton.setAttribute('aria-describedby', this.warning.id);
      this.hint = element('span', 'settings-unlock-hint', 'Select Unlock settings changes to enable authorized controls temporarily.');
      control.append(this.unlockButton, this.hint);
      this.lockNow = element('button', 'btn settings-unlock-lock-now', 'Lock now');
      this.lockNow.type = 'button';
      this.lockNow.hidden = true;
      this.status = element('div', 'settings-unlock-status', 'Settings changes are locked.');
      this.status.setAttribute('role', 'status');
      this.status.setAttribute('aria-live', 'polite');
      this.mount.append(heading, control, this.warning, this.lockNow, this.status);
    }

    bind() {
      if (!this.unlockButton) return;
      this.unlockButton.addEventListener('click', event => {
        this.unlock(event.detail === 0 ? 'keyboard' : 'button');
      });
      this.unlockButton.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        this.unlock('keyboard');
      });
      this.lockNow?.addEventListener('click', () => this.relock('Settings changes locked.'));
      for (const eventName of ['pointerdown', 'keydown', 'input']) {
        this.activityRoot?.addEventListener?.(eventName, () => this.noteActivity(), { passive: true });
      }
      this.activityRoot?.addEventListener?.('pointerdown', event => {
        if (!this.authorized || !this.locked || this.unlockDialog?.open) return;
        if (this.mount?.contains(event.target)) return;
        const selector = 'button:disabled, input:disabled, select:disabled, textarea:disabled, [aria-disabled="true"]';
        let control = event.target?.closest?.(selector);
        // Some disabled controls do not receive pointer events themselves.
        if (!control) control = [...this.activityRoot.querySelectorAll(selector)].find(node => {
          if (!node.getClientRects().length) return false;
          const box = node.getBoundingClientRect();
          return event.clientX >= box.left && event.clientX <= box.right
            && event.clientY >= box.top && event.clientY <= box.bottom;
        });
        if (!control || !this.activityRoot.contains(control)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        requestAnimationFrame(() => this.showUnlockPrompt(control));
      }, true);
      window.addEventListener('pagehide', () => {
        this.closeUnlockPrompt();
        this.relock('', '');
      });
    }

    showUnlockPrompt(returnTarget = document.activeElement) {
      if (!this.authorized || !this.locked || !this.mount?.isConnected) return;
      if (this.unlockDialog?.open) { this.unlockButton.focus(); return; }
      const dialog = document.createElement('dialog');
      dialog.className = 'settings-unlock-dialog';
      dialog.setAttribute('aria-label', 'Unlock settings changes');
      const message = element('p', '', 'Settings are locked. Unlock here, then retry your action. Unlocking does not save or perform that action.');
      const close = element('button', 'btn', 'Cancel');
      close.type = 'button';
      const home = document.createComment('Settings unlock panel returns here');
      this.mount.before(home);
      dialog.append(message, this.mount, close);
      document.body.appendChild(dialog);
      this.unlockDialog = dialog;
      // Move the actual panel, including its one existing unlock button.
      // Keep its handlers and the same authorization/inactivity controller.
      const restore = () => {
        home.replaceWith(this.mount);
        dialog.remove();
        if (this.unlockDialog === dialog) this.unlockDialog = null;
        if (returnTarget?.isConnected && !returnTarget.disabled) returnTarget.focus?.({ preventScroll: true });
      };
      dialog.addEventListener('close', restore, { once: true });
      close.addEventListener('click', () => dialog.close());
      try { dialog.showModal(); this.unlockButton.focus(); }
      catch (error) { restore(); this.unlockButton.focus(); }
    }

    closeUnlockPrompt() {
      if (this.unlockDialog?.open) this.unlockDialog.close();
    }

    updatePresentation() {
      if (!this.unlockButton) return;
      this.unlockButton.disabled = !this.authorized || !this.locked;
      this.lockNow.hidden = this.locked;
      this.state.textContent = this.locked ? 'Locked' : 'Unlocked';
      this.mount.classList.toggle('is-unlocked', !this.locked);
      if (!this.authorized) {
        this.state.textContent = 'Locked — authorization required';
        this.hint.textContent = 'You are not authorized to change these settings.';
      } else {
        this.hint.textContent = this.locked
          ? 'Select Unlock settings changes to enable authorized controls temporarily.'
          : 'Settings changes are temporarily unlocked for this presentation.';
      }
    }

    announce(message, type = '') {
      if (!this.status) return;
      this.status.textContent = message || '';
      this.status.className = `settings-unlock-status ${type}`.trim();
    }

    unlock(source = 'button') {
      if (!this.authorized || !this.locked) return false;
      this.locked = false;
      this.clearTimer();
      this.updatePresentation();
      this.announce(`Settings changes unlocked by ${source === 'keyboard' ? 'keyboard' : 'button'}.`, 'ok');
      this.onLockChange(false, source);
      this.closeUnlockPrompt();
      this.noteActivity();
      return true;
    }

    relock(message = 'Settings changes locked.', reason = 'manual') {
      const changed = !this.locked;
      this.locked = true;
      this.clearTimer();
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
        this.announce('Unlock settings changes before making an edit.', 'error');
        this.showUnlockPrompt();
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
      this.onAssetChange = options.onAssetChange || null;
      this.onMediaPackAction = options.onMediaPackAction || null;
      this.onViewChange = options.onViewChange || (() => {});
      this.categoryNavigation = Boolean(options.categoryNavigation);
      this.navigationLabel = options.navigationLabel || 'Settings section';
      this.sessionKey = options.sessionKey || `chatspace.settings-section:${document.body?.dataset?.appBase || '/'}`;
      this.lockDescriptionId = options.lockDescriptionId
        || this.container.closest('[data-settings-scroll-owner]')?.querySelector('.settings-unlock-warning')?.id
        || '';
      this.registry = null;
      this.entries = [];
      this.entryMap = new Map();
      this.draft = new Map();
      this.touched = new Set();
      this.controls = new Map();
      this.query = '';
      this.filter = 'all';
      this.selectedView = 'overview';
      this.lastCategoryView = '';
      this.expandedHelpId = '';
      this.helpReturnControl = null;
      this.searchInput?.addEventListener('input', () => {
        this.query = this.searchInput.value.trim().toLocaleLowerCase();
        this.render();
      });
      this.filterInput?.addEventListener('change', () => {
        this.filter = this.filterInput.value || 'all';
        this.render();
      });
      if (this.categoryNavigation) {
        window.addEventListener('popstate', () => {
          const fragment = String(window.location.hash || '').replace(/^#settings-/, '');
          if (!fragment || fragment === this.selectedView) return;
          this.selectView(fragment, false);
        });
      }
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
      if (this.categoryNavigation) {
        const fragment = String(window.location.hash || '').replace(/^#settings-/, '');
        const saved = window.sessionStorage?.getItem(this.sessionKey) || '';
        const candidate = fragment || saved;
        const valid = candidate === 'overview'
          || candidate === 'limits'
          || (this.registry?.categories || []).some(category => category.id === candidate);
        this.selectedView = valid ? candidate : 'overview';
        if (this.selectedView !== 'overview' && this.selectedView !== 'limits') this.lastCategoryView = this.selectedView;
      }
      this.render();
      this.onViewChange(this.selectedView);
      const reminder = this.entryMap.get('branding_license_reminder');
      if (reminder) {
        document.querySelectorAll('[data-branding-reminder-authority]').forEach(node => {
          node.textContent = String(reminder.currentValue || reminder.defaultValue || '');
        });
      }
      this.onDraftChange(this.getState());
      this.syncSurfacePresentation();
    }

    unitFor(entry) {
      if (entry.type !== 'number') return '';
      if (entry.unit) return String(entry.unit);
      const id = String(entry.id || '');
      const label = String(entry.label || '').toLocaleLowerCase();
      if (/_mb$/.test(id) || /\bmb\b/.test(label)) return 'MB';
      if (/_px$/.test(id) || /\bpixels?\b|\bwidth\b|\bheight\b/.test(label)) return 'pixels';
      if (/minutes?/.test(id) || /\bminutes?\b|\btimeout\b|\blockout\b|\bwindow\b/.test(label)) return 'minutes';
      if (/attempt/.test(id) || /\battempts?\b/.test(label)) return 'attempts';
      if (/relationship.*links|members?/.test(id) || /\bmembers?\b/.test(label)) return 'members';
      if (/history/.test(id)) return 'messages';
      if (/gesture_upload_limit/.test(id)) return 'gestures';
      if (/character limit/.test(label)) return 'characters';
      if (/per_second/.test(id)) return 'per second';
      if (/age/.test(id)) return 'years';
      return '';
    }

    rangeFor(entry) {
      if (entry.minimum === null || entry.minimum === undefined || entry.maximum === null || entry.maximum === undefined) return '';
      return `${entry.minimum} to ${entry.maximum}${this.unitFor(entry) ? ` ${this.unitFor(entry)}` : ''}`;
    }

    inheritedSourceLabel(entry) {
      const source = String(entry.inheritanceSource || '').toLocaleLowerCase();
      if (source.includes('community') && source.includes('name')) return 'Community name';
      if (source.includes('brand')) return 'Shared Branding';
      return 'shared value';
    }

    plainPresentationText(value, entry = null) {
      let text = String(value || '');
      if (entry?.allowsOverride) text = text.replace(/Use shared value/gi, `Use ${this.inheritedSourceLabel(entry)}`);
      return text
        .replace(/\bmandatory[- ]core\b/gi, 'required')
        .replace(/\boptional[- ]core\b/gi, 'optional')
        .replace(/\brepository-owned\b/gi, 'included')
        .replace(/\bsource-backed\b/gi, 'verified')
        .replace(/\b(?:Post-)?Build\s+\d{6}\b/gi, 'this release')
        .replace(/\bPart 3\b/gi, 'gesture browsing')
        .replace(/\bPart 4\b/gi, 'gesture creation')
        .replace(/\bPart 5\b/gi, 'main gesture')
        .replace(/\bcheckpoint\b/gi, 'update')
        .replace(/\bcanonical package owner\b/gi, 'package importer')
        .replace(/\bcanonical\b/gi, 'supported')
        .replace(/\brequirements transfer\b/gi, 'requirements')
        .replace(/\bimplementation owner\b/gi, 'responsible component')
        .replace(/\bverification owner\b/gi, 'automated checks')
        .replace(/\bframework owner\b/gi, 'responsible component')
        .replace(/\bserver-authoritative\b/gi, 'server-enforced')
        .replace(/\bshared registry\b/gi, 'shared settings')
        .replace(/\bregistry-backed\b/gi, 'saved')
        .replace(/\bframework default\b/gi, 'recommended default')
        .replace(/\bprovenance\b/gi, 'source information')
        .replace(/\bmigrations\b/gi, 'database updates')
        .replace(/\bmigration\b/gi, 'database update');
    }

    ensureHelpPanelClearance(help) {
      const scrollOwner = help?.closest?.('[data-settings-scroll-owner]');
      const stickyActions = scrollOwner?.querySelector?.('.settings-registry-sticky-actions');
      if (!scrollOwner || help.hidden) return;
      const ownerRect = scrollOwner.getBoundingClientRect();
      const helpRect = help.getBoundingClientRect();
      const stickyRect = stickyActions?.getBoundingClientRect?.() || null;
      const gap = 12;
      const safeTop = ownerRect.top + gap;
      const safeBottom = Math.min(
        ownerRect.bottom,
        stickyRect ? stickyRect.top : ownerRect.bottom,
      ) - gap;
      let delta = 0;
      if (helpRect.bottom > safeBottom) delta = helpRect.bottom - safeBottom;
      else if (helpRect.top < safeTop) delta = helpRect.top - safeTop;
      if (Math.abs(delta) >= 1) scrollOwner.scrollBy({ top: Math.ceil(delta), behavior: 'auto' });
    }

    ensureSelectedHeadingClearance(heading) {
      const scrollOwner = heading?.closest?.('[data-settings-scroll-owner]');
      const stickyActions = scrollOwner?.querySelector?.('.settings-registry-sticky-actions');
      const selectedContent = heading?.closest?.('.settings-section-content');
      const firstEntry = selectedContent?.querySelector?.('.settings-entry');
      if (!scrollOwner || !heading || !firstEntry) return;
      const ownerRect = scrollOwner.getBoundingClientRect();
      const headingRect = heading.getBoundingClientRect();
      const firstEntryRect = firstEntry.getBoundingClientRect();
      const stickyRect = stickyActions?.getBoundingClientRect?.() || null;
      const gap = 12;
      const safeTop = ownerRect.top + gap;
      const safeBottom = Math.min(
        ownerRect.bottom,
        stickyRect ? stickyRect.top : ownerRect.bottom,
      ) - gap;
      let delta = 0;
      if (headingRect.top > safeTop) delta = headingRect.top - safeTop;
      else if (headingRect.top < safeTop) delta = headingRect.top - safeTop;
      if (firstEntryRect.bottom - delta > safeBottom) {
        delta = Math.max(delta, firstEntryRect.bottom - safeBottom);
      }
      if (Math.abs(delta) >= 1) {
        scrollOwner.scrollBy({ top: Math.ceil(delta), behavior: 'auto' });
      }
    }

    syncSurfacePresentation() {
      const setup = this.container.closest('.settings-registry-setup');
      if (!setup) return;
      const subtitle = setup.querySelector('.settings-registry-heading .minor');
      if (subtitle) subtitle.textContent = 'Choose the community features and limits to use after installation.';
      const search = setup.querySelector('input[type="search"]');
      if (search) search.placeholder = 'Setting name, description, or category';
      const filter = setup.querySelector('select[id$="-settings-filter"]');
      if (filter) {
        const labels = {
          all: 'All',
          enabled: 'Enabled',
          disabled: 'Disabled',
          changed: 'Changed from standard',
          original: 'Different from original ChatSpace behavior',
        };
        for (const option of filter.options) if (labels[option.value]) option.textContent = labels[option.value];
      }
      const original = setup.querySelector('#setup-settings-original');
      const recommended = setup.querySelector('#setup-settings-framework');
      if (original) original.textContent = 'Use Original ChatSpace Values';
      if (recommended) recommended.textContent = 'Use Recommended Defaults';
      const state = this.getState();
      const status = setup.querySelector('#setup-settings-compatibility-state');
      if (status) {
        status.textContent = state.invalidCount
          ? `${state.invalidCount} setting field${state.invalidCount === 1 ? ' needs' : 's need'} attention`
          : ({
              'original-compatible': 'Original ChatSpace values',
              'framework-default': 'Recommended defaults',
              custom: 'Custom values',
            }[state.compatibilityState] || 'Custom values');
      }
      const submit = setup.closest('form')?.querySelector('.setup-submit');
      if (submit) {
        submit.disabled = !state.valid;
        if (state.valid) submit.removeAttribute('aria-describedby');
        else submit.setAttribute('aria-describedby', 'setup-settings-compatibility-state');
      }
    }

    readControlValue(entry, input) {
      if (entry.type === 'boolean') return input.checked;
      if (entry.type === 'number') return input.value === '' ? '' : Number(input.value);
      return input.value;
    }

    createColorControl(entry) {
      const id = `settings-registry-${safeId(entry.id)}`;
      const roleMatch = String(entry.id || '').match(/^role_color_(admin|developer|guide|moderator|owner|user)_(bg|text)$/);
      const role = roleMatch?.[1] || '';
      const part = roleMatch?.[2] || '';
      const roleLabel = {
        admin: 'Administrator',
        developer: 'Developer',
        guide: 'Guide',
        moderator: 'Moderator',
        owner: 'CoreChat Owner',
        user: 'Standard User',
      }[role] || 'Role';
      const wrapper = element('div', 'settings-color-control');
      wrapper.dataset.colorRole = role;
      wrapper.dataset.colorPart = part;

      const chooser = element('div', 'settings-color-chooser');
      const swatch = element('span', 'settings-color-swatch');
      swatch.setAttribute('aria-hidden', 'true');
      const choose = element('button', 'btn settings-color-choose', 'Choose color');
      choose.type = 'button';
      choose.disabled = this.readOnly || this.locked;
      choose.setAttribute('aria-expanded', 'false');
      const paletteId = `${id}-palette`;
      choose.setAttribute('aria-controls', paletteId);
      const palette = element('div', 'settings-color-palette');
      palette.id = paletteId;
      palette.hidden = true;
      palette.setAttribute('role', 'menu');
      palette.setAttribute('aria-label', `${entry.label} common colors`);
      const text = document.createElement('input');
      text.id = id;
      text.name = `setting[${entry.id}]`;
      text.type = 'text';
      text.inputMode = 'text';
      text.maxLength = 7;
      text.pattern = '#[0-9A-Fa-f]{6}';
      text.value = String(this.draft.get(entry.id) ?? '').toUpperCase();
      text.disabled = this.readOnly || this.locked;
      text.setAttribute('aria-label', `${entry.label} hexadecimal value`);
      const customLabel = element('label', 'btn settings-color-custom', 'Custom picker');
      const custom = document.createElement('input');
      custom.type = 'color';
      custom.disabled = this.readOnly || this.locked;
      custom.value = normalizeHex(text.value) || '#000000';
      custom.setAttribute('aria-label', `Open the full custom color picker for ${entry.label}`);
      customLabel.appendChild(custom);

      const apply = value => {
        const normalized = normalizeHex(value);
        text.value = normalized || String(value || '').toUpperCase();
        if (normalized) {
          custom.value = normalized;
          swatch.style.backgroundColor = normalized;
        }
        this.updateDraft(entry, text);
        this.refreshRoleColorPresentation();
      };
      for (const [name, value] of COMMON_COLORS) {
        const option = element('button', 'settings-color-option');
        option.type = 'button';
        option.disabled = this.readOnly || this.locked;
        option.setAttribute('role', 'menuitem');
        option.setAttribute('aria-label', `${name} ${value}`);
        option.title = `${name} ${value}`;
        option.style.setProperty('--settings-color-option', value);
        option.addEventListener('click', () => {
          apply(value);
          palette.hidden = true;
          choose.setAttribute('aria-expanded', 'false');
          choose.focus();
        });
        palette.appendChild(option);
      }
      choose.addEventListener('click', () => {
        const opening = palette.hidden;
        palette.hidden = !opening;
        choose.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening) palette.querySelector('button')?.focus();
      });
      palette.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        palette.hidden = true;
        choose.setAttribute('aria-expanded', 'false');
        choose.focus();
      });
      custom.addEventListener('input', () => apply(custom.value));
      text.addEventListener('input', () => apply(text.value));
      chooser.append(swatch, choose, palette, customLabel, text);

      const preview = element('div', 'settings-color-preview');
      const sample = element('span', 'settings-color-preview-name', roleLabel);
      const ratio = element('output', 'settings-color-contrast');
      ratio.setAttribute('aria-live', 'polite');
      const error = element('p', 'settings-entry-error');
      error.setAttribute('role', 'alert');
      preview.append(sample, ratio, error);
      wrapper.append(chooser, preview);
      this.controls.set(entry.id, text);
      return wrapper;
    }

    colorValidation() {
      const issues = new Map();
      for (const entry of this.entries.filter(item => item.type === 'color')) {
        if (!normalizeHex(this.draft.get(entry.id))) issues.set(entry.id, 'Enter a color in #RRGGBB format, such as #000000.');
      }
      for (const role of ['admin', 'developer', 'guide', 'moderator', 'owner', 'user']) {
        const backgroundId = `role_color_${role}_bg`;
        const textId = `role_color_${role}_text`;
        if (!this.entryMap.has(backgroundId) || !this.entryMap.has(textId)) continue;
        const ratio = contrastRatio(this.draft.get(backgroundId), this.draft.get(textId));
        if (ratio !== null && ratio < 4.5) {
          const message = `Choose background and text colors with at least 4.5:1 contrast. Current contrast is ${ratio.toFixed(1)}:1.`;
          issues.set(backgroundId, message);
          issues.set(textId, message);
        }
      }
      return issues;
    }

    nativeValidationMessage(entry, input) {
      const validity = input?.validity;
      if (!validity || validity.valid) return '';
      const label = entry.label || 'This setting';
      const unit = this.unitFor(entry);
      const suffix = unit ? ` ${unit}` : '';
      if (validity.valueMissing) return `${label} is required.`;
      if (validity.badInput) return `Enter a valid number for ${label}.`;
      if (validity.rangeUnderflow && entry.minimum !== null && entry.minimum !== undefined) {
        return `Enter ${label} at or above ${entry.minimum}${suffix}.`;
      }
      if (validity.rangeOverflow && entry.maximum !== null && entry.maximum !== undefined) {
        return `Enter ${label} at or below ${entry.maximum}${suffix}.`;
      }
      if (validity.stepMismatch) {
        return `${label} must use increments of ${entry.step}${suffix}.`;
      }
      if (validity.tooLong && entry.maximum) return `${label} must be ${entry.maximum} characters or fewer.`;
      if (validity.tooShort && entry.minimum) return `${label} must be at least ${entry.minimum} characters.`;
      if (validity.patternMismatch) return `Enter ${label} in the required format.`;
      return `${label} contains an invalid value.`;
    }

    controlValidation() {
      const issues = this.colorValidation();
      for (const entry of this.entries) {
        if (entry.type === 'color' || ['asset', 'fixed', 'profile-review'].includes(entry.type)) continue;
        const input = this.controls.get(entry.id);
        const message = this.nativeValidationMessage(entry, input);
        if (message) issues.set(entry.id, message);
      }
      return issues;
    }

    refreshValidationPresentation() {
      const issues = this.controlValidation();
      for (const row of this.container.querySelectorAll('[data-setting-id]')) {
        const id = row.dataset.settingId || '';
        const input = this.controls.get(id);
        const error = row.querySelector('[data-settings-validation-error]');
        const message = issues.get(id) || '';
        if (error) {
          error.textContent = message;
          error.hidden = !message;
        }
        if (this.entryMap.get(id)?.type !== 'color') row.classList.toggle('is-invalid', Boolean(message));
        if (input) {
          input.toggleAttribute('aria-invalid', Boolean(message));
          const descriptions = new Set(String(input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
          if (error?.id) {
            if (message) descriptions.add(error.id);
            else descriptions.delete(error.id);
          }
          if (descriptions.size) input.setAttribute('aria-describedby', [...descriptions].join(' '));
          else input.removeAttribute('aria-describedby');
        }
      }
      return issues;
    }

    refreshRoleColorPresentation() {
      const issues = this.colorValidation();
      for (const wrapper of this.container.querySelectorAll('.settings-color-control')) {
        const role = wrapper.dataset.colorRole || '';
        const part = wrapper.dataset.colorPart || '';
        const id = `role_color_${role}_${part}`;
        const background = normalizeHex(this.draft.get(`role_color_${role}_bg`));
        const foreground = normalizeHex(this.draft.get(`role_color_${role}_text`));
        const ratio = contrastRatio(background, foreground);
        const own = normalizeHex(this.draft.get(id));
        const swatch = wrapper.querySelector('.settings-color-swatch');
        if (swatch && own) swatch.style.backgroundColor = own;
        const custom = wrapper.querySelector('.settings-color-custom input');
        if (custom && own) custom.value = own;
        const sample = wrapper.querySelector('.settings-color-preview-name');
        if (sample) {
          if (background) sample.style.backgroundColor = background;
          if (foreground) sample.style.color = foreground;
        }
        const output = wrapper.querySelector('.settings-color-contrast');
        if (output) output.textContent = ratio === null
          ? 'Enter both colors in #RRGGBB format.'
          : `${ratio.toFixed(1)}:1 - ${ratio >= 4.5 ? 'Pass' : 'Needs more contrast'}`;
        const error = wrapper.querySelector('.settings-entry-error');
        if (error) {
          error.textContent = issues.get(id) || '';
          error.hidden = !issues.has(id);
        }
        const row = wrapper.closest('[data-setting-id]');
        row?.classList.toggle('is-invalid', issues.has(id));
        const input = this.controls.get(id);
        input?.toggleAttribute('aria-invalid', issues.has(id));
      }
    }

    createControl(entry) {
      const id = `settings-registry-${safeId(entry.id)}`;
      if (entry.type === 'file-transfer-provenance') {
        const provenance = this.registry?.fileTransferProvenance || {};
        const wrapper = element('div', 'settings-file-transfer-provenance');
        wrapper.id = id;
        const summary = element('dl', 'settings-file-transfer-provenance-summary');
        const add = (label, value) => summary.append(element('dt', '', label), element('dd', '', String(value || 'Not yet checked')));
        add('Pinned upstream commit', provenance.pinnedSource);
        add('CoreChat adaptation', provenance.adaptationVersion);
        add('Upstream source date', provenance.sourceDate);
        const checkedValue = element('dd', '', String(provenance.lastUpdateCheckAt || 'Not yet checked'));
        summary.append(element('dt', '', 'Last update check'), checkedValue);
        const review = element('button', 'btn', provenance.reviewAction || 'Review upstream changes');
        review.type = 'button';
        const status = element('span', 'minor settings-file-transfer-provenance-status', 'Manual and informational only; no source is downloaded or applied.');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        review.addEventListener('click', async () => {
          review.disabled = true;
          status.textContent = 'Reviewing the pinned source identity...';
          try {
            const base = String(document.body?.dataset?.appBase || '').replace(/\/$/, '');
            const csrf = String(document.body?.dataset?.csrf || '');
            const response = await fetch(`${base}/api/p2p_transfer.php`, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ action: 'review-upstream', _csrf: csrf }),
            });
            const result = await response.json();
            if (!response.ok || result.error) throw new Error(result.error || 'The source review could not be recorded.');
            checkedValue.textContent = result.provenance?.lastUpdateCheckAt || 'Checked now';
            status.textContent = 'Pinned source metadata reviewed. No source was downloaded or applied.';
          } catch (error) {
            status.textContent = error?.message || 'The source review could not be recorded.';
          } finally {
            review.disabled = false;
          }
        });
        const details = document.createElement('details');
        details.className = 'settings-file-transfer-provenance-details';
        details.appendChild(element('summary', '', 'Source, license, and adaptation details'));
        for (const source of provenance.sources || []) {
          const sourceFacts = element('dl', 'settings-file-transfer-source');
          const addSource = (label, value) => sourceFacts.append(element('dt', '', label), element('dd', '', String(value || '')));
          addSource('Repository', source.repository);
          addSource('Commit', source.commit);
          addSource('License', source.license);
          addSource('Source/archive SHA-256', source.archiveSha256);
          details.appendChild(sourceFacts);
        }
        details.appendChild(element('p', 'minor', provenance.adaptationSummary || 'CoreChat maintains the authenticated transfer adaptation.'));
        wrapper.append(summary, review, status, details);
        return wrapper;
      }
      if (entry.type === 'profile-review') {
        const value = element('div', 'settings-profile-current');
        const profiles = this.registry?.operationalCapacity?.profiles || [];
        const selected = profiles.find(profile => profile.id === entry.currentValue);
        value.textContent = selected?.label || String(entry.currentValue || 'Custom');
        value.setAttribute('role', 'status');
        value.dataset.capacityProfileCurrent = String(entry.currentValue || 'custom');
        return value;
      }
      if (entry.type === 'fixed') {
        const fixed = element('div', 'settings-fixed-value', entry.fixedDisplayValue || 'Always enforced');
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
        input.addEventListener('change', async () => {
          const file = input.files?.[0] || null;
          if (!file || !this.onAssetChange || this.readOnly || this.locked) return;
          input.disabled = true;
          try {
            await this.onAssetChange(entry, file, this);
          } finally {
            input.value = '';
            input.disabled = this.readOnly || this.locked;
          }
        });
        this.controls.set(entry.id, input);
        return input;
      }
      if (entry.type === 'editable-reminder') {
        const wrapper = element('div', 'settings-reminder-editor');
        const input = document.createElement('textarea');
        input.id = id;
        input.name = `setting[${entry.id}]`;
        input.rows = 5;
        input.maxLength = Number(entry.maximum || 600);
        input.value = String(this.draft.get(entry.id) ?? '');
        input.readOnly = true;
        input.disabled = this.readOnly || this.locked;
        const actions = element('div', 'shared-form-actions settings-reminder-actions');
        const edit = element('button', 'btn', 'Edit reminder wording');
        edit.type = 'button';
        const save = element('button', 'btn btn-primary', 'Save wording');
        save.type = 'button';
        const cancel = element('button', 'btn', 'Cancel');
        cancel.type = 'button';
        const reset = element('button', 'btn', 'Reset to standard wording');
        reset.type = 'button';
        save.hidden = cancel.hidden = reset.hidden = true;
        const finish = () => {
          input.readOnly = true;
          edit.hidden = false;
          save.hidden = cancel.hidden = reset.hidden = true;
        };
        edit.addEventListener('click', () => {
          if (this.readOnly || this.locked) return;
          input.readOnly = false;
          edit.hidden = true;
          save.hidden = cancel.hidden = reset.hidden = false;
          input.focus();
        });
        input.addEventListener('input', () => this.updateDraft(entry, input));
        save.addEventListener('click', () => {
          this.updateDraft(entry, input);
          finish();
          this.onEntryChange(entry, this.draft.get(entry.id), this.getState());
        });
        cancel.addEventListener('click', () => {
          input.value = String(entry.currentValue ?? entry.defaultValue ?? '');
          this.draft.set(entry.id, entry.currentValue ?? entry.defaultValue ?? '');
          this.touched.delete(entry.id);
          input.closest('[data-setting-id]')?.classList.remove('is-dirty');
          finish();
          this.updateSummaries();
          this.onDraftChange(this.getState());
          this.syncSurfacePresentation();
        });
        reset.addEventListener('click', () => {
          input.value = String(entry.defaultValue ?? '');
          this.updateDraft(entry, input);
          finish();
          this.onEntryChange(entry, this.draft.get(entry.id), this.getState());
        });
        actions.append(edit, save, cancel, reset);
        wrapper.append(input, actions);
        this.controls.set(entry.id, input);
        return wrapper;
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
      if (entry.type === 'color') return this.createColorControl(entry);
      if (entry.type === 'select') {
        const select = document.createElement('select');
        select.id = id;
        select.name = `setting[${entry.id}]`;
        for (const value of entry.allowedValues || []) {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = entry.allowedValueLabels?.[value]
            || value.replaceAll('-', ' ').replace(/\b\w/g, match => match.toUpperCase());
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
      input.type = entry.type === 'secret' ? 'password' : (entry.type === 'number' ? 'number' : 'text');
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
      if (entry.id === 'diagnostic_screenshots_enabled' && this.draft.get(entry.id) === true) {
        const retentionEntry = this.entryMap.get('diagnostic_screenshot_retention_days');
        const retentionInput = this.controls.get('diagnostic_screenshot_retention_days');
        if (retentionEntry && retentionInput && Number(this.draft.get(retentionEntry.id) || 0) === 0) {
          this.draft.set(retentionEntry.id, 30);
          retentionInput.value = '30';
          this.touched.add(retentionEntry.id);
          retentionInput.closest('[data-setting-id]')?.classList.add('is-dirty');
        }
      }
      if (entry.id === 'branding_license_reminder') {
        document.querySelectorAll('[data-branding-reminder-authority]').forEach(node => {
          node.textContent = String(this.draft.get(entry.id) || entry.defaultValue || '');
        });
      }
      this.touched.add(entry.id);
      const card = input.closest('[data-setting-id]');
      card?.classList.toggle('is-dirty', this.isDirty(entry));
      this.updateSummaries();
      this.applyDependencyStates();
      this.syncLimitEnforcementControls();
      this.syncInheritedActions();
      this.refreshRoleColorPresentation();
      this.refreshValidationPresentation();
      this.applySearchAndFilter();
      this.onDraftChange(this.getState());
      this.syncSurfacePresentation();
      this.onEntryChange(entry, this.draft.get(entry.id), this.getState());
    }

    isDirty(entry) {
      if (entry.type === 'asset' || entry.type === 'fixed' || entry.type === 'profile-review' || entry.type === 'file-transfer-provenance') return false;
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
        if (entry.type === 'fixed' || entry.type === 'profile-review' || entry.type === 'asset' || entry.type === 'secret') continue;
        values[entry.id] = this.draft.get(entry.id);
      }
      for (const entry of this.entries.filter(item => item.type === 'secret')) {
        if (this.isDirty(entry)) values[entry.id] = this.draft.get(entry.id);
      }
      return values;
    }

    getState() {
      const changed = this.changedValues();
      const issues = this.controlValidation();
      return {
        changed,
        changedCount: Object.keys(changed).length,
        values: this.getValues(),
        compatibilityState: this.compatibilityState(),
        invalidCount: issues.size,
        valid: issues.size === 0,
      };
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
        const searchActive = Boolean(this.query || this.filter !== 'all');
        const selected = !this.categoryNavigation
          || this.selectedView === 'overview'
          || this.selectedView === category.dataset.settingsCategory
          || (this.selectedView === 'limits' && category.dataset.settingsView === 'limits');
        category.hidden = !visible || (!searchActive && !selected);
      }
      this.overviewPanel?.toggleAttribute('hidden', Boolean(this.query || this.filter !== 'all') || this.selectedView !== 'overview');
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
      const floodEntries = entries.filter(entry => entry.bulkGroup === 'flood-protection');
      if (floodEntries.length && entries.every(entry => entry.subsectionId === 'flood-protection')) {
        const enabled = floodEntries.filter(entry => this.draftEnabled(entry) === true).length;
        const state = enabled === floodEntries.length ? 'All enabled' : (enabled === 0 ? 'All disabled' : 'Custom');
        return `${state} · ${changed} changed`;
      }
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
      for (const node of this.container.querySelectorAll('[data-settings-changed-count]')) {
        const ids = String(node.dataset.settingIds || '').split(',').filter(Boolean);
        const changed = ids.map(id => this.entryMap.get(id)).filter(Boolean).filter(entry => this.isDirty(entry)).length;
        node.textContent = String(changed);
        node.closest('[data-settings-nav-item]')?.classList.toggle('has-changes', changed > 0);
      }
      this.updateInstalledFeatureStatuses();
      this.updateConnectionCapabilityStatuses();
    }

    installedFeatures() {
      return Array.isArray(this.registry?.installedFeatures)
        ? this.registry.installedFeatures
        : [];
    }

    connectionCapabilities() {
      return Array.isArray(this.registry?.connectionCapabilities)
        ? this.registry.connectionCapabilities
        : [];
    }

    connectionCapabilityEnabled(capability) {
      const settingId = String(capability?.manageSettingId || '');
      const entry = this.entryMap.get(settingId);
      if (!entry) return Boolean(capability?.effectiveEnabled);
      let enabled = entry.type === 'fixed'
        ? Boolean(entry.effectiveValue)
        : entry.type === 'boolean'
          ? Boolean(this.draft.get(settingId))
          : Boolean(this.draft.has(settingId) ? this.draft.get(settingId) : entry.effectiveValue);
      for (const [requiredId, requiredValue] of Object.entries(capability?.effectiveWhen || {})) {
        const requiredEntry = this.entryMap.get(String(requiredId));
        const current = this.draft.has(String(requiredId))
          ? this.draft.get(String(requiredId))
          : requiredEntry?.effectiveValue;
        if (current !== requiredValue) enabled = false;
      }
      return enabled;
    }

    updateConnectionCapabilityStatuses() {
      for (const node of this.container.querySelectorAll('[data-connection-capability-status]')) {
        const settingId = String(node.dataset.connectionCapabilitySettingId || '');
        const capability = this.connectionCapabilities().find(item => item.manageSettingId === settingId);
        if (!capability) continue;
        const enabled = this.connectionCapabilityEnabled(capability);
        node.textContent = enabled ? 'Enabled' : 'Disabled';
        node.classList.toggle('is-enabled', enabled);
        node.classList.toggle('is-disabled', !enabled);
        const pending = node.parentElement?.querySelector('[data-connection-capability-pending]');
        if (pending) {
          const entry = this.entryMap.get(settingId);
          const dirty = Boolean(entry && this.isDirty(entry));
          pending.hidden = !dirty;
          pending.textContent = dirty ? `Pending save: ${enabled ? 'Enabled' : 'Disabled'}` : '';
        }
      }
    }

    updateInstalledFeatureStatuses() {
      for (const node of this.container.querySelectorAll('[data-installed-feature-status]')) {
        const settingId = String(node.dataset.installedFeatureSettingId || '');
        const feature = this.installedFeatures().find(item => item.manageSettingId === settingId);
        const entry = this.entryMap.get(settingId);
        if (!feature || !entry) continue;
        const effectiveEnabled = Boolean(feature.effectiveEnabled);
        node.textContent = effectiveEnabled ? 'Enabled' : 'Disabled';
        node.classList.toggle('is-enabled', effectiveEnabled);
        node.classList.toggle('is-disabled', !effectiveEnabled);
        const pending = node.parentElement?.querySelector('[data-installed-feature-pending]');
        if (pending) {
          const dirty = this.isDirty(entry);
          pending.hidden = !dirty;
          pending.textContent = dirty
            ? `Pending save: ${Boolean(this.draft.get(settingId)) ? 'Enabled' : 'Disabled'}`
            : '';
        }
      }
    }

    manageInstalledFeature(feature) {
      const settingId = String(feature?.manageSettingId || '');
      const view = String(feature?.manageView || '');
      if (!settingId || !this.entryMap.has(settingId)) return false;
      if (this.categoryNavigation) {
        if (!this.selectView(view)) return false;
        window.requestAnimationFrame(() => {
          const row = this.container.querySelector(`[data-setting-id="${CSS.escape(settingId)}"]`);
          row?.scrollIntoView?.({ block: 'nearest' });
          const control = row?.querySelector('input, select, textarea, button');
          control?.focus({ preventScroll: true });
        });
        return true;
      }
      const row = this.container.querySelector(`[data-setting-id="${CSS.escape(settingId)}"]`);
      if (!row) return false;
      row.scrollIntoView?.({ block: 'center' });
      const control = row.querySelector('input, select, textarea, button');
      control?.focus({ preventScroll: true });
      return true;
    }

    renderInstalledFeatures(target) {
      const features = this.installedFeatures();
      if (!features.length) return;
      const section = element('section', 'settings-installed-features');
      const heading = element('div', 'settings-installed-features-heading');
      const title = element('h3', '', 'Installed Features');
      const titleId = `${this.container.id || 'settings-registry'}-installed-features-title`;
      title.id = titleId;
      section.setAttribute('aria-labelledby', titleId);
      heading.append(
        title,
        element('p', 'minor', 'Open the settings that manage each installed feature.')
      );
      section.appendChild(heading);
      const list = element('div', 'settings-installed-feature-list');
      for (const feature of features) {
        const row = element('article', 'settings-installed-feature');
        row.dataset.installedFeature = String(feature.id || '');
        const summary = element('div', 'settings-installed-feature-summary');
        summary.appendChild(element('strong', '', String(feature.name || 'Installed feature')));
        const state = element(
          'span',
          `settings-installed-feature-status ${feature.effectiveEnabled ? 'is-enabled' : 'is-disabled'}`,
          feature.effectiveEnabled ? 'Enabled' : 'Disabled'
        );
        state.dataset.installedFeatureStatus = 'true';
        state.dataset.installedFeatureSettingId = String(feature.manageSettingId || '');
        const pending = element('span', 'settings-installed-feature-pending', '');
        pending.dataset.installedFeaturePending = 'true';
        pending.hidden = true;
        summary.append(state, pending);
        const action = element('button', 'btn settings-installed-feature-action', String(feature.manageLabel || 'Manage'));
        action.type = 'button';
        action.addEventListener('click', () => this.manageInstalledFeature(feature));
        row.append(summary, action);
        list.appendChild(row);
      }
      section.appendChild(list);
      target.appendChild(section);
      this.updateInstalledFeatureStatuses();
    }

    renderConnectionCapabilities(target) {
      const capabilities = this.connectionCapabilities();
      if (!capabilities.length) return;
      const section = element('section', 'settings-installed-features settings-connection-capabilities');
      section.dataset.connectionCapabilities = 'true';
      const heading = element('div', 'settings-installed-features-heading');
      const title = element('h3', '', 'Voice, Media & Players');
      const titleId = `${this.container.id || 'settings-registry'}-connection-capabilities-title`;
      title.id = titleId;
      section.setAttribute('aria-labelledby', titleId);
      heading.append(
        title,
        element('p', 'minor', 'Installed voice, webcam, and direct-connection capabilities are listed once. Each action opens its authoritative control.')
      );
      section.appendChild(heading);
      const list = element('div', 'settings-installed-feature-list settings-connection-capability-list');
      for (const capability of capabilities) {
        const row = element('article', 'settings-installed-feature settings-connection-capability');
        row.dataset.connectionCapability = String(capability.id || '');
        const summary = element('div', 'settings-installed-feature-summary');
        summary.appendChild(element('strong', '', String(capability.name || 'Connection capability')));
        const enabled = this.connectionCapabilityEnabled(capability);
        const state = element(
          'span',
          `settings-installed-feature-status ${enabled ? 'is-enabled' : 'is-disabled'}`,
          enabled ? 'Enabled' : 'Disabled'
        );
        state.dataset.connectionCapabilityStatus = 'true';
        state.dataset.connectionCapabilitySettingId = String(capability.manageSettingId || '');
        const pending = element('span', 'settings-installed-feature-pending', '');
        pending.dataset.connectionCapabilityPending = 'true';
        pending.hidden = true;
        summary.append(state, pending);
        const action = element('button', 'btn settings-installed-feature-action', String(capability.manageLabel || 'Manage'));
        action.type = 'button';
        action.addEventListener('click', () => this.manageInstalledFeature(capability));
        row.append(summary, action);
        list.appendChild(row);
      }
      section.appendChild(list);
      target.appendChild(section);
      this.updateConnectionCapabilityStatuses();
    }

    async mediaPackFilesAreIdentical(first, second) {
      if (!(first instanceof Blob) || !(second instanceof Blob) || first.size !== second.size) return false;
      const [firstBytes, secondBytes] = await Promise.all([first.arrayBuffer(), second.arrayBuffer()]);
      const left = new Uint8Array(firstBytes);
      const right = new Uint8Array(secondBytes);
      for (let index = 0; index < left.length; index += 1) {
        if (left[index] !== right[index]) return false;
      }
      return true;
    }

    async collectFiveDiceDroppedFiles(dataTransfer) {
      const collected = [];
      const walkHandle = async handle => {
        if (!handle) return;
        if (handle.kind === 'file') {
          collected.push(await handle.getFile());
          return;
        }
        if (handle.kind === 'directory') {
          for await (const child of handle.values()) await walkHandle(child);
        }
      };
      const walkEntry = entry => new Promise((resolve, reject) => {
        if (entry.isFile) {
          entry.file(file => { collected.push(file); resolve(); }, reject);
          return;
        }
        if (!entry.isDirectory) { resolve(); return; }
        const reader = entry.createReader();
        const read = () => reader.readEntries(async entries => {
          if (!entries.length) { resolve(); return; }
          try {
            for (const child of entries) await walkEntry(child);
            read();
          } catch (error) { reject(error); }
        }, reject);
        read();
      });
      const items = [...(dataTransfer?.items || [])];
      for (const item of items) {
        if (typeof item.getAsFileSystemHandle === 'function') {
          const handle = await item.getAsFileSystemHandle();
          await walkHandle(handle);
        } else if (typeof item.webkitGetAsEntry === 'function') {
          await walkEntry(item.webkitGetAsEntry());
        } else {
          const file = item.getAsFile?.();
          if (file) collected.push(file);
        }
      }
      if (!collected.length) collected.push(...(dataTransfer?.files || []));
      return collected;
    }

    renderFiveDiceMediaPack(target) {
      const status = this.registry?.fiveDiceMediaPack;
      if (!status || String(status.gameKey || '') !== 'g_4f8c2d71') return;
      const section = element('section', 'settings-installed-features settings-five-dice-media-pack');
      section.dataset.fiveDiceMediaPack = 'true';
      const heading = element('div', 'settings-installed-features-heading');
      const title = element('h3', '', `Install Classic Artwork & Sound — ${String(status.displayName || 'Five Dice')}`);
      const titleId = `${this.container.id || 'settings-registry'}-five-dice-media-pack-title`;
      title.id = titleId;
      section.setAttribute('aria-labelledby', titleId);
      heading.append(
        title,
        element('p', 'minor', `Add the optional installation-private Classic appearance for ${String(status.displayName || 'Five Dice')}. The complete Built-in appearance and silent audio fallback remain usable without it.`)
      );
      section.appendChild(heading);
      const summary = element('dl', 'settings-entry-destination settings-five-dice-media-summary');
      const add = (label, value) => summary.append(element('dt', '', label), element('dd', '', String(value)));
      add('Installed slots', Number(status.installedCount || 0));
      add('Required Classic slots', Number(status.requiredCount || 0));
      add('Missing slots using built-in fallback', Number(status.missingCount || 0));
      add('Invalid slots using built-in fallback', Number(status.invalidCount || 0));
      add('Classic appearance', status.classicAvailable ? 'Available' : 'Unavailable');
      add('Selected appearance', status.presentation?.requestedPack === 'built-in' ? 'Built-in' : 'Classic');
      add('Effective appearance', status.presentation?.effectivePack === 'classic' ? 'Classic' : 'Built-in');
      add('Music source', 'MP3 when installed; MIDI is not required');
      section.appendChild(summary);
      if (Array.isArray(status.invalid) && status.invalid.length) {
        const warning = element('div', 'settings-entry-error', 'Some optional media files are invalid. Replace only the listed slots; games and records are unaffected.');
        warning.setAttribute('role', 'status');
        const list = element('ul', 'settings-five-dice-media-invalid');
        for (const item of status.invalid) {
          list.appendChild(element('li', '', String(item.label || 'Invalid media slot')));
        }
        warning.appendChild(list);
        section.appendChild(warning);
      }
      section.appendChild(element('p', 'minor', String(status.guidance || '')));
      if (status.surface === 'setup') {
        const note = element('div', 'settings-entry-destination settings-five-dice-media-owner-note');
        note.append(
          element('strong', '', 'Available after Setup'),
          element('p', 'minor', 'Classic artwork and sound installation becomes available after the first Installation Owner account is created and Setup is finalized. Setup never exposes an unauthenticated upload path.')
        );
        section.appendChild(note);
        target.appendChild(section);
        return;
      }
      if (!status.canManage) {
        section.appendChild(element('p', 'minor', 'Only the Installation Owner can verify, install, replace, or remove this private media pack.'));
        target.appendChild(section);
        return;
      }

      const manager = element('div', 'settings-five-dice-media-manager');
      const fileInput = document.createElement('input');
      fileInput.type = 'file';
      fileInput.multiple = true;
      fileInput.accept = '.ocx,.png,.wav,.mp3,application/octet-stream,image/png,audio/wav,audio/mpeg';
      fileInput.hidden = true;
      fileInput.dataset.fiveDicePackInput = 'files';
      const folderInput = fileInput.cloneNode();
      folderInput.dataset.fiveDicePackInput = 'folder';
      folderInput.setAttribute('webkitdirectory', '');
      folderInput.setAttribute('directory', '');
      const chooseFiles = element('button', 'btn', 'Select OCX or original/prepared files');
      chooseFiles.type = 'button';
      const chooseFolder = element('button', 'btn', 'Choose original source folder');
      chooseFolder.type = 'button';
      const drop = element('div', 'settings-five-dice-media-drop', 'Drop a legacy OCX for static-only media extraction, the MyChange source folder, original files, or a prepared pack here. The OCX is never executed, registered, or retained.');
      drop.tabIndex = 0;
      drop.setAttribute('role', 'button');
      drop.setAttribute('aria-label', 'Choose Classic artwork and sound files');
      const progress = document.createElement('progress');
      progress.max = Number(status.requiredCount || 30);
      progress.value = 0;
      progress.setAttribute('aria-label', 'Recognized Classic media slots');
      const selectionStatus = element('p', 'minor settings-five-dice-media-selection', 'No files selected.');
      selectionStatus.setAttribute('role', 'status');
      selectionStatus.setAttribute('aria-live', 'polite');
      const actionStatus = element('p', 'settings-five-dice-media-action-status', '');
      actionStatus.setAttribute('role', 'status');
      actionStatus.setAttribute('aria-live', 'polite');
      const install = element('button', 'btn btn-primary', status.installedCount ? 'Replace Pack' : 'Install Classic Artwork & Sound');
      install.type = 'button';
      install.disabled = true;
      const verify = element('button', 'btn', 'Verify Pack');
      verify.type = 'button';
      const remove = element('button', 'btn btn-danger', 'Remove Pack');
      remove.type = 'button';
      remove.disabled = !status.installedCount;
      const actions = element('div', 'settings-five-dice-media-actions');
      actions.append(install, verify, remove);
      const confirmation = element('div', 'settings-entry-error settings-five-dice-media-confirmation');
      confirmation.hidden = true;
      confirmation.appendChild(element('p', '', 'Remove the active Classic artwork and sound pack? The game immediately uses Built-in presentation; games, scores, records, saves, and the saved appearance preference remain unchanged.'));
      const confirmRemove = element('button', 'btn btn-danger', 'Remove Classic Pack');
      confirmRemove.type = 'button';
      const cancelRemove = element('button', 'btn', 'Cancel');
      cancelRemove.type = 'button';
      confirmation.append(confirmRemove, cancelRemove);

      let selectedFiles = [];
      let selectionCanInstall = false;
      const accepted = new Map(Object.entries(status.acceptedFilenameSlots || {}).map(([name, slot]) => [String(name).toLocaleLowerCase(), String(slot)]));
      const updateSelection = async files => {
        actionStatus.textContent = '';
        const bySlot = new Map();
        let ignored = 0;
        let identicalDuplicates = 0;
        let conflictingDuplicates = 0;
        const ocxFiles = [];
        for (const file of files) {
          if (String(file.name || '').toLocaleLowerCase().endsWith('.ocx')) { ocxFiles.push(file); continue; }
          const slot = accepted.get(String(file.name || '').toLocaleLowerCase());
          if (!slot) { ignored += 1; continue; }
          if (bySlot.has(slot)) {
            if (await this.mediaPackFilesAreIdentical(bySlot.get(slot), file)) identicalDuplicates += 1;
            else conflictingDuplicates += 1;
            continue;
          }
          bySlot.set(slot, file);
        }
        selectedFiles = [...bySlot.values(), ...ocxFiles];
        progress.value = bySlot.size;
        const required = Number(status.requiredCount || 30);
        const missing = Math.max(0, required - bySlot.size);
        const duplicateCopy = `${identicalDuplicates} identical duplicate file${identicalDuplicates === 1 ? '' : 's'} collapsed safely; ${conflictingDuplicates} conflicting duplicate${conflictingDuplicates === 1 ? '' : 's'}.`;
        if (ocxFiles.length === 1) {
          const nextAction = this.locked
            ? `Unlock settings changes, then click ${install.textContent}.`
            : `Click ${install.textContent} to statically extract and validate all ${required} required media slots.`;
          const supplemental = bySlot.size
            ? `${bySlot.size}/${required} supplemental files directly recognized.`
            : 'No supplemental files are selected or required for raw-OCX import.';
          selectionStatus.textContent = `1 OCX recognized. ${nextAction} ${supplemental} ${duplicateCopy} ${ignored} unrelated file${ignored === 1 ? '' : 's'} ignored safely.`;
        } else if (ocxFiles.length > 1) {
          selectionStatus.textContent = `${ocxFiles.length} OCX files selected; choose exactly one OCX. ${bySlot.size}/${required} supplemental files directly recognized; ${duplicateCopy} ${ignored} unrelated file${ignored === 1 ? '' : 's'} ignored safely.`;
        } else {
          selectionStatus.textContent = `${bySlot.size}/${required} directly recognized; ${missing} not directly supplied; ${duplicateCopy} ${ignored} unrelated file${ignored === 1 ? '' : 's'} ignored safely.`;
        }
        selectionStatus.classList.toggle('error', (missing > 0 && ocxFiles.length === 0) || conflictingDuplicates > 0 || ocxFiles.length > 1);
        selectionCanInstall = conflictingDuplicates === 0 && ocxFiles.length <= 1 && (missing === 0 || ocxFiles.length === 1);
        install.disabled = this.readOnly || this.locked || !selectionCanInstall;
      };
      const choose = async files => {
        try { await updateSelection([...(files || [])]); }
        catch (error) { actionStatus.textContent = error.message || 'The selected files could not be read.'; }
      };
      fileInput.addEventListener('change', () => choose(fileInput.files));
      folderInput.addEventListener('change', () => choose(folderInput.files));
      chooseFiles.addEventListener('click', () => fileInput.click());
      chooseFolder.addEventListener('click', () => folderInput.click());
      drop.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        fileInput.click();
      });
      for (const type of ['dragenter', 'dragover']) drop.addEventListener(type, event => {
        event.preventDefault();
        drop.classList.add('is-dragging');
      });
      for (const type of ['dragleave', 'drop']) drop.addEventListener(type, event => {
        event.preventDefault();
        drop.classList.remove('is-dragging');
      });
      drop.addEventListener('drop', async event => {
        actionStatus.textContent = 'Reading selected files…';
        try {
          await updateSelection(await this.collectFiveDiceDroppedFiles(event.dataTransfer));
          actionStatus.textContent = '';
        } catch (error) {
          actionStatus.textContent = error.message || 'The dropped folder could not be read safely.';
        }
      });
      const run = async (action, details = {}) => {
        if (!this.onMediaPackAction || this.readOnly || this.locked) return;
        actionStatus.textContent = action === 'verify' ? 'Verifying the active pack…' : `${action === 'remove' ? 'Removing' : 'Validating'} the private pack…`;
        for (const control of [install, verify, remove, confirmRemove, cancelRemove, chooseFiles, chooseFolder]) control.disabled = true;
        try {
          await this.onMediaPackAction(action, details, this);
          actionStatus.textContent = action === 'remove' ? 'Pack removed; Built-in is active.' : (action === 'verify' ? 'Pack verification complete.' : 'Complete pack activated atomically.');
        } catch (error) {
          actionStatus.textContent = error.message || 'The Classic artwork and sound action failed safely.';
          for (const control of [verify, confirmRemove, cancelRemove, chooseFiles, chooseFolder]) control.disabled = this.readOnly || this.locked;
          install.disabled = this.readOnly || this.locked || !selectionCanInstall;
          remove.disabled = this.readOnly || this.locked || !status.installedCount;
          throw error;
        }
      };
      install.addEventListener('click', () => run(status.installedCount ? 'replace' : 'install', { files: selectedFiles }).catch(() => {}));
      verify.addEventListener('click', () => run('verify').catch(() => {}));
      remove.addEventListener('click', () => {
        confirmation.hidden = false;
        confirmRemove.focus();
      });
      cancelRemove.addEventListener('click', () => {
        confirmation.hidden = true;
        remove.focus();
      });
      confirmRemove.addEventListener('click', () => run('remove', { confirmed: true }).catch(() => {}));
      manager.append(fileInput, folderInput, drop, chooseFolder, chooseFiles, progress, selectionStatus, actions, confirmation, actionStatus);
      section.appendChild(manager);
      target.appendChild(section);
    }

    renderGameMediaPacks(target) {
      const packs = Array.isArray(this.registry?.gameMediaPacks) ? this.registry.gameMediaPacks : [];
      for (const status of packs) {
        const extensionId = String(status.extensionId || '');
        if (!extensionId) continue;
        const section = element('section', 'settings-installed-features settings-five-dice-media-pack settings-game-media-pack');
        section.dataset.gameMediaPack = extensionId;
        const title = element('h3', '', `Install Classic Artwork & Sound — ${String(status.displayName || 'Installed game')}`);
        const titleId = `${this.container.id || 'settings-registry'}-${extensionId}-media-pack-title`;
        title.id = titleId;
        section.setAttribute('aria-labelledby', titleId);
        section.append(
          title,
          element('p', 'minor', 'Add the optional installation-private Classic appearance. The complete Built-in presentation and silent audio fallback remain usable without it.')
        );
        const summary = element('dl', 'settings-entry-destination settings-five-dice-media-summary');
        const add = (label, value) => summary.append(element('dt', '', label), element('dd', '', String(value)));
        add('Installed slots', Number(status.installedCount || 0));
        add('Required Classic slots', Number(status.requiredCount || 0));
        add('Missing slots using Built-in fallback', Array.isArray(status.missing) ? status.missing.length : 0);
        add('Invalid slots using Built-in fallback', Array.isArray(status.invalid) ? status.invalid.length : 0);
        add('Classic appearance', status.classicComplete ? 'Available' : 'Unavailable');
        add('Effective appearance', status.presentation?.effectivePack === 'classic' ? 'Classic' : (status.presentation?.effectivePack === 'corechat' ? 'CoreChat artwork' : 'Built-in'));
        section.appendChild(summary);
        if (Array.isArray(status.invalid) && status.invalid.length) {
          const warning = element('div', 'settings-entry-error', 'Some private media files are invalid. Replace the listed slots; active games, saves, scores, and records remain unchanged.');
          warning.setAttribute('role', 'status');
          const list = element('ul', 'settings-five-dice-media-invalid');
          for (const item of status.invalid) list.appendChild(element('li', '', String(item.label || 'Invalid media slot')));
          warning.appendChild(list);
          section.appendChild(warning);
        }
        if (status.surface === 'setup') {
          const note = element('div', 'settings-entry-destination settings-five-dice-media-owner-note');
          note.append(element('strong', '', 'Available after Setup'), element('p', 'minor', String(status.setupGuidance || 'Classic media installation becomes available after the Installation Owner account is finalized. Setup never exposes an unauthenticated upload path.')));
          section.appendChild(note);
          target.appendChild(section);
          continue;
        }
        if (!status.canManage) {
          section.appendChild(element('p', 'minor', 'Only the Installation Owner can verify, install, replace, or remove this private media pack.'));
          target.appendChild(section);
          continue;
        }
        const manager = element('div', 'settings-five-dice-media-manager');
        const fileInput = document.createElement('input');
        fileInput.type = 'file'; fileInput.multiple = true; fileInput.accept = '.ocx,.png,.gif,.wav,application/octet-stream,image/png,image/gif,audio/wav'; fileInput.hidden = true;
        const folderInput = fileInput.cloneNode(); folderInput.setAttribute('webkitdirectory', ''); folderInput.setAttribute('directory', '');
        const chooseFiles = element('button', 'btn', 'Select OCX or original/prepared files'); chooseFiles.type = 'button';
        const chooseFolder = element('button', 'btn', 'Choose original source folder'); chooseFolder.type = 'button';
        const drop = element('div', 'settings-five-dice-media-drop', 'Drop a legacy OCX for static-only media extraction, recognized original files, or a prepared semantic-name pack here. The OCX is never executed, registered, or retained.');
        drop.tabIndex = 0; drop.setAttribute('role', 'button'); drop.setAttribute('aria-label', `Choose Classic artwork and sound for ${String(status.displayName || 'this game')}`);
        const progress = document.createElement('progress'); progress.max = Number(status.requiredCount || 1); progress.value = 0; progress.setAttribute('aria-label', 'Recognized Classic media slots');
        const selectionStatus = element('p', 'minor settings-five-dice-media-selection', 'No files selected.'); selectionStatus.setAttribute('role', 'status'); selectionStatus.setAttribute('aria-live', 'polite');
        const actionStatus = element('p', 'settings-five-dice-media-action-status', ''); actionStatus.dataset.gameMediaStatus = extensionId; actionStatus.setAttribute('role', 'status'); actionStatus.setAttribute('aria-live', 'polite');
        const install = element('button', 'btn btn-primary', status.installedCount ? 'Replace Pack' : 'Install Classic Artwork & Sound'); install.type = 'button'; install.disabled = true;
        const verify = element('button', 'btn', 'Verify Pack'); verify.type = 'button';
        const remove = element('button', 'btn btn-danger', 'Remove Pack'); remove.type = 'button'; remove.disabled = !status.installedCount;
        const actions = element('div', 'settings-five-dice-media-actions'); actions.append(install, verify, remove);
        const confirmation = element('div', 'settings-entry-error settings-five-dice-media-confirmation'); confirmation.hidden = true;
        confirmation.appendChild(element('p', '', 'Remove this active Classic pack? The game immediately uses Built-in presentation; game state, scores, records, saves, and presentation preferences remain unchanged.'));
        const confirmRemove = element('button', 'btn btn-danger', 'Remove Classic Pack'); confirmRemove.type = 'button';
        const cancelRemove = element('button', 'btn', 'Cancel'); cancelRemove.type = 'button'; confirmation.append(confirmRemove, cancelRemove);
        const accepted = new Map(Object.entries(status.acceptedFilenameSlots || {}).map(([name, slot]) => [String(name).toLocaleLowerCase(), String(slot)]));
        let selectedFiles = [];
        let selectionCanInstall = false;
        const updateSelection = async files => {
          actionStatus.textContent = '';
          const bySlot = new Map(); const ocxFiles = []; let ignored = 0; let identicalDuplicates = 0; let conflictingDuplicates = 0;
          for (const file of files) {
            if (String(file.name || '').toLocaleLowerCase().endsWith('.ocx')) { ocxFiles.push(file); continue; }
            const slot = accepted.get(String(file.name || '').toLocaleLowerCase());
            if (!slot) { ignored++; continue; }
            if (bySlot.has(slot)) {
              if (await this.mediaPackFilesAreIdentical(bySlot.get(slot), file)) identicalDuplicates++;
              else conflictingDuplicates++;
              continue;
            }
            bySlot.set(slot, file);
          }
          selectedFiles = [...bySlot.values(), ...ocxFiles]; progress.value = bySlot.size;
          const required = Number(status.requiredCount || 0); const missing = Math.max(0, required - bySlot.size);
          const duplicateCopy = `${identicalDuplicates} identical duplicate file${identicalDuplicates === 1 ? '' : 's'} collapsed safely; ${conflictingDuplicates} conflicting duplicate${conflictingDuplicates === 1 ? '' : 's'}.`;
          if (ocxFiles.length === 1) {
            const nextAction = this.locked
              ? `Unlock settings changes, then click ${install.textContent}.`
              : `Click ${install.textContent} to statically extract and validate all ${required} required media slots.`;
            const supplemental = bySlot.size
              ? `${bySlot.size}/${required} supplemental files directly recognized.`
              : 'No supplemental files are selected or required for raw-OCX import.';
            selectionStatus.textContent = `1 OCX recognized. ${nextAction} ${supplemental} ${duplicateCopy} ${ignored} unrelated file${ignored === 1 ? '' : 's'} ignored safely.`;
          } else if (ocxFiles.length > 1) {
            selectionStatus.textContent = `${ocxFiles.length} OCX files selected; choose exactly one OCX. ${bySlot.size}/${required} supplemental files directly recognized; ${duplicateCopy} ${ignored} unrelated file${ignored === 1 ? '' : 's'} ignored safely.`;
          } else {
            selectionStatus.textContent = `${bySlot.size}/${required} directly recognized; ${missing} not directly supplied; ${duplicateCopy} ${ignored} unrelated file${ignored === 1 ? '' : 's'} ignored safely.`;
          }
          selectionStatus.classList.toggle('error', (missing > 0 && ocxFiles.length === 0) || conflictingDuplicates > 0 || ocxFiles.length > 1);
          selectionCanInstall = conflictingDuplicates === 0 && ocxFiles.length <= 1 && (missing === 0 || ocxFiles.length === 1);
          install.disabled = this.readOnly || this.locked || !selectionCanInstall;
        };
        const choose = files => updateSelection([...(files || [])]).catch(error => { actionStatus.textContent = error.message || 'The selected files could not be compared safely.'; });
        fileInput.addEventListener('change', () => choose(fileInput.files)); folderInput.addEventListener('change', () => choose(folderInput.files));
        chooseFiles.addEventListener('click', () => fileInput.click()); chooseFolder.addEventListener('click', () => folderInput.click());
        drop.addEventListener('keydown', event => { if (event.key !== 'Enter' && event.key !== ' ') return; event.preventDefault(); fileInput.click(); });
        for (const type of ['dragenter', 'dragover']) drop.addEventListener(type, event => { event.preventDefault(); drop.classList.add('is-dragging'); });
        for (const type of ['dragleave', 'drop']) drop.addEventListener(type, event => { event.preventDefault(); drop.classList.remove('is-dragging'); });
        drop.addEventListener('drop', async event => { try { await updateSelection(await this.collectFiveDiceDroppedFiles(event.dataTransfer)); } catch (error) { actionStatus.textContent = error.message || 'The dropped folder could not be read safely.'; } });
        const run = async (action, details = {}) => {
          if (!this.onMediaPackAction || this.readOnly || this.locked) return;
          actionStatus.textContent = action === 'verify' ? 'Verifying the active pack…' : `${action === 'remove' ? 'Removing' : 'Validating'} the private pack…`;
          await this.onMediaPackAction(action, { ...details, game: extensionId }, this);
        };
        install.addEventListener('click', () => run(status.installedCount ? 'replace' : 'install', { files: selectedFiles }).catch(error => { actionStatus.textContent = error.message || 'The Classic pack action failed safely.'; }));
        verify.addEventListener('click', () => run('verify').catch(error => { actionStatus.textContent = error.message || 'Verification failed safely.'; }));
        remove.addEventListener('click', () => { confirmation.hidden = false; confirmRemove.focus(); });
        cancelRemove.addEventListener('click', () => { confirmation.hidden = true; remove.focus(); });
        confirmRemove.addEventListener('click', () => run('remove', { confirmed: true }).catch(error => { actionStatus.textContent = error.message || 'Removal failed safely.'; }));
        manager.append(fileInput, folderInput, drop, chooseFolder, chooseFiles, progress, selectionStatus, actions, confirmation, actionStatus);
        section.appendChild(manager); target.appendChild(section);
      }
    }

    applyDependencyStates() {
      for (const entry of this.entries) {
        const dependencies = Array.isArray(entry.dependencies) ? entry.dependencies : [];
        const unmet = dependencies.filter(id => this.draft.get(id) === false);
        const card = Array.from(this.container.querySelectorAll('[data-setting-id]'))
          .find(node => node.dataset.settingId === entry.id);
        if (!card) continue;
        card.classList.toggle('is-dependency-inactive', unmet.length > 0);
        const badge = card.querySelector('[data-settings-dependency-badge]');
        if (badge) {
          badge.hidden = unmet.length === 0;
          badge.textContent = unmet.length
            ? `Inactive while ${unmet.map(id => this.entryMap.get(id)?.label || id).join(', ')} is disabled`
            : '';
        }
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

    syncInheritedActions() {
      for (const row of this.container.querySelectorAll('[data-setting-id]')) {
        const entry = this.entryMap.get(row.dataset.settingId);
        if (!entry?.allowsOverride) continue;
        const inherited = String(this.draft.get(entry.id) ?? '').trim() === '';
        const action = row.querySelector('[data-use-inherited]');
        const status = row.querySelector('[data-using-inherited]');
        if (action) action.hidden = inherited;
        if (status) status.hidden = !inherited;
      }
    }

    renderEntryLegacy(entry) {
      const card = element('article', `settings-entry settings-entry-${safeId(entry.controlClass)}`);
      card.dataset.settingId = entry.id;
      card.dataset.settingType = entry.type;
      const heading = element('div', 'settings-entry-heading');
      const label = element('label', 'settings-entry-label', entry.label);
      if (entry.type !== 'fixed' && entry.type !== 'profile-review') {
        label.htmlFor = `settings-registry-${safeId(entry.id)}`;
      }
      heading.appendChild(label);
      const badges = element('div', 'settings-entry-badges');
      const controlLabel = entry.controlClass === 'optional'
        ? 'Optional'
        : (entry.controlClass === 'optional-core'
          ? 'Optional'
          : (entry.controlClass === 'mandatory-fixed' ? 'Mandatory' : 'Configurable'));
      badges.appendChild(element('span', `settings-badge settings-badge-${safeId(entry.controlClass)}`, controlLabel));
      if (entry.changedFromDefault) badges.appendChild(element('span', 'settings-badge settings-badge-changed', 'Changed'));
      if (entry.originalRelevant) badges.appendChild(element('span', 'settings-badge settings-badge-original', 'Original ChatSpace behavior'));
      if ((entry.dependencies || []).length) {
        const dependency = element('span', 'settings-badge settings-badge-dependency', '');
        dependency.dataset.settingsDependencyBadge = 'true';
        dependency.hidden = true;
        badges.appendChild(dependency);
      }
      heading.appendChild(badges);
      card.appendChild(heading);
      card.appendChild(element('p', 'settings-entry-description', entry.description));
      if (entry.helpText) card.appendChild(element('p', 'minor settings-entry-help', entry.helpText));
      if (entry.fixedReason) card.appendChild(element('p', 'minor settings-entry-fixed-reason', entry.fixedReason));
      if (entry.owner === 'operational_capacity_policy' && entry.type === 'number') {
        const measurement = element('dl', 'settings-entry-destination settings-capacity-metadata');
        measurement.append(
          element('dt', '', 'Certified hard bound'),
          element('dd', '', `${entry.minimum}–${entry.maximum}`),
          element('dt', '', 'Measured recommendation'),
          element('dd', '', String(entry.defaultValue)),
          element('dt', '', 'Recommendation source'),
          element('dd', '', 'Measured installation guidance')
        );
        card.appendChild(measurement);
      }
      if (entry.previewPage || entry.previewField || entry.standardFallback) {
        const destination = element('dl', 'settings-entry-destination');
        const addMeta = (label, value) => {
          if (value === '' || value === null || value === undefined) return;
          destination.append(element('dt', '', label), element('dd', '', String(value)));
        };
        addMeta('Preview page', entry.previewPage);
        addMeta('Exact field', entry.previewField);
        addMeta('Editing state', entry.allowsOverride
          ? (String(entry.currentValue ?? '').trim() === '' ? 'Inherited' : 'Explicit override')
          : (entry.type === 'fixed' ? 'Protected read-only' : 'Shared value'));
        addMeta('Inheritance source', entry.inheritanceSource
          ? (this.entryMap.get(entry.inheritanceSource)?.label || entry.inheritanceSource)
          : 'Shared value');
        addMeta('Effective value', entry.effectiveValue);
        addMeta('Standard fallback', entry.standardFallback);
        if (entry.previewPath) {
          destination.appendChild(element('dt', '', 'Preview'));
          const previewValue = element('dd');
          const link = element('a', '', `Open ${entry.previewPage || 'preview'}`);
          const base = String(document.body?.dataset?.appBase || '').replace(/\/$/, '');
          link.href = `${base}${entry.previewPath}`;
          link.target = '_blank';
          link.rel = 'noopener';
          previewValue.appendChild(link);
          destination.appendChild(previewValue);
        }
        card.appendChild(destination);
      }
      const controlRow = element('div', 'settings-entry-control');
      controlRow.appendChild(this.createControl(entry));
      if (entry.allowsOverride) {
        const useShared = element('button', 'btn settings-entry-use-shared', 'Use shared value');
        useShared.type = 'button';
        useShared.disabled = this.readOnly || this.locked;
        useShared.addEventListener('click', () => this.setDraftValues({ [entry.id]: '' }));
        controlRow.appendChild(useShared);
      }
      if (entry.safeToReset && !['asset', 'fixed', 'editable-reminder'].includes(entry.type)) {
        const reset = this.operationButton('Reset', 'reset_setting', { setting_id: entry.id }, 'btn settings-entry-reset');
        reset.setAttribute('aria-label', `${entry.resetLabel || 'Reset'}: ${entry.label}`);
        controlRow.appendChild(reset);
      }
      card.appendChild(controlRow);
      const meta = element('div', 'settings-entry-meta');
      meta.appendChild(element('code', '', entry.id));
      meta.appendChild(element('span', '', `Default: ${entry.type === 'boolean' ? (entry.defaultValue ? 'Enabled' : 'Disabled') : entry.defaultValue}`));
      card.appendChild(meta);
      return card;
    }

    entryRequiresWidePresentation(entry) {
      if (['asset', 'color', 'editable-reminder', 'fixed', 'profile-review', 'file-transfer-provenance'].includes(entry.type)) return true;
      if (entry.previewPath || entry.standardFallback || entry.fixedReason || entry.allowsOverride) return true;
      if ((entry.dependencies || []).length) return true;
      return `${entry.description || ''} ${entry.helpText || ''}`.trim().length > 220;
    }

    renderEntry(entry) {
      const row = element('article', `settings-entry settings-entry-${safeId(entry.controlClass)}`);
      row.dataset.settingId = entry.id;
      row.dataset.settingType = entry.type;
      row.classList.toggle('is-dirty', this.isDirty(entry));
      row.classList.toggle('settings-entry-wide', this.entryRequiresWidePresentation(entry));
      const controlId = `settings-registry-${safeId(entry.id)}`;
      const main = element('div', 'settings-entry-main');
      const labelGroup = element('div', 'settings-entry-label-group');
      const label = element('label', 'settings-entry-label', entry.label);
      if (!['fixed', 'profile-review', 'file-transfer-provenance'].includes(entry.type)) label.htmlFor = controlId;
      const helpId = `${controlId}-details`;
      const infoLabel = `More information about ${entry.label}`;
      const info = element('button', 'settings-entry-info');
      const infoGlyph = element('span', 'settings-entry-info-glyph', 'i');
      infoGlyph.setAttribute('aria-hidden', 'true');
      info.appendChild(infoGlyph);
      info.type = 'button';
      info.title = infoLabel;
      info.setAttribute('aria-label', infoLabel);
      info.setAttribute('aria-controls', helpId);
      info.setAttribute('aria-expanded', 'false');

      const controlRow = element('div', 'settings-entry-control');
      const control = this.createControl(entry);
      let authenticationWarning = null;
      if (entry.id === 'flood_authentication_protection_enabled' && entry.type === 'boolean') {
        authenticationWarning = element('section', 'settings-auth-protection-warning');
        authenticationWarning.hidden = true;
        authenticationWarning.setAttribute('role', 'alert');
        authenticationWarning.setAttribute('aria-label', 'Authentication Protection disable confirmation');
        authenticationWarning.appendChild(element(
          'strong',
          '',
          'High severity: disabling Authentication Protection allows repeated authentication attempts without this limiter.',
        ));
        const warningActions = element('div', 'shared-form-actions');
        const keepEnabled = element('button', 'btn', 'No \u2014 Keep Authentication Protection Enabled');
        keepEnabled.type = 'button';
        const disable = element('button', 'btn btn-danger', 'Yes \u2014 Disable Authentication Protection');
        disable.type = 'button';
        const cancelWarning = (restoreFocus = false) => {
          authenticationWarning.hidden = true;
          control.checked = true;
          this.authenticationProtectionDisableConfirmed = false;
          if (restoreFocus) control.focus();
        };
        keepEnabled.addEventListener('click', () => cancelWarning(true));
        disable.addEventListener('click', () => {
          authenticationWarning.hidden = true;
          this.authenticationProtectionDisableConfirmed = true;
          control.dataset.authenticationDisableApproved = '1';
          control.checked = false;
          control.dispatchEvent(new Event('change', { bubbles: true }));
          control.focus();
        });
        authenticationWarning.addEventListener('keydown', event => {
          if (event.key !== 'Escape') return;
          event.preventDefault();
          cancelWarning(true);
        });
        authenticationWarning.addEventListener('focusout', () => {
          window.setTimeout(() => {
            if (authenticationWarning.hidden) return;
            if (!authenticationWarning.contains(document.activeElement) && document.activeElement !== control) {
              cancelWarning(false);
            }
          }, 0);
        });
        warningActions.append(keepEnabled, disable);
        authenticationWarning.appendChild(warningActions);
        control.addEventListener('change', event => {
          if (control.dataset.authenticationDisableApproved === '1') {
            delete control.dataset.authenticationDisableApproved;
            return;
          }
          if (control.checked) {
            this.authenticationProtectionDisableConfirmed = false;
            authenticationWarning.hidden = true;
            return;
          }
          event.preventDefault();
          event.stopImmediatePropagation();
          control.checked = true;
          authenticationWarning.hidden = false;
          window.requestAnimationFrame(() => disable.focus());
        }, true);
      }
      if (entry.type === 'boolean') {
        const booleanControl = element('div', 'settings-boolean-control');
        const booleanLabel = element('label', 'settings-boolean-label');
        booleanLabel.htmlFor = controlId;
        booleanLabel.append(control, element('span', '', entry.label));
        booleanControl.append(booleanLabel, info);
        controlRow.appendChild(booleanControl);
        labelGroup.appendChild(element('span', 'settings-entry-label settings-entry-label-spacer', entry.label));
      } else {
        const labelLine = element('div', 'settings-entry-label-line');
        labelLine.append(label, info);
        labelGroup.appendChild(labelLine);
        controlRow.appendChild(control);
      }

      const adjacent = element('div', 'settings-entry-adjacent');
      const unit = this.unitFor(entry);
      if (unit) adjacent.appendChild(element('span', 'settings-entry-unit', unit));
      const range = this.rangeFor(entry);
      if (range) adjacent.appendChild(element('span', 'settings-entry-range', `Allowed: ${range}`));
      if (entry.safeToReset && !['asset', 'fixed', 'editable-reminder'].includes(entry.type)) {
        const reset = this.operationButton('Reset', 'reset_setting', { setting_id: entry.id }, 'btn settings-entry-reset');
        reset.setAttribute('aria-label', `${entry.resetLabel || 'Reset'}: ${entry.label}`);
        controlRow.appendChild(reset);
      }

      const actions = element('div', 'settings-entry-actions');
      const badges = element('div', 'settings-entry-badges');
      if (entry.changedFromDefault) badges.appendChild(element('span', 'settings-badge settings-badge-changed', 'Changed'));
      if (entry.mandatory || entry.controlClass === 'mandatory-fixed') {
        badges.appendChild(element('span', 'settings-badge settings-badge-required', 'Required'));
      }
      if (entry.optional && entry.type === 'boolean' && !this.draft.get(entry.id)) {
        badges.appendChild(element('span', 'settings-badge settings-badge-disabled', 'Disabled'));
      }
      if ((entry.dependencies || []).length) {
        const dependency = element('span', 'settings-badge settings-badge-dependency', '');
        dependency.dataset.settingsDependencyBadge = 'true';
        dependency.hidden = true;
        badges.appendChild(dependency);
      }
      actions.appendChild(badges);

      if (entry.allowsOverride) {
        const sourceLabel = this.inheritedSourceLabel(entry);
        const useShared = element('button', 'btn settings-entry-use-shared', `Use ${sourceLabel}`);
        useShared.type = 'button';
        useShared.dataset.useInherited = 'true';
        useShared.disabled = this.readOnly || this.locked;
        useShared.addEventListener('click', () => this.setDraftValues({ [entry.id]: '' }));
        const sharedStatus = element('span', 'settings-entry-inherited-status', `Using ${sourceLabel}`);
        sharedStatus.dataset.usingInherited = 'true';
        actions.append(useShared, sharedStatus);
      }
      main.append(labelGroup, controlRow);
      if (adjacent.childElementCount) main.appendChild(adjacent);
      main.appendChild(actions);
      row.appendChild(main);
      if (authenticationWarning) row.appendChild(authenticationWarning);

      if (entry.type !== 'color' && !['asset', 'fixed', 'profile-review'].includes(entry.type)) {
        const error = element('p', 'settings-entry-error');
        error.id = `${controlId}-error`;
        error.dataset.settingsValidationError = 'true';
        error.setAttribute('role', 'alert');
        error.hidden = true;
        row.appendChild(error);
      }

      const currentBranding = String(entry.effectiveValue ?? '');
      const standardBranding = String(entry.standardFallback ?? '');
      const usesOriginalStandardBranding = Boolean(entry.standardFallback)
        && currentBranding === standardBranding
        && standardBranding === 'ChatSpace Community Edition';
      const help = element('section', 'settings-entry-help-panel');
      help.id = helpId;
      help.hidden = true;
      help.tabIndex = -1;
      help.appendChild(element(
        'p',
        'settings-entry-description',
        this.plainPresentationText(entry.description || `Controls ${entry.label.toLocaleLowerCase()}.`, entry),
      ));
      if (entry.helpText) {
        const plainHelpText = this.plainPresentationText(entry.helpText, entry);
        const presentationHelpText = usesOriginalStandardBranding
          ? plainHelpText.replaceAll('ChatSpace Community Edition', 'the original standard branding')
          : plainHelpText;
        help.appendChild(element('p', 'minor settings-entry-help', presentationHelpText));
      }
      if (entry.fixedReason) {
        help.appendChild(element(
          'p',
          'minor settings-entry-fixed-reason',
          this.plainPresentationText(entry.fixedReason, entry),
        ));
      }

      const details = element('dl', 'settings-entry-details');
      const addDetail = (name, value) => {
        if (value === '' || value === null || value === undefined) return;
        details.append(element('dt', '', name), element('dd', '', String(value)));
      };
      addDetail('Standard value', entry.type === 'boolean' ? (entry.defaultValue ? 'Enabled' : 'Disabled') : entry.defaultValue);
      addDetail('Allowed range', range);
      if (entry.dependencies?.length) {
        addDetail('Depends on', entry.dependencies.map(id => this.entryMap.get(id)?.label || 'another setting').join(', '));
      }
      if (entry.effectiveValue !== undefined
        && !valuesEqual(entry.effectiveValue, entry.currentValue, entry.type)
        && !usesOriginalStandardBranding) {
        addDetail('Saved value', entry.currentValue);
        addDetail('Value currently in use', entry.effectiveValue);
      }
      if (entry.standardFallback) {
        if (usesOriginalStandardBranding) {
          addDetail('Current branding', 'ChatSpace Community Edition');
          help.appendChild(element('p', 'settings-branding-fallback-note', 'Using the original standard branding because no custom value is set.'));
        } else {
          addDetail('Current value', currentBranding);
          addDetail('Standard fallback', standardBranding);
        }
      }
      if (details.childElementCount) help.appendChild(details);
      if (entry.previewPath) {
        const base = String(document.body?.dataset?.appBase || '').replace(/\/$/, '');
        const link = element('a', 'btn settings-entry-preview', `Open ${entry.previewPage || 'preview'}`);
        link.href = `${base}${entry.previewPath}`;
        link.target = '_blank';
        link.rel = 'noopener';
        help.appendChild(link);
      }
      row.appendChild(help);

      const closeHelp = () => {
        help.hidden = true;
        info.setAttribute('aria-expanded', 'false');
        row.classList.remove('is-expanded');
        this.expandedHelpId = '';
        info.focus();
      };
      info.addEventListener('click', () => {
        const opening = help.hidden;
        if (opening && this.expandedHelpId) {
          const previous = this.container.querySelector(`#${CSS.escape(this.expandedHelpId)}`);
          const previousRow = previous?.closest('[data-setting-id]');
          if (previous) previous.hidden = true;
          previousRow?.classList.remove('is-expanded');
          previousRow?.querySelector('.settings-entry-info')?.setAttribute('aria-expanded', 'false');
        }
        help.hidden = !opening;
        info.setAttribute('aria-expanded', opening ? 'true' : 'false');
        row.classList.toggle('is-expanded', opening);
        this.expandedHelpId = opening ? helpId : '';
        if (opening) {
          help.focus({ preventScroll: true });
          window.requestAnimationFrame(() => this.ensureHelpPanelClearance(help));
        }
      });
      help.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        closeHelp();
      });
      return row;
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
          else control.value = entry.type === 'color' ? String(value ?? '').toUpperCase() : String(value ?? '');
          control.closest('[data-setting-id]')?.classList.toggle('is-dirty', this.isDirty(entry));
        }
      }
      this.updateSummaries();
      this.applyDependencyStates();
      this.syncLimitEnforcementControls();
      this.syncInheritedActions();
      this.refreshRoleColorPresentation();
      this.refreshValidationPresentation();
      this.applySearchAndFilter();
      this.onDraftChange(this.getState());
      this.syncSurfacePresentation();
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
        if (!entry.originalRelevant
            || !entry.originalValueAvailable
            || !entry.safeToReset
            || !(entry.bulkOperations || []).includes('preset')) continue;
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
        if (categoryEntries.some(entry => entry.safeToReset && (entry.bulkOperations || []).includes('category'))) {
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
          const floodEntries = sectionEntries.filter(entry => entry.bulkGroup === 'flood-protection');
          if (floodEntries.length) {
            headerText.appendChild(element('p', 'minor settings-flood-protection-info', 'Disables all optional flood protections except Authentication Protection. Authentication Protection is not changed by Disable All.'));
            const optionalFloodEntries = floodEntries.filter(entry => entry.id !== 'flood_authentication_protection_enabled');
            actions.appendChild(this.operationButton('Enable All', 'set_many', { values: Object.fromEntries(floodEntries.map(entry => [entry.id, true])) }, 'btn'));
            actions.appendChild(this.operationButton('Disable All', 'set_many', { values: Object.fromEntries(optionalFloodEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
            actions.appendChild(this.operationButton('Restore defaults', 'set_many', { values: Object.fromEntries(sectionEntries.filter(entry => entry.safeToReset).map(entry => [entry.id, entry.defaultValue])) }, 'btn'));
          }
          if (sectionEntries.some(entry => entry.bulkGroup === 'dances')) {
            actions.appendChild(this.operationButton('Enable All Dances', 'set_many', { values: Object.fromEntries(sectionEntries.map(entry => [entry.id, true])) }, 'btn'));
            actions.appendChild(this.operationButton('Disable All Dances', 'set_many', { values: Object.fromEntries(sectionEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
          }
          if (sectionEntries.some(entry => entry.bulkGroup === 'gesture-part-3')) {
            const gestureEntries = sectionEntries.filter(entry => entry.bulkGroup === 'gesture-part-3');
            actions.appendChild(this.operationButton('Enable All Gesture Features', 'set_many', { values: Object.fromEntries(gestureEntries.map(entry => [entry.id, true])) }, 'btn'));
            actions.appendChild(this.operationButton('Disable All Gesture Features', 'set_many', { values: Object.fromEntries(gestureEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
          }
          if (sectionEntries.some(entry => entry.bulkGroup === 'gesture-capability')) {
            const capabilityEntries = sectionEntries.filter(entry => entry.bulkGroup === 'gesture-capability');
            actions.appendChild(this.operationButton('Enable All Gesture Capabilities', 'set_many', { values: Object.fromEntries(capabilityEntries.map(entry => [entry.id, true])) }, 'btn'));
            actions.appendChild(this.operationButton('Disable All Gesture Capabilities', 'set_many', { values: Object.fromEntries(capabilityEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
          }
          if (sectionEntries.some(entry => entry.bulkGroup === 'gesture-part-4')) {
            const packageEntries = sectionEntries.filter(entry => entry.bulkGroup === 'gesture-part-4');
            actions.appendChild(this.operationButton('Enable All Gesture Maker and Package Features', 'set_many', { values: Object.fromEntries(packageEntries.map(entry => [entry.id, true])) }, 'btn'));
            actions.appendChild(this.operationButton('Disable All Gesture Maker and Package Features', 'set_many', { values: Object.fromEntries(packageEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
          }
          if (sectionEntries.some(entry => entry.safeToReset && (entry.bulkOperations || []).includes('subsection'))) {
            actions.appendChild(this.operationButton('Reset Subsection', 'reset_subsection', { category_id: category.id, subsection_id: subsectionId }, 'btn'));
          }
          header.appendChild(actions);
          section.appendChild(header);
          const grid = element('div', 'settings-entry-grid');
          for (const entry of sectionEntries) grid.appendChild(this.renderEntry(entry));
          section.appendChild(grid);
          details.appendChild(section);
        }
        this.container.appendChild(details);
      }
      this.applyDependencyStates();
      this.applySearchAndFilter();
      document.querySelectorAll('[data-edit-branding-reminder]').forEach(button => {
        button.disabled = this.readOnly || this.locked;
        this.applyControlLockSemantics(button);
        button.onclick = () => {
          if (this.readOnly || this.locked) return;
          const card = Array.from(this.container.querySelectorAll('[data-setting-id]'))
            .find(node => node.dataset.settingId === 'branding_license_reminder');
          card?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
          card?.querySelector('.settings-reminder-actions .btn')?.click();
        };
      });
    }

    isLimitEntry(entry) {
      if (entry.limitGroup) return true;
      if (entry.type !== 'number') return false;
      const id = String(entry.id || '');
      return /(?:limit|maximum|max_|_max|rate|per_second|history|timeout|attempt|lockout|window|capacity|width|height|size|retention_days|profile)/i.test(id)
        && !/age_gate_min_age/i.test(id);
    }

    limitGroups() {
      return [
        'Chat & Presence',
        'Avatar & Webcam Sizes',
        'Room & Media Uploads',
        'Profile Limits',
        'Account Protection',
        'Avatar Relationships',
        'Community Capacity',
        'Private Voice Chats',
        'Diagnostics',
        'Other Limits',
      ];
    }

    limitSections() {
      return ['Recommended Safeguards', 'Operational Controls', 'Community Preferences', 'Feature-Specific Limits'];
    }

    limitFeatureInactive(entry) {
      return (entry.dependencies || []).some(id => this.draft.get(id) === false);
    }

    syncLimitEnforcementControls() {
      for (const entry of this.entries.filter(item => this.isLimitEntry(item))) {
        const enforcementId = entry.enforcementSettingId;
        if (!enforcementId) continue;
        const enforced = this.draft.get(enforcementId) !== false;
        const inactive = this.limitFeatureInactive(entry);
        const control = this.controls.get(entry.id);
        if (control) control.disabled = this.readOnly || this.locked || !enforced || inactive;
        const state = this.container?.querySelector?.(`[data-limit-state-for="${safeId(entry.id)}"]`);
        if (state) state.textContent = inactive ? 'Inactive - feature disabled' : (enforced ? 'Enabled' : 'Disabled');
        const noLimit = this.container?.querySelector?.(`[data-limit-unenforced-for="${safeId(entry.id)}"]`);
        if (noLimit) noLimit.hidden = enforced || inactive;
      }
    }

    limitGroupFor(entry) {
      if (entry.limitGroup) return String(entry.limitGroup);
      const id = String(entry.id || '');
      if (/chat_posts|room_chat_history|avatar_movements|idle_timeout/.test(id)) return 'Chat & Presence';
      if (/avatar_(?:max_size|upload_max|display_max)|webcam_display_max/.test(id)) return 'Avatar & Webcam Sizes';
      if (/room_(?:image|video)_max|gesture_upload_limit|media/.test(id)) return 'Room & Media Uploads';
      if (/^profile_|_character_limit|member_profile/.test(id)) return 'Profile Limits';
      if (/^auth_/.test(id)) return 'Account Protection';
      if (/relationship/.test(id)) return 'Avatar Relationships';
      if (/capacity|concurrent|events|participants|rooms/.test(id) || entry.owner === 'operational_capacity_policy') return 'Community Capacity';
      return 'Other Limits';
    }

    limitEntries() {
      const groups = new Set(this.limitGroups());
      const entries = this.entries.filter(entry => this.isLimitEntry(entry));
      const incomplete = entries.filter(entry => !this.unitFor(entry) || !groups.has(this.limitGroupFor(entry)));
      if (incomplete.length) {
        throw new Error(`Limit settings require units and known groups: ${incomplete.map(entry => entry.id).join(', ')}`);
      }
      return entries;
    }

    selectView(view, pushHistory = true) {
      const categories = this.registry?.categories || [];
      const valid = view === 'overview'
        || view === 'limits'
        || categories.some(category => category.id === view);
      if (!valid) return false;
      this.selectedView = view;
      if (view !== 'overview' && view !== 'limits') this.lastCategoryView = view;
      window.sessionStorage?.setItem(this.sessionKey, view);
      if (pushHistory) {
        const url = new URL(window.location.href);
        url.hash = `settings-${view}`;
        window.history.pushState({ settingsView: view }, '', url);
      }
      this.render();
      this.onViewChange(view);
      window.requestAnimationFrame(() => {
        const heading = this.container.querySelector('[data-settings-selected-heading]');
        window.applyProgrammaticHeadingFocus(heading);
        this.ensureSelectedHeadingClearance(heading);
      });
      return true;
    }

    makeViewControl(view, label, entries) {
      const button = element('button', 'settings-section-nav-item', label);
      button.type = 'button';
      button.dataset.settingsNavItem = view;
      button.classList.toggle('active', this.selectedView === view);
      button.setAttribute('aria-current', this.selectedView === view ? 'page' : 'false');
      const count = element('span', 'settings-section-nav-count', String(entries.length));
      const changed = element('span', 'settings-section-nav-changed', '0');
      changed.dataset.settingsChangedCount = 'true';
      changed.dataset.settingIds = entries.map(entry => entry.id).join(',');
      changed.setAttribute('aria-label', 'changed settings');
      button.append(count, changed);
      button.addEventListener('click', () => this.selectView(view));
      return button;
    }

    renderSubsections(target, entries, categoryId = '') {
      const subsectionIds = [...new Set(entries.map(entry => entry.subsectionId))];
      const subsectionGrid = element('div', 'settings-subsection-grid');
      for (const subsectionId of subsectionIds) {
        const sectionEntries = entries.filter(entry => entry.subsectionId === subsectionId);
        const section = element('section', 'settings-subsection');
        section.classList.toggle(
          'settings-subsection-wide',
          sectionEntries.some(entry => entry.type === 'color'),
        );
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
        const floodEntries = sectionEntries.filter(entry => entry.bulkGroup === 'flood-protection');
        if (floodEntries.length) {
          headerText.appendChild(element('p', 'minor settings-flood-protection-info', 'Disables all optional flood protections except Authentication Protection. Authentication Protection is not changed by Disable All.'));
          const optionalFloodEntries = floodEntries.filter(entry => entry.id !== 'flood_authentication_protection_enabled');
          actions.appendChild(this.operationButton('Enable All', 'set_many', { values: Object.fromEntries(floodEntries.map(entry => [entry.id, true])) }, 'btn'));
          actions.appendChild(this.operationButton('Disable All', 'set_many', { values: Object.fromEntries(optionalFloodEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
          actions.appendChild(this.operationButton('Restore defaults', 'set_many', { values: Object.fromEntries(sectionEntries.filter(entry => entry.safeToReset).map(entry => [entry.id, entry.defaultValue])) }, 'btn'));
        }
        const bulkGroups = [
          ['dances', 'Dances'],
          ['gesture-part-3', 'Browsing and Organization'],
          ['gesture-capability', 'Gesture Availability'],
          ['gesture-part-4', 'Creation, Packages, and Media'],
        ];
        for (const [bulkGroup, label] of bulkGroups) {
          const groupEntries = sectionEntries.filter(entry => entry.bulkGroup === bulkGroup);
          if (!groupEntries.length) continue;
          actions.appendChild(this.operationButton(`Enable All ${label}`, 'set_many', { values: Object.fromEntries(groupEntries.map(entry => [entry.id, true])) }, 'btn'));
          actions.appendChild(this.operationButton(`Disable All ${label}`, 'set_many', { values: Object.fromEntries(groupEntries.map(entry => [entry.id, false])) }, 'btn btn-danger'));
        }
        if (sectionEntries.some(entry => entry.safeToReset && (entry.bulkOperations || []).includes('subsection'))) {
          actions.appendChild(this.operationButton('Reset Group', 'reset_subsection', { category_id: categoryId, subsection_id: subsectionId }, 'btn'));
        }
        header.appendChild(actions);
        section.appendChild(header);
        const grid = element('div', 'settings-entry-grid');
        for (const entry of sectionEntries) grid.appendChild(this.renderEntry(entry));
        section.appendChild(grid);
        subsectionGrid.appendChild(section);
      }
      target.appendChild(subsectionGrid);
    }

    renderCategory(target, category, entries, selectedHeading = false) {
      const categorySection = element('section', 'settings-category');
      categorySection.dataset.settingsCategory = category.id;
      const heading = element('div', 'settings-category-summary');
      const title = element('h2', 'settings-category-title', category.label);
      if (selectedHeading) {
        title.tabIndex = -1;
        title.dataset.settingsSelectedHeading = 'true';
      }
      const counts = element('span', 'settings-category-counts');
      counts.dataset.settingsSummary = 'category';
      counts.dataset.settingIds = entries.map(entry => entry.id).join(',');
      counts.textContent = this.summaryFor(entries);
      heading.append(title, counts);
      if (entries.some(entry => entry.safeToReset && (entry.bulkOperations || []).includes('category'))) {
        heading.appendChild(this.operationButton('Reset Category', 'reset_category', { category_id: category.id }, 'btn'));
      }
      categorySection.appendChild(heading);
      this.renderSubsections(categorySection, entries, category.id);
      if (category.id === 'system') {
        const diagnostics = element('details', 'settings-checksum-diagnostics');
        const extensions = this.registry?.firstPartyExtensions || [];
        const changed = extensions.filter(item => item.integrity?.state !== 'verified');
        diagnostics.appendChild(element('summary', '', `Release file checksums — ${changed.length ? `${changed.length} modified or unverified features` : 'all checked files match'}`));
        diagnostics.appendChild(element('p', '', 'Compared with release-manifest.json. Editing files does not update this inventory. Checksums are refreshed when a reviewed release is prepared.'));
        for (const item of extensions) {
          const detail = element('details');
          const check = item.integrity;
          const label = check?.state === 'modified' ? 'Locally modified' : check?.state === 'verified' ? 'Matches release' : 'Unverified';
          detail.appendChild(element('summary', '', `${item.name} — ${label}${item.state === 'integrity-blocked' ? ' (blocked by strict checking)' : ''}`));
          if (item.failure) detail.appendChild(element('p', '', item.failure));
          for (const path of check?.modifiedFiles || []) detail.appendChild(element('div', '', path));
          for (const path of check?.unverifiedFiles || []) detail.appendChild(element('div', '', `${path} — no release checksum`));
          diagnostics.appendChild(detail);
        }
        categorySection.appendChild(diagnostics);
      }
      target.appendChild(categorySection);
    }

    renderLimitEntry(entry) {
      const card = element('article', 'settings-entry settings-limit-entry');
      card.dataset.settingId = entry.id;
      const titleRow = element('div', 'settings-limit-title-row');
      const title = element('h4', '', entry.label);
      const state = element('span', 'settings-badge settings-limit-state', entry.limitState || 'Enabled');
      state.dataset.limitStateFor = safeId(entry.id);
      titleRow.append(title, state);

      const enforcement = this.entryMap.get(entry.enforcementSettingId);
      const enforcementWrap = element('div', 'settings-limit-enforcement');
      if (enforcement) {
        enforcementWrap.dataset.settingId = enforcement.id;
        const label = element('label', 'settings-boolean-label');
        const control = this.createControl(enforcement);
        label.htmlFor = control.id;
        label.append(control, element('span', '', 'Enforce limit'));
        enforcementWrap.appendChild(label);
      }

      const valueRow = element('div', 'settings-limit-value-row');
      const valueLabel = element('label', 'settings-entry-label', 'Saved value');
      const control = this.createControl(entry);
      valueLabel.htmlFor = control.id;
      valueRow.append(valueLabel, control);
      const unit = this.unitFor(entry);
      if (unit) valueRow.appendChild(element('span', 'settings-entry-unit', unit));
      const range = this.rangeFor(entry);
      if (range) valueRow.appendChild(element('span', 'settings-entry-range', `Allowed: ${range}`));

      const recommendation = element('p', 'settings-limit-recommendation', `Recommended: ${entry.recommendedValue ?? entry.defaultValue} ${unit}`.trim());
      const noLimit = element('p', 'settings-limit-unenforced', 'Configured enforcement is off. The saved value is preserved; mandatory format, storage, protocol, and platform safety boundaries still apply.');
      noLimit.dataset.limitUnenforcedFor = safeId(entry.id);
      noLimit.hidden = this.draft.get(entry.enforcementSettingId) !== false;
      const risk = element('p', 'settings-limit-risk', entry.riskWarning || 'Disabling this limit removes CoreChat enforcement.');
      risk.setAttribute('role', 'note');
      const details = element('details', 'settings-limit-help');
      details.append(element('summary', '', 'Why this limit matters'), element('p', '', entry.helpText || entry.description || entry.riskWarning));
      const id = element('code', 'settings-limit-id', entry.id);
      card.append(titleRow, enforcementWrap, valueRow, recommendation, noLimit, risk, details, id);
      return card;
    }

    renderLimitEvents(target) {
      const section = element('section', 'settings-category settings-limit-events');
      const heading = element('div', 'settings-category-summary');
      heading.append(element('h2', 'settings-category-title', 'Limit Events'), element('span', 'settings-category-counts', 'Privacy-safe operational log'));
      const description = element('p', 'minor', 'Records when an enforced limit is reached. Message content, files, credentials, tokens, email addresses, and raw network addresses are not stored.');
      const filters = element('div', 'settings-limit-event-filters');
      const search = element('input'); search.type = 'search'; search.placeholder = 'Filter by limit or outcome'; search.setAttribute('aria-label', 'Filter Limit Events');
      const outcome = element('select'); outcome.setAttribute('aria-label', 'Filter Limit Events by outcome');
      for (const [value, label] of [['','All outcomes'],['blocked','Blocked'],['warning','Warning'],['allowed','Allowed after review']]) { const option = element('option', '', label); option.value = value; outcome.appendChild(option); }
      const exportJson = element('a', 'btn', 'Export JSON');
      const exportCsv = element('a', 'btn', 'Export CSV');
      const base = String(document.body?.dataset?.appBase || '').replace(/\/$/, '');
      exportJson.href = `${base}/api/limit_events.php?action=export&format=json`;
      exportCsv.href = `${base}/api/limit_events.php?action=export&format=csv`;
      filters.append(search, outcome, exportJson, exportCsv);
      const status = element('p', 'minor'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
      const table = element('table', 'settings-limit-events-table');
      const pager = element('div', 'shared-form-actions');
      const previous = element('button', 'btn', 'Previous'); previous.type = 'button';
      const next = element('button', 'btn', 'Next'); next.type = 'button';
      pager.append(previous, next);
      let page = Number(this.registry?.limitEvents?.page || 1);
      let snapshot = this.registry?.limitEvents || { items: [], total: 0, pages: 0 };
      const draw = () => {
        table.replaceChildren();
        const head = document.createElement('thead'); const row = document.createElement('tr');
        for (const label of ['Limit','Outcome','Occurrences','Last reached','Audit','']) row.appendChild(element('th', '', label));
        head.appendChild(row); table.appendChild(head); const body = document.createElement('tbody');
        for (const item of snapshot.items || []) {
          const tr = document.createElement('tr');
          tr.append(element('td', '', item.limitName || item.settingId), element('td', '', item.outcome), element('td', '', String(item.occurrenceCount || 1)), element('td', '', item.lastReachedAt || ''), element('td', '', item.auditRunPublicId || 'Not linked'));
          const action = document.createElement('td');
          if (!String(item.settingId || '').includes('idle')) {
            const details = element('button', 'btn', 'Details'); details.type = 'button';
            details.dataset.limitEventDetails = item.publicId;
            action.appendChild(details);
          }
          const remove = element('button', 'btn btn-danger', 'Delete'); remove.type = 'button'; remove.disabled = this.readOnly || this.locked;
          remove.addEventListener('click', async () => {
            if (!window.confirm('Delete this privacy-safe Limit Event record?')) return;
            const csrf = document.querySelector('input[name="csrf"]')?.value || '';
            const response = await fetch(`${base}/api/limit_events.php`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ action: 'delete', public_id: item.publicId, csrf }) });
            if (!response.ok) throw new Error('Limit Event could not be deleted.');
            await load();
          }); action.appendChild(remove); tr.appendChild(action); body.appendChild(tr);
        }
        if (!(snapshot.items || []).length) { const tr = document.createElement('tr'); const td = element('td', 'minor', 'No matching Limit Events.'); td.colSpan = 6; tr.appendChild(td); body.appendChild(tr); }
        table.appendChild(body); status.textContent = `${snapshot.total || 0} event record${Number(snapshot.total || 0) === 1 ? '' : 's'}; page ${snapshot.page || 1} of ${Math.max(1, snapshot.pages || 1)}`;
        previous.disabled = page <= 1; next.disabled = page >= Math.max(1, Number(snapshot.pages || 1));
      };
      const load = async () => {
        const params = new URLSearchParams({ page: String(page), page_size: '25', search: search.value, outcome: outcome.value });
        const response = await fetch(`${base}/api/limit_events.php?${params}`); const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || 'Limit Events could not be loaded.'); snapshot = payload.limitEvents; draw();
      };
      let timer = null; search.addEventListener('input', () => { window.clearTimeout(timer); page = 1; timer = window.setTimeout(() => load().catch(error => { status.textContent = error.message; }), 180); });
      outcome.addEventListener('change', () => { page = 1; load().catch(error => { status.textContent = error.message; }); });
      previous.addEventListener('click', () => { page = Math.max(1, page - 1); load().catch(error => { status.textContent = error.message; }); });
      next.addEventListener('click', () => { page += 1; load().catch(error => { status.textContent = error.message; }); });
      section.append(heading, description, filters, status, table, pager); target.appendChild(section); draw();
    }

    renderLimits(target, entries) {
      const section = element('section', 'settings-category settings-limits-view');
      section.dataset.settingsCategory = 'limits';
      section.dataset.settingsView = 'limits';
      const heading = element('div', 'settings-category-summary');
      const title = element('h2', 'settings-category-title', 'Limit Settings');
      title.tabIndex = -1;
      title.dataset.settingsSelectedHeading = 'true';
      const counts = element('span', 'settings-category-counts', `${entries.length} configurable limits`);
      heading.append(title, counts);
      section.appendChild(heading);
      const bulk = element('section', 'settings-limit-bulk');
      bulk.appendChild(element('h3', '', 'Draft configuration controls'));
      bulk.appendChild(element('p', 'minor', 'These controls update the draft only. Review the changes, then use the shared Save Changes action.'));
      const bulkActions = element('div', 'shared-form-actions');
      const companions = entries.map(entry => this.entryMap.get(entry.enforcementSettingId)).filter(Boolean);
      const draftButton = (label, values, className = 'btn') => { const button = element('button', className, label); button.type = 'button'; button.disabled = this.readOnly || this.locked; button.addEventListener('click', () => this.setDraftValues(values)); return button; };
      bulkActions.append(
        draftButton('Apply Recommended Configuration', Object.fromEntries([...entries.map(entry => [entry.id, entry.recommendedValue ?? entry.defaultValue]), ...companions.map(entry => [entry.id, true])])),
        draftButton('Enable All Limits', Object.fromEntries(companions.map(entry => [entry.id, true]))),
        draftButton('Restore Saved Values', Object.fromEntries([...entries, ...companions].map(entry => [entry.id, entry.currentValue]))),
      );
      const disable = element('button', 'btn btn-danger', 'Disable All Limits'); disable.type = 'button'; disable.disabled = this.readOnly || this.locked;
      const impact = element('section', 'settings-limit-disable-review'); impact.hidden = true; impact.setAttribute('role', 'alert');
      impact.appendChild(element('strong', '', 'High impact: CoreChat will stop enforcing every configurable limit while preserving all saved values.'));
      const confirm = element('button', 'btn btn-danger', 'Confirm draft: Disable All Limits'); confirm.type = 'button';
      const cancel = element('button', 'btn', 'Cancel'); cancel.type = 'button';
      disable.addEventListener('click', () => { impact.hidden = false; confirm.focus(); });
      cancel.addEventListener('click', () => { impact.hidden = true; disable.focus(); });
      confirm.addEventListener('click', () => { this.setDraftValues(Object.fromEntries(companions.map(entry => [entry.id, false]))); impact.hidden = true; });
      impact.append(confirm, cancel); bulkActions.appendChild(disable); bulk.append(bulkActions, impact); section.appendChild(bulk);
      const groupOrder = this.limitSections();
      const groupGrid = element('div', 'settings-subsection-grid settings-limit-group-grid');
      for (const group of groupOrder) {
        const groupEntries = entries.filter(entry => String(entry.limitSection || 'Community Preferences') === group);
        if (!groupEntries.length) continue;
        const groupSection = element('section', 'settings-subsection settings-limit-group');
        groupSection.dataset.settingsSubsection = safeId(group);
        const groupHeading = element('div', 'settings-subsection-heading');
        groupHeading.appendChild(element('h3', '', group));
        groupSection.appendChild(groupHeading);
        const grid = element('div', 'settings-entry-grid');
        for (const entry of groupEntries) grid.appendChild(this.renderLimitEntry(entry));
        groupSection.appendChild(grid);
        groupGrid.appendChild(groupSection);
      }
      section.appendChild(groupGrid);
      target.appendChild(section);
      this.renderLimitEvents(target);
      this.syncLimitEnforcementControls();
    }

    render() {
      this.container.textContent = '';
      this.controls.clear();
      const categories = [...(this.registry?.categories || [])].sort((a, b) => Number(a.order) - Number(b.order));
      const entries = this.entries.filter(entry => !entry.limitEnforcementControl && this.matches(entry));
      const searchActive = Boolean(this.query || this.filter !== 'all');
      const shell = element('div', this.categoryNavigation ? 'settings-section-layout' : 'settings-section-content');
      let content = shell;

      if (this.categoryNavigation) {
        const navigation = element('nav', 'settings-section-navigation');
        navigation.setAttribute('aria-label', this.navigationLabel);
        const selectorLabel = element('label', 'settings-section-selector-label', this.navigationLabel);
        const selector = element('select', 'settings-section-selector');
        selector.setAttribute('aria-label', this.navigationLabel);
        const options = [
          ['overview', 'Overview'],
          ...categories.map(category => [category.id, category.label]),
          ['limits', 'Limit Settings'],
        ];
        for (const [value, label] of options) {
          const option = element('option', '', label);
          option.value = value;
          option.selected = value === this.selectedView;
          selector.appendChild(option);
        }
        selector.addEventListener('change', () => this.selectView(selector.value));
        selectorLabel.appendChild(selector);
        navigation.appendChild(selectorLabel);
        const list = element('div', 'settings-section-nav-list');
        list.appendChild(this.makeViewControl('overview', 'Overview', this.entries));
        for (const category of categories) {
          const categoryEntries = this.entries.filter(entry => entry.categoryId === category.id);
          if (categoryEntries.length) list.appendChild(this.makeViewControl(category.id, category.label, categoryEntries));
        }
        const limitEntries = this.limitEntries();
        list.appendChild(this.makeViewControl('limits', 'Limit Settings', limitEntries));
        navigation.appendChild(list);
        content = element('div', 'settings-section-content');
        shell.append(navigation, content);

        if (!searchActive && this.selectedView === 'overview') {
          this.overviewPanel = element('section', 'settings-overview');
          const title = element('h2', '', 'Settings Overview');
          title.tabIndex = -1;
          title.dataset.settingsSelectedHeading = 'true';
          this.overviewPanel.appendChild(title);
          this.renderInstalledFeatures(this.overviewPanel);
          const cards = element('div', 'settings-overview-grid');
          for (const category of categories) {
            const categoryEntries = this.entries.filter(entry => entry.categoryId === category.id);
            if (!categoryEntries.length) continue;
            const card = element('button', 'settings-overview-card');
            card.type = 'button';
            const optional = categoryEntries.filter(entry => entry.optional);
            const optionalEnabled = optional.filter(entry => this.draftEnabled(entry) === true).length;
            card.append(
              element('strong', '', category.label),
              element('span', '', `${categoryEntries.length} settings`),
              element('span', '', `${categoryEntries.filter(entry => this.isDirty(entry)).length} unsaved changes`),
              element('span', '', optional.length ? `${optionalEnabled} of ${optional.length} optional enabled` : 'No optional settings')
            );
            card.addEventListener('click', () => this.selectView(category.id));
            cards.appendChild(card);
          }
          this.overviewPanel.appendChild(cards);
          content.appendChild(this.overviewPanel);
        } else if (!searchActive && this.selectedView === 'limits') {
          this.renderLimits(content, this.limitEntries());
        } else {
          for (const category of categories) {
            if (!searchActive && category.id !== this.selectedView) continue;
            const categoryEntries = entries.filter(entry => entry.categoryId === category.id);
            if (categoryEntries.length) {
              if (category.id === 'voice-media-players') this.renderConnectionCapabilities(content);
              if (category.id === 'rooms-games') {
                if (this.registry?.surface === 'admin') window.CoreChatGameRecordings?.render(content, { locked: this.locked, readOnly: this.readOnly });
                this.renderFiveDiceMediaPack(content);
                this.renderGameMediaPacks(content);
              }
              this.renderCategory(content, category, categoryEntries, !searchActive);
            }
          }
        }
      } else {
        if (!searchActive) this.renderInstalledFeatures(content);
        for (const category of categories) {
          const categoryEntries = entries.filter(entry => entry.categoryId === category.id);
          if (categoryEntries.length) {
            if (category.id === 'voice-media-players') this.renderConnectionCapabilities(content);
            if (category.id === 'rooms-games') {
                if (this.registry?.surface === 'admin') window.CoreChatGameRecordings?.render(content, { locked: this.locked, readOnly: this.readOnly });
              this.renderFiveDiceMediaPack(content);
              this.renderGameMediaPacks(content);
            }
            this.renderCategory(content, category, categoryEntries);
          }
        }
      }

      this.container.appendChild(shell);
      this.applyDependencyStates();
      this.syncInheritedActions();
      this.refreshRoleColorPresentation();
      this.refreshValidationPresentation();
      this.updateSummaries();
      const any = this.container.querySelector('[data-setting-id], .settings-overview-card');
      if (!any) this.container.appendChild(element('p', 'settings-registry-empty', 'No settings match this search and filter.'));
      document.querySelectorAll('[data-edit-branding-reminder]').forEach(button => {
        button.disabled = this.readOnly || this.locked;
        this.applyControlLockSemantics(button);
        button.onclick = () => {
          if (this.readOnly || this.locked) return;
          const entry = this.entryMap.get('branding_license_reminder');
          if (entry && this.categoryNavigation && this.selectedView !== entry.categoryId) this.selectView(entry.categoryId);
          window.requestAnimationFrame(() => {
            const row = this.container.querySelector('[data-setting-id="branding_license_reminder"]');
            row?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
            row?.querySelector('.settings-reminder-actions .btn')?.click();
          });
        };
      });
    }

    applyControlLockSemantics(control) {
      if (!control) return;
      const locked = this.readOnly || this.locked;
      control.toggleAttribute('aria-disabled', locked);
      if (!this.lockDescriptionId) return;
      const descriptions = new Set(String(control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
      if (locked) descriptions.add(this.lockDescriptionId);
      else descriptions.delete(this.lockDescriptionId);
      if (descriptions.size) control.setAttribute('aria-describedby', [...descriptions].join(' '));
      else control.removeAttribute('aria-describedby');
    }

    setLocked(locked) {
      this.locked = Boolean(locked);
      for (const control of this.controls.values()) {
        control.disabled = this.readOnly || this.locked;
        this.applyControlLockSemantics(control);
      }
      for (const control of this.container.querySelectorAll('.settings-color-custom input')) {
        control.disabled = this.readOnly || this.locked;
        this.applyControlLockSemantics(control);
      }
      for (const control of this.container.querySelectorAll('[data-five-dice-pack-input]')) {
        control.disabled = this.readOnly || this.locked;
        this.applyControlLockSemantics(control);
      }
      for (const section of this.container.querySelectorAll('[data-game-recordings]')) section.dispatchEvent(new CustomEvent('recording-lock-change', { detail: { locked: this.locked, readOnly: this.readOnly } }));
      for (const button of this.container.querySelectorAll('button')) {
        if (button.closest('[data-game-recordings]')) continue;
        const presentationOnly = button.matches(
          '.settings-entry-info, .settings-section-nav-item, '
          + '.settings-overview-card, .settings-installed-feature-action'
        );
        button.disabled = presentationOnly ? false : (this.readOnly || this.locked);
        if (!presentationOnly) this.applyControlLockSemantics(button);
      }
      document.querySelectorAll('[data-edit-branding-reminder]').forEach(button => {
        button.disabled = this.readOnly || this.locked;
        this.applyControlLockSemantics(button);
      });
      this.container.classList.toggle('is-settings-locked', this.locked);
    }
  }

  window.SettingsRegistryUI = SettingsRegistryUI;
  window.SettingsUnlockController = SettingsUnlockController;
})();
