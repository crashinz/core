/* Avatar Runtime: one passive, draggable relationship-request notification. */
export class AvatarRelationshipRequestNotice {
    #document;
    #root;
    #list;
    #summary;
    #status;
    #bindings = new AbortController();
    #dismissed = new Set();
    #items = [];
    #rows = new Map();
    #drag = null;

    constructor(document, { onAction, onReview }) {
        this.#document = document;
        const signal = this.#bindings.signal;
        const root = document.createElement("section");
        root.className = "relationship-request-notice";
        root.hidden = true;
        root.setAttribute("aria-label", "Relationship requests");
        const header = document.createElement("div");
        header.className = "relationship-request-notice-header";
        const handle = document.createElement("button");
        handle.type = "button";
        handle.className = "relationship-request-notice-handle";
        handle.textContent = "Relationship requests";
        handle.setAttribute("aria-label", "Move relationship requests. Drag or use arrow keys.");
        const close = document.createElement("button");
        close.type = "button";
        close.className = "btn";
        close.textContent = "Dismiss";
        close.addEventListener("click", () => this.#dismiss(), { signal });
        header.append(handle, close);
        this.#summary = document.createElement("p");
        this.#summary.setAttribute("role", "status");
        this.#summary.setAttribute("aria-live", "polite");
        this.#list = document.createElement("ul");
        this.#list.className = "relationship-request-list";
        this.#status = document.createElement("p");
        this.#status.className = "relationship-request-notice-status";
        this.#status.setAttribute("role", "status");
        const review = document.createElement("button");
        review.type = "button";
        review.className = "btn";
        review.textContent = "Manage Relationship";
        review.addEventListener("click", () => {
            const relationshipId = this.#items[0]?.relationshipId;
            if (relationshipId) onReview(relationshipId);
        }, { signal });
        root.append(header, this.#summary, this.#list, this.#status, review);
        root.addEventListener("click", event => {
            const action = event.target.closest?.("[data-relationship-action]");
            if (action && root.contains(action)) onAction(action);
        }, { signal });
        root.addEventListener("keydown", event => {
            if (event.key !== "Escape") return;
            event.stopPropagation();
            this.#dismiss();
        }, { signal });
        handle.addEventListener("pointerdown", event => {
            if (event.button !== 0) return;
            event.preventDefault(); // Moving this notice must not focus it or blur the composer.
            const rect = root.getBoundingClientRect();
            this.#drag = { id: event.pointerId, x: event.clientX - rect.left, y: event.clientY - rect.top };
            handle.setPointerCapture(event.pointerId);
        }, { signal });
        handle.addEventListener("pointermove", event => {
            if (this.#drag?.id !== event.pointerId) return;
            this.#position(event.clientX - this.#drag.x, event.clientY - this.#drag.y);
        }, { signal });
        const endDrag = () => {
            const id = this.#drag?.id;
            this.#drag = null;
            if (id !== undefined && handle.hasPointerCapture(id)) handle.releasePointerCapture(id);
        };
        for (const name of ["pointerup", "pointercancel", "lostpointercapture"]) {
            handle.addEventListener(name, endDrag, { signal });
        }
        handle.addEventListener("keydown", event => {
            const direction = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
            if (!direction) return;
            event.preventDefault();
            event.stopPropagation();
            const rect = root.getBoundingClientRect();
            this.#position(rect.left + direction[0] * 20, rect.top + direction[1] * 20);
        }, { signal });
        document.defaultView?.addEventListener("blur", endDrag, { signal });
        document.defaultView?.addEventListener("resize", () => {
            if (root.hidden) return;
            const rect = root.getBoundingClientRect();
            this.#position(rect.left, rect.top);
        }, { signal });
        this.#root = root;
        document.body.append(root);
    }

    update(items) {
        const activeIds = new Set(items.map(item => item.id));
        this.#dismissed = new Set([...this.#dismissed].filter(id => activeIds.has(id)));
        this.#items = items.filter(item => !this.#dismissed.has(item.id));
        const visibleIds = new Set(this.#items.map(item => item.id));
        for (const [id, entry] of this.#rows) {
            if (visibleIds.has(id)) continue;
            entry.row.remove();
            this.#rows.delete(id);
        }
        for (const item of this.#items) {
            const existing = this.#rows.get(item.id);
            // Repeated event/poll deliveries never replace a focused action button.
            if (existing?.signature === item.signature) continue;
            if (existing) existing.row.replaceWith(item.row);
            else this.#list.append(item.row);
            this.#rows.set(item.id, item);
        }
        const wasHidden = this.#root.hidden;
        this.#root.hidden = this.#items.length === 0;
        const summary = `${this.#items.length} pending request${this.#items.length === 1 ? "" : "s"}. You can keep typing.`;
        if (this.#summary.textContent !== summary) this.#summary.textContent = summary;
        if (wasHidden && !this.#root.hidden) {
            this.#status.textContent = "";
            const rect = this.#root.getBoundingClientRect();
            this.#position(rect.left, rect.top);
        }
    }

    showStatus(message) {
        this.#status.textContent = String(message || "");
    }

    isVisible() { return !this.#root.hidden; }

    #dismiss() {
        this.#items.forEach(item => this.#dismissed.add(item.id));
        this.#root.hidden = true;
    }

    #position(x, y) {
        const view = this.#document.defaultView;
        const rect = this.#root.getBoundingClientRect();
        this.#root.style.left = `${Math.max(8, Math.min(x, view.innerWidth - rect.width - 8))}px`;
        this.#root.style.top = `${Math.max(8, Math.min(y, view.innerHeight - rect.height - 8))}px`;
        this.#root.style.right = "auto";
    }

    destroy() {
        this.#bindings.abort();
        this.#root.remove();
        this.#rows.clear();
        this.#dismissed.clear();
    }
}
