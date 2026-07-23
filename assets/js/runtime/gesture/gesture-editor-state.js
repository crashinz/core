"use strict";

const LIMITS = Object.freeze({
    title: 120,
    text: 180,
    creatorCredit: 120,
    catalogFilename: 120,
    packageBytes: 30 * 1024 * 1024,
    animationBytes: 25 * 1024 * 1024,
    posterBytes: 5 * 1024 * 1024,
    audioBytes: 10 * 1024 * 1024,
});

function clean(value) {
    return String(value ?? "").replace(/[\r\n\t]+/g, " ").replace(/\s+/g, " ").trim();
}

function safeFile(file, extensions, maxBytes, label) {
    if (!file) return null;
    const extension = String(file.name || "").split(".").pop()?.toLowerCase() || "";
    if (!extensions.includes(extension)) throw new Error(`${label} must use ${extensions.map(value => `.${value}`).join(" or ")}.`);
    if (!Number.isFinite(file.size) || file.size < 1 || file.size > maxBytes) throw new Error(`${label} is empty or exceeds its safe size limit.`);
    return file;
}

export class GestureEditorState {
    #mode;
    #admin;
    #gesture;
    #package;
    #features;
    #preferences;
    #files;
    #urls;

    constructor({ mode = "create", admin = false } = {}) {
        this.#mode = mode === "edit" ? "edit" : "create";
        this.#admin = Boolean(admin);
        this.#gesture = null;
        this.#package = null;
        this.#features = {};
        this.#preferences = { show_animations: true, show_text: true, play_sounds: true };
        this.#files = { package: null, animation: null, poster: null, audio: null };
        this.#urls = new Map();
    }

    mode() { return this.#mode; }
    admin() { return this.#admin; }
    gesture() { return this.#gesture ? { ...this.#gesture } : null; }
    packageSummary() { return this.#package ? structuredClone(this.#package) : null; }
    features() { return Object.freeze({ ...this.#features }); }
    preferences() { return Object.freeze({ ...this.#preferences }); }
    files() { return { ...this.#files }; }

    hydrate(payload = {}) {
        this.#gesture = payload.gesture ? { ...payload.gesture } : null;
        this.#package = payload.package ? structuredClone(payload.package) : null;
        this.#features = { ...(payload.features || {}) };
        this.#preferences = { ...this.#preferences, ...(payload.preferences || {}) };
        if (this.#gesture) this.#mode = "edit";
        return this;
    }

    setFile(role, file) {
        if (!(role in this.#files)) throw new Error("Unknown gesture media role.");
        const validated = role === "package" ? safeFile(file, ["agst"], LIMITS.packageBytes, "AGST package")
            : role === "animation" ? safeFile(file, ["gif"], LIMITS.animationBytes, "Animation")
            : role === "poster" ? safeFile(file, ["gif", "png", "jpg", "jpeg", "webp"], LIMITS.posterBytes, "Poster")
            : safeFile(file, ["mp3"], LIMITS.audioBytes, "Audio");
        this.#files[role] = validated;
        this.#revoke(role);
        if (validated && role !== "package") this.#urls.set(role, URL.createObjectURL(validated));
    }

    previewUrl(role) {
        if (this.#urls.has(role)) return this.#urls.get(role);
        if (role === "animation") return this.#gesture?.gif_url || this.#gesture?.gif_path || "";
        if (role === "poster") return this.#gesture?.poster_url || this.#gesture?.poster_path || "";
        if (role === "audio") return this.#gesture?.audio_url || this.#gesture?.audio_path || "";
        return "";
    }

    validate(values = {}) {
        const title = clean(values.title);
        const text = clean(values.text);
        const creatorCredit = clean(values.creator_credit);
        const catalogFilename = clean(values.catalog_filename);
        const errors = [];
        if (!title || title.length > LIMITS.title) errors.push(`Gesture title is required and must be at most ${LIMITS.title} characters.`);
        if (!text || text.length > LIMITS.text) errors.push(`Gesture text is required and must be at most ${LIMITS.text} characters.`);
        if (!creatorCredit || creatorCredit.length > LIMITS.creatorCredit) errors.push(`Creator credit is required and must be at most ${LIMITS.creatorCredit} characters.`);
        if (!catalogFilename || catalogFilename.length > LIMITS.catalogFilename) errors.push(`Catalog filename is required and must be at most ${LIMITS.catalogFilename} characters.`);
        if (!/^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/u.test(catalogFilename)) errors.push("Catalog filename may use letters, numbers, spaces, dots, underscores, and hyphens.");
        if (this.#mode === "create" && !this.#files.package && !this.#files.animation) errors.push("Choose an AGST package or GIF animation before saving a new gesture.");
        if (this.#files.package && this.#files.animation) errors.push("Choose either an AGST package or a replacement GIF as the primary source, not both.");
        return { ok: errors.length === 0, errors, values: { title, text, creator_credit: creatorCredit, catalog_filename: catalogFilename } };
    }

    applySaved(payload = {}) {
        this.#gesture = payload.gesture ? { ...payload.gesture } : this.#gesture;
        this.#package = payload.package ? structuredClone(payload.package) : this.#package;
        this.#mode = "edit";
        for (const role of Object.keys(this.#files)) this.#files[role] = null;
        this.revokeObjectUrls();
    }

    #revoke(role) {
        const url = this.#urls.get(role);
        if (url) URL.revokeObjectURL(url);
        this.#urls.delete(role);
    }

    revokeObjectUrls() {
        for (const role of [...this.#urls.keys()]) this.#revoke(role);
    }
}

export { LIMITS as gestureEditorLimits };
