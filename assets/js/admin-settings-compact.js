(() => {
  'use strict';
  const Base = window.SettingsRegistryUI;
  if (!Base) return;
  const node = (tag, className = '', text = '') => {
    const result = document.createElement(tag);
    result.className = className;
    result.textContent = text;
    return result;
  };
  const info = (label, ...children) => {
    const details = node('details', 'admin-compact-info');
    const summary = node('summary', '', 'i');
    summary.setAttribute('aria-label', label);
    summary.title = label;
    details.append(summary, ...children);
    return details;
  };
  const normalize = value => String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '');

  class CompactSettings extends Base {
    render() {
      super.render();
      this.container.classList.add('settings-compact');
      this.compactRows();
    }

    renderSubsections(target, entries, categoryId = '') {
      // Relationships first; fixed room voice information last.
      const ordered = [...entries].sort((a, b) => {
        const rank = entry => entry.subsectionId === 'other-detected-branding' ? 1
          : ['setup-update-pages', 'maintenance-error-pages', 'about-legal-page'].includes(entry.subsectionId) ? 2
          : /avatar relationships/i.test(entry.subsectionLabel || '') ? -1
          : /ordinary room voice/i.test(entry.label || '') && entry.type === 'fixed' ? 1 : 0;
        return rank(a) - rank(b);
      });
      super.renderSubsections(target, ordered, categoryId);
      if (categoryId === 'avatars-presence' && this.categoryNavigation
          && this.selectedView === categoryId && !this.query && this.filter === 'all') {
        const webcam = this.entries.filter(entry => !entry.limitEnforcementControl && entry.categoryId === 'voice-media-players'
          && entry.subsectionId === 'webcam-display');
        if (webcam.length) super.renderSubsections(target, webcam, 'voice-media-players');
      }
      const sections = [...target.querySelectorAll('[data-settings-subsection]')];
      if (sections.length > 1) {
        const toc = node('nav', 'settings-subsection-toc');
        toc.setAttribute('aria-label', 'Jump to a section');
        toc.appendChild(node('strong', '', 'Jump to:'));
        for (const [index, section] of sections.entries()) {
          section.id = `${this.container.id || 'settings'}-${categoryId}-section-${index}`;
          const heading = section.querySelector('h3');
          const link = node('a', '', heading?.textContent || 'Section');
          link.href = `#${section.id}`;
          link.addEventListener('click', event => {
            event.preventDefault();
            section.scrollIntoView({ block: 'start' });
            if (heading) { heading.tabIndex = -1; heading.focus({ preventScroll: true }); }
          });
          toc.appendChild(link);
        }
        target.insertBefore(toc, target.querySelector('.settings-subsection-grid'));
      }
      for (const copy of target.querySelectorAll('.settings-flood-protection-info')) {
        const disclosure = info('Flood protection bulk controls');
        copy.replaceWith(disclosure);
        disclosure.appendChild(copy);
      }
    }

    compactRows() {
      for (const row of this.container.querySelectorAll('.settings-entry[data-setting-id]')) {
        const entry = this.entryMap.get(row.dataset.settingId);
        if (!entry) continue;
        if (entry.categoryId === 'voice-media-players' && entry.subsectionId === 'p2p-connections') {
          const notice = row.querySelector('[data-settings-dependency-badge]');
          const control = row.querySelector('.settings-entry-control');
          if (notice && control && !control.closest('.settings-dependency-field')) {
            const field = node('div', 'settings-dependency-field');
            control.before(field);
            notice.classList.add('settings-dependency-note');
            field.append(notice, control);
          }
        }
        const boolean = row.querySelector('.settings-boolean-control');
        if (boolean && !row.closest('.settings-paired-row')) {
          const group = row.querySelector('.settings-entry-label-group');
          const control = boolean.querySelector('input[type=checkbox]');
          const button = boolean.querySelector('.settings-entry-info');
          if (group && control && button) {
            const line = node('div', 'settings-entry-label-line');
            const label = node('label', 'settings-entry-label', entry.label);
            label.htmlFor = control.id;
            line.append(label, button); group.replaceChildren(line);
            boolean.querySelector('.settings-boolean-label > span')?.classList.add('sr-only');
            control.setAttribute('role', 'switch');
          }
        }
        const help = row.querySelector('.settings-entry-help-panel');
        if (help && ['community_logo_path', 'branding_powered_logo_path'].includes(entry.id)) {
          const path = String(entry.currentValue || entry.effectiveValue || '');
          if (path.startsWith('/assets/') && !path.includes('..') && !path.includes('\\')) {
            const preview = node('img', 'settings-branding-image-preview');
            const base = String(document.body?.dataset?.appBase || '').replace(/\/$/, '');
            preview.src = base + path;
            preview.alt = entry.label;
            preview.loading = 'lazy';
            help.appendChild(preview);
          }
        }
      }
      for (const grid of this.container.querySelectorAll('.settings-entry-grid')) {
        const rows = [...grid.children].filter(row => row.dataset.settingId);
        const consumed = new Set();
        for (const row of rows) {
          if (consumed.has(row)) continue;
          const entry = this.entryMap.get(row.dataset.settingId);
          const role = String(entry?.id || '').match(/^role_color_(.+)_bg$/);
          let partner;
          let label;
          if (role) {
            partner = rows.find(other => other.dataset.settingId === `role_color_${role[1]}_text`);
            label = String(entry.label).replace(/\s*Background color$/i, ' Color');
          } else if (entry?.categoryId === 'rooms-games' && entry.type === 'boolean') {
            const game = String(entry.label).replace(/^Enable\s+/i, '');
            partner = rows.find(other => {
              const definition = this.entryMap.get(other.dataset.settingId);
              return definition?.type === 'string'
                && normalize(definition.label) === normalize(`${game} display name`);
            });
            label = game;
          }
          if (!partner) continue;
          const pair = node('div', `settings-paired-row${role ? ' settings-role-pair' : ' settings-game-pair'}`);
          const heading = node('div', 'settings-paired-label');
          heading.appendChild(node('strong', 'settings-paired-name', label));
          if (role) {
            pair.dataset.colorRole = role[1];
            for (const [member, channel] of [[row, 'Background'], [partner, 'Text']]) {
              const control = member.querySelector('.settings-entry-control');
              if (control) control.prepend(node('span', 'settings-role-channel-label', channel));
            }
          }
          row.before(pair);
          pair.append(heading, row, partner);
          for (const member of [row, partner]) consumed.add(member);
          this.combinePairHelp(pair, heading, [row, partner], label);
        }
      }
      this.refreshRoleColorPresentation();
    }

    combinePairHelp(pair, heading, members, label) {
      const disclosure = info(`More information about ${label}: both settings`);
      disclosure.classList.add('settings-paired-help');
      const panel = node('section', 'settings-combined-help');
      panel.id = `combined-${members[0].dataset.settingId}-help`;
      panel.hidden = true; panel.tabIndex = -1;
      panel.setAttribute('role', 'region');
      panel.setAttribute('aria-label', `${label} settings information`);
      const summary = disclosure.querySelector('summary');
      summary.setAttribute('aria-controls', panel.id);
      for (const member of members) member.querySelector('.settings-entry-info')?.remove();
      disclosure.addEventListener('toggle', () => {
        panel.hidden = !disclosure.open;
        summary.setAttribute('aria-expanded', String(disclosure.open));
        if (!disclosure.open) return;
        panel.replaceChildren();
        for (const member of members) {
          const title = this.entryMap.get(member.dataset.settingId)?.label || member.dataset.settingId;
          panel.appendChild(node('h4', '', title));
          const help = member.querySelector('.settings-entry-help-panel');
          if (help) {
            const content = help.cloneNode(true);
            content.className = 'settings-combined-help-content';
            content.hidden = false; content.removeAttribute('id');
            for (const child of content.querySelectorAll('[id]')) child.removeAttribute('id');
            panel.appendChild(content);
          }
        }
      });
      panel.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); disclosure.open = false; summary.focus(); }
      });
      heading.appendChild(disclosure); pair.appendChild(panel);
    }
    refreshRoleColorPresentation() {
      super.refreshRoleColorPresentation();
      for (const pair of this.container.querySelectorAll('.settings-role-pair')) {
        const sample = pair.querySelector('.settings-color-preview-name');
        const label = pair.querySelector('.settings-paired-name');
        if (sample && label) {
          label.style.backgroundColor = sample.style.backgroundColor;
          label.style.color = sample.style.color;
        }
        for (const row of pair.querySelectorAll('.settings-entry')) {
          const help = row.querySelector('.settings-entry-help-panel');
          const ratio = row.querySelector('.settings-color-contrast');
          if (!help || !ratio) continue;
          let detail = help.querySelector('.settings-compact-contrast');
          if (!detail) { detail = node('p', 'settings-compact-contrast'); help.appendChild(detail); }
          detail.textContent = ratio.textContent;
        }
      }
    }

    renderEntry(entry) {
      return this.sharedLimitNodes?.get(entry.id) || super.renderEntry(entry);
    }

    renderLimits(target, entries) {
      super.renderLimits(target, entries);
      const shared = this.entries.filter(entry => !entry.limitEnforcementControl
        && entry.categoryId === 'moderation-privacy-security'
        && ['flood-protection', 'authentication'].includes(entry.subsectionId));
      const section = target.querySelector('.settings-limits-view');
      if (!section || !shared.length) return;
      const ids = new Set(shared.map(entry => entry.id));
      // Move already-rendered numeric limits; never duplicate controls or their IDs.
      this.sharedLimitNodes = new Map([...section.querySelectorAll('.settings-limit-entry[data-setting-id]')]
        .filter(card => ids.has(card.dataset.settingId))
        .map(card => [card.dataset.settingId, card]));
      // A protection exposed as a full shared setting must not also have a second switch.
      for (const enforcement of section.querySelectorAll('.settings-limit-enforcement[data-setting-id]')) {
        if (ids.has(enforcement.dataset.settingId)) enforcement.remove();
      }
      const host = node('div', 'settings-shared-security-controls');
      try {
        this.renderSubsections(host, shared, 'moderation-privacy-security');
      } finally {
        this.sharedLimitNodes = null;
      }
      for (const group of section.querySelectorAll('.settings-limit-group')) {
        if (!group.querySelector('[data-setting-id]')) group.remove();
      }
      section.insertBefore(host, section.querySelector('.settings-limit-group-grid'));
    }

    renderLimitEntry(entry) {
      const row = super.renderLimitEntry(entry);
      const title = row.querySelector('.settings-limit-title-row');
      const details = row.querySelector('.settings-limit-help');
      const summary = details.querySelector('summary');
      summary.textContent = 'i';
      summary.title = `More information about ${entry.label}`;
      summary.setAttribute('aria-label', summary.title);
      for (const selector of ['.settings-entry-range', '.settings-limit-recommendation', '.settings-limit-unenforced', '.settings-limit-risk', '.settings-limit-id']) {
        const field = row.querySelector(selector);
        if (field) details.appendChild(field);
      }
      const body = node('section', 'settings-limit-help-body');
      body.id = `limit-${entry.id}-help`; body.hidden = true; body.tabIndex = -1;
      body.setAttribute('role', 'region'); body.setAttribute('aria-label', summary.title);
      for (const child of [...details.children]) if (child !== summary) body.appendChild(child);
      summary.setAttribute('aria-controls', body.id);
      details.addEventListener('toggle', () => { body.hidden = !details.open; summary.setAttribute('aria-expanded', String(details.open)); });
      body.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); details.open = false; summary.focus(); } });
      const enforcement = row.querySelector('.settings-limit-enforcement input[type=checkbox]');
      if (enforcement) { enforcement.setAttribute('role', 'switch'); enforcement.setAttribute('aria-label', `Enforce ${entry.label}`); }
      row.querySelector('.settings-limit-enforcement .settings-boolean-label > span')?.classList.add('sr-only');
      const valueLabel = row.querySelector('.settings-limit-value-row > label');
      if (valueLabel) valueLabel.classList.add('sr-only');
      const value = row.querySelector('.settings-limit-value-row > input');
      if (value) value.setAttribute('aria-label', `${entry.label} saved value`);
      title.appendChild(details); row.appendChild(body);
      return row;
    }
    renderLimitEvents(target) {
      super.renderLimitEvents(target);
      const section = target.querySelector('.settings-limit-events');
      if (!section) return;
      section.insertBefore(node('p', 'minor', 'Operational warnings are not moderation warnings or penalties. Slow-request warnings describe server response time, not member misconduct. Details show available evidence, not message contents.'), section.querySelector('.settings-limit-event-filters'));
      section.addEventListener('click', async event => {
        const button = event.target.closest('[data-limit-event-details]');
        if (!button) return;
        button.disabled = true;
        try {
          const base = String(document.body?.dataset?.appBase || '').replace(/\/$/, '');
          const response = await fetch(`${base}/api/limit_events.php?action=details&public_id=${encodeURIComponent(button.dataset.limitEventDetails)}`, { credentials: 'same-origin' });
          const data = await response.json();
          if (!response.ok) throw new Error(data.error || 'Details are unavailable.');
          const dialog = node('dialog', 'admin-event-dialog');
          dialog.append(node('h3', '', data.limitName || 'Limit event'), node('p', '', data.explanation));
          const facts = node('dl');
          for (const [key, value] of Object.entries(data.latest || {})) facts.append(node('dt', '', key), node('dd', '', typeof value === 'object' ? JSON.stringify(value) : String(value)));
          dialog.appendChild(facts);
          dialog.appendChild(node('p', 'minor', `${data.occurrenceCount} total occurrences. Showing ${data.samples.length} retained recent samples; older occurrences may have aggregate information only.`));
          for (const sample of data.samples) {
            const item = node('article', 'admin-event-sample');
            item.append(node('strong', '', `${sample.user || 'System / account not recorded'} · ${sample.time}`), node('p', '', JSON.stringify(sample.measurements)));
            dialog.appendChild(item);
          }
          const close = node('button', 'btn', 'Close'); close.type = 'button';
          close.addEventListener('click', () => dialog.close());
          dialog.addEventListener('close', () => { dialog.remove(); button.focus(); }, { once: true });
          dialog.appendChild(close); document.body.appendChild(dialog); dialog.showModal();
        } catch (error) {
          const status = section.querySelector('[role="status"]');
          if (status) status.textContent = error.message;
        } finally { button.disabled = false; }
      });
    }

    renderFiveDiceMediaPack(target) { this.renderUnifiedMediaPacks(target); }
    renderGameMediaPacks() {}

    renderUnifiedMediaPacks(target) {
      const packs = [this.registry?.fiveDiceMediaPack, ...(this.registry?.gameMediaPacks || [])].filter(Boolean);
      if (!packs.length) return;
      const section = node('section', 'settings-installed-features settings-bulk-media');
      section.appendChild(node('h3', '', 'Classic artwork & sound'));
      section.appendChild(info('About Classic media installation', node('p', '', 'Choose or drop a folder containing one or more supported game OCXs, including nested folders. Recognized games install independently; missing games are untouched and a failed game does not cancel successful games. Files are statically extracted, never executed. Existing games, scores and records are preserved. Five Dice prefers the Yahtzee-mychange copy when both original copies are present. Ambiguous sources are skipped, not guessed. Verify and Remove remain available per game.')));
      const input = document.createElement('input'); input.type = 'file'; input.multiple = true; input.hidden = true;
      input.setAttribute('webkitdirectory', '');
      const choose = node('button', 'btn', 'Choose folder'); choose.type = 'button';
      const drop = node('div', 'settings-bulk-media-drop', 'Drop a game folder or the folder containing all games');
      drop.tabIndex = 0; drop.setAttribute('role', 'button');
      const install = node('button', 'btn btn-primary', 'Install selected games'); install.type = 'button'; install.disabled = true;
      const status = node('p', 'minor'); status.setAttribute('role', 'status');
      const rows = node('div', 'settings-bulk-media-rows');
      const labels = new Map();
      const resultLabel = pack => (pack.classicAvailable || pack.classicComplete) ? 'Classic installed' : (pack.installedCount ? 'Incomplete Classic media' : 'Classic not installed');
      for (const pack of packs) {
        const row = node('div', 'settings-bulk-media-row');
        const label = node('span', '', resultLabel(pack)); labels.set(pack, label);
        row.append(node('strong', '', pack.displayName || 'Five Dice'), label);
        const actions = info(`Manage ${pack.displayName || 'Five Dice'} Classic media`, node('p', '', pack.guidance || 'Validated Classic media is optional. Built-in presentation remains available.'));
        for (const [action, text] of [['verify', 'Verify'], ['remove', 'Remove Classic media']]) {
          const button = node('button', 'btn', text); button.type = 'button';
          button.disabled = !pack.canManage || this.readOnly || this.locked;
          button.addEventListener('click', async () => {
            if (this.locked || this.readOnly || !pack.canManage) return;
            if (action === 'remove' && !window.confirm(`Remove Classic media for ${pack.displayName || 'Five Dice'}? Built-in presentation will be used; game state and scores are unchanged.`)) return;
            button.disabled = true;
            try {
              const data = await this.onMediaPackAction(action, { game: pack.extensionId || '', confirmed: action === 'remove', deferRefresh: true }, this);
              Object.assign(pack, data.gameMediaPack || data.fiveDiceMediaPack || {});
              label.textContent = resultLabel(pack);
              status.textContent = `${pack.displayName || 'Five Dice'}: ${action === 'verify' ? 'verification complete' : 'Classic media removed'}.`;
            } catch (error) { status.textContent = error.message; }
            finally { button.disabled = this.locked || this.readOnly; }
          });
          actions.appendChild(button);
        }
        row.appendChild(actions); rows.appendChild(row);
      }
      const aliases = pack => [...new Set([normalize(pack.extensionId), normalize(pack.displayName), ...(!pack.extensionId ? ['yahtzee', 'fivedice', 'yahtzeemychange'] : []), ...(String(pack.extensionId).startsWith('backgammon') ? ['backgammon'] : [])])].filter(Boolean);
      let selection = []; let busy = false;
      const select = async files => {
        if (busy) return;
        selection = []; const notes = [];
        const list = [...files];
        const path = file => String(file.webkitRelativePath || file._classicRelativePath || file.name).replaceAll('\\', '/');
        for (const pack of packs) {
          if (!pack.canManage) continue;
          const names = aliases(pack);
          let sources = list.filter(file => /\.ocx$/i.test(file.name) && names.includes(normalize(file.name.replace(/\.ocx$/i, ''))));
          if (!pack.extensionId && sources.some(file => /yahtzee-mychange\//i.test(path(file)))) sources = sources.filter(file => /yahtzee-mychange\//i.test(path(file)));
          if (sources.length > 1) {
            const first = sources[0]; let identical = true;
            for (const candidate of sources.slice(1)) if (!(await this.mediaPackFilesAreIdentical(first, candidate))) identical = false;
            if (!identical) { notes.push(`${pack.displayName}: multiple different OCXs, skipped`); continue; }
            sources = [first];
          }
          const accepted = new Set(Object.keys(pack.acceptedFilenameSlots || {}).map(name => name.toLowerCase()));
          const sourceDirectory = sources[0] ? path(sources[0]).split('/').slice(0, -1).join('/') : '';
          const supplements = list.filter(file => {
            if (!accepted.has(file.name.toLowerCase())) return false;
            const parent = path(file).split('/').slice(0, -1);
            return (sourceDirectory && parent.join('/') === sourceDirectory) || parent.some(part => names.includes(normalize(part)));
          });
          const slots = new Map(); let conflict = false;
          for (const file of supplements) {
            const slot = pack.acceptedFilenameSlots[file.name.toLowerCase()] || file.name.toLowerCase();
            if (slots.has(slot)) { if (!(await this.mediaPackFilesAreIdentical(slots.get(slot), file))) conflict = true; }
            else slots.set(slot, file);
          }
          if (conflict) { notes.push(`${pack.displayName}: conflicting media copies, skipped`); continue; }
          if (sources.length || slots.size >= Number(pack.requiredCount || Infinity)) selection.push({ pack, files: [...slots.values(), ...sources] });
        }
        const recognized = new Set(selection.flatMap(item => item.files));
        const unknown = list.filter(file => /\.ocx$/i.test(file.name) && !recognized.has(file)).length;
        status.textContent = `${selection.length} game(s) ready: ${selection.map(item => item.pack.displayName || 'Five Dice').join(', ') || 'none'}. ${unknown} unselected/unsupported OCX file(s). ${notes.join('; ')}`;
        install.disabled = !selection.length || this.locked || this.readOnly;
      };
      choose.addEventListener('click', () => { if (!busy) input.click(); });
      drop.addEventListener('click', () => { if (!busy) input.click(); });
      drop.addEventListener('keydown', event => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); if (!busy) input.click(); } });
      input.addEventListener('change', () => select(input.files).catch(error => { status.textContent = error.message; }));
      for (const name of ['dragover', 'drop']) drop.addEventListener(name, event => event.preventDefault());
      drop.addEventListener('drop', async event => {
        if (busy) return;
        try {
          const files = [];
          const walk = async (entry, parent = '') => {
            if (!entry) return;
            const relative = `${parent}${entry.name}`;
            if (entry.isFile) {
              const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
              Object.defineProperty(file, '_classicRelativePath', { value: relative }); files.push(file); return;
            }
            if (entry.isDirectory) {
              const reader = entry.createReader();
              for (;;) {
                const children = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
                if (!children.length) break;
                for (const child of children) await walk(child, `${relative}/`);
              }
            }
          };
          for (const item of [...(event.dataTransfer?.items || [])]) await walk(item.webkitGetAsEntry?.());
          await select(files.length ? files : event.dataTransfer.files);
        } catch (error) { status.textContent = error.message; }
      });
      install.addEventListener('click', async () => {
        if (busy || this.locked || this.readOnly || !selection.length) return;
        busy = true; install.disabled = true; choose.disabled = true;
        const results = [];
        try {
          for (const item of selection) {
            if (this.locked) { results.push('Remaining games not installed: settings locked.'); break; }
            const name = item.pack.displayName || 'Five Dice';
            status.textContent = `Installing ${name}...`;
            try {
              const result = await this.onMediaPackAction(item.pack.installedCount ? 'replace' : 'install', { game: item.pack.extensionId || '', files: item.files, deferRefresh: true }, this);
              Object.assign(item.pack, result.gameMediaPack || result.fiveDiceMediaPack || {});
              labels.get(item.pack).textContent = resultLabel(item.pack);
              results.push(`${name}: installed`);
            } catch (error) { results.push(`${name}: ${error.message}`); }
          }
        } finally {
          busy = false; choose.disabled = false; install.disabled = this.locked || this.readOnly;
          status.textContent = results.join(' | ');
        }
      });
      section.append(input, choose, install, drop, status, rows); target.appendChild(section);
    }
  }
  window.SettingsRegistryUI = CompactSettings;

  // Reuse the actual unlock controls; this is presentation, not another unlock owner.
  const unlock = window.SettingsUnlockController?.prototype;
  if (unlock) {
    const render = unlock.render;
    unlock.render = function () {
      render.call(this);
      if (!this.mount) return;
      this.mount.classList.add('settings-unlock-compact');
      const help = info('About unlocking settings changes', this.warning, this.hint);
      this.mount.appendChild(help);
    };
  }
})();
