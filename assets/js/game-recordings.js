(function () {
  'use strict';
  const node = (tag, text, className = '') => {
    const el = document.createElement(tag);
    if (text) el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  window.CoreChatGameRecordings = {
    render(target, { locked = true, readOnly = false } = {}) {
      const section = node('section', '', 'settings-installed-features game-recordings');
      section.dataset.gameRecordings = 'true';
      section.style.cssText = 'min-width:0;overflow-wrap:anywhere';
      section.append(node('h3', 'Recorded Games'), node('p', 'Review moves and scores from supported games. Closed games can be exported for later analysis.', 'minor'));
      const refresh = node('button', 'Refresh recordings', 'btn'); refresh.type = 'button';
      const status = node('p', 'Select Refresh recordings to load the archive.', 'minor'); status.setAttribute('role', 'status');
      const archive = node('details');
      const archiveTitle = node('summary', 'Show recorded games');
      const list = node('div'); archive.append(archiveTitle, list);
      const pager = node('div', '', 'settings-five-dice-media-actions');
      const previous = node('button', 'Previous', 'btn'); previous.type = 'button';
      const next = node('button', 'Next', 'btn'); next.type = 'button';
      pager.append(previous, next); pager.hidden = true;
      section.append(refresh, status, archive, pager);
      target.append(section);
      const script = Array.from(document.scripts).find(item => /\/game-recordings\.js(?:\?|$)/.test(item.src));
      section.addEventListener('recording-lock-change', event => {
        locked = event.detail.locked; readOnly = event.detail.readOnly;
        section.querySelectorAll('[data-recording-action]').forEach(button => { button.disabled = locked || readOnly || button.dataset.recordingEligible !== 'true'; });
        lockNote.hidden = !(locked || readOnly);
      });
      const endpoint = new URL('../../api/admin_game_recordings.php', script?.src || document.baseURI);
      let offset = 0;
      const readResponse = async response => {
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'The recording action failed.');
        return data;
      };
      const load = async () => {
        refresh.disabled = true; archive.open = true;
        status.textContent = 'Loading recordings…';
        try {
          const url = new URL(endpoint); url.searchParams.set('offset', String(offset));
          const data = await readResponse(await fetch(url, { credentials: 'same-origin', cache: 'no-store' }));
          list.replaceChildren();
          status.textContent = `${(data.storageBytes / 1048576).toFixed(1)} MB of ${(data.storageLimitBytes / 1048576).toFixed(0)} MB used · ${(data.queuedBytes / 1024).toFixed(0)} KB waiting to be saved`;
          if (Number(data.health?.capture_failures)) list.append(node('p', `${data.health.capture_failures} recording failures have occurred. Affected games may have missing moves.`, 'settings-entry-error'));
          if (!data.recordings.length) list.append(node('p', 'No recorded games on this page. New supported games are recorded automatically when enabled.'));
          for (const record of data.recordings) {
            const card = node('article', '', 'settings-entry');
            const game = record.game === 'uno' ? 'UNO' : record.game.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            card.append(node('h4', `${game} · ${record.created_at} UTC`));
            card.append(node('p', (record.players || []).map(player => player.name).join(' · '), 'minor'));
            const description = record.complete ? 'Complete recording' : (record.closed ? 'Partial recording' : 'Game still open');
            card.append(node('p', `${description} · ${record.event_count} ${Number(record.event_count) === 1 ? 'event' : 'events'} · ${record.part_number} ${Number(record.part_number) === 1 ? 'file part' : 'file parts'}`));
            if (Number(record.gap_count)) card.append(node('p', `${record.gap_count} recording ${Number(record.gap_count) === 1 ? 'gap' : 'gaps'}. This archive cannot reconstruct every move.`, 'settings-entry-error'));
            if (record.error_code) card.append(node('p', `Recording needs attention: ${record.error_code.replace(/-/g, ' ')}.`, 'settings-entry-error'));
            const actions = node('div', '', 'settings-five-dice-media-actions');
            actions.style.cssText = 'display:flex;flex-wrap:wrap;gap:.5rem';
            const exportButton = node('button', 'Export game', 'btn'); exportButton.type = 'button';
            exportButton.dataset.recordingAction = 'true'; exportButton.dataset.recordingEligible = String(Boolean(record.exportable));
            exportButton.disabled = locked || readOnly || !record.exportable;
            exportButton.addEventListener('click', () => {
              const url = new URL(endpoint); url.searchParams.set('action', 'export'); url.searchParams.set('id', record.id);
              const link = node('a'); link.href = url.href; link.download = ''; link.click();
            });
            const remove = node('button', 'Delete recording', 'btn btn-danger'); remove.type = 'button'; remove.dataset.recordingAction = 'true'; remove.dataset.recordingEligible = String(Boolean(record.closed)); remove.disabled = locked || readOnly || !record.closed;
            const confirm = node('div', '', 'settings-entry-error'); confirm.hidden = true;
            const actionStatus = node('p', '', 'settings-entry-error'); actionStatus.setAttribute('role', 'alert'); actionStatus.hidden = true;
            confirm.append(node('p', 'Permanently delete this game recording and every file part? Export it first if you want to keep it.'));
            const yes = node('button', 'Delete permanently', 'btn btn-danger'); yes.type = 'button'; yes.dataset.recordingAction = 'true'; yes.dataset.recordingEligible = String(Boolean(record.closed));
            const cancel = node('button', 'Cancel', 'btn'); cancel.type = 'button';
            confirm.append(yes, cancel);
            remove.addEventListener('click', () => { confirm.hidden = false; yes.focus(); });
            cancel.addEventListener('click', () => { confirm.hidden = true; remove.focus(); });
            yes.addEventListener('click', async () => {
              yes.disabled = true;
              actionStatus.hidden = true;
              try {
                const csrf = document.body?.dataset.csrf || '';
                await readResponse(await fetch(endpoint, { method: 'POST', credentials: 'same-origin',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                  body: JSON.stringify({ action: 'delete', id: record.id, confirmed: true }) }));
                await load();
              } catch (error) { status.textContent = error.message; actionStatus.textContent = error.message; actionStatus.hidden = false; yes.disabled = locked || readOnly; }
            });
            actions.append(exportButton, remove); card.append(actions, confirm, actionStatus); list.append(card);
          }
          pager.hidden = offset === 0 && !data.hasMore;
          previous.disabled = offset === 0; next.disabled = !data.hasMore;
        } catch (error) { status.textContent = error.message || 'Recording storage is unavailable. Games can continue.'; }
        finally { refresh.disabled = false; }
      };
      refresh.addEventListener('click', load);
      previous.addEventListener('click', () => { offset = Math.max(0, offset - 100); load(); });
      next.addEventListener('click', () => { offset += 100; load(); });
      const lockNote = node('p', 'Unlock Admin settings to export or delete closed recordings.', 'minor');
      lockNote.hidden = !(locked || readOnly); section.append(lockNote);
    }
  };
}());
