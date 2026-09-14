/** ChatRuntime-owned, event-driven DM/link notification audio. */
export class ChatMessageChimeService {
    #context = null;
    #bindings = null;
    #enabled = true;
    #volume = 100;
    #intervalSeconds = 30;
    #whileFocused = false;
    #roomEnabled = false;
    #roomIntervalSeconds = 30;
    #lastRoomChime = -Infinity;
    #gain = null;
    #lastChime = -Infinity;
    #pendingChats = new Set();
    #seen = new Set();
    #audio = null;
    #buffer = null;
    #loading = null;
    #sources = new Set();
    #status = "";

    initialize() {}

    configure(context = {}) {
        this.destroy();
        this.#context = context;
        this.#bindings = new context.window.AbortController();
        try {
            this.#enabled = context.window.localStorage.getItem(context.storageKey) !== "off";
            this.#volume = this.#normalizeVolume(context.window.localStorage.getItem(`${context.storageKey}.volume`));
            this.#intervalSeconds = this.#normalizeInterval(context.window.localStorage.getItem(`${context.storageKey}.interval`));
            this.#whileFocused = context.window.localStorage.getItem(`${context.storageKey}.focused`) === "on";
            this.#roomEnabled = context.window.localStorage.getItem(`${context.storageKey}.room`) === "on";
            this.#roomIntervalSeconds = this.#normalizeInterval(context.window.localStorage.getItem(`${context.storageKey}.roomInterval`));
        } catch {
            this.#enabled = true;
            this.#volume = 100;
            this.#intervalSeconds = 30;
            this.#whileFocused = false;
            this.#roomEnabled = false;
            this.#roomIntervalSeconds = 30;
        }
        const options = { signal: this.#bindings.signal };
        const attend = () => this.syncAttention();
        context.window.addEventListener("focus", attend, options);
        context.document.addEventListener("visibilitychange", attend, options);
        const unlock = event => {
            if (event.isTrusted && (this.#enabled || this.#roomEnabled)) this.#unlockAudio();
            this.syncAttention();
        };
        context.document.addEventListener("pointerdown", unlock, options);
        context.document.addEventListener("keydown", unlock, options);
        context.toggle?.addEventListener("click", () => this.setEnabled(!this.#enabled), options);
        context.focusedToggle?.addEventListener("click", () => this.setWhileFocused(!this.#whileFocused), options);
        context.volumeDown?.addEventListener("click", () => this.setVolume(this.#volume - 25), options);
        context.volumeUp?.addEventListener("click", () => this.setVolume(this.#volume + 25), options);
        context.intervalDown?.addEventListener("click", () => this.setIntervalSeconds(this.#intervalSeconds - 5), options);
        context.intervalUp?.addEventListener("click", () => this.setIntervalSeconds(this.#intervalSeconds + 5), options);
        context.intervalPresets?.addEventListener("click", event => {
            const button = event.target.closest?.("button[data-chime-interval]");
            if (button && context.intervalPresets.contains(button)) {
                this.setIntervalSeconds(button.dataset.chimeInterval);
            }
        }, options);
        context.roomToggle?.addEventListener("click", () => this.setRoomEnabled(!this.#roomEnabled), options);
        context.roomIntervalDown?.addEventListener("click", () => this.setRoomIntervalSeconds(this.#roomIntervalSeconds - 5), options);
        context.roomIntervalUp?.addEventListener("click", () => this.setRoomIntervalSeconds(this.#roomIntervalSeconds + 5), options);
        context.roomIntervalPresets?.addEventListener("click", event => {
            const button = event.target.closest?.("button[data-room-chime-interval]");
            if (button && context.roomIntervalPresets.contains(button)) {
                this.setRoomIntervalSeconds(button.dataset.roomChimeInterval);
            }
        }, options);
        context.preview?.addEventListener("click", () => this.preview(), options);
        context.window.addEventListener("storage", event => {
            if (event.key === context.storageKey) this.setEnabled(event.newValue !== "off", false);
            if (event.key === `${context.storageKey}.volume`) this.setVolume(event.newValue, false);
            if (event.key === `${context.storageKey}.interval`) this.setIntervalSeconds(event.newValue, false);
            if (event.key === `${context.storageKey}.focused`) this.setWhileFocused(event.newValue === "on", false);
            if (event.key === `${context.storageKey}.room`) this.setRoomEnabled(event.newValue === "on", false);
            if (event.key === `${context.storageKey}.roomInterval`) this.setRoomIntervalSeconds(event.newValue, false);
        }, options);
        if (this.#enabled || this.#roomEnabled) this.#prepareAudio();
        this.syncControls();
    }

    setEnabled(enabled, persist = true) {
        this.#enabled = Boolean(enabled);
        this.#lastChime = -Infinity;
        for (const chatKey of this.#pendingChats) {
            if (chatKey !== "room") this.#pendingChats.delete(chatKey);
        }
        this.#status = "";
        if (persist) {
            try {
                this.#context.window.localStorage.setItem(
                    this.#context.storageKey, this.#enabled ? "on" : "off"
                );
            } catch {
                this.#status = "Your choice applies to this page; browser storage is unavailable.";
            }
        }
        if (this.#enabled) this.#unlockAudio();
        else if (!this.#roomEnabled) this.#stopSources();
        this.syncControls();
    }

    #normalizeVolume(value) {
        const number = Number(value);
        return value !== null && value !== "" && Number.isFinite(number)
            ? Math.max(25, Math.min(200, Math.round(number / 25) * 25)) : 100;
    }

    setVolume(value, persist = true) {
        this.#volume = this.#normalizeVolume(value);
        if (this.#gain) this.#gain.gain.value = this.#volume / 100;
        if (persist) {
            try {
                this.#context.window.localStorage.setItem(
                    `${this.#context.storageKey}.volume`, String(this.#volume)
                );
            } catch {
                this.#status = "Volume applies to this page; browser storage is unavailable.";
            }
        }
        this.syncControls();
    }

    #normalizeInterval(value) {
        const number = Number(value);
        return value !== null && value !== "" && Number.isFinite(number)
            ? Math.max(0, Math.min(300, Math.round(number / 5) * 5)) : 30;
    }

    setIntervalSeconds(value, persist = true) {
        this.#intervalSeconds = this.#normalizeInterval(value);
        if (persist) {
            try {
                this.#context.window.localStorage.setItem(
                    `${this.#context.storageKey}.interval`, String(this.#intervalSeconds)
                );
            } catch {
                this.#status = "Interval applies to this page; browser storage is unavailable.";
            }
        }
        // Do not play or queue anything when changing the interval.
        this.syncControls();
    }

    #intervalLabel(intervalSeconds = this.#intervalSeconds) {
        if (intervalSeconds === 0) return "Every message";
        const minutes = Math.floor(intervalSeconds / 60);
        const seconds = intervalSeconds % 60;
        const parts = [];
        if (minutes) parts.push(`${minutes} ${minutes === 1 ? "minute" : "minutes"}`);
        if (seconds) parts.push(`${seconds} seconds`);
        return parts.join(" ");
    }

    setWhileFocused(enabled, persist = true) {
        this.#whileFocused = Boolean(enabled);
        if (persist) {
            try {
                this.#context.window.localStorage.setItem(
                    `${this.#context.storageKey}.focused`, this.#whileFocused ? "on" : "off"
                );
            } catch {
                this.#status = "Focus preference applies to this page; browser storage is unavailable.";
            }
        }
        this.syncControls();
    }

    setRoomEnabled(enabled, persist = true) {
        this.#roomEnabled = Boolean(enabled);
        this.#lastRoomChime = -Infinity;
        this.#pendingChats.delete("room");
        if (persist) {
            try {
                this.#context.window.localStorage.setItem(
                    `${this.#context.storageKey}.room`, this.#roomEnabled ? "on" : "off"
                );
            } catch {
                this.#status = "Room chime preference applies to this page; browser storage is unavailable.";
            }
        }
        if (this.#roomEnabled) this.#unlockAudio();
        else if (!this.#enabled) this.#stopSources();
        this.syncControls();
    }

    setRoomIntervalSeconds(value, persist = true) {
        this.#roomIntervalSeconds = this.#normalizeInterval(value);
        if (persist) {
            try {
                this.#context.window.localStorage.setItem(
                    `${this.#context.storageKey}.roomInterval`, String(this.#roomIntervalSeconds)
                );
            } catch {
                this.#status = "Room interval applies to this page; browser storage is unavailable.";
            }
        }
        this.syncControls();
    }

    #isReading(chatKey) {
        const context = this.#context;
        return Boolean(context && context.document.visibilityState === "visible"
            && context.document.hasFocus() && context.getActiveChat() === chatKey);
    }

    syncAttention() {
        const chatKey = this.#context?.getActiveChat();
        if (this.#pendingChats.has(chatKey) && this.#isReading(chatKey)) {
            this.#pendingChats.delete(chatKey);
            if (chatKey === "room") this.#lastRoomChime = -Infinity;
            else this.#lastChime = -Infinity;
        }
    }

    consider(message, chatKey, { live = false, existing = false, suppressed = false } = {}) {
        const roomMessage = chatKey === "room";
        if (!this.#context || (!roomMessage && !/^(dm|link):/.test(String(chatKey)))) return false;
        const id = message?.id || message?.client_message_id;
        if (!id) return false;
        const identity = `${chatKey}:${id}`;
        const seen = this.#seen.has(identity);
        this.#seen.add(identity);
        if (this.#seen.size > 2048) this.#seen.delete(this.#seen.values().next().value);
        const config = this.#context.getConfig() || {};
        const ownUser = Number(config.myUserId) > 0
            && Number(message.user_id) === Number(config.myUserId);
        const ownParticipant = Number(config.myParticipantId) > 0
            && Number(message.participant_id) === Number(config.myParticipantId);
        this.syncAttention();
        const reading = this.#isReading(chatKey);
        if (!live || existing || seen || suppressed || ownUser || ownParticipant
            || message.is_deleted || !(roomMessage ? this.#roomEnabled : this.#enabled)
            || (reading && !this.#whileFocused)) return false;
        // Focused messages are already being viewed: do not mark them pending,
        // or the next message would reset the cooldown while focus is unchanged.
        if (!reading) {
            this.#pendingChats.add(chatKey);
            if (this.#pendingChats.size > 128) this.#pendingChats.delete(this.#pendingChats.values().next().value);
        }
        const now = this.#context.window.performance.now();
        const lastChime = roomMessage ? this.#lastRoomChime : this.#lastChime;
        const intervalSeconds = roomMessage ? this.#roomIntervalSeconds : this.#intervalSeconds;
        if (now - lastChime < intervalSeconds * 1000) return false;
        // Never queue a delayed sound for later focus/unlock or start a reminder timer.
        if (!this.#play()) return false;
        if (roomMessage) this.#lastRoomChime = now;
        else this.#lastChime = now;
        return true;
    }

    #prepareAudio() {
        if (this.#loading) return this.#loading;
        const context = this.#context;
        if (!context) return Promise.resolve(false);
        try {
            const AudioContext = context.window.AudioContext || context.window.webkitAudioContext;
            if (!AudioContext) throw new Error("Audio unavailable");
            const audio = this.#audio || new AudioContext();
            this.#audio = audio;
            if (!this.#gain) {
                this.#gain = audio.createGain();
                this.#gain.gain.value = this.#volume / 100;
                this.#gain.connect(audio.destination);
            }
            this.#loading = context.window.fetch(context.soundUrl, {
                credentials: "same-origin", signal: this.#bindings.signal
            }).then(response => {
                if (!response.ok) throw new Error("Sound unavailable");
                return response.arrayBuffer();
            }).then(bytes => audio.decodeAudioData(bytes)).then(buffer => {
                if (this.#audio !== audio) return false;
                this.#buffer = buffer;
                this.#status = "";
                this.syncControls();
                return true;
            }).catch(error => {
                if (this.#audio !== audio || error.name === "AbortError") return false;
                this.#loading = null;
                this.#status = "The chime could not be loaded. Use Play chime to retry.";
                this.syncControls();
                return false;
            });
        } catch {
            this.#status = "Message sound is unavailable in this browser.";
            this.syncControls();
            return Promise.resolve(false);
        }
        return this.#loading;
    }

    #unlockAudio() {
        this.#prepareAudio();
        const audio = this.#audio;
        if (audio?.state === "suspended") {
            audio.resume().then(() => {
                if (this.#audio === audio) this.syncControls();
            }).catch(() => {});
        }
    }

    async preview() {
        this.#unlockAudio();
        const audio = this.#audio;
        if (await this.#prepareAudio()) {
            if (this.#audio === audio) this.#play();
        }
    }

    #play() {
        if (!this.#buffer || this.#audio?.state !== "running") return false;
        try {
            const source = this.#audio.createBufferSource();
            source.buffer = this.#buffer;
            source.connect(this.#gain);
            this.#sources.add(source);
            source.addEventListener("ended", () => {
                this.#sources.delete(source);
                source.disconnect();
            }, { once: true });
            source.start();
            return true;
        } catch {
            this.#status = "The browser could not play the chime.";
            this.syncControls();
            return false;
        }
    }

    syncControls() {
        const context = this.#context;
        if (!context) return;
        if (context.toggle) {
            context.toggle.textContent = this.#enabled ? "On" : "Off";
            context.toggle.setAttribute("aria-pressed", String(this.#enabled));
            context.toggle.classList.toggle("btn-primary", this.#enabled);
        }
        if (context.volumeValue) context.volumeValue.textContent = `${this.#volume}%`;
        if (context.volumeDown) context.volumeDown.disabled = this.#volume <= 25;
        if (context.volumeUp) context.volumeUp.disabled = this.#volume >= 200;
        if (context.focusedToggle) {
            context.focusedToggle.textContent = this.#whileFocused ? "On" : "Off";
            context.focusedToggle.setAttribute("aria-pressed", String(this.#whileFocused));
            context.focusedToggle.classList.toggle("btn-primary", this.#whileFocused);
        }
        if (context.intervalValue) context.intervalValue.textContent = this.#intervalLabel();
        if (context.intervalDown) context.intervalDown.disabled = this.#intervalSeconds <= 0;
        if (context.intervalUp) context.intervalUp.disabled = this.#intervalSeconds >= 300;
        context.intervalPresets?.querySelectorAll("button[data-chime-interval]").forEach(button => {
            const selected = Number(button.dataset.chimeInterval) === this.#intervalSeconds;
            button.setAttribute("aria-pressed", String(selected));
            button.classList.toggle("btn-primary", selected);
        });
        if (context.roomToggle) {
            context.roomToggle.textContent = this.#roomEnabled ? "On" : "Off";
            context.roomToggle.setAttribute("aria-pressed", String(this.#roomEnabled));
            context.roomToggle.classList.toggle("btn-primary", this.#roomEnabled);
        }
        if (context.roomIntervalValue) context.roomIntervalValue.textContent = this.#intervalLabel(this.#roomIntervalSeconds);
        if (context.roomIntervalDown) context.roomIntervalDown.disabled = this.#roomIntervalSeconds <= 0;
        if (context.roomIntervalUp) context.roomIntervalUp.disabled = this.#roomIntervalSeconds >= 300;
        context.roomIntervalPresets?.querySelectorAll("button[data-room-chime-interval]").forEach(button => {
            const selected = Number(button.dataset.roomChimeInterval) === this.#roomIntervalSeconds;
            button.setAttribute("aria-pressed", String(selected));
            button.classList.toggle("btn-primary", selected);
        });
        if (context.status) context.status.textContent = this.#status || (
            (this.#enabled || this.#roomEnabled) && this.#audio?.state === "suspended"
                ? "Click or type in this room once to allow message sounds."
                : "Saved for this account in this browser."
        );
    }

    #stopSources() {
        for (const source of this.#sources) {
            try { source.stop(); source.disconnect(); } catch {}
        }
        this.#sources.clear();
    }

    destroy() {
        this.#bindings?.abort();
        this.#bindings = null;
        this.#stopSources();
        this.#gain?.disconnect();
        this.#gain = null;
        this.#audio?.close().catch(() => {});
        this.#audio = null;
        this.#buffer = null;
        this.#loading = null;
        this.#pendingChats.clear();
        this.#seen.clear();
        this.#lastChime = -Infinity;
        this.#lastRoomChime = -Infinity;
        this.#status = "";
        this.#context = null;
    }

    getDiagnostics() {
        return Object.freeze({ owner: "ChatRuntime", configured: Boolean(this.#context),
            enabled: this.#enabled, roomEnabled: this.#roomEnabled, roomCooldownMs: this.#roomIntervalSeconds * 1000,
            whileFocused: this.#whileFocused, volumePercent: this.#volume, audioReady: Boolean(this.#buffer),
            audioState: this.#audio?.state || "unavailable", cooldownMs: this.#intervalSeconds * 1000 });
    }
}
