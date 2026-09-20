(() => {
  'use strict';
  if (window.CoreChatPopups) return;
  const records = new Map();
  const draftOwners = new Map();
  const managedField = field => [...draftOwners.keys()].some(container => container.contains(field));
  function registerDraftOwner(container, owner) {
    draftOwners.set(container, owner);
    for (const record of records.values()) {
      if (record.baseline) record.baseline = record.baseline.filter(field => !container.contains(field.el));
    }
  }
  const selector = '.modal, .game-start-menu, #media-picker, dialog';
  const draftIds = new Set(['admin-modal', 'room-edit-modal', 'lobby-room-edit-modal', 'room-effects-modal', 'aura-modal', 'avatar-size-modal', 'webcam-audience-modal', 'message-protection-dialog', 'report-problem-modal', 'p2p-transfer-compose-modal', 'host-warn-modal', 'host-kick-modal', 'community-eject-modal', 'game-mode-modal']);
  let lastOutsideFocus = null, titleSequence = 0;
  let order = 0, permittedButton = null, suppressOutsideClick = false;
  const visible = el => el.isConnected && !el.hidden && !!el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden';
  const focusables = box => [...box.querySelectorAll('button, input, select, textarea, a[href], [tabindex]')].filter(el => visible(el) && !el.disabled && el.tabIndex >= 0);
  const tracksDraft = record => draftIds.has(record.root.id) || record.root.matches('.avatar-library-dialog');
  function snapshot(record) {
    const fields = [...record.box.querySelectorAll('input,textarea,select')].filter(el =>
      !managedField(el) && !['password','file','search','submit','button'].includes(el.type) && !/search|filter/.test(el.id || '') && !el.closest('[data-popup-no-draft]'));
    return fields.map(el => ({el, value:el.value, checked:el.checked, selected:el.tagName === 'SELECT' ? [...el.options].map(o=>o.selected) : null}));
  }
  const extras = record => [...record.box.querySelectorAll('.aura-option.selected')].map(el=>el.dataset.auraKey).join('|');
  function markSaved(root, fields = null) {
    if (!root) return;
    if (!records.has(root)) enhance(root);
    const record = records.get(root);
    if (fields && record.baseline) { const current=snapshot(record); for(const field of fields){const updated=current.find(item=>item.el===field);const index=record.baseline.findIndex(item=>item.el===field);if(updated&&index>=0)record.baseline[index]=updated;}return; }
    record.baseline = snapshot(record); record.extraBaseline = extras(record); record.dirty = false;
  }
  function isDirty(record) {
    if (!tracksDraft(record) || !record.baseline) return false;
    if ([...draftOwners].some(([container, owner]) => record.box.contains(container) && owner.isDirty())) return true;
    const current = snapshot(record);
    const baseline = record.baseline.filter(field => !managedField(field.el));
    return current.length !== baseline.length || current.some((field,i)=> {
      const before=baseline[i];
      return field.el!==before.el || field.value!==before.value || field.checked!==before.checked || JSON.stringify(field.selected)!==JSON.stringify(before.selected);
    }) || extras(record)!==record.extraBaseline;
  }
  function discardChanges(record) {
    for (const field of record.baseline || []) {
      if (!field.el.isConnected || managedField(field.el)) continue;
      field.el.value=field.value;
      if (field.checked!==undefined) field.el.checked=field.checked;
      if (field.selected) [...field.el.options].forEach((o,i)=>o.selected=field.selected[i]);
    }
    for (const [container, owner] of draftOwners) if (record.box.contains(container)) owner.discard();
    record.root.dispatchEvent(new Event('corechat:popup-discard'));
  }
  function restoreFocus(record) {
    const other=[...records.values()].some(r=>r!==record && visible(r.root));
    if (other) return;
    const target=[record.returnFocus,lastOutsideFocus,document.getElementById('chat-input'),document.querySelector('main button,main a[href],button')]
      .find(el=>el && visible(el) && !el.disabled && !record.root.contains(el));
    target?.focus({preventScroll:true});
  }
  function buttonFor(root) {
    if (root.id === 'media-picker') return null;
    const special = {
      'host-notice-modal': 'host-notice-understand', 'lobby-ejection-modal': 'lobby-ejection-understand',
      'link-choice-modal': 'link-choice-cancel', 'voice-note-modal': 'voice-note-cancel',
      'game-start-menu': 'game-picker-close', 'room-games-menu': 'room-games-close',
      'message-protection-dialog': 'message-protection-cancel', 'message-protection-auth-dialog': 'message-protection-auth-close'
    };
    if (special[root.id]) return document.getElementById(special[root.id]);
    return root.querySelector('.window-close, [id$="-close"], [data-close], [id$="-cancel"]')
      || [...root.querySelectorAll('button')].find(el => /^(Cancel|Close)$/i.test(el.textContent.trim()));
  }
  function clamp(record, left, top) {
    const rect = record.box.getBoundingClientRect();
    Object.assign(record.box.style, {position: 'fixed', margin: '0', transform: 'none', right: 'auto', bottom: 'auto',
      left: `${Math.max(8, Math.min(left, innerWidth - rect.width - 8))}px`,
      top: `${Math.max(8, Math.min(top, innerHeight - rect.height - 8))}px`});
    record.moved = true;
  }
  function dragHeader(record, ownPointer = true) {
    const header = record.header;
    let drag = null;
    const stop = () => {
      if (!drag) return;
      const id = drag.id; drag = null;
      header.classList.remove('cc-popup-dragging');
      if (header.hasPointerCapture(id)) header.releasePointerCapture(id);
    };
    if (ownPointer) header.addEventListener('pointerdown', event => {
      if (event.button !== 0 || event.target.closest('button,input,select,textarea,a')) return;
      event.preventDefault(); event.stopImmediatePropagation();
      const r = record.box.getBoundingClientRect();
      drag = {id:event.pointerId, x:event.clientX-r.left, y:event.clientY-r.top};
      header.setPointerCapture(event.pointerId); header.classList.add('cc-popup-dragging');
    }, true);
    header.addEventListener('pointermove', event => {
      if (!drag || event.pointerId !== drag.id) return;
      event.preventDefault(); event.stopImmediatePropagation();
      clamp(record,event.clientX-drag.x,event.clientY-drag.y);
    }, true);
    for (const type of ['pointerup','pointercancel','lostpointercapture']) header.addEventListener(type,stop);
    record.stopDrag=stop;
    header.tabIndex = 0;
    header.setAttribute('aria-label', 'Move window: drag the title bar or use arrow keys');
    header.addEventListener('keydown', event => {
      if (event.target !== header) return;
      const delta = {ArrowLeft:[-10,0],ArrowRight:[10,0],ArrowUp:[0,-10],ArrowDown:[0,10]}[event.key];
      if (!delta) return;
      event.preventDefault(); event.stopImmediatePropagation();
      const r=record.box.getBoundingClientRect();clamp(record,r.left+delta[0],r.top+delta[1]);
    }, true);
  }
  function enhance(root) {
    if (records.has(root)) return;
    const native = root.tagName === 'DIALOG';
    const box = native ? root : root.querySelector(':scope > .modal-box') || root;
    let header = box.querySelector(':scope > .modal-head, :scope > .game-picker-head,  :scope > .avatar-library-titlebar, :scope > .cc-popup-header');
    if (!header) {
      header=document.createElement('div');
      const title=box.querySelector(':scope > h2, :scope > h3, :scope > form > h2');
      if (title) header.append(title);
      else {const strong=document.createElement('strong');strong.textContent=root.id==='media-picker'?'Media':root.getAttribute('aria-label')||'Options';header.append(strong);}
      box.prepend(header);
    }
    header.classList.add('cc-popup-header');box.classList.add('cc-popup-box');
    const heading=header.querySelector('h2,h3,strong');
    if (heading && !root.hasAttribute('aria-labelledby')) {
      if (!heading.id) heading.id=`cc-popup-title-${++titleSequence}`;
      root.setAttribute('aria-labelledby',heading.id);
    }
    if (root.matches('.modal') && !box.matches('[role=dialog],[role=alertdialog]')) {root.setAttribute('role','dialog');if(!root.hasAttribute('aria-modal'))root.setAttribute('aria-modal','true');}

    const record={root,box,header,native,button:buttonFor(root),active:false,dirty:false,order:0,moved:false,closing:false,returnFocus:null};
    records.set(root,record);
    let close=header.querySelector('.window-close,[id$="-close"],[data-close],.cc-popup-close') || (record.button && header.contains(record.button) ? record.button : null);
    if (!close) {
      close=document.createElement('button');close.type='button';close.className='cc-popup-close';
      close.textContent='×';close.setAttribute('aria-label','Close');header.append(close);
      close.addEventListener('click',()=>requestClose(record));
    }
    if (/^Close$/i.test(close.textContent.trim())) {
      close.textContent='×';close.setAttribute('aria-label','Close');close.classList.add('cc-popup-close');
    }
    record.close=close;
    // Existing owners keep their close/cancel semantics. Capture before they run.
    box.addEventListener('click',event=>{
      const target=event.target.closest('button');
      if (!target || target===permittedButton) return;
      const sameWindowCancel=record.button?.id && /-(?:close|cancel)$/.test(target.id)
        && target.id.replace(/-(?:close|cancel)$/, '')===record.button.id.replace(/-(?:close|cancel)$/, '');
      const ownerClose=target===record.button || target===close || sameWindowCancel;
      if (!ownerClose) return;
      event.preventDefault();event.stopImmediatePropagation();requestClose(record,target===close?record.button:target);
    },true);
    if (native) {
      root.addEventListener('cancel',event=>{event.preventDefault();requestClose(record);});
      // Portal dialogs must not trigger the room's background outside-click handlers.
      box.addEventListener('click',event=>event.stopPropagation());
    }
    box.addEventListener('input',event=>{
      if (!draftIds.has(root.id)) return;
      const input=event.target;
      if (input.type==='search' || /search|filter/.test(input.id||'') || input.closest('[data-popup-no-draft]')) return;
      record.dirty=true;
    });
    box.addEventListener('change',event=>{
      if (draftIds.has(root.id) && !/search|filter/.test(event.target.id||'')) record.dirty=true;
    });
    box.addEventListener('reset',()=>{queueMicrotask(()=>markSaved(root));});
    // Game pickers and music already own pointer dragging; do not double-bind it.
    dragHeader(record, !root.matches('.game-start-menu,#vp-music-modal,.avatar-library-dialog'));
    sync(record);
  }
  function sync(record) {
    const active=visible(record.root);
    if (active && !record.active) {
      record.reopen?.remove();record.reopen=null;
      record.order=++order;record.dirty=false;record.returnFocus=record.root.contains(document.activeElement)?lastOutsideFocus:document.activeElement;
      markSaved(record.root);
      if(record.native)record.box.style.setProperty('--cc-popup-inset',getComputedStyle(record.box).paddingTop);
      if (record.moved) {const r=record.box.getBoundingClientRect();clamp(record,r.left,r.top);}
    }
    if (!active && record.active) {record.dirty=false;record.closing=false;queueMicrotask(()=>restoreFocus(record));}
    record.active=active;
  }
  // Independently managed dialogs keep their own event ownership.
  function independentDialog(target) {
    const dialog=target?.closest?.('[role="dialog"], [role="alertdialog"]');
    return dialog && !records.has(dialog) && !dialog.closest(selector);
  }
  function inside(record, event) {
    if (record.root.id==='media-picker' && event.target.closest('#gesture-action-menu, #custom-emoji-action-menu')) return true;
    if (!record.box.contains(event.target)) return false;
    if (!record.native || event.target !== record.box) return true;
    const r=record.box.getBoundingClientRect();
    return event.clientX>=r.left && event.clientX<=r.right && event.clientY>=r.top && event.clientY<=r.bottom;
  }
  function top() {
    for(const record of records.values()) sync(record);
    return [...records.values()].filter(r=>r.active).sort((a,b)=>
      // Native modal dialogs occupy the browser's top layer, above any CSS z-index.
      Number(b.root.matches('dialog:modal'))-Number(a.root.matches('dialog:modal')) || Number(b.native)-Number(a.native) || (Number(getComputedStyle(b.root).zIndex)||0)-(Number(getComputedStyle(a.root).zIndex)||0) || b.order-a.order)[0];
  }
  async function requestClose(record, ownerButton=record.button) {
    if (!record.active || record.closing) return;
    // Never dismiss a pending password/submission behind a disabled Cancel control.
    if (record.root.dataset.popupBusy==='true' || ownerButton?.disabled || record.box.getAttribute('aria-busy')==='true' || [...record.box.querySelectorAll('form[aria-busy="true"], [data-popup-busy="true"]')].some(visible)) return;
    record.closing=true;
    try {
      if (record.root.id==='voice-note-modal') {
        if (!await ask('Discard this voice recording? It will not be sent.',{title:'Cancel recording',accept:'Discard recording'})) return;
      } else if (isDirty(record)) {
        if (!await ask('This window has changes that may not have been saved. Keep editing, or close and discard those changes?',{title:'Unsaved changes',accept:'Discard and close',cancel:'Keep editing'})) return;
        record.root.dataset.popupDiscardApproved='1';
        discardChanges(record);
      }
      if (!visible(record.root)) return;
      if (record.root.id==='p2p-transfer-offer-modal') {
        // Dismiss only the view. Accept/Decline remain explicit owner actions.
        record.root.classList.remove('open');record.root.setAttribute('aria-hidden','true');
        if (!record.reopen) {
          const reopen=document.createElement('button');reopen.type='button';reopen.className='cc-popup-return';reopen.textContent='Incoming transfer';
          reopen.addEventListener('click',()=>{record.root.classList.add('open');record.root.setAttribute('aria-hidden','false');reopen.remove();record.reopen=null;});
          document.body.append(reopen);record.reopen=reopen;
        }
      } else if (ownerButton) {
        // Leave the original click stack before replaying an owner's Cancel.
        // HTMLElement.click() suppresses a recursive click on the same button.
        await Promise.resolve();
        if (!visible(record.root) || ownerButton.disabled) return;
        permittedButton=ownerButton;
        try {ownerButton.click();} finally {permittedButton=null;}
      } else if (record.native) record.root.close();
      else if (record.root.id==='media-picker') {record.root.dispatchEvent(new Event('corechat:popup-dismiss'));record.root.hidden=true;document.getElementById('emoji-btn')?.focus();}
      sync(record);
    } finally {record.closing=false;}
  }
  // Shared application prompts use text nodes only; no server/user HTML insertion.
  function ask(message,{title='Confirm action',value,accept='Continue',cancel='Cancel'}={}) {
    return new Promise(resolve=>{
      const previous=document.activeElement,dialog=document.createElement('dialog');dialog.className='cc-prompt';dialog.setAttribute('aria-label',title);
      const form=document.createElement('form'),heading=document.createElement('h2'),body=document.createElement('p'),actions=document.createElement('div');
      heading.textContent=title;body.textContent=message;actions.className='cc-prompt-actions';form.append(heading,body);
      let input;
      if (value!==undefined) {input=document.createElement('input');input.type='text';input.value=value;input.setAttribute('aria-label',title);form.append(input);}
      const no=document.createElement('button'),yes=document.createElement('button');no.type='button';no.textContent=cancel;no.dataset.close='';yes.type='submit';yes.textContent=accept;
      actions.append(no,yes);form.append(actions);dialog.append(form);document.body.append(dialog);
      let result=value!==undefined?null:false,finished=false;
      const finish=()=>{if(finished)return;finished=true;dialog.remove();records.delete(dialog);if(previous?.isConnected)previous.focus();resolve(result);};
      no.addEventListener('click',()=>dialog.close());form.addEventListener('submit',event=>{event.preventDefault();result=input?input.value:true;dialog.close();});dialog.addEventListener('close',finish,{once:true});
      enhance(dialog);dialog.showModal();sync(records.get(dialog));(input||no).focus();input?.select();
    });
  }
  window.CoreChatPopups=Object.freeze({markSaved, registerDraftOwner, isDirty:root=>records.has(root)&&isDirty(records.get(root)), confirm:(message,options)=>ask(message,options),prompt:(message,value='')=>ask(message,{title:'Enter details',value,accept:'Save'}),
    reflow(root){const record=records.get(root);if(record?.active&&record.moved){const r=record.box.getBoundingClientRect();clamp(record,r.left,r.top);}},
    clearDismissed(root){const record=records.get(root);record?.reopen?.remove();if(record)record.reopen=null;},
    consumeDiscardApproval(root){const approved=root?.dataset.popupDiscardApproved==='1';if(root)delete root.dataset.popupDiscardApproved;return approved;}});
  document.querySelectorAll(selector).forEach(enhance);
  new MutationObserver(changes=>{
    for(const change of changes){
      if(change.type==='childList')for(const node of change.addedNodes)if(node.nodeType===1){if(node.matches(selector))enhance(node);node.querySelectorAll(selector).forEach(enhance);}
      if(change.type==='attributes' && records.has(change.target))sync(records.get(change.target));
    }
    for(const [root,record]of records)if(!root.isConnected){record.reopen?.remove();records.delete(root);}
  }).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class','hidden','open']});
  document.addEventListener('pointerdown',event=>{
    if (![...records.values()].some(r=>visible(r.root)&&r.root.contains(event.target))) lastOutsideFocus=event.target.closest('button,a[href],input,[tabindex]') || document.activeElement;
    suppressOutsideClick=false;
    const record=top();if(!record || inside(record,event) || (independentDialog(event.target) || event.target.closest('.cc-popup-return')))return;
    suppressOutsideClick=true;event.preventDefault();event.stopImmediatePropagation();requestClose(record);
  },true);
  // Suppress backdrop click handlers too, including when the guard stays open.
  document.addEventListener('click',event=>{if(permittedButton && permittedButton.contains(event.target))return;if(suppressOutsideClick){suppressOutsideClick=false;event.preventDefault();event.stopImmediatePropagation();return;}const record=top();if(record && !inside(record,event) && !(independentDialog(event.target) || event.target.closest('.cc-popup-return'))){event.preventDefault();event.stopImmediatePropagation();}},true);
  document.addEventListener('focusin',event=>{if(![...records.values()].some(r=>visible(r.root)&&r.root.contains(event.target)))lastOutsideFocus=event.target;});
  document.addEventListener('keydown',event=>{
    if(independentDialog(document.activeElement))return;
    const record=top();if(!record)return;
    if(event.key==='Escape' && record.root.id==='media-picker'
      && [...document.querySelectorAll('#gesture-action-menu,#custom-emoji-action-menu')].some(visible))return;
    if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();requestClose(record);return;}
    if(event.key==='Tab' && (record.native || record.root.matches('.modal'))){const list=focusables(record.box);if(!list.length)return;const first=list[0],last=list.at(-1);if(event.shiftKey && (document.activeElement===first||!record.box.contains(document.activeElement))){event.preventDefault();last.focus();}else if(!event.shiftKey && (document.activeElement===last||!record.box.contains(document.activeElement))){event.preventDefault();first.focus();}}
  },true);
  window.addEventListener('blur',()=>{for(const record of records.values())record.stopDrag?.();});
  window.addEventListener('resize',()=>{for(const record of records.values())if(record.active&&record.moved){const r=record.box.getBoundingClientRect();clamp(record,r.left,r.top);}});
})();
