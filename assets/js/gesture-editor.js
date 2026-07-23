"use strict";

import { GestureEditorState } from "./runtime/gesture/gesture-editor-state.js";

const body = document.body;
const APP_BASE = String(body?.dataset.appBase || "").replace(/\/$/, "");
const CSRF = String(body?.dataset.csrf || "");
const publicId = String(body?.dataset.gestureId || "");
const admin = body?.dataset.admin === "true";
const state = new GestureEditorState({ mode: publicId ? "edit" : "create", admin });
const byId = id => document.getElementById(id);
const form = byId("gesture-editor-form");
const status = byId("gesture-editor-status");
const validation = byId("gesture-editor-validation");
const preview = byId("gesture-editor-preview");
const previewText = byId("gesture-editor-preview-text");
const previewExplanation = byId("gesture-editor-preview-explanation");
const audioButton = byId("gesture-editor-audio-preview");
const packageSummary = byId("gesture-editor-package-summary");
const saveButton = byId("gesture-editor-save");
const channel = "BroadcastChannel" in window ? new BroadcastChannel("chatspace-gesture-catalog") : null;
let audio = null;

function appUrl(path) {
    return `${APP_BASE}/${String(path).replace(/^\/+/, "")}`;
}

function requestKey(prefix) {
    return `${prefix}-${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`}`.slice(0, 96);
}

function setStatus(message, type = "") {
    status.textContent = message;
    status.className = `gesture-editor-status ${type}`.trim();
}

function stopAudio() {
    if (!audio) return;
    audio.pause();
    audio.removeAttribute("src");
    audio.load();
    audio = null;
    audioButton.textContent = "Play sound preview";
}

function values() {
    return {
        title: byId("gesture-title").value,
        text: byId("gesture-text").value,
        creator_credit: byId("gesture-creator-credit").value,
        catalog_filename: byId("gesture-catalog-filename").value,
    };
}

function renderValidation(errors = []) {
    validation.replaceChildren();
    validation.hidden = errors.length === 0;
    if (!errors.length) return;
    const heading = document.createElement("strong");
    heading.textContent = "Correct these fields before saving:";
    const list = document.createElement("ul");
    for (const message of errors) {
        const item = document.createElement("li");
        item.textContent = message;
        list.appendChild(item);
    }
    validation.append(heading, list);
}

function renderPreview() {
    stopAudio();
    const preferences = state.preferences();
    const content = values();
    const animationUrl = state.previewUrl("animation");
    const posterUrl = state.previewUrl("poster");
    preview.replaceChildren();
    if (preferences.show_animations !== false && animationUrl) {
        const image = document.createElement("img");
        image.src = animationUrl;
        image.alt = content.text ? `(Gesture) ${content.text}` : "Gesture preview";
        image.draggable = false;
        preview.appendChild(image);
        previewExplanation.textContent = "";
    } else if (posterUrl && preferences.show_animations !== false) {
        const image = document.createElement("img");
        image.src = posterUrl;
        image.alt = content.text ? `(Gesture) ${content.text}` : "Gesture poster preview";
        preview.appendChild(image);
        previewExplanation.textContent = "";
    } else {
        const placeholder = document.createElement("span");
        placeholder.textContent = animationUrl ? "Animation hidden by your Chat Options preference." : "Choose a GIF or AGST package to preview the gesture.";
        preview.appendChild(placeholder);
        previewExplanation.textContent = animationUrl && preferences.show_animations === false
            ? "Gesture animation hidden — Gesture animations are turned off in Chat Options."
            : "";
    }
    previewText.textContent = preferences.show_text === false ? "" : (content.text ? `(Gesture) ${content.text}` : "(Gesture)");
    const audioUrl = state.previewUrl("audio");
    audioButton.hidden = !audioUrl;
    audioButton.disabled = !audioUrl || preferences.play_sounds === false;
    audioButton.title = preferences.play_sounds === false ? "Gesture sounds are turned off in Chat Options." : "";
}

function renderPackageSummary(summary) {
    packageSummary.replaceChildren();
    if (!summary) {
        packageSummary.textContent = "A canonical AGST v1 package will be created after validation.";
        return;
    }
    const facts = [
        `Status: ${summary.status || "unknown"}`,
        `Package version: ${Number(summary.version || 0)}`,
        `Generation: ${Number(summary.generation || 0)}`,
        `Compatibility: ${summary.compatibility || "unknown"}`,
        `Media: ${Object.keys(summary.media || {}).join(", ") || "none"}`,
    ];
    for (const fact of facts) {
        const row = document.createElement("div");
        row.textContent = fact;
        packageSummary.appendChild(row);
    }
}

function hydrate(payload) {
    state.hydrate(payload);
    const gesture = state.gesture();
    if (gesture) {
        byId("gesture-title").value = gesture.title || gesture.name || "";
        byId("gesture-text").value = gesture.text || "";
        byId("gesture-creator-credit").value = gesture.creator_credit || "";
        byId("gesture-catalog-filename").value = gesture.catalog_filename || "";
        byId("gesture-editor-heading").textContent = admin ? "Manage Server Gesture" : "Edit Gesture";
        byId("gesture-editor-mode").textContent = admin ? "Admin package and metadata management" : "Edit your Personal Gesture";
        byId("gesture-uploaded-by-row").hidden = !gesture.uploaded_by;
        byId("gesture-uploaded-by").textContent = gesture.uploaded_by || "";
        byId("gesture-current-version").textContent = String(gesture.version || 1);
        byId("remove-audio-row").hidden = !gesture.audio_url;
        byId("remove-poster-row").hidden = !gesture.poster_url;
    }
    const features = state.features();
    byId("gesture-package-input-row").hidden = features.user_package_import === false;
    byId("gesture-audio-input-row").hidden = features.audio_media === false;
    byId("gesture-animation-input-row").hidden = features.animation_media === false;
    byId("gesture-download-package").hidden = !gesture || features.user_package_download === false;
    renderPackageSummary(state.packageSummary());
    renderPreview();
}

async function load() {
    setStatus("Loading Gesture Maker…", "working");
    try {
        const url = publicId
            ? appUrl(`/api/gesture_packages.php?action=detail&id=${encodeURIComponent(publicId)}&admin=${admin ? "1" : "0"}`)
            : appUrl("/api/gesture_packages.php?action=preferences");
        const response = await fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" });
        const data = await response.json();
        if (!response.ok || data.error) throw new Error(data.error || "Gesture Maker could not load.");
        hydrate(data);
        setStatus(publicId ? "Gesture ready for editing." : "Create a private Personal Gesture. Nothing is uploaded until Save.", "ok");
    } catch (error) {
        saveButton.disabled = true;
        setStatus(error.message || "Gesture Maker could not load.", "error");
    }
}

for (const role of ["package", "animation", "poster", "audio"]) {
    byId(`gesture-${role}`)?.addEventListener("change", event => {
        try {
            state.setFile(role, event.currentTarget.files?.[0] || null);
            renderValidation();
            renderPreview();
            setStatus(role === "package" ? "AGST package selected. It will be validated by the server before Save." : `${role[0].toUpperCase()}${role.slice(1)} selected for preview.`, "ok");
        } catch (error) {
            event.currentTarget.value = "";
            state.setFile(role, null);
            renderValidation([error.message || String(error)]);
        }
    });
}

for (const id of ["gesture-title", "gesture-text", "gesture-creator-credit", "gesture-catalog-filename"]) {
    byId(id)?.addEventListener("input", () => {
        renderValidation();
        renderPreview();
    });
}

audioButton.addEventListener("click", async () => {
    if (audio) { stopAudio(); return; }
    const url = state.previewUrl("audio");
    if (!url || state.preferences().play_sounds === false) return;
    audio = new Audio(url);
    audioButton.textContent = "Stop sound preview";
    audio.addEventListener("ended", stopAudio, { once: true });
    audio.addEventListener("error", () => { stopAudio(); setStatus("Sound preview is unavailable.", "error"); }, { once: true });
    try { await audio.play(); } catch { stopAudio(); setStatus("Sound preview could not start.", "error"); }
});

form.addEventListener("submit", async event => {
    event.preventDefault();
    const checked = state.validate(values());
    renderValidation(checked.errors);
    if (!checked.ok) { validation.focus(); return; }
    saveButton.disabled = true;
    setStatus("Validating and saving the gesture atomically…", "working");
    const data = new FormData();
    const gesture = state.gesture();
    data.append("_csrf", CSRF);
    data.append("action", admin ? "admin_edit" : (gesture ? "edit" : "create"));
    data.append("request_key", requestKey(admin ? "gesture-admin-edit" : (gesture ? "gesture-edit" : "gesture-create")));
    for (const [key, value] of Object.entries(checked.values)) data.append(key, value);
    if (gesture) {
        data.append("public_id", gesture.public_id);
        data.append("expected_version", String(gesture.version));
    }
    if (byId("gesture-remove-audio").checked) data.append("remove_audio", "1");
    if (byId("gesture-remove-poster").checked) data.append("remove_poster", "1");
    for (const [role, file] of Object.entries(state.files())) if (file) data.append(role, file, file.name);
    try {
        const response = await fetch(appUrl("/api/gesture_packages.php"), { method: "POST", body: data, credentials: "same-origin", headers: { Accept: "application/json", "X-CSRF-Token": CSRF } });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            const error = new Error(payload.error || "Gesture could not be saved.");
            error.code = payload.error_code || "";
            error.authoritative = payload.authoritative || null;
            throw error;
        }
        state.applySaved(payload);
        hydrate({ ...payload, features: state.features(), preferences: state.preferences() });
        channel?.postMessage({ type: "gesture-saved", gesturePublicId: payload.gesture?.public_id, version: payload.gesture?.version });
        try { window.opener?.postMessage?.({ type: "chatspace-gesture-saved", gesturePublicId: payload.gesture?.public_id }, window.location.origin); } catch {}
        setStatus(payload.idempotent ? "Gesture save was already completed safely." : "Gesture saved. The Personal Gesture catalog has been refreshed; no message was sent.", "ok");
    } catch (error) {
        if (error.code === "GESTURE_VERSION_CONFLICT") setStatus("This gesture changed in another editor. Close or reload before trying again; no partial change was saved.", "error");
        else setStatus(error.message || "Gesture could not be saved.", "error");
    } finally {
        saveButton.disabled = false;
    }
});

byId("gesture-download-package").addEventListener("click", () => {
    const gesture = state.gesture();
    if (!gesture) return;
    const requestId = requestKey("gesture-download");
    window.location.assign(appUrl(`/api/gesture_packages.php?action=download&id=${encodeURIComponent(gesture.public_id)}&request_id=${encodeURIComponent(requestId)}`));
});

function closeEditor() {
    stopAudio();
    state.revokeObjectUrls();
    channel?.close();
    if (window.opener && !window.opener.closed) window.close();
    else if (history.length > 1) history.back();
    else window.close();
}

byId("gesture-editor-cancel").addEventListener("click", closeEditor);
byId("gesture-editor-close").addEventListener("click", closeEditor);
window.addEventListener("beforeunload", () => { stopAudio(); state.revokeObjectUrls(); channel?.close(); });
document.addEventListener("keydown", event => { if (event.key === "Escape") closeEditor(); });

load();
