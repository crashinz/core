// Memory-only recovery: never persist private plaintext in browser storage.
export class ChatOutbox {
    #entries = new Map();
    constructor(onChange = () => {}) { this.onChange = onChange; }
    entries() { return [...this.#entries.values()]; }
    enqueue(content, chatKey, send) {
        const entry = {id: crypto.randomUUID(), content, chatKey, send, pending: false, error: '', prepared: null};
        this.#entries.set(entry.id, entry);
        this.retry(entry.id);
        return entry;
    }
    async retry(id) {
        const entry = this.#entries.get(id);
        if (!entry || entry.pending) return;
        entry.pending = true; entry.error = ''; this.onChange();
        try {
            const result = await entry.send(entry);
            if (!result) throw new Error('The message was not accepted.');
            this.#entries.delete(id);
        } catch (error) {
            entry.error = error?.message || 'The message could not be sent.';
        } finally { entry.pending = false; this.onChange(); }
    }
    discard(id) {
        if (!this.#entries.get(id)?.pending) { this.#entries.delete(id); this.onChange(); }
    }
}
