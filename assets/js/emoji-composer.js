// A text-protocol composer with atomic, image-backed custom emoji tokens.
// Sending, encryption, drafts and limits continue to use the original string.
const TOKEN = /\[emoji:([a-f0-9]{32})(?::([a-z0-9_-]{1,32}))?\]/g;
const EXACT_TOKEN = /^\[emoji:[a-f0-9]{32}(?::[a-z0-9_-]{1,32})?\]$/;

export function enhanceEmojiComposer(textarea, { mediaUrl }) {
  if (!textarea || textarea.tagName !== 'TEXTAREA') return textarea;
  const doc = textarea.ownerDocument;
  const editor = doc.createElement('div');
  editor.id = textarea.id;
  editor.className = `${textarea.className} emoji-composer`.trim();
  editor.style.cssText = textarea.style.cssText;
  editor.setAttribute('role', 'textbox');
  editor.setAttribute('aria-multiline', 'true');
  editor.setAttribute('aria-label', textarea.getAttribute('aria-label') || 'Message');
  editor.spellcheck = textarea.spellcheck;
  editor.tabIndex = 0;
  let value = textarea.value;
  let start = textarea.selectionStart;
  let end = textarea.selectionEnd;
  let direction = 'none';
  let limit = textarea.maxLength;
  let disabled = textarea.disabled;
  let readOnly = textarea.readOnly;
  let placeholder = textarea.placeholder;
  let composing = false;
  let beforeEdit = null;
  const undo = [];
  const redo = [];
  const focus = editor.focus.bind(editor);
  const segmenter = typeof Intl.Segmenter === 'function'
    ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null;

  function serialize(node) {
    if (node.nodeType === 3) return node.nodeValue || '';
    const token = node.nodeType === 1 ? node.getAttribute('data-emoji-token') : null;
    if (token && EXACT_TOKEN.test(token)) return token;
    if (node.nodeName === 'BR') return '\n';
    let result = '';
    for (const child of node.childNodes) {
      if (/^(DIV|P|LI)$/.test(child.nodeName) && result && !result.endsWith('\n')) result += '\n';
      result += serialize(child);
    }
    return result;
  }

  function offsetAt(node, offset) {
    const range = doc.createRange();
    range.selectNodeContents(editor);
    range.setEnd(node, offset);
    return serialize(range.cloneContents()).length;
  }

  function rememberSelection() {
    const selection = doc.getSelection();
    if (!selection?.rangeCount || !editor.contains(selection.anchorNode) || !editor.contains(selection.focusNode)) return;
    const anchor = offsetAt(selection.anchorNode, selection.anchorOffset);
    const target = offsetAt(selection.focusNode, selection.focusOffset);
    start = Math.min(anchor, target);
    end = Math.max(anchor, target);
    direction = anchor > target ? 'backward' : 'forward';
  }

  function pointAt(offset, trailing = false) {
    let position = 0;
    const nodes = Array.from(editor.childNodes);
    for (let index = 0; index < nodes.length; index += 1) {
      const node = nodes[index];
      const length = serialize(node).length;
      if (offset <= position + length) {
        if (node.nodeType === 3) return [node, Math.max(0, offset - position)];
        return [editor, index + (offset > position && (trailing || offset === position + length) ? 1 : 0)];
      }
      position += length;
    }
    return [editor, nodes.length];
  }

  function restoreSelection() {
    if (doc.activeElement !== editor || composing) return;
    const selection = doc.getSelection();
    const first = pointAt(start);
    const last = start === end ? first : pointAt(end, true);
    if (selection?.setBaseAndExtent) {
      const anchor = direction === 'backward' ? last : first;
      const target = direction === 'backward' ? first : last;
      selection.setBaseAndExtent(...anchor, ...target);
    } else if (selection) {
      const range = doc.createRange();
      range.setStart(...first);
      range.setEnd(...last);
      selection.removeAllRanges();
      selection.addRange(range);
    }
  }

  function render() {
    const fragment = doc.createDocumentFragment();
    let offset = 0;
    for (const match of value.matchAll(TOKEN)) {
      if (match.index > offset) fragment.appendChild(doc.createTextNode(value.slice(offset, match.index)));
      const chip = doc.createElement('span');
      chip.className = 'composer-custom-emoji';
      chip.contentEditable = 'false';
      chip.setAttribute('data-emoji-token', match[0]);
      const image = doc.createElement('img');
      image.src = mediaUrl(`/api/custom_emojis.php?action=image&id=${match[1]}`);
      image.alt = `:${match[2] || 'custom-emoji'}:`;
      image.title = image.alt;
      image.width = 24;
      image.height = 24;
      image.draggable = false;
      chip.appendChild(image);
      fragment.appendChild(chip);
      offset = match.index + match[0].length;
    }
    if (offset < value.length) fragment.appendChild(doc.createTextNode(value.slice(offset)));
    // A terminal text node provides a caret position after an emoji/newline.
    if (!fragment.lastChild || fragment.lastChild.nodeType !== 3) fragment.appendChild(doc.createTextNode(''));
    editor.replaceChildren(fragment);
    editor.dataset.empty = value ? 'false' : 'true';
    restoreSelection();
  }

  function snapshot() { return { value, start, end, direction }; }
  function record(previous) {
    if (previous.value === value) return;
    undo.push(previous);
    if (undo.length > 100) undo.shift();
    redo.length = 0;
  }
  function notify() { editor.dispatchEvent(new Event('input', { bubbles: true })); }
  function truncate(text, maximum) {
    if (maximum < 0 || text.length <= maximum) return text;
    let cut = maximum;
    for (const match of text.matchAll(TOKEN)) {
      if (match.index < cut && match.index + match[0].length > cut) cut = match.index;
    }
    if (cut > 0 && /[\uD800-\uDBFF]/.test(text[cut - 1])) cut -= 1;
    return text.slice(0, cut);
  }
  function selectRange(nextStart, nextEnd, nextDirection = 'none') {
    start = Math.max(0, Math.min(value.length, Number(nextStart) || 0));
    end = Math.max(start, Math.min(value.length, Number(nextEnd) || 0));
    for (const match of value.matchAll(TOKEN)) {
      const left = match.index;
      const right = left + match[0].length;
      if (start === end && start > left && start < right) {
        start = end = start - left < right - start ? left : right;
      } else {
        if (start > left && start < right) start = left;
        if (end > left && end < right) end = right;
      }
    }
    direction = nextDirection;
    restoreSelection();
  }
  function replace(text, from = start, to = end, mode = 'end', emit = true) {
    if (disabled || readOnly) return;
    selectRange(from, to);
    const previous = snapshot();
    const available = limit < 0 ? -1 : Math.max(0, limit - value.length + end - start);
    const addition = truncate(String(text).replace(/\r\n?/g, '\n'), available);
    const begin = start;
    value = value.slice(0, start) + addition + value.slice(end);
    start = mode === 'select' || mode === 'start' ? begin : begin + addition.length;
    end = mode === 'select' ? begin + addition.length : start;
    direction = 'none';
    record(previous);
    render();
    if (emit) notify();
  }
  function travelHistory(backward) {
    const source = backward ? undo : redo;
    const destination = backward ? redo : undo;
    if (!source.length || disabled || readOnly) return;
    rememberSelection();
    destination.push(snapshot());
    const previous = source.pop();
    ({ value, start, end, direction } = previous);
    render();
    notify();
  }
  function deleteBoundary(backward) {
    const cursor = start;
    for (const match of value.matchAll(TOKEN)) {
      if (backward && match.index + match[0].length === cursor) return match.index;
      if (!backward && match.index === cursor) return cursor + match[0].length;
    }
    const units = segmenter ? Array.from(segmenter.segment(value), part => ({ start: part.index, length: part.segment.length }))
      : Array.from(value).reduce((parts, text) => {
        parts.push({ start: parts.length ? parts[parts.length - 1].start + parts[parts.length - 1].length : 0, length: text.length });
        return parts;
      }, []);
    if (backward) return units.filter(part => part.start < cursor).pop()?.start ?? 0;
    const next = units.find(part => part.start + part.length > cursor);
    return next ? next.start + next.length : value.length;
  }
  function syncAvailability() {
    editor.contentEditable = String(!disabled && !readOnly);
    editor.tabIndex = disabled ? -1 : 0;
    editor.setAttribute('aria-disabled', String(disabled));
    editor.setAttribute('aria-readonly', String(readOnly));
  }

  Object.defineProperties(editor, {
    value: { get: () => value, set(next) {
      value = String(next ?? '').replace(/\r\n?/g, '\n');
      start = end = value.length;
      direction = 'none';
      composing = false;
      undo.length = redo.length = 0; // Never carry undo history between chat drafts.
      render();
    } },
    selectionStart: { get() { rememberSelection(); return start; }, set(next) { selectRange(next, Math.max(Number(next), end)); } },
    selectionEnd: { get() { rememberSelection(); return end; }, set(next) { selectRange(Math.min(start, Number(next)), next); } },
    selectionDirection: { get: () => direction, set(next) { direction = next; restoreSelection(); } },
    maxLength: { get: () => limit, set(next) { limit = Number(next); } },
    placeholder: { get: () => placeholder, set(next) { placeholder = String(next); editor.dataset.placeholder = placeholder; editor.setAttribute('aria-placeholder', placeholder); } },
    disabled: { get: () => disabled, set(next) { disabled = Boolean(next); syncAvailability(); } },
    readOnly: { get: () => readOnly, set(next) { readOnly = Boolean(next); syncAvailability(); } },
    form: { get: () => editor.closest('form') },
  });
  editor.setSelectionRange = selectRange;
  editor.select = () => selectRange(0, value.length);
  editor.setRangeText = (text, from = editor.selectionStart, to = editor.selectionEnd, mode = 'end') => replace(text, from, to, mode, false);
  editor.focus = options => { focus(options); restoreSelection(); };
  editor.placeholder = placeholder;
  syncAvailability();

  editor.addEventListener('beforeinput', event => {
    rememberSelection();
    beforeEdit = snapshot();
    if (composing || event.isComposing) return;
    const type = event.inputType;
    if (type === 'historyUndo' || type === 'historyRedo') {
      event.preventDefault();
      travelHistory(type === 'historyUndo');
    } else if ((type === 'insertText' || type === 'insertReplacementText') && event.data !== null) {
      event.preventDefault();
      replace(event.data);
    } else if (type === 'insertParagraph' || type === 'insertLineBreak') {
      event.preventDefault();
      replace('\n');
    } else if (type === 'deleteContentBackward' || type === 'deleteContentForward') {
      event.preventDefault();
      if (start !== end) replace('');
      else if (type === 'deleteContentBackward') replace('', deleteBoundary(true), end);
      else replace('', start, deleteBoundary(false));
    } else if (type?.startsWith('format')) event.preventDefault();
  });
  editor.addEventListener('input', event => {
    // Our normalized edits dispatch input for the existing room/typing handlers.
    if (!event.isTrusted) return;
    const previous = beforeEdit || snapshot();
    rememberSelection();
    value = serialize(editor);
    if (composing || event.isComposing) return;
    value = truncate(value, limit);
    start = Math.min(start, value.length);
    end = Math.min(end, value.length);
    record(previous);
    render();
    beforeEdit = null;
  });
  let compositionBefore = null;
  editor.addEventListener('compositionstart', () => { rememberSelection(); compositionBefore = snapshot(); composing = true; });
  editor.addEventListener('compositionend', () => {
    rememberSelection();
    composing = false;
    value = truncate(serialize(editor), limit);
    start = Math.min(start, value.length);
    end = Math.min(end, value.length);
    record(compositionBefore || snapshot());
    compositionBefore = beforeEdit = null;
    render();
    notify();
  });
  editor.addEventListener('keydown', event => {
    if (composing && event.key === 'Enter') { event.stopImmediatePropagation(); return; }
    if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
    const key = event.key.toLowerCase();
    if (key === 'z' || key === 'y') { event.preventDefault(); travelHistory(key === 'z' && !event.shiftKey); }
    if (['b', 'i', 'u'].includes(key)) event.preventDefault();
  });
  editor.addEventListener('paste', event => {
    if (Array.from(event.clipboardData?.items || []).some(item => item.kind === 'file' && item.type.startsWith('image/'))) return;
    event.preventDefault();
    rememberSelection();
    replace(event.clipboardData?.getData('text/plain') || '');
  });
  for (const type of ['copy', 'cut']) editor.addEventListener(type, event => {
    rememberSelection();
    if (start === end || !event.clipboardData) return;
    event.preventDefault();
    event.clipboardData.setData('text/plain', value.slice(start, end));
    if (type === 'cut') replace('');
  });
  editor.addEventListener('dragstart', event => { event.preventDefault(); });
  editor.addEventListener('drop', event => {
    event.preventDefault();
    if (event.dataTransfer?.files.length) return;
    const caret = doc.caretPositionFromPoint?.(event.clientX, event.clientY);
    const range = !caret ? doc.caretRangeFromPoint?.(event.clientX, event.clientY) : null;
    const node = caret?.offsetNode || range?.startContainer;
    const offset = caret?.offset ?? range?.startOffset;
    if (node && editor.contains(node)) selectRange(offsetAt(node, offset), offsetAt(node, offset));
    replace(event.dataTransfer?.getData('text/plain') || '');
  });
  editor.addEventListener('blur', rememberSelection);
  doc.addEventListener('selectionchange', rememberSelection);
  const hadFocus = doc.activeElement === textarea;
  textarea.replaceWith(editor);
  render();
  if (hadFocus) editor.focus();
  return editor;
}
