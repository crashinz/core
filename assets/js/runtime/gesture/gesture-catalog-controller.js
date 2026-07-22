"use strict";

function element(tag, className = "", text = "") {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== "") node.textContent = text;
    return node;
}

export class GestureCatalogController {
    #options;
    #states;
    #activeScope;
    #menuGesture;
    #menuReturnFocus;
    #searchTimers;
    #drag;
    #suppressClick;

    constructor(options) {
        this.#options = options;
        this.#states = new Map(["server", "personal"].map(scope => [scope, {
            scope,
            page: 1,
            pages: 1,
            total: 0,
            query: "",
            sort: "last_uploaded",
            items: [],
            orderedIds: [],
            loaded: false,
        }]));
        this.#activeScope = null;
        this.#menuGesture = null;
        this.#menuReturnFocus = null;
        this.#searchTimers = new Map();
        this.#drag = null;
        this.#suppressClick = new WeakSet();
    }

    features() {
        return this.#options.features || {};
    }

    initialize() {
        for (const scope of ["server", "personal"]) {
            const refs = this.#refs(scope);
            refs.search?.addEventListener("input", () => {
                const state = this.#states.get(scope);
                state.query = refs.search.value.slice(0, 120);
                state.page = 1;
                clearTimeout(this.#searchTimers.get(scope));
                this.#searchTimers.set(scope, setTimeout(() => this.load(scope), 250));
                this.#renderGuidance(scope);
            });
            refs.sort?.addEventListener("change", () => this.#setSort(scope, refs.sort.value));
        }
        document.addEventListener("pointerdown", event => {
            const menu = this.#options.actionMenu;
            if (menu && !menu.hidden && !menu.contains(event.target)) this.closeActionMenu();
        });
        document.addEventListener("keydown", event => {
            if (event.key !== "Escape") return;
            if (this.#drag) this.#cancelDrag();
            this.closeActionMenu(true);
        });
        this.#bindHiddenActions();
        this.#applyFeatureVisibility();
    }

    #applyFeatureVisibility() {
        const features = this.features();
        const enhanced = features.enhanced_picker !== false;
        const mapping = {
            gifs: features.gifs_tab !== false,
            "server-gestures": enhanced && features.server_tab !== false,
            "personal-gestures": enhanced && features.personal_tab !== false,
            emojis: features.emojis_tab !== false,
            gestures: !enhanced,
        };
        for (const [tab, visible] of Object.entries(mapping)) {
            const button = this.#options.root?.querySelector(`[data-media-tab="${tab}"]`);
            if (button) button.hidden = !visible;
        }
        for (const scope of ["server", "personal"]) {
            const refs = this.#refs(scope);
            if (refs.search) refs.search.closest("label").hidden = features.search === false;
            if (refs.sort) refs.sort.closest("label").hidden = features.sorting === false;
            const customOption = refs.sort?.querySelector('option[value="custom"]');
            if (customOption) customOption.hidden = features.custom_order === false;
            if (refs.pager) refs.pager.hidden = features.pagination === false;
        }
        if (this.#options.hiddenSection) this.#options.hiddenSection.hidden = features.hide_unhide === false;
    }

    activate(scope) {
        if (!this.#states.has(scope)) return;
        this.#activeScope = scope;
        const state = this.#states.get(scope);
        if (!state.loaded) this.load(scope);
    }

    async refresh(scope = this.#activeScope) {
        if (scope && this.#states.has(scope)) await this.load(scope);
    }

    #refs(scope) {
        return this.#options.catalogs[scope];
    }

    #query(scope) {
        const state = this.#states.get(scope);
        return new URLSearchParams({
            ...this.#options.queryIdentity,
            catalog: scope,
            page: String(state.page),
            q: this.features().search === false ? "" : state.query,
            sort: this.features().sorting === false || (this.features().custom_order === false && state.sort === "custom")
                ? "last_uploaded"
                : state.sort,
        });
    }

    async load(scope) {
        const state = this.#states.get(scope);
        const refs = this.#refs(scope);
        if (!state || !refs?.grid) return;
        refs.grid.replaceChildren(element("div", "gif-loading", "Loading gestures…"));
        try {
            const data = await this.#options.getJson(`/api/gestures.php?${this.#query(scope)}`, `load-${scope}-gestures`);
            state.page = Math.max(1, Number(data.page || 1));
            state.pages = Math.max(1, Number(data.pages || 1));
            state.total = Math.max(0, Number(data.total || 0));
            state.sort = data.sort || state.sort;
            state.items = Array.isArray(data.items) ? data.items : [];
            state.orderedIds = Array.isArray(data.ordered_ids) ? data.ordered_ids.map(String) : [];
            state.loaded = true;
            if (scope === "personal") {
                state.ownedCount = Number(data.owned_count || 0);
                state.ownedLimit = Number(data.owned_limit ?? 50);
            }
            this.#options.onPreferences?.(data.preferences || {}, `catalog-${scope}-load`);
            this.#render(scope);
            if (scope === "server" && this.features().hide_unhide !== false) this.loadHidden();
        } catch (error) {
            refs.grid.replaceChildren(element("div", "minor", error.message || "Gestures could not load."));
            this.#announce(scope, error.message || "Gestures could not load.");
        }
    }

    #render(scope) {
        const state = this.#states.get(scope);
        const refs = this.#refs(scope);
        refs.grid.textContent = "";
        if (scope === "personal") refs.grid.appendChild(this.#uploadTile(state));
        for (const gesture of state.items) refs.grid.appendChild(this.#gestureTile(scope, gesture));
        if (!state.items.length) refs.grid.appendChild(element("div", "gesture-empty", "No gestures found."));
        if (refs.sort) refs.sort.value = state.sort;
        this.#renderPager(scope);
        this.#renderGuidance(scope);
        this.#announce(scope, `${state.total} ${scope === "server" ? "Server" : "Personal"} Gesture${state.total === 1 ? "" : "s"}; page ${state.page} of ${state.pages}.`);
    }

    #uploadTile(state) {
        const button = element("button", "gesture-upload-tile");
        button.type = "button";
        const limitReached = Number(state.ownedCount || 0) >= Number(state.ownedLimit ?? 50);
        button.disabled = limitReached;
        button.title = limitReached ? "Remove some gestures to make room." : "Upload .agst";
        const progress = element("div", "gesture-upload-progress");
        progress.appendChild(document.createElement("i"));
        button.append(
            element("span", "", "+"),
            element("small", "", "Upload .agst"),
            element("em", "", `${Number(state.ownedCount || 0)}/${Number(state.ownedLimit ?? 50)}`),
            progress
        );
        button.addEventListener("click", () => this.#options.onUpload?.(button));
        return button;
    }

    #gestureTile(scope, gesture) {
        const tile = element("article", `gesture-tile${gesture.is_public ? " public" : ""}`);
        tile.dataset.gesturePublicId = gesture.public_id;
        tile.dataset.gestureScope = scope;
        const play = element("button", "gesture-play");
        play.type = "button";
        play.setAttribute("aria-label", `Send ${gesture.text || gesture.title || "gesture"}`);
        const image = document.createElement("img");
        image.src = this.#options.mediaUrl(gesture.gif_path || gesture.gif_url || "");
        image.alt = gesture.text || gesture.title || "Gesture";
        image.draggable = false;
        play.appendChild(image);
        play.addEventListener("click", event => {
            if (this.#suppressClick.has(play)) {
                this.#suppressClick.delete(play);
                event.preventDefault();
                return;
            }
            this.#options.onSend?.(gesture);
        });
        this.#bindPointerReorder(scope, gesture, tile, play);
        tile.appendChild(play);

        if (scope === "personal") {
            const owner = element("button", "gesture-star", "★");
            owner.type = "button";
            owner.title = "Delete my gesture";
            owner.addEventListener("click", () => this.#options.onDelete?.(gesture));
            const visibility = element("button", "gesture-global", "🌐");
            visibility.type = "button";
            visibility.title = gesture.is_public ? "Make Personal" : "Make public";
            visibility.addEventListener("click", () => this.#options.onTogglePublic?.(gesture, !gesture.is_public));
            tile.append(owner, visibility);
        }
        if (!gesture.audio_is_silent && gesture.audio_path) {
            const audio = element("button", "gesture-audio", "🎧");
            audio.type = "button";
            audio.title = "Play gesture audio";
            audio.addEventListener("click", () => this.#options.onAudio?.(gesture, audio));
            tile.appendChild(audio);
        }
        if (this.features().context_menus !== false) {
            const actions = element("button", "gesture-actions", "⋮");
            actions.type = "button";
            actions.setAttribute("aria-label", `Actions for ${gesture.text || gesture.title || "gesture"}`);
            actions.addEventListener("click", event => {
                event.stopPropagation();
                const rect = actions.getBoundingClientRect();
                this.openActionMenu(scope, gesture, rect.left, rect.bottom, actions);
            });
            tile.appendChild(actions);
            tile.addEventListener("contextmenu", event => {
                event.preventDefault();
                event.stopPropagation();
                this.openActionMenu(scope, gesture, event.clientX, event.clientY, tile);
            });
        } else {
            tile.addEventListener("contextmenu", event => event.preventDefault());
        }
        tile.addEventListener("mouseenter", () => this.#announce(scope, gesture.text || gesture.title || "Gesture"));
        return tile;
    }

    #canReorder(scope) {
        const state = this.#states.get(scope);
        return this.features().custom_order !== false && state.query === "";
    }

    #bindPointerReorder(scope, gesture, tile, play) {
        let pending = null;
        play.addEventListener("pointerdown", event => {
            if (!this.#canReorder(scope) || event.button !== 0) return;
            pending = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, started: false };
        });
        play.addEventListener("pointermove", event => {
            if (!pending || pending.pointerId !== event.pointerId) return;
            const distance = Math.hypot(event.clientX - pending.x, event.clientY - pending.y);
            if (!pending.started && distance >= 8) {
                pending.started = true;
                this.#drag = { scope, gesture, tile, beforeId: null, pointerId: event.pointerId };
                tile.classList.add("gesture-dragging");
                play.setPointerCapture?.(event.pointerId);
            }
            if (!pending.started) return;
            event.preventDefault();
            const target = document.elementFromPoint(event.clientX, event.clientY)?.closest?.('.gesture-tile[data-gesture-public-id]');
            this.#options.root.querySelectorAll('.gesture-insertion-target').forEach(node => node.classList.remove('gesture-insertion-target'));
            if (target && target !== tile && target.dataset.gestureScope === scope) {
                target.classList.add('gesture-insertion-target');
                this.#drag.beforeId = target.dataset.gesturePublicId;
            } else {
                this.#drag.beforeId = null;
            }
        });
        const finish = async event => {
            if (!pending || pending.pointerId !== event.pointerId) return;
            const started = pending.started;
            pending = null;
            if (!started) return;
            event.preventDefault();
            this.#suppressClick.add(play);
            const beforeId = this.#drag?.beforeId || null;
            this.#cancelDrag();
            await this.#move(scope, gesture.public_id, "move_before", { before_id: beforeId });
        };
        play.addEventListener("pointerup", finish);
        play.addEventListener("pointercancel", () => { pending = null; this.#cancelDrag(); });
    }

    #cancelDrag() {
        this.#drag?.tile?.classList.remove("gesture-dragging");
        this.#options.root.querySelectorAll('.gesture-insertion-target').forEach(node => node.classList.remove('gesture-insertion-target'));
        this.#drag = null;
    }

    #renderPager(scope) {
        const state = this.#states.get(scope);
        const pager = this.#refs(scope).pager;
        if (!pager) return;
        pager.textContent = "";
        const previous = element("button", "btn", "Previous");
        previous.type = "button";
        previous.disabled = state.page <= 1;
        previous.addEventListener("click", () => { state.page -= 1; this.load(scope); });
        const label = element("span", "", `Page ${state.page} of ${state.pages}`);
        label.setAttribute("aria-live", "polite");
        const next = element("button", "btn", "Next");
        next.type = "button";
        next.disabled = state.page >= state.pages;
        next.addEventListener("click", () => { state.page += 1; this.load(scope); });
        pager.append(previous, label, next);
    }

    #renderGuidance(scope) {
        const state = this.#states.get(scope);
        const guidance = this.#refs(scope).guidance;
        if (guidance) guidance.hidden = state.query === "";
    }

    async #setSort(scope, sort) {
        const preferences = this.#options.getPreferences();
        try {
            const result = await this.#options.mutate({
                action: "set_sort",
                catalog: scope,
                sort,
                expected_version: Number(preferences.version || 0),
                request_key: this.#options.requestKey(`gesture-sort-${scope}`),
            });
            this.#options.onPreferences?.(result.preferences || {}, `catalog-${scope}-sort`);
            const state = this.#states.get(scope);
            state.sort = sort;
            state.page = 1;
            await this.load(scope);
        } catch (error) {
            this.#announce(scope, error.message || "Gesture sort could not be saved.");
            await this.load(scope);
        }
    }

    async #move(scope, publicId, action, extra = {}) {
        const state = this.#states.get(scope);
        if (!this.#canReorder(scope)) {
            this.#announce(scope, "Clear the search to rearrange gestures.");
            return;
        }
        const preferences = this.#options.getPreferences();
        try {
            const result = await this.#options.mutate({
                action,
                catalog: scope,
                public_id: publicId,
                expected_version: Number(preferences[scope === "server" ? "serverOrderVersion" : "personalOrderVersion"] || 0),
                request_key: this.#options.requestKey(`${action}-${scope}`),
                search: state.query,
                ...extra,
            });
            this.#options.onOrderVersion?.(scope, Number(result.version || 0));
            state.sort = "custom";
            await this.load(scope);
        } catch (error) {
            this.#announce(scope, error.message || "Gesture order could not be changed.");
            await this.load(scope);
        }
    }

    openActionMenu(scope, gesture, x, y, returnFocus) {
        const menu = this.#options.actionMenu;
        if (!menu) return;
        this.#menuGesture = { scope, gesture };
        this.#menuReturnFocus = returnFocus;
        menu.textContent = "";
        const action = (label, callback, disabled = false, keepOpen = false) => {
            const button = element("button", "", label);
            button.type = "button";
            button.setAttribute("role", "menuitem");
            button.disabled = disabled;
            button.addEventListener("click", async event => {
                event.stopPropagation();
                if (!keepOpen) this.closeActionMenu();
                await callback();
            });
            menu.appendChild(button);
        };
        const searchActive = this.#states.get(scope).query !== "";
        if (scope === "server" && this.features().hide_unhide !== false) {
            action("Hide Server Gesture", () => this.#hideGesture(gesture), false);
        }
        action("Move to Top", () => this.#move(scope, gesture.public_id, "move_top"), searchActive || this.features().custom_order === false);
        action("Move to Page…", () => this.#openMovePage(scope, gesture), searchActive || this.features().custom_order === false, true);
        action("Reset Custom Position", () => this.#move(scope, gesture.public_id, "reset_position"), searchActive || this.features().custom_order === false);
        menu.hidden = false;
        menu.style.left = `${Math.max(8, Math.min(x, window.innerWidth - 230))}px`;
        menu.style.top = `${Math.max(8, Math.min(y, window.innerHeight - menu.offsetHeight - 8))}px`;
        menu.querySelector("button:not(:disabled)")?.focus();
    }

    #openMovePage(scope, gesture) {
        const menu = this.#options.actionMenu;
        const pages = this.#states.get(scope).pages;
        menu.hidden = false;
        menu.textContent = "";
        const label = element("label", "", "Move to page");
        const select = document.createElement("select");
        for (let page = 1; page <= pages; page += 1) {
            const option = document.createElement("option");
            option.value = String(page);
            option.textContent = `Page ${page}`;
            select.appendChild(option);
        }
        label.appendChild(select);
        const move = element("button", "btn btn-primary", "Move");
        move.type = "button";
        move.addEventListener("click", async () => {
            const page = Number(select.value);
            this.closeActionMenu(true);
            await this.#move(scope, gesture.public_id, "move_page", { page });
        });
        const cancel = element("button", "btn", "Cancel");
        cancel.type = "button";
        cancel.addEventListener("click", () => this.closeActionMenu(true));
        menu.append(label, move, cancel);
        select.focus();
    }

    closeActionMenu(restoreFocus = false) {
        const menu = this.#options.actionMenu;
        if (menu) menu.hidden = true;
        if (restoreFocus) this.#menuReturnFocus?.focus?.();
        this.#menuGesture = null;
        this.#menuReturnFocus = null;
    }

    async #hideGesture(gesture) {
        try {
            await this.#options.onHide?.(gesture.public_id, true);
            await this.load("server");
        } catch (error) {
            this.#announce("server", error.message || "Gesture could not be hidden.");
        }
    }

    async loadHidden() {
        if (!this.#options.hiddenList) return;
        const query = this.#options.hiddenSearch?.value?.slice(0, 120) || "";
        try {
            const qs = new URLSearchParams({ ...this.#options.queryIdentity, catalog: "hidden", page: "1", q: query, sort: "file_name" });
            const data = await this.#options.getJson(`/api/gestures.php?${qs}`, "load-hidden-gestures");
            const items = Array.isArray(data.items) ? data.items : [];
            this.#options.hiddenList.textContent = "";
            for (const gesture of items) {
                const row = element("label", "hidden-gesture-row");
                const checkbox = document.createElement("input");
                checkbox.type = "checkbox";
                checkbox.value = gesture.public_id;
                const copy = element("span", "", gesture.text || gesture.title || gesture.catalog_filename || "Gesture");
                row.append(checkbox, copy);
                this.#options.hiddenList.appendChild(row);
            }
            if (!items.length) this.#options.hiddenList.appendChild(element("div", "minor", "No hidden gestures."));
            if (this.#options.hiddenCount) this.#options.hiddenCount.textContent = String(data.total || 0);
            this.#options.onPreferences?.(data.preferences || {}, "hidden-catalog-load");
        } catch (error) {
            this.#hiddenStatus(error.message || "Hidden gestures could not load.");
        }
    }

    #bindHiddenActions() {
        let timer = null;
        this.#options.hiddenSearch?.addEventListener("input", () => {
            clearTimeout(timer);
            timer = setTimeout(() => this.loadHidden(), 250);
        });
        this.#options.showSelected?.addEventListener("click", async () => {
            const ids = [...this.#options.hiddenList.querySelectorAll('input:checked')].map(input => input.value);
            if (ids.length) await this.#unhideMany(ids);
        });
        this.#options.showAll?.addEventListener("click", () => {
            this.#options.hiddenConfirm.hidden = false;
            this.#options.hiddenConfirmYes?.focus();
        });
        this.#options.hiddenConfirmNo?.addEventListener("click", () => {
            this.#options.hiddenConfirm.hidden = true;
            this.#options.showAll?.focus();
        });
        this.#options.hiddenConfirmYes?.addEventListener("click", async () => {
            this.#options.hiddenConfirm.hidden = true;
            await this.#unhideMany([]);
        });
    }

    async #unhideMany(ids) {
        try {
            const result = await this.#options.mutate({
                action: "unhide_many",
                public_ids: ids,
                expected_version: Number(this.#options.getPreferences().hiddenVersion || 0),
                request_key: this.#options.requestKey("unhide-many"),
            });
            for (const id of result.unhidden_ids || []) this.#options.onHiddenMutation?.(id, false, result.version);
            this.#hiddenStatus(`${(result.unhidden_ids || []).length} hidden gesture${(result.unhidden_ids || []).length === 1 ? "" : "s"} shown again.`);
            await this.load("server");
        } catch (error) {
            this.#hiddenStatus(error.message || "Hidden gestures could not be shown again.");
        }
    }

    #announce(scope, message) {
        const status = this.#refs(scope)?.status;
        if (status) status.textContent = message;
    }

    #hiddenStatus(message) {
        if (this.#options.hiddenStatus) this.#options.hiddenStatus.textContent = message;
    }
}
