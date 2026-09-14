/** Shared pregame seating UI for the room shell and embedded card games. */
function ensureSeatStyles(document) {
    if (!document.querySelector("link[data-game-seat-styles]")) {
        const stylesheet = document.createElement("link");
        stylesheet.rel = "stylesheet";
        stylesheet.href = new URL("../../../../css/game-seat-controls.css?v=1812e2e46bbe", import.meta.url).href;
        stylesheet.dataset.gameSeatStyles = "true";
        document.head.append(stylesheet);
    }
}

export function renderGameBotControls(document, bots, runAction) {
    if (!bots?.options?.length) return null;
    ensureSeatStyles(document);
    const panel = document.createElement("section");
    panel.className = "game-setting game-bot-controls";
    panel.setAttribute("aria-label", "Fill empty seats with bots");
    const heading = document.createElement("strong");
    heading.textContent = "Fill empty seats with bots";
    const note = document.createElement("p");
    note.textContent = bots.mode === "practice"
        ? "Practice Mode — bot games do not affect rankings. The host can add bots to empty seats, then start."
        : "Adding a bot switches this game to Practice Mode. It will not affect rankings. The host can add bots after people join.";
    const status = document.createElement("p");
    status.setAttribute("role", "status");
    let busy = false;
    const act = async (action, payload) => {
        if (busy) return;
        busy = true;
        const controls = [...panel.querySelectorAll("button")].map(button => [button, button.disabled]);
        for (const [button] of controls) button.disabled = true;
        status.textContent = action === "start" ? "Starting game…" : "Updating bot seats…";
        try {
            await runAction(action, payload);
            status.textContent = action === "start" ? "Game started." : "Bot seats updated.";
        } catch (error) { status.textContent = error?.message || "Bot seats could not be updated."; }
        finally { busy = false; for (const [button, disabled] of controls) button.disabled = disabled; }
    };
    panel.append(heading, note);
    if (bots.strengthNote) {
        const strengthNote = document.createElement("p");
        strengthNote.textContent = bots.strengthNote;
        panel.append(strengthNote);
    }
    for (const slot of bots.options) {
        const row = document.createElement("div");
        row.className = "game-bot-seat";
        row.dataset.botSeat = String(slot.seat);
        const label = document.createElement("strong");
        label.textContent = `Seat ${slot.seat}${slot.occupantName ? ` — ${slot.occupantName}` : " — Open for a player"}`;
        row.append(label);
        if (slot.occupantName) {
            const human = document.createElement("span");
            human.textContent = "Player seated";
            row.append(human);
        } else {
            const choices = document.createElement("div");
            choices.className = "game-bot-choices";
            choices.setAttribute("role", "group");
            choices.setAttribute("aria-label", `Seat ${slot.seat} bot`);
            for (const {value, label: caption} of (bots.choices || [{value:"none",label:"None"},{value:"normal",label:"Normal"},{value:"expert",label:"Expert"}])) {
                const button = document.createElement("button");
                button.type = "button";
                button.className = "btn";
                button.textContent = caption;
                button.dataset.botDifficulty = value;
                button.setAttribute("aria-pressed", String(slot.difficulty === value));
                button.disabled = !slot.editable;
                button.addEventListener("click", () => {
                    if (slot.difficulty === value) return;
                    act("set-lobby-bot", {seat: Number(slot.seat), difficulty: value,
                        settings_sha256: bots.settingsSha256, player_set_sha256: bots.playerSetSha256});
                });
                choices.append(button);
            }
            row.append(choices);
        }
        panel.append(row);
    }
    panel.append(status);
    if (bots.showStart) {
        const start = document.createElement("button");
        start.type = "button";
        start.className = "btn btn-primary";
        start.textContent = "Start game";
        start.disabled = !bots.canStart;
        start.addEventListener("click", () => act("start", {}));
        panel.append(start);
    }
    return panel;
}

export function renderGameSeatControls(document, seating, viewerId, runAction) {
    if (!seating?.canChoose) return null;
    ensureSeatStyles(document);
    const panel = document.createElement("section");
    panel.className = "game-setting game-pregame-rules game-seat-controls";
    panel.setAttribute("aria-label", "Choose your seat");
    const title = document.createElement("strong");
    title.textContent = "Choose your seat";
    const description = document.createElement("p");
    description.textContent = seating.description;
    const board = document.createElement("div");
    board.className = `game-seat-table is-${seating.tableKind || "four"}`;
    board.setAttribute("role", "group");
    board.setAttribute("aria-label", `${seating.gameName || "Card game"} seat map`);
    const center = document.createElement("div");
    center.className = "game-seat-table-center";
    const game = document.createElement("strong");
    game.textContent = seating.gameName || "Card table";
    const hint = document.createElement("span");
    hint.textContent = "Choose a seat";
    center.append(game, hint);
    board.append(center);
    const preview = document.createElement("p");
    preview.className = "game-seat-preview";
    preview.setAttribute("aria-live", "polite");
    const status = document.createElement("p");
    status.setAttribute("role", "status");
    let busy = false;
    const act = async (payload, control, action = "choose-seat") => {
        if (busy) return;
        busy = true;
        const buttons = [...panel.querySelectorAll("button")].map(button => [button, button.disabled]);
        for (const [button] of buttons) button.disabled = true;
        status.textContent = "Updating seating…";
        try { await runAction(action, payload); status.textContent = "Game controls updated."; }
        catch (error) { status.textContent = error?.message || "Seating could not be updated."; }
        finally { busy = false; for (const [button, disabled] of buttons) button.disabled = disabled; }
    };
    const current = seating.options.find(entry => entry.current);
    // Fixed seat 1 faces the table from below, matching the initial gameplay view.
    const positions = seating.tableKind === "ten"
        ? [[1,2],[1,3],[2,4],[3,4],[4,4],[5,3],[5,2],[4,1],[3,1],[2,1]]
        : seating.tableKind === "two" ? [[3,2],[1,2]] : [[3,2],[2,1],[1,2],[2,3]];
    for (const [index, entry] of (seating.options || []).entries()) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "game-seat-button";
        button.dataset.seat = String(entry.seat);
        const position = positions[seating.tableKind === "two" ? index : Number(entry.seat) - 1];
        button.style.gridRow = String(position[0]);
        button.style.gridColumn = String(position[1]);
        const action = entry.current ? "Your seat" : entry.available ? "Take this seat" : "Request seat swap";
        button.setAttribute("aria-label", `${entry.label}. ${action}`);
        button.title = `${entry.label}. ${action}`;
        button.classList.toggle("is-current", Boolean(entry.current));
        button.classList.toggle("is-open", Boolean(entry.available));
        button.classList.toggle("is-partner", Number(current?.partnerSeat) === Number(entry.seat));
        if (entry.current) button.setAttribute("aria-current", "true");
        const seatNumber = document.createElement("span");
        seatNumber.className = "game-seat-number";
        seatNumber.textContent = `Seat ${entry.seat}`;
        const name = document.createElement("strong");
        name.className = "game-seat-name";
        name.textContent = entry.occupantName || "Open seat";
        const badge = document.createElement("span");
        badge.className = "game-seat-badge";
        badge.textContent = entry.current ? "You" : Number(current?.partnerSeat) === Number(entry.seat) ? (entry.available ? "Partner · Open" : "Partner") : entry.available ? "Open" : "Swap";
        button.append(seatNumber, name, badge);
        const pending = (seating.requests || []).some(request => Number(request.byUserId) === Number(viewerId) && Number(request.toSeat) === Number(entry.seat));
        button.disabled = Boolean(pending);
        // Keep the current seat focusable so keyboard users can hear their relationship.
        button.addEventListener("focus", () => { preview.textContent = entry.label; });
        button.addEventListener("mouseenter", () => { preview.textContent = entry.label; });
        button.addEventListener("click", () => {
            preview.textContent = entry.label;
            if (!entry.current) act({ seat: Number(entry.seat) }, button);
        });
        board.append(button);
    }
    preview.textContent = current?.label || "Choose a seat to see who sits beside or opposite you.";
    panel.append(title, description, board, preview, status);
    for (const request of seating.requests || []) {
        const text = document.createElement("p");
        const receiving = Number(request.toUserId) === Number(viewerId);
        const destination = seating.options.find(entry => Number(entry.seat) === Number(request.fromSeat));
        text.textContent = receiving
            ? `${request.requesterName} requests a swap: you would move to seat ${request.fromSeat}. ${destination?.label || ""}`
            : `Waiting for approval to swap into seat ${request.toSeat}.`;
        panel.append(text);
        for (const [decision, caption] of receiving ? [["approve", "Approve seat swap"], ["decline", "Decline seat swap"]] : [["cancel", "Cancel seat swap"]]) {
            const control = document.createElement("button");
            control.type = "button";
            control.className = "btn";
            control.textContent = caption;
            control.addEventListener("click", () => act({ decision, request_id: request.id }, control));
            panel.append(control);
        }
    }
    const readiness = document.createElement("p");
    readiness.textContent = seating.startStatus;
    panel.append(readiness);
    if (seating.canAccept) {
        const accept = document.createElement("button");
        accept.type = "button"; accept.className = "btn";
        accept.textContent = "Accept current seating and options";
        accept.addEventListener("click", () => act({ settings_sha256: seating.settingsSha256, mode: seating.mode }, accept, "accept"));
        panel.append(accept);
    }
    if (seating.isHost) {
        const start = document.createElement("button");
        start.type = "button"; start.className = "btn btn-primary";
        start.textContent = "Start game"; start.disabled = !seating.canStart;
        start.addEventListener("click", () => act({}, start, "start"));
        panel.append(start);
    }
    return panel;
}
