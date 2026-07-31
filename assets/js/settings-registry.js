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
      window.addEventListener('pagehide', () => this.relock('', ''));
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
        this.unlockButton?.focus();
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
      const roleMatch = String(entry.id || '').match(/^role_color_(admin|developer|guide|owner|user)_(bg|text)$/);
      const role = roleMatch?.[1] || '';
      const part = roleMatch?.[2] || '';
      const roleLabel = {
        admin: 'Administrator',
        developer: 'Developer',
        guide: 'Guide',
        owner: 'Room Owner',
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
      for (const role of ['admin', 'developer', 'guide', 'owner', 'user']) {
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
      this.syncInheritedActions();
      this.refreshRoleColorPresentation();
      this.refreshValidationPresentation();
      this.applySearchAndFilter();
      this.onDraftChange(this.getState());
      this.syncSurfacePresentation();
      this.onEntryChange(entry, this.draft.get(entry.id), this.getState());
    }

    isDirty(entry) {
      if (entry.type === 'asset' || entry.type === 'fixed' || entry.type === 'profile-review') return false;
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
    }

    installedFeatures() {
      return Array.isArray(this.registry?.installedFeatures)
        ? this.registry.installedFeatures
        : [];
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
      if (['asset', 'color', 'editable-reminder', 'fixed', 'profile-review'].includes(entry.type)) return true;
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
      if (entry.type !== 'fixed' && entry.type !== 'profile-review') label.htmlFor = controlId;
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
        'Diagnostics',
        'Other Limits',
      ];
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
      target.appendChild(categorySection);
    }

    renderLimits(target, entries) {
      const section = element('section', 'settings-category settings-limits-view');
      section.dataset.settingsCategory = 'limits';
      section.dataset.settingsView = 'limits';
      const heading = element('div', 'settings-category-summary');
      const title = element('h2', 'settings-category-title', 'Limits Settings');
      title.tabIndex = -1;
      title.dataset.settingsSelectedHeading = 'true';
      const counts = element('span', 'settings-category-counts', `${entries.length} configurable limits`);
      heading.append(title, counts);
      section.appendChild(heading);
      const groupOrder = this.limitGroups();
      const groupGrid = element('div', 'settings-subsection-grid settings-limit-group-grid');
      for (const group of groupOrder) {
        const groupEntries = entries.filter(entry => this.limitGroupFor(entry) === group);
        if (!groupEntries.length) continue;
        const groupSection = element('section', 'settings-subsection settings-limit-group');
        groupSection.dataset.settingsSubsection = safeId(group);
        const groupHeading = element('div', 'settings-subsection-heading');
        groupHeading.appendChild(element('h3', '', group));
        const reset = element('button', 'btn', 'Reset Group');
        reset.type = 'button';
        reset.disabled = this.readOnly || this.locked;
        reset.addEventListener('click', () => this.resetDraft(groupEntries.map(entry => entry.id)));
        groupHeading.appendChild(reset);
        groupSection.appendChild(groupHeading);
        const grid = element('div', 'settings-entry-grid');
        for (const entry of groupEntries) grid.appendChild(this.renderEntry(entry));
        groupSection.appendChild(grid);
        groupGrid.appendChild(groupSection);
      }
      section.appendChild(groupGrid);
      target.appendChild(section);
    }

    render() {
      this.container.textContent = '';
      this.controls.clear();
      const categories = [...(this.registry?.categories || [])].sort((a, b) => Number(a.order) - Number(b.order));
      const entries = this.entries.filter(entry => this.matches(entry));
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
          ['limits', 'Limits Settings'],
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
        list.appendChild(this.makeViewControl('limits', 'Limits Settings', limitEntries));
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
            if (categoryEntries.length) this.renderCategory(content, category, categoryEntries, !searchActive);
          }
        }
      } else {
        if (!searchActive) this.renderInstalledFeatures(content);
        for (const category of categories) {
          const categoryEntries = entries.filter(entry => entry.categoryId === category.id);
          if (categoryEntries.length) this.renderCategory(content, category, categoryEntries);
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
      for (const button of this.container.querySelectorAll('button')) {
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
