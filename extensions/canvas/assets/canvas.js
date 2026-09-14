const canvasLaunchers = [...document.querySelectorAll("[data-canvas-launcher]")];

if (canvasLaunchers.length) {
  const appBase = String(document.body.dataset.appBase || "").replace(/\/$/, "");
  const csrf = String(document.body.dataset.csrf || "");
  const appUrl = path => `${appBase}${path}`;
  const state = {scope: "community", roomPublicId: "", data: null, dirty: false, returnFocus: null};
  const node = (tag, className = "", text = "") => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text) element.textContent = text;
    return element;
  };
  const requestId = prefix => `${prefix}-${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
  const payloadBase = () => ({scope: state.scope, room_public_id: state.roomPublicId});

  const overlay = node("section", "canvas-overlay");
  overlay.hidden = true;
  overlay.innerHTML = `
    <div class="canvas-surface" role="dialog" aria-modal="true" aria-labelledby="canvas-heading">
      <div class="canvas-sticky-region">
        <header class="canvas-header">
          <div><span class="canvas-kicker">Shared information</span><h2 id="canvas-heading">Canvas</h2></div>
          <button class="canvas-close" type="button" aria-label="Close Canvas">&times;</button>
        </header>
        <div class="canvas-status" role="status" aria-live="polite"></div>
      </div>
      <nav class="canvas-tabs" aria-label="Canvas views"></nav>
      <div class="canvas-content"></div>
    </div>`;
  document.body.appendChild(overlay);
  const surface = overlay.querySelector(".canvas-surface");
  const heading = overlay.querySelector("#canvas-heading");
  const status = overlay.querySelector(".canvas-status");
  const tabs = overlay.querySelector(".canvas-tabs");
  const content = overlay.querySelector(".canvas-content");
  const closeButton = overlay.querySelector(".canvas-close");

  function setStatus(message = "", kind = "") {
    status.textContent = message;
    status.dataset.kind = kind;
  }

  async function api(body = null) {
    const options = body ? {
      method: "POST",
      headers: {"Content-Type": "application/json", "X-CSRF-Token": csrf},
      body: JSON.stringify(body),
    } : {};
    const query = new URLSearchParams({scope: state.scope});
    if (state.roomPublicId) query.set("room_public_id", state.roomPublicId);
    const response = await fetch(appUrl(`/api/canvas.php${body ? "" : `?${query}`}`), options);
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.error) {
      const error = new Error(result.error || "Canvas request failed.");
      error.code = result.code || "CANVAS_REQUEST_FAILED";
      throw error;
    }
    return result;
  }

  function confirmDiscard() {
    return !state.dirty || globalThis.confirm("Discard unsaved Canvas changes?");
  }

  function closeCanvas() {
    if (!confirmDiscard()) return;
    state.dirty = false;
    overlay.hidden = true;
    document.body.classList.remove("canvas-open");
    state.returnFocus?.focus?.();
  }

  function formatText(target, text) {
    const parts = String(text || "").split(/(\*\*[^*]+\*\*|\*[^*]+\*|\[[^\]]+\]\(https:\/\/[^\s)]+\))/g);
    parts.forEach(part => {
      let match;
      if ((match = part.match(/^\*\*([^*]+)\*\*$/))) {
        target.append(node("strong", "", match[1]));
      } else if ((match = part.match(/^\*([^*]+)\*$/))) {
        target.append(node("em", "", match[1]));
      } else if ((match = part.match(/^\[([^\]]+)\]\((https:\/\/[^\s)]+)\)$/))) {
        const link = node("a", "", match[1]);
        link.href = match[2];
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        target.append(link);
      } else {
        target.append(document.createTextNode(part));
      }
    });
  }

  function sectionBody(section) {
    const wrap = node("div", "canvas-section-body");
    const lines = String(section.body || "").split("\n");
    if (section.kind === "checklist") {
      const list = node("ul", "canvas-checklist");
      lines.filter(Boolean).forEach(line => {
        const item = node("li");
        const checked = /^\s*\[[xX]\]\s*/.test(line);
        const label = line.replace(/^\s*\[[ xX]\]\s*/, "");
        const box = document.createElement("input");
        box.type = "checkbox";
        box.checked = checked;
        box.disabled = true;
        box.setAttribute("aria-label", label);
        const labelText = node("span", "canvas-checklist-label");
        labelText.textContent = label;
        item.append(box, labelText);
        list.append(item);
      });
      wrap.append(list);
    } else {
      lines.forEach(line => {
        const paragraph = node("p");
        formatText(paragraph, line);
        wrap.append(paragraph);
      });
    }
    if (section.mediaUrl) {
      const ref = node("a", "canvas-reference", section.kind === "media" ? "Open media reference" : "Open resource");
      ref.href = section.mediaUrl.startsWith("/") ? appUrl(section.mediaUrl) : section.mediaUrl;
      ref.target = "_blank";
      ref.rel = "noopener noreferrer";
      wrap.append(ref);
    }
    return wrap;
  }

  function commentsFor(sectionId) {
    return (state.data.comments || []).filter(comment => comment.sectionId === sectionId);
  }

  function renderPublished() {
    content.replaceChildren();
    const published = state.data.published || {sections: []};
    if (!published.sections.length) {
      content.append(node("div", "canvas-empty", "Nothing has been published here yet."));
      return;
    }
    published.sections.forEach(section => {
      const article = node("article", `canvas-section canvas-section-${section.kind}`);
      article.append(node("h3", "", section.heading), sectionBody(section));
      const thread = node("section", "canvas-thread");
      thread.append(node("h4", "", "Discussion"));
      const comments = commentsFor(section.id);
      if (!comments.length) thread.append(node("p", "canvas-minor", "No comments yet."));
      comments.forEach(comment => {
        const row = node("article", "canvas-comment");
        const meta = node("div", "canvas-comment-meta", `${comment.author} - ${comment.createdAt}`);
        const body = node("p", "", comment.body);
        row.append(meta, body);
        if (state.data.permissions.manage || comment.authorId === Number(document.body.dataset.userId || 0)) {
          const remove = node("button", "canvas-link-button", "Remove");
          remove.type = "button";
          remove.addEventListener("click", async () => {
            if (!globalThis.confirm("Remove this Canvas comment?")) return;
            try {
              await api({...payloadBase(), action: "remove_comment", comment_id: comment.id, reason: "Removed from Canvas discussion."});
              await reload("Comment removed.");
            } catch (error) { setStatus(error.message, "error"); }
          });
          row.append(remove);
        }
        thread.append(row);
      });
      if (state.data.permissions.comment) {
        const form = node("form", "canvas-comment-form");
        const textarea = document.createElement("textarea");
        textarea.maxLength = 4000;
        textarea.required = true;
        textarea.placeholder = "Add a section comment";
        const submit = node("button", "btn btn-primary", "Comment");
        submit.type = "submit";
        form.append(textarea, submit);
        form.addEventListener("submit", async event => {
          event.preventDefault();
          try {
            await api({...payloadBase(), action: "add_comment", request_id: requestId("canvas-comment"), section_id: section.id, body: textarea.value});
            await reload("Comment added.");
          } catch (error) { setStatus(error.message, "error"); }
        });
        thread.append(form);
      }
      article.append(thread);
      content.append(article);
    });
  }

  function sectionEditor(section = {}) {
    const card = node("fieldset", "canvas-section-editor");
    card.dataset.sectionId = section.id || (globalThis.crypto?.randomUUID?.() || requestId("section"));
    const legend = node("legend", "", section.heading || "New section");
    const headingInput = document.createElement("input");
    headingInput.value = section.heading || "";
    headingInput.maxLength = 160;
    headingInput.required = true;
    headingInput.placeholder = "Section heading";
    headingInput.dataset.field = "heading";
    const kind = document.createElement("select");
    kind.dataset.field = "kind";
    [["text","Formatted text"],["rules","Rules"],["checklist","Checklist"],["links","Links and resources"],["media","Media reference"]].forEach(([value,label]) => {
      const option = new Option(label, value);
      option.selected = (section.kind || "text") === value;
      kind.add(option);
    });
    const body = document.createElement("textarea");
    body.dataset.field = "body";
    body.maxLength = 12000;
    body.rows = 7;
    body.value = section.body || "";
    body.placeholder = "Plain text with optional **bold**, *italic*, [label](https://example.com), or checklist lines such as [ ] Task";
    const media = document.createElement("input");
    media.dataset.field = "mediaUrl";
    media.type = "url";
    media.value = section.mediaUrl || "";
    media.placeholder = "Optional HTTPS media or resource reference";
    const remove = node("button", "canvas-link-button canvas-remove-section", "Remove section");
    remove.type = "button";
    remove.addEventListener("click", () => { card.remove(); state.dirty = true; });
    [headingInput, kind, body, media].forEach(control => control.addEventListener("input", () => {
      state.dirty = true;
      if (control === headingInput) legend.textContent = headingInput.value || "New section";
    }));
    card.append(legend, headingInput, kind, body, media, remove);
    return card;
  }

  function readEditor(form) {
    return {
      title: form.elements.title.value,
      sections: [...form.querySelectorAll(".canvas-section-editor")].map(card => ({
        id: card.dataset.sectionId,
        heading: card.querySelector('[data-field="heading"]').value,
        kind: card.querySelector('[data-field="kind"]').value,
        body: card.querySelector('[data-field="body"]').value,
        mediaUrl: card.querySelector('[data-field="mediaUrl"]').value,
      })),
    };
  }

  function renderEditor() {
    content.replaceChildren();
    const form = node("form", "canvas-editor");
    const titleLabel = node("label", "canvas-field");
    titleLabel.append(node("span", "", "Canvas title"));
    const title = document.createElement("input");
    title.name = "title";
    title.required = true;
    title.maxLength = 160;
    title.value = state.data.title || "Canvas";
    title.addEventListener("input", () => { state.dirty = true; });
    titleLabel.append(title);
    const sections = node("div", "canvas-editor-sections");
    (state.data.draft?.sections || state.data.published?.sections || []).forEach(section => sections.append(sectionEditor(section)));
    const add = node("button", "btn", "Add section");
    add.type = "button";
    add.addEventListener("click", () => { sections.append(sectionEditor()); state.dirty = true; });
    const actions = node("div", "canvas-editor-actions");
    const save = node("button", "btn btn-primary", "Save Draft");
    save.type = "submit";
    actions.append(save);
    if (state.data.permissions.publish && state.data.draftVersion > 0) {
      const publish = node("button", "btn", "Publish current draft");
      publish.type = "button";
      publish.addEventListener("click", async () => {
        if (state.dirty) { setStatus("Save the current draft before publishing.", "error"); return; }
        try {
          await api({...payloadBase(), action: "publish", request_id: requestId("canvas-publish"), expected_version: state.data.draftVersion});
          await reload("Canvas published.");
        } catch (error) { setStatus(error.message, "error"); }
      });
      actions.append(publish);
    }
    form.append(titleLabel, sections, add, actions);
    form.addEventListener("submit", async event => {
      event.preventDefault();
      try {
        await api({...payloadBase(), action: "save_draft", request_id: requestId("canvas-save"), expected_version: state.data.draftVersion || 0, document: readEditor(form)});
        state.dirty = false;
        await reload("Draft saved.");
      } catch (error) { setStatus(error.message, "error"); }
    });
    content.append(form);
  }

  function renderPermissions() {
    content.replaceChildren();
    const intro = node("p", "canvas-minor", "Grant each action separately. Room presence or a role label never bypasses server authorization.");
    const list = node("div", "canvas-grant-list");
    (state.data.grants || []).forEach(grant => {
      list.append(node("div", "canvas-grant-row", `${grant.displayName} (@${grant.username}): ${["view","comment","edit","manage","publish"].filter(key => grant[key]).join(", ") || "no access"}`));
    });
    const form = node("form", "canvas-permission-form");
    const username = document.createElement("input");
    username.name = "username";
    username.required = true;
    username.placeholder = "Member username";
    const flags = node("div", "canvas-permission-flags");
    ["view","comment","edit","manage","publish"].forEach(name => {
      const label = node("label");
      const input = document.createElement("input");
      input.type = "checkbox";
      input.name = name;
      if (name === "view") input.checked = true;
      label.append(input, document.createTextNode(name[0].toUpperCase() + name.slice(1)));
      flags.append(label);
    });
    const save = node("button", "btn btn-primary", "Save permissions");
    save.type = "submit";
    form.append(username, flags, save);
    form.addEventListener("submit", async event => {
      event.preventDefault();
      const body = {...payloadBase(), action: "set_permission", username: username.value};
      ["view","comment","edit","manage","publish"].forEach(name => body[name] = form.elements[name].checked);
      try {
        await api(body);
        await reload("Permissions updated.");
        showTab("permissions");
      } catch (error) { setStatus(error.message, "error"); }
    });
    content.append(intro, list, form);
  }

  function showTab(name) {
    [...tabs.children].forEach(button => {
      const active = button.dataset.canvasTab === name;
      button.classList.toggle("active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
    });
    if (name === "edit") renderEditor();
    else if (name === "permissions") renderPermissions();
    else renderPublished();
  }

  function render() {
    heading.textContent = state.data.title || (state.scope === "community" ? "Community Canvas" : "Room Canvas");
    tabs.replaceChildren();
    const choices = [["published", "Published"]];
    if (state.data.permissions.edit) choices.push(["edit", "Edit"]);
    if (state.data.permissions.manage && state.data.documentId) choices.push(["permissions", "Permissions"]);
    choices.forEach(([name, label]) => {
      const button = node("button", "", label);
      button.type = "button";
      button.dataset.canvasTab = name;
      button.setAttribute("role", "tab");
      button.addEventListener("click", () => {
        if (name !== "edit" && !confirmDiscard()) return;
        if (name !== "edit") state.dirty = false;
        showTab(name);
      });
      tabs.append(button);
    });
    showTab("published");
  }

  async function reload(success = "") {
    const result = await api();
    state.data = result.canvas;
    state.dirty = false;
    render();
    setStatus(success);
  }

  async function openCanvas(launcher) {
    state.returnFocus = launcher;
    state.scope = launcher.dataset.canvasScope || "community";
    state.roomPublicId = launcher.dataset.canvasRoom || "";
    overlay.hidden = false;
    document.body.classList.add("canvas-open");
    content.replaceChildren(node("div", "canvas-empty", "Loading Canvas..."));
    setStatus("");
    try {
      await reload();
      closeButton.focus();
    } catch (error) {
      content.replaceChildren(node("div", "canvas-empty", error.message));
      setStatus(error.message, "error");
    }
  }

  canvasLaunchers.forEach(launcher => launcher.addEventListener("click", () => openCanvas(launcher)));
  closeButton.addEventListener("click", closeCanvas);
  overlay.addEventListener("mousedown", event => { if (event.target === overlay) closeCanvas(); });
  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !overlay.hidden) {
      event.preventDefault();
      closeCanvas();
    }
  });
  globalThis.addEventListener("beforeunload", event => {
    if (!state.dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });
}
